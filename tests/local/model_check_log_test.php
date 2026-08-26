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
 * Unit tests for the model-check diagnostic log.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\model_check_log
 */
final class model_check_log_test extends \advanced_testcase {
    /**
     * It is an addition, which the table's own comment separates from the breaking kind - that
     * Comment names renaming and dropping. A report selecting named columns is unaffected; one
     * Selecting `*` gains a column at the end.
     *
     * Why the column had to exist: a structural check failure takes a model out of the dropdown,
     * And that verdict has to be revocable by us. On the day this was added, two models failed the
     * Check in the morning and passed in the afternoon, because the defect was in how the plugin
     * Read the response. Scoping an exclusion to the plugin version that produced it means our own
     * Fix reopens them; without the column the exclusion would have outlived its own cause.
     */
    public function test_schema_matches_the_documented_interface(): void {
        global $DB;
        $this->resetAfterTest();

        $this->assertSame(
            [
                'id',
                'timecreated',
                'provider',
                'model',
                'checktype',
                'result',
                'errorcode',
                'errormessage',
                'duration',
                'triggertype',
                'pluginversion',
            ],
            array_keys($DB->get_columns(model_check_log::TABLE))
        );
    }

    /**
     * The table must be readable with a plain, unquoted, portable SELECT - which is the entire
     * Point of naming the column triggertype rather than the annex's `trigger`. TRIGGER is a
     * Reserved word; an unquoted reference to it is a syntax error in MariaDB and PostgreSQL, and
     * A quoted one is not portable between them.
     */
    public function test_every_column_is_selectable_unquoted(): void {
        global $DB;
        $this->resetAfterTest();

        model_check_log::record(
            'gemini',
            'gemini-3.5-flash',
            model_check_log::CHECK_STRUCTURE,
            false,
            'probe failed',
            1234,
            model_check_log::TRIGGER_MANUAL
        );

        $rows = $DB->get_records_sql(
            'SELECT id, timecreated, provider, model, checktype, result, errorcode, errormessage,
                    duration, triggertype, pluginversion
               FROM {local_artqtml_modelcheck}
              ORDER BY id DESC'
        );

        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('gemini', $row->provider);
        $this->assertSame(model_check_log::TRIGGER_MANUAL, $row->triggertype);
        // The verdict carries the plugin version it was made under, so our own fix can reopen a model our own defect excluded.
        $this->assertGreaterThan(0, (int) $row->pluginversion);
    }

    public function test_failure_records_an_error_code_derived_from_the_row_id(): void {
        global $DB;
        $this->resetAfterTest();

        $result = model_check_log::record(
            'anthropic',
            'claude-opus-4-8',
            model_check_log::CHECK_AVAILABILITY,
            false,
            'not in the fetched list',
            42,
            model_check_log::TRIGGER_SCHEDULED
        );

        $this->assertMatchesRegularExpression('/^AIQ-\d{8}-\d{4}$/', $result['errorcode']);
        $this->assertStringEndsWith(sprintf('%04d', $result['id'] % 10000), $result['errorcode']);

        $stored = $DB->get_record(model_check_log::TABLE, ['id' => $result['id']]);
        $this->assertSame($result['errorcode'], $stored->errorcode);
        $this->assertSame(model_check_log::RESULT_FAILURE, $stored->result);
    }

    /**
     * A successful check stores no error code and no message.
     */
    public function test_success_stores_no_error_fields(): void {
        global $DB;
        $this->resetAfterTest();

        $result = model_check_log::record(
            'gemini',
            'gemini-3.5-flash',
            model_check_log::CHECK_STRUCTURE,
            true,
            '',
            810,
            model_check_log::TRIGGER_SCHEDULED
        );

        $this->assertSame('', $result['errorcode']);
        $stored = $DB->get_record(model_check_log::TABLE, ['id' => $result['id']]);
        $this->assertNull($stored->errorcode);
        $this->assertNull($stored->errormessage);
        $this->assertSame(model_check_log::RESULT_SUCCESS, $stored->result);
        $this->assertEquals(810, $stored->duration);
    }

    /**
     * Only a shortened detail is stored, never a raw provider response.
     */
    public function test_error_message_is_truncated(): void {
        global $DB;
        $this->resetAfterTest();

        $huge = str_repeat('x', 5000);
        $result = model_check_log::record(
            'anthropic',
            'claude-opus-4-8',
            model_check_log::CHECK_STRUCTURE,
            false,
            $huge,
            1,
            model_check_log::TRIGGER_MANUAL
        );

        $stored = $DB->get_record(model_check_log::TABLE, ['id' => $result['id']]);
        $this->assertLessThanOrEqual(500, \core_text::strlen($stored->errormessage));
    }

