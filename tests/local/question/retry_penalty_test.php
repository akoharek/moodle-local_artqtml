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
 * Pins the retry penalty that question_form_builder writes onto the real Moodle question.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\question\question_form_builder
 */
final class retry_penalty_test extends \advanced_testcase {
    /** Moodle core's default question penalty (one third), used when the plugin does not manage it. */
    private const MOODLE_DEFAULT_PENALTY = 0.3333333;

    /**
     * Case 1 - retry on, penalty left at its default: exactly 33% reaches the bank.
     */
    public function test_retry_on_default_is_33_percent(): void {
        $this->assertEqualsWithDelta(0.33, $this->penalty_for('IH', ['retryenabled' => 1]), 1e-9);
    }

    /**
     * Case 2 - retry on, penalty configured to 50: the configured value reaches the bank, not 33%.
     */
    public function test_retry_on_custom_penalty_wins(): void {
        $this->assertEqualsWithDelta(
            0.50,
            $this->penalty_for('IH', ['retryenabled' => 1, 'retrypenalty' => 50]),
            1e-9
        );
    }

    /**
     * Case 3 - retry off: Moodle's own default penalty reaches the bank (never applied without an
     * Interactive behaviour, but this is the field's stored value).
     */
    public function test_retry_off_is_the_moodle_default(): void {
        $this->assertEqualsWithDelta(self::MOODLE_DEFAULT_PENALTY, $this->penalty_for('IH', []), 1e-9);
    }

    /**
     * Case 4 - the penalty follows the RETRY switch, not the HINT switch (the corrected ).
     *
     * A hint with retry off must NOT pull in the retry-on penalty, and retry on with no hint must
     * Still apply it - proving the two switches are independent, which the old wording got wrong.
     */
    public function test_penalty_follows_retry_not_hint(): void {
        // Hint on, retry off -> the retry-off value, because the hint switch does not touch penalty.
        $this->assertEqualsWithDelta(
            self::MOODLE_DEFAULT_PENALTY,
            $this->penalty_for('IH', ['hintenabled' => 1]),
            1e-9,
            'A hint with retry off must not change the penalty.'
        );

        // Retry on, no hint -> the 33% default, because retry alone drives the penalty.
        $this->assertEqualsWithDelta(
            0.33,
            $this->penalty_for('IH', ['retryenabled' => 1]),
            1e-9,
            'Retry on with no hint must still apply the 33% penalty.'
        );
    }

    /**
     * FE uses the same retry-driven penalty as IH.
     */
    public function test_fe_retry_on_default_is_33_percent(): void {
        $this->assertEqualsWithDelta(
            0.33,
            $this->penalty_for('FE', ['retryenabled' => 1], [
                'questiontext' => 'Q?',
                'options'      => [
                    ['text' => 'a', 'correct' => true],
                    ['text' => 'b', 'correct' => false],
                ],
            ]),
            1e-9
        );
    }

    /**
     * Build a question form the way the importer does and return the penalty it would store.
     *
     * @param string $typecode e.g. 'IH' (true/false) or 'FE' (multichoice)
     * @param array $typesettings the per-type generation settings (retryenabled, retrypenalty, hintenabled)
     * @param array|null $data optional question payload (defaults to a true/false stem)
     * @return float
     */
    private function penalty_for(string $typecode, array $typesettings, ?array $data = null): float {
        $this->resetAfterTest();
        $category = (object) ['id' => 1, 'contextid' => \context_system::instance()->id];
        $form = question_form_builder::build(
            $typecode,
            $data ?? ['questiontext' => 'Q', 'correctanswer' => 1],
            $category,
            'C-' . $typecode . '-0001',
            $typesettings,
            2
        );
        return (float) $form->penalty;
    }
}
