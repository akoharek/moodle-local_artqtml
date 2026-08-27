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
 * Capability definitions for local_artqtml.
 *
 * The plugin is site-wide: context is always system.
 * :use — view any generation; mutate own only. :manageall — mutate any generation (manager).
 * Draft questions edited outside the plugin are locked (externallyedited); generation delete
 * still allowed for owner/:use or :manageall unless the generation has moved-out questions.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/artqtml:use' => [
        // Teachers generate and review AI content; may view others' generations but mutate own only.
        'riskbitmask'  => RISK_SPAM | RISK_XSS | RISK_PERSONAL,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
    'local/artqtml:manageall' => [
        // Elevated: mutate (approve, edit source, abort, delete, …) any user's generation.
        'riskbitmask'  => RISK_PERSONAL | RISK_DATALOSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/artqtml:configure' => [
        // Admin settings, API keys, license — config + secrets + potential data loss.
        'riskbitmask'  => RISK_CONFIG | RISK_XSS | RISK_DATALOSS | RISK_PERSONAL,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
