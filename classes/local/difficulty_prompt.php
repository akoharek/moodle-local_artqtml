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
 * The single source for what the difficulty levels mean, for both the generator and the validator
 * (Admin-069, Val-031).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Renders the admin-configured difficulty definition for a generation's chosen mode.
 *
 * Why this is its own class rather than a method on the generating task. The generator writes a
 * question to a level and the validator has to judge whether it hit that level; if each built its
 * own description of what the levels mean, the two would drift, and the validator would be
 * measuring against a scale the generator never saw. That is the same two-source shape this plugin
 * removed from the suggestion and problem-category value lists, and for the same reason: a
 * disagreement between the two would look like a model failure and would not be one.
 */
class difficulty_prompt {
    /**
     * The difficulty description for a generation, ready to drop into a prompt.
     *
     * The counts are data and are substituted here; the wording is prompt text and comes from the
     * admin settings, like every other sentence the models read (Admin-066/067).
     *
     * FREE-TEXT MODE RETURNS A REFERENCE, NOT THE TEACHER'S WORDS. It used to return the
     * description unchanged, on the reasoning that in free-text mode "there is nothing to define" -
     * which was true about the difficulty scale and wrong about the prompt: the return value of
     * this method is dropped into a SYSTEM message, so returning user-authored text here hands a
     * per-generation form field the administrator's authority.
     *
     * This class exists to be the one place that answers "what does this generation's difficulty
     * say", precisely so the generator and the validator cannot drift apart. That is also why the
     * fix belongs here rather than only at the call site: a second caller added later would
     * otherwise reintroduce the defect by doing the obvious thing.
     *
     * The teacher's actual description travels in the structured user message, under
     * teacher_preferences.difficulty - see generate_questions_task::build_user_content().
     *
     * @param array $difficulty the generation's decoded difficulty settings
     * @return string empty only if the admin has emptied the setting
     */
    public static function describe(array $difficulty): string {
        $mode = $difficulty['mode'] ?? 'scale';

        if ($mode === 'freetext') {
            return trim((string) (get_config('local_artqtml', 'promptdifficultyfreetextreference') ?: ''));
        }

        if ($mode === 'bloom') {
            $bloom = $difficulty['bloom'] ?? [];
            return strtr((string) get_config('local_artqtml', 'promptdifficultybloom'), [
                '{{REMEMBER}}'   => (string) (int) ($bloom['remember'] ?? 0),
                '{{UNDERSTAND}}' => (string) (int) ($bloom['understand'] ?? 0),
                '{{APPLY}}'      => (string) (int) ($bloom['apply'] ?? 0),
            ]);
        }

        $scale = $difficulty['scale'] ?? [];
        return strtr((string) get_config('local_artqtml', 'promptdifficultyscale'), [
            '{{EASY}}'   => (string) (int) ($scale['easy'] ?? 0),
            '{{MEDIUM}}' => (string) (int) ($scale['medium'] ?? 0),
            '{{HARD}}'   => (string) (int) ($scale['hard'] ?? 0),
        ]);
    }

    /**
     * The same description, read straight off a generation record.
     *
     * The validator holds the generation but not its decoded settings, and decoding them at each
     * call site is how the two sides would start disagreeing about defaults.
     *
     * @param \stdClass $generation a local_artqtml_generations record
     * @return string
     */
    public static function for_generation(\stdClass $generation): string {
        $settings = json_decode((string) ($generation->settings ?? ''), true);

        return self::describe(is_array($settings) ? ($settings['difficulty'] ?? []) : []);
    }
}
