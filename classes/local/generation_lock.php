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
 * Serialises the read-decide-write sequences that act on one generation.
 *
 * WHAT IT IS FOR, in one sentence: three screens and the start button all read a generation's row,
 * Decide from what they read whether they may write, and then write - and between the decision and
 * The write another request can change the very thing the decision was made on.
 *
 * WHY A TRANSACTION IS NOT ENOUGH, because that was the first answer and it was wrong.
 * `$DB->start_delegated_transaction()` gives atomicity, not isolation of a row: it issues no
 * `SELECT ... FOR UPDATE`, so a second request reads the same row happily while the first is
 * Between its read and its write. Making the read locking would mean database-specific SQL across
 * Every engine Moodle supports.
 *
 * - a source text replaced from an older tab while the generation is already being read by the
 * Model - the questions come from the old text, the screen shows the new one, and nothing says
 * They are not the same thing;
 * - two people pressing "Start generation" in the same instant - both pass the status check, both
 * Set `generating`, both clear the question rows, and the run is paid for twice.
 *
 * THESE LOCKS MUST NEVER BE NESTED, and the reason was measured rather than assumed. The protection
 * Is between requests, not within one: `lock_config::get_lock_factory()` returns a NEW factory
 * Object on every call, the fail-fast guard against a stacked lock is that object's own list, and
 * On MySQL/MariaDB `GET_LOCK` is re-entrant within a single database connection. A call site that
 * Ran inside another one's locked section would therefore take the same lock a second time and
 * Carry on as if it held it alone. None of the four call sites nests today; this paragraph is why
 * None of them may be made to.
 *
 * THE TIMEOUT IS SHORT ON PURPOSE. These are user-facing page submits, not background work: a
 * Teacher waiting on a lock is a teacher watching a spinner. Five seconds is far longer than the
 * Millisecond-scale window this closes, and short enough that a stuck lock fails visibly instead of
 * Hanging the page.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

defined('MOODLE_INTERNAL') || die();

/**
 * A per-generation lock around read-decide-write.
 */
class generation_lock {
    /** @var string the lock factory's type, shared by every caller. */
    public const LOCK_TYPE = 'local_artqtml_generation';

    /** @var int seconds a page submit will wait for the lock before giving up. */
    public const TIMEOUT = 5;

    /**
     * Run a callback with this generation locked.
     *
     * The lock is released in a `finally`, so it survives the callback throwing - which matters,
     * Because the callback's whole job is to throw when the status turns out to be wrong.
     *
     * @param int $generationid the generation to lock
     * @param callable $callback the read-decide-write sequence
     * @return mixed whatever the callback returns
     * @throws \moodle_exception if the lock cannot be obtained within the timeout
     */
    public static function run(int $generationid, callable $callback) {
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        $lock = $factory->get_lock((string) $generationid, self::TIMEOUT);

        if ($lock === false) {
            // The message is the teacher's, not the developer's: what happened, and what to do.
            throw new \moodle_exception('errorgenerationbusy', 'local_artqtml');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
