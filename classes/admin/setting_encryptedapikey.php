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
 * Helper.
 *
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\admin;

/**
 * Transparently encrypts on write and decrypts on read, so the admin form/unmask toggle still
 * Shows the real plaintext key, while `config_plugins` only ever holds ciphertext.
 *
 * A value that fails to decrypt is treated as missing: the field shows empty (never the raw
 * Ciphertext) and runtime readers via {@see \local_artqtml\local\api_key_store} also get an empty
 * Key until an administrator re-saves a valid key.
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

        try {
            return \core\encryption::decrypt($stored);
        } catch (\Throwable $e) {
            // Do not echo corrupted ciphertext into the password field.
            debugging(
                'local_artqtml: cannot decrypt admin setting ' . $this->name .
                    '; showing empty — re-save the API key. ' . $e->getMessage(),
                DEBUG_NORMAL
            );
            if (!$this->decryptfailhintshown) {
                $this->description = get_string('apikeymustresave', 'local_artqtml') .
                    '<br>' . $this->description;
                $this->decryptfailhintshown = true;
            }
            return '';
        }
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
            return ($this->config_write($this->name, '') ? '' : get_string('errorsetting', 'admin'));
        }

        $encrypted = \core\encryption::encrypt($data);
        return ($this->config_write($this->name, $encrypted) ? '' : get_string('errorsetting', 'admin'));
    }
}
