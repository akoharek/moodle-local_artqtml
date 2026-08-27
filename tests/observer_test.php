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

namespace local_artqtml;

/**
 * Unit tests for the question-save observer.
 *
 * The defect these were written for was not in the observer's body - that was correct all along.
 * It was in which event the plugin listened to. Moodle 4.x versions questions, so an editor save
 * Is a *creation*: question_type::save_question() fires question_created every time, and the two
 * Places core fires question_updated are the draft/ready switch and the bank list's inline rename.
 * Db/events.php subscribed to question_updated alone, so this observer had never run on a real
 * Edit - and nothing said so, because nothing tested the wiring.
 *
 * Measured cost of that gap on 2026-08-02: the stored question id was never re-pointed, so the
 * Approve page's Edit link kept opening the pre-edit version, and saving from there built a new
 * Current version out of stale text - losing the edit before it.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\observer::question_saved
 */
final class observer_test extends \advanced_testcase {
    /** @var int question category used by the fixtures. */
    private int $categoryid;

    /** @var int question_bank_entries.id shared by every version in one fixture. */
    private int $entryid;

    /**
     * A generation row to hang the questions off.
     *
     * @return int local_artqtml_generations.id
     */
    private function make_generation(): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_artqtml_generations', (object) [
            'userid'       => 2,
            'name'         => 'Observer fixture',
            'shortname'    => 'OBS',
            'status'       => local\generation_status::COMPLETED,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Register a question id as a version of this fixture's bank entry.
     *
     * @param int $questionid the (fictional) question id
     * @param int $version 1 for the original, 2 for the first edit, and so on
     * @return void
     */
    private function add_version(int $questionid, int $version): void {
        global $DB;

        $DB->insert_record('question_versions', (object) [
            'questionbankentryid' => $this->entryid,
            'version'             => $version,
            'questionid'          => $questionid,
            'status'              => 'ready',
        ]);
    }

    /**
     * A plugin question row in the state a validated, approved question is in.
     *
     * @param int $generationid
     * @param int $questionbankid the question id this row currently points at
     * @return int local_artqtml_questions.id
     */
    private function make_question(int $generationid, int $questionbankid): int {
        global $DB;

        return (int) $DB->insert_record('local_artqtml_questions', (object) [
            'generationid'         => $generationid,
            'questioncode'         => 'OBS-IH-0001',
            'typecode'             => 'IH',
            'questiontype'         => 'truefalse',
            'questiontext'         => 'Az eredeti, AI által írt kérdésszöveg.',
            'difficultylabel'      => 'Könnyű',
            'questiondata'         => json_encode(['type' => 'IH', 'correctanswer' => true]),
            'validationsuggestion' => local\validation_suggestion::ACCEPTED,
            'problemcategory'      => 'ok',
            'justification'        => 'A validátor ítélete az EREDETI szövegről.',
            'confidence'           => 92,
            'validationdata'       => json_encode(['verdict' => 'accepted']),
            'questionbankid'       => $questionbankid,
            'movedout'             => 0,
            'approved'             => 1,
            'approvedby'           => 2,
            'edited'               => 0,
            'timecreated'          => time(),
        ]);
    }

    /**
     * The core event a save produces, built the way core builds it.
     *
     * @param int $questionid the id of the version this save produced
     * @return \core\event\question_created
     */
    private function save_event(int $questionid): \core\event\question_created {
        return \core\event\question_created::create([
            'objectid' => $questionid,
            'context'  => \context_system::instance(),
            'other'    => ['categoryid' => $this->categoryid],
        ]);
    }

    /**
     * Shared fixture: a question category and one bank entry to version against.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $DB;
        $category = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question_category();
        $this->categoryid = (int) $category->id;
        $this->entryid = (int) $DB->insert_record('question_bank_entries', (object) [
            'questioncategoryid' => $this->categoryid,
            'ownerid'            => 2,
        ]);
    }

    /**
     * The wiring itself: the plugin must listen to the event an editor save actually fires.
     */
    public function test_the_event_an_editor_save_fires_is_subscribed(): void {
        $observers = [];
        include(__DIR__ . '/../db/events.php');

        $subscribed = array_column($observers, 'eventname');

        $this->assertContains(
            '\core\event\question_created',
            $subscribed,
            'Moodle versions questions, so an editor save fires question_created, not '
                . 'question_updated. Subscribing to question_updated alone means the observer '
                . 'never runs on a real edit - see .'
        );
        $this->assertContains('\core\event\question_updated', $subscribed);

        foreach ($observers as $observer) {
            $this->assertTrue(
                is_callable($observer['callback']),
                "the observer callback {$observer['callback']} does not exist"
            );
        }
    }

