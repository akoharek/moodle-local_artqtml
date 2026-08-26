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
 * First-run admin setup: mandatory plugin settings before teachers can generate.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Which mandatory admin settings are still missing after install.
 */
class plugin_setup {
    /** @var string draft course id setting. */
    public const ITEM_DRAFTCOURSE = 'draftcourse';

    /** @var string Claude API key setting. */
    public const ITEM_CLAUDEKEY = 'claudeapikey';

    /** @var string Gemini API key setting. */
    public const ITEM_GEMINIKEY = 'geminiapikey';

    /** @var string Claude model setting. */
    public const ITEM_CLAUDEMODEL = 'claudemodel';

    /** @var string Gemini model setting. */
    public const ITEM_GEMINIMODEL = 'geminimodel';

    /** @var string config flag: redirect configure-capable users to settings once after install. */
    public const POSTINSTALL_REDIRECT_KEY = 'postinstallredirect';

    /**
     * Whether every mandatory admin setting is in place.
     *
     * @return bool
     */
    public static function is_complete(): bool {
        return self::missing() === [];
    }

    /**
     * Machine ids of settings still required (empty = ready for generation).
     *
     * @return string[]
     */
    public static function missing(): array {
        $missing = [];

        if (!draft_bank::is_configured()) {
            $missing[] = self::ITEM_DRAFTCOURSE;
        }
        if (api_key_store::get(model_list::PROVIDER_CLAUDE) === '') {
            $missing[] = self::ITEM_CLAUDEKEY;
        }
        if (api_key_store::get(model_list::PROVIDER_GEMINI) === '') {
            $missing[] = self::ITEM_GEMINIKEY;
        }
        if ((string) get_config('local_artqtml', 'claudemodel') === '') {
            $missing[] = self::ITEM_CLAUDEMODEL;
        }
        if ((string) get_config('local_artqtml', 'geminimodel') === '') {
            $missing[] = self::ITEM_GEMINIMODEL;
        }

        return $missing;
    }

    /**
     * Localised labels for {@see self::missing()} items (for setup banners).
     *
     * @return string[]
     */
    public static function missing_labels(): array {
        $labels = [];
        foreach (self::missing() as $item) {
            $labels[] = get_string('setupmissing_' . $item, 'local_artqtml');
        }
        return $labels;
    }

    /**
     * Whether this request should redirect a configure-capable user to plugin settings.
     *
     * @return bool
     */
    public static function should_redirect_to_settings(): bool {
        if (!get_config('local_artqtml', self::POSTINSTALL_REDIRECT_KEY)) {
            return false;
        }

        if (self::is_on_plugin_settings_page()) {
            return false;
        }

        return self::user_can_configure();
    }

    /**
     * Clear the one-shot post-install redirect flag and, when complete, leave it cleared.
     *
     * @return void
     */
    public static function acknowledge_settings_visit(): void {
        unset_config(self::POSTINSTALL_REDIRECT_KEY, 'local_artqtml');
    }

    /**
     * Mark that a fresh install should send configure-capable users to settings once.
     *
     * @return void
     */
    public static function flag_post_install_redirect(): void {
        set_config(self::POSTINSTALL_REDIRECT_KEY, 1, 'local_artqtml');
    }

    /**
     * Whether the current user may open plugin admin settings.
     *
     * @return bool
     */
    public static function user_can_configure(): bool {
        if (!isloggedin()) {
            return false;
        }

        $context = \context_system::instance();
        return has_capability('local/artqtml:configure', $context)
            || has_capability('moodle/site:config', $context);
    }

    /**
     * Whether the current HTTP request is already on a local_artqtml admin settings tab.
     *
     * @return bool
     */
    public static function is_on_plugin_settings_page(): bool {
        if (empty($_SERVER['SCRIPT_NAME']) || basename($_SERVER['SCRIPT_NAME']) !== 'settings.php') {
            return false;
        }

        $section = optional_param('section', '', PARAM_SAFEPATH);
        return $section !== '' && str_starts_with($section, 'local_artqtml');
    }
}
