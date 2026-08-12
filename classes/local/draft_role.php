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
 * Jov-036: the narrow role that lets a generator edit their draft questions in Moodle's native
 * question editor, without being enrolled in the draft course as an editingteacher.
 *
 * Why a role of our own rather than enrolment. The draft course is deliberately one nobody is
 * enrolled in: that is what keeps unreviewed AI content out of ordinary question bank browsing
 * (Jov-023, see draft_bank). Enrolling every generator as an editingteacher would hand them the
 * whole teacher capability set in that course - grading, participants, course editing - to solve a
 * question-editing problem, and would list them as course participants.
 *
 * What the role has to carry, read off Moodle's own entry points rather than guessed:
 *
 * - moodle/course:view - the question edit and preview pages both call require_login($courseid).
 *   For a user with no enrolment that succeeds only through is_viewing(), which checks exactly
 *   this capability on the course context (lib/accesslib.php::is_viewing).
 * - moodle/question:editall - question/bank/editquestion/question.php calls
 *   question_require_capability_on($question, 'edit'), which question_has_capability_on()
 *   (lib/questionlib.php) resolves to editall OR (createdby == $USER->id AND editmine).
 * - moodle/question:useall - the same shape for the Preview link, which asks for 'use'.
 *
 * **all, not mine — and that is the requirement, not a shortcut.** Glob-031: local/artqtml is a
 * site-wide tool, not a per-owner-locked one. Any user holding local/artqtml:use may open and
 * act on any generation, and editing its questions is one of those acts. The "mine" pair was
 * written here first and was wrong: it would have let a reviewer approve a colleague's question
 * but not correct it, which is the workflow this plugin exists to support. What keeps the breadth
 * honest is not a narrower capability but a prompt - approve_renderer asks for confirmation, by
 * name, before the Edit action opens someone else's question.
 *
 * Note what is deliberately absent: no view capability of any kind. The two entry points above
 * check 'edit' and 'use' directly and never a 'view', so the links work without it, while the
 * course's question bank listing stays empty for this role. That is what keeps Jov-036's
 * "hozzáférés kizárólag a plugin approve.php oldalán keresztül" true in practice - the drafts are
 * reachable from the approval page, not by browsing the draft course.
 *
 * Three capabilities, and deliberately no archetype: an archetype would seed the role with a
 * built-in role's defaults, which is the breadth this class exists to avoid.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Creates and hands out the draft-editing role.
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

        // The role is only ever assigned on a course context, so that is the only level it may be
        // assigned at. Without this the role does not appear as assignable anywhere, and an admin
        // inspecting it sees a role that cannot be used.
        set_role_contextlevels($roleid, [CONTEXT_COURSE]);

        $systemcontext = \context_system::instance();
        foreach (self::CAPABILITIES as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext->id, true);
        }

        return $roleid;
    }

    /**
     * Give a user the role in the draft course, so their Edit and Preview links work.
     *
     * Called when a generation starts. Assigning a role a user already holds in that context is a
     * no-op in Moodle, but the check is explicit here so the intent is readable and the common
     * case (every generation after the first) costs one indexed read.
     *
     * Does nothing when no draft course is configured: generation is blocked in that state anyway,
     * and inventing a context to assign against would be worse than leaving it.
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
