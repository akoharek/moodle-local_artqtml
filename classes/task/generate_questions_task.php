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
 * Calls the Claude API to generate quiz questions for a generation.
 *
 * Invoked from {@see process_pending_generations}, the scheduled task that actually runs the
 * AI pipeline in the background (every 5 minutes by default, or manually via
 * `admin/cli/scheduled_task.php --execute='\local_artqtml\task\process_pending_generations'`
 * - see that class). This is a plain processor, not itself an adhoc/scheduled task, so it can
 * Be unit-tested and invoked directly without going through Moodle's task runner.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\task;

use local_artqtml\local\ai_request;
use local_artqtml\local\generation_recover;
use local_artqtml\local\model_list;
use local_artqtml\local\question\ai_text_cleaner;
use local_artqtml\local\question_types;
use local_artqtml\local\question_schema;
use local_artqtml\local\security_filter;
use local_artqtml\local\source_text_limit;

defined('MOODLE_INTERNAL') || die();

/**
 * Generates AI quiz questions for a single local_artqtml_generations record.
 */
class generate_questions_task {
    use generation_status_trait;
    use retry_trait;

    /** @var int maximum JSON-fallback retry attempts, independent of the HTTP retry counter. */
    public const MAX_JSON_ATTEMPTS = 3; // 1 initial + 2 fallback retries.

    /** @var int fixed number of items generated for SR (ordering) questions, unless overridden. */
    protected const DEFAULT_SR_ITEM_COUNT = 4;

    /**
     * Run the Claude call for one generation. On success, leaves the generation in
     * "validating" status - it is the caller's (process_pending_generations') job to then hand
     * It to {@see validate_questions_task::process()}, not this method's.
     *
     * @param \stdClass $generation the local_artqtml_generations record to process
     * @return void
     */
    public function process(\stdClass $generation): void {
        $generationid = (int) $generation->id;
        $userid = (int) $generation->userid;
        $this->log_event($generationid, 'processing_started', [], $userid);

        try {
            $settings = json_decode((string) $generation->settings, true);
            if (!is_array($settings)) {
                throw new \moodle_exception('errormissingsettings', 'local_artqtml');
            }

            $sourcetext = (string) $generation->sourcetext;
            if (source_text_limit::is_exceeded($sourcetext)) {
                $usage = source_text_limit::usage($sourcetext);
                $this->log_event($generationid, 'source_text_too_long', [
                    'estimatedtokens' => $usage['estimatedtokens'],
                    'tokenlimit'      => $usage['tokenlimit'],
                    'characters'      => $usage['characters'],
                ], $userid);

                throw new \moodle_exception('errorsourcetexttoolong', 'local_artqtml');
            }

            if (
                security_filter::has_sql_injection($sourcetext)
                || security_filter::has_prompt_injection($sourcetext)
            ) {
                $this->log_event($generationid, 'security_filter_blocked', [
                    'stage' => 'generate',
                ], $userid);
                generation_recover::to_started(
                    $generation,
                    get_string('errorgenerationunexpected', 'local_artqtml')
                );
                return;
            }

            // One call per question type, not one call for the generation.
            [$questions, $outcomes] = $this->call_claude_per_type($generation, $settings);

            // the calls above can take a long time - re-check the generation still exists
            // And hasn't been aborted/deleted while they were in flight before saving results.
            $generation = $this->reload_if_active($generationid, \local_artqtml\local\generation_status::GENERATING);
            if ($generation === null) {
                $this->log_event($generationid, 'processing_abandoned', [], $userid);
                return;
            }

            // Every type failed. There is nothing to validate and nothing to save, so this is a
            // Failure of the whole generation rather than a partial one - and the teacher gets the
            // Retry path that a failed generation has.
            if ($questions === []) {
                $messages = [];
                foreach ($outcomes as $code => $outcome) {
                    $messages[] = $code . ': ' . ($outcome['message'] ?? $outcome['result']);
                }
                throw new \moodle_exception(
                    'errorapirequest',
                    'local_artqtml',
                    '',
                    implode('; ', $messages) ?: get_string('errorapiresponse', 'local_artqtml')
                );
            }

            // Compare Claude's actual per-type output against what was requested.
            $this->store_count_discrepancy($generationid, $settings, $questions, $userid);

            $this->log_event($generationid, 'claude_call_completed', [
                'questioncount' => count($questions),
                'outcomes'      => $outcomes,
            ], $userid);

            $generation->pendingdata = json_encode(['questions' => $questions]);
            $this->set_status($generation, \local_artqtml\local\generation_status::VALIDATING);
        } catch (\Throwable $e) {
            debugging('local_artqtml: generation ' . $generationid . ' failed: ' . $e->getMessage(), DEBUG_NORMAL);
            $this->rollback($generationid, $e->getMessage(), $userid);
        }
    }

