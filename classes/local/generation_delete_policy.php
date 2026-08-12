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
 * Who may delete a generation.
 *
 * Shared by delete.php, generate.php's "Delete and exit" abort, and the list-page Delete control,
 * so the rule cannot drift between UI and server.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * The single answer to "may this user delete this generation?".
 */
class generation_delete_policy {
    /**
     * Whether the given user may delete this generation.
     *
     * True only when the user has local/artqtml:use and owns the generation. :configure is
     * deliberately ignored.
     *
     * @param \stdClass $generation a local_artqtml_generations record (needs ->userid)
     * @param int|null $userid user to check; defaults to the current user
     * @param \context|null $context capability context; defaults to system
     * @return bool
     */
    public static function can_delete(\stdClass $generation, ?int $userid = null, ?\context $context = null): bool {
        global $USER;

        $userid = $userid ?? (int) $USER->id;
        $context = $context ?? \context_system::instance();

        if (!has_capability('local/artqtml:use', $context, $userid)) {
            return false;
        }

        return (int) ($generation->userid ?? 0) === $userid;
    }

    /**
     * Throw unless the current user may delete this generation.
     *
     * Used immediately before purge() on every destructive entry path.
     *
     * @param \stdClass $generation a local_artqtml_generations record
     * @param \context|null $context capability context; defaults to system
     * @return void
     * @throws \required_capability_exception if the user lacks local/artqtml:use
     * @throws \moodle_exception if the user is not the owner
     */
    public static function require_can_delete(\stdClass $generation, ?\context $context = null): void {
        global $USER;

        $context = $context ?? \context_system::instance();
        require_capability('local/artqtml:use', $context);

        if ((int) ($generation->userid ?? 0) !== (int) $USER->id) {
            throw new \moodle_exception('cannotdeleteothers', 'local_artqtml');
        }
    }
}
