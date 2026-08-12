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
 * Step 1 of the "New generation" flow: upload/paste source text.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_artqtml\form\upload_form;
use local_artqtml\local\security_filter;
use local_artqtml\local\source_text_limit;
use local_artqtml\local\extraction_result;
use local_artqtml\local\duplicate_detector;
use local_artqtml\local\generation_list;
use local_artqtml\local\text_extractor;

require_login();

$context = context_system::instance();
require_capability('local/artqtml:use', $context);

if (!get_config('local_artqtml', 'enabled')) {
    redirect(new moodle_url('/local/artqtml/index.php'));
}

if (!\local_artqtml\local\draft_bank::is_configured()) {
    \core\notification::error(get_string('errordraftcoursenotconfigured', 'local_artqtml'));
    redirect(new moodle_url('/local/artqtml/index.php'));
}

$PAGE->set_url('/local/artqtml/upload.php');
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->add_body_class('mediumwidth');
$PAGE->set_title(get_string('newgeneration', 'local_artqtml'));
$PAGE->set_heading(get_string('newgeneration', 'local_artqtml'));

$indexurl = new moodle_url('/local/artqtml/index.php');

$editid = optional_param('id', 0, PARAM_INT);
$editgeneration = null;
if ($editid > 0) {
    $editgeneration = $DB->get_record('local_artqtml_generations', ['id' => $editid], '*', MUST_EXIST);

    if (!\local_artqtml\local\generation_edit_policy::can_edit_source($editgeneration)) {
        redirect(
            \local_artqtml\local\generation_list::open_url($editgeneration),
            get_string('cannoteditsourcenondraft', 'local_artqtml'),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }

    // Same one-shot surface as generate.php: recoverable pipeline rollback may leave a generic
    // Error on a started draft so the teacher knows to restart from the source step.
    if (!empty($editgeneration->error)) {
        \core\notification::error($editgeneration->error);
        $DB->set_field('local_artqtml_generations', 'error', null, ['id' => $editid]);
        $editgeneration->error = null;
    }
}

$maxbytes = $CFG->maxbytes;

$mform = new upload_form(null, ['maxbytes' => $maxbytes, 'editid' => $editid]);

if ($editgeneration && !$mform->is_submitted()) {
    $mform->set_data([
        'id'         => $editid,
        'name'       => $editgeneration->name,
        'shortname'  => $editgeneration->shortname,
        'sourcetext' => $editgeneration->sourcetext,
    ]);
}

/**
 * Save a new generation, or update the source of one that is still a draft.
 *
 * Pass-through to {@see \local_artqtml\local\generation_source_service::save()}.
 *
 * @param string $name
 * @param string $shortname
 * @param string $sourcetext
 * @param int $editingid the generation being edited, or 0 to create a new one
 * @param string|null $filehash
 * @return int the generation id
 * @throws \moodle_exception if an existing generation is no longer a draft
 */
function local_artqtml_save_generation(
    string $name,
    string $shortname,
    string $sourcetext,
    int $editingid,
    ?string $filehash
): int {
    global $USER;

    return \local_artqtml\local\generation_source_service::save(
        $name,
        $shortname,
        $sourcetext,
        $editingid,
        $filehash,
        (int) $USER->id
    );
}


/**
 * Turn a refused save into the canonical page for the generation's current status.
 *
 * The user did nothing wrong and has every permission this page requires - the generation simply
 * Moved on while their form was open. What they get is the page that matches where it moved to,
 * And a sentence saying why nothing was saved. What they do not get is an exception screen: a
 * Stack trace over a race the product itself allows would be a defect report about the user.
 *
 * @param int $generationid the generation that was being edited
 * @param \moodle_url $fallback where to go if the record has since disappeared entirely
 * @return void
 */
function local_artqtml_redirect_after_refused_save(int $generationid, \moodle_url $fallback): void {
    global $DB;

    $generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid], 'id, status');

    redirect(
        $generation ? \local_artqtml\local\generation_list::open_url($generation) : $fallback,
        get_string('cannoteditsourcenondraft', 'local_artqtml'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$confirmed = optional_param('artqtmlconfirmdup', 0, PARAM_BOOL);
if ($confirmed) {
    require_sesskey();
    if (empty($SESSION->artqtml_pending)) {
        redirect(new moodle_url('/local/artqtml/upload.php', $editid ? ['id' => $editid] : []));
    }

    $pending = $SESSION->artqtml_pending;
    unset($SESSION->artqtml_pending);

    // Initialised before the try below because the catch ends in a redirect(), which never returns
    // - but static analysis cannot see that, and reads the variable as possibly undefined.
    $generationid = 0;

    // The session says which generation this confirmation was prepared for; it says nothing about
    // What state that generation is in NOW. The pending data was already discarded above, so a
    // Refusal here leaves nothing behind to retry with - which is the correct outcome: the
    // Decision the user confirmed was about a draft that no longer exists in that form.
    try {
        $generationid = local_artqtml_save_generation(
            $pending['name'],
            $pending['shortname'],
            $pending['sourcetext'],
            (int) $pending['editingid'],
            $pending['filehash'] ?? null
        );
    } catch (\moodle_exception $e) {
        if ($e->errorcode !== 'cannoteditsourcenondraft') {
            throw $e;
        }
        local_artqtml_redirect_after_refused_save((int) $pending['editingid'], $indexurl);
    }

    redirect(new moodle_url('/local/artqtml/generate.php', ['id' => $generationid]));
}

if ($mform->is_cancelled()) {
    redirect($indexurl);
} else if ($data = $mform->get_data()) {
    if (\core_text::strlen((string) $data->name) > 100) {
        \core\notification::error(get_string('errornametoolong', 'local_artqtml'));
        redirect(new moodle_url('/local/artqtml/upload.php', $editid ? ['id' => $editid] : []));
    }

    $sourcetext = (string) $data->sourcetext;

    $draftitemid = (int) ($data->sourcefile ?? 0);
    $contenthashes = [];
    foreach (text_extractor::draft_files($draftitemid) as $file) {
        $contenthashes[] = $file->get_contenthash();

        $report = text_extractor::extract_with_report($file);
        if ($report['status'] === extraction_result::STATUS_REJECTED) {
            \core\notification::error(extraction_result::message($report['reason']));
            redirect(new moodle_url('/local/artqtml/upload.php', $editid ? ['id' => $editid] : []));
        }

        if ($report['text'] !== '') {
            $sourcetext = $sourcetext !== '' ? ($sourcetext . "\n\n" . $report['text']) : $report['text'];
        }

        $littletext = $report['status'] === extraction_result::STATUS_OK
            && (int) $file->get_filesize() > 1048576
            && \core_text::strlen($report['text']) < 500;
        if ($littletext) {
            \core\notification::warning(get_string('warningfilelittletext', 'local_artqtml'));
        }
    }
    // Sorted so the combined hash does not depend on the order the File API happened to return
    // The files in.
    sort($contenthashes);
    $filehash = $contenthashes !== [] ? duplicate_detector::hash_file_bytes(implode('', $contenthashes)) : null;

    if (trim($sourcetext) === '') {
        \core\notification::error(get_string('errorsourcetextrequired', 'local_artqtml'));
        redirect(new moodle_url('/local/artqtml/upload.php', $editid ? ['id' => $editid] : []));
    }

    if (source_text_limit::is_exceeded($sourcetext)) {
        \core\notification::error(source_text_limit::error_message($sourcetext));
        redirect(new moodle_url('/local/artqtml/upload.php', $editid ? ['id' => $editid] : []));
    }

    // 020: security screen. On failure the text is deliberately never redisplayed.
    if (security_filter::has_sql_injection($sourcetext) || security_filter::has_prompt_injection($sourcetext)) {
        \core\notification::error(get_string('errorsecurityfilter', 'local_artqtml'));
        redirect(new moodle_url('/local/artqtml/upload.php', $editid ? ['id' => $editid] : []));
    }

    $match = duplicate_detector::find_match($sourcetext, $editid);
    if ($match !== null) {
        $SESSION->artqtml_pending = [
            'name'       => $data->name,
            'shortname'  => strtoupper($data->shortname),
            'sourcetext' => $sourcetext,
            'editingid'  => (int) ($data->id ?? 0),
            'filehash'   => $filehash,
        ];

        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('duplicatefound', 'local_artqtml'));
        echo html_writer::tag('p', get_string('duplicatefounddesc', 'local_artqtml', (object) [
            'creator'    => fullname(\core_user::get_user($match->userid)),
            'date'       => userdate($match->timecreated, get_string('datetimeformat', 'local_artqtml')),
            'name'       => format_string($match->name),
            'similarity' => $match->similarity,
            'status'     => get_string('status_' . $match->status, 'local_artqtml'),
        ]));

        $continueurl = new moodle_url(
            '/local/artqtml/upload.php',
            ['artqtmlconfirmdup' => 1] + ($editid ? ['id' => $editid] : [])
        );
        // D-5: the panel already knows the matched generation's status (it prints it above), so
        // The button must land where that status is actually actionable - the approval page for a
        // Completed generation, not the settings page. generation_list::open_url() is where that
        // Status->destination rule already lives for the list page; reused here so the two cannot
        // Drift apart.
        $openurl = generation_list::open_url($match);

        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $continueurl->out(false)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::tag(
            'button',
            get_string('duplicatecontinue', 'local_artqtml'),
            ['type' => 'submit', 'class' => 'btn btn-primary mr-2']
        );
        echo html_writer::end_tag('form');

        echo html_writer::link($openurl, get_string('duplicateopenexisting', 'local_artqtml'), ['class' => 'btn btn-secondary']);
        echo $OUTPUT->footer();
        exit;
    }

    $generationid = 0;
    try {
        $generationid = local_artqtml_save_generation(
            $data->name,
            strtoupper($data->shortname),
            $sourcetext,
            (int) ($data->id ?? 0),
            $filehash
        );
    } catch (\moodle_exception $e) {
        // Only the status refusal is handled here. Anything else is a real fault and goes to
        // Moodle's own error handling, where it belongs - swallowing exceptions by category is
        // How a broken save comes to look like a successful one.
        if ($e->errorcode !== 'cannoteditsourcenondraft') {
            throw $e;
        }
        local_artqtml_redirect_after_refused_save((int) ($data->id ?? 0), $indexurl);
    }

    redirect(new moodle_url('/local/artqtml/generate.php', ['id' => $generationid]));
}

echo $OUTPUT->header();
echo local_artqtml_model_warning_banner();
if ($editgeneration) {
    echo local_artqtml_owner_warning_banner($editgeneration);
}
echo $OUTPUT->heading(get_string('uploadheading', 'local_artqtml'));
$mform->display();

$sourcetokenlimit = source_text_limit::token_limit();
$counterlimittemplate = get_string('textcounterlimitlabel', 'local_artqtml', $sourcetokenlimit);
$countererrormessage = get_string('errorsourcetexttoolong', 'local_artqtml');

$countertemplate = get_string('textcounterlabel', 'local_artqtml', (object) [
    'chars'  => '__CHARS__',
    'words'  => '__WORDS__',
    'tokens' => '__TOKENS__',
]);

$PAGE->requires->js(new moodle_url('/local/artqtml/js/textcounter.js', [
    'v' => (int) get_config('local_artqtml', 'version'),
]));
echo html_writer::script(
    'document.addEventListener("DOMContentLoaded", function() {' .
    'if (window.ArtqtmlTextCounter) {' .
    'window.ArtqtmlTextCounter.init("id_sourcetext", "artqtml-textcounter", ' .
        $sourcetokenlimit . ', ' . json_encode($countertemplate) . ', ' .
        json_encode($counterlimittemplate) . ', ' . json_encode($countererrormessage) . ');' .
    '}});'
);

$PAGE->requires->js_call_amd('local_artqtml/uploadconflict', 'init', [
    'id_sourcetext',
    'id_sourcefile',
    [
        'filepromptmessage' => get_string('uploadconflictfileprompt', 'local_artqtml'),
        'textpromptmessage' => get_string('uploadconflicttextprompt', 'local_artqtml'),
        'fileignorednote'   => get_string('uploadconflictfileignored', 'local_artqtml'),
        'fileloadednote'    => get_string('uploadconflictfileloaded', 'local_artqtml'),
        'extractfailedmessage' => get_string('errorextractfailed', 'local_artqtml'),
    ],
]);

// The Continue button is only active once all three required fields are filled.
$PAGE->requires->js(new moodle_url('/local/artqtml/js/continuebutton.js'));
echo html_writer::script(
    'document.addEventListener("DOMContentLoaded", function() {' .
    'if (window.ArtqtmlContinueButton) {' .
    'window.ArtqtmlContinueButton.init("id_name", "id_shortname", "id_sourcetext", "id_sourcefile");' .
    '}});'
);

// Confirm before discarding entered data via the Cancel button.
echo html_writer::script(
    'document.addEventListener("DOMContentLoaded", function() {' .
    'var cancelbtn = document.querySelector(\'input[name="cancel"], button[name="cancel"]\');' .
    'if (!cancelbtn) { return; }' .
    'cancelbtn.addEventListener("click", function(e) {' .
    'if (!window.confirm(' . json_encode(get_string('uploadcancelconfirm', 'local_artqtml')) . ')) {' .
    'e.preventDefault(); e.stopPropagation();' .
    '}' .
    '}, true);' .
    '});'
);

echo $OUTPUT->footer();
