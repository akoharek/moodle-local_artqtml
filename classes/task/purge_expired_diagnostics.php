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
 * Removes the raw diagnostic payload from log entries past their retention period.
 *
 * The log rows themselves are never deleted - Glob-040 keeps them, and this task does not argue
 * with that. What it removes is the system prompt, the response schema and the raw provider
 * response, which are the parts that are large, can carry a teacher's material, and stop being
 * useful long before they stop being sensitive.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\task;

use local_artqtml\local\diagnostic_log_retention;

/**
 * Daily sweep over expired diagnostic payloads.
 */
class purge_expired_diagnostics extends \core\task\scheduled_task {
    /**
     * The task's display name in the scheduled task administration screen.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskpurgeexpireddiagnostics', 'local_artqtml');
    }

    /**
     * Redact every diagnostic payload past the configured retention period.
     *
     * The output is a count and nothing else. A task whose job is removing sensitive payloads
     * must not print them, or a fraction of them, on its way past - and cron output is written to
     * a file and often mailed.
     *
     * @return void
     */
    public function execute(): void {
        $changed = diagnostic_log_retention::purge_expired();

        mtrace('local_artqtml: diagnostic payloads redacted: ' . $changed);
    }
}
