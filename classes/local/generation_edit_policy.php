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
 * Only draft (`started`) generations are editable. Changing source after questions exist
 * (or while the pipeline is running) would desync stored questions from their material.
 * New statuses default to non-editable (whitelist).
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
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
     * The two hashes derived from them.
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
     * Page loaded when it was opened. The gap between those two is the whole point: a form opened
     * On a draft and submitted a minute later may be submitted against a generation that has since
     * Started running.
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
