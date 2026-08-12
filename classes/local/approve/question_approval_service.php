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
 * Helper.
 *
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\approve;

/**
 * Single-row and bulk "approve all accepted" approval.
 */
class question_approval_service {
    /**
     * Approve a single question (a human step independent of the AI's validationsuggestion - a
     * Question must be approved before it can be moved into a real question bank).
     *
     * @param int $questionid local_artqtml_questions.id
     * @param int $generationid
     * @param int $userid the approving user
     * @param \context $context system context, for the event
     * @return bool true if a not-yet-approved, not-yet-moved row was actually approved
     */
    public static function approve_single(int $questionid, int $generationid, int $userid, \context $context): bool {
        global $DB;

        $row = $DB->get_record('local_artqtml_questions', ['id' => $questionid, 'generationid' => $generationid]);
        if ($row && !$row->movedout && !$row->approved) {
            $transaction = $DB->start_delegated_transaction();
            try {
                $DB->update_record('local_artqtml_questions', (object) [
                    'id'         => $questionid,
                    'approved'   => 1,
                    'approvedby' => $userid,
                ]);

                \local_artqtml\event\question_approved::create([
                    'objectid' => $questionid,
                    'context'  => $context,
                    'other'    => ['questionbankid' => $row->questionbankid],
                ])->trigger();

                $transaction->allow_commit();
            } catch (\Throwable $e) {
                if (!$transaction->is_disposed()) {
                    $transaction->rollback($e);
                }
                throw $e;
            }

            return true;
        }

        return false;
    }

    /**
     * Revoke a single question's approval.
     *
     * Only a currently-approved, not-yet-moved row can be revoked, so a stale link or a replayed
     * Request against an already-moved question is a no-op rather than an error.
     *
     * @param int $questionid local_artqtml_questions.id
     * @param int $generationid
     * @param \context $context system context, for the event
     * @return bool true if an approved, not-yet-moved row was actually revoked
     */
    public static function revoke_single(int $questionid, int $generationid, \context $context): bool {
        global $DB;

        $row = $DB->get_record('local_artqtml_questions', ['id' => $questionid, 'generationid' => $generationid]);
        if (!$row || $row->movedout || !$row->approved) {
            return false;
        }

        // Same atomicity contract as approve_single(): an observer listening on
        // Question_approval_revoked must never see a committed revocation that then rolls back.
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record('local_artqtml_questions', (object) [
                'id'         => $questionid,
                'approved'   => 0,
                // The approver record belongs to the approval that has just been taken back; a
                // Surviving approvedby would keep naming someone who no longer approves this row.
                'approvedby' => null,
            ]);

            \local_artqtml\event\question_approval_revoked::create([
                'objectid' => $questionid,
                'context'  => $context,
                'other'    => ['questionbankid' => $row->questionbankid],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (!$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        }

        return true;
    }

    /**
     * approve accepted bulk.
     *
     * @param int $generationid
     * @param int $userid the approving user
     * @param \context $context system context, for the events
     * @return int number of questions approved
     * @throws \Throwable rethrown on any mid-batch failure (after rollback), for the caller to report
     */
    public static function approve_accepted_bulk(int $generationid, int $userid, \context $context): int {
        global $DB;

        $rows = $DB->get_records('local_artqtml_questions', [
            'generationid'         => $generationid,
            'validationsuggestion' => \local_artqtml\local\validation_suggestion::ACCEPTED,
            'movedout'             => 0,
            'approved'             => 0,
        ]);

        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($rows as $row) {
                $DB->update_record('local_artqtml_questions', (object) [
                    'id'         => $row->id,
                    'approved'   => 1,
                    'approvedby' => $userid,
                ]);

                \local_artqtml\event\question_approved::create([
                    'objectid' => $row->id,
                    'context'  => $context,
                    'other'    => ['questionbankid' => $row->questionbankid],
                ])->trigger();
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // B3: run the whole bulk approval in one transaction so a failure part-way through
            // Never leaves a partially-approved batch. rollback() rethrows $e by contract, so it
            // Propagates to the controller, which turns it into a notification::error.
            //
            // Az is_disposed() őr akkor számít, ha $e magából az allow_commit()-ból jön:
            // Commit_delegated_transaction() még a hiba előtt disposed-ra állítja a tranzakciót,
            // És egy disposed tranzakcióra hívott rollback() dml_transaction_exception-t dob,
            // Elfedve a valódi hibát. Ilyenkor az eredeti $e-t dobjuk tovább változatlanul.
            if (!$transaction->is_disposed()) {
                $transaction->rollback($e);
            }

            throw $e;
        }

        return count($rows);
    }
}
