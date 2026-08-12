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
 * Per-user AJAX rate limits for extract_text and get_status (security audit finding #7).
 *
 * Fixed 60-second windows (core_ai-style counter, shorter window). Limits leave headroom for
 * The status page's 3s poll (~20/min) plus a few tabs, while capping extract_text bursts.
 *
 * @package    local_artqtml
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

    /** @var string Cache key action for status polling. */
    public const ACTION_GET_STATUS = 'get_status';

    /** @var string Cache key action for draft text extraction. */
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
        $cache = \cache::make('local_artqtml', 'ajax_ratelimit');
        $key = self::cache_key($action, $userid);
        $ratedata = $cache->get($key);

        if ($ratedata === false || !is_array($ratedata)) {
            $ratedata = ['count' => 0, 'start_time' => $now];
        }

        if ($now - (int) $ratedata['start_time'] >= self::WINDOW_SECONDS) {
            $ratedata = ['count' => 0, 'start_time' => $now];
        }

        if ((int) $ratedata['count'] >= $limit) {
            return false;
        }

        $ratedata['count'] = (int) $ratedata['count'] + 1;
        $cache->set($key, $ratedata);
        return true;
    }

    /**
     * Build a simple cache key.
     *
     * @param string $action
     * @param int $userid
     * @return string
     */
    public static function cache_key(string $action, int $userid): string {
        return $action . '_' . $userid;
    }
}
