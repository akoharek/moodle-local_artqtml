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
 * Library functions and callbacks for local_artqtml.
 *
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add a link to the ArtQTML into the primary/global navigation.
 *
 * @param global_navigation $navigation the global navigation tree
 * @return void
 */
function local_artqtml_extend_navigation(global_navigation $navigation) {
    if (!get_config('local_artqtml', 'enabled')) {
        return;
    }

    $context = context_system::instance();
    if (!has_capability('local/artqtml:use', $context)) {
        return;
    }

    $url = new moodle_url('/local/artqtml/index.php');
    $node = navigation_node::create(
        get_string('navigationlink', 'local_artqtml'),
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_artqtml',
        new pix_icon('i/settings', '')
    );

    $navigation->add_node($node);
}

/**
 * Map a generation status to a Bootstrap badge CSS class.
 *
 * Thin wrapper kept for the existing call sites; the map itself lives with the status values in
 * {@see \local_artqtml\local\generation_status} so the six-value set has one home.
 *
 * @param string $status one of \local_artqtml\local\generation_status::VALUES
 * @return string badge CSS class
 */
function local_artqtml_status_badge_class(string $status): string {
    return \local_artqtml\local\generation_status::badge_class($status);
}

/**
 * Map a question validationsuggestion to a Bootstrap badge CSS class.
 *
 * @param string $status one of \local_artqtml\local\validation_suggestion::DISPLAY, or 'edited'
 * @return string badge CSS class
 */
function local_artqtml_validation_badge_class(string $status): string {
    return \local_artqtml\local\validation_suggestion::badge_class($status);
}

/**
 * Deliberately kept out of settings.php: Moodle can include a plugin's settings.php more
 * Than once per request while building/caching the admin tree, which would fatal-error on
 * A plain top-level function redeclaration. lib.php is loaded via include_once through the
 * Component callback mechanism, so it does not have that problem.
 *
 * @param string $provider 'claude' or 'gemini'
 * @return string
 */
function local_artqtml_render_test_button(string $provider): string {
    global $PAGE;

    $PAGE->requires->js(new moodle_url('/local/artqtml/js/admintest.js'));

    static $stringsemitted = false;
    if (!$stringsemitted) {
        $PAGE->requires->data_for_js('M.artqtml_admintest', [
            'testing'      => get_string('admintesttesting', 'local_artqtml'),
            'errorunknown' => get_string('errorajaxunknown', 'local_artqtml'),
        ]);
        $stringsemitted = true;
    }

    $buttonid = 'artqtml-test-' . $provider;
    $statusid = 'artqtml-teststatus-' . $provider;

    $html = html_writer::tag('button', get_string('testconnectionbutton', 'local_artqtml'), [
        'type' => 'button', 'id' => $buttonid, 'class' => 'btn btn-secondary',
        'data-testid' => 'artqtml-admin-connectiontest-' . $provider,
    ]);
    $html .= html_writer::tag('span', '', ['id' => $statusid, 'class' => 'ml-2']);
    $html .= html_writer::script(
        'document.addEventListener("DOMContentLoaded", function() {' .
        'if (window.ArtqtmlAdminTest) {' .
        'window.ArtqtmlAdminTest.init(' . json_encode($provider) . ', ' . json_encode($buttonid) . ', ' .
        json_encode($statusid) . ');' .
        '}});'
    );

    return $html;
}

/**
 * Render the model-list actions for one LLM tab: "Refresh models".
 *
 * Plain links with a sesskey rather than AJAX: the actions change server state and the page must
 * Re-render from the refreshed cache anyway, so a round trip is the honest mechanism and needs no
 * JavaScript to be testable.
 *
 * @param string $provider one of \local_artqtml\local\model_list::PROVIDERS
 * @return string
 */
