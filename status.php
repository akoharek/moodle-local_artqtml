<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// It under the terms of the GNU General Public License as published by
// The Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// But WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// Along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Step 3 of the "New generation" flow: poll and display generation status.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_artqtml\local\draft_bank;
use local_artqtml\local\generation_status;
use local_artqtml\local\question_types;

require_login();

$context = context_system::instance();
require_capability('local/artqtml:use', $context);

$generationid = required_param('generationid', PARAM_INT);

$generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid], '*', MUST_EXIST);

if ($generation->status === generation_status::STARTED) {
    redirect(new moodle_url('/local/artqtml/generate.php', ['id' => $generationid]));
}

/**
 * Roll back an in-progress or failed generation: delete its draft bank/questions.
 *
 * @param stdClass $generation
 * @return void
 */
function local_artqtml_rollback(stdClass $generation): void {
    // Shared with the pipeline security gate — keep Abort and task recover identical.
    $fresh = \local_artqtml\local\generation_recover::to_started($generation, null);
    // Keep the caller's in-memory row aligned (retry/abort continue using this object).
    $generation->draftcategoryid = $fresh->draftcategoryid;
    $generation->pendingdata = $fresh->pendingdata;
    $generation->countdiscrepancy = $fresh->countdiscrepancy;
    $generation->status = $fresh->status;
    $generation->error = $fresh->error;
    $generation->timemodified = $fresh->timemodified;
}

if (optional_param('abort', 0, PARAM_BOOL)) {
    if (!data_submitted()) {
        throw new moodle_exception('invalidrequest');
    }
    require_sesskey();
    if (generation_status::is_in_progress($generation->status)) {
        local_artqtml_rollback($generation);

        $generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid], '*', MUST_EXIST);

        \local_artqtml\event\generation_aborted::create([
            'objectid' => $generationid,
            'context'  => $context,
        ])->trigger();
    }
    redirect(new moodle_url('/local/artqtml/generate.php', ['id' => $generationid]));
}

