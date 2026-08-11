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
 * Question settings form: difficulty mode, per-type counts and detailed options
 * (functional spec ch.4).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\form;

use local_artqtml\local\question_types;
use local_artqtml\local\license_checker;
use local_artqtml\local\security_filter;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Step 2 of the "New generation" flow.
 */
class generate_form extends \moodleform {
    /**
     * @var array<string, string[]> the three levels each difficulty mode offers, in display order.
     *
     * BL-35: free text is absent on purpose - it has no levels, and its per-type count is a single
     * field rather than a row of three.
     */
    public const MODE_LEVELS = [
        'scale' => ['easy', 'medium', 'hard'],
        'bloom' => ['remember', 'understand', 'apply'],
    ];

    /** @var array<string, string> level key -> lang string key, so the labels are not duplicated. */
    public const LEVEL_STRINGS = [
        'easy'       => 'scale_easy',
        'medium'     => 'scale_medium',
        'hard'       => 'scale_hard',
        'remember'   => 'bloom_remember',
        'understand' => 'bloom_understand',
        'apply'      => 'bloom_apply',
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
        // tell "Generálás indítása" (validated, starts the task) apart from "Mentés és kilépés"
        // (raw save via get_submitted_data(), no validation - Beal-025/027) even though both
        // buttons live outside this <form> and are triggered via form.requestSubmit().
        $mform->addElement('hidden', 'artqtmlaction', 'generate');
        $mform->setType('artqtmlaction', PARAM_ALPHA);

        // Azonosítók (Beal-022): read-only.
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

        // 1. lépés (Beal-001-005).
        $mform->addElement('header', 'step1header', get_string('step1heading', 'local_artqtml'));
        $mform->setExpanded('step1header');

        $mform->addElement('select', 'difficultymode', get_string('difficultymode', 'local_artqtml'), [
            'bloom'    => get_string('difficultymode_bloom', 'local_artqtml'),
            'scale'    => get_string('difficultymode_scale', 'local_artqtml'),
            'freetext' => get_string('difficultymode_freetext', 'local_artqtml'),
        ]);
        $mform->setDefault('difficultymode', get_config('local_artqtml', 'defaultdifficultymode') ?: 'scale');

        // BL-35: the level counts used to live here, generation-wide, and the per-type counts lived
        // in step 2 - two independent axes with nothing joining them. Which type got the easy
        // question was therefore decided by nobody: the model paired them, and M-16 only checked
        // that the two totals matched. Both sets of fields have moved into the step 2 grid below,
        // where a count belongs to one type at one level and says what the teacher actually meant.
        //
        // Free text is the exception and keeps its own two fields: it has no levels to grid.

        $mform->addElement(
            'textarea',
            'freetextdescription',
            get_string('freetextdescription', 'local_artqtml'),
            ['rows' => 4, 'cols' => 60]
        );
        $mform->setType('freetextdescription', PARAM_TEXT);
        $mform->hideIf('freetextdescription', 'difficultymode', 'neq', 'freetext');

        // BL-35: the "Total number of questions" field that used to sit next to the description is
        // gone. The specification said outright what it was for - free text has no levels to add
        // up, and the X/Y difference indicator needed a left-hand number from somewhere. The
        // indicator went with the two-axis form, and nothing else ever read the value: the prompt
        // takes the free-text description, never the count, and the real per-type numbers are the
        // count_<CODE> fields below. A number field a teacher can fill in that changes nothing is
        // worse than no field.
        //
        // No live total in step 1 either: after BL-35 the counts live only in step 2, so a Total
        // between the steps was the same number printed twice.

        // 2. lépés (Beal-006/007).
        $mform->addElement('header', 'step2header', get_string('step2heading', 'local_artqtml'));
        $mform->setExpanded('step2header');

        // BL-35: one row per question type, one field per level. This is the whole point of the
        // change - "two easy True/False questions" is now something the teacher can ask for, and
        // it is what the per-type API call is given. Pinning that down for the 2026-08-01
        // measurement took twelve separate generations, because the form could not express it.
        //
        // Two grids, one per difficulty mode, each hidden unless its mode is selected. The level
        // names differ between the modes and so do the field names, so a mode switch cannot leave
        // a stale value behind in a field the other mode also reads.
        //
        // Column hints must be real form elements (static), not raw html: hideIf only applies to
        // registered elements, so an html hint for the inactive mode stayed visible after a switch.
        foreach (self::MODE_LEVELS as $mode => $levels) {
            $mform->addElement(
                'static',
                'matrixhead_' . $mode,
                '',
                \html_writer::tag('em', get_string('matrixcolumns', 'local_artqtml', (object) [
                    'a' => get_string(self::LEVEL_STRINGS[$levels[0]], 'local_artqtml'),
                    'b' => get_string(self::LEVEL_STRINGS[$levels[1]], 'local_artqtml'),
                    'c' => get_string(self::LEVEL_STRINGS[$levels[2]], 'local_artqtml'),
                ]), ['class' => 'text-muted small'])
            );
            $mform->hideIf('matrixhead_' . $mode, 'difficultymode', 'neq', $mode);

            foreach (question_types::CODES as $code) {
                $row = [];
                foreach ($levels as $level) {
                    $name = 'matrix_' . $code . '_' . $level;
                    $label = question_types::label($code) . ' - ' . get_string(self::LEVEL_STRINGS[$level], 'local_artqtml');
                    $row[] = $mform->createElement('text', $name, $label, [
                        'size' => 3,
                        // The visible label belongs to the row, so each box needs its own
                        // accessible name - a screen reader otherwise reads three identical
                        // unlabelled fields.
                        'aria-label' => $label,
                        'title' => $label,
                    ]);
                    $mform->setType($name, PARAM_INT);
                    $mform->setDefault($name, 0);
                }
                $groupname = 'matrixrow_' . $mode . '_' . $code;
                $mform->addGroup($row, $groupname, question_types::label($code), ' ', false);
                $mform->hideIf($groupname, 'difficultymode', 'neq', $mode);
            }
        }

        // Free text has no levels, so it keeps a single count per type.
        foreach (question_types::CODES as $code) {
            $mform->addElement('text', 'count_' . $code, question_types::label($code), ['size' => 4]);
            $mform->setType('count_' . $code, PARAM_INT);
            $mform->setDefault('count_' . $code, 0);
            $mform->hideIf('count_' . $code, 'difficultymode', 'neq', 'freetext');
        }

        // One shared live total for whichever mode is active (scale grid, Bloom grid, or free-text
        // counts). Per-mode total divs used to sit after each grid as raw html and stayed visible
        // with the inactive mode's hint, so the page showed the same "Total: N" twice.
        $mform->addElement('html', \html_writer::div(
            '',
            'font-weight-bold border-top pt-2 mt-2',
            [
                'id' => 'artqtml-step2total',
                'role' => 'status',
                'aria-live' => 'polite',
            ]
        ));

        // Tudásforrás (Beal-018).
        $mform->addElement('select', 'knowledgesource', get_string('knowledgesource', 'local_artqtml'), [
            'sourceonly'  => get_string('knowledgesource_sourceonly', 'local_artqtml'),
            'ownknowledge' => get_string('knowledgesource_ownknowledge', 'local_artqtml'),
        ]);
        $mform->setDefault('knowledgesource', 'sourceonly');

        // BL-34 (Admin-070): diagnostics for this one generation - store the full AI request and
        // response in the log so a failure can be read out of the database instead of guessed at.
        //
        // Only offered to a user who can configure the plugin. Not merely disabled for everyone
        // else: an unexplained greyed-out box invites the question "what am I missing?", and the
        // answer would be "nothing you can act on". The stored payloads are large, so leaving this
        // to every teacher's discretion would grow the log with nobody deciding to.
        if (has_capability('local/artqtml:configure', \context_system::instance())) {
            $mform->addElement('advcheckbox', 'diagnostics', get_string('diagnosticsmode', 'local_artqtml'));
            $mform->setDefault('diagnostics', (int) ($generation->diagnostics ?? 0));
            $mform->addHelpButton('diagnostics', 'diagnosticsmode', 'local_artqtml');
        }

        // Tagadó kérdés kiemelése (Beal-016), generálásonként felülírható.
        $mform->addElement('advcheckbox', 'negationhighlight', get_string('negationhighlight', 'local_artqtml'));
        $mform->setDefault('negationhighlight', get_config('local_artqtml', 'negationhighlightdefault') ? 1 : 0);

        // Részletes beállítások (Beal-011/012): egy accordion panel típusonként.
        foreach (question_types::CODES as $code) {
            $mform->addElement('header', 'typeheader_' . $code, question_types::label($code));

            // Beal-014: IH still gets the control, just disabled/grayed - not omitted outright,
            // so the user can see multiple attempts exists as a concept and why it's unavailable
            // here, rather than wondering if the panel rendered incompletely.
            $supportsretry = question_types::supports_retry($code);
            $disabledattrs = $supportsretry ? [] : ['disabled' => 'disabled'];

            // Admin-023: default sourced from the admin setting, same pattern as negationhighlight
            // above - IH stays forced-disabled regardless of the configured default.
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

            // Gen-025: independent per-type switch, replacing the old single generation-wide
            // "feedbackenabled" checkbox - a teacher generating a mix of types may want feedback
            // for some but not others.
            $mform->addElement('advcheckbox', 'feedback_' . $code, get_string('feedbackenabled', 'local_artqtml'));
            $mform->setDefault('feedback_' . $code, 1);

            // Gen-022: independent of both retry and feedback above - a teacher may want a hint
            // available on a type without also wanting the "try again" penalty mechanic, or vice
            // versa. Rendered for all six types (Cursor audit v3 #5) - question_importer.php's
            // own, separate question_types::supports_hints() check is what still limits which
            // types get a real Moodle "try again" hint attached (not IH, not EH, see that
            // method's own docblock); for those two, the AI-generated hint content is stored and
            // shown in the plugin's own approve.php review UI instead.
            // V20 #15: hints default OFF, independently of the admin retry default - the two are
            // unrelated features (a hint is available without the "try again" penalty mechanic and
            // vice versa), so the retry default must not silently pre-tick the hint checkbox.
            $mform->addElement('advcheckbox', 'hint_' . $code, get_string('hintenabled', 'local_artqtml'));
            $mform->setDefault('hint_' . $code, 0);

            // BL-29: an explanation per answer option - the thing that tells a student why the
            // option they picked is wrong, which is where a distractor earns its keep. Off by
            // default, like the hint and for the same reason: it is written per option, so a
            // six-question generation with four options each is twenty-four extra sentences.
            //
            // Shown for IH, FE and FT only, and absent - not disabled - elsewhere. The "present but
            // greyed" treatment used for retry on the IH panel is right where the teacher might
            // reasonably look for the feature; here the two remaining types have nowhere to put an
            // explanation at all. Ordering keeps only combined feedback for the whole question, and
            // short answer and essay have no options to explain. A greyed box would invite the
            // question "what am I missing?", and the honest answer is "nothing that exists".
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

            // M-26: SR (ordering) item count is admin-configured (Admin-036) but overridable per
            // generation, same pattern as the retry/instruction fields above - 0 (the field's own
            // default) means "use the admin default", set by generate_questions_task::build_prompt().
            if ($code === 'SR') {
                $mform->addElement('text', 'sritemcount', get_string('sritemcountoverride', 'local_artqtml'), ['size' => 4]);
                $mform->setType('sritemcount', PARAM_INT);
                $mform->setDefault('sritemcount', 0);
                // BL-31: the only field on this form whose value can silently reduce how many
                // questions come back. An ordering question needs this many items the source text
                // genuinely sequences, and the generator returns fewer questions rather than
                // padding a list - measured, and invisible from the screen without saying so here.
                $mform->addHelpButton('sritemcount', 'sritemcountoverride', 'local_artqtml');
            }

            // Admin-026: the field itself can be hidden entirely by admin setting; Admin-027's
            // admin-level default instruction still applies via generate_questions_task's prompt
            // builder even when hidden (see build_prompt()'s fallback), so hiding this field never
            // silently drops the admin's own default instruction from the prompt.
            if (get_config('local_artqtml', 'showstandardinstruction')) {
                $mform->addElement(
                    'textarea',
                    'instruction_' . $code,
                    get_string('aiinstruction', 'local_artqtml'),
                    ['rows' => 2, 'cols' => 60]
                );
                $mform->setType('instruction_' . $code, PARAM_TEXT);
                $mform->setDefault(
                    'instruction_' . $code,
                    (string) (get_config('local_artqtml', 'instructiondefault_' . strtolower($code)) ?: '')
                );
                // The field's effect is invisible from the form itself, so it is stated next to the
                // box rather than behind a help icon.
                //
                // What it used to say - and what the code used to do - was that this text goes
                // "verbatim into the generation's system prompt". That was accurate, and it was
                // the defect: a per-generation text box was writing system prompt. Since
                // 2026-08-04 the text reaches the model as a teacher preference in the structured
                // user message, so it still shapes the questions and no longer carries the
                // administrator's authority.
                $mform->addElement(
                    'static',
                    'instructionnote_' . $code,
                    '',
                    get_string('aiinstructionnote', 'local_artqtml')
                );
            }
        }

        // A per-type accordion headerek után egy saját, kényszerítetten kinyitott fieldset kell
        // a token becslésnek, különben az utolsó (typeheader_RV) összecsukott panel belsejébe
        // kerülne - a headerek utáni elemek mindig az adott header fieldsetjéhez tartoznak a
        // következő headerig (lásd Moodle formslib.php).
        $mform->addElement('header', 'actionsheader', get_string('actionsheading', 'local_artqtml'));
        $mform->setExpanded('actionsheader', true, true);

        // Token becslés (Beal-019/020/021): csak tájékoztató, nem blokkol.
        $mform->addElement('html', \html_writer::div('', '', ['id' => 'artqtml-tokenestimate']));

        // No submit element here: generate.php renders "Generálás indítása" and "Mentés és
        // kilépés" outside this <form>, in the same row as "Törlés és kilépés"/"Vissza", and
        // amd/src/generatesettings.js submits this form on their behalf via requestSubmit().
    }

