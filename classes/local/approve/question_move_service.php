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
 * Moves already-approved draft questions into a real question bank - split out of the
 * approve.php controller. The Moodle question move and the movedout
 * flag update happen in one transaction; never renders anything.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\approve;

use local_artqtml\local\generation_access_policy;
use local_artqtml\local\question_mover;

/**
 * Bulk move of selected, approved questions into a chosen target category.
 */
class question_move_service {
    /**
     * Ensure the current user may mutate this generation before moving its questions.
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
     * Move the selected, already-approved questions into the given real bank category.
     *
     * Only already-approved rows are moved - a selected-but-not-approved row is silently excluded
     * (same pattern as delete/approve) but counted separately so the caller can tell the
     * user why the moved count differs from their selection.
     *
     * @param int[] $questionids the selected local_artqtml_questions ids
     * @param int $generationid
     * @param string $categoryvalue "categoryid,contextid" target
     * @param \context $context system context, for the events
     * @return array{moved: int, skipped: int} moved: successfully moved; skipped: selected but
     *      not yet approved
     */
    public static function move_selected(
        array $questionids,
        int $generationid,
        string $categoryvalue,
        \context $context
    ): array {
        global $DB;

        self::require_generation_mutable($generationid, $context);

        [$insql, $inparams] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED);
        // Only already-approved questions may be moved - selecting a not-yet-approved row and
        // clicking "move" silently excludes it rather than erroring (same pattern as delete/approve).
        $rows = $DB->get_records_select(
            'local_artqtml_questions',
            "generationid = :generationid AND id $insql AND movedout = 0 AND approved = 1 AND externallyedited = 0",
            array_merge(['generationid' => $generationid], $inparams)
        );

        // Count selected-but-not-approved rows separately so the notification tells the user
        // why their selected count and the moved count differ, instead of silently moving fewer.
        $skippedcount = $DB->count_records_select(
            'local_artqtml_questions',
            "generationid = :generationid AND id $insql AND movedout = 0 AND approved = 0",
            array_merge(['generationid' => $generationid], $inparams)
        );

        $moved = self::move_rows(array_values($rows), $categoryvalue, $context);

        return ['moved' => $moved, 'skipped' => $skippedcount];
    }

    /**
     * Move already-approved questions into a real question bank and log the move event.
     *
     * @param \stdClass[] $rows local_artqtml_questions records (must already be approved)
     * @param string $categoryvalue "categoryid,contextid"
     * @param \context $context system context, for the events
     * @return int number of questions successfully moved
     */
    protected static function move_rows(array $rows, string $categoryvalue, \context $context): int {
        global $DB;

        if (empty($rows)) {
            return 0;
        }

        $questionids = array_map(static function ($row) {
            return (int) $row->questionbankid;
        }, $rows);

        // The Moodle question move and the movedout flag update must succeed or fail
        // together - without a transaction, a mid-batch failure could leave questions physically
        // moved into the real bank while local_artqtml_questions still says movedout=0.
        $transaction = $DB->start_delegated_transaction();

        question_mover::move($questionids, $categoryvalue);

        foreach ($rows as $row) {
            $DB->set_field('local_artqtml_questions', 'movedout', 1, ['id' => $row->id]);

            \local_artqtml\event\question_moved::create([
                'objectid' => $row->id,
                'context'  => $context,
                'other'    => ['questionbankid' => $row->questionbankid],
            ])->trigger();
        }

        $transaction->allow_commit();

        return count($rows);
    }
}
