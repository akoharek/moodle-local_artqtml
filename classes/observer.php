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
 * Moodle event observers for local_artqtml (db/events.php).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml;

use local_artqtml\local\question_importer;

/**
 * Reacts to core question bank events for questions this plugin created.
 */
class observer {
    /**
     * @param \core\event\question_created|\core\event\question_updated $event
     * @return void
     */
    public static function question_saved(\core\event\base $event): void {
        global $DB;

        $questionid = (int) $event->objectid;

        // Moodle's question versioning creates a NEW question.id every time a question is
        // saved, so a stored questionbankid captured at generation/previous-edit time goes
        // stale after this save. question_bank_entries.id is the one identifier that stays
        // constant across versions, so match through that instead of a direct id lookup -
        // otherwise this observer (and the validation panel's own lookup) silently stop
        // finding the row after the question's first edit.
        $entryid = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $questionid]);
        if (!$entryid) {
            return;
        }

        $row = $DB->get_record_sql(
            "SELECT q.id, q.generationid, q.typecode, q.movedout, q.questionbankid
               FROM {local_artqtml_questions} q
               JOIN {question_versions} v ON v.questionid = q.questionbankid
              WHERE v.questionbankentryid = :entryid",
            ['entryid' => $entryid],
            IGNORE_MISSING
        );

        if (!$row || $row->movedout) {
            // Not one of ours, or already moved into a real bank - no longer part of the
            // approval workflow, so its stored validation/approval state is left untouched.
            return;
        }

        if ((int) $row->questionbankid === $questionid) {
            return;
        }

        $DB->update_record('local_artqtml_questions', (object) [
            'id'                  => $row->id,
            'questionbankid'       => $questionid, // Re-point at this save's new current version.
            'validationsuggestion' => 'not_evaluated',
            'problemcategory'      => null,
            'justification'        => null,
            'confidence'           => null,
            'validationdata'       => null,
            'approved'             => 0,
            // An edit invalidates any prior approval, so the record of who approved it must be
            // cleared along with the flag itself - otherwise a stale approvedby would keep
            // pointing at someone who approved a now-superseded version of the question.
            'approvedby'           => null,
            'edited'               => 1,
            // who, and (for the list page's "Modified by" column) when.
            'lasteditedby'         => $event->userid,
            'lasteditedat'         => time(),
        ]);

        $logrecord = new \stdClass();
        $logrecord->generationid = $row->generationid;
        $logrecord->userid = $event->userid;
        $logrecord->event = 'question_edited';
        $logrecord->data = json_encode(['questionid' => $row->id]);
        $logrecord->timecreated = time();
        $DB->insert_record('local_artqtml_log', $logrecord);

        \local_artqtml\event\question_edited::create([
            'objectid' => $row->id,
            'context'  => \context_system::instance(),
        ])->trigger();

        if ($row->typecode === 'FE') {
            question_importer::recompute_multichoice_fractions($questionid);
        }
    }
}