    /**
     * A teacher's save produces a new version, and the stored row must follow it.
     */
    public function test_a_new_version_repoints_the_row_and_invalidates_the_verdict(): void {
        global $DB;

        $generationid = $this->make_generation();
        $this->add_version(1001, 1);
        $rowid = $this->make_question($generationid, 1001);

        // The save: a second version, with an id of its own.
        $this->add_version(1002, 2);
        observer::question_saved($this->save_event(1002));

        $row = $DB->get_record('local_artqtml_questions', ['id' => $rowid]);

        $this->assertSame(1002, (int) $row->questionbankid, 'the stored id must follow the save');
        $this->assertSame(
            local\validation_suggestion::NOT_EVALUATED,
            $row->validationsuggestion,
            'a verdict about the previous text must not survive the edit'
        );
        $this->assertNull($row->justification);
        $this->assertNull($row->validationdata);
        $this->assertSame(0, (int) $row->approved, 'an edit revokes the approval');
        $this->assertNull($row->approvedby);
        $this->assertSame(1, (int) $row->edited);
        $this->assertSame(1, (int) $row->externallyedited);
        $this->assertNotEmpty($row->lasteditedat);

        $this->assertTrue(
            $DB->record_exists('local_artqtml_log', [
                'generationid' => $generationid,
                'event'        => 'question_edited',
            ]),
            'the edit must leave a log row - that is what makes it answerable afterwards'
        );
    }

    /**
     * The plugin's own generation-time creation must not be mistaken for a teacher's edit.
     *
     * Save_questions_task creates the Moodle question first and inserts the plugin row after, so
     * At the moment the event fires there is nothing to find - but the whole save runs in one
     * Transaction and Moodle holds external observers back until it commits, by which time the row
     * Is there. The discriminator is the stored id: on our own creation it IS this question.
     */
    public function test_the_generation_time_creation_is_not_an_edit(): void {
        global $DB;

        $generationid = $this->make_generation();
        $this->add_version(2001, 1);
        $rowid = $this->make_question($generationid, 2001);

        observer::question_saved($this->save_event(2001));

        $row = $DB->get_record('local_artqtml_questions', ['id' => $rowid]);

        $this->assertSame(2001, (int) $row->questionbankid);
        $this->assertSame(local\validation_suggestion::ACCEPTED, $row->validationsuggestion);
        $this->assertSame(1, (int) $row->approved, 'the plugin\'s own insert must not revoke anything');
        $this->assertSame(0, (int) $row->edited);
        $this->assertFalse($DB->record_exists('local_artqtml_log', ['event' => 'question_edited']));
    }

    /**
     * A question already moved into a real bank has left the approval workflow.
     */
    public function test_a_moved_out_question_is_left_alone(): void {
        global $DB;

        $generationid = $this->make_generation();
        $this->add_version(3001, 1);
        $rowid = $this->make_question($generationid, 3001);
        $DB->set_field('local_artqtml_questions', 'movedout', 1, ['id' => $rowid]);

        $this->add_version(3002, 2);
        observer::question_saved($this->save_event(3002));

        $row = $DB->get_record('local_artqtml_questions', ['id' => $rowid]);

        $this->assertSame(3001, (int) $row->questionbankid);
        $this->assertSame(local\validation_suggestion::ACCEPTED, $row->validationsuggestion);
        $this->assertSame(0, (int) $row->edited);
    }

    /**
     * A question belonging to nobody here must not be touched, and must not throw.
     */
    public function test_a_question_that_is_not_ours_is_ignored(): void {
        global $DB;

        observer::question_saved($this->save_event(9999));

        $this->assertSame(0, $DB->count_records('local_artqtml_questions'));
    }
}
