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
 * Shared license constants (functional spec ch.10) - a dependency-free leaf so the license\*
 * implementation classes no longer have to reference the license_checker facade for them
 * (v20 #12: breaks the facade<->implementation constant dependency cycle).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\license;

/**
 * Constants shared across the license facade and its implementation classes.
 */
class license_constants {
    /** @var string[] valid values of the license file's "edition" field. */
    public const EDITIONS = ['perpetual', 'annual', 'question_limit'];

    /**
     * @var string[] status()['state'] values that block starting new generations (Lic-009).
     */
    public const BLOCKING_STATES = ['none', 'invalid', 'wrongsite', 'expired', 'exhausted', 'tampered'];

    /** @var string local_artqtml_log.event value for an encrypted integrity violation record. */
    public const INTEGRITY_VIOLATION_EVENT = 'integrity_violation';
}
