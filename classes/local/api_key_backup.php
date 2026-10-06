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
 * Dataroot backup for dev-critical plugin settings stored in config_plugins.
 *
 * Docker dev often resets MariaDB while moodledata survives (see moodle-docker/local.yml).
 * config_plugins rows then disappear, but the site encryption secret in moodledata is still
 * valid, so ciphertext copied back from this file decrypts normally. Plain settings (model
 * ids, draft course) are stored in the same file under "settings".
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Writes encrypted API-key and plain admin config rows to moodledata and restores when missing.
 */
class api_key_backup {
    /** @var string file name under $CFG->dataroot/local_artqtml/ */
    public const FILENAME = 'apikeys.json';

    /** @var int schema version inside the JSON file */
    public const FILE_VERSION = 2;

    /** @var string[] plain config_plugins names to mirror in moodledata (not encrypted). */
    public const PLAIN_SETTING_NAMES = ['claudemodel', 'geminimodel', 'draftcourseid'];

    /**
     * Absolute path to the backup file.
     *
     * @return string
     */
    public static function path(): string {
        global $CFG;

        return $CFG->dataroot . '/local_artqtml/' . self::FILENAME;
    }

    /**
     * Persist one setting's stored value (ciphertext or legacy plaintext) to moodledata.
     *
     * @param string $name one of encrypted_config::SETTING_NAMES
     * @param string $storedvalue value as held in config_plugins (not decrypted)
     * @return void
     */
    public static function backup_setting(string $name, string $storedvalue): void {
        if (!in_array($name, encrypted_config::SETTING_NAMES, true)) {
            return;
        }
        if ($storedvalue === '') {
            return;
        }

        $payload = self::read_file();
        $payload['keys'][$name] = $storedvalue;
        self::stamp_payload($payload);
        self::write_file($payload);
    }

    /**
     * Persist one plain (non-encrypted) setting to moodledata.
     *
     * @param string $name one of self::PLAIN_SETTING_NAMES
     * @param string $value value as held in config_plugins
     * @return void
     */
    public static function backup_plain_setting(string $name, string $value): void {
        if (!in_array($name, self::PLAIN_SETTING_NAMES, true)) {
            return;
        }
        if ($value === '') {
            return;
        }

        $payload = self::read_file();
        $payload['settings'][$name] = $value;
        self::stamp_payload($payload);
        self::write_file($payload);
    }

    /**
     * Copy current config_plugins values for all backed-up settings into moodledata.
     *
     * @return void
     */
    public static function backup_all_from_config(): void {
        foreach (encrypted_config::SETTING_NAMES as $name) {
            $stored = get_config('local_artqtml', $name);
            if ($stored !== false && $stored !== '') {
                self::backup_setting($name, (string) $stored);
            }
        }
        foreach (self::PLAIN_SETTING_NAMES as $name) {
            $stored = get_config('local_artqtml', $name);
            if ($stored !== false && $stored !== '') {
                self::backup_plain_setting($name, (string) $stored);
            }
        }
    }

    /**
     * Copy backed-up values into config_plugins when a key row is missing or empty.
     *
     * Does not overwrite non-empty config. Returns names that were restored.
     *
     * @return string[]
     */
    public static function restore_missing(): array {
        $payload = self::read_file();

        $restored = [];
        foreach (encrypted_config::SETTING_NAMES as $name) {
            $current = get_config('local_artqtml', $name);
            if ($current !== false && $current !== '') {
                continue;
            }

            if (!array_key_exists($name, $payload['keys'])) {
                continue;
            }

            $stored = (string) $payload['keys'][$name];
            if ($stored === '') {
                continue;
            }

            set_config($name, $stored, 'local_artqtml');
            $restored[] = $name;
        }

        foreach (self::PLAIN_SETTING_NAMES as $name) {
            $current = get_config('local_artqtml', $name);
            if ($current !== false && $current !== '') {
                continue;
            }

            if (!array_key_exists($name, $payload['settings'])) {
                continue;
            }

            $stored = (string) $payload['settings'][$name];
            if ($stored === '') {
                continue;
            }

            set_config($name, $stored, 'local_artqtml');
            $restored[] = $name;
        }

        return $restored;
    }

    /**
     * Read and decode the backup file.
     *
     * Always returns normalised keys/settings maps so callers need no shape guards.
     *
     * @return array Always includes keys and settings string maps (may be empty).
     */
    protected static function read_file(): array {
        $empty = ['keys' => [], 'settings' => []];

        $path = self::path();
        if (!is_readable($path)) {
            return $empty;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $empty;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            debugging('local_artqtml: unreadable API key backup at ' . $path, DEBUG_NORMAL);
            return $empty;
        }

        if (!isset($decoded['keys']) || !is_array($decoded['keys'])) {
            $decoded['keys'] = [];
        }
        if (!isset($decoded['settings']) || !is_array($decoded['settings'])) {
            $decoded['settings'] = [];
        }

        return $decoded;
    }

    /**
     * Update version and timestamp on a payload about to be written.
     *
     * @param array $payload Backup payload (keys/settings maps); modified in place.
     * @return void
     */
    protected static function stamp_payload(array &$payload): void {
        $payload['version'] = self::FILE_VERSION;
        $payload['backedat'] = time();
    }

    /**
     * Write the backup file with restrictive permissions.
     *
     * @param array $payload Backup payload with version, backedat, keys, and settings.
     * @return void
     */
    protected static function write_file(array $payload): void {
        global $CFG;

        $dir = $CFG->dataroot . '/local_artqtml';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, $CFG->directorypermissions, true) && !is_dir($dir)) {
                debugging('local_artqtml: could not create ' . $dir . ' for API key backup', DEBUG_NORMAL);
                return;
            }
        }

        $path = self::path();
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            debugging('local_artqtml: could not encode API key backup', DEBUG_NORMAL);
            return;
        }

        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            debugging('local_artqtml: could not write API key backup to ' . $path, DEBUG_NORMAL);
            return;
        }

        @chmod($path, $CFG->filepermissions);
    }
}
