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
 * CLI: migrate a site from local_aiquizgen tables/registry to local_artqtml.
 *
 * Safe to re-run. Prefer running this after the plugin directory has been renamed to artqtml
 * And before or after admin/cli/upgrade.php - install.php / upgrade.php also call the same
 * Migrator, so this is mainly for explicit ops and Docker smoke.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');

defined('MOODLE_INTERNAL') || die();

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    fwrite(STDOUT, "Migrate local_aiquizgen DB tables and Moodle registry rows to local_artqtml.\n\n"
        . "Options:\n"
        . "  -h, --help  Show this help.\n");
    exit(0);
}

$ran = \local_artqtml\local\component_rename::migrate_if_needed();
fwrite(STDOUT, $ran
    ? "Migration applied (or registry leftovers rewritten).\n"
    : "Nothing to migrate (no local_aiquizgen tables/registry rows found).\n");

exit(0);
