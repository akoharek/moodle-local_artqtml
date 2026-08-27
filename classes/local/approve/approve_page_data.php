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
 * Read-only data gathering for the draft approval page - split out of the
 * Approve.php controller. Counters, the paginated/sorted question query and the category
 * Resolution; no HTML, no mutation.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\approve;

use local_artqtml\local\draft_bank;

/**
 * Supplies the approve page's display data.
 */
class approve_page_data {
    /**
     * sortable columns.
     *
     * @return array<string,string>
     */
    public static function sortable_columns(): array {
        return [
            'name'         => 'q.questioncode',
            'type'         => 'q.typecode',
            'difficulty'   => 'q.difficultylabel',
            'validation'   => 'q.validationsuggestion',
            'timecreated'  => 'q.timecreated',
            'creator'      => 'creatorname',
            'lasteditedby' => 'lasteditorname',
        ];
    }

    /**
     * Total number of questions in the generation (all statuses).
     *
     * @param int $generationid
     * @return int
     */
    public static function total_questions(int $generationid): int {
        global $DB;

        return $DB->count_records('local_artqtml_questions', ['generationid' => $generationid]);
    }

    /**
     * status counts.
     *
     * @param int $generationid
     * @return array<string,int>
     */
    public static function status_counts(int $generationid): array {
        global $DB;

        $statuscounts = array_fill_keys(\local_artqtml\local\validation_suggestion::DISPLAY, 0);
        $statusrows = $DB->get_records_sql(
            'SELECT validationsuggestion, COUNT(*) AS total
               FROM {local_artqtml_questions}
              WHERE generationid = :generationid
           GROUP BY validationsuggestion',
            ['generationid' => $generationid]
        );
        foreach ($statusrows as $statusrow) {
            if (isset($statuscounts[$statusrow->validationsuggestion])) {
                $statuscounts[$statusrow->validationsuggestion] = (int) $statusrow->total;
            }
        }

        return $statuscounts;
    }

    /**
     * eligible for approval.
     *
     * @param int $generationid
     * @return int
     */
    public static function eligible_for_approval(int $generationid): int {
        global $DB;

        return $DB->count_records('local_artqtml_questions', [
            'generationid'         => $generationid,
            'validationsuggestion' => \local_artqtml\local\validation_suggestion::ACCEPTED,
            'movedout'             => 0,
            'approved'             => 0,
            'externallyedited'     => 0,
        ]);
    }

    /**
     * The paginated, sorted question rows for one page of the table.
     *
     * @param int $generationid
     * @param string $sort a sortable_columns() key (unknown values fall back to q.id)
     * @param string $dir 'ASC' or 'DESC' (already sanitised by the caller)
     * @param int $page zero-based page number (already clamped by the caller)
     * @param int $perpage
     * @return \stdClass[]
     */
    public static function questions(int $generationid, string $sort, string $dir, int $page, int $perpage): array {
        global $DB;

        $sortable = self::sortable_columns();
        $orderby = ($sortable[$sort] ?? 'q.id') . ' ' . $dir . ', q.id ASC';

        $editornamesort = 'LOWER(' . $DB->sql_concat("COALESCE(ue.lastname, '')", "COALESCE(ue.firstname, '')") . ')';
        $creatornamesort = 'LOWER(' . $DB->sql_concat('uc.lastname', 'uc.firstname') . ')';

        return $DB->get_records_sql(
            "SELECT q.*, $editornamesort AS lasteditorname, $creatornamesort AS creatorname
               FROM {local_artqtml_questions} q
               LEFT JOIN {user} ue ON ue.id = q.lasteditedby
               JOIN {local_artqtml_generations} g ON g.id = q.generationid
               JOIN {user} uc ON uc.id = g.userid
              WHERE q.generationid = :generationid
           ORDER BY $orderby",
            ['generationid' => $generationid],
            $page * $perpage,
            $perpage
        );
    }

