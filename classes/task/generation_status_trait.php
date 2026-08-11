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
 * Shared status/log helpers for local_artqtml adhoc tasks (technical annex 2.5, 7, 8).
 *
 * @package    local_artqtml
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
     * before committing further work (C-03). A long-running Claude/Gemini HTTP call can take
     * tens of seconds; in that window the user could have aborted (status reset to "started"
     * after a rollback) or deleted the generation entirely via the UI, and results from a call
     * started before that must never be silently saved afterwards.
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
     * @param int|null $userid the user who initiated the generation (Val-020), null if unknown
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
     * BL-34 (Admin-070): store what a call actually sent and what came back, when this generation
     * has diagnostics switched on.
     *
     * A no-op otherwise, and that is the whole design: the payloads are large, so they are kept
     * only for a run somebody deliberately marked. Everything else about the call is already
     * logged by {@see self::log_ai_call()} on every run - status, tokens, attempt number - and
     * that stays as it is.
     *
     * What is worth keeping and what is not. The system prompt and the response schema are
     * rebuildable from the generation's settings, so they are here for convenience. The **raw
     * response body is not rebuildable**, and it is the one thing every unanswered question so far
     * has needed: whether the model returned nothing, returned the wrong question type, or
     * returned something the importer dropped cannot be told apart without it. The source text is
     * deliberately left out - it is already on the generation row, and repeating it per call would
     * multiply the largest field in the request by the number of attempts.
     *
     * The API key never appears: this records the payload and the body, never the headers.
     *
     * @param \stdClass $generation the generation being processed
     * @param string $calltype 'generate' or 'validate'
     * @param array $request the ai_request array, as sent (its 'headers' key is dropped)
     * @param array $result the send() result: httpcode, body, curlerror
     * @param int $jsonattempt which JSON-fallback attempt this was
     * @return void
     */
    protected function log_diagnostics(
        \stdClass $generation,
        string $calltype,
        array $request,
        array $result,
        int $jsonattempt
    ): void {
        if (empty($generation->diagnostics)) {
            return;
        }

        $payload = $request['payload'] ?? [];

        $this->log_event((int) $generation->id, 'diagnostics_call', [
            'calltype'     => $calltype,
            'jsonattempt'  => $jsonattempt,
            'httpstatus'   => $result['httpcode'] ?? null,
            'curlerror'    => $result['curlerror'] ?? '',
            // The payload's shape differs per provider, and knowing that shape belongs to
            // ai_request - the class that built it. Unpacking it here put a second copy of that
            // knowledge in a file that has no other business with provider requests.
            'systemprompt' => \local_artqtml\local\ai_request::system_from_payload($payload),
            'schema'       => \local_artqtml\local\ai_request::schema_from_payload($payload),
            'responsebody' => $result['body'] ?? null,
        ], (int) $generation->userid);
    }

    /**
     * Record one AI API call attempt (technical annex 2.5/7.2) and trigger the matching
     * Moodle event (Glob-010).
     *
     * @param int $generationid
     * @param string $calltype 'generate' or 'validate'
     * @param string $provider 'claude' or 'gemini'
     * @param array $details httpstatus, tokensinput, tokensoutput, jsonattempt, isretry,
     *      requestid, result ('success'|'error'), errormessage
     * @param int|null $userid the user who initiated the generation (Val-020), null if unknown
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

        \local_artqtml\local\debug_logger::log(sprintf(
            'generation #%d %s/%s call %s (attempt %d%s, HTTP %s): %s',
            $generationid,
            $calltype,
            $provider,
            $record->result,
            $record->jsonattempt,
            $record->isretry ? ', retry' : '',
            $record->httpstatus ?? 'n/a',
            $record->result === 'success'
                ? sprintf('%d in / %d out tokens', $record->tokensinput ?? 0, $record->tokensoutput ?? 0)
                : (string) $record->errormessage
        ));

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
