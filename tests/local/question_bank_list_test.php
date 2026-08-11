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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\question_bank_list
 */
final class question_bank_list_test extends \advanced_testcase {
    /**
     * Categories in the configured draft course are omitted even when the user can add there,
     * including sibling legacy roots that are not under Light's own artqtml_draft_root.
     */
    public function test_options_exclude_entire_draft_course_context(): void {
        global $DB;

        $this->resetAfterTest();

        $draftcourse = $this->getDataGenerator()->create_course(['fullname' => 'Draft Bank Course']);
        $realcourse = $this->getDataGenerator()->create_course(['fullname' => 'Real Target Course']);
        set_config('draftcourseid', $draftcourse->id, 'local_artqtml');

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $draftcourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $realcourse->id, 'editingteacher');

        $draftcontext = \context_course::instance($draftcourse->id);
        $realcontext = \context_course::instance($realcourse->id);

        // Light's own draft root + a legacy sibling root (as Full / aiquizgen leave behind).
        // Creating Light's root also ensures the draft course has Moodle's hidden top category.
        $lightroot = draft_bank::get_root_category_id();
        $lightdraft = draft_bank::create((object) [
            'id' => 99,
            'name' => 'Light draft gen',
            'shortname' => 'LGD1',
        ]);

        $topcategoryid = (int) $DB->get_field('question_categories', 'id', [
            'contextid' => $draftcontext->id,
            'parent' => 0,
        ], MUST_EXIST);

        $legacyroot = (object) [
            'name' => 'AI Quiz Generator',
            'contextid' => $draftcontext->id,
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
            'contextid' => $draftcontext->id,
            'info' => '',
            'infoformat' => FORMAT_HTML,
            'stamp' => make_unique_id_code(),
            'parent' => $legacyrootid,
            'sortorder' => 1,
            'idnumber' => 'aiquizgen_draft_1',
        ];
        $legacydraftid = (int) $DB->insert_record('question_categories', $legacydraft);

        $realtarget = $this->getDataGenerator()->create_question_category([
            'contextid' => $realcontext->id,
            'name' => 'Course default',
        ]);

        $options = question_bank_list::options_for_user((int) $teacher->id, $lightdraft);

        $this->assertArrayHasKey(
            $realtarget->id . ',' . $realcontext->id,
            $options,
            'real course categories must remain selectable move targets'
        );
        $this->assertArrayNotHasKey($lightroot . ',' . $draftcontext->id, $options);
        $this->assertArrayNotHasKey($lightdraft . ',' . $draftcontext->id, $options);
        $this->assertArrayNotHasKey($legacyrootid . ',' . $draftcontext->id, $options);
        $this->assertArrayNotHasKey($legacydraftid . ',' . $draftcontext->id, $options);

        foreach ($options as $label) {
            $this->assertStringNotContainsString(
                'Draft Bank Course',
                $label,
                'no option may be labelled under the draft course'
            );
        }
    }
}
