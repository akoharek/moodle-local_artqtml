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
 * The shipped prompt seed must stay in step with the code that substitutes into it.
 *
 * Scale + sourceonly prompt defaults for IH/FE/SR.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\prompt_seed
 */
final class prompt_defaults_test extends \advanced_testcase {
    /** @var string[] every setting the seed file must provide. */
    private const EXPECTED_SETTINGS = [
        'generatorprompttemplate',
        'promptknowledgesourceonly',
        'promptnegation',
        'promptoptioncount',
        'promptitemcount',
        'promptnosourcemetaref',
        'promptdifficultyscale',
        'promptfeedbackcorrect',
        'promptfeedbackincorrect',
        'promptoptionexplanation',
        'promptoptionexplanationtruefalse',
        'validatorprompttemplate',
        'validationpromptsuggestion',
        'validationpromptcategory',
        'validationpromptlanguage',
        'validationpromptdifficulty',
        'validationpromptwording',
        'validationpromptitemsource',
        'promptjsoninvalid',
    ];

    /**
     * Read the shipped seed file.
     *
     * @return array<string, string>
     */
    private function defaults(): array {
        global $CFG;

        return require($CFG->dirroot . '/local/artqtml/db/prompt_defaults.php');
    }

    /**
     * Every setting install and upgrade rely on is present, and none is empty.
     */
    public function test_the_seed_file_provides_every_setting(): void {
        $defaults = $this->defaults();

        foreach (self::EXPECTED_SETTINGS as $setting) {
            $this->assertArrayHasKey($setting, $defaults, "db/prompt_defaults.php has no '$setting'");
            $this->assertNotSame('', trim($defaults[$setting]), "the shipped '$setting' is empty");
        }

        $this->assertSame(
            [],
            array_diff(array_keys($defaults), self::EXPECTED_SETTINGS),
            'db/prompt_defaults.php provides a setting no admin field exposes - it would be written '
                . 'to config and then never read'
        );
    }

    /**
     * The template carries every placeholder build_prompt() substitutes.
     */
    public function test_the_template_carries_every_placeholder(): void {
        $template = $this->defaults()['generatorprompttemplate'];

        foreach (
            [
                '{{QUESTION_COUNTS}}',
                '{{DIFFICULTY_MODE}}',
                '{{KNOWLEDGE_SOURCE}}',
                '{{NEGATION_INSTRUCTION}}',
                '{{TYPE_INSTRUCTIONS}}',
            ] as $placeholder
        ) {
            $this->assertStringContainsString(
                $placeholder,
                $template,
                "the shipped template has no $placeholder, so that value would never reach the model"
            );
        }
    }

    /**
     * The fragments carry their own placeholders, which come from other admin settings.
     */
    public function test_the_fragments_carry_their_placeholders(): void {
        $defaults = $this->defaults();

        $this->assertStringContainsString('{{OPTION_MIN}}', $defaults['promptoptioncount']);
        $this->assertStringContainsString('{{OPTION_MAX}}', $defaults['promptoptioncount']);
        $this->assertStringContainsString('{{SR_ITEM_COUNT}}', $defaults['promptitemcount']);

        foreach (['{{EASY}}', '{{MEDIUM}}', '{{HARD}}'] as $placeholder) {
            $this->assertStringContainsString($placeholder, $defaults['promptdifficultyscale']);
        }

        foreach (
            [
                '{{DIFFICULTY_INSTRUCTION}}',
                '{{WORDING_INSTRUCTION}}',
                '{{ITEMSOURCE_INSTRUCTION}}',
            ] as $placeholder
        ) {
            $this->assertStringContainsString(
                $placeholder,
                $defaults['validatorprompttemplate'],
                "the shipped validator template has no $placeholder, so that clause would never "
                    . 'reach the model'
            );
        }

        foreach (['promptfeedbackcorrect', 'promptfeedbackincorrect'] as $setting) {
            $this->assertStringContainsString('{{TYPE}}', $defaults[$setting]);
            $this->assertStringContainsString('{{FEEDBACK}}', $defaults[$setting]);
        }
    }

    /**
     * The template instructs the model about the language of the questions.
     */
    public function test_the_template_states_the_output_language_rule(): void {
        $this->assertStringContainsString(
            'language of the source text',
            $this->defaults()['generatorprompttemplate'],
            'without this sentence the model has no instruction about which language to'
                . 'generate in, and an English prompt over Hungarian source material is exactly '
                . 'the case that breaks'
        );
    }

    /**
     * The always-on stem-wording fragment must name the banned HU/EN meta-references.
     */
    public function test_nosourcemetaref_fragment_names_banned_phrases(): void {
        $fragment = $this->defaults()['promptnosourcemetaref'];

        foreach (['szöveg szerint', 'according to the text', 'based on the passage'] as $phrase) {
            $this->assertStringContainsString(
                $phrase,
                $fragment,
                "promptnosourcemetaref must name '$phrase' so the model is told what not to write"
            );
        }

        $this->assertStringContainsString(
            'szöveg szerint',
            $this->defaults()['validationpromptwording'],
            'the AI wording check must also name the Hungarian meta-reference'
        );
    }

    /**
     * No prompt text is left in the code or the lang packs.
     */
    public function test_the_generator_holds_no_prompt_text(): void {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/local/artqtml/classes/task/generate_questions_task.php');

        $this->assertStringNotContainsString(
            "get_string('prompt",
            $source,
            'generate_questions_task reads prompt text from a lang string. requires every'
                . 'word of the prompt to come from admin settings.'
        );
    }

    /**
     * An empty setting is filled from the shipped text.
     */
    public function test_seeding_fills_an_empty_setting(): void {
        $this->resetAfterTest();

        set_config('generatorprompttemplate', '', 'local_artqtml');
        $result = prompt_seed::apply();

        $this->assertContains('generatorprompttemplate', $result['seeded']);
        $this->assertSame(
            $this->defaults()['generatorprompttemplate'],
            get_config('local_artqtml', 'generatorprompttemplate')
        );
    }

    /**
     * An edited setting is never overwritten, however different it has become.
     */
    public function test_seeding_never_overwrites_an_edit(): void {
        $this->resetAfterTest();

        $edited = 'Wholly rewritten by the administrator. {{QUESTION_COUNTS}}';
        set_config('generatorprompttemplate', $edited, 'local_artqtml');

        $result = prompt_seed::apply();

        $this->assertContains('generatorprompttemplate', $result['kept']);
        $this->assertSame($edited, get_config('local_artqtml', 'generatorprompttemplate'));
    }

    /**
     * Running it twice changes nothing the second time.
     */
    public function test_seeding_is_repeatable(): void {
        $this->resetAfterTest();

        foreach (self::EXPECTED_SETTINGS as $setting) {
            unset_config($setting, 'local_artqtml');
        }

        $first = prompt_seed::apply();
        $second = prompt_seed::apply();

        $seeded = $first['seeded'];
        $expected = self::EXPECTED_SETTINGS;
        sort($seeded);
        sort($expected);

        $this->assertSame($expected, $seeded);
        $this->assertSame([], $second['seeded']);
        $this->assertSame([], $second['kept']);
    }
}
