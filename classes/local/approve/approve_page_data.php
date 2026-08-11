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
 * Read-only data gathering for the draft approval page (functional spec ch.7) - split out of the
 * approve.php controller. Counters, the paginated/sorted question query and the category
 * resolution; no HTML, no mutation.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\approve;

/**
 * Supplies the approve page's display data.
 */
class approve_page_data {
    /**
     * The sortable column key -> SQL order-by expression map (Jov-011/012). 'lasteditedby' sorts
     * by the editor's name via the lasteditorname alias the {@see self::questions()} query builds.
     *
     * @return array<string,string>
     */
    public static function sortable_columns(): array {
        return [
            'name'         => 'q.questioncode',
            'type'         => 'q.typecode',
            'difficulty'   => 'q.difficultylabel',
            'validation'   => 'q.validationsuggestion',
            // No 'confidence' entry: spec v26 removed the Confidence column from this table
            // ("Konfidencia oszlop a táblázatban nem jelenik meg"), and an unsortable-because-
            // unrendered column must not keep a sort key that a stale bookmarked URL could still
            // reach. The confidence % itself lives on in the question editor's read-only
            // validation section (Jov-020) - see classes/local/validation_panel.php.
            'timecreated'  => 'q.timecreated',
            // V20 #14: sortable creator column, consistent with the list page. On this page every
            // row shares the generation owner as its creator, so the sort is effectively a no-op
            // here, but the header is made sortable for UI consistency. Backed by creatorname below.
            'creator'      => 'creatorname',
            // Jov-011: sort by the editor's name (not the raw userid), so a teacher can group a
            // generation's questions by who last touched them; unedited rows have no editor and sort
            // together at the start/end. Backed by the LEFT JOIN + lasteditorname alias in the query below.
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
     * Val-017/TC-Val-019: the four-status validation summary counts, in a fixed key order
     * (accepted, needs_review, rejected, not_evaluated) that drives the badge display order.
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
     * Same eligibility criteria as the 'allaccepted' bulk action (accepted, not yet approved, not
     * yet moved out) - TC-Val-019/TC-Val-043: the bulk-approve button reacts to this count.
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

        // Jov-011: LEFT JOIN the editor so "Last edited by" can be sorted by name (lasteditorname),
        // not by the raw q.lasteditedby id. COALESCE keeps unedited rows (no join match) as an empty
        // name rather than a NULL that some engines refuse to concatenate. q.id stays the leading unique
        // column get_records_sql() requires.
        $editornamesort = 'LOWER(' . $DB->sql_concat("COALESCE(ue.lastname, '')", "COALESCE(ue.firstname, '')") . ')';
        // V20 #14: the generation owner's name, so the "Created by" column is a valid ORDER BY
        // target. INNER JOINs are safe: every question has a generation (FK), and a generation
        // has a userid (NOT NULL).
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
     * without it, edit_question_form.php is built with no hidden "category" field at all, and
     * validation() unconditionally reads $fromform['category'] on save, throwing a PHP notice/
     * warning in core. The category can't be assumed to be this generation's own draft category
     * (system context) either, since an already-moved question now lives in whatever real course
     * category the teacher picked - so this is resolved fresh from the question's current version.
     *
     * @param int $questionid
     * @return string|null null if the question/category can't be resolved
     */
    public static function question_category_value(int $questionid): ?string {
        global $DB;

        $category = $DB->get_record_sql(
            "SELECT c.id, c.contextid
               FROM {question_categories} c
               JOIN {question_bank_entries} e ON e.questioncategoryid = c.id
               JOIN {question_versions} v ON v.questionbankentryid = e.id
              WHERE v.questionid = :questionid",
            ['questionid' => $questionid]
        );

        return $category ? ($category->id . ',' . $category->contextid) : null;
    }
}
