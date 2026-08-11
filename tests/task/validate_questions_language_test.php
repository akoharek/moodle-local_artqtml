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

use local_artqtml\local\validation_suggestion;
use local_artqtml\local\problem_category;

/**
 * Unit tests for the validator prompt's output-language clause (Val-030).
 *
 * The justification Gemini returns is stored text, written once at validation time - it cannot
 * follow the interface language afterwards, so the language has to be settled in the prompt.
 * Neither shipped template stated one, which is why a Hungarian site got English justifications.
 *
 * **Two things changed on 2026-07-31.** The clause now asks for the *source text's* language rather
 * than the site's (Val-030), so a teacher never sees a question and its reasoning in two languages.
 * And it lives in its own admin setting rather than being appended by code: the whole prompt is
 * editable now (Admin-066/067), which means an administrator who deletes {{LANGUAGE_INSTRUCTION}}
 * from the template loses the clause. That is a deliberate trade for a prompt they can read, so the
 * test that used to pin "an edit cannot drop it" now pins the placeholder instead.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\task\validate_questions_task
 */
final class validate_questions_language_test extends \advanced_testcase {
    /**
     * Build the assembled system instruction the task would send.
     *
     * @return string
     */
    private function build_instruction(): string {
        $task = new validate_questions_task();
        $method = new \ReflectionMethod(validate_questions_task::class, 'build_system_instruction');
        $method->setAccessible(true);

        return (string) $method->invoke($task, $this->scale_generation());
    }

    /**
     * With the shipped default template, the assembled prompt states an output language.
     *
     * Compared against the lang string itself, not a re-typed sentence: the wording is allowed to
     * change, its presence is not.
     *
     * @return void
     */
    public function test_default_template_states_an_output_language(): void {
        global $CFG;

        $this->resetAfterTest();
        $shipped = require($CFG->dirroot . '/local/artqtml/db/prompt_defaults.php');
        foreach ($shipped as $setting => $text) {
            set_config($setting, $text, 'local_artqtml');
        }

        $this->assertStringContainsString(
            $shipped['validationpromptlanguage'],
            $this->build_instruction()
        );
    }

    /**
     * An administrator keeps the placeholder, and their own wording keeps the clause.
     *
     * This replaces a test that pinned the opposite - that a template edit could not drop the
     * clause, because the code appended it. Since 2026-07-31 the whole prompt is editable
     * (Admin-066/067) and the clause has its own field, so an edit that keeps
     * {{LANGUAGE_INSTRUCTION}} keeps the clause, and one that removes it does not. The first half
     * is what the product promises; the second is the accepted cost.
     *
     * @return void
     */
    public function test_a_custom_template_keeps_the_clause_through_its_placeholder(): void {
        global $CFG;

        $this->resetAfterTest();
        $shipped = require($CFG->dirroot . '/local/artqtml/db/prompt_defaults.php');
        foreach ($shipped as $setting => $text) {
            set_config($setting, $text, 'local_artqtml');
        }
        set_config(
            'validatorprompttemplate',
            "Completely custom reviewer instructions.\n\n{{LANGUAGE_INSTRUCTION}}",
            'local_artqtml'
        );

        $instruction = $this->build_instruction();

        $this->assertStringContainsString('Completely custom reviewer instructions', $instruction);
        $this->assertStringContainsString($shipped['validationpromptlanguage'], $instruction);
        $this->assertStringNotContainsString('{{LANGUAGE_INSTRUCTION}}', $instruction);
    }

    /**
     * Asking for a language is the instruction most likely to make a model localise the enum
     * values and break the response schema, so the clause has to keep saying they are exempt -
     * and the enum values themselves must still be listed in the prompt.
     *
     * @return void
     */
    public function test_the_enum_values_are_still_named_and_exempt(): void {
        global $CFG;

        $this->resetAfterTest();
        $shipped = require($CFG->dirroot . '/local/artqtml/db/prompt_defaults.php');
        foreach ($shipped as $setting => $text) {
            set_config($setting, $text, 'local_artqtml');
        }

        $instruction = $this->build_instruction();
        $clause = $shipped['validationpromptlanguage'];

        $this->assertStringContainsString('suggestion', $clause);
        $this->assertStringContainsString('problem_category', $clause);

        foreach (validation_suggestion::VALUES as $value) {
            $this->assertStringContainsString($value, $instruction);
        }
        foreach (problem_category::VALUES as $value) {
            $this->assertStringContainsString($value, $instruction);
        }
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
