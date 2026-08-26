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
 * The native editor validation overlay is only for still-in-draft questions.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\validation_panel
 */
final class validation_panel_test extends \advanced_testcase {
    /**
     * A draft question still has a panel row the hook can inject.
     */
    public function test_draft_question_still_has_a_panel_row(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $questionbankid = $this->seed_plugin_question(0);

        $this->assertNotNull(validation_panel::for_questionbank_id($questionbankid));
    }

    /**
     * After move, Open must not find a panel row — core question.php stays unadorned.
     */
    public function test_moved_question_has_no_panel_row(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $questionbankid = $this->seed_plugin_question(1);

        $this->assertNull(validation_panel::for_questionbank_id($questionbankid));
    }

    /**
     * Insert one generation plus one plugin question, optionally already moved.
     *
     * @param int $movedout
     * @return int Moodle question.id
     */
    private function seed_plugin_question(int $movedout): int {
        global $DB, $USER;

        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $qgen->create_question_category();
        $moodleq = $qgen->create_question('truefalse', null, ['category' => $category->id]);

        $generationid = $DB->insert_record('local_artqtml_generations', (object) [
            'userid'       => (int) $USER->id,
            'name'         => 'Validation panel fixture',
            'shortname'    => 'VALPNL',
            'status'       => generation_status::COMPLETED,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        $DB->insert_record('local_artqtml_questions', (object) [
            'generationid'         => $generationid,
            'questioncode'         => 'VALPNL-IH-0001',
            'typecode'             => 'IH',
            'questiontype'         => 'truefalse',
            'questiontext'         => 'Chlorophyll is used in photosynthesis.',
            'difficultylabel'      => 'Easy',
            'questiondata'         => json_encode(['correctanswer' => true]),
            'validationsuggestion' => validation_suggestion::ACCEPTED,
            'questionbankid'       => (int) $moodleq->id,
            'movedout'             => $movedout,
            'approved'             => $movedout,
            'edited'               => 0,
            'timecreated'          => time(),
        ]);

        return (int) $moodleq->id;
    }
}
