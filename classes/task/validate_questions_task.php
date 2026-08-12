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
 * Calls the Gemini API to validate AI-generated quiz questions (functional spec ch.6, technical
 * annex ch.4).
 *
 * Invoked from {@see process_pending_generations}, the scheduled task that actually runs the
 * AI pipeline in the background - see that class and {@see generate_questions_task} for why
 * this is a plain processor rather than an adhoc/scheduled task itself.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\task;

use local_artqtml\local\ai_request;
use local_artqtml\local\generation_recover;
use local_artqtml\local\model_list;
use local_artqtml\local\security_filter;

/**
 * Validates the not-yet-evaluated questions of a single generation via Gemini.
 */
class validate_questions_task {
    use generation_status_trait;
    use retry_trait;

    /** @var int maximum JSON-fallback retry attempts (1 initial + 2 fallback, per 2.3). */
    public const MAX_JSON_ATTEMPTS = 3;

    // Both response-schema enums are defined once each, outside this class, and read from there by
    // the schema AND the prompt so the two value sets can never drift apart - do not re-list
    // either of them here:
    // - the three suggestions (Val-017) in {@see \local_artqtml\local\validation_suggestion};
    // - the four problem categories (Val-019/Val-028) in
    // {@see \local_artqtml\local\problem_category}.

