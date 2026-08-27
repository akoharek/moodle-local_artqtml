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

namespace local_artqtml\form;

use local_artqtml\local\question_types;

/**
 * Unit tests for the question-settings form's server-side validation.
 *
 * Scale-only matrix (IH/FE/SR); no per-type instruction fields / security filter.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\form\generate_form
 */
final class generate_form_test extends \advanced_testcase {
    /**
     * Run the form's validation() over a submitted-data array.
     *
     * @param array $data submitted field values, merged over a valid one-question request
     * @return array validation errors keyed by element name
     */
    protected function validate(array $data): array {
        $this->setAdminUser();

        $generation = (object) [
            'id'        => 1,
            'name'      => 'A perfectly valid generation name',
            'shortname' => 'BIO1',
        ];
        $form = new generate_form(null, ['generation' => $generation]);

        $defaults = [
            'matrix_IH_easy'   => 1,
            'matrix_IH_medium' => 0,
            'matrix_IH_hard'   => 0,
            'matrix_FE_easy'   => 0,
            'matrix_FE_medium' => 0,
            'matrix_FE_hard'   => 0,
            'matrix_SR_easy'   => 0,
            'matrix_SR_medium' => 0,
            'matrix_SR_hard'   => 0,
            'sritemcount'      => 0,
        ];

        return $form->validation(array_merge($defaults, $data), []);
    }

    /**
     * A valid one-question scale request produces no count errors.
     */
    public function test_a_valid_scale_request_passes(): void {
        $this->resetAfterTest();

        $errors = $this->validate([]);

        $this->assertArrayNotHasKey('matrixrow_scale_IH', $errors);
        $this->assertArrayNotHasKey('matrixrow_scale_FE', $errors);
        $this->assertArrayNotHasKey('matrixrow_scale_SR', $errors);
    }

    /**
     * Zero questions across the matrix is rejected.
     */
    public function test_zero_questions_is_rejected(): void {
        $this->resetAfterTest();

        $errors = $this->validate([
            'matrix_IH_easy' => 0,
        ]);

        $this->assertArrayHasKey('matrixrow_scale_IH', $errors);
        $this->assertSame(get_string('errornoquestions', 'local_artqtml'), $errors['matrixrow_scale_IH']);
    }

    /**
     * An SR item-count override below 2 is rejected when SR questions are requested.
     */
    public function test_sr_item_count_override_too_low_is_rejected(): void {
        $this->resetAfterTest();

        $errors = $this->validate([
            'matrix_IH_easy' => 0,
            'matrix_SR_easy' => 1,
            'sritemcount'    => 1,
        ]);

        $this->assertArrayHasKey('sritemcount', $errors);
        $this->assertSame(get_string('errorsritemcounttoolow', 'local_artqtml'), $errors['sritemcount']);
    }

    /**
     * Only the three CODES types appear on the form.
     */
    public function test_form_offers_exactly_the_light_types(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generation = (object) [
            'id'        => 1,
            'name'      => 'A perfectly valid generation name',
            'shortname' => 'BIO1',
        ];
        $form = new generate_form(null, ['generation' => $generation]);
        $reflection = new \ReflectionProperty(\moodleform::class, '_form');
        $mform = $reflection->getValue($form);

        $this->assertSame(['IH', 'FE', 'SR'], question_types::CODES);
        foreach (question_types::CODES as $code) {
            $this->assertTrue($mform->elementExists('matrixrow_scale_' . $code), $code);
            $this->assertTrue($mform->elementExists('typeheader_' . $code), $code);
        }
        foreach (['FT', 'EH', 'RV'] as $removed) {
            $this->assertFalse($mform->elementExists('matrixrow_scale_' . $removed), $removed);
            $this->assertFalse($mform->elementExists('typeheader_' . $removed), $removed);
            $this->assertFalse($mform->elementExists('instruction_' . $removed), $removed);
        }
        // Per-type AI instruction boxes are not supported.
        $this->assertFalse($mform->elementExists('instruction_IH'));
    }

    /**
     * Scale matrix column hints remain registered form elements (hideIf-compatible).
     */
    public function test_scale_matrix_column_hint_exists(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generation = (object) [
            'id'        => 1,
            'name'      => 'A perfectly valid generation name',
            'shortname' => 'BIO1',
        ];
        $form = new generate_form(null, ['generation' => $generation]);
        $reflection = new \ReflectionProperty(\moodleform::class, '_form');
        $mform = $reflection->getValue($form);

        $this->assertTrue($mform->elementExists('matrixhead_scale'));

        ob_start();
        $form->display();
        $html = ob_get_clean();
        $this->assertSame(1, substr_count($html, 'id="artqtml-step2total"'));
        $this->assertStringNotContainsString('artqtml-step1total', $html);
        $this->assertStringNotContainsString('artqtml-matrixtotal-', $html);
    }
}
