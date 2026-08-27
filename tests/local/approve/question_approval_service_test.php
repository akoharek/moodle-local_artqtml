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

namespace local_artqtml\local\approve;

/**
 * Unit tests for approval and its revocation.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\approve\question_approval_service
 */
final class question_approval_service_test extends \advanced_testcase {
    /**
     * Insert one generation plus one question row in a given state.
     *
     * @param array<string,mixed> $overrides columns to override on the question row
     * @return array{0:int,1:int} [generationid, questionid]
     */
    protected function seed_question(array $overrides = []): array {
        global $DB, $USER;

        $generation = (object) [
            'userid'         => (int) $USER->id,
            'name'           => 'Approval service fixture',
            'shortname'      => 'PHPUAS',
            'sourcetext'     => 'Source text for the approval service test.',
            'sourcetexthash' => sha1('Source text for the approval service test.'),
            'status'         => \local_artqtml\local\generation_status::COMPLETED,
            'settings'       => json_encode(['knowledgesource' => 'sourceonly']),
            'timecreated'    => time(),
            'timemodified'   => time(),
        ];
        $generationid = $DB->insert_record('local_artqtml_generations', $generation);

        $question = (object) array_merge([
            'generationid'         => $generationid,
            'questioncode'         => 'PHPUAS-IH-0001',
            'typecode'             => 'IH',
            'questiontype'         => 'truefalse',
            'questiontext'         => 'Moodle is open-source software.',
            'difficultylabel'      => 'Medium',
            'questiondata'         => json_encode(['correctanswer' => true]),
            'validationsuggestion' => \local_artqtml\local\validation_suggestion::NEEDS_REVIEW,
            'problemcategory'      => \local_artqtml\local\problem_category::VALUES[2],
            'justification'        => 'Wording could be tighter.',
            'confidence'           => 60,
            'validationdata'       => json_encode(['suggestion' => 'needs_review']),
            'questionbankid'       => null,
            'movedout'             => 0,
            'approved'             => 0,
            'approvedby'           => null,
            'edited'               => 0,
            'timecreated'          => time(),
        ], $overrides);

        return [(int) $generationid, (int) $DB->insert_record('local_artqtml_questions', $question)];
    }

    public function test_revoke_clears_only_the_approval_columns(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$generationid, $questionid] = $this->seed_question();
        $context = \context_system::instance();

        $this->assertTrue(question_approval_service::approve_single($questionid, $generationid, (int) $USER->id, $context));
        $approved = $DB->get_record('local_artqtml_questions', ['id' => $questionid], '*', MUST_EXIST);
        $this->assertEquals(1, $approved->approved);
        $this->assertEquals((int) $USER->id, (int) $approved->approvedby);

        $sink = $this->redirectEvents();
        $this->assertTrue(question_approval_service::revoke_single($questionid, $generationid, $context));
        $events = $sink->get_events();
        $sink->close();

        $revoked = $DB->get_record('local_artqtml_questions', ['id' => $questionid], '*', MUST_EXIST);
        $this->assertEquals(0, $revoked->approved);
        $this->assertNull($revoked->approvedby);

        // Everything the validator wrote is untouched.
        $this->assertSame($approved->validationsuggestion, $revoked->validationsuggestion);
        $this->assertSame($approved->problemcategory, $revoked->problemcategory);
        $this->assertSame($approved->justification, $revoked->justification);
        $this->assertEquals($approved->confidence, $revoked->confidence);
        $this->assertSame($approved->validationdata, $revoked->validationdata);
        $this->assertEquals($approved->edited, $revoked->edited);
        $this->assertEquals($approved->movedout, $revoked->movedout);

        // Keeps the workflow steps separately auditable.
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\local_artqtml\event\question_approval_revoked::class, $events[0]);
        $this->assertEquals($questionid, $events[0]->objectid);
    }

    public function test_revoke_is_a_noop_outside_the_approved_state(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $context = \context_system::instance();

        [$generationid, $unapprovedid] = $this->seed_question();
        $this->assertFalse(question_approval_service::revoke_single($unapprovedid, $generationid, $context));

        [$movedgenerationid, $movedid] = $this->seed_question(['movedout' => 1, 'approved' => 1, 'approvedby' => (int) $USER->id]);
        $this->assertFalse(question_approval_service::revoke_single($movedid, $movedgenerationid, $context));
        $this->assertEquals(1, $DB->get_field('local_artqtml_questions', 'approved', ['id' => $movedid]));

        // A question id from a different generation is never touched, even if it is approvable.
        [, $otherid] = $this->seed_question(['approved' => 1, 'approvedby' => (int) $USER->id]);
        $this->assertFalse(question_approval_service::revoke_single($otherid, $generationid, $context));
        $this->assertEquals(1, $DB->get_field('local_artqtml_questions', 'approved', ['id' => $otherid]));
    }

    /**
     * A moved question is not deletable row-by-row, and its generation reports that it holds one.
     */
    public function test_moved_questions_are_not_deletable_and_block_generation_deletion(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $context = \context_system::instance();

        [$generationid, $movedid] = $this->seed_question(['movedout' => 1, 'approved' => 1, 'approvedby' => (int) $USER->id]);

        $this->assertFalse(question_deletion_service::delete_single($movedid, $generationid, $context));
        $this->assertTrue($DB->record_exists('local_artqtml_questions', ['id' => $movedid]));

        $this->assertTrue(question_deletion_service::has_moved_questions($generationid));

        [$cleangenerationid] = $this->seed_question();
        $this->assertFalse(question_deletion_service::has_moved_questions($cleangenerationid));
    }
}
