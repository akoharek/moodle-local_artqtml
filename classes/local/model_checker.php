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
 * Availability and structured-output probe for configured Claude/Gemini models.
 *
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
            // Itself (model_blocking::state()), so there is nothing to write here either.
            return ['success' => false, 'messages' => [get_string('modelcheckskippednomodel', 'local_artqtml')]];
        }

        $messages = [];

        $started = microtime(true);
        $refresh = model_list::refresh($provider);
        $listed = $refresh['success'] && model_list::is_listed($provider, $model);
        $duration = (int) round((microtime(true) - $started) * 1000);

        $availabilityerror = $refresh['success']
            ? get_string('modelchecknotlisted', 'local_artqtml', $model)
            : $refresh['error'];

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
            if (!$transient) {
                model_blocking::block($provider, $model, model_check_log::CHECK_STRUCTURE, $log['errorcode']);
            }

            return ['success' => false, 'messages' => [$probe['error'] . ' (' . $log['errorcode'] . ')']];
        }

        // Both halves passed: says a successful run clears the blocking state.
        model_blocking::clear($provider);
        $messages[] = get_string('modelcheckstructureok', 'local_artqtml', $model);

        return ['success' => true, 'messages' => $messages];
    }

    /**
     * Probe every listed model that this plugin version has not already ruled out, and record each.
     *
     * Already-excluded models are skipped rather than retried, because within one plugin version
     * The answer cannot have changed: the verdict says this build cannot read that model. A version
     * Bump clears the exclusions by construction (see model_check_log::excluded_models), so our own
     * Fixes reopen them without anyone having to remember.
     *
     * THE COST IS REAL AND WAS ACCEPTED. One paid call per model, sequentially, each bounded by the
     * API timeout - a dozen models make this a click that runs for minutes and costs a fraction of
     * A cent. The judgement was that an edge case which surfaces about once a year is worth that,
     * And the alternative is what happened on the day this was written: a button that reported
     * Success for models on which every generation would fail.
     *
     * Recording each result is not incidental - it is how the dropdown learns. Without the log
     * Entry the exclusion never happens and this loop is a slow no-op.
     *
     * @param string $provider one of model_list::PROVIDERS
     * @return array{checked: int, skipped: int, failed: string[]} failed holds the model ids that
     * Did not return a readable question, and are now out of the dropdown
     */
    public static function check_listed_models(string $provider): array {
        $cached = model_list::get_cached($provider);
        $models = $cached['models'] ?? [];
        $excluded = model_check_log::excluded_models($provider);

        $percall = (int) (get_config('local_artqtml', 'apitimeout') ?: 60);
        set_time_limit($percall * max(1, count($models)) + 30);

        $checked = 0;
        $skipped = 0;
        $failed = [];

        foreach ($models as $model) {
            $id = (string) ($model['id'] ?? '');
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
            // Administrator as a model that was struck off, because it was not.
            if (!$result['success'] && !$transient) {
                $failed[] = $id;
            }
        }

        return ['checked' => $checked, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * The minimal structured-output probe.
     *
     * The request is built by {@see ai_request}, the same class the generation and validation
     * Tasks use, so the probe cannot test a shape production does not send. It previously built
     * Its own - omitting the beta header the generator sent and hand-writing a schema without
     * AdditionalProperties:false - and the resulting 400 blocked generation site-wide on a
     * Configuration that worked. A probe that does not reproduce the production request can only
     * Produce false positives.
     *
     * @param string $provider
     * @param string $model
     * @return array{success: bool, transient?: bool, error: string} `transient` is present and true
     * Only when the call failed because the provider was busy or unreachable, which is not a
     * Verdict about the model - see model_check_log::RESULT_TRANSIENT
     */
    protected static function probe(string $provider, string $model): array {
        $apikey = api_key_store::get($provider);
        if ($apikey === '') {
            return ['success' => false, 'error' => get_string('errormissingapikey', 'local_artqtml')];
        }

        $timeout = (int) (get_config('local_artqtml', 'apitimeout') ?: 60);

        $schema = question_schema::build(['counts' => ['IH' => 1], 'types' => []]);
        $prompt = 'Source text: Water freezes at 0 degrees Celsius at standard atmospheric pressure.'
            . "\n\nGenerate exactly one True/False question from the source text above.";

        // 128 tokens was enough for {ok: true} and is not enough for a question with its feedback.
        // Kept small deliberately: this runs per model, and the item's whole point is that it stays
        // Cheap enough to run across the list rather than only against the selected model.
        if ($provider === model_list::PROVIDER_CLAUDE) {
            $request = ai_request::claude($model, $apikey, 1024, $prompt, $prompt, $schema);
        } else {
            $request = ai_request::gemini($model, $apikey, $prompt, $prompt, $schema);
        }

        $result = ai_request::send($request, $timeout);

        // A busy or unreachable provider says nothing about this model, so it is recorded and then
        // Ignored when the dropdown is filtered - see model_check_log::excluded_models(). Judged
        // With ai_request::is_transient() so this and the tasks' backoff cannot drift apart.
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
        // By the caller's log entry and does not. The provider telling us something will stop
        // Working one day is not a reason to stop the site generating questions today.
        if ($classified['outcome'] === ai_request::OUTCOME_REJECTED) {
            return ['success' => false, 'error' => \core_text::substr($classified['message'], 0, 300)];
        }

        $text = (string) ai_request::extract_text($provider, $decoded);

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
     * Claude/gemini as the key - so the two are mapped rather than assumed equal.
     *
     * @param string $provider
     * @return string
     */
    protected static function log_provider(string $provider): string {
        return model_check_log::stored_provider($provider);
    }
}