    /**
     * store count discrepancy.
     *
     * @param int $generationid
     * @param array $settings decoded settings JSON (requested counts)
     * @param array $questions raw question arrays as returned by Claude (before validation
     * This is about what Claude actually produced, independent of whether it later turns
     * Out to be semantically valid enough to import)
     * @param int $userid
     * @return void
     */
    protected function store_count_discrepancy(int $generationid, array $settings, array $questions, int $userid): void {
        global $DB;

        $requested = array_fill_keys(question_types::CODES, 0);
        foreach ($settings['counts'] ?? [] as $code => $count) {
            if (isset($requested[$code])) {
                $requested[$code] = (int) $count;
            }
        }

        $received = array_fill_keys(question_types::CODES, 0);
        foreach ($questions as $question) {
            $typecode = is_array($question) ? ($question['type'] ?? '') : '';
            if (isset($received[$typecode])) {
                $received[$typecode]++;
            }
        }

        $discrepancies = [];
        foreach (question_types::CODES as $code) {
            if ($requested[$code] !== $received[$code]) {
                $discrepancies[] = ['type' => $code, 'requested' => $requested[$code], 'received' => $received[$code]];
            }
        }

        if (empty($discrepancies)) {
            return;
        }

        $DB->set_field('local_artqtml_generations', 'countdiscrepancy', json_encode($discrepancies), ['id' => $generationid]);
        $this->log_event($generationid, 'question_count_discrepancy', ['discrepancies' => $discrepancies], $userid);
    }

