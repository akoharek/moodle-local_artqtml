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
 * Step 3 of the "New generation" flow: poll and display generation status (functional spec ch.5).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_artqtml\local\draft_bank;
use local_artqtml\local\generation_status;
use local_artqtml\local\token_budget;
use local_artqtml\local\question_types;

require_login();

$context = context_system::instance();
require_capability('local/artqtml:use', $context);

$generationid = required_param('generationid', PARAM_INT);

$generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid], '*', MUST_EXIST);
// Glob-031: collaborative :use by design; delete is owner-only (see delete.php).
// :use (already required above) opens any generation; owner shown via banner, not access-gated.

// Recoverable pipeline rollback (Finding #5 security gate, or Abort) leaves status=started.
// This page is the live progress view for in-flight runs — send the teacher back to settings
// (from which they can open upload). generate.php surfaces any stored generic error once.
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
    // Shared with the pipeline security gate (Finding #5) — keep Abort and task recover identical.
    $fresh = \local_artqtml\local\generation_recover::to_started($generation, null);
    // Keep the caller's in-memory row aligned (retry/abort continue using this object).
    $generation->draftcategoryid = $fresh->draftcategoryid;
    $generation->pendingdata = $fresh->pendingdata;
    $generation->countdiscrepancy = $fresh->countdiscrepancy;
    $generation->status = $fresh->status;
    $generation->error = $fresh->error;
    $generation->timemodified = $fresh->timemodified;
}

// Megszakítás (Gen-008/009/010): available while generating/validating.
// Glob-031: collaborative :use by design; delete is owner-only (see delete.php).
// Abort rolls back in-progress work for any :use holder; it does not purge the generation.
// State-changing: POST + sesskey only (no GET+sesskey URL).
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

// Gen-015 (újrapróbálás): full restart from zero.
// State-changing: POST + sesskey only (no GET+sesskey URL).
if (optional_param('retry', 0, PARAM_BOOL)) {
    if (!data_submitted()) {
        throw new moodle_exception('invalidrequest');
    }
    require_sesskey();
    if ($generation->status === generation_status::FAILED && !draft_bank::is_configured()) {
        \core\notification::error(get_string('errordraftcoursenotconfigured', 'local_artqtml'));
        redirect(new moodle_url('/local/artqtml/generate.php', ['id' => $generationid]));
    }
    // BL-57 (Andras, 2026-08-06): Retry puts a generation back into 'generating', so it is a start
    // like any other and it asks the same question - does this user already have one running? It
    // did not when BL-57 was first built, which meant this button walked straight past the limit.
    //
    // The check sits BEFORE local_artqtml_rollback(), because a refusal may not destroy the
    // failed attempt's draft bank and questions on the way out. Same message and same destination
    // as the start path: the running generation's status page, which is where the progress is shown
    // and where the Megszakítás button is - so a stuck run can be cleared from the page the teacher
    // is sent to.
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

        // Gen-015: the "generating" status set above is the queue signal for the
        // process_pending_generations scheduled task - see generate.php.
    }
    redirect(new moodle_url('/local/artqtml/status.php', ['generationid' => $generationid]));
}

$PAGE->set_url('/local/artqtml/status.php', ['generationid' => $generationid]);
$PAGE->set_context($context);
// These pages carry wide data tables, and 'standard' is the one layout Boost caps at
// $course-content-maxwidth (830px) from the md breakpoint up
// (theme/boost/scss/moodle/layout.scss:56-62) - which squeezed the columns into a narrow strip on
// a full-screen browser while the page around them stayed wide, and pushed the actions column off
// the right edge. 'report' is byte-for-byte the same layout in Boost's config.php (same file, same
// regions, same default region) and differs only in the body class, which that rule does not
// match; it is what core's own wide-table pages use (report/log/index.php:100).
//
// 'mediumwidth' then caps the result at $medium-content-maxwidth (1120px, variables.scss:28)
// instead of letting it run the full viewport width. Decided 2026-07-29 against both extremes:
// 830px is what the demo complaint was about, and edge-to-edge spreads eight columns thin on a
// wide screen. Note that core defines this class but never sets it - every core page picks
// 'limitedwidth' or nothing - so the rule to lean on is the SCSS, not core precedent.
$PAGE->set_pagelayout('report');
$PAGE->add_body_class('mediumwidth');
$PAGE->set_title(get_string('statusheading', 'local_artqtml'));
$PAGE->set_heading(get_string('statusheading', 'local_artqtml'));
$PAGE->requires->js_call_amd('local_artqtml/status', 'init');

$questioncount = $DB->count_records('local_artqtml_questions', ['generationid' => $generationid]);
$tokenwarningmessage = token_budget::warning_message($generationid);

$approveurl = new moodle_url('/local/artqtml/approve.php', ['generationid' => $generationid]);
$backurl = new moodle_url('/local/artqtml/generate.php', ['id' => $generationid]);
$retryurl = new moodle_url('/local/artqtml/status.php', ['generationid' => $generationid, 'retry' => 1]);
$aborturl = new moodle_url('/local/artqtml/status.php', ['generationid' => $generationid, 'abort' => 1]);

