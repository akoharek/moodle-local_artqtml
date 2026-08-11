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
 * Privacy provider for local_artqtml.
 *
 * The plugin is site-wide (Glob-022/023): generations are not tied to a course, so all
 * personal data lives at the system context.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the local_artqtml plugin.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    core_userlist_provider {
    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection the initialised collection to add items to
     * @return collection the updated collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_artqtml_generations',
            [
                'userid' => 'privacy:metadata:local_artqtml_generations:userid',
                'name' => 'privacy:metadata:local_artqtml_generations:name',
                'shortname' => 'privacy:metadata:local_artqtml_generations:shortname',
                'sourcetext' => 'privacy:metadata:local_artqtml_generations:sourcetext',
                'status' => 'privacy:metadata:local_artqtml_generations:status',
                'timecreated' => 'privacy:metadata:local_artqtml_generations:timecreated',
                'timemodified' => 'privacy:metadata:local_artqtml_generations:timemodified',
            ],
            'privacy:metadata:local_artqtml_generations'
        );

        $collection->add_database_table(
            'local_artqtml_questions',
            [
                'questiontype' => 'privacy:metadata:local_artqtml_questions:questiontype',
                'questiontext' => 'privacy:metadata:local_artqtml_questions:questiontext',
                'questiondata' => 'privacy:metadata:local_artqtml_questions:questiondata',
                'validationsuggestion' => 'privacy:metadata:local_artqtml_questions:validationsuggestion',
                'justification' => 'privacy:metadata:local_artqtml_questions:justification',
                'confidence' => 'privacy:metadata:local_artqtml_questions:confidence',
                'timecreated' => 'privacy:metadata:local_artqtml_questions:timecreated',
                // V20 #5: these can identify a user other than the generation owner (M-30/Glob-032:
                // any local/artqtml:use user can edit/approve any generation's questions).
                'lasteditedby' => 'privacy:metadata:local_artqtml_questions:lasteditedby',
                'approvedby' => 'privacy:metadata:local_artqtml_questions:approvedby',
            ],
            'privacy:metadata:local_artqtml_questions'
        );

        // Every column, not the four that happened to be listed. A privacy declaration is a
        // statement about what is stored, and a partial one is a wrong one - the table has always
        // held the provider, the token counts and the request id as well.
        $collection->add_database_table(
            'local_artqtml_log',
            [
                'generationid' => 'privacy:metadata:local_artqtml_log:generationid',
                'originalgenerationid' => 'privacy:metadata:local_artqtml_log:originalgenerationid',
                'userid' => 'privacy:metadata:local_artqtml_log:userid',
                'event' => 'privacy:metadata:local_artqtml_log:event',
                'calltype' => 'privacy:metadata:local_artqtml_log:calltype',
                'provider' => 'privacy:metadata:local_artqtml_log:provider',
                'httpstatus' => 'privacy:metadata:local_artqtml_log:httpstatus',
                'tokensinput' => 'privacy:metadata:local_artqtml_log:tokensinput',
                'tokensoutput' => 'privacy:metadata:local_artqtml_log:tokensoutput',
                'jsonattempt' => 'privacy:metadata:local_artqtml_log:jsonattempt',
                'isretry' => 'privacy:metadata:local_artqtml_log:isretry',
                'requestid' => 'privacy:metadata:local_artqtml_log:requestid',
                'result' => 'privacy:metadata:local_artqtml_log:result',
                'errormessage' => 'privacy:metadata:local_artqtml_log:errormessage',
                'data' => 'privacy:metadata:local_artqtml_log:data',
                'timecreated' => 'privacy:metadata:local_artqtml_log:timecreated',
            ],
            'privacy:metadata:local_artqtml_log'
        );

        $collection->add_external_location_link(
            'claude',
            ['sourcetext' => 'privacy:metadata:externalpurpose:sourcetext'],
            'privacy:metadata:externalpurpose'
        );
        $collection->add_external_location_link(
            'gemini',
            ['sourcetext' => 'privacy:metadata:externalpurpose:sourcetext'],
            'privacy:metadata:externalpurpose'
        );

        return $collection;
    }

    /**
     * Get the list of contexts containing personal data for the given user.
     *
     * Everything lives at the system context, so this is either empty or a single-item list.
     *
     * @param int $userid the user to search
     * @return contextlist the contexts containing personal data for this user
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        // V20 #5: a user has data here not only as a generation owner, but also as the editor or
        // approver of any question (possibly in someone else's generation - M-30/Glob-032), or as
        // the actor on a log entry.
        $hasdata = $DB->record_exists('local_artqtml_generations', ['userid' => $userid])
            || $DB->record_exists('local_artqtml_questions', ['lasteditedby' => $userid])
            || $DB->record_exists('local_artqtml_questions', ['approvedby' => $userid])
            || $DB->record_exists('local_artqtml_log', ['userid' => $userid]);

        if ($hasdata) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * Get the list of users within a context.
     *
     * @param userlist $userlist the userlist to add users to
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        $userlist->add_from_sql('userid', 'SELECT DISTINCT g.userid FROM {local_artqtml_generations} g', []);
        // V20 #5: also the editors/approvers of questions and the actors on log entries, who need
        // not own any generation of their own.
        $userlist->add_from_sql(
            'lasteditedby',
            'SELECT DISTINCT q.lasteditedby FROM {local_artqtml_questions} q WHERE q.lasteditedby IS NOT NULL',
            []
        );
        $userlist->add_from_sql(
            'approvedby',
            'SELECT DISTINCT q.approvedby FROM {local_artqtml_questions} q WHERE q.approvedby IS NOT NULL',
            []
        );
        $userlist->add_from_sql(
            'userid',
            'SELECT DISTINCT l.userid FROM {local_artqtml_log} l WHERE l.userid IS NOT NULL',
            []
        );
    }

    /**
     * Export personal data for the given approved contexts.
     *
     * @param approved_contextlist $contextlist the approved contexts to export data for
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        $hassystem = false;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                $hassystem = true;
            }
        }
        if (!$hassystem) {
            return;
        }

        $generations = $DB->get_records('local_artqtml_generations', ['userid' => $user->id]);

        $data = [];
        foreach ($generations as $generation) {
            $questions = $DB->get_records('local_artqtml_questions', ['generationid' => $generation->id]);

            $data[] = [
                'name' => $generation->name,
                'shortname' => $generation->shortname,
                'sourcetext' => $generation->sourcetext,
                'status' => $generation->status,
                'timecreated' => transform::datetime($generation->timecreated),
                'timemodified' => transform::datetime($generation->timemodified),
                'questions' => array_map(static function ($question) {
                    return [
                        'questiontype' => $question->questiontype,
                        'questiontext' => $question->questiontext,
                        'questiondata' => $question->questiondata,
                        'validationsuggestion' => $question->validationsuggestion,
                        'justification' => $question->justification,
                        'confidence' => $question->confidence,
                        'timecreated' => transform::datetime($question->timecreated),
                    ];
                }, array_values($questions)),
            ];
        }

        // V20 #5: the user's cross-user footprint - questions they edited or approved in ANY
        // generation (including other users'). Without this, a user who only ever edited/approved
        // someone else's questions (and never owned a generation) would export nothing.
        $footprintrows = $DB->get_records_sql(
            "SELECT q.id, q.questioncode, q.questiontext, q.approvedby, q.lasteditedby, q.lasteditedat,
                    g.name AS generationname
               FROM {local_artqtml_questions} q
               JOIN {local_artqtml_generations} g ON g.id = q.generationid
              WHERE q.lasteditedby = :editor OR q.approvedby = :approver",
            ['editor' => $user->id, 'approver' => $user->id]
        );
        $footprint = array_map(static function ($q) use ($user) {
            $editedbyme = (int) $q->lasteditedby === (int) $user->id;
            return [
                'generation' => $q->generationname,
                'questioncode' => $q->questioncode,
                'questiontext' => $q->questiontext,
                'edited_by_me' => transform::yesno($editedbyme),
                'edited_time' => ($editedbyme && $q->lasteditedat) ? transform::datetime($q->lasteditedat) : '',
                'approved_by_me' => transform::yesno((int) $q->approvedby === (int) $user->id),
            ];
        }, array_values($footprintrows));

        // The log entries, found by user id and NOT through the generations.
        //
        // This is the defect being fixed. They used to be collected inside the loop above, one
        // generation at a time - so an entry whose generation had since been deleted was in the
        // table, carried this user's id, and appeared in no export at all. Those are exactly the
        // entries Glob-040 keeps on purpose, which made the gap invisible: the data was retained
        // deliberately and then omitted accidentally.
        //
        // Not joined to the generations table, for the same reason: the row it would join to is
        // the row that is gone. The historical id is exported as its own field instead, so the
        // export says which generation an entry belonged to without pretending it still exists.
        //
        // The data field is exported as stored. Within the retention period that includes the full
        // system prompt and provider response - which is the user's own data, and an export is
        // where it belongs. An export never redacts and never writes: redaction is a separate,
        // deliberate act.
        $logrows = $DB->get_records('local_artqtml_log', ['userid' => $user->id], 'timecreated ASC');
        $logs = array_map(static function ($entry) {
            return [
                'generationid' => $entry->generationid,
                'originalgenerationid' => $entry->originalgenerationid,
                'event' => $entry->event,
                'calltype' => $entry->calltype,
                'provider' => $entry->provider,
                'httpstatus' => $entry->httpstatus,
                'tokensinput' => $entry->tokensinput,
                'tokensoutput' => $entry->tokensoutput,
                'jsonattempt' => $entry->jsonattempt,
                'isretry' => transform::yesno((bool) $entry->isretry),
                'requestid' => $entry->requestid,
                'result' => $entry->result,
                'errormessage' => $entry->errormessage,
                'data' => $entry->data,
                'timecreated' => transform::datetime($entry->timecreated),
            ];
        }, array_values($logrows));

        // A user whose only remaining footprint is a retained log must not export nothing.
        if (empty($data) && empty($footprint) && empty($logs)) {
            return;
        }

        writer::with_context(\context_system::instance())->export_data(
            [get_string('pluginname', 'local_artqtml')],
            (object) [
                'generations' => $data,
                'edited_or_approved_questions' => $footprint,
                'logs' => $logs,
            ]
        );
    }

    /**
     * Delete all personal data for all users within a context.
     *
     * @param \context $context the context to delete data for
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        self::delete_generations([]);

        // Only log entries attached to a generation are reached by delete_generations(). Entries
        // that are not - a licence integrity violation, or one whose generation was deleted long
        // ago - are caught here, so "delete everything in this context" means everything.
        \local_artqtml\local\diagnostic_log_retention::redact_all();
    }

    /**
     * Delete personal data for the user in the given approved contexts.
     *
     * @param approved_contextlist $contextlist the approved contexts to delete data for
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_SYSTEM) {
                continue;
            }

            self::delete_generations(['userid' => $user->id]);
            // V20 #5: also remove the user's identity from questions/log that belong to OTHER
            // users' generations, which delete_generations() above deliberately leaves in place.
            self::scrub_user_references((int) $user->id);
        }
    }

    /**
     * Delete personal data for the given approved users within a context.
     *
     * @param approved_userlist $userlist the approved users to delete data for
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        foreach ($userlist->get_userids() as $userid) {
            self::delete_generations(['userid' => $userid]);
            // V20 #5: scrub the user's editor/approver/log footprint from other users' generations.
            self::scrub_user_references((int) $userid);
        }
    }

    /**
     * Delete generations matching the given conditions (empty = all), along with their questions
     * and draft bank categories.
     *
     * THE LOG ENTRIES ARE NOT DELETED, they are anonymised - which is deliberate and is Glob-040:
     * a log entry outlives the generation it describes, because what it records is what the site
     * spent and what it asked for, and that has to remain auditable after the material is gone.
     * The identifying part goes: the generation reference is moved to `originalgenerationid`, the
     * user id and the stored payload are redacted, and only then is the generation itself removed.
     *
     * @param array $conditions conditions passed to get_records() on local_artqtml_generations
     * @return void
     */
    protected static function delete_generations(array $conditions): void {
        global $DB;

        $generations = $DB->get_records('local_artqtml_generations', $conditions);
        if (empty($generations)) {
            return;
        }

        $generationids = array_keys($generations);

        foreach ($generations as $generation) {
            if (!empty($generation->draftcategoryid)) {
                \local_artqtml\local\draft_bank::delete((int) $generation->draftcategoryid);
            }
        }

        // The log entries are ANONYMISED, not deleted - the line that deleted them was here until
        // 2026-08-04, and it contradicted Glob-040 for the one case where the product had actually
        // decided the entries should survive.
        //
        // The two obligations are not in conflict once they are separated. What GDPR requires
        // removed is the link to the person and the raw content: the user id goes, the system
        // prompt, schema and provider response go. What the product needs kept is the technical
        // record that a call was made, what it cost and whether it worked - which, with no user id
        // on it, is not personal data.
        //
        // Order matters. The id is preserved into originalgenerationid and the live reference
        // cleared BEFORE the generation rows are deleted, or the entries would be left pointing at
        // rows that no longer exist.
        [$insql, $inparams] = $DB->get_in_or_equal($generationids, SQL_PARAMS_NAMED);

        $DB->execute(
            "UPDATE {local_artqtml_log}
                SET originalgenerationid = generationid
              WHERE originalgenerationid IS NULL AND generationid $insql",
            $inparams
        );
        \local_artqtml\local\diagnostic_log_retention::redact_for_generation_ids($generationids);
        $DB->execute(
            "UPDATE {local_artqtml_log} SET generationid = NULL WHERE generationid $insql",
            $inparams
        );

        $DB->delete_records_select('local_artqtml_questions', "generationid $insql", $inparams);
        $DB->delete_records_select('local_artqtml_generations', "id $insql", $inparams);
    }

    /**
     * Anonymise a user's editor/approver/log footprint on content that belongs to OTHER users'
     * generations (v20 #5). The questions/log rows themselves are another user's data and must
     * stay, so only the user-identifying columns are nulled - all three columns are nullable.
     *
     * @param int $userid
     * @return void
     */
    protected static function scrub_user_references(int $userid): void {
        global $DB;

        // The lasteditedat field is paired with lasteditedby ("who edited, and when") - null it first,
        // while
        // the lasteditedby = :userid rows can still be found, then null lasteditedby itself.
        $DB->set_field('local_artqtml_questions', 'lasteditedat', null, ['lasteditedby' => $userid]);
        $DB->set_field('local_artqtml_questions', 'lasteditedby', null, ['lasteditedby' => $userid]);
        $DB->set_field('local_artqtml_questions', 'approvedby', null, ['approvedby' => $userid]);
        // Nulling the user id alone was not enough: a diagnostics entry still held the full system
        // prompt and the raw provider response, which can carry that user's own material. An
        // anonymous row containing the text of somebody's document is not anonymous in any sense
        // that matters. This nulls the id AND empties the payload, and keeps the row.
        \local_artqtml\local\diagnostic_log_retention::redact_for_user($userid);
    }
}
