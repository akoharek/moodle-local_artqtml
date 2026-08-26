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

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for encrypted admin API-key storage and plaintext upgrade migration.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\encrypted_config
 * @covers     \local_artqtml\local\api_key_store
 * @covers     \local_artqtml\admin\setting_encryptedapikey
 */
final class encrypted_config_test extends \advanced_testcase {
    /**
     * Leftover plaintext (no sodium: prefix) is re-encrypted in place and still readable.
     */
    public function test_plaintext_is_reencrypted_on_read(): void {
        $this->resetAfterTest();

        set_config('claudeapikey', 'sk-ant-plain-leftover', 'local_artqtml');

        $this->assertSame('sk-ant-plain-leftover', encrypted_config::get('claudeapikey'));
        $this->assertDebuggingNotCalled();

        $stored = (string) get_config('local_artqtml', 'claudeapikey');
        $this->assertTrue(encrypted_config::is_encrypted_value($stored));
        $this->assertSame('sk-ant-plain-leftover', \core\encryption::decrypt($stored));
        $this->assertSame('sk-ant-plain-leftover', api_key_store::get('claude'));
    }

    /**
     * Already-encrypted values decrypt without being written again.
     */
    public function test_encrypted_value_is_not_double_encrypted(): void {
        $this->resetAfterTest();

        $encrypted = \core\encryption::encrypt('sk-ant-already');
        set_config('claudeapikey', $encrypted, 'local_artqtml');

        $this->assertSame('sk-ant-already', encrypted_config::get('claudeapikey'));
        $this->assertSame($encrypted, get_config('local_artqtml', 'claudeapikey'));
        $this->assertDebuggingNotCalled();
    }

    /**
     * Ciphertext that fails integrity cannot be recovered; stored value is left in place.
     */
    public function test_unreadable_ciphertext_is_empty_and_logged_once(): void {
        $this->resetAfterTest();

        $bogus = 'sodium:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 32));
        set_config('claudeapikey', $bogus, 'local_artqtml');

        $this->assertSame('', encrypted_config::get('claudeapikey'));
        $this->assertDebuggingCalled();
        $this->assertSame(['claudeapikey'], encrypted_config::failed_names());
        $this->assertSame($bogus, get_config('local_artqtml', 'claudeapikey'));

        $this->assertSame('', encrypted_config::get('claudeapikey'));
        $this->assertDebuggingNotCalled();
        $this->assertSame('', api_key_store::get('claude'));
        $this->assertDebuggingNotCalled();
    }

    /**
     * Upgrade migrates leftover plaintext keys and records unreadable ciphertext.
     */
    public function test_upgrade_migrates_plaintext_and_flags_unreadable(): void {
        $this->resetAfterTest();

        set_config('claudeapikey', 'sk-ant-upgrade-plain', 'local_artqtml');
        $bogus = 'sodium:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 32));
        set_config('geminiapikey', $bogus, 'local_artqtml');

        encrypted_config::migrate_plaintext_on_upgrade();
        $this->assertDebuggingCalled();

        $claudestored = (string) get_config('local_artqtml', 'claudeapikey');
        $this->assertTrue(encrypted_config::is_encrypted_value($claudestored));
        $this->assertSame('sk-ant-upgrade-plain', \core\encryption::decrypt($claudestored));

        $this->assertSame($bogus, get_config('local_artqtml', 'geminiapikey'));
        $this->assertSame(['geminiapikey'], encrypted_config::failed_names());
    }

    /**
     * The admin setting shows leftover plaintext (so it can be copied) and encrypts it.
     */
    public function test_setting_get_migrates_plaintext_for_display(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/adminlib.php');

        set_config('claudeapikey', 'sk-ant-from-form', 'local_artqtml');
        $setting = new \local_artqtml\admin\setting_encryptedapikey(
            'local_artqtml/claudeapikey',
            'Claude',
            'desc',
            ''
        );

        $this->assertSame('sk-ant-from-form', $setting->get_setting());
        $this->assertTrue(encrypted_config::is_encrypted_value((string) get_config('local_artqtml', 'claudeapikey')));
        $this->assertDebuggingNotCalled();
    }

    /**
     * Re-saving a valid key clears the unrecoverable notice.
     */
    public function test_write_setting_clears_unrecoverable_notice(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/adminlib.php');

        $bogus = 'sodium:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 32));
        set_config('claudeapikey', $bogus, 'local_artqtml');
        encrypted_config::get('claudeapikey');
        $this->assertDebuggingCalled();
        $this->assertSame(['claudeapikey'], encrypted_config::failed_names());

        $setting = new \local_artqtml\admin\setting_encryptedapikey(
            'local_artqtml/claudeapikey',
            'Claude',
            'desc',
            ''
        );
        $this->assertSame('', $setting->write_setting('sk-ant-new'));
        $this->assertSame([], encrypted_config::failed_names());
        $this->assertSame('sk-ant-new', $setting->get_setting());
        $this->assertDebuggingNotCalled();
    }

