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

use local_artqtml\local\approve\question_approval_service;
use local_artqtml\local\approve\question_move_service;

/**
 * Locked draft question behaviour.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\draft_question_lock
 */
final class draft_question_lock_test extends \advanced_testcase {
    /** @var \context_system */
    private $context;

    /**
     * Fresh context for each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->context = \context_system::instance();
    }

    /**
     * Insert a generation owned by the current user.
     *
     * @param int $userid
     * @return int generation id
     */
    private function make_generation(int $userid): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_artqtml_generations', (object) [
            'userid'       => $userid,
            'name'         => 'Lock fixture',
            'shortname'    => 'LOCK',
            'status'       => generation_status::COMPLETED,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Insert one draft question row.
     *
     * @param int $generationid parent generation id
     * @param array $overrides columns to override on the question row
     * @return int question row id
     */
    private function make_question(int $generationid, array $overrides = []): int {
        global $DB;

        $record = (object) array_merge([
            'generationid'         => $generationid,
            'questioncode'         => 'LOCK-IH-0001',
            'typecode'             => 'IH',
            'questiontype'         => 'truefalse',
            'questiontext'         => 'Fixture',
            'validationsuggestion' => validation_suggestion::ACCEPTED,
            'questionbankid'       => 0,
            'movedout'             => 0,
            'approved'             => 0,
            'edited'               => 0,
            'externallyedited'     => 0,
            'timecreated'          => time(),
        ], $overrides);

        return (int) $DB->insert_record('local_artqtml_questions', $record);
    }

    /**
     * Grant :use to a user at system context.
     *
     * @param \stdClass $user
     * @return void
     */
    private function grant_use(\stdClass $user): void {
        $roleid = create_role('artqtm lock test ' . $user->id, 'artqtmlk' . $user->id, '');
        assign_capability('local/artqtml:use', CAP_ALLOW, $roleid, $this->context->id);
        role_assign($roleid, $user->id, $this->context->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Locked rows are detected by the helper.
     */
    public function test_is_locked_reads_externallyedited_flag(): void {
        $row = (object) ['externallyedited' => 1];
        $this->assertTrue(draft_question_lock::is_locked($row));
        $this->assertFalse(draft_question_lock::is_locked((object) ['externallyedited' => 0]));
    }

    /**
     * Approve and move services skip locked rows.
     */
    public function test_locked_row_blocks_approve_and_move(): void {
        global $DB;

        $owner = $this->getDataGenerator()->create_user();
        $this->grant_use($owner);
        $this->setUser($owner);

        $generationid = $this->make_generation((int) $owner->id);
        $questionid = $this->make_question($generationid, [
            'approved'         => 1,
            'externallyedited' => 1,
        ]);

        $this->assertFalse(question_approval_service::approve_single(
            $questionid,
            $generationid,
            (int) $owner->id,
            $this->context
        ));

        $result = question_move_service::move_selected(
            [$questionid],
            $generationid,
            '1,1',
            $this->context
        );
        $this->assertSame(0, $result['moved']);
    }

    /**
     * Whole generation delete remains allowed when it only contains locked draft questions.
     */
    public function test_generation_delete_allowed_with_locked_questions(): void {
        $owner = $this->getDataGenerator()->create_user();
        $this->grant_use($owner);
        $this->setUser($owner);

        $generationid = $this->make_generation((int) $owner->id);
        $this->make_question($generationid, ['externallyedited' => 1]);

        $generation = (object) ['userid' => (int) $owner->id];
        $this->assertTrue(generation_delete_policy::can_delete($generation, null, $this->context));
        $this->assertFalse(
            \local_artqtml\local\approve\question_deletion_service::has_moved_questions($generationid)
        );

        generation_deletion::purge($generationid);
        global $DB;
        $this->assertFalse($DB->record_exists('local_artqtml_generations', ['id' => $generationid]));
    }
}
