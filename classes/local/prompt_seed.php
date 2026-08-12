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
 * Writes the shipped prompt text into the database, without ever overwriting an edit.
 *
 * The rule, in one sentence: **an empty setting is filled from the shipped file; a setting that
 * differs from the shipped text is left exactly as it is.**
 *
 * **What this deliberately gives up.** A site that never customised its prompt also never receives
 * an improvement to the shipped one: its stored value differs from the new default, and "differs"
 * means "leave alone". Telling an untouched value apart from an edited one would need a fingerprint
 * of whatever was seeded, stored alongside it. That is not built, because the failure it would
 * prevent (a site staying on an older prompt) is visible and recoverable - the administrator can
 * see both texts on one screen - while the failure it could cause (overwriting a customer's tuned
 * prompt during a routine upgrade) is neither.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Seeds the prompt settings from the shipped defaults, filling only what is empty.
 */
class prompt_seed {
    /**
     * Apply the shipped prompt text to any setting that has none.
     *
     * Safe to call repeatedly: a second run finds nothing empty and writes nothing.
     *
     * @return array{seeded: string[], kept: string[]} which settings were written, which were left
     */
    public static function apply(): array {
        $seeded = [];
        $kept = [];

        foreach (self::shipped() as $setting => $text) {
            $current = get_config('local_artqtml', $setting);

            if ($current === false || trim((string) $current) === '') {
                // Nothing there - a prompt setting left empty would silently produce a prompt with
                // a hole in it, so this is the one case where writing is right.
                set_config($setting, $text, 'local_artqtml');
                $seeded[] = $setting;
                continue;
            }

            if ((string) $current !== $text) {
                // Different from what ships. That is either an administrator's edit or an older
                // shipped version, and this class cannot tell them apart - so it does neither
                // harm nor good, and says so to the caller.
                $kept[] = $setting;
            }
        }

        return ['seeded' => $seeded, 'kept' => $kept];
    }

    /**
     * The shipped starting text, read from the seed file.
     *
     * @return array<string, string>
     */
    public static function shipped(): array {
        global $CFG;

        return require($CFG->dirroot . '/local/artqtml/db/prompt_defaults.php');
    }
}
