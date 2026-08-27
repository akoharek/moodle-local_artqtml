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

/**
 * Unit tests for the validator's suggestion enum as a single source of truth.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\validation_suggestion
 * @covers     \local_artqtml\task\validate_questions_task
 */
final class validate_questions_suggestion_test extends \advanced_testcase {
    /**
     * Exactly the three verdicts, in order, none empty, no duplicates.
     */
    public function test_enum_is_exactly_three_values(): void {
        $this->assertSame(
            ['accepted', 'needs_review', 'rejected'],
            validation_suggestion::VALUES
        );
        $this->assertCount(3, validation_suggestion::VALUES);
        $this->assertNotContains('', validation_suggestion::VALUES, 'empty string must not be an enum member');
        $this->assertSame(
            validation_suggestion::VALUES,
            array_values(array_unique(validation_suggestion::VALUES))
        );

        // The not_evaluated value is the plugin's own marker, never one the validator may return, so it
        // Must stay out of the schema enum while still being displayable.
        $this->assertNotContains(validation_suggestion::NOT_EVALUATED, validation_suggestion::VALUES);
        $this->assertContains(validation_suggestion::NOT_EVALUATED, validation_suggestion::DISPLAY);
        $this->assertCount(4, validation_suggestion::DISPLAY);
    }

    /**
     * The response schema's suggestion enum IS the constant - not a copy of it - and the field
     * Stays required.
     */
    public function test_schema_enum_matches_single_source_and_is_required(): void {
        $task = new validate_questions_task();
        $method = new \ReflectionMethod(validate_questions_task::class, 'build_schema');
        $method->setAccessible(true);
        $schema = $method->invoke($task);

        $properties = $schema['properties']['evaluations']['items']['properties'];
        $required = $schema['properties']['evaluations']['items']['required'];

        $this->assertSame(validation_suggestion::VALUES, $properties['suggestion']['enum']);
        $this->assertNotContains('', $properties['suggestion']['enum']);
        $this->assertContains('suggestion', $required, 'suggestion must stay required');

        // As a set, ignoring order, the schema enum and the constant are the same thing.
        $schemaset = $properties['suggestion']['enum'];
        $constantset = validation_suggestion::VALUES;
        sort($schemaset);
        sort($constantset);
        $this->assertSame($constantset, $schemaset);
    }

    /**
     * The assembled prompt names exactly the three values, and the values come from code rather
     * Than from the (admin-editable) template - so editing the template cannot desynchronise them.
     */
    public function test_assembled_prompt_contains_exactly_the_three_values(): void {
        global $CFG;

        $this->resetAfterTest();
        foreach (require($CFG->dirroot . '/local/artqtml/db/prompt_defaults.php') as $s => $v) {
            set_config($s, $v, 'local_artqtml');
        }

        $task = new validate_questions_task();
        $method = new \ReflectionMethod(validate_questions_task::class, 'build_system_instruction');
        $method->setAccessible(true);

        $prompt = $method->invoke($task, $this->scale_generation());

        foreach (validation_suggestion::VALUES as $value) {
            $this->assertStringContainsString(
                $value,
                $prompt,
                "the assembled prompt must name the '$value' suggestion"
            );
        }

        // Exactly three - a fourth, retired or hallucinated key must not appear.
        foreach (['not_evaluated', 'edited', 'needs_work', 'approved'] as $notavalue) {
            $this->assertStringNotContainsString(
                $notavalue,
                $prompt,
                "'$notavalue' is not a suggestion value and must not appear in the prompt"
            );
        }

        // The shipped template carries a placeholder, never the values themselves. An admin may
        // Rewrite the sentence around {{SUGGESTION_VALUES}}; they cannot make the prompt name a
        // Value the schema would reject, because they never type the list.
        global $CFG;
        $shipped = require($CFG->dirroot . '/local/artqtml/db/prompt_defaults.php');
        $this->assertStringContainsString('{{SUGGESTION_VALUES}}', $shipped['validationpromptsuggestion']);
        foreach (validation_suggestion::VALUES as $value) {
            $this->assertStringNotContainsString(
                $value,
                $shipped['validatorprompttemplate'],
                "the shipped template must not spell out '$value' - the placeholder carries it"
            );
        }
    }

