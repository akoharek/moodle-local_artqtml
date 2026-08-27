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
 * Password field for the Claude/Gemini API keys, encrypted at rest via \core\encryption.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\admin;

use local_artqtml\local\encrypted_config;

/**
 * Transparently encrypts on write and decrypts on read, so the admin form/unmask toggle still
 * shows the real plaintext key, while `config_plugins` only ever holds ciphertext.
 *
 * Leftover plaintext (no Moodle encryption prefix) is re-encrypted in place on read so an
 * upgrade that introduced encryption does not drop the key. Ciphertext that fails integrity
 * cannot be recovered: the field shows empty (never the raw ciphertext) and a one-time admin
 * notice asks for the key to be re-entered from the provider dashboard.
 *
 * An empty submitted value does not overwrite config: password fields POST empty when the
 * administrator does not retype the key, including after a decrypt-failure display.
 */
class setting_encryptedapikey extends \admin_setting_configpasswordunmask {
    /** @var bool whether a decrypt failure hint was already prepended to the description */
    private bool $decryptfailhintshown = false;

    /**
     * Return the decrypted setting value for display.
     *
     * @return mixed
     */
    public function get_setting() {
        $stored = $this->config_read($this->name);
        if ($stored === null || $stored === '') {
            return $stored;
        }

        $plain = encrypted_config::get($this->name);
        if ($plain === '' && in_array($this->name, encrypted_config::failed_names(), true)) {
            $this->prepend_decrypt_hint();
        }
        return $plain;
    }

    /**
     * Encrypt and store the submitted value. Empty input leaves the stored value unchanged.
     *
     * @param string $data
     * @return string empty string on success, an error message otherwise
     */
    public function write_setting($data) {
        $data = trim((string) $data);
        if ($data === '') {
            // Leave the stored ciphertext (or leftover plaintext) unchanged. An empty POST is
            // how password fields submit when the administrator does not retype the key.
            return '';
        }

        try {
            $encrypted = \core\encryption::encrypt($data);
        } catch (\Throwable $e) {
            return get_string('errorsetting', 'admin');
        }

        encrypted_config::clear_failure($this->name);
        return ($this->config_write($this->name, $encrypted) ? '' : get_string('errorsetting', 'admin'));
    }

    /**
     * Require a key on first save; empty resubmit is allowed when a value is already stored.
     *
     * @param string $data
     * @return true|string
     */
    public function validate($data) {
        $data = trim((string) $data);
        if ($data !== '') {
            return true;
        }

        $stored = $this->config_read($this->name);
        if ($stored === null || $stored === '') {
            return get_string('errorapikeyrequired', 'local_artqtml');
        }

        return true;
    }

    /**
     * Prepend the field-level "re-enter the key" hint once per instance.
     *
     * @return void
     */
    private function prepend_decrypt_hint(): void {
        if ($this->decryptfailhintshown) {
            return;
        }
        $this->description = get_string('apikeymustresave', 'local_artqtml') .
            '<br>' . $this->description;
        $this->decryptfailhintshown = true;
    }
}
