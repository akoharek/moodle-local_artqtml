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
 * Unit tests for mandatory admin setup detection.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\plugin_setup
 */
final class plugin_setup_test extends \advanced_testcase {
    /**
     * Fresh install state lists every mandatory item.
     */
    public function test_missing_lists_all_mandatory_items_when_unconfigured(): void {
        $this->resetAfterTest();

        $missing = plugin_setup::missing();
        $this->assertContains(plugin_setup::ITEM_DRAFTCOURSE, $missing);
        $this->assertContains(plugin_setup::ITEM_CLAUDEKEY, $missing);
        $this->assertContains(plugin_setup::ITEM_GEMINIKEY, $missing);
        $this->assertContains(plugin_setup::ITEM_CLAUDEMODEL, $missing);
        $this->assertContains(plugin_setup::ITEM_GEMINIMODEL, $missing);
        $this->assertFalse(plugin_setup::is_complete());
    }

    /**
     * When every mandatory setting is present, setup is complete.
     */
    public function test_is_complete_when_all_mandatory_settings_present(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        set_config('draftcourseid', $course->id, 'local_artqtml');
        set_config('claudeapikey', 'plain-claude-key', 'local_artqtml');
        set_config('geminiapikey', 'plain-gemini-key', 'local_artqtml');
        set_config('claudemodel', 'claude-test-model', 'local_artqtml');
        set_config('geminimodel', 'gemini-test-model', 'local_artqtml');

        $this->assertSame([], plugin_setup::missing());
        $this->assertTrue(plugin_setup::is_complete());
    }
}
