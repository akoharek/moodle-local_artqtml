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
 * Question approval business logic for the draft approval page -
 * split out of the approve.php controller. Sets only the approved/approvedby fields and logs the
 * question_approved event; never renders anything.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\approve;

use local_artqtml\local\generation_access_policy;

/**
 * Single-row and bulk "approve all accepted" approval.
 */
class question_approval_service {
    /**
     * Ensure the current user may mutate this generation before changing its questions.
     *
     * @param int $generationid
     * @param \context $context
     * @return void
     */
    private static function require_generation_mutable(int $generationid, \context $context): void {
        global $DB;

        $generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid], 'id, userid', MUST_EXIST);
        generation_access_policy::require_can_mutate($generation, $context);
    }

    /**
     * Approve a single question (a human step independent of the AI's validationsuggestion - a
     * question must be approved before it can be moved into a real question bank).
     *
     * @param int $questionid local_artqtml_questions.id
     * @param int $generationid
     * @param int $userid the approving user
     * @param \context $context system context, for the event
     * @return bool true if a not-yet-approved, not-yet-moved row was actually approved
     */
    public static function approve_single(int $questionid, int $generationid, int $userid, \context $context): bool {
        global $DB;

        self::require_generation_mutable($generationid, $context);

        $row = $DB->get_record('local_artqtml_questions', ['id' => $questionid, 'generationid' => $generationid]);
        if ($row && !$row->movedout && !$row->approved && empty($row->externallyedited)) {
            // Keep the row update and the event emission atomic, matching the bulk path -
            // an observer listening on question_approved must never see a committed approval that
            // then rolls back (or vice versa).
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
     * Approval may be revoked only while a question is in the approved state. Revocation
     * clears the approved flag to 0; it does not change the validation status. So this touches
     * approved/approvedby and nothing else - validationsuggestion, problemcategory, justification,
     * confidence, validationdata and the edited flag are all left exactly as they were. It is
     * deliberately not the inverse of an edit (which does clear the validation - see
     * classes/observer.php): revoking says "I take my approval back", not "the content changed".
     *
     * Only a currently-approved, not-yet-moved row can be revoked, so a stale link or a replayed
     * request against an already-moved question is a no-op rather than an error.
     *
     * @param int $questionid local_artqtml_questions.id
     * @param int $generationid
     * @param \context $context system context, for the event
     * @return bool true if an approved, not-yet-moved row was actually revoked
     */
    public static function revoke_single(int $questionid, int $generationid, \context $context): bool {
        global $DB;

        self::require_generation_mutable($generationid, $context);

        $row = $DB->get_record('local_artqtml_questions', ['id' => $questionid, 'generationid' => $generationid]);
        if (!$row || $row->movedout || !$row->approved || !empty($row->externallyedited)) {
            return false;
        }

        // Same atomicity contract as approve_single(): an observer listening on
        // question_approval_revoked must never see a committed revocation that then rolls back.
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record('local_artqtml_questions', (object) [
                'id'         => $questionid,
                'approved'   => 0,
                // The approver record belongs to the approval that has just been taken back; a
                // surviving approvedby would keep naming someone who no longer approves this row.
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
     * Bulk-approve every "accepted", not-yet-approved, not-yet-moved question in one transaction.
     * Approval only - moving to a real bank is a separate step, so no target bank here.
     *
     * @param int $generationid
     * @param int $userid the approving user
     * @param \context $context system context, for the events
     * @return int number of questions approved
     * @throws \Throwable rethrown on any mid-batch failure (after rollback), for the caller to report
     */
    public static function approve_accepted_bulk(int $generationid, int $userid, \context $context): int {
        global $DB;

        self::require_generation_mutable($generationid, $context);

        $rows = $DB->get_records('local_artqtml_questions', [
            'generationid'         => $generationid,
            'validationsuggestion' => \local_artqtml\local\validation_suggestion::ACCEPTED,
            'movedout'             => 0,
            'approved'             => 0,
            'externallyedited'     => 0,
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
            // never leaves a partially-approved batch. rollback() rethrows $e by contract, so it
            // propagates to the controller, which turns it into a notification::error.
            //
            // The is_disposed() guard matters when $e comes from allow_commit() itself:
            // commit_delegated_transaction() marks the transaction disposed before the error,
            // and calling rollback() on a disposed transaction throws dml_transaction_exception,
            // masking the real error. In that case rethrow the original $e unchanged.
            if (!$transaction->is_disposed()) {
                $transaction->rollback($e);
            }

            throw $e;
        }

        return count($rows);
    }
}
