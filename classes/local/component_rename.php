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
 * One-shot migration from local_aiquizgen to local_artqtml.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Renames DB tables and Moodle registry rows left behind by the frankenstyle rename.
 *
 * When the plugin directory/component changed from aiquizgen to artqtml, an existing site still
 * Has local_aiquizgen_* tables and config_plugins / capability / task rows under the old name.
 * Moodle then installs local_artqtml as "new" (install.xml creates empty artqtml tables). This class
 * Drops those empty tables, renames the populated aiquizgen tables, and rewrites the registry so
 * Settings, caps and scheduled tasks keep working.
 */
class component_rename {
    /** @var string Previous frankenstyle component. */
    public const OLD_COMPONENT = 'local_aiquizgen';

    /** @var string Previous plugin directory / short name. */
    public const OLD_NAME = 'aiquizgen';

    /** @var string New frankenstyle component. */
    public const NEW_COMPONENT = 'local_artqtml';

    /** @var string New plugin directory / short name. */
    public const NEW_NAME = 'artqtml';

    /**
     * Table suffixes shared by both components (without the local_*_ prefix).
     *
     * @return string[]
     */
    public static function table_suffixes(): array {
        return [
            'generations',
            'questions',
            'log',
            'license',
            'modelcheck',
        ];
    }

    /**
     * Whether any old-named table still exists.
     *
     * @return bool
     */
    public static function old_tables_exist(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        foreach (self::table_suffixes() as $suffix) {
            if ($dbman->table_exists(new \xmldb_table(self::OLD_COMPONENT . '_' . $suffix))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Migrate tables and Moodle registry from local_aiquizgen to local_artqtml when needed.
     *
     * Safe to call when there is nothing to migrate (no-op).
     *
     * @return bool true when a migration ran, false when nothing needed doing
     */
    public static function migrate_if_needed(): bool {
        global $DB;

        if (!self::old_tables_exist()) {
            // Still rewrite registry leftovers if old config rows remain without tables.
            return self::migrate_registry();
        }

        $dbman = $DB->get_manager();

        foreach (self::table_suffixes() as $suffix) {
            $oldname = self::OLD_COMPONENT . '_' . $suffix;
            $newname = self::NEW_COMPONENT . '_' . $suffix;
            $oldtable = new \xmldb_table($oldname);
            $newtable = new \xmldb_table($newname);

            if (!$dbman->table_exists($oldtable)) {
                continue;
            }

            // Install.xml may already have created empty new tables - drop those first.
            if ($dbman->table_exists($newtable)) {
                $dbman->drop_table($newtable);
            }

            $dbman->rename_table($oldtable, $newname);
        }

        self::migrate_registry();
        return true;
    }

    /**
     * Rewrite config, capabilities, tasks and external function rows to the new component.
     *
     * @return bool true if any registry row was rewritten
     */
    public static function migrate_registry(): bool {
        global $DB;

        $changed = false;

        // Admin settings and plugin version rows. Moodle may already have created local_artqtml
        // rows (including `version`) during install.xml/install.php, so a blind UPDATE of the
        // plugin column hits the (plugin, name) unique key - merge per-name instead.
        $oldconfigs = $DB->get_records('config_plugins', ['plugin' => self::OLD_COMPONENT]);
        foreach ($oldconfigs as $cfg) {
            $exists = $DB->record_exists('config_plugins', [
                'plugin' => self::NEW_COMPONENT,
                'name' => $cfg->name,
            ]);
            if ($exists) {
                // Keep the administrator's previous value for real settings; the fresh install's
                // version row must stay (it matches the code just installed).
                if ($cfg->name !== 'version') {
                    $DB->set_field('config_plugins', 'value', $cfg->value, [
                        'plugin' => self::NEW_COMPONENT,
                        'name' => $cfg->name,
                    ]);
                }
                $DB->delete_records('config_plugins', ['id' => $cfg->id]);
            } else {
                $DB->set_field('config_plugins', 'plugin', self::NEW_COMPONENT, ['id' => $cfg->id]);
            }
            $changed = true;
        }

        // Capabilities: name + component.
        $oldcaps = [
            'local/' . self::OLD_NAME . ':use' => 'local/' . self::NEW_NAME . ':use',
            'local/' . self::OLD_NAME . ':configure' => 'local/' . self::NEW_NAME . ':configure',
        ];
        foreach ($oldcaps as $oldcap => $newcap) {
            if ($DB->record_exists('capabilities', ['name' => $oldcap])) {
                // If the new capability was already installed, move role assignments onto it and
                // drop the old definition; otherwise rename in place.
                if ($DB->record_exists('capabilities', ['name' => $newcap])) {
                    $oldid = (int) $DB->get_field('capabilities', 'id', ['name' => $oldcap]);
                    $newid = (int) $DB->get_field('capabilities', 'id', ['name' => $newcap]);
                    if ($oldid && $newid) {
                        $DB->set_field('role_capabilities', 'capability', $newcap, ['capability' => $oldcap]);
                        $DB->delete_records('capabilities', ['id' => $oldid]);
                    }
                } else {
                    $DB->execute(
                        "UPDATE {capabilities}
                            SET name = ?, component = ?
                          WHERE name = ?",
                        [$newcap, self::NEW_COMPONENT, $oldcap]
                    );
                    $DB->set_field('role_capabilities', 'capability', $newcap, ['capability' => $oldcap]);
                }
                $changed = true;
            }
        }

        // Scheduled tasks: classname namespace prefix.
        $oldprefix = self::OLD_COMPONENT . '\\';
        $newprefix = self::NEW_COMPONENT . '\\';
        $tasks = $DB->get_records_select(
            'task_scheduled',
            $DB->sql_like('classname', ':prefix'),
            ['prefix' => $DB->sql_like_escape($oldprefix) . '%'],
            '',
            'id, classname'
        );
        foreach ($tasks as $task) {
            $DB->set_field(
                'task_scheduled',
                'classname',
                $newprefix . substr($task->classname, strlen($oldprefix)),
                ['id' => $task->id]
            );
            $changed = true;
        }

        // External functions registered under the old component name.
        if ($DB->get_manager()->table_exists('external_functions')) {
            $functions = $DB->get_records_select(
                'external_functions',
                $DB->sql_like('name', ':prefix') . ' OR component = :comp',
                [
                    'prefix' => $DB->sql_like_escape(self::OLD_COMPONENT . '_') . '%',
                    'comp' => self::OLD_COMPONENT,
                ],
                '',
                'id, name, classname, component'
            );
            foreach ($functions as $function) {
                $record = (object) [
                    'id' => $function->id,
                    'name' => str_replace(self::OLD_COMPONENT . '_', self::NEW_COMPONENT . '_', $function->name),
                    'classname' => str_replace(
                        self::OLD_COMPONENT . '\\',
                        self::NEW_COMPONENT . '\\',
                        $function->classname
                    ),
                    'component' => self::NEW_COMPONENT,
                ];
                $DB->update_record('external_functions', $record);
                $changed = true;
            }
        }

        // Event observers / message providers keyed by component, if present.
        if ($DB->get_manager()->table_exists('events_handlers')) {
            if ($DB->record_exists('events_handlers', ['component' => self::OLD_COMPONENT])) {
                $DB->set_field(
                    'events_handlers',
                    'component',
                    self::NEW_COMPONENT,
                    ['component' => self::OLD_COMPONENT]
                );
                $changed = true;
            }
        }

        return $changed;
    }
}
