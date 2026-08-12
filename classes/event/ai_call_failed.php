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
 * ai_call_failed event.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\event;

/**
 * Triggered for an HTTP-level failure or an exhausted JSON-retry fallback.
 *
 * The `other` field carries the same keys as ai_call_made, plus error_message.
 */
class ai_call_failed extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Return localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_ai_call_failed', 'local_artqtml');
    }

    /**
     * Return non-localised event description.
     *
     * @return string
     */
    public function get_description() {
        $provider = $this->other['provider'] ?? 'unknown';
        $calltype = $this->other['call_type'] ?? 'unknown';
        $generationid = $this->other['generationid'] ?? 0;
        $message = $this->other['error_message'] ?? '';
        return "A '$calltype' AI call to '$provider' failed for generation '$generationid': $message";
    }
}
