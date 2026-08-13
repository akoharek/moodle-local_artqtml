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
 * Kérdésbank - Draft jóváhagyó oldal.
 *
 * Thin controller: bootstrap -> capability -> parameter parsing -> service call + notification +
 * Redirect (POST actions), or data gathering + renderer calls (GET). The business logic lives in
 * Local_artqtml\local\approve\* (approval / move / deletion services, page data, renderer).
 *
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/engine/bank.php');

use local_artqtml\local\question_types;
use local_artqtml\local\question_bank_list;
use local_artqtml\local\draft_bank;
use local_artqtml\local\approve\question_approval_service;
use local_artqtml\local\approve\question_move_service;
use local_artqtml\local\approve\question_deletion_service;
use local_artqtml\local\approve\approve_page_data;
use local_artqtml\local\approve\approve_renderer;

require_login();

$context = context_system::instance();
require_capability('local/artqtml:use', $context);

$generationid = required_param('generationid', PARAM_INT);

$generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid], '*', MUST_EXIST);

$pageurl = new moodle_url('/local/artqtml/approve.php', ['generationid' => $generationid]);

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->add_body_class('mediumwidth');
$PAGE->set_title(get_string('approveheading', 'local_artqtml'));
$PAGE->set_heading(get_string('approveheading', 'local_artqtml'));

$categoryoptions = question_bank_list::options_for_user((int) $USER->id, (int) $generation->draftcategoryid);

// Soronkénti jóváhagyás: a human approval step, independent of the AI's validationsuggestion -
// A question must be approved before it can be moved into a real question bank.
$approveid = optional_param('approvequestion', 0, PARAM_INT);
if ($approveid) {
    require_sesskey();
    question_approval_service::approve_single($approveid, $generationid, (int) $USER->id, $context);
    redirect($pageurl);
}

$revokeid = optional_param('revokequestion', 0, PARAM_INT);
if ($revokeid) {
    require_sesskey();
    question_approval_service::revoke_single($revokeid, $generationid, $context);
    redirect($pageurl);
}

$deleteid = optional_param('deletequestion', 0, PARAM_INT);
if ($deleteid) {
    require_sesskey();
    question_deletion_service::delete_single($deleteid, $generationid, $context);
    redirect($pageurl);
}

