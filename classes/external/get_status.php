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
 * External function returning the status and question count of a generation.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Returns the current status and question count for a local_artqtml_generations record.
 */
class get_status extends external_api {
    /**
     * Parameter definition for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Generation id'),
        ]);
    }

    /**
     * Return the current status and question count for a generation.
     *
     * @param int $id the local_artqtml_generations id
     * @return array
     */
    public static function execute(int $id): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/artqtml:use', $context);
        \local_artqtml\local\ajax_rate_limiter::require_get_status();

        $generation = $DB->get_record('local_artqtml_generations', ['id' => $params['id']], '*', MUST_EXIST);

        $questioncount = $DB->count_records('local_artqtml_questions', ['generationid' => $generation->id]);
        $unvalidatedcount = $DB->count_records('local_artqtml_questions', [
            'generationid'         => $generation->id,
            'validationsuggestion' => 'not_evaluated',
        ]);

        $technicalerror = has_capability('local/artqtml:configure', $context) ? (string) ($generation->error ?? '') : '';

        $countdiscrepancy = json_decode((string) $generation->countdiscrepancy, true);
        $countdiscrepancymessage = (is_array($countdiscrepancy) && !empty($countdiscrepancy))
            ? \local_artqtml\local\question_types::format_count_discrepancy($countdiscrepancy)
            : '';

        $failedpercent = 25;
        if ($generation->status === \local_artqtml\local\generation_status::FAILED) {
            $pendingdata = json_decode((string) $generation->pendingdata, true);
            if (is_array($pendingdata) && array_key_exists('evaluations', $pendingdata)) {
                $failedpercent = 75;
            } else if (is_array($pendingdata) && array_key_exists('questions', $pendingdata)) {
                $failedpercent = 50;
            }
        }

        $generatingpercent = \local_artqtml\local\generation_progress::generating_percent($generation->pendingdata);
        $generatingtype = \local_artqtml\local\generation_progress::generating_type($generation->pendingdata);

        return [
            'status'                  => $generation->status,
            'questioncount'           => $questioncount,
            'unvalidatedcount'        => $unvalidatedcount,
            'error'                   => $technicalerror,
            'countdiscrepancymessage' => $countdiscrepancymessage,
            'failedpercent'           => $failedpercent,
            'generatingpercent'       => $generatingpercent,
            'generatingtypelabel'     => $generatingtype === ''
                ? ''
                : \local_artqtml\local\question_types::label($generatingtype),
            'restarturl'              => $generation->status === \local_artqtml\local\generation_status::STARTED
                ? (new \moodle_url('/local/artqtml/generate.php', ['id' => (int) $generation->id]))->out(false)
                : '',
        ];
    }

    /**
     * Return definition for execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'started, generating, validating, saving, completed, partial or failed'),
            'questioncount' => new external_value(PARAM_INT, 'Number of questions generated so far'),
            'unvalidatedcount' => new external_value(PARAM_INT, 'Number of questions not yet validated'),
            'error' => new external_value(PARAM_RAW, 'Technical error message from the last failed API call, empty if none'),
            'countdiscrepancymessage' => new external_value(
                PARAM_RAW,
                'Requested-vs-received question count warning, empty if none'
            ),
            'failedpercent' => new external_value(
                PARAM_INT,
                'Progress-bar percent (25/50/75) for a failed generation, based on which stage it reached'
            ),
            'generatingpercent' => new external_value(
                PARAM_INT,
                'Progress-bar percent within the generating stage, 25-45, from how many question types are done'
            ),
            'generatingtypelabel' => new external_value(
                PARAM_RAW,
                'Human-readable name of the question type currently being generated, empty if none'
            ),
            'restarturl' => new external_value(
                PARAM_RAW,
                'Settings URL when status is started after a recoverable rollback; empty otherwise'
            ),
        ]);
    }
}
