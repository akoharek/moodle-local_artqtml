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

/**
 * Builds the Claude Structured Outputs JSON schema for generated questions
 * . Supported types: IH/FE/SR.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Per-type and combined JSON schema fragments for IH, FE and SR.
 */
class question_schema {
    /**
     * Fields present on every question type regardless of settings.
     *
     * hint1/hint2/generalfeedback are added per-type by {@see self::apply_hint_feedback()}
     * only when actually enabled for that type in this generation's settings.
     *
     * @param string $typecode IH/FE/SR
     * @return array
     */
    protected static function common_properties(string $typecode): array {
        return [
            'type'             => ['const' => $typecode],
            'questiontext'     => ['type' => 'string'],
            'difficulty_label' => ['type' => 'string'],
            'source_reference' => ['type' => 'string'],
        ];
    }

    /**
 * Build the full output_config.format.schema object sent to the Claude API (3.3.8).
 *
 * @param array $settings this generation's decoded settings (settings['types'][$typecode]
 * ['hintenabled'|'feedbackenabled'])
 * @return array
 */
    public static function build(array $settings): array {
        $builders = [
            'IH' => fn(): array => self::ih_schema($settings),
            'FE' => fn(): array => self::fe_schema($settings),
            'SR' => fn(): array => self::sr_schema($settings),
        ];

        $counts = $settings['counts'] ?? [];
        $branches = [];
        foreach ($builders as $code => $builder) {
            if ((int) ($counts[$code] ?? 0) > 0) {
                $branches[] = $builder();
            }
        }

        // Settings with no counts at all keep the previous behaviour rather than producing a
        // schema that permits nothing.
        if ($branches === []) {
            $branches = array_map(static fn(callable $builder): array => $builder(), array_values($builders));
        }

        $items = count($branches) === 1 ? $branches[0] : ['anyOf' => $branches];

        return [
            'type' => 'object',
            'properties' => [
                'questions' => [
                    'type' => 'array',
                    'items' => $items,
                ],
            ],
            'required' => ['questions'],
            'additionalProperties' => false,
        ];
    }

    /**
     * IH (truefalse) schema fragment (3.3.2).
     *
     * @param array $settings
     * @return array
     */
    protected static function ih_schema(array $settings): array {
        $properties = self::common_properties('IH') + [
            'correctanswer' => ['type' => 'boolean'],
        ];
        $required = ['type', 'questiontext', 'difficulty_label', 'source_reference', 'correctanswer'];
        self::apply_hint_feedback($settings, 'IH', $properties, $required);

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * FE (multichoice single-answer) schema fragment (3.3.3).
     *
     * @param array $settings
     * @return array
     */
    protected static function fe_schema(array $settings): array {
        $optionproperties = [
            'text'    => ['type' => 'string'],
            'correct' => ['type' => 'boolean'],
        ];
        $optionrequired = ['text', 'correct'];

        if (!empty($settings['types']['FE']['explanationenabled'])) {
            $optionproperties['explanation'] = [
                'type'        => 'string',
                'maxLength'   => 250,
                'description' => 'Shown to a student who chose THIS option. For a wrong option, say '
                    . 'what makes it wrong against the source text - this is where a distractor '
                    . 'earns its keep. For the correct one, say briefly why it is right. Address '
                    . 'the option itself, not the question in general.',
            ];
            $optionrequired[] = 'explanation';
        }

        $properties = self::common_properties('FE') + [
            'options' => [
                'type'     => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => $optionproperties,
                    'required' => $optionrequired,
                    'additionalProperties' => false,
                ],
            ],
        ];
        $required = ['type', 'questiontext', 'difficulty_label', 'source_reference', 'options'];
        self::apply_hint_feedback($settings, 'FE', $properties, $required);

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
 * two progressive hints.
 *
 * @return array
 */
    protected static function hint_property(): array {
        return [
            'hint1' => [
                'type'        => 'string',
                'description' => 'A general hint that nudges the student toward the correct '
                    . 'answer without revealing it, shown after their first incorrect attempt.',
            ],
            'hint2' => [
                'type'        => 'string',
                'description' => 'A more specific hint than hint1, shown after a second '
                    . 'incorrect attempt if the student is still stuck - still more specific, '
                    . 'but must not give the correct answer away directly.',
            ],
        ];
    }

    /**
 * shown to the student regardless of which answer they picked.
 *
 * @return array
 */
    protected static function feedback_property(): array {
        return [
            'generalfeedback' => [
                'type'        => 'string',
                'maxLength'   => 250,
                'description' => 'A short (max 250 characters) explanation of the concept or '
                    . 'reasoning behind the correct answer, shown to the student after '
                    . 'attempting the question regardless of whether they answered correctly.',
            ],
        ];
    }

    /**
 * The per-option explanation for a true/false question.
 *
 * @return array
 */
    protected static function ih_explanation_property(): array {
        return [
            'explanationtrue' => [
                'type'        => 'string',
                'maxLength'   => 250,
                'description' => 'Shown to a student who answered TRUE. If true is correct, say '
                    . 'briefly why the statement holds; if it is wrong, say what the source text '
                    . 'actually states. Address the choice the student made.',
            ],
            'explanationfalse' => [
                'type'        => 'string',
                'maxLength'   => 250,
                'description' => 'The same for a student who answered FALSE.',
            ],
        ];
    }

    /**
     * Merge hint1/hint2 and/or generalfeedback into $properties/$required for $typecode.
     *
     * @param array $settings decoded generation settings
     * @param string $typecode
     * @param array $properties by reference
     * @param array $required by reference
     * @return void
     */
    protected static function apply_hint_feedback(array $settings, string $typecode, array &$properties, array &$required): void {
        if (!empty($settings['types'][$typecode]['hintenabled'])) {
            $properties += self::hint_property();
            $required[] = 'hint1';
            $required[] = 'hint2';
        }

        if (!empty($settings['types'][$typecode]['feedbackenabled'])) {
            $properties += self::feedback_property();
            $required[] = 'generalfeedback';
        }

        if ($typecode === 'IH' && !empty($settings['types'][$typecode]['explanationenabled'])) {
            $properties += self::ih_explanation_property();
            $required[] = 'explanationtrue';
            $required[] = 'explanationfalse';
        }
    }

    /**
     * SR (ordering) schema fragment (3.3.5): an array of items to put in the correct order.
     *
     * @param array $settings
     * @return array
     */
    protected static function sr_schema(array $settings): array {
        $properties = self::common_properties('SR') + [
            'items' => [
                'type'     => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => ['text' => ['type' => 'string']],
                    'required' => ['text'],
                    'additionalProperties' => false,
                ],
            ],
        ];
        $required = ['type', 'questiontext', 'difficulty_label', 'source_reference', 'items'];
        self::apply_hint_feedback($settings, 'SR', $properties, $required);

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }
}
