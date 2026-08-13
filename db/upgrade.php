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
 * Upgrade steps for local_artqtml.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute local_artqtml upgrade steps between two given versions.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool always true
 */
function xmldb_local_artqtml_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026081300) {
        // Pluginversion scopes a model-check exclusion to the plugin version that produced it.
        // Fresh installs get it from install.xml; existing sites (and aiquizgen renames that
        // restore a table without the column) need this add-field.
        $table = new xmldb_table('local_artqtml_modelcheck');
        $field = new xmldb_field('pluginversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'triggertype');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026081300, 'local', 'artqtml');
    }

    return true;
}
