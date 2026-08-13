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

namespace local_artqtml\local;

/**
 * Admin-visible API-key banner and status Retry/Back layout.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml_apikey_decrypt_notice
 * @covers     \local_artqtml_apikey_start_error
 */
final class apikey_admin_notice_test extends \advanced_testcase {
    /**
     * Load lib.php helpers used by the banner.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/local/artqtml/lib.php');
    }

    /**
     * Teachers on the generation list do not see the API-key banner.
     */
    public function test_notice_hidden_from_teachers(): void {
        $this->resetAfterTest();
        unset_config('claudeapikey', 'local_artqtml');
        unset_config('geminiapikey', 'local_artqtml');

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertSame('', \local_artqtml_apikey_decrypt_notice());
        $this->assertSame(
            get_string('errormissingapikey', 'local_artqtml'),
            \local_artqtml_apikey_start_error()
        );
    }

    /**
     * Site admins see a persistent banner when keys are missing.
     */
    public function test_notice_shown_to_admin_when_keys_missing(): void {
        $this->resetAfterTest();
        unset_config('claudeapikey', 'local_artqtml');
        unset_config('geminiapikey', 'local_artqtml');
        $this->setAdminUser();

        $html = \local_artqtml_apikey_decrypt_notice();
        $this->assertStringContainsString('artqtml-apikey-decrypt-notice', $html);
        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString(
            get_string('apikeyupgradeunrecoverable', 'local_artqtml'),
            $html
        );
        $this->assertSame(
            get_string('apikeyupgradeunrecoverable', 'local_artqtml'),
            \local_artqtml_apikey_start_error()
        );
    }

    /**
     * Configure-capable users see the banner when stored ciphertext cannot be decrypted.
     */
    public function test_notice_shown_when_ciphertext_unreadable(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $bogus = 'sodium:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 32));
        set_config('claudeapikey', $bogus, 'local_artqtml');
        set_config('geminiapikey', \core\encryption::encrypt('gemini-ok'), 'local_artqtml');

        $html = \local_artqtml_apikey_decrypt_notice();
        $this->assertDebuggingCalled();
        $this->assertStringContainsString('artqtml-apikey-decrypt-notice', $html);
    }

    /**
     * Readable keys hide the banner.
     */
    public function test_notice_hidden_when_keys_readable(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('claudeapikey', \core\encryption::encrypt('sk-ant-ok'), 'local_artqtml');
        set_config('geminiapikey', \core\encryption::encrypt('gemini-ok'), 'local_artqtml');

        $this->assertSame('', \local_artqtml_apikey_decrypt_notice());
        $this->assertDebuggingNotCalled();
    }

    /**
     * Banner is echoed on plugin pages; Retry/Back sit in a flex button row.
     */
    public function test_notice_is_echoed_on_plugin_pages(): void {
        $root = dirname(__DIR__, 2);
        $this->assertStringContainsString(
            'echo local_artqtml_apikey_decrypt_notice();',
            file_get_contents($root . '/index.php')
        );
        $this->assertStringContainsString(
            'echo local_artqtml_apikey_decrypt_notice();',
            file_get_contents($root . '/status.php')
        );
        $this->assertStringContainsString(
            'echo local_artqtml_apikey_decrypt_notice();',
            file_get_contents($root . '/generate.php')
        );
        $this->assertStringContainsString(
            'encrypted_config::any_unusable()',
            file_get_contents($root . '/generate.php')
        );
        $this->assertStringContainsString(
            'artqtml-buttonrow',
            file_get_contents($root . '/status.php')
        );
        $this->assertStringNotContainsString(
            'singlebutton d-inline mr-2',
            file_get_contents($root . '/status.php')
        );
    }
}
