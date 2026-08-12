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
 * Helper.
 *
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\admin;

/**
 * Adds a minimum-value cross-check on top of the normal PARAM_INT text setting.
 */
class setting_configtext_min extends \admin_setting_configtext {
    /** @var int the smallest value this setting will accept. */
    protected int $minvalue;

    /**
     * Constructor.
     *
     * @param string $name
     * @param string $visiblename
     * @param string $description
     * @param mixed $defaultsetting
     * @param int $minvalue
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, int $minvalue = 1) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_INT);
        $this->minvalue = $minvalue;
    }

    /**
     * Validate the submitted value.
     *
     * @param string $data
     * @return true|string true if valid, an error message string otherwise
     */
    public function validate($data) {
        $parentresult = parent::validate($data);
        if ($parentresult !== true) {
            return $parentresult;
        }

        if ((int) $data < $this->minvalue) {
            return get_string('errorvaluetoolow', 'local_artqtml', $this->minvalue);
        }

        return true;
    }
}
