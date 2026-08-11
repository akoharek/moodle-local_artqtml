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
 * Event observer definitions for local_artqtml.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// BL-28: BOTH events are needed, and question_created is the one that actually matters.
//
// Moodle 4.x versions questions: saving in the native editor does not overwrite the row, it writes
// a new one. question_type::save_question() - the method every editor save goes through - therefore
// fires \core\event\question_created every time, never question_updated. Core fires
// question_updated in exactly two places, and neither is the editor's save path:
// update_question_version_status.php (draft/ready) and viewquestionname/lib.php (inline rename).
//
// Until 2026-08-02 this file subscribed to question_updated alone, so the observer had never once
// run on a teacher's edit. Measured consequence: the stored question id was never re-pointed at the
// new version, the stale validator verdict and the approval both survived the edit, and the approve
// page's Edit link kept opening the pre-edit content - saving that created a new current version
// from stale text, losing the previous edit.
$observers = [
    [
        // Jov-024: recompute FE/FT answer percentages after a teacher edits a draft question
        // in Moodle's native question editor. This is the event that path really fires.
        'eventname' => '\core\event\question_created',
        'callback'  => '\local_artqtml\observer::question_saved',
    ],
    [
        // The two narrow paths that do fire question_updated - kept so a rename from the bank
        // list, or a draft/ready switch, invalidates the verdict the same way an edit does.
        'eventname' => '\core\event\question_updated',
        'callback'  => '\local_artqtml\observer::question_saved',
    ],
];
