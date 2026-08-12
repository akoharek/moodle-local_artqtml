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
 * Hooks API listeners for local_artqtml (db/hooks.php).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml;

use core\hook\output\before_standard_top_of_body_html_generation;
use local_artqtml\local\validation_panel;

/**
 * Listeners for core output hooks.
 */
class hook_callbacks {
    /**
     * Only ever adds HTML on /question/bank/editquestion/question.php for a question that has
     * A matching local_artqtml_questions row - a cheap no-op everywhere else.
     *
     * @param before_standard_top_of_body_html_generation $hook
     * @return void
     */
    public static function before_standard_top_of_body_html(before_standard_top_of_body_html_generation $hook): void {
        global $PAGE;

        if (strpos($PAGE->url->get_path(), '/question/bank/editquestion/question.php') === false) {
            return;
        }

        $questionid = optional_param('id', 0, PARAM_INT);
        if ($questionid <= 0) {
            return;
        }

        $row = validation_panel::for_questionbank_id($questionid);
        if ($row === null) {
            return;
        }

        $hook->add_html(validation_panel::render($row));
    }
}
