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

namespace local_artqtml\local\license;

/**
 * Unit tests for the license RSA/SHA-256 crypto layer (functional spec ch.10, Lic-001/002).
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\license\license_crypto
 */
final class license_crypto_test extends \advanced_testcase {
    /**
     * Load a signed .lic fixture as a decoded assoc array.
     *
     * @param string $edition perpetual|annual|question_limit
     * @return array
     */
    protected function load_license(string $edition): array {
        $raw = file_get_contents(__DIR__ . '/../../fixtures/licenses/' . $edition . '.lic');
        return json_decode($raw, true);
    }

    /**
     * The canonical payload keeps a fixed key order and normalises types, independent of the
     * order the input fields happen to arrive in.
     */
    public function test_canonical_payload_field_order_and_types(): void {
        $payload = license_crypto::canonical_payload([
            'signature'      => 'ignored',
            'question_limit' => '250',
            'issued_at'      => '2026-01-01',
            'edition'        => 'question_limit',
            'issued_to'      => 'Test University',
            'issued_to_url'  => 'https://moodle.test.edu',
            'expires_at'     => null,
        ]);

        // Deterministic order; question_limit cast to int; expires_at preserved as null; the
        // stray "signature" key never leaks into the signed payload.
        $this->assertSame(
            '{"edition":"question_limit","issued_to":"Test University",'
                . '"issued_to_url":"https://moodle.test.edu","issued_at":"2026-01-01",'
                . '"expires_at":null,"question_limit":250}',
            $payload
        );
    }

    /**
     * The optional files manifest is sorted by path so the signature never depends on the order
     * the generation tool walked the filesystem in.
     */
    public function test_canonical_payload_sorts_files(): void {
        $payload = license_crypto::canonical_payload([
            'edition'   => 'perpetual',
            'issued_to' => 'X',
            'files'     => [
                ['path' => 'lib.php', 'hash' => 'bbb'],
                ['path' => 'classes/a.php', 'hash' => 'aaa'],
            ],
        ]);

        $decoded = json_decode($payload, true);
        $this->assertSame(['classes/a.php', 'lib.php'], array_column($decoded['files'], 'path'));
    }

    /**
     * A license with no files key produces a payload with no files key (pre-integrity licenses
     * must keep verifying exactly as before).
     */
    public function test_canonical_payload_omits_files_when_absent(): void {
        $payload = license_crypto::canonical_payload(['edition' => 'perpetual', 'issued_to' => 'X']);
        $this->assertStringNotContainsString('files', $payload);
    }

    /**
     * A genuinely signed license fixture verifies against the embedded public key.
     */
    public function test_verify_signature_accepts_valid_license(): void {
        $this->assertTrue(license_crypto::verify_signature($this->load_license('perpetual')));
        $this->assertTrue(license_crypto::verify_signature($this->load_license('annual')));
        $this->assertTrue(license_crypto::verify_signature($this->load_license('question_limit')));
    }

    /**
     * Any tampering with a signed field breaks verification.
     */
    public function test_verify_signature_rejects_tampered_field(): void {
        $decoded = $this->load_license('perpetual');
        $decoded['issued_to'] = 'Somebody Else';
        $this->assertFalse(license_crypto::verify_signature($decoded));
    }

    /**
     * Missing / non-string / undecodable / wrong signatures are all rejected without error.
     */
    public function test_verify_signature_rejects_bad_signatures(): void {
        $base = $this->load_license('perpetual');

        $missing = $base;
        unset($missing['signature']);
        $this->assertFalse(license_crypto::verify_signature($missing));

        $nonstring = $base;
        $nonstring['signature'] = ['not', 'a', 'string'];
        $this->assertFalse(license_crypto::verify_signature($nonstring));

        $badbase64 = $base;
        $badbase64['signature'] = '@@@ not base64 @@@';
        $this->assertFalse(license_crypto::verify_signature($badbase64));

        $wrongsig = $base;
        $wrongsig['signature'] = base64_encode('this is not a real signature');
        $this->assertFalse(license_crypto::verify_signature($wrongsig));
    }
}
