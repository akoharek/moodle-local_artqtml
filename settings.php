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
 * Admin settings for local_artqtml: general, generator LLM, validator LLM,
 * Question-type defaults (IH/FE/SR), and security.
 *
 * Moodle's admin_settingpage API does not support in-page tabs for a single settings form,
 * So tabs are sibling pages under one admin_category.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Moodle can include a plugin's settings.php more than once per request while building/
// Caching the admin tree. require_once is safe against that, and guarantees
// Local_artqtml_render_test_button() is defined before it's used below.
require_once($CFG->dirroot . '/local/artqtml/lib.php');

// Visible to site admins and users with local/artqtml:configure (without needing moodle/site:config).
if ($hassiteconfig || has_capability('local/artqtml:configure', context_system::instance())) {
    $ADMIN->add('localplugins', new admin_category('local_artqtml_category', get_string('pluginname', 'local_artqtml')));

    $draftcoursebanner = local_artqtml_draftcourse_warning_banner();
    $apikeybanner = local_artqtml_apikey_decrypt_notice();
    $setupbanner = local_artqtml_setup_incomplete_banner();

    // ------------------------------------------------------------------
    // General.
    $general = new admin_settingpage(
        'local_artqtml_general',
        get_string('tabgeneral', 'local_artqtml'),
        'local/artqtml:configure'
    );

    if ($draftcoursebanner !== '') {
        $general->add(new admin_setting_description('local_artqtml/draftcoursebanner_general', '', $draftcoursebanner));
    }
    if ($apikeybanner !== '') {
        $general->add(new admin_setting_description('local_artqtml/apikeybanner_general', '', $apikeybanner));
    }

    if ($setupbanner !== '') {
        $general->add(new admin_setting_description('local_artqtml/setupbanner_general', '', $setupbanner));
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
    // 0 means 80% of the generator's context window (see source_text_limit).
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
    // Draft categories live in this course's context - see classes/local/draft_bank.php.
    $general->add(new \local_artqtml\admin\setting_configtext_courseid(
        'local_artqtml/draftcourseid',
        get_string('settingdraftcourseid', 'local_artqtml'),
        get_string('settingdraftcourseid_desc', 'local_artqtml')
    ));

    $ADMIN->add('local_artqtml_category', $general);

    // ------------------------------------------------------------------
    // Generator LLM (Claude).
    $generator = new admin_settingpage(
        'local_artqtml_generator',
        get_string('tabgenerator', 'local_artqtml'),
        'local/artqtml:configure'
    );

    if ($draftcoursebanner !== '') {
        $generator->add(new admin_setting_description('local_artqtml/draftcoursebanner_generator', '', $draftcoursebanner));
    }
    if ($apikeybanner !== '') {
        $generator->add(new admin_setting_description('local_artqtml/apikeybanner_generator', '', $apikeybanner));
    }
    if ($setupbanner !== '') {
        $generator->add(new admin_setting_description('local_artqtml/setupbanner_generator', '', $setupbanner));
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

    $ADMIN->add('local_artqtml_category', $generator);

    // ------------------------------------------------------------------
    // Validator LLM (Gemini).
    $validator = new admin_settingpage(
        'local_artqtml_validator',
        get_string('tabvalidator', 'local_artqtml'),
        'local/artqtml:configure'
    );

    if ($draftcoursebanner !== '') {
        $validator->add(new admin_setting_description('local_artqtml/draftcoursebanner_validator', '', $draftcoursebanner));
    }
    if ($apikeybanner !== '') {
        $validator->add(new admin_setting_description('local_artqtml/apikeybanner_validator', '', $apikeybanner));
    }
    if ($setupbanner !== '') {
        $validator->add(new admin_setting_description('local_artqtml/setupbanner_validator', '', $setupbanner));
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

    $ADMIN->add('local_artqtml_category', $validator);

    // ------------------------------------------------------------------
    // Question-type defaults — IH / FE / SR.
    $qtypes = new admin_settingpage(
        'local_artqtml_qtypes',
        get_string('tabqtypes', 'local_artqtml'),
        'local/artqtml:configure'
    );

    if ($draftcoursebanner !== '') {
        $qtypes->add(new admin_setting_description('local_artqtml/draftcoursebanner_qtypes', '', $draftcoursebanner));
    }
    if ($apikeybanner !== '') {
        $qtypes->add(new admin_setting_description('local_artqtml/apikeybanner_qtypes', '', $apikeybanner));
    }

    foreach (['IH', 'FE', 'SR'] as $code) {
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
        $qtypes->add(new admin_setting_configtextarea(
            'local_artqtml/feedback_' . $lowercode . '_incorrect',
            get_string('settingfeedbackincorrect', 'local_artqtml'),
            '',
            ''
        ));
    }

    $qtypes->add(new admin_setting_configcheckbox(
        'local_artqtml/retrydefault',
        get_string('settingretrydefault', 'local_artqtml'),
        get_string('settingretrydefault_desc', 'local_artqtml'),
        0
    ));
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

    // Local copies of qtype_ordering option sets — avoid loading the qtype class from settings.php.
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
    // Security.
    $security = new admin_settingpage(
        'local_artqtml_security',
        get_string('tabsecurity', 'local_artqtml'),
        'local/artqtml:configure'
    );

    if ($draftcoursebanner !== '') {
        $security->add(new admin_setting_description('local_artqtml/draftcoursebanner_security', '', $draftcoursebanner));
    }
    if ($apikeybanner !== '') {
        $security->add(new admin_setting_description('local_artqtml/apikeybanner_security', '', $apikeybanner));
    }

    $security->add(new admin_setting_configtextarea(
        'local_artqtml/sqlkeywords',
        get_string('settingsqlkeywords', 'local_artqtml'),
        get_string('settingsqlkeywords_desc', 'local_artqtml'),
        'SELECT,INSERT,UPDATE,DELETE,DROP,UNION'
    ));
    $security->add(new admin_setting_configtextarea(
        'local_artqtml/promptinjectionpatterns',
        get_string('settingpromptinjectionpatterns', 'local_artqtml'),
        get_string('settingpromptinjectionpatterns_desc', 'local_artqtml'),
        implode("\n", \local_artqtml\local\security_filter::default_prompt_patterns())
    ));
    $ADMIN->add('local_artqtml_category', $security);
}
