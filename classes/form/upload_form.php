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
 * Source text upload form for a new AI quiz question generation.
 *
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Step 1 of the "New generation" flow: collect name, shortname, and source text.
 */
class upload_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;
        $maxbytes = (int) $this->_customdata['maxbytes'];

        $mform->addElement('hidden', 'id', (int) ($this->_customdata['editid'] ?? 0));
        $mform->setType('id', PARAM_INT);

        $mform->addElement('header', 'idheader', get_string('idsectionheading', 'local_artqtml'));
        $mform->setExpanded('idheader');

        $mform->addElement('text', 'name', get_string('generationname', 'local_artqtml'), ['size' => '64', 'maxlength' => 100]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 100), 'maxlength', 100, 'client');

        $mform->addElement(
            'text',
            'shortname',
            get_string('generationshortname', 'local_artqtml'),
            ['size' => '10', 'maxlength' => 8]
        );
        $mform->setType('shortname', PARAM_RAW);
        $mform->addRule('shortname', get_string('required'), 'required', null, 'client');
        $mform->addRule('shortname', get_string('maximumchars', '', 8), 'maxlength', 8, 'client');
        $mform->addHelpButton('shortname', 'generationshortname', 'local_artqtml');

        $mform->addElement('header', 'sourceheader', get_string('sourcesectionheading', 'local_artqtml'));
        $mform->setExpanded('sourceheader');

        $mform->addElement(
            'filepicker',
            'sourcefile',
            get_string('sourcefile', 'local_artqtml'),
            null,
            // Maxfiles is explicit rather than left to the default: the specification allows one
            // Source file, and every path downstream (extraction, the combined hash, the size
            // Limit) is written for one. Accepted_types is a browser convenience and is checked
            // Again on the server in validation(), because a direct POST never sees it.
            ['accepted_types' => ['.txt'], 'maxbytes' => $maxbytes, 'maxfiles' => 1]
        );
        $mform->addHelpButton('sourcefile', 'sourcefile', 'local_artqtml');

        $mform->addElement(
            'static',
            'sourcefilemaxsize',
            '',
            \html_writer::div(
                get_string('sourcefilemaxsize', 'local_artqtml', display_size($maxbytes)),
                'text-muted small'
            )
        );

        $mform->addElement(
            'textarea',
            'sourcetext',
            get_string('sourcetext', 'local_artqtml'),
            ['rows' => 15, 'cols' => 60, 'id' => 'id_sourcetext']
        );
        $mform->setType('sourcetext', PARAM_RAW);
        $mform->addHelpButton('sourcetext', 'sourcetext', 'local_artqtml');

        $mform->addElement('html', \html_writer::div('', '', ['id' => 'artqtml-textcounter']));

        $this->add_action_buttons(true, get_string('continue'));
    }

    /**
     * Server-side validation.
     *
     * @param array $data submitted form data
     * @param array $files submitted files
     * @return array errors keyed by element name
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = get_string('required');
        }

        $shortname = (string) ($data['shortname'] ?? '');
        if (trim($shortname) === '') {
            $errors['shortname'] = get_string('required');
        } else if (!preg_match('/^[a-zA-Z0-9]{1,8}$/', $shortname)) {
            $errors['shortname'] = get_string('errorshortnameformat', 'local_artqtml');
        }

        $draftitemid = (int) ($data['sourcefile'] ?? 0);
        $draftfiles = \local_artqtml\local\text_extractor::draft_files($draftitemid);
        $hasfile = !empty($files['sourcefile']) || !empty($draftfiles);

        // Server-side file checks. The filepicker's own accepted_types and maxfiles are client
        // Configuration and are not evidence about what actually arrived. text_extractor still
        // Rejects unsupported types and oversize drafts; this is the cheap form-level gate for
        // TXT upload path.
        if (count($draftfiles) > 1) {
            $errors['sourcefile'] = get_string('errorfiletoomany', 'local_artqtml');
        } else {
            foreach ($draftfiles as $draftfile) {
                if (!\local_artqtml\local\text_extractor::is_supported_file($draftfile)) {
                    $errors['sourcefile'] = get_string('errorfileunsupportedtype', 'local_artqtml');
                }
            }
        }
        $sourcetext = (string) ($data['sourcetext'] ?? '');
        if (trim($sourcetext) === '' && !$hasfile) {
            $errors['sourcetext'] = get_string('errorsourcetextrequired', 'local_artqtml');
        }

        if (trim($sourcetext) !== '' && \local_artqtml\local\source_text_limit::is_exceeded($sourcetext)) {
            $errors['sourcetext'] = \local_artqtml\local\source_text_limit::error_message($sourcetext);
        }

        return $errors;
    }
}
