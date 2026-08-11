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
 * Scheduled task definitions for local_artqtml Light.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        // Admin-052: once a day. Deliberately not more often - each run makes a real (if tiny)
        // billable call per provider, and a withdrawn model is not a minute-to-minute risk.
        'classname' => 'local_artqtml\task\model_check_task',
        'blocking'  => 0,
        'minute'    => '15',
        'hour'      => '4',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        // Gen-001-021/Val-*: runs the Claude/Gemini calls in the background instead of inline
        // in the web request. Every 5 minutes by default; can also be run on demand via
        // admin/cli/scheduled_task.php --execute for near-instant processing while testing.
        'classname' => 'local_artqtml\task\process_pending_generations',
        'blocking'  => 0,
        'minute'    => '*/5',
        'hour'      => '*',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
];
