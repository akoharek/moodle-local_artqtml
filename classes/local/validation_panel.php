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
 * Read-only AI validation panel injected into Moodle's native question editor.
 *
 * The plugin deliberately reuses Moodle's own /question/bank/editquestion/question.php rather
 * than shipping a custom editing form (the editor is exactly the page where a teacher would
 * edit any question-bank question). Since that page belongs to core,
 * This panel is injected via the before_standard_top_of_body_html plugin callback (lib.php)
 * Rather than by modifying the native edit form.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Looks up and renders the validation summary for one draft question.
 */
class validation_panel {
    /**
     * Find the local_artqtml_questions row for a real Moodle question.id, if any.
     *
     * @param int $questionbankid question.id as used on /question/bank/editquestion/question.php
     * @return \stdClass|null
     */
    public static function for_questionbank_id(int $questionbankid): ?\stdClass {
        global $DB;

        $row = $DB->get_record('local_artqtml_questions', ['questionbankid' => $questionbankid], '*', IGNORE_MISSING);
        if (!$row || !empty($row->movedout)) {
            // After move the question belongs to Moodle's bank. Core question.php must not
            // carry the plugin validation overlay.
            return null;
        }

        return $row;
    }

    /**
     * Render the read-only panel HTML for one question row.
     *
     * Returned as a hidden <div> plus a small inline script that relocates it immediately
     * Before the native form's "Question name" field (#id_name) once the DOM is ready - the
     * Exact placement the spec calls for (before the question name field). If that field can't be found
     * (a future Moodle version changes the form), the panel simply stays visible at the top of
     * The page instead of disappearing.
     *
     * @param \stdClass $row a local_artqtml_questions record
     * @return string
     */
    public static function render(\stdClass $row): string {
        $panelid = 'artqtml-validation-panel';

        $rows = [];
        $rows[] = [
            get_string('colvalidationstatus', 'local_artqtml'),
            \html_writer::span(
                \local_artqtml\local\validation_suggestion::label($row->validationsuggestion),
                'badge ' . \local_artqtml\local\validation_suggestion::badge_class($row->validationsuggestion)
            ),
        ];
        if ($row->validationsuggestion !== \local_artqtml\local\validation_suggestion::NOT_EVALUATED) {
            // Show the problem category for any validated question, via its lang.
            // Label (never the raw key). 'ok' renders as "No issue", not as an
            // Empty field. normalise() guards against a stale/legacy value reaching get_string().
            $category = \local_artqtml\local\problem_category::normalise($row->problemcategory);
            if ($category !== null) {
                $rows[] = [
                    get_string('validationcategorylabel', 'local_artqtml'),
                    \html_writer::span(
                        \s(\local_artqtml\local\problem_category::label($category)),
                        'artqtml-problemcategory'
                    ),
                ];
            }
            if (!empty($row->justification)) {
                $rows[] = [get_string('validationjustificationlabel', 'local_artqtml'), \s($row->justification)];
            }
            if ($row->confidence !== null) {
                $rows[] = [get_string('validationconfidencelabel', 'local_artqtml'), (int) $row->confidence . '%'];
            }
        }

        $table = new \html_table();
        // Fluid + wrapping, never wider than its container.
        $table->attributes['class'] = 'generaltable table-sm mb-0 artqtml-table';
        $table->data = $rows;

        $panel = \html_writer::div(
            \html_writer::tag('h5', get_string('validationpanelheading', 'local_artqtml'), ['class' => 'mb-2']) .
            \html_writer::table($table),
            'alert alert-info artqtml-validation-panel',
            ['id' => $panelid]
        );

        $script = \html_writer::script(
            'document.addEventListener("DOMContentLoaded", function() {' .
            'var panel = document.getElementById(' . json_encode($panelid) . ');' .
            'var name = document.getElementById("id_name");' .
            'var namefitem = name ? name.closest(".fitem") : null;' .
            'if (panel && namefitem && namefitem.parentNode) {' .
            'namefitem.parentNode.insertBefore(panel, namefitem);' .
            '}});'
        );

        return $panel . $script;
    }
}
