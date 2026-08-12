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
 * Roll an in-flight generation back to a draft the teacher can reopen.
 *
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Shared "back to Megkezdett" cleanup used by the status-page Abort button and by pipeline
 * Gates that must refuse to call a provider without leaving the generation stuck in
 * Generating/validating/failed.
 */
class generation_recover {
    /**
     * Delete draft bank / question rows / pending pipeline junk and return the generation to
     * {@see generation_status::STARTED} so upload.php and generate.php are editable again.
     *
     * @param \stdClass $generation a local_artqtml_generations record (id required; other fields
     * Refreshed from the DB inside this method)
     * @param string|null $usermessage teacher-facing message, or null to clear any previous error
     * @return \stdClass the updated generation record
     */
    public static function to_started(\stdClass $generation, ?string $usermessage = null): \stdClass {
        global $DB;

        $generationid = (int) $generation->id;
        $fresh = $DB->get_record('local_artqtml_generations', ['id' => $generationid], '*', MUST_EXIST);

        $transaction = $DB->start_delegated_transaction();
        if (!empty($fresh->draftcategoryid)) {
            draft_bank::delete((int) $fresh->draftcategoryid);
            $fresh->draftcategoryid = null;
        }
        $DB->delete_records('local_artqtml_questions', ['generationid' => $generationid]);
        $DB->set_field('local_artqtml_generations', 'pendingdata', null, ['id' => $generationid]);
        $DB->set_field('local_artqtml_generations', 'countdiscrepancy', null, ['id' => $generationid]);
        $transaction->allow_commit();

        $fresh->draftcategoryid = null;
        $fresh->pendingdata = null;
        $fresh->countdiscrepancy = null;
        $fresh->status = generation_status::STARTED;
        $fresh->error = $usermessage;
        $fresh->timemodified = time();
        $DB->update_record('local_artqtml_generations', $fresh);

        return $fresh;
    }
}
