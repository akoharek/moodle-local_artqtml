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

namespace local_artqtml\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for AJAX rate limiting (security audit finding #7, option B).
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\ajax_rate_limiter
 */
final class ajax_rate_limiter_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        \cache::make('local_artqtml', 'ajax_ratelimit')->purge();
    }

    /**
     * Allow up to the limit within one window, then deny until the window resets.
     */
    public function test_allow_then_block_then_reset(): void {
        $userid = 42;
        $action = ajax_rate_limiter::ACTION_EXTRACT_TEXT;
        $limit = 3;
        $now = 1_700_000_000;

        $this->assertTrue(ajax_rate_limiter::allow($action, $userid, $limit, $now));
        $this->assertTrue(ajax_rate_limiter::allow($action, $userid, $limit, $now + 1));
        $this->assertTrue(ajax_rate_limiter::allow($action, $userid, $limit, $now + 2));
        $this->assertFalse(ajax_rate_limiter::allow($action, $userid, $limit, $now + 3));

        // Same window still blocked.
        $this->assertFalse(ajax_rate_limiter::allow($action, $userid, $limit, $now + 30));

        // New window after WINDOW_SECONDS.
        $later = $now + ajax_rate_limiter::WINDOW_SECONDS;
        $this->assertTrue(ajax_rate_limiter::allow($action, $userid, $limit, $later));
    }

    /**
     * Status and extract counters are independent for the same user.
     */
    public function test_actions_are_independent(): void {
        $userid = 7;
        $now = 1_700_000_100;
        $limit = 1;

        $this->assertTrue(ajax_rate_limiter::allow(
            ajax_rate_limiter::ACTION_GET_STATUS,
            $userid,
            $limit,
            $now
        ));
        $this->assertFalse(ajax_rate_limiter::allow(
            ajax_rate_limiter::ACTION_GET_STATUS,
            $userid,
            $limit,
            $now
        ));
        $this->assertTrue(ajax_rate_limiter::allow(
            ajax_rate_limiter::ACTION_EXTRACT_TEXT,
            $userid,
            $limit,
            $now
        ));
    }

    /**
     * Different users do not share a counter.
     */
    public function test_users_are_independent(): void {
        $now = 1_700_000_200;
        $limit = 1;

        $this->assertTrue(ajax_rate_limiter::allow(
            ajax_rate_limiter::ACTION_GET_STATUS,
            1,
            $limit,
            $now
        ));
        $this->assertTrue(ajax_rate_limiter::allow(
            ajax_rate_limiter::ACTION_GET_STATUS,
            2,
            $limit,
            $now
        ));
    }

    /**
     * Require_* throws moodle_exception once the configured cap is hit.
     */
    public function test_require_extract_text_throws(): void {
        $this->setAdminUser();
        $cache = \cache::make('local_artqtml', 'ajax_ratelimit');
        $key = ajax_rate_limiter::cache_key(
            ajax_rate_limiter::ACTION_EXTRACT_TEXT,
            (int) $GLOBALS['USER']->id
        );
        $cache->set($key, [
            'count' => ajax_rate_limiter::LIMIT_EXTRACT_TEXT,
            'start_time' => time(),
        ]);

        $this->expectException(\moodle_exception::class);
        ajax_rate_limiter::require_extract_text();
    }
}
