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

/**
 * The approve page's detail panel must describe the question as it is now (BL-28).
 *
 * Measured on 2026-08-02, before this class existed: a teacher rewrote an answer option in Moodle's
 * editor, and the panel went on listing the option they had replaced - because it rendered the JSON
 * the AI returned at generation time, which nothing ever updates. The panel is what a teacher reads
 * before pressing Approve, so it was describing one version and approving another.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\approve\current_question
 */
final class current_question_test extends \advanced_testcase {
    /**
     * A plugin row shaped the way the approve page reads it.
     *
     * @param int $questionbankid
     * @param string $typecode
     * @param array $storeddata what the AI returned at generation time
     * @return \stdClass
     */
    private function row(int $questionbankid, string $typecode, array $storeddata): \stdClass {
        return (object) [
            'questionbankid' => $questionbankid,
            'typecode'       => $typecode,
            'questiondata'   => json_encode($storeddata),
        ];
    }

    /**
     * The stored copy, deliberately different from anything the live question could say, so a test
     * that reads the wrong source cannot accidentally pass.
     *
     * @return array
     */
    private function stale_data(): array {
        return [
            'options'         => [['text' => 'A GENERÁLÁSKORI VÁLASZ', 'correct' => true]],
            'hint1'           => 'A generáláskori első hint.',
            'generalfeedback' => 'A generáláskori visszajelzés.',
        ];
    }

    /**
     * The live answers win over the stored ones.
     */
    public function test_the_options_come_from_the_live_question(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $question = $generator->create_question('multichoice', 'one_of_four', ['category' => $category->id]);

        $data = current_question::data_for($this->row((int) $question->id, 'FE', $this->stale_data()));

        $texts = array_column($data['options'], 'text');

        $this->assertNotContains(
            'A GENERÁLÁSKORI VÁLASZ',
            $texts,
            'the panel must not show an option the teacher has already replaced'
        );
        $this->assertGreaterThan(1, count($texts), 'the live multichoice question has four options');
        $this->assertContains(
            true,
            array_column($data['options'], 'correct'),
            'exactly the positively-graded options are marked correct'
        );

        foreach ($texts as $text) {
            $this->assertStringNotContainsString(
                '<',
                $text,
                'Moodle stores these as HTML and the panel escapes what it renders, so the markup '
                    . 'has to come off here - otherwise the teacher reads "<p>" on the screen'
            );
        }
    }

    /**
     * True/False reads the verdict off the definition, not off the answers.
     */
    public function test_truefalse_reads_the_live_verdict(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $question = $generator->create_question('truefalse', 'true', ['category' => $category->id]);

        $data = current_question::data_for($this->row((int) $question->id, 'IH', ['correctanswer' => false]));

        $this->assertTrue($data['correctanswer'], 'the live question is the one that answers this');
    }

    /**
     * Short answer takes the first graded answer.
     */
    public function test_shortanswer_reads_the_live_answer(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $question = $generator->create_question('shortanswer', 'frogtoad', ['category' => $category->id]);

        $data = current_question::data_for($this->row((int) $question->id, 'RV', ['answer' => 'RÉGI VÁLASZ']));

        $this->assertNotSame('RÉGI VÁLASZ', $data['answer']);
        $this->assertNotSame('', $data['answer']);
    }

    /**
     * A question that can no longer be loaded falls back to the stored copy.
     *
     * This is the only case the stored copy is still for: the row is created after the Moodle
     * question exists, and a semantically rejected question never gets a row at all - so "not yet
     * imported" cannot happen. Deleted afterwards can.
     */
    public function test_a_deleted_question_falls_back_to_the_stored_copy(): void {
        $this->resetAfterTest();

        $data = current_question::data_for($this->row(999999, 'FE', $this->stale_data()));

        $this->assertSame('A GENERÁLÁSKORI VÁLASZ', $data['options'][0]['text']);
        $this->assertSame('A generáláskori visszajelzés.', $data['generalfeedback']);
    }

    /**
     * A row with no question id at all is handled without touching the question bank.
     */
    public function test_a_row_without_a_question_id_returns_the_stored_copy(): void {
        $this->resetAfterTest();

        $data = current_question::data_for($this->row(0, 'FE', $this->stale_data()));

        $this->assertSame($this->stale_data()['options'], $data['options']);
    }

    /**
     * Broken stored JSON must not take the page down with it.
     */
    public function test_unreadable_stored_json_degrades_to_an_empty_panel(): void {
        $this->resetAfterTest();

        $row = (object) ['questionbankid' => 0, 'typecode' => 'FE', 'questiondata' => 'nem json'];

        $this->assertSame([], current_question::data_for($row));
    }
}
