<?php
/**
 * Standalone dev/QA tool: generates a signed local_artqtml .lic license file, including a
 * SHA-256 manifest of every plugin PHP file for tamper detection (functional spec ch.10,
 * file integrity checking).
 *
 * Supersedes the older tools/sign_license.php, which had no file integrity manifest - any
 * license issued by that script has no "files" key, so it verifies and behaves exactly as
 * before (see classes/local/license_checker.php::canonical_payload()'s handling of a missing
 * "files" key), but obviously gets none of the tamper-detection protection this tool adds.
 *
 * NOT part of the shipped plugin - excluded from the deployment zip (see CLAUDE.md). Runs
 * standalone on a developer machine with plain PHP + the openssl extension, no Moodle
 * bootstrap, so it deliberately does not use require()/include() at all (CLAUDE.md's "no
 * require/include outside Moodle bootstrap" rule is about the plugin runtime; this script
 * duplicates the ~10 lines of canonical-payload logic from
 * classes/local/license_checker.php::canonical_payload() instead of pulling that file in - the
 * two must be kept in sync by hand if that method ever changes).
 *
 * Important operational note: the file manifest is locked to the exact file contents at the
 * moment this script runs. Any later legitimate code change (a bug fix, a version bump, anything)
 * will make an already-issued license's file integrity check start failing for that install,
 * since its hashes now point to an older snapshot. Every plugin update that ships to a customer
 * who has file integrity enabled needs a freshly regenerated, re-issued .lic file alongside it -
 * this tool does not (and structurally cannot) distinguish "legitimate update" from "tampering".
 *
 * Usage:
 *   php tools/generate_license.php \
 *     --private-key=/path/to/artqtml_license_private.pem \
 *     --edition=perpetual|annual|question_limit \
 *     --issued-to="Example University" \
 *     --issued-to-url="https://moodle.example.edu" \
 *     --issued-at=2026-07-17 \
 *     [--question-limit=5000]        (required, question_limit only) \
 *     [--plugin-root=/path/to/local_artqtml]  (defaults to this script's parent directory) \
 *     [--no-file-integrity]          (omit the "files" manifest entirely - old-style license) \
 *     --out=/path/to/output.lic
 *
 * The private key is never checked into the plugin repository - keep it outside version
 * control and only on machines that are allowed to issue licenses.
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

function artqtml_canonical_payload(array $fields): string {
    $expiresat = $fields['expires_at'] ?? null;
    $questionlimit = $fields['question_limit'] ?? null;

    $ordered = [
        'edition'        => (string) ($fields['edition'] ?? ''),
        'issued_to'      => (string) ($fields['issued_to'] ?? ''),
        'issued_to_url'  => (string) ($fields['issued_to_url'] ?? ''),
        'issued_at'      => (string) ($fields['issued_at'] ?? ''),
        'expires_at'     => $expiresat !== null ? (string) $expiresat : null,
        'question_limit' => $questionlimit !== null ? (int) $questionlimit : null,
    ];

    if (isset($fields['files']) && is_array($fields['files'])) {
        $files = array_map(static function ($entry) {
            return [
                'path' => (string) ($entry['path'] ?? ''),
                'hash' => (string) ($entry['hash'] ?? ''),
            ];
        }, $fields['files']);
        usort($files, static function ($a, $b) {
            return strcmp($a['path'], $b['path']);
        });
        $ordered['files'] = $files;
    }

    return json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * BL-10: files that belong in the manifest despite not being *.php.
 *
 * MIRROR: license_file_integrity::MANIFEST_EXTRA_FILES must hold the same list. This script runs
 * as plain PHP CLI with no Moodle bootstrap, so it cannot read that constant - the duplication is
 * deliberate, and so is this comment. If the two disagree, every installation reports either a
 * missing or an extra file on every admin page.
 */
const ARTQTML_MANIFEST_EXTRA_FILES = ['COPYRIGHT.txt'];

