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
 * Unit tests for "one running generation per person".
 *
 * The column this rule counts on, `userid`, is written by the start path to the user who pressed
 * The button, so "whose runs" and "who pressed Start" are the same person by construction. That
 * Write lives in generate.php and is not exercised from here.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\generation_start_policy
 */
final class generation_start_policy_test extends \advanced_testcase {
    /** @var int the owner whose allowance is under test. */
    private const OWNER = 501;

    /** @var int a colleague, to prove the allowance is per person. */
    private const COLLEAGUE = 502;

    /**
     * Insert a generation row for an owner, in a given status.
     *
     * @param int $ownerid local_artqtml_generations.userid
     * @param string $status one of generation_status::VALUES
     * @param string $name the generation's name, so the refusal can be checked to name the right one
     * @param int $timecreated fixed, because the rule returns the oldest of several
     * @return int the new generation's id
     */
    private function make_generation(int $ownerid, string $status, string $name = 'Fixture', int $timecreated = 1000): int {
        global $DB;

        return (int) $DB->insert_record('local_artqtml_generations', (object) [
            'userid'       => $ownerid,
            'name'         => $name,
            'shortname'    => 'START',
            'status'       => $status,
            'timecreated'  => $timecreated,
            'timemodified' => $timecreated,
        ]);
    }

    /**
     * Each of the three in-progress statuses blocks, on its own.
     *
     * @return void
     */
    public function test_each_in_progress_status_blocks_on_its_own(): void {
        $this->resetAfterTest();

        $statuses = [
            generation_status::GENERATING,
            generation_status::VALIDATING,
            generation_status::SAVING,
        ];

        foreach ($statuses as $status) {
            $runningid = $this->make_generation(self::OWNER, $status, 'Running ' . $status);
            $draftid = $this->make_generation(self::OWNER, generation_status::STARTED, 'The draft being started');

            $blocking = generation_start_policy::find_running(self::OWNER, $draftid);

            $this->assertNotNull($blocking, "$status must block a new start");
            $this->assertSame($runningid, (int) $blocking->id);
            // The refusal names the other generation, so the name has to come back with it.
            $this->assertSame('Running ' . $status, $blocking->name);

            $this->reset_table();
        }
    }

    /**
     * The three in-progress statuses asserted above are exactly the shared IN_PROGRESS constant.
     *
     * Written as its own assertion rather than by looping the constant in the test above: the
     * Statuses are named there so a change to the constant fails as a decision to be made, not as
     * A test that quietly re-derives itself from whatever the constant now says.
     *
     * @return void
     */
    public function test_the_blocking_statuses_are_the_shared_constant(): void {
        $this->assertSame(
            [
                generation_status::GENERATING,
                generation_status::VALIDATING,
                generation_status::SAVING,
            ],
            generation_status::IN_PROGRESS
        );
    }

    /**
     * 'started' does not block: a teacher may keep as many drafts as they like.
     *
     * This is the half of the rule that is easiest to get wrong in the other direction, and the
     * One that decides whether the plugin is still usable: a draft costs nothing until it is
     * Started, so limiting drafts would take away the way people work without buying anything.
     *
     * @return void
     */
    public function test_started_drafts_do_not_block(): void {
        $this->resetAfterTest();

        $this->make_generation(self::OWNER, generation_status::STARTED, 'Draft one');
        $this->make_generation(self::OWNER, generation_status::STARTED, 'Draft two');
        $thirdid = $this->make_generation(self::OWNER, generation_status::STARTED, 'Draft three');

        $this->assertNull(generation_start_policy::find_running(self::OWNER, $thirdid));
    }

    /**
     * A finished generation does not block, in any of its three endings.
     *
     * @return void
     */
    public function test_terminal_statuses_do_not_block(): void {
        $this->resetAfterTest();

        foreach (generation_status::TERMINAL as $status) {
            $this->make_generation(self::OWNER, $status, 'Finished ' . $status);
        }
        $draftid = $this->make_generation(self::OWNER, generation_status::STARTED, 'The draft being started');

        $this->assertNull(generation_start_policy::find_running(self::OWNER, $draftid));
    }

    /**
     * The allowance is per person: a colleague's running generation does not block mine.
     *
     * @return void
     */
    public function test_a_colleagues_running_generation_does_not_block(): void {
        $this->resetAfterTest();

        $this->make_generation(self::COLLEAGUE, generation_status::GENERATING, "The colleague's run");
        $draftid = $this->make_generation(self::OWNER, generation_status::STARTED, 'My draft');

        $this->assertNull(generation_start_policy::find_running(self::OWNER, $draftid));
        // And the colleague really is blocked by their own - the fixture is not simply invisible.
        $this->assertNotNull(generation_start_policy::find_running(self::COLLEAGUE, 0));
    }

    /**
     * A generation is not blocked by itself.
     *
     * Belt and braces on the start path (only a 'started' generation ever reaches the check there),
     * But the rule is also readable on its own, and "my own row stops me" would be a trap for the
     * Next caller.
     *
     * @return void
     */
    public function test_a_generation_does_not_block_itself(): void {
        $this->resetAfterTest();

        $id = $this->make_generation(self::OWNER, generation_status::GENERATING, 'Only me');

        $this->assertNull(generation_start_policy::find_running(self::OWNER, $id));
        $this->assertNotNull(generation_start_policy::find_running(self::OWNER, 0));
    }

    /**
     * With more than one running (rows predating this rule), the oldest is the one named.
     *
     * @return void
     */
    public function test_the_oldest_running_generation_is_the_one_returned(): void {
        $this->resetAfterTest();

        $this->make_generation(self::OWNER, generation_status::SAVING, 'Newer', 3000);
        $oldestid = $this->make_generation(self::OWNER, generation_status::GENERATING, 'Older', 1000);
        $draftid = $this->make_generation(self::OWNER, generation_status::STARTED, 'The draft being started', 4000);

        $blocking = generation_start_policy::find_running(self::OWNER, $draftid);

        $this->assertNotNull($blocking);
        $this->assertSame($oldestid, (int) $blocking->id);
        $this->assertSame('Older', $blocking->name);
    }

    /**
     * Empty the generations table between the iterations of a data-driven test.
     *
     * @return void
     */
    private function reset_table(): void {
        global $DB;

        $DB->delete_records('local_artqtml_generations');
    }
}