echo $OUTPUT->header();
echo local_artqtml_model_warning_banner();
echo local_artqtml_owner_warning_banner($generation);
echo html_writer::tag('p', format_string($generation->name));

// Gen-018/019: rendered up front if already known, otherwise revealed live by amd/src/status.js
// once the AJAX poll's tokenwarningmessage turns non-empty (e.g. the warning appears
// mid-generation). Plain markup (not $OUTPUT->notification()) so amd/src/status.js can safely
// overwrite just the message text without disturbing a dismiss-button/JS init.
echo html_writer::div(
    html_writer::div($tokenwarningmessage, 'alert alert-warning mb-0', ['data-region' => 'tokenwarning-text']),
    $tokenwarningmessage !== '' ? 'mb-3' : 'mb-3 d-none',
    ['data-region' => 'tokenwarning']
);

// M-08: only known once the generating stage has run - rendered up front if already known,
// otherwise revealed live by amd/src/status.js the same way the token-budget warning above is.
$countdiscrepancy = json_decode((string) $generation->countdiscrepancy, true);
$countdiscrepancymessage = (is_array($countdiscrepancy) && !empty($countdiscrepancy))
    ? question_types::format_count_discrepancy($countdiscrepancy)
    : '';
//
// BL-35: hidden on a partly successful run, where the partial notice below prints the very same
// sentence as part of explaining itself - two identical amber boxes stacked on top of each other
// was what the screen actually showed. The region stays in the markup either way, because
// amd/src/status.js reveals it mid-poll for the runs that are not partial.
$discrepancyhidden = $countdiscrepancymessage === '' || $generation->status === generation_status::PARTIAL;
echo html_writer::div(
    html_writer::div($countdiscrepancymessage, 'alert alert-warning mb-0', ['data-region' => 'countdiscrepancy-text']),
    $discrepancyhidden ? 'mb-3 d-none' : 'mb-3',
    ['data-region' => 'countdiscrepancy']
);

// Gen-011: a distinct green "completed successfully" notification (separate from just filling the
// progress bar and revealing Continue), shown up front if the generation is already completed on
// page load, otherwise revealed live by amd/src/status.js the moment the poll returns 'completed'.
// The question count sits in its own span (like question-count above) so the JS can refresh it to
// the final value when completion happens mid-poll rather than on a fresh already-completed load.
$successcountspan = html_writer::tag('span', $questioncount, ['data-region' => 'success-count']);
echo html_writer::div(
    '✓ ' . get_string('generationcompletedsuccess', 'local_artqtml', $successcountspan),
    'alert alert-success' . ($generation->status === generation_status::COMPLETED ? ' mb-3' : ' mb-3 d-none'),
    ['data-region' => 'success']
);

// BL-35: the third outcome. The pipeline ran to the end and the teacher still did not get what
// they asked for - which is neither the green notice above nor the red one at the bottom, and
// was shown as the green one until 2026-08-01.
if ($generation->status === generation_status::PARTIAL) {
    // The button is inside the notice, not down with Retry/Back, because it is the answer to the
    // sentence directly above it - and because Retry means something else here: it restarts this
    // generation from zero and throws away the questions it did produce. This one keeps them.
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

    // BL-30 leftover: countdiscrepancy only names the shortfall. The why is already in
    // local_artqtml_log (type_generation_failed / question_rejected / undershoot outcomes) -
    // surface it here so the teacher is not left guessing why SR/RV came back empty.
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
    // S-3/S-2: the stage -> percent/colour/striping map and the terminal-status list, emitted
    // from their single PHP source so amd/src/status.js can read them instead of owning copies.
    'data-progress-config'   => \local_artqtml\local\generation_progress::config_json(),
]);

// Gen-001/M-15: a single Bootstrap progress bar over the pipeline stages
// (generating/validating/saving/completed - 25/50/75/100%), matching the spec's own "Moodle native
// striped progress bar" wording instead of a plain color-coded list.
//
// BL-35: the generating stage is no longer one step. It is one API call per requested question
// type, so the bar advances within it, 25% to 45%, and the label names the type in flight. The
// individual call is still a single synchronous HTTP request with no streaming signal - what
// changed is that there are now several of them, and how many have finished is a real number
// rather than a fabricated one.
//
// S-3: the mapping itself lives in generation_progress, shared with amd/src/status.js.
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
        // BL-35: name the type being generated, so six calls do not look like one stuck one.
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

// Gen-004: "Minden szakasznál szöveges visszajelzés is megjelenik a progress bar alatt".
// S-4: this used to be written inside the bar, where at 25% the bar was narrower than its own
// label and Bootstrap's overflow: hidden clipped the text away - content made unreachable by
// clipping, which Glob-034 forbids. It was worked around in CSS; below the bar it needs no
// workaround at all.
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

// BL-35: shown server-side for a partly successful run. The notice above tells the teacher the
// questions that did get made are usable - and until this line the page then gave them no way to
// reach them, because Continue was only ever revealed by the JS on 'completed'. The other statuses
// are unchanged: hidden here, unhidden by amd/src/status.js when the poll says completed.
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

// Gen-014/M-27: the raw technical error (may contain provider-internal details) is shown to
// anyone allowed to configure the plugin, regardless of debug mode - everyone else only sees
// the generic message.
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
