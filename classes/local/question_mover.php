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
 * Moves already-created draft questions into a real Moodle question bank category.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Moves one or more question ids into a target question_categories row.
 */
class question_mover {
    /**
     * Move the given real Moodle question ids into a target category.
     *
     * @param int[] $questionids question.id values (from local_artqtml_questions.questionbankid)
     * @param string $categoryvalue "categoryid,contextid" as produced by a question bank category dropdown
     * @return void
     */
    public static function move(array $questionids, string $categoryvalue): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/question/engine/bank.php');
        require_once($CFG->libdir . '/questionlib.php');

        if (empty($questionids)) {
            return;
        }

        if (!preg_match('/^(\d+),(\d+)$/', $categoryvalue, $matches)) {
            throw new \moodle_exception('errornocategory', 'local_artqtml');
        }

        $categoryid = (int) $matches[1];
        if (!$DB->record_exists('question_categories', ['id' => $categoryid])) {
            throw new \moodle_exception('errornocategory', 'local_artqtml');
        }

        question_move_questions_to_category($questionids, $categoryid);
    }
}