    /**
     * A busy provider is not a verdict about the model.
     *
     */
    public function test_a_transient_failure_is_recorded_but_does_not_exclude(): void {
        $this->resetAfterTest();

        model_check_log::record(
            'gemini',
            'gemini-3.1-pro-preview-customtools',
            model_check_log::CHECK_STRUCTURE,
            false,
            'This model is currently experiencing high demand.',
            3033,
            model_check_log::TRIGGER_MANUAL,
            true
        );
        model_check_log::record(
            'gemini',
            'gemini-2.0-flash',
            model_check_log::CHECK_STRUCTURE,
            false,
            'This model is no longer available.',
            424,
            model_check_log::TRIGGER_MANUAL
        );

        $this->assertSame(
            ['gemini-2.0-flash'],
            model_check_log::excluded_models('gemini'),
            'Only the model that really cannot be used may be excluded.'
        );

        $sweep = model_check_log::latest_sweep('gemini');
        $this->assertSame(1, $sweep['checked']);
        $this->assertSame(1, $sweep['failed']);
    }

    /**
     * An outage must not erase the failure underneath it, which is what would let a genuinely
     * Broken model back into the dropdown on the next busy night.
     */
    public function test_a_transient_row_does_not_overwrite_an_earlier_verdict(): void {
        $this->resetAfterTest();

        model_check_log::record(
            'gemini',
            'gemini-2.0-flash',
            model_check_log::CHECK_STRUCTURE,
            false,
            'This model is no longer available.',
            424,
            model_check_log::TRIGGER_MANUAL
        );
        model_check_log::record(
            'gemini',
            'gemini-2.0-flash',
            model_check_log::CHECK_STRUCTURE,
            false,
            'High demand.',
            3000,
            model_check_log::TRIGGER_SCHEDULED,
            true
        );

        $this->assertSame(['gemini-2.0-flash'], model_check_log::excluded_models('gemini'));
    }

    /**
     * Latest_for_provider() returns the newest entry for that provider and ignores the other.
     */
    public function test_latest_for_provider(): void {
        $this->resetAfterTest();

        $this->assertNull(model_check_log::latest_for_provider('gemini'));

        model_check_log::record(
            'gemini',
            'old-model',
            model_check_log::CHECK_AVAILABILITY,
            false,
            'gone',
            5,
            model_check_log::TRIGGER_SCHEDULED
        );
        model_check_log::record(
            'anthropic',
            'claude-opus-4-8',
            model_check_log::CHECK_AVAILABILITY,
            true,
            '',
            5,
            model_check_log::TRIGGER_SCHEDULED
        );
        model_check_log::record(
            'gemini',
            'gemini-3.5-flash',
            model_check_log::CHECK_STRUCTURE,
            true,
            '',
            5,
            model_check_log::TRIGGER_MANUAL
        );

        $latest = model_check_log::latest_for_provider('gemini');
        $this->assertNotNull($latest);
        $this->assertSame('gemini-3.5-flash', $latest->model);
        $this->assertSame('anthropic', model_check_log::latest_for_provider('anthropic')->provider);
    }

    /**
     * No retention policy - nothing prunes the table.
     */
    public function test_no_purge_mechanism_exists(): void {
        $root = realpath(__DIR__ . '/../..');
        $offenders = [];

        foreach (['classes', 'db'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($iterator as $fileinfo) {
                if (substr($fileinfo->getPathname(), -4) !== '.php') {
                    continue;
                }
                $contents = file_get_contents($fileinfo->getPathname());
                if (preg_match('/delete_records[^;]*local_artqtml_modelcheck/s', $contents)) {
                    $offenders[] = str_replace($root . '/', '', $fileinfo->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, 'forbids automatic purging of the diagnostic log');
    }

    /**
     * Existing sites skip install.xml. A savepoint must add pluginversion or the settings page
     * Fails after a ZIP upgrade.
     */
    public function test_upgrade_adds_pluginversion(): void {
        $upgrade = file_get_contents(__DIR__ . '/../../db/upgrade.php');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$oldversion\s*<\s*2026081300\s*\).*pluginversion.*upgrade_plugin_savepoint\s*\(\s*true\s*,\s*2026081300/s',
            $upgrade,
            'db/upgrade.php must add pluginversion at savepoint 2026081300'
        );
    }

    /**
     * The aiquizgen rename drops the install.xml table (which has the column) and restores the
     * Old one. Install must add the field afterwards.
     */
    public function test_install_adds_pluginversion_after_rename(): void {
        $install = file_get_contents(__DIR__ . '/../../db/install.php');

        $this->assertStringContainsString(
            'migrate_if_needed()',
            $install
        );
        $this->assertMatchesRegularExpression(
            '/migrate_if_needed\(\).*pluginversion.*add_field/s',
            $install,
            'db/install.php must add pluginversion after the aiquizgen table rename'
        );
    }
}
