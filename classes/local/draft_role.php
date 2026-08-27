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
 * plugin approve page; preview uses Moodle's native preview UI and needs question:useall.
 *
 * What the role carries, read off Moodle's entry points:
 *
 * - moodle/course:view — preview and require_login($courseid) succeed through is_viewing().
 * - moodle/question:useall — qbank_previewquestion preview links on approve.php.
 *
 * Two capabilities, and deliberately no archetype. Assignments are granted while a user has draft
 * work in progress and revoked once they no longer need preview access in the shared draft course.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Creates and hands out the draft preview role.
 */
class draft_role {
    /** @var string Role shortname. Stable: the upgrade step and every assignment look it up by this. */
    public const SHORTNAME = 'artqtmldraftediting';

    /** @var string[] The capabilities the role grants, and nothing else. */
    public const CAPABILITIES = [
        'moodle/course:view',
        'moodle/question:useall',
    ];

    /**
     * Create the role if it does not exist yet, and make sure it grants exactly CAPABILITIES.
     *
     * Idempotent by design: it runs from the install step, from the upgrade step, and as a safety
     * net before an assignment, so it must be safe to call when the role is already there. It only
     * ever adds the capabilities it owns - an administrator who has deliberately added another one
     * keeps it, because silently reverting a site's own decision is worse than a broad role.
     * editall is explicitly removed when it is no longer part of CAPABILITIES.
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

        unassign_capability('moodle/question:editall', $roleid, $systemcontext->id);

        return $roleid;
    }

    /**
     * Whether the user still needs preview access in the shared draft course.
     *
     * True while they own an in-flight generation with a draft bank, or while they still have at
     * least one unmoved draft question awaiting review on one of their generations.
     *
     * @param int $userid
     * @return bool
     */
    public static function user_needs_draft_access(int $userid): bool {
        global $DB;

        if ($userid <= 0) {
            return false;
        }

        $inflight = $DB->record_exists_select(
            'local_artqtml_generations',
            'userid = :userid
                AND draftcategoryid IS NOT NULL AND draftcategoryid > 0
                AND status IN (:generating, :validating, :saving)',
            [
                'userid' => $userid,
                'generating' => generation_status::GENERATING,
                'validating' => generation_status::VALIDATING,
                'saving' => generation_status::SAVING,
            ]
        );
        if ($inflight) {
            return true;
        }

        $sql = "SELECT 1
                  FROM {local_artqtml_generations} g
                  JOIN {local_artqtml_questions} q ON q.generationid = g.id
                 WHERE g.userid = :userid
                   AND g.draftcategoryid IS NOT NULL AND g.draftcategoryid > 0
                   AND q.movedout = 0";

        return $DB->record_exists_sql($sql, ['userid' => $userid]);
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

    /**
     * Remove the draft preview role assignment for a user in the draft course.
     *
     * @param int $userid
     * @return bool true when an assignment was removed
     */
    public static function revoke(int $userid): bool {
        global $DB;

        $coursecontext = draft_bank::get_draft_course_context();
        if ($coursecontext === null) {
            return false;
        }

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => self::SHORTNAME]);
        if (!$roleid) {
            return false;
        }

        return (bool) role_unassign($roleid, $userid, $coursecontext->id);
    }

    /**
     * Drop the draft preview role when the user no longer has draft work that needs it.
     *
     * @param int $userid
     * @return void
     */
    public static function revoke_if_idle(int $userid): void {
        if (self::user_needs_draft_access($userid)) {
            return;
        }

        self::revoke($userid);
    }
}
