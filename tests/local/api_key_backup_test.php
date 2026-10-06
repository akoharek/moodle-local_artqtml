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
 * Unit tests for moodledata API key backup/restore.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\api_key_backup
 */
final class api_key_backup_test extends \advanced_testcase {
    /**
     * Backup and restore round-trip after config_plugins rows are cleared.
     */
    public function test_restore_missing_after_db_reset(): void {
        global $CFG;

        $this->resetAfterTest();

        $encrypted = \core\encryption::encrypt('sk-ant-backup-test');
        set_config('claudeapikey', $encrypted, 'local_artqtml');
        api_key_backup::backup_setting('claudeapikey', $encrypted);

        unset_config('claudeapikey', 'local_artqtml');
        $this->assertFalse(get_config('local_artqtml', 'claudeapikey'));

        $restored = api_key_backup::restore_missing();
        $this->assertSame(['claudeapikey'], $restored);
        $this->assertSame($encrypted, get_config('local_artqtml', 'claudeapikey'));
        $this->assertSame('sk-ant-backup-test', encrypted_config::get('claudeapikey'));

        @unlink($CFG->dataroot . '/local_artqtml/' . api_key_backup::FILENAME);
        @rmdir($CFG->dataroot . '/local_artqtml');
    }

    /**
     * Restore does not overwrite an existing config row.
     */
    public function test_restore_does_not_overwrite_existing(): void {
        global $CFG;

        $this->resetAfterTest();

        $old = \core\encryption::encrypt('sk-ant-old');
        $backup = \core\encryption::encrypt('sk-ant-from-backup');
        set_config('claudeapikey', $old, 'local_artqtml');
        api_key_backup::backup_setting('claudeapikey', $backup);

        $restored = api_key_backup::restore_missing();
        $this->assertSame([], $restored);
        $this->assertSame('sk-ant-old', encrypted_config::get('claudeapikey'));

        @unlink($CFG->dataroot . '/local_artqtml/' . api_key_backup::FILENAME);
        @rmdir($CFG->dataroot . '/local_artqtml');
    }

    /**
     * encrypted_config::get() triggers restore when config is empty.
     */
    public function test_encrypted_config_get_restores_from_backup(): void {
        global $CFG;

        $this->resetAfterTest();

        $encrypted = \core\encryption::encrypt('sk-ant-auto-restore');
        api_key_backup::backup_setting('claudeapikey', $encrypted);

        $this->assertSame('sk-ant-auto-restore', encrypted_config::get('claudeapikey'));
        $this->assertSame($encrypted, get_config('local_artqtml', 'claudeapikey'));

        @unlink($CFG->dataroot . '/local_artqtml/' . api_key_backup::FILENAME);
        @rmdir($CFG->dataroot . '/local_artqtml');
    }

    /**
     * Plain settings (model ids) restore after config_plugins rows are cleared.
     */
    public function test_restore_missing_plain_settings(): void {
        global $CFG;

        $this->resetAfterTest();

        set_config('claudemodel', 'claude-sonnet-4-20250514', 'local_artqtml');
        api_key_backup::backup_plain_setting('claudemodel', 'claude-sonnet-4-20250514');

        unset_config('claudemodel', 'local_artqtml');
        $restored = api_key_backup::restore_missing();
        $this->assertContains('claudemodel', $restored);
        $this->assertSame('claude-sonnet-4-20250514', get_config('local_artqtml', 'claudemodel'));

        @unlink($CFG->dataroot . '/local_artqtml/' . api_key_backup::FILENAME);
        @rmdir($CFG->dataroot . '/local_artqtml');
    }
}
