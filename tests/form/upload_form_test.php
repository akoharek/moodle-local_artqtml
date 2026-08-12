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

/**
 * Unit tests for the source-text upload form's server-side validation, in particular the
 * shortname format rule (TC-Felt-010/011 - the PARAM_RAW "reject, don't sanitise" fix).
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\form\upload_form
 */
final class upload_form_test extends \advanced_testcase {
    /**
     * Run the form's validation() over a submitted-data array.
     *
     * @param array $data submitted field values (merged over sensible valid defaults)
     * @return array validation errors keyed by element name
     */
    protected function validate(array $data): array {
        $this->setAdminUser();
        $form = new upload_form(null, ['maxbytes' => 1048576, 'editid' => 0]);

        $defaults = [
            'name'       => 'A perfectly valid generation name',
            'shortname'  => 'BIO1',
            'sourcetext' => 'Some ordinary source text for the generation.',
            'sourcefile' => 0,
        ];

        return $form->validation(array_merge($defaults, $data), []);
    }

    /**
     * TC-Felt-010/011: a shortname containing a non-alphanumeric character is rejected with the
     * format error, rather than being silently sanitised (the PARAM_RAW fix).
     */
    public function test_non_alphanumeric_shortname_rejected(): void {
        $this->resetAfterTest();

        $errors = $this->validate(['shortname' => 'BIO-1']);

        $this->assertArrayHasKey('shortname', $errors);
        $this->assertSame(get_string('errorshortnameformat', 'local_artqtml'), $errors['shortname']);
    }

    /**
     * A shortname longer than 8 characters fails the same format rule server-side, independent of
     * the client maxlength attribute.
     */
    public function test_overlong_shortname_rejected(): void {
        $this->resetAfterTest();

        $errors = $this->validate(['shortname' => 'ABCDEFGHI']); // 9 alphanumeric chars.

        $this->assertArrayHasKey('shortname', $errors);
        $this->assertSame(get_string('errorshortnameformat', 'local_artqtml'), $errors['shortname']);
    }

    /**
     * A blank shortname is reported as required, not as a format error.
     */
    public function test_blank_shortname_required(): void {
        $this->resetAfterTest();

        $errors = $this->validate(['shortname' => '   ']);

        $this->assertArrayHasKey('shortname', $errors);
        $this->assertSame(get_string('required'), $errors['shortname']);
    }

    /**
     * A valid 1-8 char alphanumeric shortname passes with no shortname error.
     */
    public function test_valid_shortname_passes(): void {
        $this->resetAfterTest();

        $errors = $this->validate(['shortname' => 'BIO1']);
        $this->assertArrayNotHasKey('shortname', $errors);

        $errors = $this->validate(['shortname' => 'A']);
        $this->assertArrayNotHasKey('shortname', $errors);

        // Lowercase is accepted at validation; upload.php uppercases on save (e.g. almafa -> ALMAFA).
        $errors = $this->validate(['shortname' => 'almafa']);
        $this->assertArrayNotHasKey('shortname', $errors);
    }

    /**
     * Accented letters are not ASCII a-z/A-Z, so they must be rejected (Felt-004 / mezotabla).
     */
    public function test_accented_shortname_rejected(): void {
        $this->resetAfterTest();

        $errors = $this->validate(['shortname' => 'ALMÁFA']);

        $this->assertArrayHasKey('shortname', $errors);
        $this->assertSame(get_string('errorshortnameformat', 'local_artqtml'), $errors['shortname']);
    }

    /**
     * Help/tooltip must describe the enforced rules: ASCII charset and uppercase-on-save.
     */
    public function test_shortname_help_describes_enforced_rules(): void {
        $help = get_string('generationshortname_help', 'local_artqtml');

        $this->assertStringContainsString('a-z', $help);
        $this->assertStringContainsString('A-Z', $help);
        $this->assertStringContainsString('0-9', $help);
        $this->assertMatchesRegularExpression('/uppercase|nagybetű/i', $help);
    }

    /**
     * The filepicker accepts TXT uploads.
     */
    public function test_source_filepicker_accepts_txt_only(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $form = new upload_form(null, ['maxbytes' => 1048576, 'editid' => 0]);
        $formreflection = new \ReflectionProperty(\moodleform::class, '_form');
        $formreflection->setAccessible(true);
        $mform = $formreflection->getValue($form);
        $element = $mform->getElement('sourcefile');

        $optionsreflection = new \ReflectionProperty(\MoodleQuickForm_filepicker::class, '_options');
        $optionsreflection->setAccessible(true);
        $options = $optionsreflection->getValue($element);

        $this->assertSame(['.txt'], $options['accepted_types']);
    }

    /**
     * A source text over the limit is refused, at the field the user has to shorten.
     *
     * Before 2026-08-04 nothing on the server compared this text to anything: the upload page's
     * JavaScript counter coloured a number and the text was saved and sent whatever its size.
     */
    public function test_an_oversized_source_text_is_rejected(): void {
        $this->resetAfterTest();

        set_config('maxsourcetokens', 10, 'local_artqtml');

        $errors = $this->validate(['sourcetext' => str_repeat('a', 41)]);

        $this->assertArrayHasKey('sourcetext', $errors);
    }

    /**
     * The message names all three numbers, because "too long" alone leaves the teacher guessing.
     */
    public function test_the_rejection_message_names_the_sizes(): void {
        $this->resetAfterTest();

        set_config('maxsourcetokens', 10, 'local_artqtml');

        $errors = $this->validate(['sourcetext' => str_repeat('a', 44)]);

        $this->assertArrayHasKey('sourcetext', $errors);
        $this->assertStringContainsString('11', $errors['sourcetext']);
        $this->assertStringContainsString('44', $errors['sourcetext']);
        $this->assertStringContainsString('10', $errors['sourcetext']);
    }

    /**
     * Text exactly at the limit is accepted.
     */
    public function test_a_source_text_at_the_limit_is_accepted(): void {
        $this->resetAfterTest();

        set_config('maxsourcetokens', 10, 'local_artqtml');

        $this->assertArrayNotHasKey('sourcetext', $this->validate(['sourcetext' => str_repeat('a', 40)]));
    }

    /**
     * The "source text is required" rule still fires, and is not replaced by the size message.
     *
     * An empty textarea is empty, not oversized - the two rules share a field and a mistake here
     * would show the wrong reason.
     */
    public function test_an_empty_source_text_still_reports_the_required_error(): void {
        $this->resetAfterTest();

        set_config('maxsourcetokens', 10, 'local_artqtml');

        $errors = $this->validate(['sourcetext' => '   ']);

        $this->assertArrayHasKey('sourcetext', $errors);
        $this->assertSame(get_string('errorsourcetextrequired', 'local_artqtml'), $errors['sourcetext']);
    }
}
