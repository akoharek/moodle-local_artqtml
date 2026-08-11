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
 * License file upload form (functional spec Lic-001).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Single filepicker element accepting a .lic file, plus a submit button.
 */
class license_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement(
            'filepicker',
            'licensefile',
            get_string('licenseuploadfile', 'local_artqtml'),
            null,
            ['accepted_types' => ['.lic'], 'maxbytes' => 51200]
        );
        $mform->addRule('licensefile', get_string('required'), 'required', null, 'client');

        $this->add_action_buttons(false, get_string('licenseuploadbutton', 'local_artqtml'));
    }
}
