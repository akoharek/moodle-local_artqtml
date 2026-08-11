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

namespace local_artqtml\task;

use local_artqtml\local\problem_category;

/**
 * Unit tests for the validation problem_category enum (Val-019/Val-028/Val-029, PROB-F004).
 *
 * The empty-string enum member used to fail Gemini's structured-output schema validation
 * ("problem_category.enum[0]: cannot be empty"); these tests lock in the four-key replacement,
 * the single source of truth, and the lang-string labelling.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\problem_category
 * @covers     \local_artqtml\task\validate_questions_task
 */
final class validate_questions_problemcategory_test extends \advanced_testcase {
    /**
     * PROB-F004: the enum is exactly the four fixed keys, in order, with no empty string.
     */
    public function test_enum_is_exactly_four_keys_no_empty_string(): void {
        $this->assertSame(
            ['ok', 'factual_error', 'ambiguous_wording', 'other'],
            problem_category::VALUES
        );
        $this->assertCount(4, problem_category::VALUES);
        $this->assertNotContains('', problem_category::VALUES, 'empty string must not be an enum member');
        // No duplicates, and 'ok' is the designated "no problem" value.
        $this->assertSame(problem_category::VALUES, array_values(array_unique(problem_category::VALUES)));
        $this->assertSame('ok', problem_category::OK);
    }

    /**
     * PROB-F004 / technikai_melleklet: the schema's enum is the SAME set (single source of truth),
     * problem_category stays required, and no empty string leaked into the schema.
     */
    public function test_schema_enum_matches_single_source_and_is_required(): void {
        $task = new validate_questions_task();
        $method = new \ReflectionMethod(validate_questions_task::class, 'build_schema');
        $method->setAccessible(true);
        $schema = $method->invoke($task);

        $props = $schema['properties']['evaluations']['items']['properties'];
        $required = $schema['properties']['evaluations']['items']['required'];

        $this->assertSame(problem_category::VALUES, $props['problem_category']['enum']);
        $this->assertNotContains('', $props['problem_category']['enum']);
        $this->assertContains('problem_category', $required, 'problem_category must stay required');
    }

    /**
     * Val-029 / PROB-F001: every key has a non-empty lang label, and the label is never the raw
     * machine key. The 'ok' label ("No issue") is distinct from the "Accepted" suggestion label
     * (PROB-F002).
     */
    public function test_every_key_has_a_distinct_lang_label(): void {
        foreach (problem_category::VALUES as $key) {
            $label = problem_category::label($key);
            $this->assertNotSame('', trim($label));
            $this->assertNotSame($key, $label, "raw key '$key' must not be shown as its own label");
            $this->assertStringNotContainsString('[[', $label, "missing lang string for '$key'");
        }
        $this->assertNotSame(
            get_string('validationstatus_accepted', 'local_artqtml'),
            problem_category::label('ok'),
            'the "ok" category label must be distinguishable from the "Accepted" suggestion label'
        );
    }

    /**
     * normalise() maps legacy/empty/hallucinated values to a valid key or the given default.
     */
    public function test_normalise_rejects_legacy_and_empty_values(): void {
        // Valid keys pass through.
        $this->assertSame('ok', problem_category::normalise('ok'));
        $this->assertSame('other', problem_category::normalise('other'));
        // Legacy keys, empty string and null fall back to the default.
        $this->assertNull(problem_category::normalise('question_wording'));
        $this->assertNull(problem_category::normalise('answers'));
        $this->assertNull(problem_category::normalise(''));
        $this->assertNull(problem_category::normalise(null));
        $this->assertSame('other', problem_category::normalise('bogus', 'other'));
    }

    /**
     * Admin-021/Val-028: the assembled system instruction lists exactly the four code-owned keys
     * and carries no "empty if accepted" stipulation, whatever the (default) template says.
     */
    public function test_system_instruction_uses_the_four_keys_and_no_empty_if_accepted(): void {
        $this->resetAfterTest();
        // Admin-066: an empty template is now an empty prompt - there is no fallback. Seed the
        // same file install and upgrade use, which is also what a real site runs.
        global $CFG;
        foreach (require($CFG->dirroot . '/local/artqtml/db/prompt_defaults.php') as $s => $v) {
            set_config($s, $v, 'local_artqtml');
        }

        $task = new validate_questions_task();
        $method = new \ReflectionMethod(validate_questions_task::class, 'build_system_instruction');
        $method->setAccessible(true);
        $instruction = $method->invoke($task, $this->scale_generation());

        foreach (problem_category::VALUES as $key) {
            $this->assertStringContainsString($key, $instruction, "prompt must mention the '$key' key");
        }
        $this->assertDoesNotMatchRegularExpression('/empty if accepted/i', $instruction);
        $this->assertDoesNotMatchRegularExpression('/üres ha elfogadható/iu', $instruction);
    }

    /**
     * A minimal generation for build_system_instruction(), which since Val-031 takes the record so
     * it can substitute the difficulty definitions for THAT generation's mode. Scale mode, because
     * that is what the shipped default is and what these assertions describe; free text would
     * deliberately leave the difficulty clause empty.
     *
     * @return \stdClass
     */
    private function scale_generation(): \stdClass {
        $generation = new \stdClass();
        $generation->settings = json_encode([
            'difficulty' => [
                'mode'  => 'scale',
                'scale' => ['easy' => 1, 'medium' => 1, 'hard' => 1],
            ],
        ]);

        return $generation;
    }
}
