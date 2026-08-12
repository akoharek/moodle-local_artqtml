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
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Builds a "categoryid,contextid" => label option list for a category-select dropdown.
 */
class question_bank_list {
    /**
     * Build the list of target categories the given user can move draft questions into.
     *
     * @param int $userid
     * @param int $excludecategoryid a draft category id to exclude (never a valid target)
     * @return array<string,string> "categoryid,contextid" => display label
     */
    public static function options_for_user(int $userid, int $excludecategoryid = 0): array {
        $options = [];

        if (draft_bank::uses_module_question_banks()) {
            // Moodle 5.1+: categories live in mod_qbank (and other shareable bank) module contexts.
            $notincourseids = [];
            if (draft_bank::is_configured()) {
                $draftcourseid = draft_bank::get_draft_courseid();
                if ($draftcourseid !== null) {
                    $notincourseids[] = $draftcourseid;
                }
            }

            $banks = call_user_func(
                ['core_question\\local\\bank\\question_bank_helper', 'get_activity_instances_with_shareable_questions'],
                [],
                $notincourseids
            );
            foreach ($banks as $bank) {
                $modcontext = \context_module::instance($bank->cminfo->id);
                if (!has_capability('moodle/question:add', $modcontext, $userid)) {
                    continue;
                }
                $grouplabel = format_string($bank->cminfo->get_course()->fullname);
                self::append_categories($options, $modcontext, $excludecategoryid, $grouplabel);
            }

            return $options;
        }

        $systemcontext = \context_system::instance();
        if (has_capability('moodle/question:add', $systemcontext, $userid)) {
            self::append_categories(
                $options,
                $systemcontext,
                $excludecategoryid,
                \context_helper::get_level_name(CONTEXT_SYSTEM)
            );
        }

        // Core's enrol_get_users_courses() only returns courses the user is enrolled in, which misses
        // Courses reachable via a role assigned at a higher context (category/system) - e.g. a
        // Manager/admin account with no per-course enrolment. get_user_capability_course()
        // Checks the capability itself at every context level, however it was granted.
        $courses = get_user_capability_course('moodle/question:add', $userid, true, 'id,fullname') ?: [];
        foreach ($courses as $course) {
            $coursecontext = \context_course::instance($course->id);
            self::append_categories($options, $coursecontext, $excludecategoryid, format_string($course->fullname));
        }

        return $options;
    }

    /**
     * Append a context's question categories to the option list, grouped by course/site name.
     *
     * @param array $options by reference
     * @param \context $context
     * @param int $excludecategoryid
     * @param string $grouplabel
     * @return void
     */
    protected static function append_categories(
        array &$options,
        \context $context,
        int $excludecategoryid,
        string $grouplabel
    ): void {
        global $DB;

        if (draft_bank::is_configured()) {
            $draftcourseid = draft_bank::get_draft_courseid();
            if (
                $draftcourseid !== null
                && (int) $context->contextlevel === CONTEXT_COURSE
                && (int) $context->instanceid === (int) $draftcourseid
            ) {
                return;
            }
            if ($context->id === draft_bank::get_draft_context_id()) {
                return;
            }
        }

        $categories = $DB->get_records('question_categories', ['contextid' => $context->id], 'sortorder, name');
        foreach ($categories as $category) {
            if ((int) $category->id === $excludecategoryid) {
                continue;
            }
            if ((int) $category->parent === 0) {
                continue;
            }
            $key = $category->id . ',' . $category->contextid;
            $options[$key] = $grouplabel . ' / ' . format_string($category->name);
        }
    }
}
