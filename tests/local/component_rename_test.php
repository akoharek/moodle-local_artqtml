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
 * Tests for the aiquizgen → artqtml component migration helper.
 *
 * Table rename is covered by Docker upgrade/smoke (DDL inside PHPUnit breaks the shared
 * schema snapshot). This test covers the registry merge that install/upgrade also run.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\component_rename
 */
final class component_rename_test extends \advanced_testcase {
    /**
     * Old config_plugins rows are merged into local_artqtml without duplicating version.
     */
    public function test_migrate_registry_merges_config(): void {
        global $DB;

        $this->resetAfterTest();

        set_config('enabled', '0', 'local_artqtml');
        set_config('enabled', '1', 'local_aiquizgen');
        set_config('claudeapikey', 'old-key-value', 'local_aiquizgen');
        set_config('version', '2026080701', 'local_aiquizgen');

        $this->assertTrue(component_rename::migrate_registry());

        $this->assertSame('1', get_config('local_artqtml', 'enabled'));
        $this->assertSame('old-key-value', get_config('local_artqtml', 'claudeapikey'));
        // Fresh/current version row must not be overwritten by the legacy one.
        $this->assertNotSame('2026080701', get_config('local_artqtml', 'version'));
        $this->assertFalse($DB->record_exists('config_plugins', ['plugin' => 'local_aiquizgen']));
    }
}
