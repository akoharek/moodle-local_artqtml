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
 * Read/write helpers for admin settings stored via \core\encryption.
 *
 * Moodle has no is_encrypted() API: ciphertext is `sodium:` or `openssl-aes-256-ctr:` plus
 * payload. A leftover plaintext key (no prefix) is re-encrypted in place so an upgrade that
 * started encrypting settings does not drop the value. Ciphertext that fails integrity cannot
 * be recovered — not by this plugin and not by Moodle — and must be re-entered.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Decrypts encrypted plugin config, migrating pre-encryption plaintext.
 */
class encrypted_config {
    /** @var string[] setting names stored encrypted in config_plugins */
    public const SETTING_NAMES = ['claudeapikey', 'geminiapikey'];

    /** @var string config key: JSON object of setting name => 1 for unrecoverable ciphertext */
    public const FAILED_KEY = 'apikeydecryptfailed';

    /**
     * Whether the value is already in Moodle's encryption envelope (not leftover plaintext).
     *
     * Matches the prefix check in {@see \core\encryption::decrypt()}.
     *
     * @param string $value
     * @return bool
     */
    public static function is_encrypted_value(string $value): bool {
        // String prefixes: METHOD_OPENSSL was removed in Moodle 5.1, but leftover
        // openssl-aes-256-ctr: rows can still exist. Do not reference the constant.
        return str_starts_with($value, 'sodium:')
            || str_starts_with($value, 'openssl-aes-256-ctr:');
    }

    /**
     * Decrypt a stored API key, re-encrypting leftover plaintext in place.
     *
     * Unreadable ciphertext is left untouched and treated as missing (empty string).
     *
     * @param string $name plugin setting name, e.g. 'claudeapikey'
     * @return string plaintext, or '' if unset / unrecoverable
     */
    public static function get(string $name): string {
        $stored = get_config(self::component(), $name);
        if ($stored === false || $stored === '') {
            return '';
        }

        $stored = (string) $stored;
        if (!self::is_encrypted_value($stored)) {
            self::encrypt_in_place($name, $stored);
            return $stored;
        }

        try {
            return \core\encryption::decrypt($stored);
        } catch (\Throwable $e) {
            self::record_unrecoverable($name, $e);
            return '';
        }
    }

    /**
     * Upgrade step: encrypt leftover plaintext keys; record unrecoverable ciphertext.
     *
     * @return void
     */
    public static function migrate_plaintext_on_upgrade(): void {
        foreach (self::SETTING_NAMES as $name) {
            $stored = get_config(self::component(), $name);
            if ($stored === false || $stored === '') {
                continue;
            }

            $stored = (string) $stored;
            if (!self::is_encrypted_value($stored)) {
                self::encrypt_in_place($name, $stored);
                continue;
            }

            try {
                \core\encryption::decrypt($stored);
            } catch (\Throwable $e) {
                self::record_unrecoverable($name, $e);
            }
        }
    }

    /**
     * Setting names whose stored ciphertext cannot be decrypted with the current site key.
     *
     * @return string[]
     */
    public static function failed_names(): array {
        $raw = get_config(self::component(), self::FAILED_KEY);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_keys(array_filter($decoded));
    }

    /**
     * Drop the unrecoverable flag for one setting after a successful re-save.
     *
     * @param string $name
     * @return void
     */
    public static function clear_failure(string $name): void {
        $raw = get_config(self::component(), self::FAILED_KEY);
        if ($raw === false || $raw === '') {
            return;
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || !isset($decoded[$name])) {
            return;
        }

        unset($decoded[$name]);
        if (empty($decoded)) {
            unset_config(self::FAILED_KEY, self::component());
            return;
        }

        set_config(self::FAILED_KEY, json_encode($decoded), self::component());
    }

    /**
     * Re-encrypt leftover plaintext. On encrypt failure the plaintext row is left as-is.
     *
     * @param string $name
     * @param string $plaintext
     * @return void
     */
    protected static function encrypt_in_place(string $name, string $plaintext): void {
        try {
            set_config($name, \core\encryption::encrypt($plaintext), self::component());
        } catch (\Throwable $e) {
            debugging(
                self::component() . ': could not re-encrypt leftover plaintext ' . $name .
                    '; leaving the stored value unchanged. ' . $e->getMessage(),
                DEBUG_NORMAL
            );
        }
    }

    /**
     * Remember unreadable ciphertext once: one debugging() line and one admin notification.
     *
     * @param string $name
     * @param \Throwable $e
     * @return void
     */
    protected static function record_unrecoverable(string $name, \Throwable $e): void {
        $raw = get_config(self::component(), self::FAILED_KEY);
        $failed = [];
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                $failed = $decoded;
            }
        }

        $already = isset($failed[$name]);
        $failed[$name] = 1;
        set_config(self::FAILED_KEY, json_encode($failed), self::component());

        if ($already) {
            return;
        }

        debugging(
            self::component() . ': stored API key ' . $name .
                ' cannot be decrypted (site encryption key mismatch or corrupt ciphertext). ' .
                'Re-enter it from the provider dashboard; the previous value cannot be recovered. ' .
                $e->getMessage(),
            DEBUG_NORMAL
        );

        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
            \core\notification::add(
                get_string('apikeyupgradeunrecoverable', self::component()),
                \core\output\notification::NOTIFY_ERROR
            );
        }
    }

    /**
     * Frankenstyle component for config_plugins.
     *
     * @return string
     */
    protected static function component(): string {
        return 'local_artqtml';
    }
}
