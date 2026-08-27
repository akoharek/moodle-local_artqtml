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
 * Shared status/log helpers for local_artqtml adhoc tasks (7, 8).
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\task;

/**
 * Common helpers for updating a generation's status and recording log/event entries.
 */
trait generation_status_trait {
    /**
     * Update the generation status, technical error message and timemodified.
     *
     * @param \stdClass $generation the generation record to update
     * @param string $status started, generating, validating, completed or failed
     * @param string|null $error technical error message to store (e.g. an API failure detail),
     *      or null to clear any previously stored error (the default for non-failure statuses)
     * @return void
     */
    protected function set_status(\stdClass $generation, string $status, ?string $error = null): void {
        global $DB;

        $generation->status = $status;
        $generation->error = $error;
        $generation->timemodified = time();
        $DB->update_record('local_artqtml_generations', $generation);
    }

    /**
     * Re-check that a generation still exists and is still in the expected in-progress status
     * Before committing further work. A long-running Claude/Gemini HTTP call can take
     * Tens of seconds; in that window the user could have aborted (status reset to "started"
     * After a rollback) or deleted the generation entirely via the UI, and results from a call
     * Started before that must never be silently saved afterwards.
     *
     * @param int $generationid
     * @param string $expectedstatus the status the generation must still be in (e.g. "generating")
     * @return \stdClass|null the freshly reloaded record if still active, or null if it no
     *      longer exists or its status has changed away from $expectedstatus
     */
    protected function reload_if_active(int $generationid, string $expectedstatus): ?\stdClass {
        global $DB;

        $generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid]);
        if (!$generation || $generation->status !== $expectedstatus) {
            return null;
        }

        return $generation;
    }

    /**
     * Insert a plain lifecycle row into local_artqtml_log (e.g. processing_started).
     *
     * @param int $generationid the owning generation id
     * @param string $event event identifier
     * @param array $data extra event data
     * @param int|null $userid the user who initiated the generation, null if unknown
     * @return void
     */
    protected function log_event(int $generationid, string $event, array $data = [], ?int $userid = null): void {
        global $DB;

        $record = new \stdClass();
        $record->generationid = $generationid;
        $record->userid = $userid;
        $record->event = $event;
        $record->data = json_encode($data);
        $record->timecreated = time();

        $DB->insert_record('local_artqtml_log', $record);
    }

    /**
     * Record one AI API call attempt and trigger the matching Moodle event.
     *
     * @param int $generationid
     * @param string $calltype 'generate' or 'validate'
     * @param string $provider 'claude' or 'gemini'
     * @param array $details httpstatus, tokensinput, tokensoutput, jsonattempt, isretry,
     * Requestid, result ('success'|'error'), errormessage
     * @param int|null $userid the user who initiated the generation, null if unknown
     * @return void
     */
    protected function log_ai_call(
        int $generationid,
        string $calltype,
        string $provider,
        array $details,
        ?int $userid = null
    ): void {
        global $DB;

        $record = new \stdClass();
        $record->generationid = $generationid;
        $record->userid = $userid;
        $record->event = $details['result'] === 'success' ? 'ai_call_made' : 'ai_call_failed';
        $record->calltype = $calltype;
        $record->provider = $provider;
        $record->httpstatus = $details['httpstatus'] ?? null;
        $record->tokensinput = $details['tokensinput'] ?? null;
        $record->tokensoutput = $details['tokensoutput'] ?? null;
        $record->jsonattempt = $details['jsonattempt'] ?? 1;
        $record->isretry = !empty($details['isretry']) ? 1 : 0;
        $record->requestid = $details['requestid'] ?? null;
        $record->result = $details['result'] ?? 'error';
        $record->errormessage = $details['errormessage'] ?? null;
        $record->data = json_encode($details);
        $record->timecreated = time();

        $DB->insert_record('local_artqtml_log', $record);

        $context = \context_system::instance();
        $eventdata = [
            'context' => $context,
            'other'   => [
                'generationid'    => $generationid,
                'call_type'       => $calltype,
                'provider'        => $provider,
                'http_status'     => (int) ($details['httpstatus'] ?? 0),
                'tokens_input'    => (int) ($details['tokensinput'] ?? 0),
                'tokens_output'   => (int) ($details['tokensoutput'] ?? 0),
                'json_attempt'    => (int) ($details['jsonattempt'] ?? 1),
                'is_retry_attempt' => !empty($details['isretry']),
                'request_id'      => (string) ($details['requestid'] ?? ''),
                'error_message'   => (string) ($details['errormessage'] ?? ''),
            ],
        ];

        if ($details['result'] === 'success') {
            \local_artqtml\event\ai_call_made::create($eventdata)->trigger();
        } else {
            \local_artqtml\event\ai_call_failed::create($eventdata)->trigger();
        }
    }
}
