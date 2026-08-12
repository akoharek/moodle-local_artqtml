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
 * The plugin is site-wide: generations are not tied to a course, so all personal data lives at the system context.
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
                'lasteditedby' => 'privacy:metadata:local_artqtml_questions:lasteditedby',
                'approvedby' => 'privacy:metadata:local_artqtml_questions:approvedby',
            ],
            'privacy:metadata:local_artqtml_questions'
        );

        // Every column, not the four that happened to be listed. A privacy declaration is a
        // Statement about what is stored, and a partial one is a wrong one - the table has always
        // Held the provider, the token counts and the request id as well.
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
        // Also the editors/approvers of questions and the actors on log entries, who need not own any generation of their own.
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
        global $DB;

        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        self::delete_generations([]);

        // Orphan log rows (generation already gone) may still carry a userid; clear it.
        // Log rows do not store full diagnostic payloads in log.data.
        $DB->set_field_select('local_artqtml_log', 'userid', null, 'userid IS NOT NULL');
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
            // Scrub the user's editor/approver/log footprint from other users' generations.
            self::scrub_user_references((int) $userid);
        }
    }

    /**
     * Delete generations matching the given conditions (empty = all), along with their questions
     * And draft bank categories.
     *
     * @param array $conditions conditions passed to get_records on local_artqtml_generations
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

        // Log entries are anonymised (not deleted): preserve id into originalgenerationid, clear
        // The live generationid and userid, then delete generation/question rows.
        [$insql, $inparams] = $DB->get_in_or_equal($generationids, SQL_PARAMS_NAMED);

        $DB->execute(
            "UPDATE {local_artqtml_log}
                SET originalgenerationid = generationid
              WHERE originalgenerationid IS NULL AND generationid $insql",
            $inparams
        );
        $DB->execute(
            "UPDATE {local_artqtml_log}
                SET generationid = NULL, userid = NULL
              WHERE generationid $insql",
            $inparams
        );

        $DB->delete_records_select('local_artqtml_questions', "generationid $insql", $inparams);
        $DB->delete_records_select('local_artqtml_generations', "id $insql", $inparams);
    }

    /**
     * Anonymise a user's editor/approver/log footprint on content that belongs to OTHER users'
     * Generations. The questions/log rows themselves are another user's data and must
     * Stay, so only the user-identifying columns are nulled - all three columns are nullable.
     *
     * @param int $userid
     * @return void
     */
    protected static function scrub_user_references(int $userid): void {
        global $DB;

        // The lasteditedat field is paired with lasteditedby ("who edited, and when") - null it first,
        // While
        // The lasteditedby = :userid rows can still be found, then null lasteditedby itself.
        $DB->set_field('local_artqtml_questions', 'lasteditedat', null, ['lasteditedby' => $userid]);
        $DB->set_field('local_artqtml_questions', 'lasteditedby', null, ['lasteditedby' => $userid]);
        $DB->set_field('local_artqtml_questions', 'approvedby', null, ['approvedby' => $userid]);
        $DB->set_field('local_artqtml_log', 'userid', null, ['userid' => $userid]);
    }
}
