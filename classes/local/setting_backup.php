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
 * Migration backup for admin-editable settings.
 *
 * The reason this exists at all: the validator prompt template is the administrator's own work - an
 * Evaluation instruction tuned to their subject area - and a plugin upgrade must not destroy it
 * Irrecoverably. It has already happened twice (the problem_category migration and the SUGGESTIONS
 * One, upgrade step 2026072600), both of which rewrote the stored template in place with no backup.
 * Those steps have already run and cannot be fixed retroactively; this rule binds every migration
 * Written from here on.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Stores the previous value of a setting before a migration overwrites it.
 */
class setting_backup {
    /** @var string config key holding the list of backups made, for the post-upgrade notice. */
    public const NOTICE_KEY = 'settingbackupnotices';

    /**
     * Back up a setting's current value before a migration changes it.
     *
     * Call this BEFORE writing the new value. Returns the key the previous value was stored under,
     * Or null if there was nothing to back up.
     *
     * @param string $setting the plugin setting name, e.g. 'validatorprompttemplate'
     * @param int $version the plugin version of the migration doing the change
     * @param bool $encrypted whether the setting is stored encrypted - the backup then is too
     * @return string|null the backup config key, or null if the setting had no value
     */
    public static function backup(string $setting, int $version, bool $encrypted = false): ?string {
        $current = get_config('local_artqtml', $setting);
        if ($current === false || $current === '') {
            // Nothing to lose, so nothing to back up - and no notice, which would only be noise.
            return null;
        }

        $key = self::backup_key($setting, $version);

        // Spec, "A mentés nem íródik felül": if this key already exists (a re-run of the same upgrade
        // Step, or a restored database) the earlier backup is the more original value and wins. A
        // Suffixed key is used rather than clobbering it.
        if (get_config('local_artqtml', $key) !== false) {
            $suffix = 2;
            while (get_config('local_artqtml', $key . '_' . $suffix) !== false) {
                $suffix++;
            }
            $key = $key . '_' . $suffix;
        }

        $store = $current;
        if ($encrypted) {
            // The original is stored encrypted, so the backup is too - a backup that downgrades a
            // Secret to plaintext would be worse than no backup.
            try {
                $store = \core\encryption::encrypt((string) $current);
            } catch (\Throwable $e) {
                // Encryption unavailable: the original could not have been encrypted either, so
                // The value is already plaintext and is stored as-is rather than lost.
                $store = $current;
            }
        }

        set_config($key, $store, 'local_artqtml');
        self::add_notice($setting, $key);

        return $key;
    }

    /**
     * The config key a backup is stored under: <setting>_backup_<version>.
     *
     * @param string $setting
     * @param int $version
     * @return string
     */
    public static function backup_key(string $setting, int $version): string {
        return $setting . '_backup_' . $version;
    }

    /**
     * Record that a setting was backed up, for the post-upgrade administrator notice.
     *
     * @param string $setting
     * @param string $key
     * @return void
     */
    protected static function add_notice(string $setting, string $key): void {
        $notices = self::pending_notices();
        $notices[$setting] = $key;
        set_config(self::NOTICE_KEY, json_encode($notices), 'local_artqtml');
    }

    /**
     * The backups made by upgrades that the administrator has not yet been shown.
     *
     * @return array<string, string> setting name => backup config key
     */
    public static function pending_notices(): array {
        $raw = get_config('local_artqtml', self::NOTICE_KEY);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Clear the pending notices once they have been displayed.
     *
     * @return void
     */
    public static function clear_notices(): void {
        unset_config(self::NOTICE_KEY, 'local_artqtml');
    }

    /**
     * The notice text telling the administrator and where the old value is.
     *
     * @return string[] one rendered message per backed-up setting
     */
    public static function notice_messages(): array {
        $messages = [];
        foreach (self::pending_notices() as $setting => $key) {
            $messages[] = get_string('settingbackednotice', 'local_artqtml', (object) [
                'setting' => $setting,
                'key'     => $key,
            ]);
        }

        return $messages;
    }
}