    /**
     * Run the Gemini validation call(s) for one generation's not-yet-evaluated questions.
     *
     * M-15: works entirely from the raw Claude output held in $generation->pendingdata (not
     * local_artqtml_questions - nothing has been written there yet). Evaluations are matched
     * against a pseudo-id (each raw question's array index) rather than a real questionbankid,
     * merged into an in-memory map, and persisted back into pendingdata after every batch (same
     * per-batch durability the old design got from writing straight to the DB row) - it is the
     * new saving stage's job to actually create the real questions and local_artqtml_questions
     * rows, combining them with these evaluations in one transaction.
     *
     * @param \stdClass $generation the local_artqtml_generations record to validate
     * @return void
     */
    public function process(\stdClass $generation): void {
        global $DB;

        $generationid = (int) $generation->id;
        $userid = (int) $generation->userid;
        $this->log_event($generationid, 'validation_started', [], $userid);

        try {
            $pending = json_decode((string) $generation->pendingdata, true);
            if (!is_array($pending) || !is_array($pending['questions'] ?? null)) {
                throw new \moodle_exception('errormissingsettings', 'local_artqtml');
            }

            // Finding #5: intentional defense-in-depth — sourcetext is re-read from the DB and
            // embedded in the Gemini prompt. Generate should already have blocked poisoned source,
            // but patterns / the stored text can still change between stages; refuse before spend.
            $fresh = $DB->get_record('local_artqtml_generations', ['id' => $generationid], '*', MUST_EXIST);
            $sourcetext = (string) $fresh->sourcetext;
            $generation->sourcetext = $sourcetext;
            if (
                security_filter::has_sql_injection($sourcetext)
                || security_filter::has_prompt_injection($sourcetext)
            ) {
                $this->log_event($generationid, 'security_filter_blocked', [
                    'stage' => 'validate',
                ], $userid);
                generation_recover::to_started(
                    $fresh,
                    get_string('errorgenerationunexpected', 'local_artqtml')
                );
                return;
            }

            $rawquestions = $pending['questions'];
            $evaluations = is_array($pending['evaluations'] ?? null) ? $pending['evaluations'] : [];

            $pseudoquestions = $this->build_pseudo_questions($rawquestions);
            $unevaluated = array_diff_key($pseudoquestions, $evaluations);

            foreach ($this->build_batches($generation, $unevaluated) as $batch) {
                $results = $this->call_gemini($generation, $batch);

                // C-03: each Gemini call can take a while, and a generation may have several
                // batches - re-check before saving every single batch's results, not just once
                // up front, so an abort/delete mid-run is caught before the very next write.
                $generation = $this->reload_if_active($generationid, \local_artqtml\local\generation_status::VALIDATING);
                if ($generation === null) {
                    $this->log_event($generationid, 'processing_abandoned', [], $userid);
                    return;
                }

                $evaluations = $this->merge_results($evaluations, $batch, $results);

                // Persisted after every batch, matching the old design's per-batch durability.
                $DB->set_field(
                    'local_artqtml_generations',
                    'pendingdata',
                    json_encode(['questions' => $rawquestions, 'evaluations' => $evaluations]),
                    ['id' => $generationid]
                );
            }

            $generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid]);
            $generation->pendingdata = json_encode(['questions' => $rawquestions, 'evaluations' => $evaluations]);
            $this->set_status($generation, \local_artqtml\local\generation_status::SAVING);
            $this->log_event($generationid, 'validation_completed', ['questioncount' => count($pseudoquestions)], $userid);
        } catch (\Throwable $e) {
            debugging(
                'local_artqtml: validation for generation ' . $generationid . ' failed: ' . $e->getMessage(),
                DEBUG_NORMAL
            );
            $this->log_event($generationid, 'error', ['message' => $e->getMessage()], $userid);

            $generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid]);
            if ($generation) {
                // Val-011: unlike generation failures, a validation failure keeps whatever
                // evaluations were already gathered in pendingdata rather than losing them
                // outright - the retry button (status.php) is what fully clears pendingdata for
                // a clean restart from "generating".
                $this->set_status($generation, \local_artqtml\local\generation_status::FAILED, $e->getMessage());
            }
        }
    }

    /**
     * Wrap each raw Claude question array in a pseudo-record carrying the fields
     * {@see self::build_batches()}/{@see self::build_prompt()}/{@see self::merge_results()}
     * need, keyed by its index in the original Claude response (used as a temporary id for
     * matching Gemini's evaluations, since no real question rows exist yet at this stage).
     *
     * @param array $rawquestions raw question arrays as returned by Claude
     * @return array<int, \stdClass>
     */
    protected function build_pseudo_questions(array $rawquestions): array {
        $pseudo = [];
        foreach ($rawquestions as $index => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $question = new \stdClass();
            $question->id = $index;
            $question->typecode = (string) ($raw['type'] ?? '');
            $question->questiontext = (string) ($raw['questiontext'] ?? '');
            $question->questiondata = json_encode($raw);
            $pseudo[$index] = $question;
        }

        return $pseudo;
    }

    /**
     * Split questions into batches so each request stays under 80% of the validator's
     * configured context window (Val-003/Val-024), keeping the full source text in every batch.
     *
     * @param \stdClass $generation
     * @param \stdClass[] $questions pseudo-question records keyed by pseudo-id (M-15)
     * @return array<int, \stdClass[]> list of batches
     */
    protected function build_batches(\stdClass $generation, array $questions): array {
        $contextwindow = (int) (get_config('local_artqtml', 'validatorcontextwindow') ?: 1000000);
        $budget = (int) ($contextwindow * 0.8);

        $sourcetokens = (int) (\core_text::strlen((string) $generation->sourcetext) / 4);
        $availablefortyquestions = max($budget - $sourcetokens, 1000);

        $batches = [];
        $current = [];
        $currenttokens = 0;
        foreach ($questions as $question) {
            $questiontokens = (int) (\core_text::strlen((string) $question->questiontext . $question->questiondata) / 4) + 100;
            if ($current && ($currenttokens + $questiontokens) > $availablefortyquestions) {
                $batches[] = $current;
                $current = [];
                $currenttokens = 0;
            }
            $current[$question->id] = $question;
            $currenttokens += $questiontokens;
        }
        if ($current) {
            $batches[] = $current;
        }

        return $batches;
    }

    /**
     * Call the Gemini API (with HTTP + JSON-fallback retry) to validate a batch of questions.
     *
     * @param \stdClass $generation the generation record (used for source text context)
     * @param \stdClass[] $questions batch of pseudo-question records, keyed by pseudo-id
     * @return array list of evaluation arrays, each with question_id/suggestion/etc
     */
    protected function call_gemini(\stdClass $generation, array $questions): array {
        $apikey = \local_artqtml\local\api_key_store::get('gemini');
        $model = get_config('local_artqtml', 'geminimodel');
        $timeout = (int) (get_config('local_artqtml', 'apitimeout') ?: 60);
        $userid = (int) $generation->userid;

        if (empty($apikey) || empty($model)) {
            throw new \moodle_exception('errormissinggeminikey', 'local_artqtml');
        }

        $basesystem = $this->build_system_instruction($generation);
        $prompt = $this->build_prompt($generation, $questions);
        $schema = $this->build_schema();

        $lasterror = '';
        for ($jsonattempt = 1; $jsonattempt <= self::MAX_JSON_ATTEMPTS; $jsonattempt++) {
            // Val-008: a JSON-fallback retry must actually tell the model its previous
            // response was invalid, not just resend an identical request.
            //
            // Added before the request is built, for the reason spelled out in
            // generate_questions_task::call_claude(): the notice is admin-editable and the security
            // guard is not, so the notice must not end up after the guard (2026-08-05, BL-52).
            $system = $basesystem;
            if ($jsonattempt > 1) {
                $system .= "\n\n" . (string) get_config('local_artqtml', 'promptjsoninvalid');
            }

            // Same single source as the generator and the model check's probe - see ai_request.
            $request = \local_artqtml\local\ai_request::gemini(
                (string) $model,
                (string) $apikey,
                $system,
                $prompt,
                $schema
            );

            $result = $this->http_with_backoff(function () use ($request, $timeout) {
                return \local_artqtml\local\ai_request::send($request, $timeout);
            }, (int) $generation->id, 'validate', 'gemini', $userid);

            if ($result['curlerror'] !== '' || $result['httpcode'] !== 200) {
                $lasterror = $result['curlerror'] !== '' ? $result['curlerror'] : $this->extract_gemini_error($result['body']);
                // Val-009: a failed attempt's tokens (there usually aren't any billable ones on
                // an HTTP-level failure anyway) never count toward the monthly budget.
                $this->log_ai_call($generation->id, 'validate', 'gemini', [
                    'httpstatus'   => $result['httpcode'],
                    'jsonattempt'  => $jsonattempt,
                    'isretry'      => true,
                    'result'       => 'error',
                    'errormessage' => $lasterror,
                ], $userid);
                if ($this->is_retryable_http((int) $result['httpcode']) || $result['httpcode'] === 0) {
                    break;
                }
                if ($this->is_nonretryable_client_error((int) $result['httpcode'])) {
                    // M-11: a 4xx (other than 429) is a bad request, not a JSON-formatting
                    // problem - retrying via the JSON-fallback loop would just resend the exact
                    // same broken request up to MAX_JSON_ATTEMPTS times.
                    break;
                }
                continue;
            }

            $decoded = json_decode((string) $result['body'], true);
            // BL-44: see the note on ai_request::extract_text - one place for where the text sits.
            $text = ai_request::extract_text(model_list::PROVIDER_GEMINI, $decoded);
            $parsed = is_string($text) ? json_decode($text, true) : null;
            $evaluations = (is_array($parsed) && is_array($parsed['evaluations'] ?? null)) ? $parsed['evaluations'] : [];

            // Val-014/015/016: a truncated response must be detected independently of whether
            // it still happens to parse as valid JSON - checking finishReason only after a
            // successful parse would miss the common case where truncation itself breaks the
            // JSON, routing it into the generic invalid-JSON retry below instead.
            if (ai_request::hit_token_limit(model_list::PROVIDER_GEMINI, $decoded)) {
                $this->log_ai_call($generation->id, 'validate', 'gemini', [
                    'httpstatus'   => 200,
                    'tokensinput'  => $decoded['usageMetadata']['promptTokenCount'] ?? null,
                    'tokensoutput' => $decoded['usageMetadata']['candidatesTokenCount'] ?? null,
                    'jsonattempt'  => $jsonattempt,
                    'isretry'      => false,
                    'result'       => 'success',
                ], $userid);
                // Val-016: the process is not blocked - whatever evaluations did parse are kept,
                // and any question left unmatched simply stays not_evaluated (Val-013).
                $this->store_token_limit_warning(
                    $generation->id,
                    max(count($questions) - count($evaluations), 0),
                    $userid
                );
                return $evaluations;
            }

            if (empty($evaluations)) {
                $lasterror = get_string('errorgeminiresponse', 'local_artqtml');
                $this->log_ai_call($generation->id, 'validate', 'gemini', [
                    'httpstatus'   => 200,
                    'tokensinput'  => $decoded['usageMetadata']['promptTokenCount'] ?? null,
                    'tokensoutput' => $decoded['usageMetadata']['candidatesTokenCount'] ?? null,
                    'jsonattempt'  => $jsonattempt,
                    'isretry'      => true,
                    'result'       => 'error',
                    'errormessage' => $lasterror,
                ], $userid);
                continue;
            }

            // Val-009: log usage for the attempt that produced a usable result,
            // regardless of whether it took more than one try.
            $this->log_ai_call($generation->id, 'validate', 'gemini', [
                'httpstatus'   => 200,
                'tokensinput'  => $decoded['usageMetadata']['promptTokenCount'] ?? null,
                'tokensoutput' => $decoded['usageMetadata']['candidatesTokenCount'] ?? null,
                'jsonattempt'  => $jsonattempt,
                'isretry'      => false,
                'result'       => 'success',
            ], $userid);

            return $evaluations;
        }

        throw new \moodle_exception('errorgeminirequest', 'local_artqtml', '', $lasterror);
    }

    /**
     * Extract the technical error.message from a Gemini error response body (4.5).
     *
     * @param string $body
     * @return string
     */
    protected function extract_gemini_error(string $body): string {
        $decoded = json_decode($body, true);
        return (string) ($decoded['error']['message'] ?? $body);
    }

    /**
     * Record a non-blocking token-limit warning, including the affected question count (Val-015).
     *
     * @param int $generationid
     * @param int $affectedcount
     * @param int|null $userid
     * @return void
     */
    protected function store_token_limit_warning(int $generationid, int $affectedcount, ?int $userid = null): void {
        $this->log_event($generationid, 'token_limit_warning', ['stage' => 'validate', 'affected' => $affectedcount], $userid);
    }

    /**
     * Build the Gemini system instruction.
     *
     * Admin-066/067: every word comes from admin settings. This method substitutes values and
     * decides which optional clauses apply; it holds no prompt text and reads none from the lang
     * packs.
     *
     * Two of the substituted values are not text but data: the suggestion and problem_category
     * value lists come from the same constants the response schema is built from. An administrator
     * can rewrite the sentence around them - and can delete the placeholder, which is the accepted
     * cost of a prompt they can read - but cannot make the prompt name a value the schema does not
     * accept, which is the drift that put the two out of step once before.
     *
     * @return string
     */
    protected function build_system_instruction(\stdClass $generation): string {
        $template = (string) get_config('local_artqtml', 'validatorprompttemplate');

        $suggestion = strtr((string) get_config('local_artqtml', 'validationpromptsuggestion'), [
            '{{SUGGESTION_VALUES}}' => implode(', ', \local_artqtml\local\validation_suggestion::VALUES),
        ]);
        $category = strtr((string) get_config('local_artqtml', 'validationpromptcategory'), [
            '{{PROBLEM_CATEGORIES}}' => implode(', ', \local_artqtml\local\problem_category::VALUES),
        ]);

        // Val-031: the level definitions the generator was given for THIS generation's scale.
        $definitions = \local_artqtml\local\difficulty_prompt::for_generation($generation);

        $difficulty = trim($definitions) === ''
            ? ''
            : strtr((string) get_config('local_artqtml', 'validationpromptdifficulty'), [
                '{{DIFFICULTY_DEFINITIONS}}' => $definitions,
            ]);

        return strtr($template, [
            '{{SUGGESTION_INSTRUCTION}}' => $suggestion,
            '{{CATEGORY_INSTRUCTION}}'   => $category,
            '{{LANGUAGE_INSTRUCTION}}'   => (string) get_config('local_artqtml', 'validationpromptlanguage'),
            '{{DIFFICULTY_INSTRUCTION}}' => $difficulty,
            '{{WORDING_INSTRUCTION}}'    => (string) get_config('local_artqtml', 'validationpromptwording'),
            '{{ITEMSOURCE_INSTRUCTION}}' => (string) get_config('local_artqtml', 'validationpromptitemsource'),
        ]);
    }

    /**
     * Build the validation user-message prompt for a batch of questions (Val-001/Val-004:
     * always includes the full source text).
     *
     * Structured JSON since 2026-08-04, for the same reason as the generator's user message.
     * The concatenated form this replaced had a second weakness the generator's did not: it built
     * `question_id:` / `questiontext:` / `questiondata:` labels out of prose, so a question text
     * containing those very words could invent an extra question, or re-label an existing one,
     * simply by being echoed back into the prompt. The questions here are AI output that has
     * already passed through a teacher's uploaded material once - the least trustworthy input in
     * the pipeline, and the one previously formatted most loosely.
     *
     * In JSON a question's text is one string field: whatever it contains stays inside it and
     * cannot create a sibling key. `content_type` and `task` are fixed strings, never derived
     * from data - the instruction the validator follows is not something a question can rewrite.
     *
     * The question ids are cast to string deliberately: the validator matches its answers back by
     * exact string id, and that matching is what the surrounding code and its tests rely on.
     *
     * @param \stdClass $generation the generation record (used for source text context)
     * @param \stdClass[] $questions batch of local_artqtml_questions records
     * @return string a JSON object as the user message
     */
    protected function build_prompt(\stdClass $generation, array $questions): string {
        $items = [];
        foreach ($questions as $question) {
            // The questiondata field is stored as JSON. Decoding it keeps the structure visible to the
            // validator instead of handing it a string containing braces; if it will not decode,
            // the raw value is passed through so the validator can still name the broken question
            // rather than the batch failing on it.
            $decoded = json_decode((string) $question->questiondata, true);

            $items[] = [
                'question_id'  => (string) $question->id,
                'type'         => (string) $question->typecode,
                'questiontext' => (string) $question->questiontext,
                'questiondata' => is_array($decoded) ? $decoded : (string) $question->questiondata,
            ];
        }

        return (string) json_encode(
            [
                'content_type'           => 'untrusted_validation_input',
                'source_text'            => (string) $generation->sourcetext,
                'questions_to_evaluate'  => $items,
                'task'                   => 'Return exactly one evaluation per question, matched by question_id.',
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * Build the Gemini responseSchema for the validation results (4.3).
     *
     * Note: Gemini's response_schema uses upper-case type names (OBJECT/STRING/ARRAY/...),
     * unlike the usual lower-case JSON Schema convention used for the Claude schema.
     *
     * @return array
     */
    protected function build_schema(): array {
        // Gen-028/Val-022-024: structured quality checks for the hint(s)/general feedback a
        // question may carry (question_schema.php's hint1/hint2/generalfeedback, Gen-023/024/
        // M-25) - required unconditionally, same reasoning as problem_category above: a question
        // with hints/feedback disabled or a type with no hint support at all still gets a
        // (all-false) answer rather than an omitted one, so the schema doesn't need to vary by
        // per-generation settings Gemini has no other visibility into.
        $hintquality = [
            'type' => 'OBJECT',
            'description' => 'Quality check for this question\'s hint1/hint2, if the questiondata '
                . 'JSON includes them. If there are no hints (e.g. the type doesn\'t support them, '
                . 'or they were left blank), report all three as false.',
            'properties' => [
                'relevance'      => ['type' => 'BOOLEAN', 'description' => 'The hint(s) are actually relevant to this question.'],
                'is_progressive' => [
                    'type'        => 'BOOLEAN',
                    'description' => 'hint2 is more specific than hint1 (a real progression), not just a repeat.',
                ],
                'reveals_answer' => [
                    'type'        => 'BOOLEAN',
                    'description' => 'Either hint effectively gives the correct answer away directly.',
                ],
            ],
            'required' => ['relevance', 'is_progressive', 'reveals_answer'],
        ];
        $feedbackquality = [
            'type' => 'OBJECT',
            'description' => 'Quality check for this question\'s generalfeedback, if the '
                . 'questiondata JSON includes it. If there is none, report all three as false.',
            'properties' => [
                'relevant'       => ['type' => 'BOOLEAN', 'description' => 'The feedback is actually relevant to this question.'],
                'misleading'     => [
                    'type'        => 'BOOLEAN',
                    'description' => 'The feedback is factually wrong or contradicts the source text.',
                ],
                'reveals_answer' => [
                    'type'        => 'BOOLEAN',
                    'description'  => 'The feedback gives away the correct answer to a student who has not yet '
                        . 'answered (it should only ever be read after attempting/answering).',
                ],
            ],
            'required' => ['relevant', 'misleading', 'reveals_answer'],
        ];

        return [
            'type' => 'OBJECT',
            'properties' => [
                'evaluations' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'question_id'      => ['type' => 'STRING'],
                            // Val-017: exactly the three verdicts, read from the single source of
                            // truth that build_system_instruction() also names in the prompt.
                            'suggestion'       => [
                                'type' => 'STRING',
                                'enum' => \local_artqtml\local\validation_suggestion::VALUES,
                            ],
                            // Val-019/Val-028: exactly the four fixed keys, none of them an empty
                            // string. An acceptable question reports 'ok' (the "no problem" value),
                            // not "" - the latter is not a permitted Gemini enum value and made the
                            // whole validation call fail schema validation. Kept in 'required'
                            // below (safe now that 'ok' is a valid, always-available member).
                            'problem_category' => ['type' => 'STRING', 'enum' => \local_artqtml\local\problem_category::VALUES],
                            'justification'    => ['type' => 'STRING'],
                            // D2: constrain confidence to a 0-100 percentage in the schema itself
                            // (merge_results still clamps defensively as a backstop).
                            'confidence'       => ['type' => 'INTEGER', 'minimum' => 0, 'maximum' => 100],
                            'hint_quality'     => $hintquality,
                            'feedback_quality' => $feedbackquality,
                        ],
                        'required' => [
                            'question_id', 'suggestion', 'problem_category', 'justification',
                            'confidence', 'hint_quality', 'feedback_quality',
                        ],
                    ],
                ],
            ],
            'required' => ['evaluations'],
        ];
    }

    /**
     * Merge Gemini validation results into the in-memory evaluations map, matched by
     * question_id (Val-012, the pseudo-id from {@see self::build_pseudo_questions()}). Any
     * question without a matching evaluation is simply left out of the map, same as
     * "not_evaluated" (Val-013) meant under the old design.
     *
     * @param array $evaluations the running map (pseudo-id => evaluation fields) to merge into
     * @param \stdClass[] $batch pseudo-question records keyed by pseudo-id
     * @param array $results list of evaluation arrays from the Gemini response
     * @return array the updated evaluations map
     */
    protected function merge_results(array $evaluations, array $batch, array $results): array {
        foreach ($batch as $pseudoid => $question) {
            // Security: an exact string comparison, not an (int) cast - a cast would silently
            // truncate/coerce a malformed or unexpected question_id (e.g. "5abc", " 5") into
            // matching a real pseudo-id it was never actually equal to, letting a malformed or
            // hallucinated Gemini response get silently (and wrongly) applied to a real question.
            $expectedid = (string) $pseudoid;
            $evaluation = null;
            foreach ($results as $candidate) {
                if (!is_array($candidate) || !isset($candidate['question_id'])) {
                    continue;
                }
                $candidateid = $candidate['question_id'];
                if (is_scalar($candidateid) && (string) $candidateid === $expectedid) {
                    $evaluation = $candidate;
                    break;
                }
            }

            if ($evaluation === null) {
                continue;
            }

            // Val-017: a missing/hallucinated verdict falls back to needs_review, so a question the
            // validator did not clearly accept always reaches a teacher.
            $suggestion = \local_artqtml\local\validation_suggestion::normalise(
                $evaluation['suggestion'] ?? null,
                \local_artqtml\local\validation_suggestion::NEEDS_REVIEW
            );
            // Val-019: the field is required and never empty. A missing/hallucinated category
            // falls back to 'ok' for an accepted question (no problem) and 'other' otherwise.
            $category = \local_artqtml\local\problem_category::normalise(
                (string) ($evaluation['problem_category'] ?? ''),
                $suggestion === \local_artqtml\local\validation_suggestion::ACCEPTED
                    ? \local_artqtml\local\problem_category::OK
                    : 'other'
            );

            // Gen-028/Val-022-024: a hint or the general feedback giving the answer away
            // outright is a real defect regardless of what Gemini's own top-level suggestion
            // said - forced to needs_review so a teacher reviews it rather than an "accepted"
            // question silently reaching students with a hint/feedback that spoils the answer.
            $hintquality = is_array($evaluation['hint_quality'] ?? null) ? $evaluation['hint_quality'] : [];
            $feedbackquality = is_array($evaluation['feedback_quality'] ?? null) ? $evaluation['feedback_quality'] : [];
            if (!empty($hintquality['reveals_answer']) || !empty($feedbackquality['reveals_answer'])) {
                $suggestion = \local_artqtml\local\validation_suggestion::NEEDS_REVIEW;
            }

            // Cursor audit v3 #6: a non-progressive hint pair, or feedback Gemini judged
            // factually misleading, are also real defects worth a teacher's review.
            //
            // is_progressive needs one extra guard build_schema()'s own hint_quality docblock
            // doesn't need: "no hint at all" is reported as is_progressive=false too (the same
            // value as "there IS a hint but it doesn't progress"), so this only counts against a
            // question that actually has hint content - otherwise every question with hints
            // simply turned off would wrongly land in needs_review. misleading has no such
            // ambiguity (a question with no feedback is reported as misleading=false, not true),
            // so it needs no equivalent guard.
            $rawquestiondata = json_decode((string) ($question->questiondata ?? ''), true);
            $hashint = is_array($rawquestiondata) && (
                trim((string) ($rawquestiondata['hint1'] ?? '')) !== '' ||
                trim((string) ($rawquestiondata['hint2'] ?? '')) !== ''
            );
            if ($hashint && array_key_exists('is_progressive', $hintquality) && $hintquality['is_progressive'] === false) {
                $suggestion = \local_artqtml\local\validation_suggestion::NEEDS_REVIEW;
            }
            // C3: a hint Gemini judged irrelevant to the question is a real defect too. Guarded
            // by $hashint for the same reason as is_progressive: "no hint at all" is also reported
            // as relevance=false, so without the guard every question with hints simply turned off
            // would wrongly land in needs_review.
            if ($hashint && array_key_exists('relevance', $hintquality) && $hintquality['relevance'] === false) {
                $suggestion = \local_artqtml\local\validation_suggestion::NEEDS_REVIEW;
            }
            if (!empty($feedbackquality['misleading'])) {
                $suggestion = \local_artqtml\local\validation_suggestion::NEEDS_REVIEW;
            }
            // C3: general feedback Gemini judged irrelevant is a real defect as well. Guarded by
            // $hasfeedback: the schema reports relevant=false when there is no feedback at all, so
            // without the guard every question without general feedback would wrongly land in
            // needs_review.
            $hasfeedback = is_array($rawquestiondata)
                && trim((string) ($rawquestiondata['generalfeedback'] ?? '')) !== '';
            if ($hasfeedback && array_key_exists('relevant', $feedbackquality) && $feedbackquality['relevant'] === false) {
                $suggestion = \local_artqtml\local\validation_suggestion::NEEDS_REVIEW;
            }

            // Val-018: Gemini's own schema constrains this to an integer, but not to a 0-100
            // range - clamp defensively before it ever reaches the DB or the UI's progress bar.
            $confidence = max(0, min(100, (int) ($evaluation['confidence'] ?? 0)));

            $evaluations[$pseudoid] = [
                'validationsuggestion' => $suggestion,
                'problemcategory'      => $category,
                'justification'        => (string) ($evaluation['justification'] ?? ''),
                'confidence'           => $confidence,
                // The complete raw Gemini response for this question, exactly as returned
                // (unlike the normalised/whitelisted fields above) - save_questions_task stores
                // this verbatim in local_artqtml_questions.validationdata.
                'validationdata'       => $evaluation,
            ];
        }

        return $evaluations;
    }
}
