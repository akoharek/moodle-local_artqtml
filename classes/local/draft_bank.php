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
 * Manages the isolated, per-generation "draft" question category (functional spec ch.7).
 *
 * Each generation gets its own question_categories row, named after the generation, so
 * generated questions never mix with real question bank content until the user explicitly
 * approves/moves them (Jov-002). This category only ever holds questions this plugin created
 * and that have never been used in a quiz attempt, so it is always safe to hard-delete its
 * contents directly via the qtype API rather than the slower "move to recycle bin" flow real
 * question bank deletions use.
 *
 * Jov-023: draft categories live under the admin-configured draft course, not context_system —
 * that keeps unreviewed AI content away from ordinary question bank browsing. On Moodle 4.5
 * that means the course context; on Moodle 5.1+ core only allows question categories in
 * CONTEXT_MODULE (mod_qbank), so we create/reuse the course's system-type qbank activity and
 * store drafts there instead.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Create/delete the isolated draft question bank category for a generation.
 */
class draft_bank {
    /**
     * The shared root category's idnumber - the plugin's stable handle on its own category.
     *
     * A category's name is a lang string and moves with the site's language; its idnumber does
     * not. Everything that looks the root up looks it up by this.
     */
    public const ROOT_IDNUMBER = 'artqtml_draft_root';

    /** @var int|null cached id of the shared "ArtQTML" root category for this request. */
    protected static $rootcategoryid = null;

    /**
     * Whether the admin-configured draft course (Jov-023) is actually usable right now - checked
     * at the "start a new generation" checkpoints (upload.php, generate.php). Callers must check
     * this before ever calling {@see self::create()}/{@see self::get_root_category_id()}, which
     * throw if it's false.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return self::get_draft_courseid() !== null;
    }

    /**
     * The configured draft course's id, or null if unset or the course no longer exists.
     *
     * Public so callers building native question-bank URLs (approve.php's Edit/Preview
     * links) can pass the course the draft questions actually live in (Jov-023) rather than
     * SITEID - the question editor resolves its context from this courseid.
     *
     * @return int|null
     */
    public static function get_draft_courseid(): ?int {
        global $DB;

        $courseid = (int) (get_config('local_artqtml', 'draftcourseid') ?: 0);
        if ($courseid <= 0 || !$DB->record_exists('course', ['id' => $courseid])) {
            return null;
        }

        return $courseid;
    }

    /**
     * Whether this Moodle build stores question banks in mod_qbank module contexts (5.1+).
     *
     * @return bool
     */
    public static function uses_module_question_banks(): bool {
        return class_exists(\core_question\local\bank\question_bank_helper::class)
            && method_exists(
                \core_question\local\bank\question_bank_helper::class,
                'get_default_open_instance_system_type'
            );
    }

    /**
     * Course context of the configured draft course (roles / course:view).
     *
     * Distinct from {@see self::get_draft_context()}: on Moodle 5.1+ question categories live in
     * a mod_qbank module context, but the draft-editing role is still assigned at course level.
     *
     * @return \context_course|null
     */
    public static function get_draft_course_context(): ?\context_course {
        $courseid = self::get_draft_courseid();
        if ($courseid === null) {
            return null;
        }

        return \context_course::instance($courseid);
    }

    /**
     * Context where draft question categories are stored (Jov-023).
     *
     * Moodle 4.5: the draft course context. Moodle 5.1+: the draft course's system-type
     * mod_qbank activity (created on first use).
     *
     * @return \context
     * @throws \moodle_exception if unset or the course no longer exists - callers are expected
     *      to have already blocked starting a new generation in that case via
     *      {@see self::is_configured()}, so reaching here means something raced past that check
     */
    protected static function get_draft_context(): \context {
        $courseid = self::get_draft_courseid();
        if ($courseid === null) {
            throw new \moodle_exception('errordraftcoursenotconfigured', 'local_artqtml');
        }

        if (self::uses_module_question_banks()) {
            $course = get_course($courseid);
            $cm = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type(
                $course,
                true
            );
            if ($cm === null) {
                throw new \moodle_exception('errordraftcoursenotconfigured', 'local_artqtml');
            }

            return \context_module::instance($cm->id);
        }

        return \context_course::instance($courseid);
    }

    /**
     * Context id where draft question categories live (Jov-023).
     *
     * Public: {@see \local_artqtml\local\question_bank_list} needs this to recognise the draft
     * bank when enumerating move-target categories.
     *
     * @return int|null null if no draft course is configured - callers must check
     *      {@see self::is_configured()} first
     */
    public static function get_draft_context_id(): ?int {
        if (!self::is_configured()) {
            return null;
        }

        return self::get_draft_context()->id;
    }

    /**
     * Create a new draft category for a generation, nested under the shared
     * "ArtQTML" root category (never directly under the context's own hidden root).
     *
     * @param \stdClass $generation the local_artqtml_generations record (needs name, shortname)
     * @return int the new question_categories.id
     */
    public static function create(\stdClass $generation): int {
        global $DB;

        $draftcontext = self::get_draft_context();

        // M-21: idnumber-tag and label every draft category as unreviewed AI content, so anyone
        // who does have access to the draft course's context (by construction, only admins/
        // managers - see get_root_category_id()'s docblock) and browses into it sees at a glance
        // it isn't meant to be used directly, and other tooling/reports can find/exclude these
        // by pattern.
        $record = new \stdClass();
        $record->name = get_string(
            'draftbankname',
            'local_artqtml',
            (object) ['name' => $generation->name, 'shortname' => $generation->shortname]
        );
        $record->contextid = $draftcontext->id;
        $record->info = get_string('draftcategoryinfo', 'local_artqtml');
        $record->infoformat = FORMAT_HTML;
        $record->stamp = make_unique_id_code();
        $record->parent = self::get_root_category_id();
        $record->sortorder = 999;
        $record->idnumber = 'artqtml_draft_' . $generation->id;

        return (int) $DB->insert_record('question_categories', $record);
    }

