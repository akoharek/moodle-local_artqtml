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
 * ai_call_made event (technical annex 7.1/7.2).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\event;

/**
 * Triggered for every successful Claude/Gemini API call.
 *
 * The `other` field carries: call_type (generate/validate), provider (claude/gemini),
 * http_status, tokens_input, tokens_output, json_attempt, is_retry_attempt, request_id.
 */
class ai_call_made extends \core\event\base {
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
        return get_string('event_ai_call_made', 'local_artqtml');
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
        return "A '$calltype' AI call to '$provider' succeeded for generation '$generationid'.";
    }
}