    /**
     * rollback.
     *
     * @param int $generationid
     * @param string $errormessage
     * @param int|null $userid
     * @return void
     */
    protected function rollback(int $generationid, string $errormessage, ?int $userid = null): void {
        global $DB;

        $generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid]);
        if (!$generation) {
            return;
        }

        $transaction = $DB->start_delegated_transaction();
        if (!empty($generation->draftcategoryid)) {
            \local_artqtml\local\draft_bank::delete((int) $generation->draftcategoryid);
            $generation->draftcategoryid = null;
        }
        $DB->delete_records('local_artqtml_questions', ['generationid' => $generationid]);
        $transaction->allow_commit();

        $this->set_status($generation, \local_artqtml\local\generation_status::FAILED, $errormessage);
        $this->log_event($generationid, 'error', ['message' => $errormessage], $userid ?? (int) $generation->userid);
    }

    /**
     * Helper.
     *
     * @var string|null how the last call_claude() failed - 'transport' or 'content'.
     */
    protected $lastfailurekind = null;

    /**
     * Generate one question type at a time, and let the others through when one fails.
     *
     * @param \stdClass $generation
     * @param array $settings the generation's decoded settings
     * @return array{0: array, 1: array<string, array>} all questions, and the per-type outcome
     */
    protected function call_claude_per_type(\stdClass $generation, array $settings): array {
        global $DB;

        $userid = (int) $generation->userid;
        $questions = [];
        $outcomes = [];

        $requested = [];
        foreach (question_types::CODES as $code) {
            if ((int) ($settings['counts'][$code] ?? 0) > 0) {
                $requested[] = $code;
            }
        }

        $total = count($requested);
        $done = 0;

        $writeprogress = function (int $done, string $current) use ($DB, $generation, $total, &$outcomes): void {
            $DB->set_field('local_artqtml_generations', 'pendingdata', json_encode([
                'generating' => [
                    'done'     => $done,
                    'total'    => $total,
                    'current'  => $current,
                    'outcomes' => $outcomes,
                ],
            ]), ['id' => $generation->id]);
        };

        foreach ($requested as $code) {
            $this->lastfailurekind = null;
            $writeprogress($done, $code);

            try {
                $typequestions = $this->call_claude($generation, $this->settings_for_type($settings, $code));
                $questions = array_merge($questions, $typequestions);
                $outcomes[$code] = ['result' => 'ok', 'count' => count($typequestions)];
            } catch (\Throwable $e) {
                // Deliberately not rethrown: the remaining types are still worth generating, and
                // The teacher is told what is missing by the partly-successful outcome.
                $outcomes[$code] = [
                    'result'  => $this->lastfailurekind ?? 'transport',
                    'count'   => 0,
                    'message' => $e->getMessage(),
                ];
                $this->log_event($generation->id, 'type_generation_failed', [
                    'typecode' => $code,
                    'kind'     => $outcomes[$code]['result'],
                    'message'  => $e->getMessage(),
                ], $userid);
            }

            $done++;
            $writeprogress($done, $code);
        }

        return [$questions, $outcomes];
    }

    /**
     * A copy of the generation's settings narrowed to one question type.
     *
     * Two things are narrowed, and the second is the point of the exercise. The counts keep only
     * This type, so the response schema carries a single branch. The difficulty levels are taken
     * From **this type's row of the grid**, so a call asking for two easy True/False questions says
     * Exactly that - rather than "six questions, two of them easy, three of them True/False" and
     * Leaving the model to pair them up.
     *
     * A generation saved before the grid existed has no 'matrix'. Its per-type levels were never
     * Recorded and cannot be invented, so the generation-wide levels are passed through unchanged:
     * Such a run behaves exactly as it did before, one type at a time.
     *
     * @param array $settings the whole generation's settings
     * @param string $code one of question_types::CODES
     * @return array
     */
    protected function settings_for_type(array $settings, string $code): array {
        $one = $settings;

        foreach (question_types::CODES as $other) {
            $one['counts'][$other] = ($other === $code) ? (int) ($settings['counts'][$code] ?? 0) : 0;
        }

        $row = $settings['matrix'][$code] ?? null;
        if (is_array($row) && $row !== []) {
            foreach (['easy', 'medium', 'hard'] as $level) {
                if (array_key_exists($level, $row)) {
                    $one['difficulty']['scale'][$level] = (int) $row[$level];
                }
            }
        }

        return $one;
    }

    /**
     * Call the Claude API (with HTTP + JSON-fallback retry) to generate questions.
     *
     * @param \stdClass $generation the generation record
     * @param array $settings decoded settings JSON
     * @return array list of generated question arrays (raw, per )
     */
    protected function call_claude(\stdClass $generation, array $settings): array {
        $apikey = \local_artqtml\local\api_key_store::get('claude');
        $model = get_config('local_artqtml', 'claudemodel');
        $timeout = (int) (get_config('local_artqtml', 'apitimeout') ?: 60);
        $userid = (int) $generation->userid;

        if (empty($apikey) || empty($model)) {
            throw new \moodle_exception('errormissingapikey', 'local_artqtml');
        }

        $basesystem = $this->build_prompt($generation, $settings);
        $usercontent = $this->build_user_content($generation, $settings);
        $schema = question_schema::build($settings);

        $requestedcount = array_sum(array_map('intval', $settings['counts'] ?? []));

        $lasterror = '';
        for ($jsonattempt = 1; $jsonattempt <= self::MAX_JSON_ATTEMPTS; $jsonattempt++) {
            $system = $basesystem;
            if ($jsonattempt > 1) {
                $system .= "\n\n" . (string) get_config('local_artqtml', 'promptjsoninvalid');
            }

            // The endpoint, headers and envelope come from ai_request, which the model check's probe
            // Uses too - the probe building its own request is what produced a false site-wide block.
            $request = \local_artqtml\local\ai_request::claude(
                (string) $model,
                (string) $apikey,
                (int) (get_config('local_artqtml', 'generatorcontextwindow') ?: 8192),
                $system,
                $usercontent,
                $schema
            );

            $result = $this->http_with_backoff(function () use ($request, $timeout) {
                return \local_artqtml\local\ai_request::send($request, $timeout);
            }, (int) $generation->id, 'generate', 'claude', $userid);

            if ($result['curlerror'] !== '' || $result['httpcode'] !== 200) {
                $this->lastfailurekind = 'transport';
                $lasterror = $result['curlerror'] !== '' ? $result['curlerror'] : $this->extract_claude_error($result['body']);
                $this->log_ai_call($generation->id, 'generate', 'claude', [
                    'httpstatus'   => $result['httpcode'],
                    'jsonattempt'  => $jsonattempt,
                    'isretry'      => true,
                    'result'       => 'error',
                    'errormessage' => $lasterror,
                ], $userid);
                if ($this->is_retryable_http((int) $result['httpcode']) || $result['httpcode'] === 0) {
                    // HTTP-level retries are already exhausted inside http_with_backoff();
                    // A further JSON-attempt loop iteration would not help a pure HTTP failure.
                    break;
                }
                if ($this->is_nonretryable_client_error((int) $result['httpcode'])) {
                    break;
                }
                continue;
            }

            $decoded = json_decode((string) $result['body'], true);
            $text = ai_request::extract_text(model_list::PROVIDER_CLAUDE, $decoded);
            $parsed = is_string($text) ? json_decode($text, true) : null;
            $questions = (is_array($parsed) && is_array($parsed['questions'] ?? null)) ? $parsed['questions'] : [];

            $questions = array_map(
                static function ($question) {
                    return is_array($question) ? ai_text_cleaner::clean_question($question) : $question;
                },
                $questions
            );

            if (ai_request::hit_token_limit(model_list::PROVIDER_CLAUDE, $decoded)) {
                $this->log_ai_call($generation->id, 'generate', 'claude', [
                    'httpstatus'   => 200,
                    'tokensinput'  => $decoded['usage']['input_tokens'] ?? null,
                    'tokensoutput' => $decoded['usage']['output_tokens'] ?? null,
                    'jsonattempt'  => $jsonattempt,
                    'isretry'      => false,
                    'requestid'    => $decoded['id'] ?? null,
                    'result'       => 'success',
                ], $userid);
                // The process is not blocked - whatever questions did parse are kept.
                $this->store_token_limit_warning($generation->id, $requestedcount, count($questions), $userid);
                return $questions;
            }

            if (empty($questions)) {
                $this->lastfailurekind = $parsed === null ? 'transport' : 'content';
                $lasterror = get_string('errorapiresponse', 'local_artqtml');
                $this->log_ai_call($generation->id, 'generate', 'claude', [
                    'httpstatus'   => 200,
                    'tokensinput'  => $decoded['usage']['input_tokens'] ?? null,
                    'tokensoutput' => $decoded['usage']['output_tokens'] ?? null,
                    'jsonattempt'  => $jsonattempt,
                    'isretry'      => true,
                    'requestid'    => $decoded['id'] ?? null,
                    'result'       => 'error',
                    'errormessage' => $lasterror,
                ], $userid);
                if ($this->lastfailurekind === 'content') {
                    break;
                }
                continue;
            }

            // Log usage for the attempt that produced a usable result, regardless of whether it took more than one try.
            $this->log_ai_call($generation->id, 'generate', 'claude', [
                'httpstatus'   => 200,
                'tokensinput'  => $decoded['usage']['input_tokens'] ?? null,
                'tokensoutput' => $decoded['usage']['output_tokens'] ?? null,
                'jsonattempt'  => $jsonattempt,
                'isretry'      => false,
                'requestid'    => $decoded['id'] ?? null,
                'result'       => 'success',
            ], $userid);

            $this->lastfailurekind = null;

            return $questions;
        }

        throw new \moodle_exception('errorapirequest', 'local_artqtml', '', $lasterror);
    }

    /**
     * Extract the technical error.message from a Claude error response body (3.5).
     *
     * @param string $body
     * @return string
     */
    protected function extract_claude_error(string $body): string {
        $decoded = json_decode($body, true);
        return (string) ($decoded['error']['message'] ?? $body);
    }

    /**
     * store token limit warning.
     *
     * @param int $generationid
     * @param int $requested
     * @param int $actual
     * @param int|null $userid
     * @return void
     */
    protected function store_token_limit_warning(int $generationid, int $requested, int $actual, ?int $userid = null): void {
        $this->log_event($generationid, 'token_limit_warning', [
            'stage'     => 'generate',
            'requested' => $requested,
            'actual'    => $actual,
        ], $userid);
    }

    /**
     * Build the untrusted user message payload for Claude (source text only).
     *
     * @param \stdClass $generation
     * @param array $settings decoded settings JSON (unused; kept for call-site parity)
     * @return string JSON body for the user turn
     */
    protected function build_user_content(\stdClass $generation, array $settings): string {
        unset($settings);

        return (string) json_encode(
            [
                'content_type' => 'untrusted_generation_input',
                'source_text'  => (string) $generation->sourcetext,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * Build the Claude system prompt.
     *
     * @param \stdClass $generation
     * @param array $settings decoded settings JSON
     * @return string
     */
    protected function build_prompt(\stdClass $generation, array $settings): string {
        unset($generation);
        $template = (string) get_config('local_artqtml', 'generatorprompttemplate');

        $counts = [];
        foreach ($settings['counts'] ?? [] as $code => $count) {
            if ((int) $count > 0) {
                $counts[] = (int) $count . ' x ' . question_types::label($code) . " ($code)";
            }
        }

        // Knowledge source fragment is always the source-only template.
        $knowledgesourcetext = (string) get_config('local_artqtml', 'promptknowledgesourceonly');

        $typeinstructions = [];
        $rawcounts = $settings['counts'] ?? [];

        $nosourcemeta = trim((string) (get_config('local_artqtml', 'promptnosourcemetaref') ?: ''));
        if ($nosourcemeta !== '') {
            $typeinstructions[] = $nosourcemeta;
        }

        if ((int) ($rawcounts['FE'] ?? 0) > 0) {
            $typeinstructions[] = strtr((string) get_config('local_artqtml', 'promptoptioncount'), [
                '{{OPTION_MIN}}' => (string) (int) (get_config('local_artqtml', 'fefminoptions') ?: 2),
                '{{OPTION_MAX}}' => (string) (int) (get_config('local_artqtml', 'fefmaxoptions') ?: 5),
            ]);
        }
        if ((int) ($rawcounts['SR'] ?? 0) > 0) {
            $sritemcount = (int) ($settings['types']['SR']['sritemcount'] ?? 0);
            if ($sritemcount <= 0) {
                $sritemcount = (int) (get_config('local_artqtml', 'sritemcount') ?: self::DEFAULT_SR_ITEM_COUNT);
            }
            $typeinstructions[] = strtr((string) get_config('local_artqtml', 'promptitemcount'), [
                '{{SR_ITEM_COUNT}}' => (string) $sritemcount,
            ]);
        }

        $feedbacktemplatesettings = [
            'SR' => ['correct' => 'feedback_sr_correct', 'incorrect' => 'feedback_sr_incorrect'],
        ];
        foreach ($feedbacktemplatesettings as $code => $settingkeys) {
            if ((int) ($rawcounts[$code] ?? 0) <= 0) {
                continue;
            }
            foreach ($settingkeys as $outcome => $settingkey) {
                $feedbacktext = trim((string) (get_config('local_artqtml', $settingkey) ?: ''));
                if ($feedbacktext === '') {
                    continue;
                }
                $fragment = $outcome === 'correct' ? 'promptfeedbackcorrect' : 'promptfeedbackincorrect';
                $typeinstructions[] = strtr((string) get_config('local_artqtml', $fragment), [
                    '{{TYPE}}'     => $code,
                    '{{FEEDBACK}}' => $feedbacktext,
                ]);
            }
        }

        foreach ($settings['types'] ?? [] as $code => $typesetting) {
            if ((int) ($rawcounts[$code] ?? 0) <= 0 || empty($typesetting['explanationenabled'])) {
                continue;
            }
            $explanationfragment = trim((string) (get_config('local_artqtml', 'promptoptionexplanation') ?: ''));

            if ($code === 'IH') {
                $truefalseclause = trim(
                    (string) (get_config('local_artqtml', 'promptoptionexplanationtruefalse') ?: '')
                );
                if ($truefalseclause !== '') {
                    $explanationfragment = trim($explanationfragment . "\n" . $truefalseclause);
                }
            }

            if ($explanationfragment !== '') {
                $typeinstructions[] = 'For ' . $code . ' questions: ' . $explanationfragment;
            }
        }

        foreach (question_types::CODES as $code) {
            if ((int) ($rawcounts[$code] ?? 0) <= 0) {
                continue;
            }
            $default = trim((string) (get_config('local_artqtml', 'instructiondefault_' . strtolower($code)) ?: ''));
            if ($default !== '') {
                $typeinstructions[] = "$code: " . $default;
            }
        }

        $replacements = [
            '{{QUESTION_COUNTS}}'      => implode(', ', $counts),
            '{{DIFFICULTY_MODE}}'      => $this->describe_difficulty($settings['difficulty'] ?? []),
            '{{KNOWLEDGE_SOURCE}}'     => $knowledgesourcetext,
            '{{NEGATION_INSTRUCTION}}' => !empty($settings['negationhighlight'])
                ? (string) get_config('local_artqtml', 'promptnegation')
                : '',
            '{{TYPE_INSTRUCTIONS}}'    => implode("\n", $typeinstructions),
        ];

        return strtr($template, $replacements);
    }

    /**
     * Turn the stored difficulty settings into a human-readable description for the prompt.
     *
     * @param array $difficulty decoded difficulty settings
     * @return string
     */
    protected function describe_difficulty(array $difficulty): string {
        return \local_artqtml\local\difficulty_prompt::describe($difficulty);
    }
}
