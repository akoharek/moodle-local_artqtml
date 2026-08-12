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
 * List page: entry point for the ArtQTML.
 *
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_artqtml\local\generation_list;

require_login();

$context = context_system::instance();
require_capability('local/artqtml:use', $context);

$PAGE->set_url('/local/artqtml/index.php');
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->add_body_class('mediumwidth');
$PAGE->set_title(get_string('pageheading', 'local_artqtml'));
$PAGE->set_heading(get_string('pageheading', 'local_artqtml'));

echo $OUTPUT->header();
echo local_artqtml_model_warning_banner();
// Shown once after an upgrade backed up an admin-editable setting.
echo local_artqtml_setting_backup_notice();
echo html_writer::tag('p', get_string('pageintro', 'local_artqtml'));

if (!get_config('local_artqtml', 'enabled')) {
    echo $OUTPUT->notification(get_string('plugindisabled', 'local_artqtml'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

// Statistical summary : global counters across all generations/questions.
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

$modelblocked = \local_artqtml\local\model_blocking::is_blocked();

// Same "blocks new generations, doesn't touch already-started ones" treatment.
$draftcourseblocked = !\local_artqtml\local\draft_bank::is_configured();
if ($draftcourseblocked) {
    echo local_artqtml_draftcourse_warning_banner();
}

if (has_capability('local/artqtml:use', $context)) {
    if ($draftcourseblocked || $modelblocked) {
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

echo $OUTPUT->heading(get_string('sectionothers', 'local_artqtml'), 3);
echo generation_list::render('other', $USER->id, false, $pageurl);

echo $OUTPUT->footer();
