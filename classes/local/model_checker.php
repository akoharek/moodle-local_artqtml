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
 * The model check: availability and structure (Admin-052, Admin-053, Admin-059, Admin-060).
 *
 * Admin-052 is emphatic that appearing in the model list is NOT proof of availability: "bizonyítottan
 * előfordul, hogy a végpont továbbra is listáz olyan modellt, amelyre a generálási hívás »no longer
 * available« hibát ad". gemini-2.0-flash did exactly this - still listed, every generation failing.
 * So the list is at most a supporting signal and the live probe is what settles it.
 *
 * Admin-059/060: the probe must not run through the normal generation or validation path, because
 * it must neither advance the question-count licence counter nor be booked against the user token
 * budget. It therefore carries its own minimal payload rather than calling
 * generate_questions_task / validate_questions_task, both of which do that accounting - but it
 * builds that payload with {@see ai_request}, exactly as they do. Admin-053 requires the probe to
 * call "a saját sémájával", and a probe that assembles its own request tests a shape production
 * never sends: this one previously omitted the beta header the generator sent, and the 400 it got
 * back blocked the whole site on a working configuration.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Runs the availability and structure checks for one or both providers.
 */
class model_checker {
    /**
     * Check both providers.
     *
     * @param string $trigger model_check_log::TRIGGER_*
     * @return array<string, array{success: bool, messages: string[]}> keyed by provider
     */
    public static function check_all(string $trigger): array {
        $results = [];
        foreach (model_list::PROVIDERS as $provider) {
            $results[$provider] = self::check_provider($provider, $trigger);
        }

        return $results;
    }

    /**
     * Run both checks for one provider and set or clear its blocking state.
     *
     * Admin-054: "Sikeres kézi futás feloldja a blokkoló állapotot, sikertelen kézi futás beállítja
     * azt" - and Admin-055 makes this the only place, scheduled or manual, that writes it.
     *
     * @param string $provider one of model_list::PROVIDERS
     * @param string $trigger model_check_log::TRIGGER_*
     * @return array{success: bool, messages: string[]}
     */
    public static function check_provider(string $provider, string $trigger): array {
        $model = (string) get_config(
            'local_artqtml',
            $provider === model_list::PROVIDER_CLAUDE ? 'claudemodel' : 'geminimodel'
        );

        if ($model === '') {
            // Nothing to check. The blocking state for an unset model is derived from the setting
            // itself (model_blocking::state()), so there is nothing to write here either.
            return ['success' => false, 'messages' => [get_string('modelcheckskippednomodel', 'local_artqtml')]];
        }

        $messages = [];

        // 1. Availability. A supporting signal only, per Admin-052 - a failure here is still worth
        // logging and blocking on, because a model absent from the list is not going to work.
        $started = microtime(true);
        $refresh = model_list::refresh($provider);
        $listed = $refresh['success'] && model_list::is_listed($provider, $model);
        $duration = (int) round((microtime(true) - $started) * 1000);

        $availabilityerror = $refresh['success']
            ? get_string('modelchecknotlisted', 'local_artqtml', $model)
            : $refresh['error'];

        // NOT covered by the transient handling the structure check gained on 2026-08-03, and this
        // says so rather than leaving it to be rediscovered: model_list::refresh() reports only
        // success/error, with no HTTP code, so a 503 from the models.list endpoint is
        // indistinguishable here from "this model is genuinely gone" - and it would block the site.
        // Left alone deliberately, because it has not been measured happening and widening
        // refresh()'s return shape is a bigger change than the defect that was measured.
        $log = model_check_log::record(
            self::log_provider($provider),
            $model,
            model_check_log::CHECK_AVAILABILITY,
            $listed,
            $listed ? '' : $availabilityerror,
            $duration,
            $trigger
        );

        if (!$listed) {
            model_blocking::block($provider, $model, model_check_log::CHECK_AVAILABILITY, $log['errorcode']);

            return ['success' => false, 'messages' => [$availabilityerror . ' (' . $log['errorcode'] . ')']];
        }
        $messages[] = get_string('modelcheckavailabilityok', 'local_artqtml', $model);

        // 2. Structure (Admin-053): a live minimal structured-output call with the plugin's own
        // schema, whose response is validated against that same schema. This is the check that
        // would have caught the retired gemini-2.0-flash on day one.
        $started = microtime(true);
        $probe = self::probe($provider, $model);
        $duration = (int) round((microtime(true) - $started) * 1000);

        $transient = !empty($probe['transient']);
        $log = model_check_log::record(
            self::log_provider($provider),
            $model,
            model_check_log::CHECK_STRUCTURE,
            $probe['success'],
            $probe['error'],
            $duration,
            $trigger,
            $transient
        );

        if (!$probe['success']) {
            // Admin-054's blocking state is for "this model cannot be used", and a provider outage
            // is not that. Blocking on one would take the site out of service over a spike in
            // demand that has usually passed by the next scheduled run.
            if (!$transient) {
                model_blocking::block($provider, $model, model_check_log::CHECK_STRUCTURE, $log['errorcode']);
            }

            return ['success' => false, 'messages' => [$probe['error'] . ' (' . $log['errorcode'] . ')']];
        }

        // Both halves passed: Admin-054 says a successful run clears the blocking state.
        model_blocking::clear($provider);
        $messages[] = get_string('modelcheckstructureok', 'local_artqtml', $model);

        return ['success' => true, 'messages' => $messages];
    }

