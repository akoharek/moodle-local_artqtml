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
 * The shipped prompt seed must stay in step with the code that substitutes into it (Admin-066).
 *
 * What changed, and why this replaces the old guard. Until 2026-07-31 the prompt was hidden -
 * encrypted in the database, base64 in the lang packs - and the test here asserted that the
 * settings page did not leak it. That protection is gone by decision: the whole prompt now lives
 * in admin settings, in plain text, and an administrator may edit or break it.
 *
 * The risk therefore moved. It is no longer disclosure; it is a placeholder going missing. The
 * template and the substitution list are two halves of one contract kept in two files, and nothing
 * fails loudly when they drift: a template missing a placeholder produces a perfectly valid prompt
 * that silently never carries that value. That is not hypothetical - it is exactly what was found
 * on the development machine that day, where a saved template had lost a line and nobody noticed,
 * because generation kept working.
 *
 * BL-47, 2026-08-03: the example that used to be given here was {{SEED}}, which has since been
 * removed outright - the value it carried never did anything, because the Claude Messages API has
 * no seed parameter. The contract this test guards is unchanged; only the illustration moved.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\prompt_seed
 */
final class prompt_defaults_test extends \advanced_testcase {
    /** @var string[] every setting the seed file must provide. */
    private const EXPECTED_SETTINGS = [
        'generatorprompttemplate',
        'promptknowledgesourceonly',
        'promptknowledgeownknowledge',
        'promptnegation',
        'promptoptioncount',
        'promptitemcount',
        // BL-32: short answer stores one word, which is a constraint on the question too.
        'promptshortanswer',
        // Always-on: do not name the source document in the stem.
        'promptnosourcemetaref',
        // Admin-069: what the difficulty levels mean. Before these existed the prompt carried the
        // labels and nothing else, and the model invented its own scale - 72 of 181 measured
        // questions did not match the level they were asked for.
        'promptdifficultyscale',
        'promptdifficultybloom',
        // 2026-08-04: the two sentences the system prompt uses INSTEAD of a teacher's own words.
        // A free-text difficulty description and a per-type instruction used to be substituted
        // into the system prompt, which gave a per-generation form field the administrator's
        // authority. They now travel as untrusted preferences in the user message, and these two
        // fragments are what the system prompt says about them.
        'promptdifficultyfreetextreference',
        'promptteacherinstructionreference',
        'promptfeedbackcorrect',
        'promptfeedbackincorrect',
        // BL-29: what an explanation attached to one answer option should say, plus the extra
        // clause True/False needs - two options over one claim leave nothing to say twice.
        'promptoptionexplanation',
        'promptoptionexplanationtruefalse',
        'validatorprompttemplate',
        'validationpromptsuggestion',
        'validationpromptcategory',
        'validationpromptlanguage',
        // Val-031/032/033: the three checks the validator did not make - difficulty, wording, and
        // whether an ordering item exists in the source text at all.
        'validationpromptdifficulty',
        'validationpromptwording',
        'validationpromptitemsource',
        'validationpromptshortanswer',
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
     *
     * A missing one costs nothing visible: the prompt is still valid, the generation still runs,
     * and the value simply never reaches the model.
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

        // Admin-069: the level definitions take the requested per-level counts by placeholder, so
        // the sentence stays editable while the numbers stay the code's.
        foreach (['{{EASY}}', '{{MEDIUM}}', '{{HARD}}'] as $placeholder) {
            $this->assertStringContainsString($placeholder, $defaults['promptdifficultyscale']);
        }
        foreach (['{{REMEMBER}}', '{{UNDERSTAND}}', '{{APPLY}}'] as $placeholder) {
            $this->assertStringContainsString($placeholder, $defaults['promptdifficultybloom']);
        }

        // 2026-08-04: the reference fragments must carry {{TYPE}} and NOTHING that would let user
        // text back into the system prompt. A placeholder like {{DESCRIPTION}} here would quietly
        // undo the whole change - the sentence would go back to being a container for whatever the
        // teacher typed, which is exactly what it replaced.
        $this->assertStringContainsString('{{TYPE}}', $defaults['promptteacherinstructionreference']);

        foreach (['promptdifficultyfreetextreference', 'promptteacherinstructionreference'] as $key) {
            $this->assertNotSame('', trim($defaults[$key]));
            foreach (['{{DESCRIPTION}}', '{{INSTRUCTION}}', '{{USER_TEXT}}', '{{FREETEXT}}'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $defaults[$key],
                    "the '$key' fragment carries $forbidden - a reference fragment must never be a "
                        . 'container for user-authored text'
                );
            }
        }

        // Val-031/032/033: the validator template must actually carry the three new clauses,
        // otherwise they are stored, editable, and never sent.
        foreach (
            [
                '{{DIFFICULTY_INSTRUCTION}}',
                '{{WORDING_INSTRUCTION}}',
                '{{ITEMSOURCE_INSTRUCTION}}',
                '{{SHORTANSWER_INSTRUCTION}}',
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
     * Gen-031: the template instructs the model about the language of the questions.
     *
     * Before the prompt became English-only, a Hungarian site sent a Hungarian prompt and the
     * output language followed by accident. That accident is gone, so the instruction has to be
     * present deliberately.
     */
    public function test_the_template_states_the_output_language_rule(): void {
        $this->assertStringContainsString(
            'language of the source text',
            $this->defaults()['generatorprompttemplate'],
            'Gen-031: without this sentence the model has no instruction about which language to '
                . 'generate in, and an English prompt over Hungarian source material is exactly '
                . 'the case that breaks'
        );
    }

    /**
     * The always-on stem-wording fragment must name the banned HU/EN meta-references.
     *
     * Without these phrases in the shipped seed, an upgrade that only fills empty settings would
     * leave existing sites with a fragment that never mentions what to avoid.
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
     * Admin-066: no prompt text is left in the code or the lang packs.
     */
    public function test_the_generator_holds_no_prompt_text(): void {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/local/artqtml/classes/task/generate_questions_task.php');

        $this->assertStringNotContainsString(
            "get_string('prompt",
            $source,
            'generate_questions_task reads prompt text from a lang string. Admin-066 requires every '
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
     *
     * This is the guarantee an upgrade has to keep: a customer who tuned their prompt must not
     * lose it to a routine version bump.
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

        // Sorted, because the order apply() reports is simply the seed file's own key order, and
        // nothing depends on it - prompt_seed writes each setting independently. What is worth
        // asserting here is that the first run seeded every one of them. Membership itself is
        // asserted in both directions by test_every_shipped_default_has_a_field, so pinning the
        // order added no protection and broke on 2026-08-01 purely because Admin-069's two
        // difficulty fragments were inserted into db/prompt_defaults.php.
        $seeded = $first['seeded'];
        $expected = self::EXPECTED_SETTINGS;
        sort($seeded);
        sort($expected);

        $this->assertSame($expected, $seeded);
        $this->assertSame([], $second['seeded']);
        $this->assertSame([], $second['kept']);
    }
}
