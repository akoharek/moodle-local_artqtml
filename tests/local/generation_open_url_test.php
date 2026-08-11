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
 * Unit tests for the status->destination rule (List-018) and its call sites.
 *
 * D-5: the rule states where a generation should be opened, given its current status. It exists
 * once, in generation_list::open_url(); the list page, upload.php's duplicate-warning panel and
 * the three event classes all read it from there. These tests pin the rule itself and the two
 * behaviours that are easiest to lose in a refactor: resolving from an id alone, and event links
 * reflecting the generation's status *now* rather than at the moment the event fired.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\generation_list::open_url
 * @covers     \local_artqtml\local\generation_list::open_url_by_id
 */
final class generation_open_url_test extends \advanced_testcase {
    /**
     * Insert a minimal generation row in the given status.
     *
     * @param string $status one of generation_status::VALUES
     * @return int the new local_artqtml_generations.id
     */
    private function make_generation(string $status): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_artqtml_generations', (object) [
            'userid'       => 2,
            'name'         => 'Open URL fixture ' . $status,
            'shortname'    => 'OPENURL',
            'status'       => $status,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Assert that a URL points at the given plugin script, carrying the given generation id.
     *
     * @param string $script e.g. 'approve.php'
     * @param string $param the id parameter that script expects
     * @param int $generationid
     * @param \moodle_url|null $url
     * @return void
     */
    private function assert_points_at(string $script, string $param, int $generationid, ?\moodle_url $url): void {
        $this->assertNotNull($url);
        $this->assertStringEndsWith('/local/artqtml/' . $script, $url->out_omit_querystring());
        $this->assertSame((string) $generationid, (string) $url->param($param));
    }

    /**
     * List-018: completed opens the approval page, the in-progress trio and failed open the
     * status page, and 'started' falls through to the settings page it can be resumed from.
     *
     * Deliberately keyed off generation_status::VALUES rather than a re-typed list, so a seventh
     * status cannot be added without this test forcing a decision about where it opens.
     *
     * @return void
     */
    public function test_every_status_has_a_destination(): void {
        $this->resetAfterTest();

        $expected = [
            generation_status::STARTED    => ['generate.php', 'id'],
            generation_status::GENERATING => ['status.php', 'generationid'],
            generation_status::VALIDATING => ['status.php', 'generationid'],
            generation_status::SAVING     => ['status.php', 'generationid'],
            generation_status::COMPLETED  => ['approve.php', 'generationid'],
            // BL-35: the status page, because that is the only page that states what is missing
            // and offers the button that asks for it again. The order here follows
            // generation_status::VALUES, which the assertion below enforces.
            generation_status::PARTIAL    => ['status.php', 'generationid'],
            generation_status::FAILED     => ['status.php', 'generationid'],
        ];

        $this->assertSame(
            generation_status::VALUES,
            array_keys($expected),
            'every status must have a stated destination, in the same order as the single source'
        );

        foreach ($expected as $status => [$script, $param]) {
            $id = $this->make_generation($status);
            $this->assert_points_at($script, $param, $id, generation_list::open_url_by_id($id));
        }
    }

    /**
     * A log entry outlives the generation it refers to. Returning null - rather than a link to a
     * page that would fail - is what lets core's log report render the entry unlinked.
     *
     * @return void
     */
    public function test_open_url_by_id_is_null_for_a_deleted_generation(): void {
        global $DB;

        $this->resetAfterTest();

        $id = $this->make_generation(generation_status::COMPLETED);
        $DB->delete_records('local_artqtml_generations', ['id' => $id]);

        $this->assertNull(generation_list::open_url_by_id($id));
    }

    /**
     * The regression this pins: an event's link must lead where the generation can be acted on
     * now, not to the page that was relevant when the event fired. A generation_started event
     * read after the generation has completed must open the approval page.
     *
     * @return void
     */
    public function test_event_links_follow_the_current_status(): void {
        global $DB;

        $this->resetAfterTest();

        $id = $this->make_generation(generation_status::STARTED);
        $context = \context_system::instance();

        $event = \local_artqtml\event\generation_started::create([
            'objectid' => $id,
            'context'  => $context,
        ]);

        // While it is still 'started', the settings page is where it can be resumed.
        $this->assert_points_at('generate.php', 'id', $id, $event->get_url());

        // The same event, read after the generation finished, must now point at the approval page.
        $DB->set_field('local_artqtml_generations', 'status', generation_status::COMPLETED, ['id' => $id]);

        $this->assert_points_at('approve.php', 'generationid', $id, $event->get_url());
    }

    /**
     * The other two events resolve through the same helper, so neither can quietly go back to a
     * fixed destination of its own.
     *
     * @return void
     */
    public function test_completed_and_aborted_events_use_the_same_rule(): void {
        $this->resetAfterTest();

        $id = $this->make_generation(generation_status::COMPLETED);
        $context = \context_system::instance();

        foreach (['generation_completed', 'generation_aborted'] as $eventclass) {
            $class = '\\local_artqtml\\event\\' . $eventclass;
            $event = $class::create(['objectid' => $id, 'context' => $context]);

            $this->assert_points_at('approve.php', 'generationid', $id, $event->get_url());
        }
    }
}
