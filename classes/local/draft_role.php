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
 * Narrow role for previewing draft questions in the hidden draft course.
 *
 * The draft course is a holding area only: questions must not be edited through Moodle's native
 * question bank while they remain in the draft category. Review, approve and move happen on the
 * plugin approve page; preview uses Moodle's native preview UI and needs question:use.
 *
 * What the role carries, read off Moodle's entry points:
 *
 * - moodle/course:view — preview and require_login($courseid) succeed through is_viewing().
 * - moodle/question:useall — qbank_previewquestion preview links on approve.php.
 * - moodle/question:editall — native question editor links on approve.php; plugin approve/move
 *   paths are blocked once externallyedited is set.
 *
 * Three capabilities, and deliberately no archetype.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates and hands out the draft preview role.
 */
class draft_role {
    /** @var string Role shortname. Stable: the upgrade step and every assignment look it up by this. */
    public const SHORTNAME = 'artqtmldraftediting';

    /** @var string[] The capabilities the role grants, and nothing else. */
    public const CAPABILITIES = [
        'moodle/course:view',
        'moodle/question:editall',
        'moodle/question:useall',
    ];

    /**
     * Create the role if it does not exist yet, and make sure it grants exactly CAPABILITIES.
     *
     * Idempotent by design: it runs from the install step, from the upgrade step, and as a safety
     * net before an assignment, so it must be safe to call when the role is already there. It only
     * ever adds the capabilities it owns - an administrator who has deliberately added another one
     * keeps it, because silently reverting a site's own decision is worse than a broad role.
     *
     * @return int the role id
     */
    public static function ensure_role(): int {
        global $DB;

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => self::SHORTNAME]);

        if (!$roleid) {
            $roleid = create_role(
                get_string('draftrolename', 'local_artqtml'),
                self::SHORTNAME,
                get_string('draftroledescription', 'local_artqtml')
            );
        }

        set_role_contextlevels($roleid, [CONTEXT_COURSE]);

        $systemcontext = \context_system::instance();
        foreach (self::CAPABILITIES as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext->id, true);
        }

        return $roleid;
    }

    /**
     * Give a user the role in the draft course so Preview links on approve.php work.
     *
     * @param int $userid
     * @return bool true when the user holds the role afterwards, false when there was nothing to
     *      assign against
     */
    public static function grant(int $userid): bool {
        global $DB;

        $coursecontext = draft_bank::get_draft_course_context();
        if ($coursecontext === null) {
            return false;
        }

        $roleid = self::ensure_role();

        $exists = $DB->record_exists('role_assignments', [
            'roleid'    => $roleid,
            'userid'    => $userid,
            'contextid' => $coursecontext->id,
        ]);

        if (!$exists) {
            role_assign($roleid, $userid, $coursecontext->id);
        }

        return true;
    }
}
