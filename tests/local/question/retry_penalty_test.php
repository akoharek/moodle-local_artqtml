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
 * Pins the retry penalty that question_form_builder writes onto the real Moodle question
 * (T-07, TC-Gen-061).
 *
 * Gen-030 (corrected): multiple attempts, hint display and the per-attempt deduction are three
 * INDEPENDENT switches. When retry is on, the deduction is configurable per question type, default
 * 33%. The teacher may change it later, per question, in Moodle's native editor. The old Gen-030
 * wording tied the 33% to hint generation, which was wrong - so the independence of the penalty
 * from the hint switch is the part most worth pinning here.
 *
 * On the 0.33 vs 0.3333333 question: they are two different intended defaults, not one value at two
 * precisions (see docs/retry_penalty_report.md).
 *  - retry ON, default -> 0.33: exactly 33%, the value Gen-030 documents and the one actually
 *    applied per failed attempt under "Interactive with multiple tries".
 *  - retry OFF -> 0.3333333: Moodle's OWN default penalty (\question/format.php,
 *    \question/type/edit_question_form.php, \question/type/questiontypebase.php all use it). When
 *    the plugin is not managing the penalty, it leaves the question at Moodle's native default,
 *    exactly as a hand-created question would be; a non-interactive behaviour never applies it.
 * The code is correct as written, so these are asserted as the contract, not copied blindly.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
     * interactive behaviour, but this is the field's stored value).
     */
    public function test_retry_off_is_the_moodle_default(): void {
        $this->assertEqualsWithDelta(self::MOODLE_DEFAULT_PENALTY, $this->penalty_for('IH', []), 1e-9);
    }

    /**
     * Case 4 - the penalty follows the RETRY switch, not the HINT switch (the corrected Gen-030).
     *
     * A hint with retry off must NOT pull in the retry-on penalty, and retry on with no hint must
     * still apply it - proving the two switches are independent, which the old wording got wrong.
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
     * Not one of T-07's four cases, but the third penalty branch: an essay is manually graded, so
     * its penalty is forced to 0 regardless of the retry switch.
     */
    public function test_essay_penalty_is_zero_regardless_of_retry(): void {
        $this->assertEqualsWithDelta(0.0, $this->penalty_for('EH', ['retryenabled' => 1]), 1e-9);
    }

    /**
     * Build a question form the way the importer does and return the penalty it would store.
     *
     * @param string $typecode e.g. 'IH' (true/false) or 'EH' (essay)
     * @param array $typesettings the per-type generation settings (retryenabled, retrypenalty, hintenabled)
     * @return float
     */
    private function penalty_for(string $typecode, array $typesettings): float {
        $this->resetAfterTest();
        $category = (object) ['id' => 1, 'contextid' => \context_system::instance()->id];
        $form = question_form_builder::build(
            $typecode,
            ['questiontext' => 'Q', 'correctanswer' => 1],
            $category,
            'C-' . $typecode . '-0001',
            $typesettings,
            2
        );
        return (float) $form->penalty;
    }
}
