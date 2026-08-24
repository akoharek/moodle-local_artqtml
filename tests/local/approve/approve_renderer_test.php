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

namespace local_artqtml\local\approve;

use local_artqtml\local\draft_bank;

/**
 * After a question is moved to the Moodle bank, the approve row shows Open, not Edit.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\approve\approve_renderer
 */
final class approve_renderer_test extends \advanced_testcase {
    /**
     * A still-in-draft question keeps the plugin-aware Edit action.
     */
    public function test_unmoved_question_shows_edit_not_open(): void {
        [$output, $creator, $pageurl] = $this->setup_page();
        $question = $this->seed_question(['movedout' => 0, 'questioncode' => 'OPEN-IH-0001']);

        $html = approve_renderer::questions_table(
            $output,
            [$question],
            'name',
            'ASC',
            $pageurl,
            true,
            $creator,
            (int) $question->generationid
        );

        $this->assertStringContainsString('artqtml-approve-edit-link', $html);
        $this->assertStringContainsString(get_string('actionedit', 'local_artqtml'), $html);
        $this->assertStringContainsString('artqtml-approve-preview-link', $html);
        $this->assertStringNotContainsString('artqtml-approve-open-link', $html);
        $this->assertStringContainsString('/question/bank/editquestion/question.php', $html);
        $this->assertStringContainsString('id=' . (int) $question->questionbankid, $html);
    }

    /**
     * A moved question shows Open to the destination bank listing, not Edit or Preview.
     */
    public function test_moved_question_shows_open_not_edit(): void {
        [$output, $creator, $pageurl] = $this->setup_page();

        $destcourse = $this->getDataGenerator()->create_course();
        $destctx = \context_course::instance($destcourse->id);
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $qgen->create_question_category([
            'contextid' => $destctx->id,
            'name'      => 'Destination bank',
        ]);
        $question = $this->seed_question([
            'movedout'     => 1,
            'approved'     => 1,
            'questioncode' => 'OPEN-IH-0002',
        ], $category);

        $html = approve_renderer::questions_table(
            $output,
            [$question],
            'name',
            'ASC',
            $pageurl,
            true,
            $creator,
            (int) $question->generationid
        );

        $this->assertStringContainsString('artqtml-approve-open-link', $html);
        $this->assertStringContainsString(get_string('actionopenquestion', 'local_artqtml'), $html);
        $this->assertStringNotContainsString('artqtml-approve-edit-link', $html);
        $this->assertStringNotContainsString('artqtml-approve-preview-link', $html);
        $this->assertStringNotContainsString('/question/bank/editquestion/question.php', $html);
        $this->assertStringContainsString('/question/edit.php', $html);
        $this->assertStringContainsString('cat=' . $category->id . '%2C' . $destctx->id, $html);
        if (draft_bank::uses_module_question_banks()) {
            $this->assertMatchesRegularExpression('/cmid=\d+/', $html);
        } else {
            $this->assertStringContainsString('courseid=' . (int) $destcourse->id, $html);
        }
        $this->assertStringNotContainsString('artqtml-approve-delete-link', $html);
        $this->assertStringNotContainsString('artqtml-approve-approve-button', $html);
        $this->assertStringNotContainsString('artqtml-approve-revoke-link', $html);
    }

    /**
     * Prepare $PAGE/$OUTPUT and the generation owner used by the renderer.
     *
     * @return array{0:\core_renderer,1:\stdClass,2:\moodle_url}
     */
    private function setup_page(): array {
        global $PAGE, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        set_config('draftcourseid', (string) $course->id, 'local_artqtml');

        $PAGE->set_url(new \moodle_url('/local/artqtml/approve.php', ['generationid' => 1]));
        $PAGE->set_context(\context_system::instance());

        return [
            $PAGE->get_renderer('core'),
            $USER,
            new \moodle_url('/local/artqtml/approve.php', ['generationid' => 1]),
        ];
    }

    /**
     * Insert one generation plus one plugin question pointing at a real Moodle question.
     *
     * @param array<string,mixed> $overrides
     * @param \stdClass|null $category optional destination/draft category
     * @return \stdClass the plugin question row
     */
    private function seed_question(array $overrides = [], ?\stdClass $category = null): \stdClass {
        global $DB, $USER;

        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        if ($category === null) {
            $category = $qgen->create_question_category();
        }
        $moodleq = $qgen->create_question('truefalse', null, [
            'category' => $category->id,
            'name'     => $overrides['questioncode'] ?? 'OPEN-IH-0001',
        ]);

        $generationid = $DB->insert_record('local_artqtml_generations', (object) [
            'userid'       => (int) $USER->id,
            'name'         => 'Open-after-move fixture',
            'shortname'    => 'OPENMV',
            'status'       => \local_artqtml\local\generation_status::COMPLETED,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        $question = (object) array_merge([
            'generationid'         => $generationid,
            'questioncode'         => 'OPEN-IH-0001',
            'typecode'             => 'IH',
            'questiontype'         => 'truefalse',
            'questiontext'         => 'Water is required for photosynthesis.',
            'difficultylabel'      => 'Easy',
            'questiondata'         => json_encode(['correctanswer' => true]),
            'validationsuggestion' => \local_artqtml\local\validation_suggestion::ACCEPTED,
            'questionbankid'       => (int) $moodleq->id,
            'movedout'             => 0,
            'approved'             => 0,
            'approvedby'           => null,
            'edited'               => 0,
            'timecreated'          => time(),
        ], $overrides);
        $question->id = $DB->insert_record('local_artqtml_questions', $question);

        return $DB->get_record('local_artqtml_questions', ['id' => $question->id], '*', MUST_EXIST);
    }
}