    /**
     * An admin who rewrites the template around the placeholder still gets all three values, and
     * Never types one of them.
     */
    public function test_values_survive_an_admin_template_override(): void {
        global $CFG;

        $this->resetAfterTest();
        foreach (require($CFG->dirroot . '/local/artqtml/db/prompt_defaults.php') as $s => $v) {
            set_config($s, $v, 'local_artqtml');
        }

        set_config(
            'validatorprompttemplate',
            "Completely custom reviewer instructions with no value list.\n\n"
                . "{{SUGGESTION_INSTRUCTION}}\n\n{{CATEGORY_INSTRUCTION}}",
            'local_artqtml'
        );

        $task = new validate_questions_task();
        $method = new \ReflectionMethod(validate_questions_task::class, 'build_system_instruction');
        $method->setAccessible(true);
        $prompt = $method->invoke($task, $this->scale_generation());

        $this->assertStringContainsString('Completely custom reviewer instructions', $prompt);
        foreach (validation_suggestion::VALUES as $value) {
            $this->assertStringContainsString($value, $prompt);
        }
        foreach (\local_artqtml\local\problem_category::VALUES as $value) {
            $this->assertStringContainsString($value, $prompt);
        }
    }

    /**
     * Every displayable value has a lang label; the raw key never reaches the UI.
     */
    public function test_every_value_has_a_lang_label(): void {
        $this->resetAfterTest();

        foreach (array_merge(validation_suggestion::DISPLAY, [validation_suggestion::EDITED]) as $value) {
            $label = validation_suggestion::label($value);
            $this->assertNotEmpty($label);
            $this->assertStringNotContainsString('[[', $label, "validationstatus_$value is missing");
            $this->assertNotSame($value, $label, "validationstatus_$value must not render as its raw key");
        }

        $this->assertSame('Accepted', validation_suggestion::label(validation_suggestion::ACCEPTED));

        $hu = [];
        require(__DIR__ . '/../../lang/hu/local_artqtml.php');
        $hu = $string;

        $this->assertSame('Elfogadható', $hu['validationstatus_accepted']);
        $this->assertSame('Módosítandó', $hu['validationstatus_needs_review']);
        $this->assertSame('Törlendő', $hu['validationstatus_rejected']);
        $this->assertSame('Nem értékelt', $hu['validationstatus_not_evaluated']);
    }

    /**
     * Normalise() keeps the three and falls back for anything else.
     */
    public function test_normalise(): void {
        foreach (validation_suggestion::VALUES as $value) {
            $this->assertSame($value, validation_suggestion::normalise($value));
        }
        $this->assertNull(validation_suggestion::normalise('not_evaluated'));
        $this->assertNull(validation_suggestion::normalise(''));
        $this->assertNull(validation_suggestion::normalise(null));
        $this->assertSame(
            validation_suggestion::NEEDS_REVIEW,
            validation_suggestion::normalise('hallucinated', validation_suggestion::NEEDS_REVIEW)
        );
    }

    /**
     * No file outside the constant's own definition re-types the three-value list.
     */
    public function test_no_file_outside_the_constant_repeats_the_literal_list(): void {
        $root = realpath(__DIR__ . '/../..');
        $allowed = [
            $root . '/classes/local/validation_suggestion.php',
            __FILE__,
            // Frozen historical upgrade steps must keep behaving as they did when they ran.
            $root . '/db/upgrade.php',
        ];

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileinfo) {
            $path = $fileinfo->getPathname();
            if (substr($path, -4) !== '.php' || in_array($path, $allowed, true)) {
                continue;
            }
            if (preg_match('#/(node_modules|vendor|\.git)/#', $path)) {
                continue;
            }
            if (
                preg_match(
                    "/'(accepted|needs_review|rejected)'\s*,\s*'(accepted|needs_review|rejected)'/",
                    file_get_contents($path)
                )
            ) {
                $offenders[] = str_replace($root . '/', '', $path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'these files re-type the suggestion list instead of reading '
                . '\local_artqtml\local\validation_suggestion: ' . implode(', ', $offenders)
        );
    }

    /**
     * scale generation.
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
