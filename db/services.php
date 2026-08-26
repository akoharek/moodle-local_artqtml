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
 * Web service definitions for local_artqtml.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_artqtml_get_status' => [
        'classname'    => 'local_artqtml\external\get_status',
        'methodname'   => 'execute',
        'description'  => 'Return the current status and question count for an AI quiz question generation.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/artqtml:use',
    ],
    'local_artqtml_test_connection' => [
        'classname'    => 'local_artqtml\external\test_connection',
        'methodname'   => 'execute',
        'description'  => 'Test the saved Claude/Gemini API key and list available models.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/artqtml:configure',
    ],
    'local_artqtml_extract_text' => [
        'classname'    => 'local_artqtml\external\extract_text',
        'methodname'   => 'execute',
        'description'  => 'Extract plain text from an upload-page draft file so it can be shown in the source text box.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/artqtml:use',
    ],
];
