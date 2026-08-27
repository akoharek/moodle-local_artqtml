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
 * Daily model check.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\task;

/**
 * Runs the availability and structure checks for both providers once a day.
 */
class model_check_task extends \core\task\scheduled_task {
    /**
     * Task name shown in the scheduled tasks admin screen.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskmodelcheck', 'local_artqtml');
    }

    /**
     * Run both providers' checks.
     *
     * @return void
     */
    public function execute() {
        $apitimeout = (int) (get_config('local_artqtml', 'apitimeout') ?: 60);
        set_time_limit((($apitimeout * 2) + 30) * count(\local_artqtml\local\model_list::PROVIDERS));

        $results = \local_artqtml\local\model_checker::check_all(
            \local_artqtml\local\model_check_log::TRIGGER_SCHEDULED
        );

        foreach ($results as $provider => $result) {
            mtrace(sprintf(
                'local_artqtml model check [%s]: %s - %s',
                $provider,
                $result['success'] ? 'OK' : 'FAILED',
                implode('; ', $result['messages'])
            ));
        }
    }
}
