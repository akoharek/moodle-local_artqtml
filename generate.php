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
 * Step 2 of the "New generation" flow: question counts, difficulty mode, detailed
 * Per-type options.
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

if (!empty($generation->error)) {
    \core\notification::error($generation->error);
    $DB->set_field('local_artqtml_generations', 'error', null, ['id' => $generationid]);
    $generation->error = null;
}

$PAGE->set_url('/local/artqtml/generate.php', ['id' => $generationid]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->add_body_class('mediumwidth');
$PAGE->set_title(get_string('generatesettingsheading', 'local_artqtml'));
$PAGE->set_heading(get_string('generatesettingsheading', 'local_artqtml'));

$indexurl = new moodle_url('/local/artqtml/index.php');

/**
 * Build the JSON-encodable settings array from submitted form data.
 *
 * @param \stdClass $data as returned by generate_form::get_data() or get_submitted_data()
 * @return array
 */
function local_artqtml_build_settings(stdClass $data): array {
    $mode = 'scale';
    $levels = generate_form::MODE_LEVELS[$mode];

    $matrix = [];
    foreach (question_types::CODES as $code) {
        foreach ($levels as $level) {
            $matrix[$code][$level] = (int) ($data->{'matrix_' . $code . '_' . $level} ?? 0);
        }
    }

    $leveltotal = static function (array $matrix, string $level): int {
        $sum = 0;
        foreach ($matrix as $bytype) {
            $sum += (int) ($bytype[$level] ?? 0);
        }

        return $sum;
    };

    $settings = [
        'difficulty' => [
            'mode'  => $mode,
            'scale' => [
                'easy'   => $leveltotal($matrix, 'easy'),
                'medium' => $leveltotal($matrix, 'medium'),
                'hard'   => $leveltotal($matrix, 'hard'),
            ],
        ],
        'matrix'            => $matrix,
        'counts'            => [],
        'knowledgesource'   => 'sourceonly',
        'negationhighlight' => (bool) ($data->negationhighlight ?? false),
        'types'             => [],
    ];

    foreach (question_types::CODES as $code) {
        $settings['counts'][$code] = array_sum($matrix[$code]);
        $settings['types'][$code] = [
            'retryenabled'    => question_types::supports_retry($code) ? (bool) ($data->{'retry_' . $code} ?? false) : false,
            'retrypenalty'    => (int) ($data->{'retrypenalty_' . $code} ?? 33),
            'feedbackenabled' => (bool) ($data->{'feedback_' . $code} ?? false),
            'hintenabled'     => (bool) ($data->{'hint_' . $code} ?? false),
            'explanationenabled' => question_types::supports_option_explanation($code)
                && (bool) ($data->{'explanation_' . $code} ?? false),
        ];
        if ($code === 'SR') {
            $settings['types'][$code]['sritemcount'] = (int) ($data->sritemcount ?? 0);
        }
    }

    return $settings;
}

// Törlés és kilépés: POST + sesskey (no GET+sesskey URL).
$abortaction = optional_param('artqtmlabort', '', PARAM_ALPHA);
if ($abortaction === 'delete') {
    if (!data_submitted()) {
        throw new moodle_exception('invalidrequest');
    }
    require_sesskey();
    \local_artqtml\local\generation_delete_policy::require_can_delete($generation, $context);
    \local_artqtml\local\generation_deletion::purge($generationid);
    redirect($indexurl);
}

$mform = new generate_form(null, ['generation' => $generation]);

$formaction = optional_param('artqtmlaction', 'generate', PARAM_ALPHA);

if ($mform->is_cancelled()) {
    redirect($indexurl);
} else if ($mform->is_submitted() && in_array($formaction, ['save', 'back'], true)) {
    require_sesskey();

    $rawdata = $mform->get_submitted_data();
    if ($rawdata) {
        \local_artqtml\local\generation_lock::run($generationid, function () use ($DB, $generationid, $rawdata) {
            $current = $DB->get_record('local_artqtml_generations', ['id' => $generationid], '*', MUST_EXIST);
            \local_artqtml\local\generation_edit_policy::require_source_editable($current);

            $DB->update_record('local_artqtml_generations', (object) [
                'id'           => $generationid,
                'settings'     => json_encode(local_artqtml_build_settings($rawdata)),
                'timemodified' => time(),
            ]);
        });
    }

    if ($formaction === 'back') {
        redirect(new moodle_url('/local/artqtml/upload.php', ['id' => $generationid]));
    }
    redirect($indexurl);
} else if ($data = $mform->get_data()) {
    if (!get_config('local_artqtml', 'enabled')) {
        \core\notification::error(get_string('plugindisabled', 'local_artqtml'));
        redirect($indexurl);
    }
    if (!\local_artqtml\local\draft_bank::is_configured()) {
        \core\notification::error(get_string('errordraftcoursenotconfigured', 'local_artqtml'));
        redirect($indexurl);
    }

    if (\local_artqtml\local\source_text_limit::is_exceeded((string) $generation->sourcetext)) {
        \core\notification::error(
            \local_artqtml\local\source_text_limit::error_message((string) $generation->sourcetext)
        );
        redirect(new moodle_url('/local/artqtml/upload.php', ['id' => (int) $generation->id]));
    }

    $sourcetext = (string) $generation->sourcetext;
    if (
        \local_artqtml\local\security_filter::has_sql_injection($sourcetext)
        || \local_artqtml\local\security_filter::has_prompt_injection($sourcetext)
    ) {
        \core\notification::error(get_string('errorgenerationunexpected', 'local_artqtml'));
        redirect(new moodle_url('/local/artqtml/upload.php', ['id' => (int) $generation->id]));
    }

    $settings = local_artqtml_build_settings($data);

    $blocking = \local_artqtml\local\generation_lock::run(
        $generationid,
        function () use ($DB, $generationid, $settings) {
            global $USER;

            $current = $DB->get_record('local_artqtml_generations', ['id' => $generationid], '*', MUST_EXIST);
            \local_artqtml\local\generation_edit_policy::require_source_editable($current);

            $running = \local_artqtml\local\generation_start_policy::find_running(
                (int) $USER->id,
                $generationid
            );
            if ($running !== null) {
                $DB->update_record('local_artqtml_generations', (object) [
                    'id'           => $generationid,
                    'settings'     => json_encode($settings),
                    'timemodified' => time(),
                ]);

                return $running;
            }

            if (!empty($current->draftcategoryid)) {
                draft_bank::delete((int) $current->draftcategoryid);
            }
            $draftcategoryid = draft_bank::create($current);

            \local_artqtml\local\draft_role::grant((int) $USER->id);

            $DB->update_record('local_artqtml_generations', (object) [
                'id'              => $generationid,
                'userid'          => (int) $USER->id,
                'settings'        => json_encode($settings),
                'draftcategoryid' => $draftcategoryid,
                'status'          => \local_artqtml\local\generation_status::GENERATING,
                'error'           => null,
                'timemodified'    => time(),
            ]);

            $DB->delete_records('local_artqtml_questions', ['generationid' => $generationid]);

            return null;
        }
    );

    if ($blocking instanceof stdClass) {
        redirect(
            \local_artqtml\local\generation_list::open_url($blocking),
            get_string('errorgenerationalreadyrunning', 'local_artqtml', format_string((string) $blocking->name)),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

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
            'difficultymode'      => 'scale',
            'negationhighlight'   => !empty($existing['negationhighlight']),
        ];
        foreach (($existing['matrix'] ?? []) as $code => $bylevel) {
            foreach ((array) $bylevel as $level => $count) {
                $formdefaults['matrix_' . $code . '_' . $level] = (int) $count;
            }
        }

        foreach (question_types::CODES as $code) {
            $formdefaults['retry_' . $code] = !empty($existing['types'][$code]['retryenabled']);
            $formdefaults['retrypenalty_' . $code] = $existing['types'][$code]['retrypenalty'] ?? 33;
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


echo $OUTPUT->header();
echo local_artqtml_model_warning_banner();
echo local_artqtml_owner_warning_banner($generation);
$mform->display();

$candeleteown = \local_artqtml\local\generation_delete_policy::can_delete($generation, null, $context);
// The "Generálás indítása", "Vissza" and "Megszakít" buttons all need the currently-typed field
// Values, so
// All three live outside generate_form's own <form> as plain (type=button) elements in this one
// Row, and amd/src/generatesettings.js submits the real form on their behalf via requestSubmit() -
// See that file for why a native form="..." cross-reference isn't used here.
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

$amdabort = [
    'backconfirm' => get_string('backtoupload_confirm', 'local_artqtml'),
];

$PAGE->requires->js_call_amd('local_artqtml/generatesettings', 'init', [
    [
        'step2total' => get_string('step2totallabel', 'local_artqtml'),
    ],
    $amdabort,
]);

echo $OUTPUT->footer();
