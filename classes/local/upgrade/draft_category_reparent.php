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

namespace local_artqtml\local\upgrade;

defined('MOODLE_INTERNAL') || die();

/**
 * One-off repair for draft categories left at parent = 0 by earlier versions (D-3).
 *
 * Parent = 0 is Moodle's own marker for a context's single hidden "top" category. Earlier
 * Versions of this plugin created each generation's draft category that way, so on any context
 * Where that happened there are now two parent = 0 rows, and question_get_top_category()'s
 * Get_record() throws "found more than one record" on any question bank page that walks it. That
 * Is what broke the target course's question bank on the demo site after a question was moved.
 *
 * Current code never writes parent = 0 (see \local_artqtml\local\draft_bank), so this repairs
 * History only.
 *
 * Frozen: this class is called from exactly one upgrade step (2026072800) and its behaviour must
 * Not change - an install upgrading from an older version years from now has to get the same
 * Repair. If a further repair is ever needed, add a new class and a new step; do not edit this
 * One. It lives here rather than inline in db/upgrade.php purely so it can be unit-tested.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class draft_category_reparent {
    /**
     * Re-parent every draft category still sitting at parent = 0 under its context's real top.
     *
     * Deliberately re-parents and never deletes: the questions inside are real, and an upgrade
     * Step is the wrong place to destroy content. Getting rid of a draft bank on purpose is what
     * The plugin's own approval UI is for.
     *
     * @return int how many categories were repaired
     */
    public static function run(): int {
        global $DB;

        // Identified through the plugin's own reference (draftcategoryid), not by name or
        // Idnumber: older versions may predate the idnumber tagging, and a name match would be a
        // Guess. A draftcategoryid is a fact.
        $orphans = $DB->get_records_sql("
            SELECT qc.id, qc.contextid
              FROM {question_categories} qc
              JOIN {local_artqtml_generations} g ON g.draftcategoryid = qc.id
             WHERE qc.parent = 0
        ");

        foreach ($orphans as $orphan) {
            $top = self::find_real_top($orphan->contextid, $orphan->id, $orphans);

            if ($top === null) {
                // Nothing else has parent = 0 here, so this draft category has been serving as the
                // Context's top all along - exactly the case draft_bank's docblock describes. A
                // Real top has to exist before the draft one can hang under it.
                $top = self::create_top($orphan->contextid);
            }

            $DB->set_field('question_categories', 'sortorder', 999, ['id' => $orphan->id]);
            $DB->set_field('question_categories', 'parent', $top->id, ['id' => $orphan->id]);
        }

        return count($orphans);
    }

    /**
     * The context's genuine top: a parent = 0 row that is not itself one of the draft categories
     * Being repaired.
     *
     * Question_get_top_category() cannot be used here - it is precisely the function that throws
     * While the duplicate still exists.
     *
     * @param int $contextid
     * @param int $orphanid the draft category being repaired
     * @param array $orphans all draft categories being repaired, keyed by id
     * @return \stdClass|null null when this context has no other parent = 0 row
     */
    private static function find_real_top(int $contextid, int $orphanid, array $orphans): ?\stdClass {
        global $DB;

        $candidates = $DB->get_records_select(
            'question_categories',
            'contextid = :contextid AND parent = 0 AND id <> :orphanid',
            ['contextid' => $contextid, 'orphanid' => $orphanid],
            'id ASC'
        );

        foreach ($candidates as $candidate) {
            if (!isset($orphans[$candidate->id])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Create the hidden top category for a context, the same shape core creates it in.
     *
     * @param int $contextid
     * @return \stdClass the inserted record, with its id
     */
    private static function create_top(int $contextid): \stdClass {
        global $DB;

        $top = new \stdClass();
        $top->name = 'top';
        $top->info = '';
        $top->infoformat = FORMAT_HTML;
        $top->contextid = $contextid;
        $top->parent = 0;
        $top->sortorder = 0;
        $top->stamp = make_unique_id_code();
        $top->id = $DB->insert_record('question_categories', $top);

        return $top;
    }
}
