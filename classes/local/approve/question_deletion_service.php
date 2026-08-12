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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\approve;

use local_artqtml\local\draft_bank;

/**
 * Single-row and bulk deletion of draft questions.
 */
class question_deletion_service {
    /**
     * delete single.
     *
     * @param int $questionid local_artqtml_questions.id
     * @param int $generationid
     * @param \context $context system context, for the event
     * @return bool true if the question was deleted, false if it was absent or already moved out
     */
    public static function delete_single(int $questionid, int $generationid, \context $context): bool {
        global $DB;

        $row = $DB->get_record('local_artqtml_questions', ['id' => $questionid, 'generationid' => $generationid]);
        if (!$row || $row->movedout) {
            return false;
        }

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
     * Whether the generation still contains at least one question that has been moved into a real Moodle question bank.
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
            // Az is_disposed() őr akkor számít, ha $e magából az allow_commit()-ból jön:
            // commit_delegated_transaction() még a hiba előtt disposed-ra állítja a tranzakciót,
            // és egy disposed tranzakcióra hívott rollback() dml_transaction_exception-t dob,
            // elfedve a valódi hibát. Ilyenkor az eredeti $e-t dobjuk tovább változatlanul.
            if (!$transaction->is_disposed()) {
                $transaction->rollback($e);
            }

            throw $e;
        }

        return count($rows);
    }
}
