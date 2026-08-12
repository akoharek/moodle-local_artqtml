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
 * Move-target category list must never offer the draft course's banks.
 *
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\question_bank_list
 */
final class question_bank_list_test extends \advanced_testcase {
    /**
     * Categories in the configured draft course are omitted even when the user can add there,
     * Including sibling legacy roots that are not under this plugin's artqtml_draft_root.
     */
    public function test_options_exclude_entire_draft_course_context(): void {
        global $CFG, $DB;

        $this->resetAfterTest();

        // Clear the per-request draft-root cache left by earlier tests in this process.
        $property = new \ReflectionProperty(draft_bank::class, 'rootcategoryid');
        $property->setAccessible(true);
        $property->setValue(null, null);

        $draftcourse = $this->getDataGenerator()->create_course(['fullname' => 'Draft Bank Course']);
        $realcourse = $this->getDataGenerator()->create_course(['fullname' => 'Real Target Course']);
        set_config('draftcourseid', $draftcourse->id, 'local_artqtml');

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $draftcourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $realcourse->id, 'editingteacher');

        require_once($CFG->libdir . '/questionlib.php');

        // This plugin's draft root + a legacy sibling root (as earlier installs leave behind).
        $lightroot = draft_bank::get_root_category_id();
        $draftqcontextid = (int) draft_bank::get_draft_context_id();
        $lightdraft = draft_bank::create((object) [
            'id' => 99,
            'name' => 'ArtQTML draft gen',
            'shortname' => 'LGD1',
        ]);

        $top = question_get_top_category($draftqcontextid, true);
        $this->assertNotFalse($top);
        $topcategoryid = (int) $top->id;

        $legacyroot = (object) [
            'name' => 'AI Quiz Generator',
            'contextid' => $draftqcontextid,
            'info' => '',
            'infoformat' => FORMAT_HTML,
            'stamp' => make_unique_id_code(),
            'parent' => $topcategoryid,
            'sortorder' => 1,
            'idnumber' => 'aiquizgen_draft_root',
        ];
        $legacyrootid = (int) $DB->insert_record('question_categories', $legacyroot);
        $legacydraft = (object) [
            'name' => 'AI draft: Legacy leftover',
            'contextid' => $draftqcontextid,
            'info' => '',
            'infoformat' => FORMAT_HTML,
            'stamp' => make_unique_id_code(),
            'parent' => $legacyrootid,
            'sortorder' => 1,
            'idnumber' => 'aiquizgen_draft_1',
        ];
        $legacydraftid = (int) $DB->insert_record('question_categories', $legacydraft);

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        // Pass course context; on Moodle 5.1+ the generator remaps into a mod_qbank module context.
        $realtarget = $questiongenerator->create_question_category([
            'contextid' => \context_course::instance($realcourse->id)->id,
            'name' => 'Course default',
        ]);

        $options = question_bank_list::options_for_user((int) $teacher->id, $lightdraft);

        $this->assertArrayHasKey(
            $realtarget->id . ',' . $realtarget->contextid,
            $options,
            'real course categories must remain selectable move targets'
        );
        $this->assertArrayNotHasKey($lightroot . ',' . $draftqcontextid, $options);
        $this->assertArrayNotHasKey($lightdraft . ',' . $draftqcontextid, $options);
        $this->assertArrayNotHasKey($legacyrootid . ',' . $draftqcontextid, $options);
        $this->assertArrayNotHasKey($legacydraftid . ',' . $draftqcontextid, $options);

        foreach ($options as $label) {
            $this->assertStringNotContainsString(
                'Draft Bank Course',
                $label,
                'no option may be labelled under the draft course'
            );
        }
    }
}
