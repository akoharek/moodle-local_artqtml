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
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\question;

defined('MOODLE_INTERNAL') || die();

/**
 * Renormalises qtype_multichoice answer fractions after a teacher edits the question.
 */
class multichoice_fraction_normalizer {
    /**
     * Moodle's own multichoice edit form leaves picking each answer's percentage entirely to
     * The teacher; it does not renormalise automatically. This mirrors apply_multichoice()'s
     * Generation-time logic against the already-saved question_answers rows instead: any answer
     * The teacher left with a positive fraction is treated as "correct" and given an even share
     * Of 100% (single correct = 100%, multiple correct split evenly); everything else is zeroed.
     *
     * @param int $questionid the real question.id (local_artqtml_questions.questionbankid)
     * @return void
     */
    public static function recompute(int $questionid): void {
        global $DB;

        $options = $DB->get_record('qtype_multichoice_options', ['questionid' => $questionid]);
        if (!$options) {
            return;
        }

        $answers = $DB->get_records('question_answers', ['question' => $questionid], 'id ASC');
        if (empty($answers)) {
            return;
        }

        $correctids = [];
        foreach ($answers as $answer) {
            if ((float) $answer->fraction > 0) {
                $correctids[] = $answer->id;
            }
        }

        if (empty($correctids)) {
            // Nothing marked correct - leave as-is, there is nothing sane to normalise to.
            return;
        }

        if (!empty($options->single) && count($correctids) > 1) {
            // FE: exactly one correct answer allowed - keep only the highest-fraction one.
            $best = null;
            foreach ($answers as $answer) {
                if (in_array($answer->id, $correctids, true) && ($best === null || $answer->fraction > $best->fraction)) {
                    $best = $answer;
                }
            }
            $correctids = [$best->id];
        }

        $share = round(1.0 / count($correctids), 7);
        foreach ($answers as $answer) {
            $newfraction = in_array($answer->id, $correctids, true) ? $share : 0.0;
            if ((float) $answer->fraction !== $newfraction) {
                $DB->set_field('question_answers', 'fraction', $newfraction, ['id' => $answer->id]);
            }
        }
    }
}
