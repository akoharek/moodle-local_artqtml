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
 * Debug-mode PHP file logging for AI request/response details (Admin-006/007).
 *
 * Deliberately separate from the local_artqtml_log DB table (Glob-010/024): that table is
 * always-on structured audit / API-traffic diagnostics; this is an optional, admin-controlled
 * plain-text trace file for troubleshooting on the hosted target, where tailing server logs
 * isn't available (see CLAUDE.md "Deployment"). Only ever writes when debug mode is enabled.
 *
 * Security audit finding #4 (2026-08-10): intentional fixed dataroot path — never accept an
 * admin-configured or free-form filesystem path (legacy config `debugfilepath` is ignored).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Appends a timestamped ArtQTML-prefixed line to the fixed dataroot debug file, if debug mode is on.
 */
class debug_logger {
    /**
     * Absolute path of the PHP debug file log under Moodle's dataroot.
     *
     * Finding #4 — intentional fixed dataroot path (not admin-configurable).
     *
     * @return string
     */
    public static function path(): string {
        global $CFG;

        return $CFG->dataroot . '/local_artqtml/debug.log';
    }

    /**
     * Append one line to the debug file, if debug mode is enabled.
     *
     * Failures to write are silently ignored - a broken/unwritable debug path must never break
     * the actual generation/validation flow it is trying to help diagnose.
     *
     * @param string $message
     * @return void
     */
    public static function log(string $message): void {
        if (empty(get_config('local_artqtml', 'debugmode'))) {
            return;
        }

        // Finding #4 — intentional fixed dataroot path; never read legacy debugfilepath config.
        $path = self::path();

        // Moodle-safe directory under dataroot (permissions via make_writable_directory).
        if (!make_upload_directory('local_artqtml', false)) {
            return;
        }

        $line = '[' . date('Y-m-d H:i:s') . '] [local_artqtml] ' . $message . PHP_EOL;

        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}