    /**
     * Resolve "categoryid,contextid" for a real Moodle question.id.
     *
     * Moodle's own native question bank always includes a "category" GET param on its edit links;
     * Without it, edit_question_form.php is built with no hidden "category" field at all, and
     * Validation() unconditionally reads $fromform['category'] on save, throwing a PHP notice/
     * Warning in core. The category can't be assumed to be this generation's own draft category
     * (system context) either, since an already-moved question now lives in whatever real course
     * Category the teacher picked - so this is resolved fresh from the question's current version.
     *
     * @param int $questionid
     * @return string|null null if the question/category can't be resolved
     */
    public static function question_category_value(int $questionid): ?string {
        $category = self::question_category_record($questionid);

        return $category ? ($category->id . ',' . $category->contextid) : null;
    }

    /**
     * Question-bank listing URL for the category the question currently lives in.
     *
     * After a move, Open goes to this destination bank page, not the question editor.
     * Moodle 4.5 uses courseid; Moodle 5.1+ module banks use cmid.
     *
     * @param int $questionbankid question.id
     * @return \moodle_url|null null if the category/context cannot be resolved
     */
    public static function question_bank_url(int $questionbankid): ?\moodle_url {
        $category = self::question_category_record($questionbankid);
        if (!$category) {
            return null;
        }
        $context = \context::instance_by_id((int) $category->contextid, IGNORE_MISSING);
        if (!$context) {
            return null;
        }

        $params = [
            'cat' => $category->id . ',' . $category->contextid,
        ];
        switch ((int) $context->contextlevel) {
            case CONTEXT_MODULE:
                $params['cmid'] = (int) $context->instanceid;
                break;
            case CONTEXT_COURSE:
                $params['courseid'] = (int) $context->instanceid;
                break;
            case CONTEXT_SYSTEM:
                $params['courseid'] = SITEID;
                break;
            default:
                return null;
        }

        return new \moodle_url('/question/edit.php', $params);
    }

    /**
     * Build URL params for Moodle's native question editor from an approve-row question.
     *
     * Moodle 4.5 accepts courseid; Moodle 5.1+ requires cmid (module question banks).
     *
     * @param int $questionbankid question.id
     * @param \moodle_url $returnurl approve page return URL
     * @return array<string,int|string>
     */
    public static function question_edit_url_params(int $questionbankid, \moodle_url $returnurl): array {
        $params = [
            'id' => $questionbankid,
            'returnurl' => $returnurl->out_as_local_url(false),
        ];
        $categoryvalue = self::question_category_value($questionbankid);
        if ($categoryvalue !== null) {
            $params['category'] = $categoryvalue;
        }

        if (draft_bank::uses_module_question_banks()) {
            $cmid = self::question_edit_cmid($questionbankid);
            if ($cmid !== null) {
                $params['cmid'] = $cmid;
            }
        } else {
            // Draft questions live in the draft course context on 4.5.
            $params['courseid'] = draft_bank::get_draft_courseid() ?? SITEID;
        }

        return $params;
    }

    /**
     * Resolve the mod_qbank (or other bank activity) cmid for editing a question on Moodle 5.1+.
     *
     * Prefers the question's current category module context (works after move); falls back to
     * The configured draft course's system-type qbank.
     *
     * @param int $questionid
     * @return int|null
     */
    public static function question_edit_cmid(int $questionid): ?int {
        $category = self::question_category_record($questionid);
        if ($category) {
            $context = \context::instance_by_id((int) $category->contextid, IGNORE_MISSING);
            if ($context && (int) $context->contextlevel === CONTEXT_MODULE) {
                return (int) $context->instanceid;
            }
        }

        $draftcontextid = draft_bank::get_draft_context_id();
        if ($draftcontextid === null) {
            return null;
        }
        $draftcontext = \context::instance_by_id($draftcontextid, IGNORE_MISSING);
        if ($draftcontext && (int) $draftcontext->contextlevel === CONTEXT_MODULE) {
            return (int) $draftcontext->instanceid;
        }

        return null;
    }

    /**
     * Return the question category id and contextid for a question.
     *
     * @param int $questionid
     * @return \stdClass|null {id, contextid}
     */
    protected static function question_category_record(int $questionid): ?\stdClass {
        global $DB;

        $category = $DB->get_record_sql(
            "SELECT c.id, c.contextid
               FROM {question_categories} c
               JOIN {question_bank_entries} e ON e.questioncategoryid = c.id
               JOIN {question_versions} v ON v.questionbankentryid = e.id
              WHERE v.questionid = :questionid",
            ['questionid' => $questionid]
        );

        return $category ?: null;
    }
}
