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
 * Admin actions for the model selector: refresh the cached model list.
 *
 * Deliberately a plain request/redirect rather than an AJAX endpoint. Both actions change server
 * State and the settings page has to re-render from the new state anyway, so a round trip is the
 * Honest mechanism - and it keeps the behaviour testable without driving JavaScript.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_artqtml\local\model_list;

require_login();

defined('MOODLE_INTERNAL') || die();

$context = context_system::instance();
require_capability('local/artqtml:configure', $context);
require_sesskey();

$provider = required_param('provider', PARAM_ALPHA);
$action = required_param('action', PARAM_ALPHA);

// Same reason as delete.php: an act-and-redirect script still needs $PAGE set up, because
// Redirect() renders a full page (theme, favicon, notification) and reads $PAGE->url, and the
// Invalid-provider exception below renders through $PAGE as well. require_login() with no course
// Argument sets neither for a system-level script. Placed before the validation deliberately, so
// The error page is covered too.
$PAGE->set_url('/local/artqtml/modelaction.php', ['provider' => $provider, 'action' => $action]);
$PAGE->set_context($context);

if (!in_array($provider, model_list::PROVIDERS, true)) {
    throw new moodle_exception('errorinvalidprovider', 'local_artqtml');
}

// Back to the tab the administrator pressed the button on.
$section = $provider === model_list::PROVIDER_CLAUDE ? 'local_artqtml_generator' : 'local_artqtml_validator';
$returnurl = new moodle_url('/admin/settings.php', ['section' => $section]);

if ($action === 'refresh') {
    // The only administrator-initiated path that may touch the provider network. The settings page itself never does.
    $result = model_list::refresh($provider);

    if ($result['success']) {
        \core\notification::success(get_string('modellistrefreshed', 'local_artqtml', count($result['models'])));
    } else {
        // A failed refresh leaves the previous cache in place, so the dropdown does not empty out
        // Under an administrator who is mid-configuration.
        \core\notification::error(get_string('modellistrefreshfailed', 'local_artqtml', $result['error']));
    }

    redirect($returnurl);
}

if ($action === 'check') {
    $result = \local_artqtml\local\model_checker::check_provider(
        $provider,
        \local_artqtml\local\model_check_log::TRIGGER_MANUAL
    );

    if ($result['success']) {
        \core\notification::success(implode(' ', $result['messages']));
    } else {
        \core\notification::error(implode(' ', $result['messages']));
    }

    redirect($returnurl);
}

throw new moodle_exception('errorinvalidaction', 'local_artqtml');
