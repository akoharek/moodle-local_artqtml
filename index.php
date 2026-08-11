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
 * List page: entry point for the ArtQTML (functional spec ch.2).
 *
 * Site-wide (Glob-022): not tied to any course. Shows a stats summary, then two
 * independently filterable/sortable/paginated sections - "My generations" and
 * "Others' generations" (List-003).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_artqtml\local\generation_list;

require_login();

$context = context_system::instance();
require_capability('local/artqtml:use', $context);

$PAGE->set_url('/local/artqtml/index.php');
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
$PAGE->set_title(get_string('pageheading', 'local_artqtml'));
$PAGE->set_heading(get_string('pageheading', 'local_artqtml'));

echo $OUTPUT->header();
echo local_artqtml_model_warning_banner();
// Glob-038: shown once after an upgrade backed up an admin-editable setting.
echo local_artqtml_setting_backup_notice();
echo html_writer::tag('p', get_string('pageintro', 'local_artqtml'));

if (!get_config('local_artqtml', 'enabled')) {
    echo $OUTPUT->notification(get_string('plugindisabled', 'local_artqtml'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

// Statistical summary (List-002): global counters across all generations/questions.
$totalgenerations = $DB->count_records('local_artqtml_generations');
$pendingquestions = $DB->count_records('local_artqtml_questions', ['movedout' => 0]);
$approvedquestions = $DB->count_records('local_artqtml_questions', ['approved' => 1]);
$rejectedquestions = $DB->count_records_select(
    'local_artqtml_questions',
    'movedout = 0 AND validationsuggestion = :rejected',
    ['rejected' => \local_artqtml\local\validation_suggestion::REJECTED]
);

echo html_writer::start_div('row text-center mb-4');
$stats = [
    ['statlabeltotal', $totalgenerations],
    ['statlabelpending', $pendingquestions],
    ['statlabelapproved', $approvedquestions],
    ['statlabelrejected', $rejectedquestions],
];
foreach ($stats as [$labelkey, $value]) {
    echo html_writer::div(
        html_writer::div($value, 'h3 mb-0') . html_writer::div(get_string($labelkey, 'local_artqtml'), 'text-muted small'),
        'col-md-3'
    );
}
echo html_writer::end_div();

// Lic-009/010: blocked (missing/invalid/expired/exhausted) licenses disable starting new
// generations, but already-started ones on the list below remain fully usable.
//
// Lic-025/026 (2026-08-07): the yellow expiry/quota warning must appear on the list page too -
// teachers need to see it before anything is blocked. The banner helper returns '' when there is
// nothing to say, danger markup when blocked, and warning markup for the soft thresholds; always
// calling it is what puts the soft warning on this page (previously only is_blocked() opened the
// gate, so warning state was silent here).
$licenseblocked = \local_artqtml\local\license_checker::is_blocked();
echo local_artqtml_license_warning_banner();
echo local_artqtml_token_warning_banner();

// Admin-065/Glob-036: an unset or unusable model blocks new generations the same way, and for the
// same reason - starting one would fail at the first API call.
$modelblocked = \local_artqtml\local\model_blocking::is_blocked();

// Jov-023: same "blocks new generations, doesn't touch already-started ones" treatment.
$draftcourseblocked = !\local_artqtml\local\draft_bank::is_configured();
if ($draftcourseblocked) {
    echo local_artqtml_draftcourse_warning_banner();
}

if (has_capability('local/artqtml:use', $context)) {
    if ($licenseblocked || $draftcourseblocked || $modelblocked) {
        echo html_writer::div(
            html_writer::tag('button', get_string('newgeneration', 'local_artqtml'), [
                'type' => 'button', 'class' => 'btn btn-primary', 'disabled' => 'disabled',
            ]),
            'mb-3'
        );
    } else {
        $newurl = new moodle_url('/local/artqtml/upload.php');
        echo html_writer::div(
            html_writer::link($newurl, get_string('newgeneration', 'local_artqtml'), ['class' => 'btn btn-primary']),
            'mb-3'
        );
    }
}

$pageurl = $PAGE->url;

echo $OUTPUT->heading(get_string('sectionmine', 'local_artqtml'), 3);
echo generation_list::render('mine', $USER->id, true, $pageurl);

// Glob-031: collaborative :use by design; delete is owner-only (see delete.php).
// "Others' generations" lists Open links for colleagues; Delete stays in the mine section only.
echo $OUTPUT->heading(get_string('sectionothers', 'local_artqtml'), 3);
echo generation_list::render('other', $USER->id, false, $pageurl);

echo $OUTPUT->footer();
