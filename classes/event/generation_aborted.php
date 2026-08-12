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
 * generation_aborted event.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\event;

/**
 * Triggered when the user aborts an in-progress generation, causing a full rollback.
 */
class generation_aborted extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_artqtml_generations';
    }

    /**
     * Return localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_generation_aborted', 'local_artqtml');
    }

    /**
     * Return non-localised event description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' aborted AI generation '{$this->objectid}'; " .
            'its temporary questions were rolled back.';
    }

    /**
     * Return the URL to view the generation, contextual to its CURRENT status.
     *
     * Deliberately not the page that was relevant when this event fired: a log entry is read
     * later, and the useful destination is wherever the generation can be acted on now. The
     * status->destination rule is stated once, in generation_list::open_url().
     *
     * @return \moodle_url|null null if the generation has since been deleted
     */
    public function get_url() {
        return \local_artqtml\local\generation_list::open_url_by_id((int) $this->objectid);
    }
}