/**
 * Recursively find every manifest-covered file under $root, excluding the given top-level
 * subdirectories, and return a path (relative to $root, forward-slashed) => sha256 hash map.
 *
 * @param string $root
 * @param string[] $excludedirs top-level directory names under $root to skip entirely
 * @return array<string, string>
 */
function artqtml_hash_php_files(string $root, array $excludedirs): array {
    $root = rtrim($root, '/');
    $hashes = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');

        if (
            strtolower($file->getExtension()) !== 'php'
            && !in_array($relative, ARTQTML_MANIFEST_EXTRA_FILES, true)
        ) {
            continue;
        }

        $topdir = explode('/', $relative)[0];
        if (in_array($topdir, $excludedirs, true)) {
            continue;
        }

        $hashes[$relative] = hash_file('sha256', $file->getPathname());
    }

    ksort($hashes);

    return $hashes;
}

$args = artqtml_parse_args($argv);

$required = ['private-key', 'edition', 'issued-to', 'issued-to-url', 'issued-at', 'out'];
foreach ($required as $key) {
    if (empty($args[$key])) {
        fwrite(STDERR, "Missing required --{$key}\n");
        exit(1);
    }
}

$edition = $args['edition'];
if (!in_array($edition, ['perpetual', 'annual', 'question_limit'], true)) {
    fwrite(STDERR, "Invalid --edition: must be perpetual, annual or question_limit\n");
    exit(1);
}

if ($edition === 'question_limit' && empty($args['question-limit'])) {
    fwrite(STDERR, "--question-limit is required for edition=question_limit\n");
    exit(1);
}

$privatekeypath = $args['private-key'];
if (!is_readable($privatekeypath)) {
    fwrite(STDERR, "Cannot read private key: {$privatekeypath}\n");
    exit(1);
}

$privatekey = openssl_pkey_get_private('file://' . $privatekeypath);
if ($privatekey === false) {
    fwrite(STDERR, "Failed to load private key: " . openssl_error_string() . "\n");
    exit(1);
}

// M-02: an annual license's expiry is always exactly 365 days after issuance - not an
// arbitrary date an operator could otherwise mistype or pick inconsistently.
$expiresat = null;
if ($edition === 'annual') {
    $expiresat = date('Y-m-d', strtotime($args['issued-at'] . ' +365 days'));
}

$fields = [
    'edition'        => $edition,
    'issued_to'      => $args['issued-to'],
    'issued_to_url'  => $args['issued-to-url'],
    'issued_at'      => $args['issued-at'],
    'expires_at'     => $expiresat,
    'question_limit' => $edition === 'question_limit' ? (int) $args['question-limit'] : null,
];

if (empty($args['no-file-integrity'])) {
    $pluginroot = !empty($args['plugin-root']) ? (string) $args['plugin-root'] : dirname(__DIR__);
    if (!is_dir($pluginroot)) {
        fwrite(STDERR, "--plugin-root is not a directory: {$pluginroot}\n");
        exit(1);
    }

    $hashes = artqtml_hash_php_files($pluginroot, ['tools', 'docs', 'tests']);
    $fields['files'] = [];
    foreach ($hashes as $path => $hash) {
        $fields['files'][] = ['path' => $path, 'hash' => $hash];
    }

    fwrite(STDOUT, 'Hashed ' . count($fields['files']) . " PHP file(s) under {$pluginroot} (excluding tools/, docs/, tests/)\n");
}

$payload = artqtml_canonical_payload($fields);

$signature = '';
if (!openssl_sign($payload, $signature, $privatekey, OPENSSL_ALGO_SHA256)) {
    fwrite(STDERR, "Signing failed: " . openssl_error_string() . "\n");
    exit(1);
}

$fields['signature'] = base64_encode($signature);

$json = json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (file_put_contents($args['out'], $json) === false) {
    fwrite(STDERR, "Failed to write {$args['out']}\n");
    exit(1);
}

fwrite(STDOUT, "Wrote " . $args['out'] . "\n");
