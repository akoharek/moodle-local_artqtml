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
 * Unit tests for the migration setting backup (Glob-037, Glob-038).
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\setting_backup
 */
final class setting_backup_test extends \advanced_testcase {
    /**
     * Glob-037: the previous value is stored under <setting>_backup_<version> before the change.
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
     * returns that default even when the administrator never touched it, so the "no value" branch
     * is unreachable through it. That is also the honest limitation of this helper - it cannot tell
     * an untouched default from a deliberate choice, so it backs both up. Backing up a value that
     * turns out to be the default is harmless; the reverse would not be.
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
     * "A mentés nem íródik felül": a second backup at the same version keeps the first, which is
     * the more original value, and takes a suffixed key instead.
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
     * Glob-037: an encrypted setting's backup is encrypted too - a backup must not downgrade a
     * secret to plaintext.
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
     * Glob-038: the administrator is told which setting changed and where the old value is.
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
        // The message names both the setting and the key, which is what Glob-038 requires.
        $this->assertStringContainsString('validatorprompttemplate', $messages[0]);
        $this->assertStringContainsString('validatorprompttemplate_backup_2026072602', $messages[0]);
        $this->assertStringNotContainsString('[[', $messages[0], 'settingbackednotice is missing from the lang file');

        setting_backup::clear_notices();
        $this->assertSame([], setting_backup::pending_notices());
        $this->assertSame([], setting_backup::notice_messages());
    }

    /**
     * The helper exists so future migrations use it. This asserts the rule is documented where a
     * migration author will see it, since the already-executed steps cannot be fixed retroactively.
     */
    public function test_upgrade_file_documents_the_backup_rule(): void {
        $upgrade = file_get_contents(__DIR__ . '/../../db/upgrade.php');

        $this->assertStringContainsString(
            'setting_backup',
            $upgrade,
            'db/upgrade.php should reference the backup helper so the next migration author uses it'
        );
    }

    /**
     * Glob-037 ENFORCEMENT (O-2): the tripwire that makes the rule real instead of documented.
     *
     * The concern the migration-backup cases exist for is "a migration that forgot to call the
     * backup still shipped". A behavioural test cannot catch that on its own, so this inspects
     * db/upgrade.php directly: it counts how many upgrade steps WRITE a prompt-template setting
     * (set_config), and how many BACK ONE UP first (setting_backup::backup). The two
     * grandfathered pre-rule steps (2026072501 and 2026072600) rewrote the template in place before
     * the rule existed and cannot be repaired retroactively, so exactly two writes are allowed to
     * have no matching backup. Every write beyond those two must be paired with a backup call.
     *
     * A future migration that changes a prompt template WITHOUT backing it up raises the write count
     * without the backup count, and this fails, naming the counts - so the author sees Glob-037
     * before the migration ships. (Proven to fail: see docs/migration_backup_report.md §5.)
     */
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

        // The two pre-rule steps (2026072501, 2026072600) are the only writes allowed without a
        // backup; they predate the rule and cannot be fixed retroactively.
        $grandfathered = 2;

        $this->assertSame(
            $grandfathered,
            $writes - $backups,
            "An upgrade step writes a prompt-template setting without a preceding "
            . "setting_backup::backup() call (Glob-037). Template set_config writes: $writes; "
            . "backup calls: $backups; grandfathered pre-rule writes allowed without a backup: "
            . "$grandfathered. Add the backup call before the write, or - only if this write is "
            . "itself a legitimately-grandfathered pre-rule step - update the allowance here."
        );
    }
}
