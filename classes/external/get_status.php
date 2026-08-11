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
 * External function returning the status and question count of a generation (Glob-006).
 *
 * @package    local_artqtml
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

        // Glob-031: collaborative :use by design; delete is owner-only (see delete.php).
        // Deliberately no per-owner check here — must match status/generate/approve page load
        // (C6; reverts the earlier M-28 ownership gate on the AJAX poll).

        $questioncount = $DB->count_records('local_artqtml_questions', ['generationid' => $generation->id]);
        $unvalidatedcount = $DB->count_records('local_artqtml_questions', [
            'generationid'         => $generation->id,
            'validationsuggestion' => 'not_evaluated',
        ]);

        // Gen-014/M-27: the raw technical error is shown to anyone allowed to configure the
        // plugin (not gated on debug mode - a configure-capable admin/teacher needs the real
        // provider error to diagnose a failure regardless of whether debug mode happens to be on).
        $technicalerror = has_capability('local/artqtml:configure', $context) ? (string) ($generation->error ?? '') : '';

        // M-08: surfaced live through the same AJAX poll status.php's JS already uses, same as
        // the token-budget warning below, rather than only ever appearing after a page reload.
        $countdiscrepancy = json_decode((string) $generation->countdiscrepancy, true);
        $countdiscrepancymessage = (is_array($countdiscrepancy) && !empty($countdiscrepancy))
            ? \local_artqtml\local\question_types::format_count_discrepancy($countdiscrepancy)
            : '';

        // M-15: which stage a failed generation actually got to is no longer reflected by
        // $questioncount (nothing is saved to local_artqtml_questions until the saving stage
        // commits it all) - mirrors status.php's own server-rendered derivation from pendingdata.
        $failedpercent = 25;
        if ($generation->status === \local_artqtml\local\generation_status::FAILED) {
            $pendingdata = json_decode((string) $generation->pendingdata, true);
            if (is_array($pendingdata) && array_key_exists('evaluations', $pendingdata)) {
                $failedpercent = 75;
            } else if (is_array($pendingdata) && array_key_exists('questions', $pendingdata)) {
                $failedpercent = 50;
            }
        }

        // BL-35: the generating stage is one API call per requested question type, so the poll has
        // to carry how far through that loop the run is - otherwise the bar sits at 25% for
        // several minutes and the only thing it communicates is that nothing has crashed.
        $generatingpercent = \local_artqtml\local\generation_progress::generating_percent($generation->pendingdata);
        $generatingtype = \local_artqtml\local\generation_progress::generating_type($generation->pendingdata);

        return [
            'status'                  => $generation->status,
            'questioncount'           => $questioncount,
            'unvalidatedcount'        => $unvalidatedcount,
            'error'                   => $technicalerror,
            'tokenwarningmessage'     => \local_artqtml\local\token_budget::warning_message($generation->id),
            'countdiscrepancymessage' => $countdiscrepancymessage,
            'failedpercent'           => $failedpercent,
            'generatingpercent'       => $generatingpercent,
            'generatingtypelabel'     => $generatingtype === ''
                ? ''
                : \local_artqtml\local\question_types::label($generatingtype),
            // Finding #5 / Abort: when the pipeline rolls back to started mid-poll, the status page
            // must leave — settings (and from there upload) are the editable draft surfaces.
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
            'tokenwarningmessage' => new external_value(PARAM_RAW, 'Token-limit warning message, empty if none logged'),
            'countdiscrepancymessage' => new external_value(
                PARAM_RAW,
                'Requested-vs-received question count warning, empty if none (M-08)'
            ),
            'failedpercent' => new external_value(
                PARAM_INT,
                'Progress-bar percent (25/50/75) for a failed generation, based on which stage it reached (M-15)'
            ),
            'generatingpercent' => new external_value(
                PARAM_INT,
                'Progress-bar percent within the generating stage, 25-45, from how many question types are done (BL-35)'
            ),
            'generatingtypelabel' => new external_value(
                PARAM_RAW,
                'Human-readable name of the question type currently being generated, empty if none (BL-35)'
            ),
            'restarturl' => new external_value(
                PARAM_RAW,
                'Settings URL when status is started after a recoverable rollback; empty otherwise'
            ),
        ]);
    }
}
