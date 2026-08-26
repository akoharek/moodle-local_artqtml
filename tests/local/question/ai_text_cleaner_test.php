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

namespace local_artqtml\local\question;

defined('MOODLE_INTERNAL') || die();

/**
 * The whole question is cleaned at the parse step, so the validator judges what the teacher will see.
 *
 * The sibling file ai_text_cleaning_test.php asserts the same cleaning at the SAVE door
 * (question_form_builder). This one is about the earlier door and about the two properties that
 * Only matter because there are now two doors: that clean_question() reaches every AI-authored
 * Field of every type, and that running the cleaner twice changes nothing.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\question\ai_text_cleaner
 */
final class ai_text_cleaner_test extends \advanced_testcase {
    /**
     * IDEMPOTENCE, and it is not decoration. The cleaner now runs at the parse step and again at
     * The save step; if a second pass changed anything, every question would be cleaned twice into
     * Something neither step intended. The entity case is the one that could go wrong: a purified
     * "&lt;" must not become "&amp;lt;" on the way through a second time.
     */
    public function test_cleaning_twice_is_the_same_as_cleaning_once(): void {
        $inputs = [
            '<span style="background-color: blue">Az alma <b>rózsaféle</b>.</span>',
            'Igaz-e, hogy x < 5 és y > 3?',
            '<p>Első bekezdés</p><p>Második bekezdés</p>',
            'A H<sub>2</sub>O és az 5 m<sup>2</sup>.',
            'Hétköznapi mondat, semmi különös.',
            'Ampersand &amp; entitás, meg egy &nbsp; szóköz.',
        ];

        foreach ($inputs as $input) {
            $once = ai_text_cleaner::clean($input);
            $twice = ai_text_cleaner::clean($once);

            $this->assertSame($once, $twice, 'A második kör megváltoztatta: ' . $input);
        }
    }

    /**
     * Every AI-authored field of every type goes through, in one call. The list is one place in
     * The class rather than six per-type lists, and this is what pins that.
     */
    public function test_clean_question_reaches_every_authored_field(): void {
        $dirty = '<span style="background-color: blue">szöveg</span>';

        $question = ai_text_cleaner::clean_question([
            'questiontext'      => $dirty,
            'generalfeedback'   => $dirty,
            'hint1'             => $dirty,
            'hint2'             => $dirty,
            'explanationtrue'   => $dirty,
            'explanationfalse'  => $dirty,
            'answer'            => $dirty,
            'graderinfo'        => $dirty,
            'options'           => [
                ['text' => $dirty, 'explanation' => $dirty, 'correct' => true],
            ],
            'items'             => [
                ['text' => $dirty],
                $dirty,
            ],
        ]);

        $fields = [
            'questiontext',
            'generalfeedback',
            'hint1',
            'hint2',
            'explanationtrue',
            'explanationfalse',
            'answer',
            'graderinfo',
        ];

        foreach ($fields as $field) {
            $this->assertSame('szöveg', $question[$field], "A(z) $field mező nem tisztult meg.");
        }

        $this->assertSame('szöveg', $question['options'][0]['text']);
        $this->assertSame('szöveg', $question['options'][0]['explanation']);
        $this->assertSame('szöveg', $question['items'][0]['text']);
        $this->assertSame('szöveg', $question['items'][1], 'A csupasz sztringes SR-elem is tisztul.');
    }

    /**
     * WHAT MUST NOT BE TOUCHED. These are machine values, not prose: a cleaner walking every
     * String in the array would have run over them too, which is how a cleaner starts corrupting
     * The data it was added to protect. The boolean is here because it must survive as a boolean,
     * Not become the string it would be if it went through clean().
     */
    public function test_machine_values_are_left_alone(): void {
        $question = ai_text_cleaner::clean_question([
            'questiontext'     => 'Kérdés',
            'type'             => 'IH',
            'correctanswer'    => true,
            'difficulty_label' => 'Könnyű',
            'source_reference' => '2. bekezdés',
            'options'          => [
                ['text' => 'Válasz', 'correct' => false],
            ],
        ]);

        $this->assertSame('IH', $question['type']);
        $this->assertTrue($question['correctanswer']);
        $this->assertSame('Könnyű', $question['difficulty_label']);
        $this->assertSame('2. bekezdés', $question['source_reference']);
        $this->assertFalse($question['options'][0]['correct']);
    }

    /**
     * A question that carries only some of the fields (every type carries a different subset) must
     * Come back with exactly the keys it went in with - no key invented by the cleaner.
     */
    public function test_absent_fields_are_not_invented(): void {
        $question = ai_text_cleaner::clean_question(['questiontext' => 'Kérdés']);

        $this->assertSame(['questiontext'], array_keys($question));
    }

    /**
     * A leading "szöveg szerint / according to the text" clause is removed at the clean step.
     */
    public function test_leading_source_meta_reference_is_stripped(): void {
        $question = ai_text_cleaner::clean_question([
            'questiontext' => 'A szöveg szerint az alma <b>rózsaféle</b>.',
            'options'      => [
                ['text' => 'According to the text, Rosaceae.', 'correct' => true],
            ],
        ]);

        $this->assertSame('Az alma rózsaféle.', $question['questiontext']);
        $this->assertSame('Rosaceae.', $question['options'][0]['text']);
    }
}
