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
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

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
        // Fresh installs get it from install.xml; existing sites need this add-field.
        $table = new xmldb_table('local_artqtml_modelcheck');
        $field = new xmldb_field('pluginversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'triggertype');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026081300, 'local', 'artqtml');
    }

    if ($oldversion < 2026081301) {
        // API keys were stored as plaintext until encryption-at-rest was added. Leftover
        // values have no sodium:/openssl- prefix, so decrypt() throws encryption_wrongmethod
        // and the admin UI showed empty — the key looked lost. Re-encrypt in place so the
        // value survives the upgrade. Ciphertext that fails integrity cannot be recovered;
        // encrypted_config records a one-time admin notice to re-enter those keys.
        \local_artqtml\local\encrypted_config::migrate_plaintext_on_upgrade();

        upgrade_plugin_savepoint(true, 2026081301, 'local', 'artqtml');
    }

    if ($oldversion < 2026082602) {
        // Draft course is a holding area only: drop native edit cap from the draft role and
        // track external Moodle edits as locked rows.
        $table = new xmldb_table('local_artqtml_questions');
        $field = new xmldb_field(
            'externallyedited',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'edited'
        );
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        \local_artqtml\local\draft_role::ensure_role();
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => \local_artqtml\local\draft_role::SHORTNAME]);
        if ($roleid) {
            $systemcontext = \context_system::instance();
            unassign_capability('moodle/question:editall', $roleid, $systemcontext->id);
        }

        upgrade_plugin_savepoint(true, 2026082602, 'local', 'artqtml');
    }

    if ($oldversion < 2026082700) {
        // Google retired gemini-2.x ids for new API keys (404 on generateContent).
        \local_artqtml\local\model_list::migrate_deprecated_gemini_model();

        upgrade_plugin_savepoint(true, 2026082700, 'local', 'artqtml');
    }

    if ($oldversion < 2026082701) {
        // S-06: replace non-atomic MUC counters with a DB table and optimistic UPDATEs.
        $table = new xmldb_table('local_artqtml_ajax_ratelimit');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('action', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('windowstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('hitcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('userid_action', XMLDB_INDEX_UNIQUE, ['userid', 'action']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026082701, 'local', 'artqtml');
    }

    return true;
}