    /**
     * Empty password POST must not delete a stored encrypted key.
     */
    public function test_write_setting_empty_does_not_wipe_stored_key(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/adminlib.php');

        $encrypted = \core\encryption::encrypt('sk-ant-keep-me');
        set_config('claudeapikey', $encrypted, 'local_artqtml');

        $setting = new \local_artqtml\admin\setting_encryptedapikey(
            'local_artqtml/claudeapikey',
            'Claude',
            'desc',
            ''
        );
        $this->assertSame('', $setting->write_setting(''));
        $this->assertSame('', $setting->write_setting('   '));
        $this->assertSame($encrypted, get_config('local_artqtml', 'claudeapikey'));
        $this->assertSame('sk-ant-keep-me', $setting->get_setting());
        $this->assertDebuggingNotCalled();
    }

    /**
     * Empty password POST must not delete leftover plaintext or unreadable ciphertext.
     */
    public function test_write_setting_empty_does_not_wipe_plaintext_or_unreadable(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/adminlib.php');

        set_config('claudeapikey', 'sk-ant-plain-keep', 'local_artqtml');
        $bogus = 'sodium:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 32));
        set_config('geminiapikey', $bogus, 'local_artqtml');
        encrypted_config::get('geminiapikey');
        $this->assertDebuggingCalled();
        $this->assertSame(['geminiapikey'], encrypted_config::failed_names());

        $claude = new \local_artqtml\admin\setting_encryptedapikey(
            'local_artqtml/claudeapikey',
            'Claude',
            'desc',
            ''
        );
        $gemini = new \local_artqtml\admin\setting_encryptedapikey(
            'local_artqtml/geminiapikey',
            'Gemini',
            'desc',
            ''
        );
        $this->assertSame('', $claude->write_setting(''));
        $this->assertSame('', $gemini->write_setting(''));

        $this->assertSame('sk-ant-plain-keep', get_config('local_artqtml', 'claudeapikey'));
        $this->assertSame($bogus, get_config('local_artqtml', 'geminiapikey'));
        $this->assertSame(['geminiapikey'], encrypted_config::failed_names());
    }

    /**
     * A value without a Moodle encryption prefix is leftover plaintext, not ciphertext.
     */
    public function test_is_encrypted_value_detects_moodle_envelope(): void {
        $this->assertFalse(encrypted_config::is_encrypted_value('sk-ant-plain'));
        $this->assertFalse(encrypted_config::is_encrypted_value(''));
        $this->assertTrue(encrypted_config::is_encrypted_value('sodium:abc'));
        $this->assertTrue(encrypted_config::is_encrypted_value('openssl-aes-256-ctr:abc'));
    }

    /**
     * Missing keys and unreadable ciphertext both count as unusable.
     */
    public function test_any_unusable_detects_empty_and_unreadable(): void {
        $this->resetAfterTest();

        unset_config('claudeapikey', 'local_artqtml');
        unset_config('geminiapikey', 'local_artqtml');
        $this->assertTrue(encrypted_config::any_unusable());
        $this->assertDebuggingNotCalled();

        set_config('claudeapikey', \core\encryption::encrypt('sk-ant-ok'), 'local_artqtml');
        set_config('geminiapikey', \core\encryption::encrypt('gemini-ok'), 'local_artqtml');
        $this->assertFalse(encrypted_config::any_unusable());
        $this->assertDebuggingNotCalled();

        $bogus = 'sodium:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 32));
        set_config('geminiapikey', $bogus, 'local_artqtml');
        $this->assertTrue(encrypted_config::any_unusable());
        $this->assertDebuggingCalled();
    }

    /**
     * Sites at 2026081300 still need the plaintext-key migration savepoint.
     */
    public function test_upgrade_migrates_api_keys_at_2026081301(): void {
        $upgrade = file_get_contents(__DIR__ . '/../../db/upgrade.php');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$oldversion\s*<\s*2026081301\s*\).*migrate_plaintext_on_upgrade.*' .
                'upgrade_plugin_savepoint\s*\(\s*true\s*,\s*2026081301/s',
            $upgrade,
            'db/upgrade.php must re-encrypt leftover plaintext API keys at savepoint 2026081301'
        );
    }
}
