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
 * External function extracting text from a just-picked upload-page draft file (Felt-010/011),
 * so it can be loaded into the source text box for review before the user submits.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_artqtml\local\extraction_result;
use local_artqtml\local\source_text_limit;
use local_artqtml\local\text_extractor;

/**
 * Returns the extracted plain text for a draft file area, or '' if nothing could be extracted.
 */
class extract_text extends external_api {
    /**
     * Parameter definition for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'draftitemid' => new external_value(PARAM_INT, 'Draft file area item id from the upload form filepicker'),
        ]);
    }

    /**
     * Extract text from the first file in the given draft area.
     *
     * text_extractor::draft_files() only ever looks at the current $USER's own draft file area
     * (context_user::instance($USER->id)), so a caller cannot use this to read another user's
     * files by guessing item ids.
     *
     * @param int $draftitemid
     * @return array
     */
    public static function execute(int $draftitemid): array {
        $params = self::execute_parameters();
        $params = self::validate_parameters($params, ['draftitemid' => $draftitemid]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/artqtml:use', $context);
        \local_artqtml\local\ajax_rate_limiter::require_extract_text();

        $text = '';
        foreach (text_extractor::draft_files($params['draftitemid']) as $file) {
            $report = text_extractor::extract_with_report($file);

            // The document was refused - an unreadable structure, an unsupported type, or a
            // processing limit. The browser gets the reason code and a localised sentence, and
            // nothing else: no partial text, no parser warning, and above all no part of the
            // document itself, which has no business in a message that may also be logged.
            if ($report['status'] === extraction_result::STATUS_REJECTED) {
                return [
                    'text'    => '',
                    'success' => false,
                    'reason'  => $report['reason'],
                    'message' => extraction_result::message($report['reason']),
                ];
            }

            if ($report['text'] !== '') {
                $text = $text !== '' ? ($text . "\n\n" . $report['text']) : $report['text'];
            }

            // Checked inside the loop, after each file, so a set of files that is collectively too
            // large stops as soon as it is known to be - rather than being assembled in full first
            // and then thrown away.
            if (source_text_limit::is_exceeded($text)) {
                return [
                    'text'    => '',
                    'success' => false,
                    'reason'  => 'sourcetexttoolong',
                    'message' => source_text_limit::error_message($text),
                ];
            }
        }

        return ['text' => $text, 'success' => true, 'reason' => '', 'message' => ''];
    }

    /**
     * Return definition for execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'text' => new external_value(PARAM_RAW, 'Extracted plain text, empty if extraction failed/produced nothing'),
            // Deliberately a normal result rather than an exception. A refused document is
            // something the teacher can act on - split it up, re-save it, choose another file -
            // and they need to be told which. An exception would surface as a
            // generic failure with nothing useful in it. Empty text, never a truncated prefix:
            // half a document silently loaded into the textarea would produce questions about
            // material nobody chose.
            'success' => new external_value(PARAM_BOOL, 'False if the document was refused'),
            'reason' => new external_value(PARAM_ALPHANUMEXT, 'Technical reason code when success is false, otherwise empty'),
            'message' => new external_value(PARAM_TEXT, 'Localised explanation when success is false, otherwise empty'),
        ]);
    }
}
