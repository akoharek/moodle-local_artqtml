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

/**
 * Unit tests for the per-generation lock.
 *
 * WHAT THESE TESTS DO NOT DO, said plainly so nobody reads more into them: they do not reproduce
 * The race. Two requests interleaving between a read and a write cannot be staged from a single
 * PHPUnit process. What is testable is the lock's own contract - that it is held for the duration
 * Of the callback, that it is released afterwards even when the callback throws, and that a second
 * Attempt on the same generation while it is held does not succeed. That contract is what the four
 * Call sites rely on.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\generation_lock
 */
final class generation_lock_test extends \advanced_testcase {
    /**
     * The callback's return value comes back out, and the lock is free again afterwards.
     */
    public function test_the_callback_runs_and_the_lock_is_released(): void {
        $this->resetAfterTest();

        $this->assertSame('done', generation_lock::run(4242, static fn(): string => 'done'));

        // Free again: the same generation can be locked immediately.
        $this->assertSame('again', generation_lock::run(4242, static fn(): string => 'again'));
    }

    /**
     * A throwing callback still releases the lock.
     *
     * This is the case that matters most in production: the callback's whole job is to re-read the
     * Status and throw when it is no longer a draft. A lock leaked on that path would block every
     * Later attempt on the same generation until the request's lock timed out.
     */
    public function test_a_throwing_callback_releases_the_lock(): void {
        $this->resetAfterTest();

        try {
            generation_lock::run(4243, static function (): void {
                throw new \moodle_exception('error');
            });
            $this->fail('The exception should have propagated.');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame('free', generation_lock::run(4243, static fn(): string => 'free'));
    }

    /**
     * The lock is per generation, not global: a second generation is unaffected by the first.
     */
    public function test_a_different_generation_is_not_blocked(): void {
        $this->resetAfterTest();

        generation_lock::run(4244, function (): void {
            $this->assertSame('other', generation_lock::run(4245, static fn(): string => 'other'));
        });
    }

    /**
     * The same factory refuses a key it is already holding.
     *
     * THIS TEST PINS AN ASSUMPTION ABOUT MOODLE, not about this plugin, and it is here because the
     * Design rests on it. It was written the other way round first - asking a *newly obtained*
     * Factory for a key an outer `run()` was holding - and it failed: `lock_config::get_lock_factory()`
     * Hands back a new factory object each call, the fail-fast guard is that object's own
     * `openlocks` list, and MySQL's `GET_LOCK` is re-entrant within one database connection. So a
     * Single request can take the same lock twice without noticing.
     *
     * That is not a defect in the four call sites - none of them nests - but it is the reason none
     * Of them may ever be made to nest, and the reason this test asserts what it does.
     */
    public function test_one_factory_refuses_a_key_it_already_holds(): void {
        $this->resetAfterTest();

        $factory = \core\lock\lock_config::get_lock_factory(generation_lock::LOCK_TYPE);

        $lock = $factory->get_lock('4246', 0);
        $this->assertNotFalse($lock);

        try {
            $this->assertFalse($factory->get_lock('4246', 0));
        } finally {
            $lock->release();
        }
    }
}
