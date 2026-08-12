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
 * PHPStan bootstrap: boot Moodle so its classes/constants are available to static analysis,
 * And pull in the core libraries the plugin builds on (admin settings, question engine, forms,
 * Upgrade helpers). Referenced from phpstan.neon's bootstrapFiles.
 *
 * The plugin always lives at <moodleroot>/local/artqtml, so the Moodle root config.php is two
 * Directories up - this works both in the local Docker checkout and in CI (where moodle-plugin-ci
 * Installs the plugin into a full Moodle tree).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->dirroot . '/question/engine/bank.php');
