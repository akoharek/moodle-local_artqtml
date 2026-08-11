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
 * Reads the Claude/Gemini API key, transparently decrypting it (Admin-010/016).
 *
 * `get_config()` reads `config_plugins` directly, bypassing
 * {@see \local_artqtml\admin\setting_encryptedapikey}'s own decrypt-on-display logic - every
 * place that actually needs to use the key for a real API call (not just display it in the
 * settings form) must go through this helper instead of a plain `get_config()` call.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Decrypts the stored Claude/Gemini API key for actual use.
 */
class api_key_store {
    /**
     * Get the decrypted API key for one provider.
     *
     * Decrypt failure is treated as a missing key (empty string). The stored ciphertext is never
     * returned for API calls — admins must re-save the key in plugin settings.
     *
     * @param string $provider 'claude' or 'gemini'
     * @return string empty string if none configured or decrypt fails
     */
    public static function get(string $provider): string {
        $configname = $provider === 'claude' ? 'claudeapikey' : 'geminiapikey';
        $stored = (string) get_config('local_artqtml', $configname);
        if ($stored === '') {
            return '';
        }

        try {
            return \core\encryption::decrypt($stored);
        } catch (\Throwable $e) {
            // Corrupt / wrong-key ciphertext must not be sent as a "plaintext" API key.
            debugging(
                'local_artqtml: failed to decrypt ' . $configname .
                    '; treating as unset. Re-save the API key in plugin settings. ' . $e->getMessage(),
                DEBUG_NORMAL
            );
            return '';
        }
    }
}
