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
 * Product decision 2026-08-10 — mutual exclusion of feature areas:
 * - local/artqtml:use → teacher usage only (list, generate, approve, status, own-delete, …).
 *   Does NOT grant admin/settings access.
 *   Glob-031: collaborative :use by design; delete is owner-only (see delete.php).
 *   Non-owner open/act paths (status, approve, get_status, source edit, abort, list Open) are
 *   intentional — not missing ownership checks. Destroying a generation stays with the owner.
 * - local/artqtml:configure → admin settings panel only (settings.php,
 *   model actions, test_connection). Does NOT grant generation UI or deletion.
 * - Both (manager archetype) → both areas; neither capability substitutes for the other.
 * - Deletion additionally requires ownership of the generation (see generation_delete_policy).
 *
 * Risk bits (security-policy follow-up):
 * - :use — teachers create AI-generated content (RISK_SPAM | RISK_XSS) and, under Glob-031,
 *   can open colleagues' generations (RISK_PERSONAL).
 * - :configure — site config and secrets (RISK_CONFIG), HTML/settings surfaces (RISK_XSS),
 *   destructive/API-key changes (RISK_DATALOSS), and personal/secret data
 *   (RISK_PERSONAL).
 *
 * The plugin is site-wide (Glob-022, Glob-023): context is always system.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/artqtml:use' => [
        // Teachers generate/see AI content; collaborative :use can expose others' generations.
        'riskbitmask'  => RISK_SPAM | RISK_XSS | RISK_PERSONAL,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
    'local/artqtml:configure' => [
        // Admin settings and API keys — config + secrets + potential data loss.
        'riskbitmask'  => RISK_CONFIG | RISK_XSS | RISK_DATALOSS | RISK_PERSONAL,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
