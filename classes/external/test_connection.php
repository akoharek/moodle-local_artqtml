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
 * Admin "Test connection" button and dynamic model list.
 *
 * Uses the currently saved API key (the admin must Save changes on the settings form before
 * Testing/fetching models - avoiding the complexity of round-tripping an unsaved secret
 * Through an AJAX call).
 *
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Tests the saved Claude/Gemini API key and lists available models.
 */
class test_connection extends external_api {
    /**
     * Parameter definition for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'provider' => new external_value(PARAM_ALPHA, 'claude or gemini'),
        ]);
    }

    /**
     * Test the connection and list available models for the given provider.
     *
     * @param string $provider 'claude' or 'gemini'
     * @return array
     */
    public static function execute(string $provider): array {
        $params = self::validate_parameters(self::execute_parameters(), ['provider' => $provider]);

        self::validate_context(\context_system::instance());
        require_capability('local/artqtml:configure', \context_system::instance());

        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if ($params['provider'] === 'claude') {
            return self::test_claude();
        }
        if ($params['provider'] === 'gemini') {
            return self::test_gemini();
        }

        return ['success' => false, 'message' => get_string('errorinvalidprovider', 'local_artqtml'), 'models' => []];
    }

    /**
     * Test the Claude API key via GET /v1/models.
     *
     * @return array
     */
    protected static function test_claude(): array {
        $apikey = \local_artqtml\local\api_key_store::get('claude');
        if (empty($apikey)) {
            return ['success' => false, 'message' => get_string('errormissingapikey', 'local_artqtml'), 'models' => []];
        }

        $curl = new \curl();
        $curl->setHeader(['x-api-key: ' . $apikey, 'anthropic-version: 2023-06-01']);
        // C7: bound the connection test with an explicit 10-second timeout so a hung or
        // Unreachable provider can never leave this AJAX call (and the admin's browser) waiting
        // Indefinitely.
        $response = $curl->get('https://api.anthropic.com/v1/models', [], [
            'CURLOPT_TIMEOUT'        => 10,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);
        $info = $curl->get_info();

        if ((int) ($info['http_code'] ?? 0) !== 200) {
            $decoded = json_decode((string) $response, true);
            $message = $decoded['error']['message'] ?? get_string('testconnectionfailed', 'local_artqtml');
            return ['success' => false, 'message' => $message, 'models' => []];
        }

        $decoded = json_decode((string) $response, true);
        $models = [];
        foreach ($decoded['data'] ?? [] as $model) {
            if (!empty($model['id'])) {
                $models[] = (string) $model['id'];
            }
        }

        \local_artqtml\local\model_list::refresh('claude');

        return self::with_structure_check('claude', $models);
    }

    /**
     * Test the Gemini API key via GET /v1beta/models.
     *
     * @return array
     */
    protected static function test_gemini(): array {
        $apikey = \local_artqtml\local\api_key_store::get('gemini');
        if (empty($apikey)) {
            return ['success' => false, 'message' => get_string('errormissingapikey', 'local_artqtml'), 'models' => []];
        }

        $curl = new \curl();
        $curl->setHeader(['x-goog-api-key: ' . $apikey]);
        // C7: bound the connection test with an explicit 10-second timeout so a hung or
        // Unreachable provider can never leave this AJAX call (and the admin's browser) waiting
        // Indefinitely.
        $response = $curl->get('https://generativelanguage.googleapis.com/v1beta/models', [], [
            'CURLOPT_TIMEOUT'        => 10,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);
        $info = $curl->get_info();

        if ((int) ($info['http_code'] ?? 0) !== 200) {
            $decoded = json_decode((string) $response, true);
            $message = $decoded['error']['message'] ?? get_string('testconnectionfailed', 'local_artqtml');
            return ['success' => false, 'message' => $message, 'models' => []];
        }

        $decoded = json_decode((string) $response, true);
        $models = [];
        foreach ($decoded['models'] ?? [] as $model) {
            if (!empty($model['name'])) {
                $models[] = (string) str_replace('models/', '', $model['name']);
            }
        }

        \local_artqtml\local\model_list::refresh('gemini');

        return self::with_structure_check('gemini', $models);
    }

    /**
     * Probe the listed models and fold the verdict into the key test's message.
     *
     * That gap had a price. Claude Sonnet 5 and Opus 5 open their reply with a thinking block, the
     * Plugin read the wrong part of the envelope, and nine calls that were HTTP 200 carrying six
     * Usable questions each were thrown away - $0.228 for zero questions. Every one of those calls
     * Would have passed this button.
     *
     * A structural failure does not make the key test fail. The key IS valid, and saying otherwise
     * Would send the administrator to the wrong setting. It is reported as its own sentence, and
     * The failing models drop out of the dropdown on the next page load.
     *
     * @param string $provider 'claude' or 'gemini'
     * @param string[] $models the model ids the key test listed
     * @return array
     */
    protected static function with_structure_check(string $provider, array $models): array {
        $summary = \local_artqtml\local\model_checker::check_listed_models($provider);

        $verdict = $summary['failed'] === []
            ? get_string('testconnectionstructureok', 'local_artqtml', $summary['checked'])
            : get_string('testconnectionstructurefailed', 'local_artqtml', (object) [
                'failed' => count($summary['failed']),
                'models' => implode(', ', $summary['failed']),
            ]);

        $message = get_string('testconnectionsuccess', 'local_artqtml') . ' ' . $verdict;

        return ['success' => true, 'message' => $message, 'models' => $models];
    }

    /**
     * Return definition for execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the connection succeeded'),
            'message' => new external_value(PARAM_RAW, 'Human-readable result message'),
            'models'  => new external_multiple_structure(new external_value(PARAM_RAW, 'Model id'), 'Available models'),
        ]);
    }
}