    /**
     * The field an error about the overall question count should attach to, for the given mode.
     *
     * mform needs a real element name or the message is rendered nowhere, and the grid renamed the
     * element that used to serve this purpose.
     *
     * @param string $mode the selected difficulty mode
     * @return string an element name that exists in this form
     */
    protected static function first_count_field(string $mode): string {
        $code = question_types::CODES[0];

        return isset(self::MODE_LEVELS[$mode])
            ? 'matrixrow_' . $mode . '_' . $code
            : 'count_' . $code;
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

        // BL-35: the counts now come from the grid for the two levelled modes, and from the plain
        // per-type field only in free text mode. Which set is read follows the selected mode, so a
        // value left behind in the other mode's fields can never contribute to the total.
        $mode = (string) ($data['difficultymode'] ?? 'scale');
        $firstfield = self::first_count_field($mode);
        $total = 0;

        if (isset(self::MODE_LEVELS[$mode])) {
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
        } else {
            foreach (question_types::CODES as $code) {
                $value = (int) ($data['count_' . $code] ?? 0);
                if ($value < 0) {
                    $errors['count_' . $code] = get_string('err_numeric', 'form');
                }
                $total += $value;
            }
        }

        if ($total <= 0) {
            $errors[$firstfield] = get_string('errornoquestions', 'local_artqtml');
        }

        // Lic-028: a question_limit licence must not be overrun. Checked here rather than at the
        // gates in generate.php so the user keeps the page and the values they typed and can lower
        // the count - and because the draft-save path uses get_submitted_data(), which skips
        // validation, the generation stays saveable while it cannot be started.
        $remaining = license_checker::remaining_questions();
        if ($remaining !== null && $total > $remaining) {
            $errors[$firstfield] = get_string('errorlicensenotenoughquestions', 'local_artqtml');
        }

        // Both of these fields reach the model, so both get the same screening the uploaded source
        // text already gets in upload.php. Without it the same user, in the same flow, would have
        // one input filtered and another passed through untouched.
        //
        // 2026-08-04: neither of them is interpolated into the system prompt any more - they travel
        // as structured, explicitly untrusted data in the user message (see
        // generate_questions_task::build_user_content()). That is the change that actually removes
        // the authority; this screen stays because defence in depth means the obvious attempt is
        // still refused at the door, and because a filter is not a licence to put user text into a
        // system message - which is why the fields moved regardless of it.
        foreach (question_types::CODES as $code) {
            $instruction = trim((string) ($data['instruction_' . $code] ?? ''));
            if ($instruction === '') {
                continue;
            }
            $unsafe = security_filter::has_sql_injection($instruction)
                || security_filter::has_prompt_injection($instruction);
            if ($unsafe) {
                $errors['instruction_' . $code] = get_string('errorsecurityfilter', 'local_artqtml');
            }
        }

        // The free-text difficulty description was never screened at all - it was the one user
        // input in this form that reached the model with no check of any kind. Only screened in
        // the mode where it is actually used, so a value left behind by switching modes cannot
        // block a submission it has no effect on.
        if ($mode === 'freetext') {
            $description = trim((string) ($data['freetextdescription'] ?? ''));
            if ($description !== '') {
                $unsafe = security_filter::has_sql_injection($description)
                    || security_filter::has_prompt_injection($description);
                if ($unsafe) {
                    $errors['freetextdescription'] = get_string('errorsecurityfilter', 'local_artqtml');
                }
            }
        }

        $maxperrun = (int) get_config('local_artqtml', 'maxquestionsperrun');
        if ($maxperrun > 0 && $total > $maxperrun) {
            $errors[$firstfield] = get_string('errortoomanyquestions', 'local_artqtml', $maxperrun);
        }

        // M-16 is gone with BL-35, and the requirement it enforced no longer exists. It
        // cross-checked step 1's level totals against step 2's type totals, because the two were
        // entered independently and could disagree. There is one set of numbers now - the grid -
        // so there is nothing left to disagree.

        // M-26: 0 means "use the admin default" (validated separately, elsewhere); an explicit
        // override only makes sense if it can actually satisfy M-07's own >= 2 items rule.
        $sritemcount = (int) ($data['sritemcount'] ?? 0);
        if ((int) ($data['count_SR'] ?? 0) > 0 && $sritemcount !== 0) {
            if ($sritemcount < 2) {
                $errors['sritemcount'] = get_string('errorsritemcounttoolow', 'local_artqtml');
            } else if ($sritemcount > 10) {
                // Gen-029: an ordering question with too many items becomes unwieldy for
                // students to actually reorder in the quiz UI.
                $errors['sritemcount'] = get_string('errorsritemcounttoohigh', 'local_artqtml');
            }
        }

        return $errors;
    }
}
