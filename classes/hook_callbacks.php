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
 * Hooks API listeners for local_artqtml (db/hooks.php).
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml;

use core\hook\after_config;
use core\hook\output\before_standard_top_of_body_html_generation;
use local_artqtml\local\plugin_setup;
use local_artqtml\local\validation_panel;

defined('MOODLE_INTERNAL') || die();

/**
 * Listeners for core output hooks.
 */
class hook_callbacks {
    /**
     * After install, send configure-capable users to plugin settings once.
     *
     * @param after_config $hook
     * @return void
     */
    public static function after_config(after_config $hook): void {
        global $CFG;

        if (\during_install()) {
            return;
        }
        if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
            return;
        }
        if (defined('WS_SERVER') && WS_SERVER) {
            return;
        }
        if (defined('AJAX_SCRIPT') && AJAX_SCRIPT) {
            return;
        }
        if (!plugin_setup::user_can_configure()) {
            return;
        }
        if (plugin_setup::is_on_plugin_settings_page()) {
            plugin_setup::acknowledge_settings_visit();
            return;
        }
        if (!plugin_setup::should_redirect_to_settings()) {
            return;
        }

        $script = basename($CFG->script ?? $_SERVER['SCRIPT_NAME'] ?? '');
        if (in_array($script, ['upgrade.php', 'upgradesettings.php', 'install.php'], true)) {
            return;
        }

        redirect(new \moodle_url('/admin/settings.php', ['section' => 'local_artqtml_general']));
    }

    /**
     * Only ever adds HTML on /question/bank/editquestion/question.php for a still-in-draft
     * question that has a matching local_artqtml_questions row. Moved questions open the core
     * editor with no plugin extras. Cheap no-op everywhere else.
     *
     * @param before_standard_top_of_body_html_generation $hook
     * @return void
     */
    public static function before_standard_top_of_body_html(before_standard_top_of_body_html_generation $hook): void {
        global $PAGE;

        if (strpos($PAGE->url->get_path(), '/question/bank/editquestion/question.php') === false) {
            return;
        }

        $questionid = optional_param('id', 0, PARAM_INT);
        if ($questionid <= 0) {
            return;
        }

        $row = validation_panel::for_questionbank_id($questionid);
        if ($row === null) {
            return;
        }

        $hook->add_html(validation_panel::render($row));
    }
}
