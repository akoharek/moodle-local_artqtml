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
 * Deletes a generation, its draft question bank and its draft questions - but never its log rows.
 *
 * The single production path for destroying a generation, shared by delete.php (the list page) and
 * generate.php's "delete and exit" abort. Having one path means the diagnostic-log retention rule
 * lives in exactly one place, and a PHPUnit test can pin it against the real deletion code instead
 * of a hand-rolled copy.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Generation deletion, minus the diagnostic log (Glob-040).
 */
class generation_deletion {
    /**
     * Delete a generation, its draft question bank and its draft questions.
     *
     * Glob-040 (V-06): the local_artqtml_log rows are deliberately NOT deleted here - they
     * outlive the generation. The log exists to make an API failure investigable after the fact,
     * and deleting a generation that failed is a teacher's natural reaction; tying the log's
     * lifetime to the generation's would destroy the evidence at exactly the moment it becomes
     * interesting. A log row's generationid may therefore reference a generation that no longer
     * exists. Do NOT add a local_artqtml_log delete here "for consistency" with the two deletes
     * below - that is the exact regression the accompanying test pins against.
     *
     * Callers own the surrounding concerns: delete.php wraps this in a delegated transaction, the
     * moved-question guard (Jov-042/043) and the generation_deleted event; generate.php's
     * abort-delete calls it plainly. No transaction is started here, so it composes inside a
     * caller's.
     *
     * @param int $generationid
     * @return void
     */
    public static function purge(int $generationid): void {
        global $DB;

        $generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid]);
        if (!$generation) {
            return;
        }

        // M-29: the draft category and its real Moodle question objects, or they would be orphaned.
        if (!empty($generation->draftcategoryid)) {
            draft_bank::delete((int) $generation->draftcategoryid);
        }

        $DB->delete_records('local_artqtml_questions', ['generationid' => $generationid]);

        // Glob-040: the log entries stay. What changed on 2026-08-04 is that they no longer stay
        // POINTING AT A ROW THAT IS ABOUT TO VANISH. The id moves to originalgenerationid and the
        // live reference is cleared, in that order and before the generation row goes - so the
        // entries remain findable by the generation they belonged to, while `generationid` stops
        // asserting a relationship that no longer exists.
        //
        // The user id is deliberately NOT cleared here. This is an ordinary deletion, not a data
        // subject request, and the entries have to stay reachable in that user's own GDPR export.
        // Anonymising is what the privacy provider does, on request, and it is a different thing.
        //
        // The diagnostic payload is not redacted here either: it is exactly what somebody
        // investigating a failed generation needs, and deleting the generation is often the last
        // step of that investigation rather than the end of it. It goes when its retention period
        // ends - see diagnostic_log_retention.
        $DB->set_field_select(
            'local_artqtml_log',
            'originalgenerationid',
            $generationid,
            'generationid = :generationid',
            ['generationid' => $generationid]
        );
        $DB->set_field_select(
            'local_artqtml_log',
            'generationid',
            null,
            'generationid = :generationid',
            ['generationid' => $generationid]
        );

        $DB->delete_records('local_artqtml_generations', ['id' => $generationid]);
    }
}
