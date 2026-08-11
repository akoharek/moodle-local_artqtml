<?php
/**
 * Standalone dev/QA tool: decrypts a local_artqtml file-integrity violation report exported
 * from the License admin tab ("Export integrity report" button, a .enc file).
 *
 * classes/local/license_checker.php's get_or_log_integrity_violation() never has access to the
 * private key half of PUBLIC_KEY_PEM (only the public half ships with the plugin), so it can
 * only ever encrypt a violation, never decrypt one - this tool is the vendor-side counterpart,
 * run by whoever holds the matching RSA private key, once an admin sends over their exported
 * .enc file quoting the "AIQ-YYYYMMDD-<id>" error code the plugin showed them.
 *
 * NOT part of the shipped plugin - excluded from the deployment zip (see CLAUDE.md, same
 * tools/* exclusion as tools/generate_license.php). Runs standalone on a developer/vendor
 * machine with plain PHP + the openssl extension, no Moodle bootstrap.
 *
 * The .enc file's content is a base64-encoded JSON envelope:
 *   {encrypted_payload, encrypted_key, error_code, site_url, timestamp}
 * - encrypted_key is the per-report random AES-256 key, RSA/OAEP-encrypted with the plugin's
 *   public key (recovered here via openssl_private_decrypt() with the matching private key).
 * - encrypted_payload is base64(iv . ciphertext): a 16-byte AES IV followed immediately by the
 *   AES-256-CBC ciphertext of the actual violation details JSON (modified/missing file lists,
 *   timestamp, site_url, moodle_version) - decrypted here with the AES key just recovered.
 *
 * Usage:
 *   php tools/decrypt_integrity_log.php \
 *     --encfile=/path/to/artqtml-integrity-AIQ-20260724-4821.enc \
 *     --key=/path/to/artqtml_license_PRIVATE_KEY.pem
 *
 * The private key is never checked into the plugin repository - keep it outside version
 * control and only on machines that are allowed to issue licenses/decrypt these reports.
 */

function artqtml_parse_args(array $argv): array {
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $matches)) {
            $args[$matches[1]] = $matches[2] ?? true;
        }
    }
    return $args;
}

$args = artqtml_parse_args($argv);

foreach (['encfile', 'key'] as $required) {
    if (empty($args[$required])) {
        fwrite(STDERR, "Missing required --{$required}\n");
        exit(1);
    }
}

$encpath = (string) $args['encfile'];
$keypath = (string) $args['key'];

if (!is_readable($encpath)) {
    fwrite(STDERR, "Cannot read .enc file: {$encpath}\n");
    exit(1);
}
if (!is_readable($keypath)) {
    fwrite(STDERR, "Cannot read private key: {$keypath}\n");
    exit(1);
}

$privatekey = openssl_pkey_get_private('file://' . $keypath);
if ($privatekey === false) {
    fwrite(STDERR, "Failed to load private key: " . openssl_error_string() . "\n");
    exit(1);
}

$raw = file_get_contents($encpath);
if ($raw === false) {
    fwrite(STDERR, "Failed to read {$encpath}\n");
    exit(1);
}

$decoded = base64_decode(trim($raw), true);
if ($decoded === false) {
    fwrite(STDERR, "{$encpath} is not valid base64 - was it downloaded/copied correctly?\n");
    exit(1);
}

$envelope = json_decode($decoded, true);
if (!is_array($envelope) || empty($envelope['encrypted_payload']) || empty($envelope['encrypted_key'])) {
    fwrite(STDERR, "{$encpath} does not contain a valid integrity report envelope\n");
    exit(1);
}

$encryptedkey = base64_decode((string) $envelope['encrypted_key'], true);
if ($encryptedkey === false) {
    fwrite(STDERR, "encrypted_key is not valid base64\n");
    exit(1);
}

// Must match the OPENSSL_PKCS1_OAEP_PADDING used by
// license_checker::encrypt_violation_payload() when this was encrypted.
if (!openssl_private_decrypt($encryptedkey, $aeskey, $privatekey, OPENSSL_PKCS1_OAEP_PADDING)) {
    fwrite(STDERR, "Failed to RSA-decrypt the AES key - wrong private key for this report? " . openssl_error_string() . "\n");
    exit(1);
}

$payloadraw = base64_decode((string) $envelope['encrypted_payload'], true);
if ($payloadraw === false || strlen($payloadraw) <= 16) {
    fwrite(STDERR, "encrypted_payload is not valid base64 or is too short to contain an IV\n");
    exit(1);
}

// The IV is not secret - it was prepended to the ciphertext before base64-encoding (see
// license_checker::encrypt_violation_payload()'s docblock), so it's simply split back off here.
$iv = substr($payloadraw, 0, 16);
$ciphertext = substr($payloadraw, 16);

$plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $aeskey, OPENSSL_RAW_DATA, $iv);
if ($plaintext === false) {
    fwrite(STDERR, "Failed to AES-decrypt the payload: " . openssl_error_string() . "\n");
    exit(1);
}

$details = json_decode($plaintext, true);
if (!is_array($details)) {
    fwrite(STDERR, "Decrypted payload is not valid JSON\n");
    exit(1);
}

$errorcode = (string) ($envelope['error_code'] ?? '(unknown)');
$siteurl = (string) ($envelope['site_url'] ?? $details['site_url'] ?? '(unknown)');
$exporttimestamp = isset($envelope['timestamp']) ? date('Y-m-d H:i:s T', (int) $envelope['timestamp']) : '(unknown)';
$violationtimestamp = isset($details['timestamp']) ? date('Y-m-d H:i:s T', (int) $details['timestamp']) : '(unknown)';
$moodleversion = (string) ($details['moodle_version'] ?? '(unknown)');
$modified = is_array($details['modified'] ?? null) ? $details['modified'] : [];
$missing = is_array($details['missing'] ?? null) ? $details['missing'] : [];

fwrite(STDOUT, "local_artqtml integrity violation report\n");
fwrite(STDOUT, "===========================================\n");
fwrite(STDOUT, "Error code:        {$errorcode}\n");
fwrite(STDOUT, "Site URL:          {$siteurl}\n");
fwrite(STDOUT, "Moodle version:    {$moodleversion}\n");
fwrite(STDOUT, "Violation logged:  {$violationtimestamp}\n");
fwrite(STDOUT, "Report exported:   {$exporttimestamp}\n");
fwrite(STDOUT, "\n");

if ($modified) {
    fwrite(STDOUT, "Modified files (" . count($modified) . "):\n");
    foreach ($modified as $path) {
        fwrite(STDOUT, "  - {$path}\n");
    }
} else {
    fwrite(STDOUT, "Modified files: none\n");
}

fwrite(STDOUT, "\n");

if ($missing) {
    fwrite(STDOUT, "Missing files (" . count($missing) . "):\n");
    foreach ($missing as $path) {
        fwrite(STDOUT, "  - {$path}\n");
    }
} else {
    fwrite(STDOUT, "Missing files: none\n");
}
