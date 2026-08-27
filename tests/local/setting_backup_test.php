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
 * Unit tests for the migration setting backup.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\setting_backup
 */
final class setting_backup_test extends \advanced_testcase {
    /**
     * The previous value is stored under <setting>_backup_<version> before the change.
     */
    public function test_backup_stores_the_previous_value(): void {
        $this->resetAfterTest();

        set_config('validatorprompttemplate', 'the administrator custom template', 'local_artqtml');

        $key = setting_backup::backup('validatorprompttemplate', 2026072602);

        $this->assertSame('validatorprompttemplate_backup_2026072602', $key);
        $this->assertSame('the administrator custom template', get_config('local_artqtml', $key));

        // The migration then overwrites the live value; the backup is unaffected.
        set_config('validatorprompttemplate', 'the new shipped template', 'local_artqtml');
        $this->assertSame('the administrator custom template', get_config('local_artqtml', $key));
    }

    /**
     * Nothing set means nothing to lose - no backup key and no notice noise.
     *
     * Uses a setting with no shipped default on purpose: for one that HAS a default, get_config()
     * Returns that default even when the administrator never touched it, so the "no value" branch
     * Is unreachable through it. That is also the honest limitation of this helper - it cannot tell
     * An untouched default from a deliberate choice, so it backs both up. Backing up a value that
     * Turns out to be the default is harmless; the reverse would not be.
     */
    public function test_no_backup_when_there_is_no_value(): void {
        $this->resetAfterTest();

        $this->assertNull(setting_backup::backup('neverconfiguredsetting', 2026072602));
        $this->assertSame([], setting_backup::pending_notices());

        set_config('neverconfiguredsetting', '', 'local_artqtml');
        $this->assertNull(setting_backup::backup('neverconfiguredsetting', 2026072602));
        $this->assertSame([], setting_backup::pending_notices());
    }

    /**
     * Backups must not overwrite an earlier backup: a second backup at the same version keeps the first, which is
     * The more original value, and takes a suffixed key instead.
     */
    public function test_an_existing_backup_is_never_overwritten(): void {
        $this->resetAfterTest();

        set_config('validatorprompttemplate', 'original', 'local_artqtml');
        $first = setting_backup::backup('validatorprompttemplate', 2026072602);

        set_config('validatorprompttemplate', 'already migrated once', 'local_artqtml');
        $second = setting_backup::backup('validatorprompttemplate', 2026072602);

        $this->assertNotSame($first, $second);
        $this->assertSame('validatorprompttemplate_backup_2026072602_2', $second);
        $this->assertSame('original', get_config('local_artqtml', $first), 'the first backup was clobbered');
        $this->assertSame('already migrated once', get_config('local_artqtml', $second));
    }

    /**
     * An encrypted setting's backup is encrypted too - a backup must not downgrade a secret to plaintext.
     */
    public function test_encrypted_setting_is_backed_up_encrypted(): void {
        $this->resetAfterTest();

        $plain = 'a secret prompt template';
        set_config('validatorprompttemplate', $plain, 'local_artqtml');

        $key = setting_backup::backup('validatorprompttemplate', 2026072602, true);
        $stored = get_config('local_artqtml', $key);

        $this->assertNotSame($plain, $stored, 'the backup was stored in plaintext');
        // ...and it round-trips back to the original.
        $this->assertSame($plain, \core\encryption::decrypt($stored));
    }

    /**
     * The administrator is told which setting changed and where the old value is.
     */
    public function test_notices_are_recorded_rendered_and_cleared(): void {
        $this->resetAfterTest();

        set_config('validatorprompttemplate', 'x', 'local_artqtml');
        set_config('generatorprompttemplate', 'y', 'local_artqtml');

        setting_backup::backup('validatorprompttemplate', 2026072602);
        setting_backup::backup('generatorprompttemplate', 2026072602);

        $notices = setting_backup::pending_notices();
        $this->assertCount(2, $notices);
        $this->assertSame('validatorprompttemplate_backup_2026072602', $notices['validatorprompttemplate']);

        $messages = setting_backup::notice_messages();
        $this->assertCount(2, $messages);
        // The message names both the setting and the key, which is what requires.
        $this->assertStringContainsString('validatorprompttemplate', $messages[0]);
        $this->assertStringContainsString('validatorprompttemplate_backup_2026072602', $messages[0]);
        $this->assertStringNotContainsString('[[', $messages[0], 'settingbackednotice is missing from the lang file');

        setting_backup::clear_notices();
        $this->assertSame([], setting_backup::pending_notices());
        $this->assertSame([], setting_backup::notice_messages());
    }

    /**
     * The setting_backup helper remains for any future step that rewrites a prompt template.
     */
    public function test_setting_backup_remains_for_future_prompt_migrations(): void {
        $this->assertTrue(
            class_exists(setting_backup::class),
            'setting_backup must stay available for future prompt migrations'
        );
    }

    public function test_future_template_migrations_must_back_up(): void {
        $upgrade = file_get_contents(__DIR__ . '/../../db/upgrade.php');

        $writes = preg_match_all(
            '/set_config\(\s*[\'"](?:validator|generator)prompttemplate[\'"]/',
            $upgrade
        );
        $backups = preg_match_all(
            '/setting_backup::backup\(\s*[\'"](?:validator|generator)prompttemplate[\'"]/',
            $upgrade
        );

        $this->assertSame(
            0,
            $writes - $backups,
            "An upgrade step writes a prompt-template setting without a preceding "
            . "setting_backup::backup call . Template set_config writes: $writes"
            . "backup calls: $backups. Add the backup call before the write."
        );
    }
}
