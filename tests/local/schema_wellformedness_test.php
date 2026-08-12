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

namespace local_artqtml\local;

use local_artqtml\local\question_schema;
use local_artqtml\task\validate_questions_task;

/**
 * Provider-agnostic well-formedness checks over EVERY enum in EVERY request schema the plugin
 * sends - the Gemini validation response schema and the Claude generation Structured Outputs
 * schema.
 *
 * Why this exists: the problem_category outage was a single illegal enum member (an empty string,
 * which Gemini rejects at schema level) that no test ever looked at. It cost nine days and was
 * only found the first time anything made a real Gemini validation call. This assertion needs no
 * API key, costs nothing, and would have caught it on the day it was written.
 *
 * Deliberately written as a recursive walk rather than a list of known enum paths, so a schema
 * (or an enum inside an existing schema) added in future is covered without editing this file.
 * If it finds no enums at all it fails, so the walk silently going blind is itself a failure.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\question_schema
 * @covers     \local_artqtml\task\validate_questions_task
 */
final class schema_wellformedness_test extends \advanced_testcase {
    /**
     * Every request schema the plugin builds, keyed by a human-readable name.
     *
     * Add a schema here when a new provider/call is introduced; the assertions themselves need no
     * change. The Claude schema is built for several settings shapes because its per-type hint and
     * feedback fragments are conditional - the "all on" variant exercises the widest schema.
     *
     * @return array<string, array>
     */
    protected function all_request_schemas(): array {
        $alltypes = question_types::CODES;

        $hintsandfeedbackoff = ['types' => []];
        $hintsandfeedbackon = ['types' => []];
        foreach ($alltypes as $code) {
            $hintsandfeedbackoff['types'][$code] = ['hintenabled' => false, 'feedbackenabled' => false];
            $hintsandfeedbackon['types'][$code] = ['hintenabled' => true, 'feedbackenabled' => true];
        }

        $validator = new validate_questions_task();
        $buildschema = new \ReflectionMethod(validate_questions_task::class, 'build_schema');
        $buildschema->setAccessible(true);

        return [
            'claude generation schema (hints/feedback off)' => question_schema::build($hintsandfeedbackoff),
            'claude generation schema (hints/feedback on)'  => question_schema::build($hintsandfeedbackon),
            'claude generation schema (no settings)'        => question_schema::build([]),
            // The build_schema() method takes no arguments: the Gemini response schema is unconditional
            // (hint_quality/feedback_quality are always required - see its own docblock).
            'gemini validation schema'                     => $buildschema->invoke($validator),
        ];
    }

    /**
     * Recursively collect every 'enum' found anywhere in a schema, keyed by its JSON path so a
     * failure names the exact offending node.
     *
     * @param mixed $node
     * @param string $path
     * @return array<string, mixed> path => the raw enum value found at that path
     */
    protected function collect_enums($node, string $path = '$'): array {
        if (!is_array($node)) {
            return [];
        }

        $found = [];
        foreach ($node as $key => $value) {
            $childpath = $path . '.' . $key;
            if ($key === 'enum') {
                $found[$childpath] = $value;
                continue;
            }
            $found += $this->collect_enums($value, $childpath);
        }

        return $found;
    }

    /**
 * no enum member anywhere may be an empty string, and no enum may be empty.
 *
 * An empty-string member is what made every Gemini validation call fail schema validation with
 * "problem_category.enum[0]: cannot be empty"; an empty enum is unsatisfiable and would reject
 * every response.
 */
    public function test_no_schema_enum_contains_an_empty_string_or_is_empty(): void {
        $totalenums = 0;

        foreach ($this->all_request_schemas() as $schemaname => $schema) {
            $enums = $this->collect_enums($schema);

            foreach ($enums as $path => $enum) {
                $where = $schemaname . ' at ' . $path;

                $this->assertIsArray($enum, "$where: an enum must be a list of values");
                $this->assertNotEmpty($enum, "$where: an empty enum can never be satisfied");

                foreach ($enum as $index => $member) {
                    $this->assertIsString($member, "$where" . "[$index]: enum members must be strings");
                    $this->assertNotSame(
                        '',
                        $member,
                        "$where" . "[$index]: an empty string is not a legal enum member - this is exactly the "
                            . 'defect that made every Gemini validation call fail schema validation'
                    );
                    $this->assertSame(
                        trim($member),
                        $member,
                        "$where" . "[$index]: an enum member must not have leading/trailing whitespace"
                    );
                }

                $this->assertSame(
                    array_values(array_unique($enum)),
                    array_values($enum),
                    "$where: an enum must not repeat a member"
                );
            }

            $totalenums += count($enums);
        }

        // A walk that finds nothing would pass every assertion above while checking nothing at all.
        $this->assertGreaterThan(
            0,
            $totalenums,
            'no enums were found in any request schema - the recursive walk is not reaching them'
        );
    }

    /**
     * Every enum in every schema is drawn from a single-source constant, not an inline literal.
     *
     * Keeps the two known enums honest by identity (not just well-formedness): whatever the schema
     * says must be exactly what the corresponding constant says.
     */
    public function test_known_enums_come_from_their_single_source_constants(): void {
        $validator = new validate_questions_task();
        $buildschema = new \ReflectionMethod(validate_questions_task::class, 'build_schema');
        $buildschema->setAccessible(true);
        $schema = $buildschema->invoke($validator);

        $properties = $schema['properties']['evaluations']['items']['properties'];

        $this->assertSame(
            validation_suggestion::VALUES,
            $properties['suggestion']['enum'],
            'the suggestion enum must be exactly \local_artqtml\local\validation_suggestion::VALUES'
        );
        $this->assertSame(
            problem_category::VALUES,
            $properties['problem_category']['enum'],
            'the problem_category enum must be exactly \local_artqtml\local\problem_category::VALUES'
        );
    }
}
