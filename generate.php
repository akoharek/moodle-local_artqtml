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
 * Step 2 of the "New generation" flow: question counts, difficulty mode, detailed
 * per-type options (functional spec ch.4).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_artqtml\form\generate_form;
use local_artqtml\local\question_types;
use local_artqtml\local\draft_bank;

require_login();

$context = context_system::instance();
require_capability('local/artqtml:use', $context);

$generationid = required_param('id', PARAM_INT);

$generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid], '*', MUST_EXIST);
// Glob-031: collaborative :use by design; delete is owner-only (see delete.php).
// :use (already required above) opens any generation; owner shown via banner, not access-gated.
// Abort-delete below still goes through generation_delete_policy (owner+:use only).

// This page is the DRAFT page, and everything on it assumes that: "Delete and exit" removes the
// generation without the moved-question guard delete.php applies, and "Start generation" clears
// the question rows unconditionally. Both are correct for a draft and destructive for anything
// else - a finished generation whose questions are already in the question bank loses the record
// of where those questions came from, and the second one also clears the very marker delete.php's
// guard reads, so afterwards even the guarded path deletes without objecting.
//
// Glob-031 means any :use holder can open any generation by id, so nothing stopped that page being
// reached in a state its own buttons were never written for. Rather than teach the two buttons the
// status, the page now refuses to open on anything but a draft - one decision instead of two that
// can drift apart.
//
// List-018: the destination is not re-derived here. generation_list::open_url() already states the
// status->destination rule (completed -> approval page, in-progress/failed/partial -> status page)
// and its docblock says in as many words not to restate it at the call site.
if ($generation->status !== \local_artqtml\local\generation_status::STARTED) {
    $message = $generation->status === \local_artqtml\local\generation_status::COMPLETED
        ? get_string('cannoteditsettingscompleted', 'local_artqtml')
        : get_string('cannoteditsettingsstarted', 'local_artqtml');

    redirect(
        \local_artqtml\local\generation_list::open_url($generation),
        $message,
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

// Pipeline recoverable rollback (e.g. Finding #5 security gate) leaves status=started with a
// generic error for the teacher; show it once, then clear so it does not stick on every revisit.
if (!empty($generation->error)) {
    \core\notification::error($generation->error);
    $DB->set_field('local_artqtml_generations', 'error', null, ['id' => $generationid]);
    $generation->error = null;
}

$PAGE->set_url('/local/artqtml/generate.php', ['id' => $generationid]);
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
$PAGE->set_title(get_string('generatesettingsheading', 'local_artqtml'));
$PAGE->set_heading(get_string('generatesettingsheading', 'local_artqtml'));

$indexurl = new moodle_url('/local/artqtml/index.php');

/**
 * Build the JSON-encodable settings array from submitted form data.
 *
 * Beal-002-004: hideIf() disables the fields belonging to the two non-selected difficulty
 * modes, so browsers never submit them - only the active mode's fields are guaranteed to be
 * present on $data, hence the ?? defaults for all three groups here.
 *
 * @param \stdClass $data as returned by generate_form::get_data() or get_submitted_data()
 * @return array
 */
function local_artqtml_build_settings(stdClass $data): array {
    $mode = (string) ($data->difficultymode ?? 'scale');
    $levels = generate_form::MODE_LEVELS[$mode] ?? [];

    // BL-35: the grid is the source now. 'matrix' holds the per-type, per-level counts the teacher
    // actually entered; 'counts' and the generation-wide 'bloom'/'scale' totals are derived from
    // it and kept because everything downstream reads them - question_schema::build(),
    // build_prompt(), the save-time discrepancy check and every stored generation from before
    // today. Deriving rather than storing twice is what stops the two drifting apart.
    $matrix = [];
    foreach (question_types::CODES as $code) {
        foreach ($levels as $level) {
            $matrix[$code][$level] = (int) ($data->{'matrix_' . $code . '_' . $level} ?? 0);
        }
    }

    // Total for one level across every question type.
    $leveltotal = static function (array $matrix, string $level): int {
        $sum = 0;
        foreach ($matrix as $bytype) {
            $sum += (int) ($bytype[$level] ?? 0);
        }

        return $sum;
    };

    $settings = [
        'difficulty' => [
            'mode'   => $mode,
            'bloom'  => [
                'remember'   => $leveltotal($matrix, 'remember'),
                'understand' => $leveltotal($matrix, 'understand'),
                'apply'      => $leveltotal($matrix, 'apply'),
            ],
            'scale' => [
                'easy'   => $leveltotal($matrix, 'easy'),
                'medium' => $leveltotal($matrix, 'medium'),
                'hard'   => $leveltotal($matrix, 'hard'),
            ],
            // BL-35: 'count' is no longer stored - the field it came from is gone (see
            // generate_form). The description is what reaches the model; the per-type numbers are
            // in 'counts' below. Generations saved before today keep their stored value; nothing
            // reads it.
            'freetext' => [
                'description' => (string) ($data->freetextdescription ?? ''),
            ],
        ],
        'matrix'            => $matrix,
        'counts'            => [],
        'knowledgesource'   => (string) ($data->knowledgesource ?? 'sourceonly'),
        'negationhighlight' => (bool) ($data->negationhighlight ?? false),
        'types'             => [],
    ];

    foreach (question_types::CODES as $code) {
        // Free text has no levels, so its per-type count is the plain field; the two levelled
        // modes sum their own row of the grid. In that second branch $levels is non-empty, so the
        // loop above has filled $matrix[$code] for every code - no fallback is reachable here.
        $settings['counts'][$code] = $levels === []
            ? (int) ($data->{'count_' . $code} ?? 0)
            : array_sum($matrix[$code]);
        $settings['types'][$code] = [
            'retryenabled'    => question_types::supports_retry($code) ? (bool) ($data->{'retry_' . $code} ?? false) : false,
            'retrypenalty'    => (int) ($data->{'retrypenalty_' . $code} ?? 33),
            'instruction'     => (string) ($data->{'instruction_' . $code} ?? ''),
            // Gen-022/025: independent per-type switches, replacing the old single
            // generation-wide feedbackenabled and the retryenabled-tied hint behaviour.
            // Cursor audit v3 #4/#5: hintenabled applies to all six types now, not just the four
            // question_types::supports_hints() covers (that check only gates whether
            // question_importer.php can attach a real Moodle "try again" hint - IH/EH still get
            // AI-generated hint content stored for the review UI, per question_schema.php).
            'feedbackenabled' => (bool) ($data->{'feedback_' . $code} ?? false),
            'hintenabled'     => (bool) ($data->{'hint_' . $code} ?? false),
            // BL-29: the field only exists on the three panels that can carry an explanation, so
            // for the other three this reads false from the ?? and stays false. That is the
            // intended result, not an accident of a missing field: the schema then never asks for
            // an explanation the importer would have nowhere to put.
            'explanationenabled' => question_types::supports_option_explanation($code)
                && (bool) ($data->{'explanation_' . $code} ?? false),
        ];
        // M-26: 0 means "use the admin default" (see generate_questions_task::build_prompt()).
        if ($code === 'SR') {
            $settings['types'][$code]['sritemcount'] = (int) ($data->sritemcount ?? 0);
        }
    }

    return $settings;
}

/**
 * BL-34 (Admin-070): read the per-generation diagnostics flag off submitted form data.
 *
 * Not part of local_artqtml_build_settings() because this is not a prompt setting - it is a
 * column, and it is capability-gated. A user without local/artqtml:configure never sees the
 * field, so their submission carries no value for it and the stored flag must be left alone
 * rather than reset to 0: otherwise a teacher opening an admin-flagged draft and pressing Save
 * would silently turn the diagnostics off.
 *
 * @param stdClass $data submitted form data
 * @param stdClass $generation the generation as currently stored
 * @return int 0 or 1
 */
function local_artqtml_diagnostics_flag(stdClass $data, stdClass $generation): int {
    if (!has_capability('local/artqtml:configure', context_system::instance())) {
        return (int) ($generation->diagnostics ?? 0);
    }

    return !empty($data->diagnostics) ? 1 : 0;
}

// Törlés és kilépés (Beal-025/026): POST + sesskey (no GET+sesskey URL).
$abortaction = optional_param('artqtmlabort', '', PARAM_ALPHA);
if ($abortaction === 'delete') {
    if (!data_submitted()) {
        throw new moodle_exception('invalidrequest');
    }
    require_sesskey();
    // Same rule as delete.php: :use + ownership. :configure never authorises this.
    // Glob-040 (V-06): purge() keeps the local_artqtml_log rows - the diagnostic trail survives
    // the generation. (M-29: the draft category and its real Moodle question objects are removed
    // by purge(), so nothing is orphaned.)
    \local_artqtml\local\generation_delete_policy::require_can_delete($generation, $context);
    \local_artqtml\local\generation_deletion::purge($generationid);
    redirect($indexurl);
}

$mform = new generate_form(null, ['generation' => $generation]);

$formaction = optional_param('artqtmlaction', 'generate', PARAM_ALPHA);

if ($mform->is_cancelled()) {
    redirect($indexurl);
} else if ($mform->is_submitted() && in_array($formaction, ['save', 'back'], true)) {
    // Mentés és kilépés (Beal-025/027) / Vissza (Beal-023/024): persist whatever is currently
    // filled in, unvalidated (get_submitted_data(), not get_data() - a draft save must work even
    // with an incomplete/zero count that validation() would otherwise reject), and stay in
    // "started" status. Only the redirect target differs between the two actions.
    require_sesskey();

    $rawdata = $mform->get_submitted_data();
    if ($rawdata) {
        // Only this page's own columns - see generation_source_service::save(). $generation was
        // read when the page opened, so writing it back whole would carry a stale source text and
        // a stale status over anything that changed in the meantime (2026-08-05, BL-51). The lock
        // holds the status re-read and the write together, so a draft save cannot land on a
        // generation that has meanwhile been started.
        \local_artqtml\local\generation_lock::run($generationid, function () use ($DB, $generationid, $rawdata) {
            $current = $DB->get_record('local_artqtml_generations', ['id' => $generationid], '*', MUST_EXIST);
            \local_artqtml\local\generation_edit_policy::require_source_editable($current);

            $DB->update_record('local_artqtml_generations', (object) [
                'id'           => $generationid,
                'settings'     => json_encode(local_artqtml_build_settings($rawdata)),
                'diagnostics'  => local_artqtml_diagnostics_flag($rawdata, $current),
                'timemodified' => time(),
            ]);
        });
    }

    if ($formaction === 'back') {
        redirect(new moodle_url('/local/artqtml/upload.php', ['id' => $generationid]));
    }
    redirect($indexurl);
} else if ($data = $mform->get_data()) {
    // M-01: upload.php only checks these at the START of the "New generation" flow - a
    // generation saved as a draft (Beal-025/027) can sit for a long time before its owner comes
    // back to actually start it here, so the license/token/enabled gate must be re-checked at
    // the point generation is actually kicked off, not assumed still valid from step 1.
    if (!get_config('local_artqtml', 'enabled')) {
        \core\notification::error(get_string('plugindisabled', 'local_artqtml'));
        redirect($indexurl);
    }
    if (\local_artqtml\local\token_budget::is_exceeded('claude') || \local_artqtml\local\token_budget::is_exceeded('gemini')) {
        \core\notification::error(get_string('errortokenbudgetexceeded', 'local_artqtml'));
        redirect($indexurl);
    }
    if (\local_artqtml\local\license_checker::is_blocked()) {
        \core\notification::error(get_string('errorlicenseblocked', 'local_artqtml'));
        redirect($indexurl);
    }
    if (!\local_artqtml\local\draft_bank::is_configured()) {
        \core\notification::error(get_string('errordraftcoursenotconfigured', 'local_artqtml'));
        redirect($indexurl);
    }

    // 2026-08-04: the stored source text is measured again here, and this is not belt-and-braces
    // repetition of the upload page's check. Four ways an oversized text can be sitting in this
    // row without ever having passed that check:
    // - The generation predates the limit existing at all.
    // - An administrator lowered the limit after it was saved.
    // - The row was written by some other route.
    // - The teacher resumed a saved draft and never reopened the upload page.
    //
    // Its position is chosen: BEFORE the draft category is created, before the status becomes
    // GENERATING and before any previous questions are cleared. Refusing later would leave the
    // generation in a started state with its earlier work already deleted, over a problem the
    // teacher can fix in one edit. The redirect goes to the upload page rather than the list, so
    // shortening the text is the next thing on screen.
    if (\local_artqtml\local\source_text_limit::is_exceeded((string) $generation->sourcetext)) {
        \core\notification::error(
            \local_artqtml\local\source_text_limit::error_message((string) $generation->sourcetext)
        );
        redirect(new moodle_url('/local/artqtml/upload.php', ['id' => (int) $generation->id]));
    }

    // Finding #5: intentional defense-in-depth — refuse to queue GENERATING if stored source
    // now fails security_filter (admin pattern change, draft never re-opened on upload, etc.).
    $sourcetext = (string) $generation->sourcetext;
    if (
        \local_artqtml\local\security_filter::has_sql_injection($sourcetext)
        || \local_artqtml\local\security_filter::has_prompt_injection($sourcetext)
    ) {
        \core\notification::error(get_string('errorgenerationunexpected', 'local_artqtml'));
        redirect(new moodle_url('/local/artqtml/upload.php', ['id' => (int) $generation->id]));
    }

    $settings = local_artqtml_build_settings($data);

    // EVERYTHING FROM THE STATUS RE-READ TO THE QUEUE SIGNAL IS ONE LOCKED STEP (2026-08-05,
    // BL-51). The status was checked when this page opened, which is a different request; without
    // the lock, THIS generation submitted twice - a double-click, a resubmitted form - passes that
    // check twice, creates two draft categories, clears the question rows twice and queues the run
    // twice, and it is paid for twice. The draft-bank work is inside the lock deliberately: it is
    // the part that must not happen twice.
    //
    // The start path owns the status, the settings, the draft category, the error and (since
    // BL-57) the userid - and nothing else. Writing the whole record back would also have written
    // the source text as it stood when this page opened, silently undoing an edit made in another
    // tab.
    //
    // The lock stays keyed on the GENERATION, as BL-51 left it. A per-owner key was tried on
    // 2026-08-06 for BL-57 and taken back out: Andras decided that two people pressing Start in
    // the same instant is not a design concern for this product, and the per-generation key is
    // what protects the thing BL-51 was about - the SAME generation started twice.
    $blocking = \local_artqtml\local\generation_lock::run(
        $generationid,
        function () use ($DB, $generationid, $data, $settings) {
            global $USER;

            $current = $DB->get_record('local_artqtml_generations', ['id' => $generationid], '*', MUST_EXIST);
            \local_artqtml\local\generation_edit_policy::require_source_editable($current);

            // BL-57: one running generation per person, counted on whoever presses this button
            // (Andras, 2026-08-06). BEFORE the draft bank is touched, the status is changed or the
            // question rows are cleared - a refusal must leave a startable draft behind, not a
            // half-started generation. Returning the blocking record instead of throwing keeps the
            // refusal a normal screen (a message that names the other generation and leads to it),
            // not an error page.
            $running = \local_artqtml\local\generation_start_policy::find_running(
                (int) $USER->id,
                $generationid
            );
            if ($running !== null) {
                // What the teacher just filled in is kept, exactly as the "Save and exit" button
                // would keep it (Beal-027) - only this page's own columns, the generation staying a
                // draft. Without this, a refusal would silently discard a filled-in grid the
                // teacher would have to type again after cancelling the other run, which is the
                // defect BL-53 fixed elsewhere: a refusal may not destroy the work it refuses.
                $DB->update_record('local_artqtml_generations', (object) [
                    'id'           => $generationid,
                    'settings'     => json_encode($settings),
                    'diagnostics'  => local_artqtml_diagnostics_flag($data, $current),
                    'timemodified' => time(),
                ]);

                return $running;
            }

            if (!empty($current->draftcategoryid)) {
                draft_bank::delete((int) $current->draftcategoryid);
            }
            $draftcategoryid = draft_bank::create($current);

            // Jov-036: give this user the draft-editing role in the draft course, so the Edit and
            // Preview links on the approval page work without an administrator enrolling them by
            // hand. Granted at the start of a generation rather than on first login, because this
            // is the moment the user demonstrably needs it, and the moment a draft course is known
            // to be configured.
            \local_artqtml\local\draft_role::grant((int) $USER->id);

            // BL-57 (Andras, 2026-08-06): the run belongs to whoever started it. `userid` is
            // written here, in the same step that sets `generating`, because that is the moment a
            // queue place is actually spent - and because the limit above counts on this column.
            // Glob-031 lets anyone with local/artqtml:use start anyone's generation, so without
            // this line a colleague could start runs all day without ever using up an allowance.
            //
            // THIS IS VISIBLE, not internal bookkeeping: the list page's "Létrehozó" column and the
            // yellow "you are viewing someone else's generation" bar both read `userid`, so after a
            // colleague starts it, both name the colleague rather than the person who created the
            // draft. That is the intended reading of the column from now on.
            $DB->update_record('local_artqtml_generations', (object) [
                'id'              => $generationid,
                'userid'          => (int) $USER->id,
                'settings'        => json_encode($settings),
                'diagnostics'     => local_artqtml_diagnostics_flag($data, $current),
                'draftcategoryid' => $draftcategoryid,
                'status'          => \local_artqtml\local\generation_status::GENERATING,
                'error'           => null,
                'timemodified'    => time(),
            ]);

            $DB->delete_records('local_artqtml_questions', ['generationid' => $generationid]);

            return null;
        }
    );

    // BL-57: refused because this user already has one running. The message names the other
    // generation and the redirect leads to it - to its status page, which is where the progress is
    // shown and where the "Megszakítás" (Cancel) button is. A refusal that only said no would
    // leave a teacher with a stuck generation no way forward at all (BL-53's lesson: an
    // impossibility must still end somewhere useful).
    //
    // List-018: the destination is not re-derived here either - generation_list::open_url() already
    // sends an in-progress generation to the status page, and a blocking generation is in-progress
    // by definition.
    if ($blocking instanceof stdClass) {
        redirect(
            \local_artqtml\local\generation_list::open_url($blocking),
            get_string('errorgenerationalreadyrunning', 'local_artqtml', format_string((string) $blocking->name)),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Gen-001-021: the "generating" status set above is the queue signal - the
    // process_pending_generations scheduled task picks it up in the background (every 5
    // minutes by default, or on demand via admin/cli/scheduled_task.php --execute).
    \local_artqtml\event\generation_started::create([
        'objectid' => $generationid,
        'context'  => $context,
    ])->trigger();

    redirect(new moodle_url('/local/artqtml/status.php', ['generationid' => $generationid]));
}

// Set form defaults from previously saved settings, if resuming a "started" generation.
if (!empty($generation->settings)) {
    $existing = json_decode($generation->settings, true);
    if (is_array($existing)) {
        $formdefaults = [
            'difficultymode'      => $existing['difficulty']['mode'] ?? 'scale',
            'freetextdescription' => $existing['difficulty']['freetext']['description'] ?? '',
            'knowledgesource'     => $existing['knowledgesource'] ?? 'sourceonly',
            'negationhighlight'   => !empty($existing['negationhighlight']),
        ];
        // BL-35: repopulate the grid. A generation saved before the grid existed has no 'matrix'
        // key; its per-type counts are all it kept, and which level they belonged to was never
        // recorded, so nothing can be reconstructed - the grid comes up empty and the teacher
        // fills it in. The plain count_ fields still carry those old numbers for free text mode.
        foreach (($existing['matrix'] ?? []) as $code => $bylevel) {
            foreach ((array) $bylevel as $level => $count) {
                $formdefaults['matrix_' . $code . '_' . $level] = (int) $count;
            }
        }

        foreach (question_types::CODES as $code) {
            $formdefaults['count_' . $code] = $existing['counts'][$code] ?? 0;
            $formdefaults['retry_' . $code] = !empty($existing['types'][$code]['retryenabled']);
            $formdefaults['retrypenalty_' . $code] = $existing['types'][$code]['retrypenalty'] ?? 33;
            $formdefaults['instruction_' . $code] = $existing['types'][$code]['instruction'] ?? '';
            // Gen-022/025: independent per-type switches now, not one generation-wide setting.
            // Cursor audit v3 #4/#5: hint_ now rendered/read for all six types - see the
            // matching comment on local_artqtml_build_settings() above.
            $formdefaults['feedback_' . $code] = !empty($existing['types'][$code]['feedbackenabled']);
            $formdefaults['hint_' . $code] = !empty($existing['types'][$code]['hintenabled']);
            if (question_types::supports_option_explanation($code)) {
                $formdefaults['explanation_' . $code] =
                    !empty($existing['types'][$code]['explanationenabled']);
            }
        }
        $mform->set_data($formdefaults);
    }
}

// BL-35: the previous generation's total for the same source text used to be looked up here, as Y
// in the X/Y difference indicator (Beal-008) - and the submit button was disabled unless X equalled
// it (Beal-009). The grid replaced the two-axis form the indicator compared, so the indicator went;
// the query and the rule are gone with it, by András's decision on 2026-08-01, after the leftover
// rule silently greyed out the "generate the missing types" follow-up, which asks for less on
// purpose and could therefore never be started.

echo $OUTPUT->header();
echo local_artqtml_model_warning_banner();
echo local_artqtml_owner_warning_banner($generation);
$mform->display();

$candeleteown = \local_artqtml\local\generation_delete_policy::can_delete($generation, null, $context);
// The "Generálás indítása", "Vissza" and "Megszakít" buttons all need the currently-typed field
// values, so
// all three live outside generate_form's own <form> as plain (type=button) elements in this one
// row, and amd/src/generatesettings.js submits the real form on their behalf via requestSubmit() -
// see that file for why a native form="..." cross-reference isn't used here.
echo html_writer::start_div('mt-3');
echo html_writer::tag('button', get_string('startgeneration', 'local_artqtml'), [
    'type'  => 'button',
    'id'    => 'artqtml-submitbutton',
    'class' => 'btn btn-primary mr-2',
]);
echo html_writer::tag('button', get_string('backtoupload', 'local_artqtml'), [
    'type'  => 'button',
    'id'    => 'artqtml-backbutton',
    'class' => 'btn btn-outline-secondary mr-2',
]);
echo html_writer::tag('button', get_string('abortbutton', 'local_artqtml'), [
    'type'  => 'button',
    'id'    => 'artqtml-abortbutton',
    'class' => 'btn btn-outline-danger',
]);
echo html_writer::end_div();

// Beal-025: one "Megszakít" button opens this modal, offering all three outcomes in one place
// (Törlés és kilépés / Mentés és kilépés / Mégsem) instead of separate always-visible buttons
// each with their own single confirm(). Plain markup + inline styles, no Bootstrap JS/AMD
// dependency, consistent with this plugin's other JS files.
// "Delete and exit" is owner+:use only (same rule as delete.php) - non-owners still get save/cancel.
echo html_writer::start_div('', [
    'id' => 'artqtml-abortmodal',
    'style' => 'display:none; position:fixed; top:0; left:0; width:100%; height:100%;' .
        ' background:rgba(0,0,0,0.5); z-index:1050;',
]);
echo html_writer::start_div('bg-white rounded p-4', [
    'style' => 'max-width:28rem; margin:10vh auto; box-shadow:0 0.5rem 1rem rgba(0,0,0,0.3);',
]);
echo html_writer::tag('p', get_string('abortsaveconfirm', 'local_artqtml', format_string($generation->name)));
if ($candeleteown) {
    echo html_writer::tag('button', get_string('abortdelete', 'local_artqtml'), [
        'type' => 'button', 'id' => 'artqtml-abortmodal-delete', 'class' => 'btn btn-outline-danger btn-block mb-2',
    ]);
}
echo html_writer::tag('button', get_string('abortsave', 'local_artqtml'), [
    'type' => 'button', 'id' => 'artqtml-abortmodal-save', 'class' => 'btn btn-outline-secondary btn-block mb-2',
]);
echo html_writer::tag('button', get_string('abortcancel', 'local_artqtml'), [
    'type' => 'button', 'id' => 'artqtml-abortmodal-cancel', 'class' => 'btn btn-secondary btn-block',
]);
echo html_writer::end_div();
echo html_writer::end_div();

// Hidden POST form for "Delete and exit" — submitted by amd/src/generatesettings.js.
if ($candeleteown) {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/local/artqtml/generate.php'))->out(false),
        'id' => 'artqtml-abortdelete-form',
        'class' => 'd-none',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $generationid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'artqtmlabort', 'value' => 'delete']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::end_tag('form');
}

// Beal-019/020/021: the token estimate is relative to the admin-configured monthly token budget
// and its warning threshold, not a hardcoded count/percentage.
$tokenbudget = (int) get_config('local_artqtml', 'generatortokenbudget');
$tokenwarningpct = (int) (get_config('local_artqtml', 'tokenbudgetwarningpct') ?: 80);

$amdabort = [
    'backconfirm' => get_string('backtoupload_confirm', 'local_artqtml'),
];

$PAGE->requires->js_call_amd('local_artqtml/generatesettings', 'init', [
    [
        'step2total' => get_string('step2totallabel', 'local_artqtml'),
    ],
    $amdabort,
    $tokenbudget,
    $tokenwarningpct,
]);

echo $OUTPUT->footer();
