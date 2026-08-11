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
 * (technical annex 3.3).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Per-type and combined JSON schema fragments matching technical annex 3.3.1-3.3.8.
 */
class question_schema {
    /**
     * Fields present on every question type regardless of settings (technical annex 3.3.1).
     *
     * hint1/hint2/generalfeedback used to live here unconditionally, which meant Claude was
     * required to invent hint/feedback content even for a type where the teacher had that
     * switch off in generate_form.php (Cursor audit v3 #4) - they are now added per-type by
     * {@see self::apply_hint_feedback()} only when actually enabled for that type in this
     * generation's settings.
     *
     * @param string $typecode IH/FE/FT/SR/EH/RV
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
     * The FE/FT min/max answer-option count (Admin-025) and the SR fixed item count
     * (Admin-036) are not expressible here: Claude Structured Outputs only allows
     * minItems/maxItems values of 0 or 1. Those counts are instead communicated to the
     * model via the prompt (see generate_questions_task::build_prompt()).
     *
     * @param array $settings this generation's decoded settings (settings['types'][$typecode]
     *      ['hintenabled'|'feedbackenabled']) - Cursor audit v3 #4: which per-type hint/feedback
     *      switches are on, so the schema only requires what was actually asked for.
     * @return array
     */
    public static function build(array $settings): array {
        // BL-30/BL-33: only the types this generation actually asked for. Until 2026-08-01 all six
        // fragments went out on every request, so a pure True/False generation still carried the
        // essay, ordering, short-answer and both multichoice schemas. Two costs, one of them
        // possibly serious:
        //
        // - tokens paid for on every call, for branches nothing can legitimately match;
        // - FE and FT are produced by the same method and differ in exactly one place, the `type`
        // const. Two near-identical branches in an anyOf is the hardest kind of choice to put in
        // front of a model, and FT returned zero questions on six consecutive attempts (BL-30)
        // while every other type returned six. Narrowing the list removes the choice entirely
        // wherever only one of the pair was requested.
        //
        // The counts have always been available here - generate.php writes them into
        // $settings['counts'] - they were simply not consulted.
        $builders = [
            'IH' => fn(): array => self::ih_schema($settings),
            'FE' => fn(): array => self::fe_ft_schema('FE', $settings),
            'FT' => fn(): array => self::fe_ft_schema('FT', $settings),
            'SR' => fn(): array => self::sr_schema($settings),
            'EH' => fn(): array => self::eh_schema($settings),
            'RV' => fn(): array => self::rv_schema($settings),
        ];

        $counts = $settings['counts'] ?? [];
        $branches = [];
        foreach ($builders as $code => $builder) {
            if ((int) ($counts[$code] ?? 0) > 0) {
                $branches[] = $builder();
            }
        }

        // Settings with no counts at all (an older row, or a caller that did not set them) keep the
        // previous behaviour rather than producing a schema that permits nothing - an empty anyOf
        // would reject every response, which is a worse failure than a wide one.
        if ($branches === []) {
            $branches = array_map(static fn(callable $builder): array => $builder(), array_values($builders));
        }

        // A single requested type needs no choice at all: anyOf with one branch is that branch.
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
     * FE/FT (multichoice single/multiple) schema fragment (3.3.3/3.3.4).
     *
     * @param string $typecode 'FE' or 'FT'
     * @param array $settings
     * @return array
     */
    protected static function fe_ft_schema(string $typecode, array $settings): array {
        // BL-29: the per-option explanation lives inside the option itself, because that is the only
        // place it can be tied to the choice it explains. Added only when the teacher asked for it -
        // Structured Outputs requires every declared property, so declaring it unconditionally would
        // make the model write one for every option of every question, whether wanted or not.
        $optionproperties = [
            'text'    => ['type' => 'string'],
            'correct' => ['type' => 'boolean'],
        ];
        $optionrequired = ['text', 'correct'];

        if (!empty($settings['types'][$typecode]['explanationenabled'])) {
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

        $properties = self::common_properties($typecode) + [
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
        self::apply_hint_feedback($settings, $typecode, $properties, $required);

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * Gen-023/024: two progressive hints. Gen-022: the hint switch (and so, potentially, this
     * schema fragment) applies to all six question types now, not just the four Moodle can
     * attach a native question_hints row to - question_importer.php's own, separate
     * {@see question_types::supports_hints()} check is what still limits which types actually
     * get a real Moodle "try again" hint; for IH/EH, hint1/hint2 are stored in questiondata and
     * surfaced only in the plugin's own approve.php review UI.
     *
     * Moodle's own multi-attempt hint mechanism shows one hint per failed attempt, in order -
     * hint1 first (general guidance), hint2 second (more specific) - neither is ever allowed to
     * give the answer away outright; that is instead what a "reveals_answer" quality check on
     * Gemini's validation side (Gen-028) exists to catch.
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
     * M-25: shown to the student regardless of which answer they picked (unlike the per-type
     * correct/incorrect feedback templates, which are admin-configured, not AI-generated) - a
     * short explanation of the underlying concept/reasoning.
     *
     * Gen-026: maxLength is advisory only - Claude Structured Outputs does not actually enforce
     * JSON Schema string length constraints, so question_importer.php still truncates (and logs)
     * defensively at the same 250 characters on the way in.
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
     * The per-option explanation for a true/false question (BL-29).
     *
     * True/False has no options array: its two answers are the values themselves, and Moodle keeps
     * their feedback in two named fields rather than in a list. So the pair travels as two strings
     * here, and question_form_builder maps them onto feedbacktrue / feedbackfalse.
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
     * Merge hint1/hint2 and/or generalfeedback into $properties/$required for $typecode, only
     * when this generation's settings actually have that switch on for that specific type
     * (Cursor audit v3 #4) - a type with the switch off gets neither the property nor the
     * required entry, so Claude is never asked to invent content nobody asked for.
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

        // BL-29: the per-answer explanation, and only for True/False here - FE and FT carry theirs
        // inside the options array, which fe_ft_schema() builds itself.
        //
        // Off by default, and the switch is what keeps it out of the schema entirely rather than
        // merely unused: an explanation is written per option, so a six-question generation with
        // four options each is twenty-four extra sentences. Nobody who leaves it off pays for it.
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

    /**
     * EH (essay) schema fragment (3.3.6).
     *
     * @param array $settings
     * @return array
     */
    protected static function eh_schema(array $settings): array {
        $properties = self::common_properties('EH') + [
            'graderinfo' => ['type' => 'string'],
        ];
        $required = ['type', 'questiontext', 'difficulty_label', 'source_reference', 'graderinfo'];
        self::apply_hint_feedback($settings, 'EH', $properties, $required);

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * RV (shortanswer) schema fragment (3.3.7).
     *
     * @param array $settings
     * @return array
     */
    protected static function rv_schema(array $settings): array {
        $properties = self::common_properties('RV') + [
            // The accepted answer is ONE WORD, and the cap is here rather than only in the prompt
            // because this is the field the whole type stands on.
            //
            // Measured 2026-08-02 (BL-32): of 36 generated short-answer questions, only 10 had an
            // answer a student could actually type. The rest were full sentences - "A pektin a
            // szervezetben zselés anyaggá alakul, ezzel segíti az emésztést és a bélflóra
            // egészségét." qtype_shortanswer compares what the student types against the stored
            // string, so those 26 questions could be known and still score zero. The better the
            // question fitted its difficulty level, the less gradable it was: more thinking admits
            // more correct phrasings, and the type accepts one.
            //
            // András's decision, 2026-08-02: one word. That narrows short answer to definition-type
            // questions - "mi az oxigén vegyjele?" - and gives up its upper difficulty levels. The
            // measurement says those levels were never usable, so what is lost is the appearance of
            // a capability, not the capability. 30 characters is a Hungarian compound word with
            // room to spare ("terméshozam", "gyümölcsfeldolgozás") and refuses a clause.
            'answer' => [
                'type' => 'string',
                'maxLength' => 30,
                'description' => 'A single word - no spaces, no punctuation, no sentence.',
            ],
        ];
        $required = ['type', 'questiontext', 'difficulty_label', 'source_reference', 'answer'];
        self::apply_hint_feedback($settings, 'RV', $properties, $required);

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }
}
