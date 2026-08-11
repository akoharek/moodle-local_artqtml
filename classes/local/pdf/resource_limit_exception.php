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
 * Signals that reading a PDF's object structure hit a hard limit.
 *
 * WHY AN EXCEPTION AND NOT A RETURN VALUE. {@see object_index::build()} returns null for "this
 * file's structure cannot be read", and the caller answers that by running the older whole-file
 * scan instead. Until 2026-08-05 a limit being exceeded returned the same null, so the two meant
 * the same thing to the caller - and a document that hit a limit therefore started the MORE
 * EXPENSIVE route, which is the one outcome the limit exists to prevent. A separate signal is the
 * smallest thing that keeps them apart, and unlike a second return value it cannot be forgotten at
 * a call site.
 *
 * It carries no message of its own. What the teacher sees comes from the extraction result's
 * reason code, the same string as every other resource limit.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\pdf;

/**
 * Thrown when the object index exceeds one of its hard limits.
 */
class resource_limit_exception extends \moodle_exception {
    /**
     * Construct with the same user-facing string every resource limit uses.
     *
     * @param string $debuginfo which limit was hit - for the developer log, never for the teacher
     */
    public function __construct(string $debuginfo = '') {
        parent::__construct('errorfileresourcelimit', 'local_artqtml', '', null, $debuginfo);
    }
}
