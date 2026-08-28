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
 * Step 1 of the "New generation" flow: upload/paste source text.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/artqtml/lib.php');

use local_artqtml\form\upload_form;
use local_artqtml\local\security_filter;
use local_artqtml\local\source_text_limit;
use local_artqtml\local\extraction_result;
use local_artqtml\local\duplicate_detector;
use local_artqtml\local\generation_list;
use local_artqtml\local\text_extractor;

require_login();

defined('MOODLE_INTERNAL') || die();

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
$PAGE->set_title(get_string('newgeneration', 'local_artqtml'));
$PAGE->set_heading(get_string('newgeneration', 'local_artqtml'));

$indexurl = new moodle_url('/local/artqtml/index.php');

// The Back button on the question settings page returns here with the generation's own
// id, to edit its already-saved identifiers/source text in place rather than starting a new one.
$editid = optional_param('id', 0, PARAM_INT);
$editgeneration = null;
if ($editid > 0) {
    $editgeneration = $DB->get_record('local_artqtml_generations', ['id' => $editid], '*', MUST_EXIST);

    // 2026-08-04: only a draft's source may be edited. Until this line existed, this page loaded
    // and saved a generation in ANY state - so `upload.php?id=<n>` on a finished one rewrote its
    // name, source text and both hashes while its questions, made from the old text, stayed as
    // they were. Nothing appeared to break; the questions and the material they came from simply
    // stopped describing each other.
    //
    // NOT an ownership rule: any :use holder still edits any colleague's
    // draft, and the message deliberately does not say "permission" - the user has permission,
    // the generation is just past the point where this page applies.
    //
    // The destination comes from generation_list::open_url(), which is the one place that maps a
    // status to its page; generate.php does exactly the same on the settings half.
    if (!\local_artqtml\local\generation_edit_policy::can_edit_source($editgeneration)) {
        redirect(
            \local_artqtml\local\generation_list::open_url($editgeneration),
            get_string('cannoteditsourcenondraft', 'local_artqtml'),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }

    if (!\local_artqtml\local\generation_access_policy::can_mutate($editgeneration, null, $context)) {
        redirect(
            \local_artqtml\local\generation_list::open_url($editgeneration),
            get_string('cannotmutateothers', 'local_artqtml'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Same one-shot surface as generate.php: recoverable pipeline rollback may leave a generic
    // error on a started draft so the teacher knows to restart from the source step.
    if (!empty($editgeneration->error)) {
        \core\notification::error($editgeneration->error);
        $DB->set_field('local_artqtml_generations', 'error', null, ['id' => $editid]);
        $editgeneration->error = null;
    }
    // No owner check here. Until 2026-08-03 this page required.
    // local/artqtml:configure to open somebody else's generation, and it was the only page in the
    // plugin that did - every other one lets any :use holder open any generation, because the tool
    // is deliberately site-wide and collaborative.
    //
    // That single stricter rule did not protect anything; it only broke a supported route. Walked
    // through on 2026-08-03 as a real non-editing teacher: opening a colleague's draft works and the
    // page even says whose it is, but the "Back" button on it failed with "you do not have
    // permission to edit the ArtQTML admin settings" - an error naming a capability the
    // teacher was not exercising. Meanwhile the same source text was fully readable through the
    // "generate the missing types" route, which copies it into a new generation owned by whoever
    // clicked. Same data, one route closed, the other open, and the closed one is the one a teacher
    // would use.
    //
    // Product decision (2026-08-03): the source text is shared working material. The check is
    // removed rather than the other route being closed, because it was the check that contradicted
    // the product's stated principle.
}

$configuredmax = (int) get_config('local_artqtml', 'maxfilesize');
$maxbytes = $configuredmax > 0 ? min($configuredmax, $CFG->maxbytes) : $CFG->maxbytes;

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
 * A thin pass-through to {@see \local_artqtml\local\generation_source_service::save()}, which
 * is where the write and the status check now live. The logic moved out of this file on
 * 2026-08-04 for one reason: a function declared at the top of a controller that starts a session
 * and redirects cannot be called from a test, and the behaviour that most needed proving here is
 * what happens when the generation stops being a draft between opening the form and submitting it.
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
 * moved on while their form was open. What they get is the page that matches where it moved to,
 * and a sentence saying why nothing was saved. What they do not get is an exception screen: a
 * stack trace over a race the product itself allows would be a defect report about the user.
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

// The user chose "Folytatom".
//
// this is a bare HTML form that only ever posts sesskey + artqtmconfirmdup=1, not a
// resubmission of upload_form's own fields - $mform->get_data() would therefore always return
// false for it (missing required fields/wrong submit button name), so this must be handled
// entirely on its own, before ever touching $mform->get_data(), using the data stashed in the
// session when the popup was first shown - not nested inside the $mform->get_data() branch below.
$confirmed = optional_param('artqtmconfirmdup', 0, PARAM_BOOL);
if ($confirmed) {
    require_sesskey();
    if (empty($SESSION->artqtm_pending)) {
        redirect(new moodle_url('/local/artqtml/upload.php', $editid ? ['id' => $editid] : []));
    }

    $pending = $SESSION->artqtm_pending;
    unset($SESSION->artqtm_pending);

    // Initialised before the try below because the catch ends in a redirect(), which never returns
    // - but static analysis cannot see that, and reads the variable as possibly undefined.
    $generationid = 0;

    // The session says which generation this confirmation was prepared for; it says nothing about
    // what state that generation is in NOW. The pending data was already discarded above, so a
    // refusal here leaves nothing behind to retry with - which is the correct outcome: the
    // decision the user confirmed was about a draft that no longer exists in that form.
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
    // NO ID-MISMATCH CHECK HERE, and the absence is deliberate - there was one between
    // 2026-08-04 morning and afternoon, and it was removed for two independent reasons.
    //
    // It could not fire. The comment claimed "the URL is the authority", but this page's URL
    // carries no id ($PAGE->set_url above), the form posts to that URL, and optional_param reads
    // `id` out of the same POST body that the hidden field is in. The two values it compared were
    // one value read twice.
    //
    // And there was nothing for it to protect. Submitting a different generation's id opens that
    // generation - which any :use holder is entitled to do, because collaborative :use is by design
    // site-wide and collaborative. What actually bounds this page is the status gate above and the
    // re-read inside the transaction in generation_source_service::save(): only a draft may be
    // written, and its status is checked again immediately before the write.
    //
    // the browser enforces maxlength=100 on the name field, but the form binds it
    // as PARAM_TEXT which does not bound length, so a hand-crafted/altered POST could exceed it.
    // Enforce the 100-character limit server-side (core_text::strlen counts characters, matching
    // the browser's maxlength semantics for multibyte Hungarian names).
    if (\core_text::strlen((string) $data->name) > 100) {
        \core\notification::error(get_string('errornametoolong', 'local_artqtml'));
        redirect(new moodle_url('/local/artqtml/upload.php', $editid ? ['id' => $editid] : []));
    }

    $sourcetext = (string) $data->sourcetext;

    // Compare file bytes so an identical re-upload is still caught as a duplicate even if text
    // extraction happens to produce slightly different output than it did the first time.
    //
    // 2026-08-04, two changes here.
    //
    // The raw bytes are no longer concatenated into a variable. The only thing that string was
    // ever used for was the hash, and Moodle has already hashed every stored file - reading a
    // whole upload into memory a second time to compute a number the File API is holding was pure
    // cost. `get_contenthash()` is that number, and it stands for the same thing: the identity of
    // the bytes, as a supplementary signal beside the text hash (see duplicate_detector),
    // because the same content in a different format hashes differently.
    //
    // THE STORED VALUE IS NOT THE SAME NUMBER IT USED TO BE, and that is worth saying plainly
    // rather than leaving it to be discovered. Rows written before 2026-08-04 hold the sha1 of the
    // raw bytes; rows written after hold the sha1 of the File API's content hashes. An old row and
    // a new row for the identical file will not match. Nothing compares them - duplicate_detector
    // decides on the text hash alone - so there is nothing to migrate; the column is a record, not
    // a key. If anything ever does start comparing it, this is the paragraph that says why it must
    // not compare across that date.
    //
    // And extraction now reports WHY it produced nothing. A refused document - unreadable, of an
    // unsupported type, or over a processing limit - used to be indistinguishable from an empty
    // one, so the page carried on with whatever else it had. It now stops here, before the
    // security screen, before duplicate detection, before anything is stored.
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

        // The plausibility check. Extraction "succeeding" with almost nothing is the silent.
        // failure this exists for: a 1.1 MB, 21-page presentation returned 64 characters, and
        // because 64 is not zero the upload was accepted, the teacher saw no problem, and the
        // questions were generated from a fragment.
        //
        // A WARNING, NOT A REFUSAL. A large file with genuinely little text - one slide, many
        // images - is a legitimate document, and refusing it would take the decision away from the
        // person who can actually see what is in it. What they need is to be told, so that pasting
        // the text becomes an obvious next step rather than a discovery made later.
        $littletext = $report['status'] === extraction_result::STATUS_OK
            && (int) $file->get_filesize() > 1048576
            && \core_text::strlen($report['text']) < 500;
        if ($littletext) {
            \core\notification::warning(get_string('warningfilelittletext', 'local_artqtml'));
        }
    }
    // Sorted so the combined hash does not depend on the order the File API happened to return
    // the files in.
    sort($contenthashes);
    $filehash = $contenthashes !== [] ? duplicate_detector::hash_file_bytes(implode('', $contenthashes)) : null;

    if (trim($sourcetext) === '') {
        \core\notification::error(get_string('errorsourcetextrequired', 'local_artqtml'));
        redirect(new moodle_url('/local/artqtml/upload.php', $editid ? ['id' => $editid] : []));
    }

    // 2026-08-04: the size limit, on the MERGED text - pasted plus everything extracted from the
    // uploaded files. The form's own check saw only the textarea, so a small paste beside a large
    // document passed it.
    //
    // Its position in this sequence is the point. It runs before the security screen, before
    // duplicate detection, before anything is written to $SESSION and before anything reaches the
    // database - so an oversized text is never stored, never hashed and never queued. Putting it
    // after any of those would leave a row behind that only the API call would eventually refuse.
    if (source_text_limit::is_exceeded($sourcetext)) {
        \core\notification::error(source_text_limit::error_message($sourcetext));
        redirect(new moodle_url('/local/artqtml/upload.php', $editid ? ['id' => $editid] : []));
    }

    // Security screen. On failure the text is deliberately never redisplayed.
    if (security_filter::has_sql_injection($sourcetext) || security_filter::has_prompt_injection($sourcetext)) {
        \core\notification::error(get_string('errorsecurityfilter', 'local_artqtml'));
        redirect(new moodle_url('/local/artqtml/upload.php', $editid ? ['id' => $editid] : []));
    }

    // Duplicate/similarity screen ($confirmed is always false here - the true case.
    // returned above before ever reaching $mform->get_data()).
    // duplicate detection is text-content based (sourcetexthash), not file-byte
    // based - see duplicate_detector::find_match(). $filehash is still recorded on the generation
    // row for reference but is intentionally not used to decide duplicates.
    $match = duplicate_detector::find_match($sourcetext, $editid);
    if ($match !== null) {
        $SESSION->artqtm_pending = [
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
            ['artqtmconfirmdup' => 1] + ($editid ? ['id' => $editid] : [])
        );
        // D-5: the panel already knows the matched generation's status (it prints it above), so
        // the button must land where that status is actually actionable - the approval page for a
        // completed generation, not the settings page. generation_list::open_url() is where that
        // status->destination rule already lives for the list page; reused here so the two cannot
        // drift apart.
        $openurl = generation_list::open_url($match);

        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $continueurl->out(false)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::tag(
            'button',
            get_string('duplicatecontinue', 'local_artqtml'),
            ['type' => 'submit', 'class' => 'btn btn-primary me-2']
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
        // how a broken save comes to look like a successful one.
        if ($e->errorcode !== 'cannoteditsourcenondraft') {
            throw $e;
        }
        local_artqtml_redirect_after_refused_save((int) ($data->id ?? 0), $indexurl);
    }

    redirect(new moodle_url('/local/artqtml/generate.php', ['id' => $generationid]));
}

echo $OUTPUT->header();
echo local_artqtml_model_warning_banner();
echo local_artqtml_apikey_decrypt_notice();
// This page was the only one that could show another user's generation.
// without saying so, and only because until today it could not show one at all - the owner check
// removed above kept it out. The warning belongs wherever a colleague's generation is on screen,
// which is why approve.php, generate.php and status.php have carried it all along. Walking the
// page as a teacher is what surfaced the gap: the source text appeared with nothing naming whose
// it was.
if ($editgeneration) {
    echo local_artqtml_owner_warning_banner($editgeneration);
}
echo $OUTPUT->heading(get_string('uploadheading', 'local_artqtml'));
$mform->display();

// The warning is relative to the generating model's context window, not a flat.
// admin-configured token count. The context window is no longer passed to the counter separately,
// because source_text_limit::token_limit() already derives the limit from it when no explicit one
// is set - passing both meant two places could disagree about the same number.
//
// 2026-08-04: the counter now also shows the server-side limit and blocks an ordinary submission
// past it. Every string handed to JavaScript goes through json_encode - a lang string concatenated
// raw into a <script> block is a quoting bug waiting for the first apostrophe in a translation.
$sourcetokenlimit = source_text_limit::token_limit();
$counterlimittemplate = get_string('textcounterlimitlabel', 'local_artqtml', $sourcetokenlimit);
$countererrormessage = get_string('errorsourcetexttoolong', 'local_artqtml');

// The counter text must come from a lang string, not be hardcoded in JS -.
// render it with sentinel placeholders here, substituted for the live counts in amd/src/textcounter.js.
$countertemplate = get_string('textcounterlabel', 'local_artqtml', (object) [
    'chars'  => '__CHARS__',
    'words'  => '__WORDS__',
    'tokens' => '__TOKENS__',
]);

$PAGE->requires->js_call_amd('local_artqtml/textcounter', 'init', [
    'id_sourcetext',
    'artqtml-textcounter',
    $sourcetokenlimit,
    $countertemplate,
    $counterlimittemplate,
    $countererrormessage,
]);

// Extracts a picked file's text into the box for review/editing, and
// warns when the user mixes typed text and an uploaded file.
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
$PAGE->requires->js_call_amd('local_artqtml/continuebutton', 'init', [
    'id_name',
    'id_shortname',
    'id_sourcetext',
    'id_sourcefile',
]);

// Confirm before discarding entered data via the Cancel button.
$PAGE->requires->js_call_amd('local_artqtml/uploadcancel', 'init', [
    get_string('uploadcancelconfirm', 'local_artqtml'),
]);

echo $OUTPUT->footer();
