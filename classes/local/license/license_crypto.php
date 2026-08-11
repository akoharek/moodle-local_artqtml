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
 * License cryptography: RSA signature verification and canonical payload building
 * (split out of license_checker - functional spec ch.10).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\license;

/**
 * Stateless RSA/SHA-256 signature verification for .lic files.
 */
class license_crypto {
    /**
     * @var string PEM-encoded RSA public key used to verify license file signatures.
     * Public (not secret - it is the public half only): license_file_integrity's encrypted
     * violation logging also RSA-wraps its AES key with this same embedded key.
     */
    public const PUBLIC_KEY_PEM = <<<PEM
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAxR8BOzLPvL1hTlGdYn6d
EliF58+q4dOL1vnjyishXEVVbypCErJDrKFZ+1A1sHvR2abvk7QiUoS+NwoDvwzv
cgqN/GxYfhbPpiUdA/U8HO168dtfQ2DQZ1h9BhngEcvtRS2xaF1cN2WKMhMFBJGe
yxbTxO2mblIcUdaykGDiwfQs4Q6VidtaQNXOH0j21g422OvEjyoNDvMdbSKOz0DD
zEIAoEGczgqALxcXAKyDYAjPlC0922kHiCHtBpwI477QDX4GgVcMRg3M3dcEH4qP
PAMGhBU49lv4nxBe3b2AsnuryXw6pW+g3GoErAA6QbAtk+MOlRpqWM2tFMY+QSDn
iQIDAQAB
-----END PUBLIC KEY-----
PEM;

    /**
     * Build the canonical, deterministically-ordered JSON payload that gets signed/verified.
     *
     * The optional "files" array (path/hash pairs, file integrity checking) is included in the
     * signed payload only when present in $fields, sorted by path so the signature never depends
     * on the order the license-generation tool happened to walk the filesystem in. This keeps
     * older licenses issued before file integrity checking existed (no "files" key at all)
     * verifying exactly as they always did - the signature simply never covered a files list for
     * those, so its absence doesn't change what gets hashed/signed for them.
     *
     * @param array $fields decoded license fields (edition, issued_to, issued_to_url,
     *      issued_at, expires_at, question_limit, optionally files) - extra keys (e.g.
     *      "signature") are ignored
     * @return string
     */
    public static function canonical_payload(array $fields): string {
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
            $files = array_map(
                static function ($entry) {
                    return [
                        'path' => (string) ($entry['path'] ?? ''),
                        'hash' => (string) ($entry['hash'] ?? ''),
                    ];
                },
                $fields['files']
            );
            usort($files, static function ($a, $b) {
                return strcmp($a['path'], $b['path']);
            });
            $ordered['files'] = $files;
        }

        return json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Verify a decoded license file's digital signature against the embedded public key.
     *
     * @param array $decoded json_decode()-d content of the .lic file (assoc array)
     * @return bool
     */
    public static function verify_signature(array $decoded): bool {
        if (empty($decoded['signature']) || !is_string($decoded['signature'])) {
            return false;
        }

        $signature = base64_decode($decoded['signature'], true);
        if ($signature === false) {
            return false;
        }

        $publickey = openssl_pkey_get_public(self::PUBLIC_KEY_PEM);
        if ($publickey === false) {
            return false;
        }

        $payload = self::canonical_payload($decoded);

        return openssl_verify($payload, $signature, $publickey, OPENSSL_ALGO_SHA256) === 1;
    }
}
