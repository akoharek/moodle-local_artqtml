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
 * Difficulty prompt helpers.
 *
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Renders the admin-configured difficulty definition for a generation's scale mode.
 *
 * Why this is its own class rather than a method on the generating task. The generator writes a
 * Question to a level and the validator has to judge whether it hit that level; if each built its
 * Own description of what the levels mean, the two would drift, and the validator would be
 * Measuring against a scale the generator never saw.
 */
class difficulty_prompt {
    /**
     * The difficulty description for a generation, ready to drop into a prompt.
     *
     * Uses the Easy/Medium/Hard scale fragment. Older stored settings keys outside scale
     * Are ignored.
     *
     * @param array $difficulty the generation's decoded difficulty settings
     * @return string empty only if the admin has emptied the setting
     */
    public static function describe(array $difficulty): string {
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
     * @param \stdClass $generation a local_artqtml_generations record
     * @return string
     */
    public static function for_generation(\stdClass $generation): string {
        $settings = json_decode((string) ($generation->settings ?? ''), true);

        return self::describe(is_array($settings) ? ($settings['difficulty'] ?? []) : []);
    }
}
