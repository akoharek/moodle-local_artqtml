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
 * Uninstall cleanup for local_artqtml.
 *
 * Moodle's standard uninstall already drops every table defined in db/install.xml and every
 * Config_plugins/capability row for this component automatically - no code is needed for that.
 * This hook only handles the one thing Moodle can't infer on its own: every generation's draft
 * Question bank category (and the real Moodle questions inside it) lives in core's own
 * Question_categories/question tables, not this plugin's tables. Without deleting those first,
 * Uninstalling the plugin would silently orphan that content in the site's real question bank -
 * Dropped local_artqtml_generations rows would take the only record of which category belonged
 * To which generation with them.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Delete every generation's draft question bank category before the plugin's own tables go.
 *
 * @return bool
 */
function xmldb_local_artqtml_uninstall(): bool {
    global $DB;

    $roleid = (int) $DB->get_field('role', 'id', [
        'shortname' => \local_artqtml\local\draft_role::SHORTNAME,
    ]);
    if ($roleid) {
        delete_role($roleid);
    }

    if (!$DB->get_manager()->table_exists('local_artqtml_generations')) {
        return true;
    }

    $draftcategoryids = $DB->get_fieldset_select(
        'local_artqtml_generations',
        'draftcategoryid',
        'draftcategoryid IS NOT NULL'
    );

    foreach ($draftcategoryids as $categoryid) {
        \local_artqtml\local\draft_bank::delete((int) $categoryid);
    }

    return true;
}
