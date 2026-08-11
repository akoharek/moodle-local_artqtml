<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * License file-integrity checking: manifest verification, encrypted violation logging and the
 * vendor-facing error code / exportable report (split out of license_checker - functional spec
 * ch.10, file integrity checking).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\license;

use local_artqtml\local\license\license_constants;

/**
 * Compares the plugin's on-disk files against a license's signed manifest and records tampering.
 */
class license_file_integrity {
    /**
     * @var \stdClass|null per-request cache of the current tampered state's already-logged (or
     * freshly-logged) local_artqtml_log row. Both the warning banner and the License tab call
     * into the logging path on every request that renders them - this avoids re-running the
     * dedup lookup query (and guarantees they report the exact same error code) when both render
     * within the same page load.
     */
    protected static $cachedviolation = null;

    /**
     * Compare a license's recorded file hashes (if any) against the plugin's actual files on
     * disk right now, to detect tampering with the shipped code (e.g. stripping out this very
     * license check, or lifting the prompt-engineering templates without a license).
     *
     * Only meaningful for a license that already passed
     * {@see license_crypto::verify_signature()}: the "files" array itself is part of the signed
     * payload (see {@see license_crypto::canonical_payload()}), so an attacker can't just edit
     * the stored license's file list to match their modified files - doing so would break the
     * signature instead of the hash check.
     *
     * A license with no "files" array at all (issued before file integrity checking existed)
     * has nothing to check and is treated as passing - this is what keeps already-issued
     * licenses working unchanged until they're reissued in the new format.
     *
     * Besides checking every manifest-listed file still matches its recorded hash, this also
     * enumerates the plugin's current .php files on disk (same exclusions as the manifest was
     * built with - tools/ and docs/) and flags any file that exists now but isn't in the
     * manifest at all as "extra" - otherwise an attacker could add a brand new malicious PHP
     * file without ever touching (and thus without invalidating the hash of) any listed file.
     *
     * @param \stdClass $licenserecord the local_artqtml_license row (from
     *      {@see license_persistence::get_or_create_record()})
     * @return array{ok: bool, modified: string[], missing: string[], extra: string[]}
     */
    public static function verify(\stdClass $licenserecord): array {
        $decoded = json_decode((string) $licenserecord->licensejson, true);

        if (!is_array($decoded) || empty($decoded['files']) || !is_array($decoded['files'])) {
            return ['ok' => true, 'modified' => [], 'missing' => [], 'extra' => []];
        }

        global $CFG;
        $pluginroot = $CFG->dirroot . '/local/artqtml';

        $modified = [];
        $missing = [];
        $manifestpaths = [];
        foreach ($decoded['files'] as $entry) {
            $path = (string) ($entry['path'] ?? '');
            $expectedhash = (string) ($entry['hash'] ?? '');
            if ($path === '' || $expectedhash === '') {
                continue;
            }
            $manifestpaths[$path] = true;

            // Defensive - the path only ever comes from an already signature-verified license,
            // but a path escaping the plugin directory should never be followed regardless.
            if (strpos($path, '..') !== false || $path[0] === '/') {
                $missing[] = $path;
                continue;
            }

            $fullpath = $pluginroot . '/' . $path;
            if (!is_file($fullpath)) {
                $missing[] = $path;
                continue;
            }

            // A permissions problem or a race (file removed between the is_file() check above
            // and this call) can make hash_file() return false - and, under Moodle's developer
            // debug error handler, a filesystem-level warning here can even be raised as a
            // catchable error/exception. Either way, a file that cannot be verified must be
            // treated as tampered-with, not silently skipped or allowed to crash the whole
            // license status check (rendered on every single admin page).
            try {
                $actualhash = hash_file('sha256', $fullpath);
            } catch (\Throwable $e) {
                $actualhash = false;
            }

            if ($actualhash === false || !hash_equals($expectedhash, $actualhash)) {
                $modified[] = $path;
            }
        }

        $extra = [];
        foreach (self::enumerate_php_files($pluginroot, ['tools', 'docs', 'tests']) as $path) {
            if (!isset($manifestpaths[$path])) {
                $extra[] = $path;
            }
        }

        return [
            'ok'       => empty($modified) && empty($missing) && empty($extra),
            'modified' => $modified,
            'missing'  => $missing,
            'extra'    => $extra,
        ];
    }

    /**
     * BL-10: files that belong in the manifest despite not being *.php.
     *
     * Until 2026-08-06 both enumerators took the file extension as the whole rule, which meant a
     * COPYRIGHT or LICENSE text file could be edited or deleted with nothing noticing - the one
     * file whose whole purpose is to state the terms was the one file not covered by the integrity
     * check. András's requirement when the copyright file was decided was explicitly that it be
     * covered, so it is named here instead.
     *
     * MIRROR: tools/generate_license.php's ARTQTML_MANIFEST_EXTRA_FILES must hold the same list.
     * If the two ever disagree, every installation reports either a missing or an extra file, on
     * every admin page, and the cause is two lines apart in two files.
     */
    protected const MANIFEST_EXTRA_FILES = ['COPYRIGHT.txt'];

