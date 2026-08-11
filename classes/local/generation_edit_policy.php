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
 * When a generation's source text and identifiers may still be changed.
 *
 * THE DEFECT THIS ANSWERS. `upload.php` loaded and saved any generation whose id it was handed,
 * with no regard for what had happened to it since. `upload.php?id=<n>` on a finished generation
 * rewrote its name, its short name, its source text and both of its hashes - and the questions
 * already made from the old text stayed exactly as they were. Nothing broke visibly, which is what
 * makes it worth fixing: the questions and the material they were made from simply stopped
 * describing each other, with no record that they ever had.
 *
 * The worse case is a generation still running. The pipeline reads the source text more than once
 * - the generator reads it, and the validator reads it again to judge the questions against it -
 * so a save landing between the two hands Claude and Gemini different documents, and the validator
 * marks questions wrong for not matching a source that was not there when they were written.
 *
 * THE RULE IS A WHITELIST, deliberately. Only `started` is editable; every other status is not,
 * including any status added later. The alternative - listing the statuses that are forbidden -
 * fails open the day somebody adds an eighth, and it would fail silently.
 *
 * WHAT THIS IS NOT: an ownership check. Any user with `local/artqtml:use` may still edit any
 * draft generation, including a colleague's, and that is Glob-031 working as decided on
 * 2026-08-03 - the tool is a site-wide collaborative one on purpose. The refusal here is about
 * WHAT STATE the generation is in, never about who created it, and the message says so: the user
 * is not being told they lack permission, because they do not.
 *
 * `generate.php` has held the same boundary for the settings page since 2026-08-03. This is the
 * same status line applied to the other half of the same generation.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * The single answer to "may this generation's source still be edited?".
 */
class generation_edit_policy {
    /**
     * Whether the source text and identifiers of this generation may still be changed.
     *
     * "Source" covers the whole of what the upload page owns: name, short name, source text and
     * the two hashes derived from them.
     *
     * @param \stdClass $generation a local_artqtml_generations record
     * @return bool
     */
    public static function can_edit_source(\stdClass $generation): bool {
        return (string) ($generation->status ?? '') === generation_status::STARTED;
    }

    /**
     * Throw unless this generation's source may still be edited.
     *
     * Used immediately before a write, on a record read in the same breath - not on whatever the
     * page loaded when it was opened. The gap between those two is the whole point: a form opened
     * on a draft and submitted a minute later may be submitted against a generation that has since
     * started running.
     *
     * @param \stdClass $generation a freshly read local_artqtml_generations record
     * @return void
     * @throws \moodle_exception if the generation is no longer a draft
     */
    public static function require_source_editable(\stdClass $generation): void {
        if (!self::can_edit_source($generation)) {
            throw new \moodle_exception('cannoteditsourcenondraft', 'local_artqtml');
        }
    }
}
