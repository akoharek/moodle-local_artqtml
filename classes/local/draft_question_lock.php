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
 * Lock state for draft questions edited outside the plugin workflow.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Whether a draft question row is locked after an external Moodle edit.
 */
class draft_question_lock {
    /**
     * True when the question was changed via core question.php while still in the draft bank.
     *
     * @param \stdClass $row a local_artqtml_questions record
     * @return bool
     */
    public static function is_locked(\stdClass $row): bool {
        return !empty($row->externallyedited);
    }

    /**
     * Throw when the row is locked and the requested mutation must be refused.
     *
     * @param \stdClass $row a local_artqtml_questions record
     * @return void
     * @throws \moodle_exception when the row is locked
     */
    public static function require_unlocked(\stdClass $row): void {
        if (self::is_locked($row)) {
            throw new \moodle_exception('errorquestionlocked', 'local_artqtml');
        }
    }
}