    /**
     * Recursively list every manifest-covered file under $root, excluding the given top-level
     * subdirectories, as paths relative to $root (forward-slashed). Mirrors
     * tools/generate_license.php's artqtml_hash_php_files() file selection exactly, so the
     * set of files this checks against matches the set the manifest was built from.
     *
     * @param string $root
     * @param string[] $excludedirs top-level directory names under $root to skip entirely
     * @return string[]
     */
    protected static function enumerate_php_files(string $root, array $excludedirs): array {
        $root = rtrim($root, '/');
        $paths = [];

        // A transient filesystem problem (e.g. a permissions hiccup) must never crash this - it
        // is called from status(), which lib.php's warning banner renders on every admin page.
        // Failing here just means the "extra file" check is skipped for this one call, not that
        // the whole plugin becomes unusable; is_file()/hash_file() above still catch tampering
        // of any manifest-listed file regardless.
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');

                // The extension test is checked first because it decides the overwhelming
                // majority; the named extras are the exception, matched on the full relative path
                // so a stray "COPYRIGHT.txt" dropped into a subdirectory is still an extra file.
                if (
                    strtolower($file->getExtension()) !== 'php'
                    && !in_array($relative, self::MANIFEST_EXTRA_FILES, true)
                ) {
                    continue;
                }

                $topdir = explode('/', $relative)[0];
                if (in_array($topdir, $excludedirs, true)) {
                    continue;
                }

                $paths[] = $relative;
            }
        } catch (\Throwable $e) {
            return [];
        }

        sort($paths);

        return $paths;
    }

    /**
     * Human-readable "AIQ-YYYYMMDD-<id>" error code for the current tampered state, logging the
     * violation (encrypted, deduplicated per calendar day) if it hasn't been logged yet today.
     *
     * Security: never returns or exposes the actual modified/missing file lists - callers (the
     * warning banner, the License tab) only ever get this opaque code back, to quote to the
     * vendor. The full details only ever exist encrypted, inside local_artqtml_log, decryptable
     * solely with the vendor's private key half of {@see license_crypto::PUBLIC_KEY_PEM} - see
     * tools/decrypt_integrity_log.php.
     *
     * @param array{modified: string[], missing: string[], extra?: string[]} $integrity as returned by
     *      {@see self::verify()}
     * @return string
     */
    public static function integrity_error_code(array $integrity): string {
        $violation = self::get_or_log_integrity_violation($integrity);

        return self::format_error_code($violation !== null ? (int) $violation->id : 0);
    }

    /**
     * Build the downloadable encrypted integrity report (a .enc file license.php streams to an
     * admin with :configure, for them to send on to the vendor) - never includes the plaintext
     * modified/missing lists itself, only the same encrypted_payload/encrypted_key already stored
     * by {@see self::get_or_log_integrity_violation()}, plus the error code/site URL/timestamp in
     * the clear (none of which reveal anything about which files were affected).
     *
     * @return array{error_code: string, content: string}|null null if there's currently nothing
     *      to export (files are fine) or encryption/logging failed
     */
    public static function export_integrity_report(): ?array {
        global $CFG;

        $integrity = self::verify(license_persistence::get_or_create_record());
        if ($integrity['ok']) {
            return null;
        }

        $violation = self::get_or_log_integrity_violation($integrity);
        if ($violation === null) {
            return null;
        }

        $data = json_decode((string) $violation->data, true);
        if (!is_array($data) || empty($data['encrypted_payload']) || empty($data['encrypted_key'])) {
            return null;
        }

        $errorcode = self::format_error_code((int) $violation->id);
        $envelope = [
            'encrypted_payload' => $data['encrypted_payload'],
            'encrypted_key'     => $data['encrypted_key'],
            'error_code'        => $errorcode,
            'site_url'          => $CFG->wwwroot,
            'timestamp'         => time(),
        ];

        return [
            'error_code' => $errorcode,
            'content'    => base64_encode(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }

    /**
     * Format a local_artqtml_log row id into the "AIQ-YYYYMMDD-<id>" error code an admin can
     * quote to the vendor. Today's date, not the log row's own timecreated, since this is a
     * human-facing reference code, not an audit timestamp.
     *
     * @param int $logid 0 if nothing could be logged (e.g. encryption failed)
     * @return string
     */
    protected static function format_error_code(int $logid): string {
        return 'AIQ-' . date('Ymd') . '-' . $logid;
    }

    /**
     * Fetch today's already-logged local_artqtml_log row for this exact modified/missing set,
     * or encrypt and log a new one if this exact set hasn't been seen yet today (Lic-0xx:
     * "only log once per unique violation set... to avoid spam" - the file-integrity check runs
     * on every single admin page load via the warning banner, so without this a site with a
     * genuinely tampered install would otherwise get one new log row per page view).
     *
     * The dedup lookup itself only ever compares a one-way SHA-256 fingerprint of the sorted
     * modified/missing lists (violation_hash, stored in the clear alongside the encrypted
     * payload) - never the file names/paths themselves, which only ever exist inside
     * encrypted_payload.
     *
     * @param array{modified: string[], missing: string[], extra?: string[]} $integrity
     * @return \stdClass|null the local_artqtml_log row, or null if nothing could be persisted
     *      (e.g. random_bytes()/openssl failed) - callers must treat this as "no error code
     *      available" rather than crash, since this runs on every admin page load
     */
    protected static function get_or_log_integrity_violation(array $integrity): ?\stdClass {
        global $DB, $CFG;

        if (self::$cachedviolation !== null) {
            return self::$cachedviolation;
        }

        $modified = $integrity['modified'];
        $missing = $integrity['missing'];
        // V20 #17: 'extra' (files present on disk but absent from the signed manifest - a brand
        // new file an attacker could have dropped in) is as diagnostically important as
        // modified/missing, so include it in both the dedup fingerprint and the encrypted payload.
        $extra = $integrity['extra'] ?? [];
        sort($modified);
        sort($missing);
        sort($extra);
        $hash = hash('sha256', json_encode(['modified' => $modified, 'missing' => $missing, 'extra' => $extra]));

        $todaystart = strtotime('today');
        $existing = $DB->get_records_select(
            'local_artqtml_log',
            'event = ? AND timecreated >= ?',
            [license_constants::INTEGRITY_VIOLATION_EVENT, $todaystart],
            'id DESC'
        );
        foreach ($existing as $row) {
            $data = json_decode((string) $row->data, true);
            if (is_array($data) && ($data['violation_hash'] ?? null) === $hash) {
                self::$cachedviolation = $row;

                return $row;
            }
        }

        $encrypted = self::encrypt_violation_payload([
            'modified'       => $modified,
            'missing'        => $missing,
            'extra'          => $extra,
            'timestamp'      => time(),
            'site_url'       => $CFG->wwwroot,
            'moodle_version' => $CFG->version,
        ]);
        if ($encrypted === null) {
            return null;
        }

        $record = new \stdClass();
        // B2: this entry is not tied to any generation. generationid is now nullable
        // (install.xml + upgrade 2026072402), so store NULL rather than 0 - a 0 would be an
        // invalid foreign key into local_artqtml_generations, which has no row with id 0.
        $record->generationid = null;
        $record->userid = null;
        $record->event = license_constants::INTEGRITY_VIOLATION_EVENT;
        $record->data = json_encode([
            'encrypted_payload' => $encrypted['payload'],
            'encrypted_key'     => $encrypted['key'],
            'violation_hash'    => $hash,
        ]);
        $record->timecreated = time();
        $record->id = $DB->insert_record('local_artqtml_log', $record);

        // The log_id field is self-referential (the exported .enc report also quotes it, as error_code) -
        // only known once the insert above has assigned it, hence the follow-up update rather
        // than including it in the first insert.
        $datawithid = json_decode($record->data, true);
        $datawithid['log_id'] = $record->id;
        $record->data = json_encode($datawithid);
        $DB->set_field('local_artqtml_log', 'data', $record->data, ['id' => $record->id]);

        self::$cachedviolation = $record;

        return $record;
    }

    /**
     * AES-256-CBC encrypt $details (as JSON) with a fresh random key, then RSA/OAEP-encrypt that
     * key with the plugin's embedded public key half - so only the vendor, who alone holds the
     * matching private key, can ever recover either the key or the plaintext details.
     *
     * @param array $details plaintext fields to encrypt: modified, missing, extra, timestamp, site_url,
     *      moodle_version
     * @return array{payload: string, key: string}|null base64-encoded ciphertext/wrapped-key
     *      pair, or null on any random-number-generator or openssl failure
     */
    protected static function encrypt_violation_payload(array $details): ?array {
        try {
            $key = random_bytes(32);
            $iv = random_bytes(16);
        } catch (\Throwable $e) {
            return null;
        }

        $plaintext = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            return null;
        }

        $publickey = openssl_pkey_get_public(license_crypto::PUBLIC_KEY_PEM);
        if ($publickey === false) {
            return null;
        }

        // OAEP, not the default PKCS1 v1.5 padding - both are within the ~245-byte capacity of a
        // 2048-bit key for a 32-byte AES key, but OAEP is the modern recommended choice.
        // tools/decrypt_integrity_log.php must use the matching padding constant to decrypt this.
        if (!openssl_public_encrypt($key, $encryptedkey, $publickey, OPENSSL_PKCS1_OAEP_PADDING)) {
            return null;
        }

        return [
            // The IV isn't secret - prepending it to the ciphertext before base64-encoding carries
            // it alongside without a separate JSON field; tools/decrypt_integrity_log.php splits
            // it back off (first 16 bytes) before AES-decrypting the remainder.
            'payload' => base64_encode($iv . $ciphertext),
            'key'     => base64_encode($encryptedkey),
        ];
    }
}
