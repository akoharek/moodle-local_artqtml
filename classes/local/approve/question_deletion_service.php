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
 * Question deletion business logic for the draft approval page -
 * split out of the approve.php controller. Deletes the real Moodle question and
 * the local row, logs question_deleted, and prunes the draft bank when empty; never renders.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\approve;

use local_artqtml\local\draft_bank;
use local_artqtml\local\generation_access_policy;

/**
 * Single-row and bulk deletion of draft questions.
 */
class question_deletion_service {
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
     * Delete a single draft question: its real Moodle question (if not already
     * moved out) and its local row, then prune the draft bank if it is now empty.
     *
     * A question that has already been moved into a real question bank is skipped here,
     * matching the delete_selected() filter below. The
     * approve page renders no Delete control for such a row, so this is the server-side half of the
     * same rule, for a replayed or hand-built URL.
     *
     * @param int $questionid local_artqtml_questions.id
     * @param int $generationid
     * @param \context $context system context, for the event
     * @return bool true if the question was deleted, false if it was absent or already moved out
     */
    public static function delete_single(int $questionid, int $generationid, \context $context): bool {
        global $DB;

        self::require_generation_mutable($generationid, $context);

        $row = $DB->get_record('local_artqtml_questions', ['id' => $questionid, 'generationid' => $generationid]);
        if (!$row || $row->movedout) {
            return false;
        }

        // The real-question delete + local-row delete + draft-bank prune must succeed
        // or fail together, exactly like delete_selected() already does - otherwise a failure
        // between them can orphan a Moodle question or leave a stale draft category.
        $transaction = $DB->start_delegated_transaction();
        try {
            if (!empty($row->questionbankid)) {
                question_delete_question((int) $row->questionbankid);
            }
            $DB->delete_records('local_artqtml_questions', ['id' => $questionid]);

            \local_artqtml\event\question_deleted::create([
                'objectid' => $questionid,
                'context'  => $context,
            ])->trigger();

            $draftcategoryid = (int) $DB->get_field('local_artqtml_generations', 'draftcategoryid', ['id' => $generationid]);
            draft_bank::delete_if_empty($generationid, $draftcategoryid);
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
     * Whether the generation still contains at least one question that has been moved into a real
     * Moodle question bank.
     *
     * When a generation contains at least one moved-out question, the generation cannot be deleted.
     * Used by the list page to render (and by delete.php to enforce) that rule.
     *
     * @param int $generationid
     * @return bool
     */
    public static function has_moved_questions(int $generationid): bool {
        global $DB;

        return $DB->record_exists('local_artqtml_questions', [
            'generationid' => $generationid,
            'movedout'     => 1,
        ]);
    }

    /**
     * Bulk-delete the selected, not-yet-moved questions in one transaction.
     *
     * @param int[] $questionids the selected local_artqtml_questions ids
     * @param int $generationid
     * @param \context $context system context, for the events
     * @return int number of questions deleted
     * @throws \Throwable rethrown on any mid-batch failure (after rollback), for the caller to report
     */
    public static function delete_selected(array $questionids, int $generationid, \context $context): int {
        global $DB;

        self::require_generation_mutable($generationid, $context);

        [$insql, $inparams] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED);
        $rows = $DB->get_records_select(
            'local_artqtml_questions',
            "generationid = :generationid AND id $insql AND movedout = 0",
            array_merge(['generationid' => $generationid], $inparams)
        );

        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($rows as $row) {
                if (!empty($row->questionbankid)) {
                    question_delete_question((int) $row->questionbankid);
                }
                $DB->delete_records('local_artqtml_questions', ['id' => $row->id]);

                \local_artqtml\event\question_deleted::create([
                    'objectid' => $row->id,
                    'context'  => $context,
                ])->trigger();
            }

            $draftcategoryid = (int) $DB->get_field('local_artqtml_generations', 'draftcategoryid', ['id' => $generationid]);
            draft_bank::delete_if_empty($generationid, $draftcategoryid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // B3: run the whole bulk delete in one transaction so a failure part-way through
            // never leaves a partially-deleted batch. rollback() rethrows $e by contract, so it
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
