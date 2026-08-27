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
 * Generate.php's "delete and exit" abort. Having one path means the diagnostic-log retention rule
 * Lives in exactly one place, and a PHPUnit test can pin it against the real deletion code instead
 * Of a hand-rolled copy.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Generation deletion, minus the diagnostic log.
 */
class generation_deletion {
    /**
     * Delete a generation, its draft question bank and its draft questions.
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

        // The draft category and its real Moodle question objects, or they would be orphaned.
        if (!empty($generation->draftcategoryid)) {
            draft_bank::delete((int) $generation->draftcategoryid);
        }

        $DB->delete_records('local_artqtml_questions', ['generationid' => $generationid]);

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
