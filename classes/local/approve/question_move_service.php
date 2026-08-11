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
 * Moves an already-approved draft question into a real question bank (functional spec ch.7,
 * Jov-014) - ArtQTML Light: single-question move only (bulk move removed).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\approve;

use local_artqtml\local\question_mover;

/**
 * Single-question move of an approved question into a chosen target category.
 */
class question_move_service {
    /**
     * Move one already-approved question into the given real bank category.
     *
     * @param int $questionid the local_artqtml_questions id
     * @param int $generationid
     * @param string $categoryvalue "categoryid,contextid" target
     * @param \context $context system context, for the events
     * @return array{moved: int, skipped: int} moved: 0 or 1; skipped: 1 if selected but not approved
     */
    public static function move_single(
        int $questionid,
        int $generationid,
        string $categoryvalue,
        \context $context
    ): array {
        global $DB;

        $row = $DB->get_record_select(
            'local_artqtml_questions',
            'generationid = :generationid AND id = :id AND movedout = 0 AND approved = 1',
            ['generationid' => $generationid, 'id' => $questionid]
        );

        if (!$row) {
            $skipped = $DB->record_exists_select(
                'local_artqtml_questions',
                'generationid = :generationid AND id = :id AND movedout = 0 AND approved = 0',
                ['generationid' => $generationid, 'id' => $questionid]
            );

            return ['moved' => 0, 'skipped' => $skipped ? 1 : 0];
        }

        $moved = self::move_rows([$row], $categoryvalue, $context);

        return ['moved' => $moved, 'skipped' => 0];
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

        // M-22: the Moodle question move and the movedout flag update must succeed or fail
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
