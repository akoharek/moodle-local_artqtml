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
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\admin;

/**
 * Validates this setting against both a fixed minimum and a sibling setting's submitted value.
 *
 * Used on *both* sides of a min/max pair (with $mustbeatmost flipped accordingly) - cross-
 * checking only the max field against the min would still let a same-submission edit push the
 * min above an unchanged max through independently-valid per-field writes.
 */
class setting_configtext_atleast extends setting_configtext_min {
    /** @var string the (unqualified) name of the sibling setting to compare against. */
    protected string $othersettingname;

    /** @var bool true if this setting must be <= the sibling (i.e. this is the "min" field). */
    protected bool $mustbeatmost;

    /**
     * Constructor.
     *
     * @param string $name
     * @param string $visiblename
     * @param string $description
     * @param mixed $defaultsetting
     * @param string $othersettingname unqualified name (no plugin prefix) of the sibling setting
     * @param int $minvalue
     * @param bool $mustbeatmost true if this setting must not exceed the sibling (the "min"
     *      field of the pair); false if it must not be less than the sibling (the "max" field)
     */
    public function __construct(
        $name,
        $visiblename,
        $description,
        $defaultsetting,
        string $othersettingname,
        int $minvalue = 1,
        bool $mustbeatmost = false
    ) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, $minvalue);
        $this->othersettingname = $othersettingname;
        $this->mustbeatmost = $mustbeatmost;
    }

    /**
     * Validate the submitted value, including the cross-field comparison.
     *
     * Reads the sibling setting's just-submitted (not yet saved) value straight from the
     * request, since admin_setting::validate() runs before any setting on the page is written -
     * config_read() at this point would still return the sibling's pre-submission value.
     *
     * @param string $data
     * @return true|string true if valid, an error message string otherwise
     */
    public function validate($data) {
        $parentresult = parent::validate($data);
        if ($parentresult !== true) {
            return $parentresult;
        }

        $othervalue = optional_param('s_local_artqtml_' . $this->othersettingname, null, PARAM_INT);
        if ($othervalue === null) {
            $otherstored = $this->config_read($this->othersettingname);
            // The sibling isn't configured yet (e.g. while plugin defaults are first applied at
            // install time, before the other half of the pair exists) - there is nothing to
            // cross-check against, so accept the value.
            if ($otherstored === null || $otherstored === false) {
                return true;
            }
            $othervalue = (int) $otherstored;
        }

        if ($this->mustbeatmost) {
            if ((int) $data > $othervalue) {
                return get_string('errorminmorethanmax', 'local_artqtml');
            }
        } else if ((int) $data < $othervalue) {
            return get_string('errormaxlessthanmin', 'local_artqtml');
        }

        return true;
    }
}
