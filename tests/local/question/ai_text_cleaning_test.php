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

/**
 * what reaches the question bank from the model is wording, not appearance.
 *
 * These assert the contract at the only door AI text uses to become a real Moodle question -
 * question_form_builder::build(). The security half (script tags and friends) was already covered
 * by clean_param(PARAM_CLEANHTML); what was NOT covered, and is what this file is really about, is
 * that the purifier keeps benign formatting on purpose. A background colour is not an attack, so
 * the sanitiser passed it, and it arrived in the teacher's editor as real formatting.
 *
 * The two cases most worth reading are test_a_stray_less_than_sign_does_not_eat_the_sentence()
 * and test_paragraphs_do_not_run_together(): both are text-loss defects that a naive strip_tags()
 * would have introduced while looking like it worked.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\question\question_form_builder
 */
final class ai_text_cleaning_test extends \advanced_testcase {
    public function test_background_colour_is_removed_but_the_words_stay(): void {
        $this->assertSame(
            'Az alma rózsaféle.',
            $this->cleaned_questiontext('<span style="background-color: blue">Az alma rózsaféle.</span>')
        );
    }

    /**
 * Sub and sup are meaning, not decoration - the one exception, -08-06.
 */
    public function test_sub_and_sup_survive(): void {
        $this->assertSame(
            'A H<sub>2</sub>O és az 5 m<sup>2</sup>.',
            $this->cleaned_questiontext('A H<sub>2</sub>O és az 5 m<sup>2</sup>.')
        );
    }

    /**
     * Bold, italic and links are formatting, so they go - including the link's target, which is
     * the part that could take a student somewhere the teacher never chose.
     */
    public function test_ordinary_formatting_and_links_go(): void {
        $this->assertSame(
            'Fontos és dőlt meg hivatkozás.',
            $this->cleaned_questiontext(
                '<b>Fontos</b> és <i>dőlt</i> meg <a href="https://example.com/x">hivatkozás</a>.'
            )
        );
    }

    public function test_a_stray_less_than_sign_does_not_eat_the_sentence(): void {
        $cleaned = $this->cleaned_questiontext('Igaz-e, hogy x < 5 és y > 3?');

        $this->assertStringContainsString('5', $cleaned);
        $this->assertStringContainsString('3', $cleaned);
        $this->assertStringContainsString('Igaz-e', $cleaned);
    }

    public function test_paragraphs_do_not_run_together(): void {
        $cleaned = $this->cleaned_questiontext('<p>Első bekezdés</p><p>Második bekezdés</p>');

        $this->assertStringNotContainsString('bekezdésMásodik', $cleaned);
        $this->assertSame("Első bekezdés\nMásodik bekezdés", $cleaned);
    }

    /**
     * A line break is a boundary too, not nothing.
     */
    public function test_br_becomes_a_line_break(): void {
        $this->assertSame("Egy\nKettő", $this->cleaned_questiontext('Egy<br>Kettő'));
    }

    public function test_script_is_still_removed_entirely(): void {
        $cleaned = $this->cleaned_questiontext('Kérdés<script>alert(1)</script>');

        $this->assertStringNotContainsString('alert', $cleaned);
        $this->assertStringNotContainsString('<script', $cleaned);
        $this->assertSame('Kérdés', $cleaned);
    }

    /**
     * Plain text is left exactly as it is - the overwhelmingly common case, and the one where a
     * cleaner earns its keep by doing nothing visible.
     */
    public function test_plain_text_is_untouched(): void {
        $plain = 'Az alma a rózsafélék családjába tartozik.';

        $this->assertSame($plain, $this->cleaned_questiontext($plain));
    }

    /**
     * The cleaning is not limited to the question text: the answer options carry model output too,
     * and an option is what the student clicks.
     */
    public function test_answer_options_are_cleaned_as_well(): void {
        $this->resetAfterTest();
        $category = (object) ['id' => 1, 'contextid' => \context_system::instance()->id];

        $form = question_form_builder::build(
            'FE',
            [
                'questiontext' => 'Melyik?',
                'options'      => [
                    ['text' => '<span style="background-color: blue">Rózsafélék</span>', 'correct' => true],
                    ['text' => '<b>Pillangósvirágúak</b>', 'correct' => false],
                ],
            ],
            $category,
            'ALMA1-FE-0001',
            [],
            2
        );

        $texts = array_map(static function ($answer) {
            return $answer['text'];
        }, $form->answer);

        $this->assertContains('Rózsafélék', $texts);
        $this->assertContains('Pillangósvirágúak', $texts);
        foreach ($texts as $text) {
            $this->assertStringNotContainsString('<', $text);
        }
    }

    /**
     * Build a question the way the importer does and return the question text that would be stored.
     *
     * @param string $questiontext the raw text as the model returned it
     * @return string
     */
    private function cleaned_questiontext(string $questiontext): string {
        $this->resetAfterTest();
        $category = (object) ['id' => 1, 'contextid' => \context_system::instance()->id];

        $form = question_form_builder::build(
            'IH',
            ['questiontext' => $questiontext, 'correctanswer' => 1],
            $category,
            'TEST-IH-0001',
            [],
            2
        );

        return (string) $form->questiontext['text'];
    }
}
