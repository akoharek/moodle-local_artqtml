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
 * Who may mutate a generation (approve, edit source, abort, retry, delete, …).
 *
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * The single answer to "may this user change this generation or its draft questions?".
 */
class generation_access_policy {
    /**
     * Whether the given user may mutate this generation.
     *
     * True when the user has local/artqtml:use and owns the generation, or holds
     * local/artqtml:manageall. local/artqtml:configure never authorises mutation.
     *
     * @param \stdClass $generation a local_artqtml_generations record (needs ->userid)
     * @param int|null $userid user to check; defaults to the current user
     * @param \context|null $context capability context; defaults to system
     * @return bool
     */
    public static function can_mutate(\stdClass $generation, ?int $userid = null, ?\context $context = null): bool {
        global $USER;

        $userid = $userid ?? (int) $USER->id;
        $context = $context ?? \context_system::instance();

        if (!has_capability('local/artqtml:use', $context, $userid)) {
            return false;
        }

        if ((int) ($generation->userid ?? 0) === $userid) {
            return true;
        }

        return has_capability('local/artqtml:manageall', $context, $userid);
    }

    /**
     * Throw unless the current user may mutate this generation.
     *
     * @param \stdClass $generation a local_artqtml_generations record
     * @param \context|null $context capability context; defaults to system
     * @return void
     * @throws \required_capability_exception if the user lacks local/artqtml:use
     * @throws \moodle_exception if the user may not mutate this generation
     */
    public static function require_can_mutate(\stdClass $generation, ?\context $context = null): void {
        global $USER;

        $context = $context ?? \context_system::instance();
        require_capability('local/artqtml:use', $context);

        if (!self::can_mutate($generation, (int) $USER->id, $context)) {
            throw new \moodle_exception('cannotmutateothers', 'local_artqtml');
        }
    }
}
