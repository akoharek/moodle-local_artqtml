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

/**
 * The per-answer explanation (BL-29): the switch, the schema and where the text lands.
 *
 * What a student could not learn before this existed: why the option they picked was wrong. The
 * only AI-written explanation was `generalfeedback` - one per question, shown whatever they
 * answered - and Moodle's own per-option feedback column was filled with an empty string on every
 * generation.
 *
 * The assertions here are about the two things that have to stay in step. The schema field and the
 * prompt instruction are both conditional on the same switch, and if they ever disagree the failure
 * is expensive in one direction and silent in the other: asking for a field the schema forbids
 * fails the whole call, and declaring a field nobody asked for makes the model write an explanation
 * for every option of every question and bills for it.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\question_schema
 * @covers     \local_artqtml\local\question_types::supports_option_explanation
 */
final class option_explanation_test extends \advanced_testcase {
    /**
     * Settings asking for one question of the given type, with the switch in the given position.
     *
     * @param string $typecode
     * @param bool $enabled
     * @return array
     */
    private function settings(string $typecode, bool $enabled): array {
        return [
            'counts' => [$typecode => 1],
            'types'  => [$typecode => ['explanationenabled' => $enabled]],
        ];
    }

    /**
     * The branch of a built schema that describes one question, whatever the wrapper looks like.
     *
     * A single requested type gets its object directly; more than one gets an anyOf. Only one type
     * is ever asked for here, but reading it this way means the test does not break if that
     * arrangement changes.
     *
     * @param array $settings
     * @return array
     */
    private function question_branch(array $settings): array {
        $items = question_schema::build($settings)['properties']['questions']['items'];

        return $items['anyOf'][0] ?? $items;
    }

    /**
     * With the switch off, nothing about an explanation reaches the schema.
     */
    public function test_the_option_has_no_explanation_field_when_the_switch_is_off(): void {
        foreach (['FE', 'FT'] as $typecode) {
            $branch = $this->question_branch($this->settings($typecode, false));
            $option = $branch['properties']['options']['items'];

            $this->assertArrayNotHasKey('explanation', $option['properties'], $typecode);
            $this->assertNotContains('explanation', $option['required'], $typecode);
        }
    }

    /**
     * With it on, the field is declared AND required - Structured Outputs will not fill an
     * optional one.
     */
    public function test_the_option_carries_a_required_explanation_when_the_switch_is_on(): void {
        foreach (['FE', 'FT'] as $typecode) {
            $branch = $this->question_branch($this->settings($typecode, true));
            $option = $branch['properties']['options']['items'];

            $this->assertArrayHasKey('explanation', $option['properties'], $typecode);
            $this->assertContains('explanation', $option['required'], $typecode);
            $this->assertFalse(
                $option['additionalProperties'],
                'Anthropic rejects the whole request if any object omits this'
            );
        }
    }

    /**
     * True/False carries its pair in named fields, because it has no options array.
     */
    public function test_truefalse_gets_two_named_explanations(): void {
        $off = $this->question_branch($this->settings('IH', false))['properties'];
        $this->assertArrayNotHasKey('explanationtrue', $off);
        $this->assertArrayNotHasKey('explanationfalse', $off);

        $branch = $this->question_branch($this->settings('IH', true));
        $this->assertArrayHasKey('explanationtrue', $branch['properties']);
        $this->assertArrayHasKey('explanationfalse', $branch['properties']);
        $this->assertContains('explanationtrue', $branch['required']);
        $this->assertContains('explanationfalse', $branch['required']);
    }

    /**
     * The three types that have nowhere to put an explanation never get asked for one.
     *
     * This is not a policy choice: ordering keeps one combined feedback for the whole question, and
     * short answer and essay have no options. A field generated for them would be discarded on
     * import, so the cost would buy nothing.
     */
    public function test_the_types_with_nowhere_to_store_it_never_ask_for_it(): void {
        foreach (['SR', 'RV', 'EH'] as $typecode) {
            $this->assertFalse(
                question_types::supports_option_explanation($typecode),
                "$typecode has no Moodle field for a per-answer explanation"
            );

            // Even if a stored setting claims otherwise - an older row, or a hand-edited one - the
            // schema must not grow a field the importer would throw away.
            $branch = $this->question_branch($this->settings($typecode, true));
            $flattened = json_encode($branch);
            $this->assertStringNotContainsString('explanation', $flattened, $typecode);
        }
    }

    /**
     * And the three that do are exactly the three the form offers the switch on.
     */
    public function test_the_switch_is_offered_for_exactly_the_types_that_can_store_it(): void {
        $supported = array_values(array_filter(
            question_types::CODES,
            static fn(string $code): bool => question_types::supports_option_explanation($code)
        ));

        $this->assertSame(['IH', 'FE', 'FT'], $supported);
    }
}
