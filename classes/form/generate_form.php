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
 * Question settings form: scale difficulty, per-type counts and detailed options
 * (IH/FE/SR, scale difficulty).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\form;

use local_artqtml\local\question_types;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Step 2 of the "New generation" flow.
 */
class generate_form extends \moodleform {
    /**
     * Helper.
     *
     * @var array<string, string[]> the three levels the scale mode offers, in display order.
     */
    public const MODE_LEVELS = [
        'scale' => ['easy', 'medium', 'hard'],
    ];

    /** @var array<string, string> level key -> lang string key. */
    public const LEVEL_STRINGS = [
        'easy'   => 'scale_easy',
        'medium' => 'scale_medium',
        'hard'   => 'scale_hard',
    ];

    /**
     * Form definition.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;
        $generation = $this->_customdata['generation'];

        $mform->addElement('hidden', 'id', $generation->id);
        $mform->setType('id', PARAM_INT);

        // Set by amd/src/generatesettings.js immediately before submitting, so generate.php can
        // tell "Generálás indítása" apart from "Mentés és kilépés".
        $mform->addElement('hidden', 'artqtmlaction', 'generate');
        $mform->setType('artqtmlaction', PARAM_ALPHA);

        // Difficulty is always scale. Kept as a hidden field so settings JSON and the AMD
        // module keep reading the same field name.
        $mform->addElement('hidden', 'difficultymode', 'scale');
        $mform->setType('difficultymode', PARAM_ALPHA);

        // Azonosítók: read-only.
        $mform->addElement('header', 'idheader', get_string('idsectionheading', 'local_artqtml'));
        $mform->setExpanded('idheader');
        $mform->addElement(
            'static',
            'namedisplay',
            get_string('generationname', 'local_artqtml'),
            format_string($generation->name)
        );
        $mform->addElement(
            'static',
            'shortnamedisplay',
            get_string('generationshortname', 'local_artqtml'),
            s($generation->shortname)
        );

        // 1. lépés — Easy/Medium/Hard scale.
        $mform->addElement('header', 'step1header', get_string('step1heading', 'local_artqtml'));
        $mform->setExpanded('step1header');
        $mform->addElement(
            'static',
            'difficultymodedisplay',
            get_string('difficultymode', 'local_artqtml'),
            get_string('difficultymode_scale', 'local_artqtml')
        );

        // 2. lépés — per-type × easy/medium/hard grid.
        $mform->addElement('header', 'step2header', get_string('step2heading', 'local_artqtml'));
        $mform->setExpanded('step2header');

        $levels = self::MODE_LEVELS['scale'];
        $mform->addElement(
            'static',
            'matrixhead_scale',
            '',
            \html_writer::tag('em', get_string('matrixcolumns', 'local_artqtml', (object) [
                'a' => get_string(self::LEVEL_STRINGS[$levels[0]], 'local_artqtml'),
                'b' => get_string(self::LEVEL_STRINGS[$levels[1]], 'local_artqtml'),
                'c' => get_string(self::LEVEL_STRINGS[$levels[2]], 'local_artqtml'),
            ]), ['class' => 'text-muted small'])
        );

        foreach (question_types::CODES as $code) {
            $row = [];
            foreach ($levels as $level) {
                $name = 'matrix_' . $code . '_' . $level;
                $label = question_types::label($code) . ' - ' . get_string(self::LEVEL_STRINGS[$level], 'local_artqtml');
                $row[] = $mform->createElement('text', $name, $label, [
                    'size' => 3,
                    'aria-label' => $label,
                    'title' => $label,
                ]);
                $mform->setType($name, PARAM_INT);
                $mform->setDefault($name, 0);
            }
            $groupname = 'matrixrow_scale_' . $code;
            $mform->addGroup($row, $groupname, question_types::label($code), ' ', false);
        }

        $mform->addElement('html', \html_writer::div(
            '',
            'font-weight-bold border-top pt-2 mt-2',
            [
                'id' => 'artqtml-step2total',
                'role' => 'status',
                'aria-live' => 'polite',
            ]
        ));

        // Knowledge source is always the uploaded/pasted source text.

        // Tagadó kérdés kiemelése, generálásonként felülírható.
        $mform->addElement('advcheckbox', 'negationhighlight', get_string('negationhighlight', 'local_artqtml'));
        $mform->setDefault('negationhighlight', get_config('local_artqtml', 'negationhighlightdefault') ? 1 : 0);

        // Részletes beállítások: egy accordion panel típusonként (IH/FE/SR).
        foreach (question_types::CODES as $code) {
            $mform->addElement('header', 'typeheader_' . $code, question_types::label($code));

            $supportsretry = question_types::supports_retry($code);
            $disabledattrs = $supportsretry ? [] : ['disabled' => 'disabled'];
            $retrydefault = $supportsretry && get_config('local_artqtml', 'retrydefault') ? 1 : 0;

            $mform->addElement('advcheckbox', 'retry_' . $code, get_string('retryenabled', 'local_artqtml'), '', $disabledattrs);
            $mform->setDefault('retry_' . $code, $retrydefault);
            $mform->addElement(
                'text',
                'retrypenalty_' . $code,
                get_string('retrypenalty', 'local_artqtml'),
                ['size' => 4] + $disabledattrs
            );
            $mform->setType('retrypenalty_' . $code, PARAM_INT);
            $mform->setDefault('retrypenalty_' . $code, 33);
            $mform->hideIf('retrypenalty_' . $code, 'retry_' . $code, 'notchecked');

            $mform->addElement('advcheckbox', 'feedback_' . $code, get_string('feedbackenabled', 'local_artqtml'));
            $mform->setDefault('feedback_' . $code, 1);

            $mform->addElement('advcheckbox', 'hint_' . $code, get_string('hintenabled', 'local_artqtml'));
            $mform->setDefault('hint_' . $code, 0);

            if (question_types::supports_option_explanation($code)) {
                $mform->addElement(
                    'advcheckbox',
                    'explanation_' . $code,
                    get_string('explanationenabled', 'local_artqtml')
                );
                $mform->setDefault(
                    'explanation_' . $code,
                    get_config('local_artqtml', 'explanationdefault') ? 1 : 0
                );
                $mform->addHelpButton('explanation_' . $code, 'explanationenabled', 'local_artqtml');
            }

            if ($code === 'SR') {
                $mform->addElement('text', 'sritemcount', get_string('sritemcountoverride', 'local_artqtml'), ['size' => 4]);
                $mform->setType('sritemcount', PARAM_INT);
                $mform->setDefault('sritemcount', 0);
                $mform->addHelpButton('sritemcount', 'sritemcountoverride', 'local_artqtml');
            }
        }

        $mform->addElement('header', 'actionsheader', get_string('actionsheading', 'local_artqtml'));
        $mform->setExpanded('actionsheader', true, true);

        // Pre-start size estimate (informational).
        $mform->addElement('html', \html_writer::div('', '', ['id' => 'artqtml-tokenestimate']));
    }

    /**
     * The field an error about the overall question count should attach to.
     *
     * @param string $mode the selected difficulty mode
     * @return string an element name that exists in this form
     */
    protected static function first_count_field(string $mode): string {
        $code = question_types::CODES[0];

        return isset(self::MODE_LEVELS[$mode])
            ? 'matrixrow_' . $mode . '_' . $code
            : 'matrixrow_scale_' . $code;
    }

