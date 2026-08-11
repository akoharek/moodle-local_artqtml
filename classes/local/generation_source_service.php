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
 * Creates a generation, or updates the source of one that is still a draft.
 *
 * WHY THIS IS A CLASS AND NOT A FUNCTION IN upload.php. It used to be
 * `local_artqtml_save_generation()`, declared at file scope in a controller that starts a
 * session, reads request parameters and redirects. Nothing could call it in a test, which meant
 * the one behaviour that actually needed proving here - that a form submitted after the
 * generation's status changed writes nothing - could not be proven at all.
 *
 * The division of labour is deliberate: this decides whether a write may happen and performs it;
 * the controller decides what the user is then shown. It never redirects, never renders and never
 * touches capabilities - the capability check belongs to the page, and has already happened by the
 * time anything gets here.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * The write path for a generation's source text and identifiers.
 */
class generation_source_service {
    /**
     * Create a generation, or update the source of an existing draft.
     *
     * THE STATUS IS READ AGAIN HERE, from the database, in the same breath as the write. The page
     * checked it too, when it opened - and that check is worth nothing by the time a form comes
     * back. The sequence this guards against needs no attacker:
     *
     *   1. a teacher opens a draft's source page;
     *   2. in another tab - or another teacher, the tool is site-wide - the generation is started;
     *   3. the first tab's form is submitted.
     *
     * Without the re-read, step 3 rewrites the source text of a generation that is at that moment
     * being read by Claude, and will shortly be read again by Gemini.
     *
     * THE TRANSACTION IS SHORT ON PURPOSE. It wraps the re-read and the update and nothing else -
     * file extraction, the security screen, duplicate detection and the whole of the page's
     * rendering happen outside it. A transaction held across those would be a long-lived lock on a
     * user-facing path.
     *
     * WHAT IT STILL DOES NOT CLOSE, stated rather than glossed over: this is a read-then-write
     * inside one transaction, not a database-level compare-and-swap. Two saves landing in the same
     * instant on the same draft can still interleave. That was not worth a non-portable
     * `SELECT ... FOR UPDATE` across every database Moodle supports, and the case it would fix -
     * two people editing the same draft in the same second - is not the case this change is about.
     *
     * @param string $name the generation's display name
     * @param string $shortname the short identifier
     * @param string $sourcetext the source material
     * @param int $editingid the generation being edited, or 0 to create a new one
     * @param string|null $filehash hash of the uploaded file, if any
     * @param int $userid the user the generation belongs to, when creating
     * @return int the generation id
     * @throws \moodle_exception if an existing generation is no longer a draft
     */
    public static function save(
        string $name,
        string $shortname,
        string $sourcetext,
        int $editingid,
        ?string $filehash,
        int $userid
    ): int {
        global $DB;

        if ($editingid > 0) {
            // The lock, not the transaction, is what makes the three lines below one decision. The
            // transaction gives atomicity; it issues no row-level SELECT ... FOR UPDATE, so without
            // this another request could start the generation between the status check and the
            // write, and the source text would be replaced under a model already reading it
            // (2026-08-05, BL-51). The lock is released whether this returns or throws.
            return (int) generation_lock::run(
                $editingid,
                static fn(): int => self::update_draft_source($editingid, $name, $shortname, $sourcetext, $filehash)
            );
        }

        $record = new \stdClass();
        $record->userid = $userid;
        $record->name = $name;
        $record->shortname = $shortname;
        $record->sourcetext = $sourcetext;
        $record->sourcetexthash = duplicate_detector::hash($sourcetext);
        $record->sourcefilehash = $filehash;
        $record->status = generation_status::STARTED;
        $record->timecreated = time();
        $record->timemodified = time();

        return (int) $DB->insert_record('local_artqtml_generations', $record);
    }

    /**
     * Re-read the status and write the source columns, with the generation already locked.
     *
     * Split out of {@see self::save()} only so that the locked section is a named thing rather than
     * a closure body - the sequence, not the layout, is what matters.
     *
     * @param int $editingid the generation being edited
     * @param string $name the generation's display name
     * @param string $shortname the short identifier
     * @param string $sourcetext the source material
     * @param string|null $filehash hash of the uploaded file, if any
     * @return int the generation id
     * @throws \moodle_exception if the generation is no longer a draft
     */
    protected static function update_draft_source(
        int $editingid,
        string $name,
        string $shortname,
        string $sourcetext,
        ?string $filehash
    ): int {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $record = $DB->get_record('local_artqtml_generations', ['id' => $editingid], '*', MUST_EXIST);

        // Glob-031: collaborative :use by design; delete is owner-only (see delete.php).
        // Deliberately no owner check here or at the call site — any :use holder may edit any
        // DRAFT (including a colleague's). What follows is a status boundary, not ownership.
        generation_edit_policy::require_source_editable($record);

        // ONLY THIS PAGE'S OWN COLUMNS ARE WRITTEN, and that is the point of the object below
        // rather than the record that was just read. Moodle's update_record() writes every
        // property it is given, so handing back the whole loaded record wrote every column -
        // including `status`. A generation started in another tab between the read above and the
        // write here therefore had its status pushed back from `generating` to `started` by a form
        // that never meant to touch it (2026-08-05, BL-51).
        $DB->update_record('local_artqtml_generations', (object) [
            'id'             => $editingid,
            'name'           => $name,
            'shortname'      => $shortname,
            'sourcetext'     => $sourcetext,
            'sourcetexthash' => duplicate_detector::hash($sourcetext),
            'sourcefilehash' => $filehash,
            'timemodified'   => time(),
        ]);
        $transaction->allow_commit();

        return $editingid;
    }
}
