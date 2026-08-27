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
 * Per-user AJAX rate limits for extract_text and get_status.
 *
 * Fixed 60-second windows (core_ai-style counter, shorter window). Limits leave headroom for
 * the status page's 3s poll (~20/min) plus a few tabs, while capping extract_text bursts.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Counts AJAX calls per user per action and rejects bursts above the configured caps.
 */
class ajax_rate_limiter {
    /** @var int Sliding/fixed window length in seconds. */
    public const WINDOW_SECONDS = MINSECS;

    /** @var int Max get_status calls per user per window (~3 tabs at 3s poll). */
    public const LIMIT_GET_STATUS = 60;

    /** @var int Max extract_text calls per user per window. */
    public const LIMIT_EXTRACT_TEXT = 10;

    /** @var string Rate-limit action for status polling. */
    public const ACTION_GET_STATUS = 'get_status';

    /** @var string Rate-limit action for draft text extraction. */
    public const ACTION_EXTRACT_TEXT = 'extract_text';

    /**
     * Record one get_status call or throw if the user is over the limit.
     *
     * @throws \moodle_exception
     */
    public static function require_get_status(): void {
        self::require_action(self::ACTION_GET_STATUS, self::LIMIT_GET_STATUS);
    }

    /**
     * Record one extract_text call or throw if the user is over the limit.
     *
     * @throws \moodle_exception
     */
    public static function require_extract_text(): void {
        self::require_action(self::ACTION_EXTRACT_TEXT, self::LIMIT_EXTRACT_TEXT);
    }

    /**
     * Increment the counter for an action; throw when the cap is exceeded.
     *
     * @param string $action
     * @param int $limit
     * @throws \moodle_exception
     */
    public static function require_action(string $action, int $limit): void {
        global $USER;

        $userid = (int) ($USER->id ?? 0);
        if ($userid <= 0) {
            // Guests / empty session should already fail capability checks; do not count them.
            return;
        }

        if (!self::allow($action, $userid, $limit, time())) {
            throw new \moodle_exception('errorajaxratelimit', 'local_artqtml');
        }
    }

    /**
     * Check and update the per-user counter. Returns true if the call is allowed.
     *
     * @param string $action
     * @param int $userid
     * @param int $limit
     * @param int $now unix timestamp (injectable for tests)
     * @return bool
     */
    public static function allow(string $action, int $userid, int $limit, int $now): bool {
        global $DB;

        $expiredbefore = $now - self::WINDOW_SECONDS;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $record = $DB->get_record('local_artqtml_ajax_ratelimit', [
                'userid' => $userid,
                'action' => $action,
            ]);

            if ($record) {
                if ((int) $record->windowstart <= $expiredbefore) {
                    $DB->execute(
                        "UPDATE {local_artqtml_ajax_ratelimit}
                        SET windowstart = ?, hitcount = 1
                      WHERE id = ?
                        AND windowstart = ?",
                        [$now, $record->id, $record->windowstart]
                    );
                    if (self::update_affected_one_row($DB)) {
                        return true;
                    }
                    continue;
                }

                if ((int) $record->hitcount >= $limit) {
                    return false;
                }

                $DB->execute(
                    "UPDATE {local_artqtml_ajax_ratelimit}
                        SET hitcount = hitcount + 1
                      WHERE id = ?
                        AND hitcount = ?
                        AND hitcount < ?",
                    [$record->id, $record->hitcount, $limit]
                );
                if (self::update_affected_one_row($DB)) {
                    return true;
                }
                continue;
            }

            try {
                $DB->insert_record('local_artqtml_ajax_ratelimit', (object) [
                    'userid' => $userid,
                    'action' => $action,
                    'windowstart' => $now,
                    'hitcount' => 1,
                ]);
                return true;
            } catch (\dml_write_exception $e) {
                continue;
            }
        }

        return false;
    }

    /**
     * Whether the immediately preceding UPDATE changed exactly one row.
     *
     * Uses driver affected-row count when available; otherwise ROW_COUNT() on the same connection.
     * Avoids the CAS/ABA race of re-reading hitcount and treating another writer's increment as ours.
     *
     * @param \moodle_database $DB
     * @return bool
     */
    protected static function update_affected_one_row(\moodle_database $DB): bool {
        if (method_exists($DB, 'get_affected_rows')) {
            return $DB->get_affected_rows() === 1;
        }

        $row = $DB->get_record_sql('SELECT ROW_COUNT() AS affectedrows');
        return $row && (int) $row->affectedrows === 1;
    }
}
