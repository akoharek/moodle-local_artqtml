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
 * Admin settings for local_artqtml (functional spec ch.8): six settings pages under one
 * category (Általános, Generáló LLM, Validáló LLM, Kérdéstípus, Biztonság, Token kezelés),
 * plus a separate licence external page.
 *
 * Moodle's admin_settingpage API does not support in-page tabs for a single settings form,
 * so "6 tabs" is implemented as 6 sibling pages under one admin_category - functionally
 * equivalent navigation, each reachable from the same place in Site administration.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Moodle can include a plugin's settings.php more than once per request while building/
// caching the admin tree. require_once (unlike settings.php's own inclusion, which is a
// plain include elsewhere in core) is safe against that, and guarantees
// local_artqtml_render_test_button() is defined before it's used below regardless of
// whether any navigation hook has already pulled lib.php in.
require_once($CFG->dirroot . '/local/artqtml/lib.php');

// Product decision 2026-08-10: settings are local/artqtml:configure territory only.
// Register for site admins (hassiteconfig) and for users who hold :configure without
// moodle/site:config - otherwise a configure-only manager would never see the panel.
// Generation UI requires local/artqtml:use and is never registered here.
if ($hassiteconfig || has_capability('local/artqtml:configure', context_system::instance())) {
    $ADMIN->add('localplugins', new admin_category('local_artqtml_category', get_string('pluginname', 'local_artqtml')));

    // Lic-008: the same license warning/blocked banner is shown at the top of every tab.
    $licensebanner = local_artqtml_license_warning_banner();
    // Jov-023: same "shown on every tab" treatment for the draft course misconfiguration banner.
    $draftcoursebanner = local_artqtml_draftcourse_warning_banner();
    // Admin-033 (C5): same "shown on every tab" treatment for the token-budget warning banner.
    $tokenbanner = local_artqtml_token_warning_banner();

    // ------------------------------------------------------------------
    // Admin-003-009, Admin-035: általános fül.
    $general = new admin_settingpage(
        'local_artqtml_general',
        get_string('tabgeneral', 'local_artqtml'),
        'local/artqtml:configure'
    );

    if ($licensebanner !== '') {
        $general->add(new admin_setting_description('local_artqtml/licensebanner_general', '', $licensebanner));
    }
    if ($draftcoursebanner !== '') {
        $general->add(new admin_setting_description('local_artqtml/draftcoursebanner_general', '', $draftcoursebanner));
    }
    if ($tokenbanner !== '') {
        $general->add(new admin_setting_description('local_artqtml/tokenbanner_general', '', $tokenbanner));
    }

    $general->add(new admin_setting_configcheckbox(
        'local_artqtml/enabled',
        get_string('settingenabled', 'local_artqtml'),
        get_string('settingenabled_desc', 'local_artqtml'),
        1
    ));
    $general->add(new \local_artqtml\admin\setting_configtext_min(
        'local_artqtml/maxquestionsperrun',
        get_string('settingmaxquestionsperrun', 'local_artqtml'),
        get_string('settingmaxquestionsperrun_desc', 'local_artqtml'),
        50,
        1
    ));
    // 2026-08-04: the source text had no server-side size limit at all. The upload page counted
    // characters, words and estimated tokens in JavaScript and coloured the number red past the
    // context window - and then saved and sent whatever was there. A crafted POST skipped even the
    // colouring. 0 keeps the number in one place: it means 80% of the generator's context window,
    // so raising that window does not silently leave a second, unrelated limit behind.
    $general->add(new \local_artqtml\admin\setting_configtext_min(
        'local_artqtml/maxsourcetokens',
        get_string('settingmaxsourcetokens', 'local_artqtml'),
        get_string('settingmaxsourcetokens_desc', 'local_artqtml'),
        0,
        0
    ));
    $general->add(new \local_artqtml\admin\setting_configtext_min(
        'local_artqtml/apitimeout',
        get_string('settingapitimeout', 'local_artqtml'),
        get_string('settingapitimeout_desc', 'local_artqtml'),
        60,
        1
    ));
    $general->add(new admin_setting_configselect(
        'local_artqtml/defaultdifficultymode',
        get_string('settingdefaultdifficultymode', 'local_artqtml'),
        '',
        'scale',
        [
            'bloom'    => get_string('difficultymode_bloom', 'local_artqtml'),
            'scale'    => get_string('difficultymode_scale', 'local_artqtml'),
            'freetext' => get_string('difficultymode_freetext', 'local_artqtml'),
        ]
    ));
    // BL-47: the "Seed" setting and its change warning (Admin-009) were removed on 2026-08-03.
    // The value never reached the model as a sampling parameter - the Claude Messages API has no
    // `seed` parameter at all - it only ever went into the prompt as the line "Seed: <n>". Measured
    // the same day: changing it from 42 to 77 and re-running the same cell on the same source text
    // returned two of six questions word-for-word identical and no new material. A control that
    // cannot move is worse than no control, because its own description ("ensures reproducibility")
    // sends whoever finds it down a day-long dead end, as it did here.
    $general->add(new admin_setting_configcheckbox(
        'local_artqtml/debugmode',
        get_string('settingdebugmode', 'local_artqtml'),
        get_string('settingdebugmode_desc', 'local_artqtml'),
        0
    ));
    // Finding #4: PHP debug file log path is fixed under dataroot (not free-form). Show the
    // resolved path as read-only help so admins can still find the file; legacy debugfilepath
    // config is ignored and removed on upgrade.
    $general->add(new admin_setting_description(
        'local_artqtml/debugfilepathinfo',
        get_string('settingdebugfilepath', 'local_artqtml'),
        get_string(
            'settingdebugfilepath_desc',
            'local_artqtml',
            s(\local_artqtml\local\debug_logger::path())
        )
    ));
    // 2026-08-04: how long the FULL diagnostic payload is kept. The log row and every technical
    // field on it are permanent (Glob-040); this governs only the system prompt, the response
    // schema and the raw provider response, which are the parts that can carry a teacher's
    // material. There is deliberately no "unlimited": a retention period that can be switched off
    // is not one, and this data loses its usefulness long before it loses its sensitivity.
    $general->add(new \local_artqtml\admin\setting_configtext_min(
        'local_artqtml/diagnosticretentiondays',
        get_string('settingdiagnosticretentiondays', 'local_artqtml'),
        get_string('settingdiagnosticretentiondays_desc', 'local_artqtml'),
        \local_artqtml\local\diagnostic_log_retention::DEFAULT_RETENTION_DAYS,
        \local_artqtml\local\diagnostic_log_retention::MIN_RETENTION_DAYS
    ));
    $general->add(new \local_artqtml\admin\setting_maxfilesize(
        'local_artqtml/maxfilesize',
        get_string('settingmaxfilesize', 'local_artqtml'),
        get_string('settingmaxfilesize_desc', 'local_artqtml'),
        2097152,
        PARAM_INT
    ));
    // Jov-023: draft categories live in this course's context instead of context_system -
    // see classes/local/draft_bank.php. Left unset (0) on a fresh install deliberately, rather
    // than defaulting to e.g. the site course, since blocking new generations until an admin
    // consciously picks a real, dedicated course is the whole point of this setting.
    $general->add(new admin_setting_configtext(
        'local_artqtml/draftcourseid',
        get_string('settingdraftcourseid', 'local_artqtml'),
        get_string('settingdraftcourseid_desc', 'local_artqtml'),
        0,
        PARAM_INT
    ));

    $ADMIN->add('local_artqtml_category', $general);

    // ------------------------------------------------------------------
    // Generáló LLM fül (Admin-010-015).
    $generator = new admin_settingpage(
        'local_artqtml_generator',
        get_string('tabgenerator', 'local_artqtml'),
        'local/artqtml:configure'
    );

    if ($licensebanner !== '') {
        $generator->add(new admin_setting_description('local_artqtml/licensebanner_generator', '', $licensebanner));
    }
    if ($draftcoursebanner !== '') {
        $generator->add(new admin_setting_description('local_artqtml/draftcoursebanner_generator', '', $draftcoursebanner));
    }
    if ($tokenbanner !== '') {
        $generator->add(new admin_setting_description('local_artqtml/tokenbanner_generator', '', $tokenbanner));
    }

    $generator->add(new \local_artqtml\admin\setting_encryptedapikey(
        'local_artqtml/claudeapikey',
        get_string('claudeapikey', 'local_artqtml'),
        get_string('claudeapikey_desc', 'local_artqtml'),
        ''
    ));
    $generator->add(new admin_setting_description(
        'local_artqtml/claudetest',
        get_string('settingtestconnection', 'local_artqtml'),
        local_artqtml_render_test_button('claude')
    ));
    // Admin-044/047/048/050/051: a select fed from the cached provider list, with no factory
    // default and no free-text alternative.
    $generator->add(new \local_artqtml\admin\setting_modelselect(
        'local_artqtml/claudemodel',
        get_string('claudemodel', 'local_artqtml'),
        get_string('claudemodel_desc', 'local_artqtml'),
        \local_artqtml\local\model_list::PROVIDER_CLAUDE
    ));
    $generator->add(new admin_setting_description(
        'local_artqtml/claudemodelactions',
        '',
        local_artqtml_render_model_buttons(\local_artqtml\local\model_list::PROVIDER_CLAUDE)
    ));
    $generator->add(new \local_artqtml\admin\setting_configtext_min(
        'local_artqtml/generatorcontextwindow',
        get_string('settinggeneratorcontextwindow', 'local_artqtml'),
        get_string('settinggeneratorcontextwindow_desc', 'local_artqtml'),
        8192,
        1
    ));
    // Admin-066: the whole system prompt lives here, in plain text, and nowhere else. No
    // encryption, no encoding, no prompt text in the lang packs or in PHP. The value shipped with
    // the plugin is written into the database once by install/upgrade (db/prompt_defaults.php);
    // from then on this page is the only source.
    //
    // The accepted consequence is that an administrator can break it - deleting a placeholder
    // means the value behind it never reaches the model. That is a deliberate trade for a prompt
    // its own maintainers can read.
    $generator->add(new admin_setting_configtextarea(
        'local_artqtml/generatorprompttemplate',
        get_string('settinggeneratorprompttemplate', 'local_artqtml'),
        get_string('settinggeneratorprompttemplate_desc', 'local_artqtml'),
        '',
        PARAM_RAW,
        '60',
        '12'
    ));

    // Admin-067: the sentences that only apply to some generations. The code decides whether each
    // one belongs in a given prompt; it no longer holds the sentence itself.
    //
    // The box dimensions are strings, not numbers, because that is what admin_setting_configtextarea
    // declares - it hands them straight to the textarea attributes.
    $promptfragments = [
        'promptknowledgesourceonly'   => '4',
        'promptknowledgeownknowledge' => '4',
        'promptnegation'              => '4',
        'promptoptioncount'           => '4',
        'promptitemcount'             => '4',
        // BL-32: what a short-answer question may ask, so that one word is the whole answer.
        'promptshortanswer'           => '6',
        // Always on: do not name the source document in the stem ("szöveg szerint" / "according
        // to the text"). Backed by strip + semantic reject server-side.
        'promptnosourcemetaref'       => '5',
        // Admin-069: what the difficulty levels mean. Without these the prompt carried the labels
        // and nothing else ("Difficulty: Easy: 2, Medium: 2, Hard: 2"), so the model invented its
        // own scale - measured on 181 questions, 72 of them did not match the level they were
        // asked for. Ten rows because these are definitions, not one-liners.
        'promptdifficultyscale'       => '10',
        'promptdifficultybloom'       => '10',
        // 2026-08-04: the two fragments the system prompt uses INSTEAD of the teacher's own words.
        // A teacher's free-text difficulty and per-type instruction used to be substituted into the
        // system prompt directly, which gave them the administrator's authority. They now travel in
        // the structured user message, and these two sentences are what the system prompt says
        // about them. Editable like every other fragment - but note that emptying one only removes
        // the explanation, it does not put the teacher's text back into the system prompt.
        'promptdifficultyfreetextreference' => '4',
        'promptteacherinstructionreference' => '5',
        'promptfeedbackcorrect'       => '4',
        'promptfeedbackincorrect'     => '4',
        // BL-29: what an explanation attached to one answer option should say. Only reaches the
        // prompt for the types and generations where the switch is on, the same way the schema
        // field does - the two have to agree, or the model is asked for something the response
        // cannot carry.
        'promptoptionexplanation'     => '6',
        // BL-29, second round: the extra clause True/False gets on top of the one above, because
        // two options over a single claim leave the model nothing to say twice except the claim.
        'promptoptionexplanationtruefalse' => '6',
        'promptjsoninvalid'           => '4',
    ];
    foreach ($promptfragments as $fragment => $rows) {
        $generator->add(new admin_setting_configtextarea(
            'local_artqtml/' . $fragment,
            get_string('setting' . $fragment, 'local_artqtml'),
            get_string('setting' . $fragment . '_desc', 'local_artqtml'),
            '',
            PARAM_RAW,
            '60',
            $rows
        ));
    }

    $ADMIN->add('local_artqtml_category', $generator);

    // ------------------------------------------------------------------
    // Validáló LLM fül (Admin-016-021).
    $validator = new admin_settingpage(
        'local_artqtml_validator',
        get_string('tabvalidator', 'local_artqtml'),
        'local/artqtml:configure'
    );

    if ($licensebanner !== '') {
        $validator->add(new admin_setting_description('local_artqtml/licensebanner_validator', '', $licensebanner));
    }
    if ($draftcoursebanner !== '') {
        $validator->add(new admin_setting_description('local_artqtml/draftcoursebanner_validator', '', $draftcoursebanner));
    }
    if ($tokenbanner !== '') {
        $validator->add(new admin_setting_description('local_artqtml/tokenbanner_validator', '', $tokenbanner));
    }

    $validator->add(new \local_artqtml\admin\setting_encryptedapikey(
        'local_artqtml/geminiapikey',
        get_string('geminiapikey', 'local_artqtml'),
        get_string('geminiapikey_desc', 'local_artqtml'),
        ''
    ));
    $validator->add(new admin_setting_description(
        'local_artqtml/geminitest',
        get_string('settingtestconnection', 'local_artqtml'),
        local_artqtml_render_test_button('gemini')
    ));
    $validator->add(new \local_artqtml\admin\setting_modelselect(
        'local_artqtml/geminimodel',
        get_string('geminimodel', 'local_artqtml'),
        get_string('geminimodel_desc', 'local_artqtml'),
        \local_artqtml\local\model_list::PROVIDER_GEMINI
    ));
    $validator->add(new admin_setting_description(
        'local_artqtml/geminimodelactions',
        '',
        local_artqtml_render_model_buttons(\local_artqtml\local\model_list::PROVIDER_GEMINI)
    ));
    $validator->add(new \local_artqtml\admin\setting_configtext_min(
        'local_artqtml/validatorcontextwindow',
        get_string('settingvalidatorcontextwindow', 'local_artqtml'),
        get_string('settingvalidatorcontextwindow_desc', 'local_artqtml'),
        1000000,
        1
    ));
    // Admin-066/067, same arrangement as the generator tab: the whole prompt in plain text, and
    // the clauses the code used to append are fields of their own.
    $validator->add(new admin_setting_configtextarea(
        'local_artqtml/validatorprompttemplate',
        get_string('settingvalidatorprompttemplate', 'local_artqtml'),
        get_string('settingvalidatorprompttemplate_desc', 'local_artqtml'),
        '',
        PARAM_RAW,
        '60',
        '10'
    ));

    $validatorfragments = [
        'validationpromptsuggestion',
        'validationpromptcategory',
        'validationpromptlanguage',
        // Val-031/Val-032: the two criteria the validator did not have. The first embeds the same
        // level definitions the generator was given ({{DIFFICULTY_DEFINITIONS}}).
        'validationpromptdifficulty',
        'validationpromptwording',
        // Val-033: ordering items must come from the source text - see BL-31.
        'validationpromptitemsource',
        // BL-32: a short answer the student cannot type is one they can know and still fail.
        'validationpromptshortanswer',
    ];
    foreach ($validatorfragments as $fragment) {
        $validator->add(new admin_setting_configtextarea(
            'local_artqtml/' . $fragment,
            get_string('setting' . $fragment, 'local_artqtml'),
            get_string('setting' . $fragment . '_desc', 'local_artqtml'),
            '',
            PARAM_RAW,
            '60',
            '4'
        ));
    }

    $ADMIN->add('local_artqtml_category', $validator);

    // ------------------------------------------------------------------
    // Kérdéstípus beállítások fül (Admin-022-027, Admin-036-038).
    $qtypes = new admin_settingpage(
        'local_artqtml_qtypes',
        get_string('tabqtypes', 'local_artqtml'),
        'local/artqtml:configure'
    );

    if ($licensebanner !== '') {
        $qtypes->add(new admin_setting_description('local_artqtml/licensebanner_qtypes', '', $licensebanner));
    }
    if ($draftcoursebanner !== '') {
        $qtypes->add(new admin_setting_description('local_artqtml/draftcoursebanner_qtypes', '', $draftcoursebanner));
    }
    if ($tokenbanner !== '') {
        $qtypes->add(new admin_setting_description('local_artqtml/tokenbanner_qtypes', '', $tokenbanner));
    }

    foreach (\local_artqtml\local\question_types::CODES as $code) {
        $lowercode = strtolower($code);
        $label = \local_artqtml\local\question_types::label($code);

        $qtypes->add(new admin_setting_heading(
            'local_artqtml/feedbackheading_' . $lowercode,
            $label,
            ''
        ));
        $qtypes->add(new admin_setting_configtextarea(
            'local_artqtml/feedback_' . $lowercode . '_correct',
            get_string('settingfeedbackcorrect', 'local_artqtml'),
            '',
            ''
        ));
        // Admin-022: FT (multi-answer) is the only type where "partially correct" is a real,
        // distinct qtype_multichoice grading outcome - see question_importer::apply_multichoice().
        if ($code === 'FT') {
            $qtypes->add(new admin_setting_configtextarea(
                'local_artqtml/feedback_ft_partial',
                get_string('settingfeedbackpartial', 'local_artqtml'),
                '',
                ''
            ));
        }
        $qtypes->add(new admin_setting_configtextarea(
            'local_artqtml/feedback_' . $lowercode . '_incorrect',
            get_string('settingfeedbackincorrect', 'local_artqtml'),
            '',
            ''
        ));
        // Admin-027: admin-level default AI instruction per type, used as the per-generation
        // field's prefilled default (generate_form.php) and as the prompt-build fallback when
        // the user leaves that field empty (generate_questions_task::build_prompt()) - applies
        // even when Admin-026 hides the field entirely.
        $qtypes->add(new admin_setting_configtextarea(
            'local_artqtml/instructiondefault_' . $lowercode,
            get_string('settinginstructiondefault', 'local_artqtml'),
            get_string('settinginstructiondefault_desc', 'local_artqtml'),
            ''
        ));
    }

    $qtypes->add(new admin_setting_configcheckbox(
        'local_artqtml/showstandardinstruction',
        get_string('settingshowstandardinstruction', 'local_artqtml'),
        get_string('settingshowstandardinstruction_desc', 'local_artqtml'),
        1
    ));
    $qtypes->add(new admin_setting_configcheckbox(
        'local_artqtml/retrydefault',
        get_string('settingretrydefault', 'local_artqtml'),
        get_string('settingretrydefault_desc', 'local_artqtml'),
        0
    ));
    // BL-29: the default for the per-answer explanation switch on the question settings page.
    // Off, like the hint default and for the same reason - the explanation is written per option,
    // so a six-question generation with four options each costs twenty-four extra sentences. A
    // teacher who wants it turns it on; nobody pays for it by accident.
    $qtypes->add(new admin_setting_configcheckbox(
        'local_artqtml/explanationdefault',
        get_string('settingexplanationdefault', 'local_artqtml'),
        get_string('settingexplanationdefault_desc', 'local_artqtml'),
        0
    ));
    $qtypes->add(new admin_setting_configcheckbox(
        'local_artqtml/negationhighlightdefault',
        get_string('settingnegationhighlightdefault', 'local_artqtml'),
        get_string('settingnegationhighlightdefault_desc', 'local_artqtml'),
        0
    ));
    $qtypes->add(new \local_artqtml\admin\setting_configtext_atleast(
        'local_artqtml/fefminoptions',
        get_string('settingfefminoptions', 'local_artqtml'),
        get_string('settingfefminoptions_desc', 'local_artqtml'),
        2,
        'fefmaxoptions',
        1,
        true
    ));
    $qtypes->add(new \local_artqtml\admin\setting_configtext_atleast(
        'local_artqtml/fefmaxoptions',
        get_string('settingfefmaxoptions', 'local_artqtml'),
        get_string('settingfefmaxoptions_desc', 'local_artqtml'),
        5,
        'fefminoptions',
        1
    ));
    $qtypes->add(new \local_artqtml\admin\setting_configtext_min(
        'local_artqtml/sritemcount',
        get_string('settingsritemcount', 'local_artqtml'),
        get_string('settingsritemcount_desc', 'local_artqtml'),
        4,
        1
    ));

    // Admin-037/038: real, labelled qtype_ordering option sets, not unlabelled raw int/string
    // fields. Deliberately a local_artqtml-owned copy of qtype_ordering's own constants/labels
    // (question/type/ordering/question.php's GRADING_*/get_numbering_styles()) rather than
    // loading that class directly - qtype_ordering's own classes are only autoloaded via the
    // question engine's bootstrap (question_bank::get_qtype()), which settings.php never
    // triggers, and qtype_ordering is a contrib dependency (CLAUDE.md) that may not even be
    // installed on every target - so this avoids a fragile require chain (or a hard dependency)
    // just to render an admin dropdown.
    $qtypes->add(new admin_setting_configselect(
        'local_artqtml/orderinggradingtype',
        get_string('settingorderinggradingtype', 'local_artqtml'),
        get_string('settingorderinggradingtype_desc', 'local_artqtml'),
        0,
        [
            -1 => get_string('orderinggradingtype_allornothing', 'local_artqtml'),
            0  => get_string('orderinggradingtype_absoluteposition', 'local_artqtml'),
            1  => get_string('orderinggradingtype_relativenextexcludelast', 'local_artqtml'),
            2  => get_string('orderinggradingtype_relativenextincludelast', 'local_artqtml'),
            3  => get_string('orderinggradingtype_relativeonepreviousandnext', 'local_artqtml'),
            4  => get_string('orderinggradingtype_relativeallpreviousandnext', 'local_artqtml'),
            5  => get_string('orderinggradingtype_longestorderedsubset', 'local_artqtml'),
            6  => get_string('orderinggradingtype_longestcontiguoussubset', 'local_artqtml'),
            7  => get_string('orderinggradingtype_relativetocorrect', 'local_artqtml'),
        ]
    ));
    $qtypes->add(new admin_setting_configselect(
        'local_artqtml/orderingnumberingtype',
        get_string('settingorderingnumberingtype', 'local_artqtml'),
        get_string('settingorderingnumberingtype_desc', 'local_artqtml'),
        'none',
        [
            'none' => get_string('orderingnumberingtype_none', 'local_artqtml'),
            'abc'  => get_string('orderingnumberingtype_abc', 'local_artqtml'),
            'ABCD' => get_string('orderingnumberingtype_abcd', 'local_artqtml'),
            '123'  => get_string('orderingnumberingtype_123', 'local_artqtml'),
            'iii'  => get_string('orderingnumberingtype_iii', 'local_artqtml'),
            'IIII' => get_string('orderingnumberingtype_iiii', 'local_artqtml'),
        ]
    ));

    $ADMIN->add('local_artqtml_category', $qtypes);

    // ------------------------------------------------------------------
    // Biztonság fül (Admin-028/029).
    $security = new admin_settingpage(
        'local_artqtml_security',
        get_string('tabsecurity', 'local_artqtml'),
        'local/artqtml:configure'
    );

    if ($licensebanner !== '') {
        $security->add(new admin_setting_description('local_artqtml/licensebanner_security', '', $licensebanner));
    }
    if ($draftcoursebanner !== '') {
        $security->add(new admin_setting_description('local_artqtml/draftcoursebanner_security', '', $draftcoursebanner));
    }
    if ($tokenbanner !== '') {
        $security->add(new admin_setting_description('local_artqtml/tokenbanner_security', '', $tokenbanner));
    }

    $security->add(new admin_setting_configtextarea(
        'local_artqtml/sqlkeywords',
        get_string('settingsqlkeywords', 'local_artqtml'),
        get_string('settingsqlkeywords_desc', 'local_artqtml'),
        'SELECT,INSERT,UPDATE,DELETE,DROP,UNION'
    ));
    // The default is read from the class that enforces it, not written out again here. Until
    // 2026-08-04 this line carried its own three-phrase list, so the field looked like the whole
    // blocklist while the code matched something else - and an administrator emptying the field
    // switched prompt screening off entirely. The mandatory patterns are now always active and
    // this default only shows what they are; the field adds to them.
    $security->add(new admin_setting_configtextarea(
        'local_artqtml/promptinjectionpatterns',
        get_string('settingpromptinjectionpatterns', 'local_artqtml'),
        get_string('settingpromptinjectionpatterns_desc', 'local_artqtml'),
        implode("\n", \local_artqtml\local\security_filter::default_prompt_patterns())
    ));
    $ADMIN->add('local_artqtml_category', $security);

    // ------------------------------------------------------------------
    // Token kezelés fül (Admin-030-033).
    $tokens = new admin_settingpage(
        'local_artqtml_tokens',
        get_string('tabtokens', 'local_artqtml'),
        'local/artqtml:configure'
    );

    if ($licensebanner !== '') {
        $tokens->add(new admin_setting_description('local_artqtml/licensebanner_tokens', '', $licensebanner));
    }
    if ($draftcoursebanner !== '') {
        $tokens->add(new admin_setting_description('local_artqtml/draftcoursebanner_tokens', '', $draftcoursebanner));
    }
    if ($tokenbanner !== '') {
        $tokens->add(new admin_setting_description('local_artqtml/tokenbanner_tokens', '', $tokenbanner));
    }

    $tokens->add(new admin_setting_configtext(
        'local_artqtml/generatortokenbudget',
        get_string('settinggeneratortokenbudget', 'local_artqtml'),
        get_string('settinggeneratortokenbudget_desc', 'local_artqtml'),
        0,
        PARAM_INT
    ));
    $tokens->add(new admin_setting_configtext(
        'local_artqtml/validatortokenbudget',
        get_string('settingvalidatortokenbudget', 'local_artqtml'),
        get_string('settingvalidatortokenbudget_desc', 'local_artqtml'),
        0,
        PARAM_INT
    ));
    $tokens->add(new admin_setting_configtext(
        'local_artqtml/tokencyclestartday',
        get_string('settingtokencyclestartday', 'local_artqtml'),
        get_string('settingtokencyclestartday_desc', 'local_artqtml'),
        1,
        PARAM_INT
    ));
    $tokens->add(new \local_artqtml\admin\setting_configtext_percentage(
        'local_artqtml/tokenbudgetwarningpct',
        get_string('settingtokenbudgetwarningpct', 'local_artqtml'),
        get_string('settingtokenbudgetwarningpct_desc', 'local_artqtml'),
        80
    ));
    $tokens->add(new admin_setting_description(
        'local_artqtml/tokenusagedisplay',
        get_string('settingtokenusage', 'local_artqtml'),
        \local_artqtml\local\token_budget::render_usage_summary()
    ));

    $ADMIN->add('local_artqtml_category', $tokens);

    // ------------------------------------------------------------------
    // Licensz fül (functional spec ch.10, Lic-001-015): külön external page, nem settingpage,
    // mert a fájlfeltöltés és az aláírás-ellenőrzés egyedi form-feldolgozást igényel.
    $ADMIN->add('local_artqtml_category', new admin_externalpage(
        'local_artqtml_license',
        get_string('tablicense', 'local_artqtml'),
        new moodle_url('/local/artqtml/license.php'),
        'local/artqtml:configure'
    ));
}