    /**
     * Validate the submitted question settings.
     *
     * @param array $data submitted form values
     * @param array $files submitted files
     * @return array error messages keyed by form field name
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $mode = 'scale';
        $firstfield = self::first_count_field($mode);
        $total = 0;

        foreach (question_types::CODES as $code) {
            foreach (self::MODE_LEVELS[$mode] as $level) {
                $name = 'matrix_' . $code . '_' . $level;
                $value = (int) ($data[$name] ?? 0);
                if ($value < 0) {
                    $errors['matrixrow_' . $mode . '_' . $code] = get_string('err_numeric', 'form');
                }
                $total += $value;
            }
        }

        if ($total <= 0) {
            $errors[$firstfield] = get_string('errornoquestions', 'local_artqtml');
        }

        $maxperrun = (int) get_config('local_artqtml', 'maxquestionsperrun');
        if ($maxperrun > 0 && $total > $maxperrun) {
            $errors[$firstfield] = get_string('errortoomanyquestions', 'local_artqtml', $maxperrun);
        }

        $sritemcount = (int) ($data['sritemcount'] ?? 0);
        $srcount = 0;
        foreach (self::MODE_LEVELS[$mode] as $level) {
            $srcount += (int) ($data['matrix_SR_' . $level] ?? 0);
        }
        if ($srcount > 0 && $sritemcount !== 0) {
            if ($sritemcount < 2) {
                $errors['sritemcount'] = get_string('errorsritemcounttoolow', 'local_artqtml');
            } else if ($sritemcount > 10) {
                $errors['sritemcount'] = get_string('errorsritemcounttoohigh', 'local_artqtml');
            }
        }

        return $errors;
    }
}