    /**
     * Get (creating if needed) the shared "ArtQTML" category all per-generation draft
     * banks live under.
     *
     * A plain `parent = 0` (as this used to be set to) is Moodle's own marker for a context's
     * single hidden "top" category - question_get_top_category() looks for exactly that. On a
     * site where no question category has ever been created at the draft course's context before
     * this plugin's first draft bank, Moodle would find and treat that first draft category as
     * if it were the real top category for that context, since nothing else with parent=0
     * existed yet to disambiguate them. Nesting every draft bank one level deeper, under a
     * category of our own that itself has a real top category as its parent, avoids ever
     * creating anything with parent=0 ourselves.
     *
     * Public: {@see \local_artqtml\local\question_bank_list} also needs this id, to exclude the
     * whole draft-bank subtree (this category plus every generation's child category under it)
     * from the "move to a real bank" target list - not just the current generation's own draft
     * category, which is all the old parent=0 based filtering used to catch incidentally.
     *
     * @return int question_categories.id of the shared root category
     */
    public static function get_root_category_id(): int {
        global $CFG, $DB;

        if (self::$rootcategoryid !== null) {
            return self::$rootcategoryid;
        }

        require_once($CFG->libdir . '/questionlib.php');

        $draftcontext = self::get_draft_context();
        $top = question_get_top_category($draftcontext->id, true);
        if (!$top) {
            throw new \moodle_exception('errordraftcoursenotconfigured', 'local_artqtml');
        }

        // Looked up by idnumber, never by name. The name is a lang string, so it changes with the
        // site's interface language - and the lookup then fails to find a category this plugin
        // created itself. What follows is an insert with the same fixed idnumber, which
        // question_categories rejects: it carries a unique index on (contextid, idnumber). The
        // user sees "Error writing to database" and cannot start any generation.
        //
        // Found on 2026-07-31 by switching the interface to Hungarian: the root had been created
        // as "ArtQTML" and the Hungarian lookup asked for "ArtQTML". The
        // idnumber was already there, already fixed, already the stable identifier - it just was
        // not what the code searched on.
        $existing = $DB->get_record('question_categories', [
            'contextid' => $draftcontext->id,
            'idnumber'  => self::ROOT_IDNUMBER,
        ]);

        if (!$existing) {
            // A root created before this fix may predate the idnumber, or carry it under a name
            // written in whatever language was active that day. Adopt it and stamp the idnumber on,
            // rather than creating a second root beside it.
            $existing = $DB->get_record('question_categories', [
                'contextid' => $draftcontext->id,
                'parent'    => $top->id,
                'name'      => get_string('draftrootcategoryname', 'local_artqtml'),
            ]);
            if ($existing && (string) $existing->idnumber === '') {
                $DB->set_field('question_categories', 'idnumber', self::ROOT_IDNUMBER, ['id' => $existing->id]);
            }
        }

        if ($existing) {
            self::$rootcategoryid = (int) $existing->id;
            return self::$rootcategoryid;
        }

        $record = new \stdClass();
        $record->name = get_string('draftrootcategoryname', 'local_artqtml');
        $record->contextid = $draftcontext->id;
        $record->info = get_string('draftcategoryinfo', 'local_artqtml');
        $record->infoformat = FORMAT_HTML;
        $record->stamp = make_unique_id_code();
        $record->parent = $top->id;
        $record->sortorder = 999;
        $record->idnumber = self::ROOT_IDNUMBER;

        self::$rootcategoryid = (int) $DB->insert_record('question_categories', $record);
        return self::$rootcategoryid;
    }

    /**
     * Delete a draft category and every question still inside it.
     *
     * Safe to call even if some/all of its questions have already been moved out
     * (questionbankid set) - those live in a different category by then.
     *
     * @param int $categoryid question_categories.id
     * @return void
     */
    public static function delete(int $categoryid): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/questionlib.php');

        if (!$DB->record_exists('question_categories', ['id' => $categoryid])) {
            return;
        }

        // The core question_delete_question() is the entry point that fully cleans up a question
        // (qtype-specific options, versions, bank entry, tags, files) - used here instead of
        // hand-rolled table deletes so cleanup stays correct as core evolves.
        $entries = $DB->get_records('question_bank_entries', ['questioncategoryid' => $categoryid]);
        foreach ($entries as $entry) {
            $versions = $DB->get_records('question_versions', ['questionbankentryid' => $entry->id]);
            foreach ($versions as $version) {
                question_delete_question($version->questionid);
            }
        }

        $DB->delete_records('question_categories', ['id' => $categoryid]);
    }

    /**
     * Delete the draft category if every question that was ever in it has been processed
     * (moved out or deleted from the draft list) - i.e. none remain in
     * local_artqtml_questions with this generationid (Jov-018).
     *
     * @param int $generationid
     * @param int $categoryid
     * @return void
     */
    public static function delete_if_empty(int $generationid, int $categoryid): void {
        global $DB;

        $remaining = $DB->count_records('local_artqtml_questions', [
            'generationid' => $generationid,
            'movedout'     => 0,
        ]);

        if ($remaining === 0) {
            self::delete($categoryid);
            $DB->set_field('local_artqtml_generations', 'draftcategoryid', null, ['id' => $generationid]);
        }
    }
}