// Single-question move to the bank.
$moveid = optional_param('movequestion', 0, PARAM_INT);
if ($moveid) {
    require_sesskey();
    $categoryvalue = required_param('categoryvalue', PARAM_RAW);
    if (!isset($categoryoptions[$categoryvalue])) {
        \core\notification::error(get_string('errornocategory', 'local_artqtml'));
        redirect($pageurl);
    }
    try {
        $result = question_move_service::move_single($moveid, $generationid, $categoryvalue, $context);
        draft_bank::delete_if_empty($generationid, (int) $generation->draftcategoryid);
        if ($result['skipped'] > 0) {
            \core\notification::success(get_string('movesuccesswithskipped', 'local_artqtml', (object) [
                'moved'   => $result['moved'],
                'skipped' => $result['skipped'],
            ]));
        } else if ($result['moved'] > 0) {
            \core\notification::success(get_string('movesuccess', 'local_artqtml', $result['moved']));
        } else {
            \core\notification::error(get_string('errorbulkactionfailed', 'local_artqtml'));
        }
    } catch (\Throwable $e) {
        debugging('local_artqtml single move failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        \core\notification::error(get_string('errorbulkactionfailed', 'local_artqtml'));
    }
    redirect($pageurl);
}

// Tömeges műveletek — approve-all and bulk delete.
$bulkaction = optional_param('bulkaction', '', PARAM_ALPHA);
if (in_array($bulkaction, ['allaccepted', 'delete'], true)) {
    require_sesskey();

    if ($bulkaction === 'delete') {
        $questionids = optional_param_array('questionids', [], PARAM_INT);
        if (empty($questionids)) {
            \core\notification::error(get_string('errornoselection', 'local_artqtml'));
            redirect($pageurl);
        }
        try {
            $count = question_deletion_service::delete_selected($questionids, $generationid, $context);
            \core\notification::success(get_string('bulkdeletesuccess', 'local_artqtml', $count));
        } catch (\Throwable $e) {
            debugging('local_artqtml bulk delete failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            \core\notification::error(get_string('errorbulkactionfailed', 'local_artqtml'));
        }
        redirect($pageurl);
    }

    // Remaining allowed bulk action: approve all accepted.
    try {
        $count = question_approval_service::approve_accepted_bulk($generationid, (int) $USER->id, $context);
        \core\notification::success(get_string('bulkapprovesuccess', 'local_artqtml', $count));
    } catch (\Throwable $e) {
        // Log the full detail, show only a generic translated message.
        debugging('local_artqtml bulk approve failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        \core\notification::error(get_string('errorbulkactionfailed', 'local_artqtml'));
    }
    redirect($pageurl);
}

// Sortable columns and pagination, matching the list page's pattern.
$sort = optional_param('qsort', 'id', PARAM_ALPHA);
$dir = strtoupper(optional_param('qdir', 'ASC', PARAM_ALPHA)) === 'DESC' ? 'DESC' : 'ASC';
$page = optional_param('qpage', 0, PARAM_INT);
$perpage = (int) get_config('moodle', 'perpage') ?: 20;

$totalquestions = approve_page_data::total_questions($generationid);

$statuscounts = approve_page_data::status_counts($generationid);
$statustotal = array_sum($statuscounts);

$eligibleforapproval = approve_page_data::eligible_for_approval($generationid);

echo $OUTPUT->header();
echo local_artqtml_model_warning_banner();
echo local_artqtml_apikey_decrypt_notice();
echo local_artqtml_owner_warning_banner($generation);
echo html_writer::tag('p', format_string($generation->name), [
    'data-testid' => 'artqtml-approve-generationname',
]);

// Site-wide list: index.php is context_system, same as this page — no course id.
$indexurl = new moodle_url('/local/artqtml/index.php');
echo html_writer::div(
    $OUTPUT->single_button($indexurl, get_string('backtolist', 'local_artqtml'), 'get'),
    'mb-3',
    ['data-testid' => 'artqtml-approve-backtolist']
);

$countdiscrepancy = json_decode((string) $generation->countdiscrepancy, true);
if (is_array($countdiscrepancy) && !empty($countdiscrepancy)) {
    echo html_writer::div(question_types::format_count_discrepancy($countdiscrepancy), 'alert alert-warning mb-3');
}

if ($totalquestions === 0) {
    echo $OUTPUT->notification(get_string('noquestions', 'local_artqtml'), 'info');
    echo $OUTPUT->footer();
    exit;
}

echo approve_renderer::validation_summary($statuscounts, $statustotal);

$lastpage = max(0, (int) ceil($totalquestions / $perpage) - 1);
$page = min($page, $lastpage);

$questions = approve_page_data::questions($generationid, $sort, $dir, $page, $perpage);
$creator = core_user::get_user($generation->userid);

// C9: the Edit and Preview actions both open the native Moodle question bank UI for a draft
// Question, which requires moodle/question:editall in the draft course context (there is no
// "moodle/question:edit" capability - using it made has_capability() emit a "capability not
// Found" debug warning and always return false, so the actions never rendered). A user can hold
// Local/artqtml:use (enough to view/approve/move here) without being enrolled as an
// Editingteacher in the draft course, in which case those links would only lead to a permission
// Error - so compute the capability once here and show them only when the user actually has it.
$candrafteditquestions = false;
if (draft_bank::is_configured()) {
    $draftcontextid = draft_bank::get_draft_context_id();
    if ($draftcontextid !== null) {
        $candrafteditquestions = has_capability('moodle/question:editall', \context::instance_by_id($draftcontextid));
    }
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
echo html_writer::input_hidden_params($pageurl);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo approve_renderer::questions_table($OUTPUT, $questions, $sort, $dir, $pageurl, $candrafteditquestions, $creator, $generationid);
echo approve_renderer::toggle_script();

$pagingurl = new moodle_url($pageurl, ['qsort' => $sort, 'qdir' => $dir]);
echo $OUTPUT->paging_bar($totalquestions, $page, $perpage, $pagingurl, 'qpage');

echo approve_renderer::bulk_action_buttons($OUTPUT, $eligibleforapproval, $categoryoptions);

echo html_writer::end_tag('form');

echo approve_renderer::selection_script();

echo $OUTPUT->footer();