    /**
     * Probe every listed model that this plugin version has not already ruled out, and record each.
     *
     * BL-44, decided by András 2026-08-03: the nightly check keeps looking at the configured models
     * only, and the "Test connection" button covers the rest - "azokat akiről még nincs információ
     * és azokat, akiket még nem zártunk ki".
     *
     * Already-excluded models are skipped rather than retried, because within one plugin version
     * the answer cannot have changed: the verdict says this build cannot read that model. A version
     * bump clears the exclusions by construction (see model_check_log::excluded_models), so our own
     * fixes reopen them without anyone having to remember.
     *
     * THE COST IS REAL AND WAS ACCEPTED. One paid call per model, sequentially, each bounded by the
     * API timeout - a dozen models make this a click that runs for minutes and costs a fraction of
     * a cent. The judgement was that an edge case which surfaces about once a year is worth that,
     * and the alternative is what happened on the day this was written: a button that reported
     * success for models on which every generation would fail.
     *
     * Recording each result is not incidental - it is how the dropdown learns. Without the log
     * entry the exclusion never happens and this loop is a slow no-op.
     *
     * @param string $provider one of model_list::PROVIDERS
     * @return array{checked: int, skipped: int, failed: string[]} failed holds the model ids that
     *      did not return a readable question, and are now out of the dropdown
     */
    public static function check_listed_models(string $provider): array {
        $cached = model_list::get_cached($provider);
        $models = $cached['models'] ?? [];
        $excluded = model_check_log::excluded_models($provider);

        // MEASURED 2026-08-03, and only visible once the probe stopped failing instantly. While
        // every Gemini model was rejected over the schema's `const` keyword, each probe took about
        // 150 ms and the whole sweep took six seconds; with a request the API accepts, each probe
        // is a real generation of about 3.4 seconds. The sweep then ran past PHP's execution limit
        // and was killed without writing a single row - the log stayed empty, which is exactly what
        // "nothing happened" looks like.
        //
        // Sized the same way process_pending_generations does it, from the per-call timeout rather
        // than a number someone would have to keep in step with it by hand.
        $percall = (int) (get_config('local_artqtml', 'apitimeout') ?: 60);
        set_time_limit($percall * max(1, count($models)) + 30);

        $checked = 0;
        $skipped = 0;
        $failed = [];

        foreach ($models as $model) {
            $id = (string) ($model['id'] ?? '');
            // Admin-047: a model without structured output could never be used, so there is nothing
            // to learn from probing it - it is already kept out of the dropdown.
            if ($id === '' || empty($model['supports_structured_output'])) {
                continue;
            }
            if (in_array($id, $excluded, true)) {
                $skipped++;
                continue;
            }

            $started = microtime(true);
            $result = self::probe($provider, $id);
            $duration = (int) round((microtime(true) - $started) * 1000);

            $transient = !empty($result['transient']);
            model_check_log::record(
                self::log_provider($provider),
                $id,
                model_check_log::CHECK_STRUCTURE,
                $result['success'],
                $result['error'],
                $duration,
                model_check_log::TRIGGER_MANUAL,
                $transient
            );

            $checked++;
            // A transient outage is neither a pass nor a fail: it is not reported to the
            // administrator as a model that was struck off, because it was not.
            if (!$result['success'] && !$transient) {
                $failed[] = $id;
            }
        }

        return ['checked' => $checked, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * The minimal structured-output probe (Admin-053).
     *
     * The request is built by {@see ai_request}, the same class the generation and validation
     * tasks use, so the probe cannot test a shape production does not send. It previously built
     * its own - omitting the beta header the generator sent and hand-writing a schema without
     * additionalProperties:false - and the resulting 400 blocked generation site-wide on a
     * configuration that worked. A probe that does not reproduce the production request can only
     * produce false positives.
     *
     * What stays the probe's own is the payload it carries, not how that payload is assembled:
     * Admin-059 forbids it advancing the question-count licence counter and Admin-060 forbids its
     * tokens being booked against the user budget, and both happen inside those tasks. So it does
     * not call them, and nothing here touches license_checker or the token accounting. The schema
     * is the smallest that still exercises structured output in both directions (Admin-060).
     *
     * @param string $provider
     * @param string $model
     * @return array{success: bool, transient?: bool, error: string} `transient` is present and true
     *      only when the call failed because the provider was busy or unreachable, which is not a
     *      verdict about the model - see model_check_log::RESULT_TRANSIENT
     */
    protected static function probe(string $provider, string $model): array {
        $apikey = api_key_store::get($provider);
        if ($apikey === '') {
            return ['success' => false, 'error' => get_string('errormissingapikey', 'local_artqtml')];
        }

        $timeout = (int) (get_config('local_artqtml', 'apitimeout') ?: 60);

        // BL-44, decided 2026-08-03: the probe asks for a REAL question, in the real question
        // shape - but the smallest possible one. Not six questions, not the teacher's material.
        //
        // Until that day it asked for {ok: true}, which proved the model could return structured
        // output and nothing whatever about whether a question could be read back. That is exactly
        // the gap the same day's failure fell through: Sonnet 5 and Opus 5 answered correctly, with
        // valid JSON and six usable questions, and the plugin threw all of it away because of where
        // the answer sat in the envelope. A boolean probe cannot see that; one question can.
        //
        // The schema comes from question_schema::build(), the SAME builder generation uses, asked
        // for one True/False question. That is what makes the check honest: passing the probe now
        // means the plugin can read what this model produces, not merely that it produces JSON.
        $schema = question_schema::build(['counts' => ['IH' => 1], 'types' => []]);
        $prompt = 'Source text: Water freezes at 0 degrees Celsius at standard atmospheric pressure.'
            . "\n\nGenerate exactly one True/False question from the source text above.";

        // 128 tokens was enough for {ok: true} and is not enough for a question with its feedback.
        // Kept small deliberately: this runs per model, and the item's whole point is that it stays
        // cheap enough to run across the list rather than only against the selected model.
        if ($provider === model_list::PROVIDER_CLAUDE) {
            $request = ai_request::claude($model, $apikey, 1024, $prompt, $prompt, $schema);
        } else {
            $request = ai_request::gemini($model, $apikey, $prompt, $prompt, $schema);
        }

        $result = ai_request::send($request, $timeout);

        // A busy or unreachable provider says nothing about this model, so it is recorded and then
        // ignored when the dropdown is filtered - see model_check_log::excluded_models(). Judged
        // with ai_request::is_transient() so this and the tasks' backoff cannot drift apart.
        if (ai_request::is_transient((int) $result['httpcode'], $result['curlerror'])) {
            $message = $result['curlerror'] !== ''
                ? $result['curlerror']
                : self::transient_message($result['body'], (int) $result['httpcode']);

            return [
                'success'   => false,
                'transient' => true,
                'error'     => \core_text::substr($message, 0, 300),
            ];
        }

        $decoded = json_decode($result['body'], true);
        $classified = ai_request::classify($result['httpcode'], is_array($decoded) ? $decoded : null);

        // A hard rejection blocks; a deprecation notice on an otherwise successful call is recorded
        // by the caller's log entry and does not. The provider telling us something will stop
        // working one day is not a reason to stop the site generating questions today.
        if ($classified['outcome'] === ai_request::OUTCOME_REJECTED) {
            return ['success' => false, 'error' => \core_text::substr($classified['message'], 0, 300)];
        }

        // BL-44: the probe MUST read the reply the same way generation does, or it can pass a
        // model that generation then fails on - which is the failure this whole item exists to
        // stop. Hence ai_request::extract_text rather than a local copy.
        $text = (string) ai_request::extract_text($provider, $decoded);

        // Admin-053: the response is validated against the shape that was asked for. A 200 whose
        // body does not satisfy it is exactly the "API structure changed" case.
        //
        // BL-44: the test is the generator's own - a `questions` array with something in it
        // (generate_questions_task reads exactly this). Deliberately no deeper inspection of the
        // question itself: whether the content is any good is the validator's job and a judgement
        // call, while this has to be a yes/no a scheduled task can act on.
        $parsed = json_decode((string) $text, true);
        $questions = (is_array($parsed) && is_array($parsed['questions'] ?? null)) ? $parsed['questions'] : [];
        if ($questions === []) {
            return ['success' => false, 'error' => get_string('modelcheckstructurefailed', 'local_artqtml')];
        }

        return ['success' => true, 'error' => $classified['message']];
    }

    /**
     * The provider's own words for a transient failure, so the log says why rather than just "503".
     *
     * @param string $body the raw response body
     * @param int $httpcode
     * @return string
     */
    protected static function transient_message(string $body, int $httpcode): string {
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';

        return $message !== '' ? $message : 'HTTP ' . $httpcode;
    }

    /**
     * The provider name as stored in the diagnostic log.
     *
     * The log records the vendor (anthropic/gemini) per the annex schema, while the settings use
     * claude/gemini as the key - so the two are mapped rather than assumed equal.
     *
     * @param string $provider
     * @return string
     */
    protected static function log_provider(string $provider): string {
        // BL-44: the mapping moved to model_check_log, which is where the column it maps to lives.
        // This wrapper stays because the call sites below read better with the short name.
        return model_check_log::stored_provider($provider);
    }
}
