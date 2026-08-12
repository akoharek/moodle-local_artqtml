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
     * When a teacher saves edits to a still-in-draft AI question in the native question editor:
     * the stale Gemini result no longer describes the current content, so it is cleared and
     * flagged "edited", any prior approval is revoked (a question must be (re-)approved before
     * it can be moved), the edit is logged, and - for FE only - answer percentages are
     * recomputed (Jov-024).
     *
     * BL-28: called for BOTH core events, because a versioned save is a *creation*. See
     * db/events.php for which core path fires which, and why subscribing to question_updated
     * alone meant this method had never run on a teacher's edit.
     *
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
            // BL-28: the plugin's own generation-time creation, not a teacher's edit.
            //
            // save_questions_task creates the Moodle question first and inserts this row after,
            // so at the moment question_created fires there is nothing here to find - but the
            // whole save runs inside one transaction, and Moodle buffers external observers until
            // the transaction commits (lib/classes/event/manager.php:110-146). By the time this
            // runs, the row exists and would look exactly like an edit.
            //
            // The discriminator is the stored id itself: on our own creation it IS this question,
            // because save_questions_task wrote the id it had just created. A teacher's save
            // always produces a new id, so the two differ. Nothing about timing is relied on.
            return;
        }

        // M-20/Jov-026: reset to the real not_evaluated state (not a synthetic 'edited'
        // suggestion value the validator's own "not yet evaluated" query never recognised) and
        // track the "edited since last validation" fact via its own flag instead - the UI's
        // "Edited" badge is now driven by that flag, not by validationsuggestion.
        $DB->update_record('local_artqtml_questions', (object) [
            'id'                  => $row->id,
            'questionbankid'       => $questionid, // Re-point at this save's new current version.
            'validationsuggestion' => 'not_evaluated',
            'problemcategory'      => null,
            'justification'        => null,
            'confidence'           => null,
            // Cursor audit v3 #3: the raw Gemini evaluation object must not outlive the content
            // it evaluated - leaving it here would let a stale hint_quality/feedback_quality
            // verdict about the PREVIOUS question text keep displaying against the edited one.
            'validationdata'       => null,
            'approved'             => 0,
            // An edit invalidates any prior approval, so the record of who approved it must be
            // cleared along with the flag itself - otherwise a stale approvedby would keep
            // pointing at someone who approved a now-superseded version of the question.
            'approvedby'           => null,
            'edited'               => 1,
            // M-30/Glob-032: who, and (for the list page's "Modified by" column) when.
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
