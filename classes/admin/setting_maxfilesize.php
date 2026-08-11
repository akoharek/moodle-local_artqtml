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
 * "Max file size" admin setting, rejected at save time if it exceeds Moodle's own site-wide
 * upload limit (Admin-035, Felt-030).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\admin;

/**
 * Adds a $CFG->maxbytes cross-check on top of the normal PARAM_INT text setting.
 */
class setting_maxfilesize extends \admin_setting_configtext {
    /**
     * Validate the submitted value.
     *
     * @param string $data
     * @return true|string true if valid, an error message string otherwise
     */
    public function validate($data) {
        global $CFG;

        $parentresult = parent::validate($data);
        if ($parentresult !== true) {
            return $parentresult;
        }

        // Only enforce the cross-check when the site actually has a positive upload limit.
        // $CFG->maxbytes == 0 means "no site-wide limit" (and is also the transient state while
        // plugin defaults are first applied during install), so there is nothing to exceed.
        if ((int) $data > 0 && (int) $CFG->maxbytes > 0 && (int) $data > (int) $CFG->maxbytes) {
            return get_string('errormaxfilesizeexceeded', 'local_artqtml', display_size((int) $CFG->maxbytes));
        }

        return true;
    }
}
