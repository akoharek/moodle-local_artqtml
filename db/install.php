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
 * Install-time setup for local_artqtml.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Create the draft-editing role, seed the system prompt, migrate from local_aiquizgen if present.
 *
 * @return bool
 */
function xmldb_local_artqtml_install(): bool {
    // Frankenstyle rename: if local_aiquizgen tables still exist, install.xml has just created
    // empty local_artqtml_* tables - swap them for the populated ones and rewrite registry rows.
    \local_artqtml\local\component_rename::migrate_if_needed();

    \local_artqtml\local\draft_role::ensure_role();

    \local_artqtml\local\prompt_seed::apply();

    return true;
}
