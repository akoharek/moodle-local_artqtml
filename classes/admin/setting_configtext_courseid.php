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
 * Required draft-course ID: positive integer pointing at an existing course.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\admin;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates that the draft course id is set and the course exists.
 */
class setting_configtext_courseid extends \admin_setting_configtext {
    /**
     * Constructor.
     *
     * @param string $name
     * @param string $visiblename
     * @param string $description
     */
    public function __construct(string $name, string $visiblename, string $description) {
        parent::__construct($name, $visiblename, $description, '', PARAM_INT);
    }

    /**
     * Validate the submitted value.
     *
     * @param string $data
     * @return true|string
     */
    public function validate($data) {
        $parentresult = parent::validate($data);
        if ($parentresult !== true) {
            return $parentresult;
        }

        $courseid = (int) $data;
        if ($courseid <= 0) {
            return get_string('errordraftcourserequired', 'local_artqtml');
        }

        global $DB;
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            return get_string('errordraftcoursemissing', 'local_artqtml', $courseid);
        }

        return true;
    }
}