function local_artqtml_render_model_buttons(string $provider): string {
    $refreshurl = new moodle_url('/local/artqtml/modelaction.php', [
        'provider' => $provider,
        'action'   => 'refresh',
        'sesskey'  => sesskey(),
    ]);

    $html = html_writer::link($refreshurl, get_string('refreshmodels', 'local_artqtml'), [
        'class' => 'btn btn-secondary',
        'data-testid' => 'artqtml-admin-refreshmodels-' . $provider,
    ]);

    $checkurl = new moodle_url('/local/artqtml/modelaction.php', [
        'provider' => $provider,
        'action'   => 'check',
        'sesskey'  => sesskey(),
    ]);
    $html .= html_writer::link($checkurl, get_string('runmodelcheck', 'local_artqtml'), [
        'class' => 'btn btn-secondary ml-2',
        'data-testid' => 'artqtml-admin-runmodelcheck-' . $provider,
    ]);

    // Say how old the cached list is, so "the dropdown looks wrong" has an obvious first thing to check.
    $cached = \local_artqtml\local\model_list::get_cached($provider);
    if ($cached !== null) {
        $html .= html_writer::span(
            get_string(
                'modellistfetched',
                'local_artqtml',
                userdate((int) $cached['fetchedat'], get_string('datetimeformat', 'local_artqtml'))
            ),
            'ml-2 text-muted small',
            ['data-testid' => 'artqtml-admin-modellistage-' . $provider]
        );
    }

    return html_writer::div($html, 'artqtml-modelactions');
}

/**
 * local artqtml owner warning banner.
 *
 * @param stdClass $generation the local_artqtml_generations record being viewed
 * @return string empty string if the current user is the generation's own owner
 */
function local_artqtml_owner_warning_banner(stdClass $generation): string {
    global $USER;

    if ($generation->userid == $USER->id) {
        return '';
    }

    $owner = core_user::get_user($generation->userid);
    if (!$owner) {
        return '';
    }

    return html_writer::div(
        get_string('crossuserwarning', 'local_artqtml', fullname($owner)),
        'alert alert-warning'
    );
}

/**
 * local artqtml draftcourse warning banner.
 *
 * @return string empty string if the draft course is configured and exists
 */
function local_artqtml_draftcourse_warning_banner(): string {
    if (\local_artqtml\local\draft_bank::is_configured()) {
        return '';
    }

    return html_writer::div(get_string('draftcoursewarningbanner', 'local_artqtml'), 'alert alert-danger');
}

/**
 * Shown once - reading it clears the pending notices - and only to users who could act on it.
 *
 * @return string HTML, or '' when there is nothing pending
 */
function local_artqtml_setting_backup_notice(): string {
    if (!has_capability('local/artqtml:configure', context_system::instance())) {
        return '';
    }

    $messages = \local_artqtml\local\setting_backup::notice_messages();
    if (empty($messages)) {
        return '';
    }

    \local_artqtml\local\setting_backup::clear_notices();

    $items = '';
    foreach ($messages as $message) {
        $items .= html_writer::tag('li', s($message));
    }

    return html_writer::div(
        html_writer::tag('ul', $items, ['class' => 'mb-0']),
        'alert alert-info',
        ['data-testid' => 'artqtml-settingbackup-notice']
    );
}

/**
 * Persistent notice when stored API keys cannot be decrypted (site encryption key mismatch).
 *
 * Unlike the setting-backup notice this is not cleared on display: it stays until the
 * administrator re-saves a valid key. Shown only to users who can configure the plugin.
 *
 * @return string HTML, or '' when every stored key is readable or empty
 */
function local_artqtml_apikey_decrypt_notice(): string {
    if (!has_capability('local/artqtml:configure', context_system::instance())) {
        return '';
    }

    if (empty(\local_artqtml\local\encrypted_config::failed_names())) {
        return '';
    }

    return html_writer::div(
        get_string('apikeyupgradeunrecoverable', 'local_artqtml'),
        'alert alert-danger',
        ['data-testid' => 'artqtml-apikey-decrypt-notice']
    );
}

/**
 * The model blocking warning bar, shown on every plugin surface.
 *
 * Returns '' when neither provider is blocked, so callers can render it unconditionally.
 *
 * @return string
 */
function local_artqtml_model_warning_banner(): string {
    $messages = \local_artqtml\local\model_blocking::messages();
    if (empty($messages)) {
        return '';
    }

    $items = '';
    foreach ($messages as $message) {
        $items .= html_writer::tag('li', s($message));
    }

    return html_writer::div(
        html_writer::tag('ul', $items, ['class' => 'mb-0']),
        'alert alert-danger',
        ['data-testid' => 'artqtml-modelblocked-banner']
    );
}
