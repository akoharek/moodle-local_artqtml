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
 * Daily scheduled task that re-checks and persists the license status (Lic-014).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\task;

/**
 * Refreshes the cached license status once a day.
 */
class license_check_task extends \core\task\scheduled_task {
    /**
     * Task name shown in the admin scheduled task list.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_license_check', 'local_artqtml');
    }

    /**
     * Execute the task.
     *
     * @return void
     */
    public function execute() {
        \local_artqtml\local\license_checker::refresh_cached_status();
    }
}
