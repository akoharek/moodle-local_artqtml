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
 * Plain text field for Claude/Gemini API keys, encrypted at rest via \core\encryption.
 *
 * Shown as a normal text input (not password-unmask): the Moodle passwordunmask widget hid the
 * real input behind an edit link, so pasting a new key and saving often left the previous value.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\admin;

use local_artqtml\local\encrypted_config;

/**
 * Encrypts on write and decrypts on read. The admin form shows plaintext; config_plugins holds
 * ciphertext only.
 *
 * Leftover plaintext is re-encrypted in place on read. Unreadable ciphertext shows empty and a
 * one-time hint asks the administrator to paste the key again.
 */
class setting_encryptedapikey extends \admin_setting_configtext {
    /** @var bool whether a decrypt failure hint was already prepended to the description */
    private bool $decryptfailhintshown = false;

    /**
     * Constructor.
     *
     * @param string $name unique ascii name
     * @param string $visiblename localised name
     * @param string $description localised long description
     * @param mixed $defaultsetting
     */
    public function __construct($name, $visiblename, $description, $defaultsetting) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_RAW, 80);
    }

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
     * Encrypt and store the submitted value.
     *
     * @param string $data
     * @return string empty string on success, an error message otherwise
     */
    public function write_setting($data) {
        $data = trim((string) $data);
        if ($data === '') {
            // Leave the stored ciphertext (or leftover plaintext) unchanged. An empty POST is
            // how the field submits when the administrator does not retype the key.
            return '';
        }

        try {
            $encrypted = \core\encryption::encrypt($data);
        } catch (\Throwable $e) {
            return get_string('errorsetting', 'admin');
        }

        encrypted_config::clear_failure($this->name);
        if (!$this->config_write($this->name, $encrypted)) {
            return get_string('errorsetting', 'admin');
        }

        \local_artqtml\local\api_key_backup::backup_setting($this->name, $encrypted);

        return '';
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
