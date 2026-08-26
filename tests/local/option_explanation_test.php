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

defined('MOODLE_INTERNAL') || die();

/**
 * The per-answer explanation: the switch, the schema and where the text lands.
 *
 * IH and FE can store per-answer explanations; SR cannot.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
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
        $branch = $this->question_branch($this->settings('FE', false));
        $option = $branch['properties']['options']['items'];

        $this->assertArrayNotHasKey('explanation', $option['properties']);
        $this->assertNotContains('explanation', $option['required']);
    }

    /**
     * With it on, the field is declared AND required - Structured Outputs will not fill an
     * Optional one.
     */
    public function test_the_option_carries_a_required_explanation_when_the_switch_is_on(): void {
        $branch = $this->question_branch($this->settings('FE', true));
        $option = $branch['properties']['options']['items'];

        $this->assertArrayHasKey('explanation', $option['properties']);
        $this->assertContains('explanation', $option['required']);
        $this->assertFalse(
            $option['additionalProperties'],
            'Anthropic rejects the whole request if any object omits this'
        );
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
     * SR has nowhere to put a per-answer explanation and must never be asked for one.
     */
    public function test_ordering_never_asks_for_an_explanation(): void {
        $this->assertFalse(question_types::supports_option_explanation('SR'));

        $branch = $this->question_branch($this->settings('SR', true));
        $flattened = json_encode($branch);
        $this->assertStringNotContainsString('explanation', $flattened);
    }

    /**
     * The switch is offered for exactly the types that can store it.
     */
    public function test_the_switch_is_offered_for_exactly_the_types_that_can_store_it(): void {
        $supported = array_values(array_filter(
            question_types::CODES,
            static fn(string $code): bool => question_types::supports_option_explanation($code)
        ));

        $this->assertSame(['IH', 'FE'], $supported);
        $this->assertCount(3, question_types::CODES);
    }
}