// (újrapróbálás): full restart from zero. State-changing: POST + sesskey only (no GET+sesskey URL).
if (optional_param('retry', 0, PARAM_BOOL)) {
    if (!data_submitted()) {
        throw new moodle_exception('invalidrequest');
    }
    require_sesskey();
    if ($generation->status === generation_status::FAILED && !draft_bank::is_configured()) {
        \core\notification::error(get_string('errordraftcoursenotconfigured', 'local_artqtml'));
        redirect(new moodle_url('/local/artqtml/generate.php', ['id' => $generationid]));
    }
    if ($generation->status === generation_status::FAILED) {
        $running = \local_artqtml\local\generation_start_policy::find_running((int) $USER->id, $generationid);
        if ($running !== null) {
            redirect(
                \local_artqtml\local\generation_list::open_url($running),
                get_string('errorgenerationalreadyrunning', 'local_artqtml', format_string((string) $running->name)),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

        local_artqtml_rollback($generation);

        $draftcategoryid = draft_bank::create($generation);
        $generation->draftcategoryid = $draftcategoryid;
        $generation->status = generation_status::GENERATING;
        $generation->error = null;
        $generation->timemodified = time();
        $DB->update_record('local_artqtml_generations', $generation);
    }
    redirect(new moodle_url('/local/artqtml/status.php', ['generationid' => $generationid]));
}

$PAGE->set_url('/local/artqtml/status.php', ['generationid' => $generationid]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->add_body_class('mediumwidth');
$PAGE->set_title(get_string('statusheading', 'local_artqtml'));
$PAGE->set_heading(get_string('statusheading', 'local_artqtml'));
$PAGE->requires->js_call_amd('local_artqtml/status', 'init');

$questioncount = $DB->count_records('local_artqtml_questions', ['generationid' => $generationid]);
$tokenwarningmessage = '';

$approveurl = new moodle_url('/local/artqtml/approve.php', ['generationid' => $generationid]);
$backurl = new moodle_url('/local/artqtml/generate.php', ['id' => $generationid]);
$retryurl = new moodle_url('/local/artqtml/status.php', ['generationid' => $generationid, 'retry' => 1]);
$aborturl = new moodle_url('/local/artqtml/status.php', ['generationid' => $generationid, 'abort' => 1]);

echo $OUTPUT->header();
echo local_artqtml_model_warning_banner();
echo local_artqtml_owner_warning_banner($generation);
echo html_writer::tag('p', format_string($generation->name));

// Reserved warning region for status.js polling compatibility (message stays empty).
echo html_writer::div(
    html_writer::div($tokenwarningmessage, 'alert alert-warning mb-0', ['data-region' => 'tokenwarning-text']),
    'mb-3 d-none',
    ['data-region' => 'tokenwarning']
);

$countdiscrepancy = json_decode((string) $generation->countdiscrepancy, true);
$countdiscrepancymessage = (is_array($countdiscrepancy) && !empty($countdiscrepancy))
    ? question_types::format_count_discrepancy($countdiscrepancy)
    : '';
$discrepancyhidden = $countdiscrepancymessage === '' || $generation->status === generation_status::PARTIAL;
echo html_writer::div(
    html_writer::div($countdiscrepancymessage, 'alert alert-warning mb-0', ['data-region' => 'countdiscrepancy-text']),
    $discrepancyhidden ? 'mb-3 d-none' : 'mb-3',
    ['data-region' => 'countdiscrepancy']
);

$successcountspan = html_writer::tag('span', $questioncount, ['data-region' => 'success-count']);
echo html_writer::div(
    '✓ ' . get_string('generationcompletedsuccess', 'local_artqtml', $successcountspan),
    'alert alert-success' . ($generation->status === generation_status::COMPLETED ? ' mb-3' : ' mb-3 d-none'),
    ['data-region' => 'success']
);

if ($generation->status === generation_status::PARTIAL) {
    // The button is inside the notice, not down with Retry/Back, because it is the answer to the
    // Sentence directly above it - and because Retry means something else here: it restarts this
    // Generation from zero and throws away the questions it did produce. This one keeps them.
    $shortfall = \local_artqtml\local\missing_types::shortfall($generation);
    $retrytypeslink = '';
    if ($shortfall !== []) {
        $described = \local_artqtml\local\missing_types::describe($shortfall);
        $retrytypesbutton = new single_button(
            new moodle_url('/local/artqtml/retrytypes.php', ['generationid' => $generationid]),
            get_string('retrymissingtypes', 'local_artqtml'),
            'post',
            single_button::BUTTON_PRIMARY
        );
        $retrytypesbutton->class = 'singlebutton mt-2';
        $retrytypesbutton->add_confirm_action(get_string('retrymissingtypesconfirm', 'local_artqtml', $described));
        $retrytypesbutton->set_attribute('data-region', 'retrymissingtypes');
        $retrytypeslink = $OUTPUT->render($retrytypesbutton);
    }

    $partialreasons = \local_artqtml\local\partial_reason::render($generationid);

    echo html_writer::div(
        html_writer::tag('p', get_string('generationpartialnotice', 'local_artqtml')) .
            html_writer::tag('p', $countdiscrepancymessage, ['class' => 'mb-0 font-weight-bold']) .
            $partialreasons .
            $retrytypeslink,
        'alert alert-warning mb-3',
        ['data-region' => 'partial']
    );
}

echo html_writer::start_div('', [
    'data-region'            => 'artqtml-status',
    'data-generationid'      => $generationid,
    'data-initialstatus'     => $generation->status,
    'data-label-generating'  => get_string('stagegenerating', 'local_artqtml'),
    'data-label-validating'  => get_string('stagevalidating', 'local_artqtml'),
    'data-label-saving'      => get_string('stagesaving', 'local_artqtml'),
    'data-label-completed'   => get_string('status_completed', 'local_artqtml'),
    'data-label-partial'     => get_string('status_partial', 'local_artqtml'),
    'data-label-failed'      => get_string('status_failed', 'local_artqtml'),
    'data-progress-config'   => \local_artqtml\local\generation_progress::config_json(),
]);

$stage = \local_artqtml\local\generation_progress::for_status($generation->status);
$barpercent = $stage['percent'] ?? \local_artqtml\local\generation_progress::failed_percent($generation->pendingdata);
if ($generation->status === generation_status::GENERATING) {
    $barpercent = \local_artqtml\local\generation_progress::generating_percent($generation->pendingdata);
}
$barcolor = $stage['color'];
$barstriped = $stage['striped'];

switch ($generation->status) {
    case generation_status::GENERATING:
        $barlabel = get_string('stagegenerating', 'local_artqtml');
        // Name the type being generated, so six calls do not look like one stuck one.
        $intype = \local_artqtml\local\generation_progress::generating_type($generation->pendingdata);
        if ($intype !== '') {
            $barlabel .= ' - ' . \local_artqtml\local\question_types::label($intype);
        }
        break;
    case generation_status::VALIDATING:
        $barlabel = get_string('stagevalidating', 'local_artqtml');
        break;
    case generation_status::SAVING:
        $barlabel = get_string('stagesaving', 'local_artqtml');
        break;
    case generation_status::COMPLETED:
        $barlabel = get_string('status_completed', 'local_artqtml');
        break;
    case generation_status::PARTIAL:
        $barlabel = get_string('status_partial', 'local_artqtml');
        break;
    case generation_status::FAILED:
        $barlabel = get_string('status_failed', 'local_artqtml');
        break;
    default:
        $barlabel = get_string('stagegenerating', 'local_artqtml');
        break;
}

$barclasses = 'progress-bar ' . $barcolor;
if ($barstriped) {
    $barclasses .= ' progress-bar-striped progress-bar-animated';
}

echo html_writer::start_div('progress', ['style' => 'height: 2rem;']);
echo html_writer::div(
    '',
    $barclasses,
    [
        'data-region'   => 'progressbar',
        'role'          => 'progressbar',
        'style'         => 'width: ' . $barpercent . '%;',
        'aria-valuenow' => $barpercent,
        'aria-valuemin' => 0,
        'aria-valuemax' => 100,
    ]
);
echo html_writer::end_div();

echo html_writer::tag(
    'p',
    $barlabel . ' (' . $barpercent . '%)',
    ['class' => 'mb-3', 'data-region' => 'stagelabel']
);

echo html_writer::tag(
    'p',
    get_string('questioncountlabel', 'local_artqtml') . ' ' .
        html_writer::tag('span', $questioncount, ['data-region' => 'question-count'])
);

if (generation_status::is_in_progress($generation->status)) {
    $abortbutton = new single_button(
        $aborturl,
        get_string('abortgeneration', 'local_artqtml'),
        'post',
        single_button::BUTTON_DANGER
    );
    $abortbutton->class = 'singlebutton d-inline';
    $abortbutton->add_confirm_action(get_string('abortgenerationconfirm', 'local_artqtml'));
    $abortbutton->set_attribute('data-region', 'abortbutton');
    echo $OUTPUT->render($abortbutton);
}

echo html_writer::div(
    html_writer::link($approveurl, get_string('continuebutton', 'local_artqtml'), ['class' => 'btn btn-primary']),
    $generation->status === generation_status::PARTIAL ? '' : 'd-none',
    ['data-region' => 'continue']
);

$retrybutton = new single_button(
    $retryurl,
    get_string('retry', 'local_artqtml'),
    'post',
    single_button::BUTTON_PRIMARY
);
$retrybutton->class = 'singlebutton d-inline mr-2';
$retrybutton->add_confirm_action(get_string('retryconfirm', 'local_artqtml', format_string($generation->name)));
$retrylink = $OUTPUT->render($retrybutton);

$technicalerror = has_capability('local/artqtml:configure', $context) ? s($generation->error ?? '') : '';

echo html_writer::div(
    html_writer::tag('p', get_string('generationfailed', 'local_artqtml'), ['class' => 'text-danger']) .
        html_writer::tag(
            'p',
            get_string('error_apifailed', 'local_artqtml'),
            ['class' => 'text-danger font-weight-bold', 'data-region' => 'error-generic']
        ) .
        html_writer::tag(
            'p',
            $technicalerror,
            ['class' => 'text-muted small' . ($technicalerror === '' ? ' d-none' : ''), 'data-region' => 'error-technical']
        ) .
        $retrylink .
        html_writer::link($backurl, get_string('backtosettingsshort', 'local_artqtml'), ['class' => 'btn btn-secondary']),
    'd-none',
    ['data-region' => 'error']
);

echo html_writer::end_div();

echo $OUTPUT->footer();
