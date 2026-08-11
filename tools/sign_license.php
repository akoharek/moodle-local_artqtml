<?php
/**
 * Standalone dev/QA tool: signs a local_artqtml .lic license file for testing the plugin's
 * Licensz admin tab (functional spec ch.10) against a real signature.
 *
 * NOT part of the shipped plugin - excluded from the deployment zip (see CLAUDE.md). Runs
 * standalone on a developer machine with plain PHP + the openssl extension, no Moodle
 * bootstrap, so it deliberately does not use require()/include() at all (CLAUDE.md's "no
 * require/include outside Moodle bootstrap" rule is about the plugin runtime; this script
 * duplicates the ~10 lines of canonical-payload logic from
 * classes/local/license_checker.php::canonical_payload() instead of pulling that file in).
 *
 * Usage:
 *   php tools/sign_license.php \
 *     --private-key=/path/to/artqtml_license_private.pem \
 *     --edition=perpetual|annual|question_limit \
 *     --issued-to="Example University" \
 *     --issued-to-url="https://moodle.example.edu" \
 *     --issued-at=2026-07-17 \
 *     [--expires-at=2027-07-17]      (required, annual only) \
 *     [--question-limit=5000]        (required, question_limit only) \
 *     --out=/path/to/output.lic
 *
 * The private key is never checked into the plugin repository - keep it outside version
 * control and only on machines that are allowed to issue licenses.
 */

function artqtml_parse_args(array $argv): array {
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $matches)) {
            $args[$matches[1]] = $matches[2];
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

    return json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

if ($edition === 'annual' && empty($args['expires-at'])) {
    fwrite(STDERR, "--expires-at is required for edition=annual\n");
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

$fields = [
    'edition'        => $edition,
    'issued_to'      => $args['issued-to'],
    'issued_to_url'  => $args['issued-to-url'],
    'issued_at'      => $args['issued-at'],
    'expires_at'     => $edition === 'annual' ? $args['expires-at'] : null,
    'question_limit' => $edition === 'question_limit' ? (int) $args['question-limit'] : null,
];

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
