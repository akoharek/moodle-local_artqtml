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

namespace local_artqtml\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;

/**
 * Unit tests for the privacy provider (L-6).
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\privacy\provider
 */
final class provider_test extends \advanced_testcase {
    /**
     * Create a generation owned by the given user, with a question and a log row.
     *
     * @param int $userid
     * @param string $sourcetext
     * @return array{generationid: int, questionid: int, logid: int}
     */
    protected function make_generation(int $userid, string $sourcetext = 'A korte gyumolcs.'): array {
        global $DB;

        $generationid = (int) $DB->insert_record('local_artqtml_generations', (object) [
            'userid'       => $userid,
            'name'         => 'Teszt generalas',
            'shortname'    => 'TESZT',
            'sourcetext'   => $sourcetext,
            'status'       => 'completed',
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        $questionid = (int) $DB->insert_record('local_artqtml_questions', (object) [
            'generationid' => $generationid,
            'questiontype' => 'IH',
            'questiontext' => 'A korte gyumolcs?',
            'questiondata' => '{}',
            'timecreated'  => time(),
        ]);

        $logid = (int) $DB->insert_record('local_artqtml_log', (object) [
            'generationid' => $generationid,
            'userid'       => $userid,
            'event'        => 'ai_call_made',
            'provider'     => 'claude',
            'httpstatus'   => 200,
            'tokensinput'  => 1000,
            'tokensoutput' => 200,
            'result'       => 'success',
            'timecreated'  => time(),
        ]);

        return ['generationid' => $generationid, 'questionid' => $questionid, 'logid' => $logid];
    }

    /**
     * Deleting a user's data removes the generation and keeps the log row, without its user id.
     */
    public function test_deleting_a_user_keeps_the_log_row_and_drops_its_identity(): void {
        global $DB;

        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $made = $this->make_generation((int) $user->id);
        $context = \context_system::instance();

        provider::delete_data_for_user(new approved_contextlist(
            $user,
            'local_artqtml',
            [$context->id]
        ));

        // The material goes.
        $this->assertFalse($DB->record_exists('local_artqtml_generations', ['id' => $made['generationid']]));
        $this->assertFalse($DB->record_exists('local_artqtml_questions', ['id' => $made['questionid']]));

        // The log row stays -.
        $log = $DB->get_record('local_artqtml_log', ['id' => $made['logid']]);
        $this->assertNotFalse($log, 'The log row must survive the deletion .');

        // With the identifying link gone and the technical record intact.
        $this->assertNull($log->userid);
        $this->assertNull($log->generationid);
        $this->assertEquals($made['generationid'], $log->originalgenerationid);
        $this->assertEquals(200, $log->httpstatus);
        $this->assertEquals(1000, $log->tokensinput);
        $this->assertSame('success', $log->result);
    }

    /**
     * Another user's generation is untouched by the first user's deletion.
     *
     * The tool is site-wide, so one user's request must not take a colleague's material with it.
     */
    public function test_another_users_generation_is_untouched(): void {
        global $DB;

        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $mine = $this->make_generation((int) $user->id);
        $theirs = $this->make_generation((int) $other->id, 'Az alma gyumolcs.');

        provider::delete_data_for_user(new approved_contextlist(
            $user,
            'local_artqtml',
            [\context_system::instance()->id]
        ));

        $this->assertFalse($DB->record_exists('local_artqtml_generations', ['id' => $mine['generationid']]));
        $this->assertTrue($DB->record_exists('local_artqtml_generations', ['id' => $theirs['generationid']]));
    }

    /**
     * A user who only edited or approved somebody else's question loses their name from it, and the
     * Question stays.
     */
    public function test_an_editors_footprint_is_scrubbed_but_the_question_stays(): void {
        global $DB;

        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $editor = $this->getDataGenerator()->create_user();
        $made = $this->make_generation((int) $owner->id);

        $DB->set_field('local_artqtml_questions', 'lasteditedby', $editor->id, ['id' => $made['questionid']]);
        $DB->set_field('local_artqtml_questions', 'lasteditedat', time(), ['id' => $made['questionid']]);
        $DB->set_field('local_artqtml_questions', 'approvedby', $editor->id, ['id' => $made['questionid']]);

        provider::delete_data_for_user(new approved_contextlist(
            $editor,
            'local_artqtml',
            [\context_system::instance()->id]
        ));

        $question = $DB->get_record('local_artqtml_questions', ['id' => $made['questionid']]);
        $this->assertNotFalse($question, "The owner's question must survive the editor's request.");
        $this->assertNull($question->lasteditedby);
        $this->assertNull($question->lasteditedat);
        $this->assertNull($question->approvedby);
    }

    /**
     * The user list for the system context names the owner and the editor.
     *
     * Whoever is not named here never gets asked, so an omission is a silent failure to honour a
     * Request.
     */
    public function test_the_userlist_names_owner_and_editor(): void {
        global $DB;

        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $editor = $this->getDataGenerator()->create_user();
        $made = $this->make_generation((int) $owner->id);
        $DB->set_field('local_artqtml_questions', 'lasteditedby', $editor->id, ['id' => $made['questionid']]);

        $userlist = new userlist(\context_system::instance(), 'local_artqtml');
        provider::get_users_in_context($userlist);
        $found = $userlist->get_userids();

        $this->assertContains((int) $owner->id, $found);
        $this->assertContains((int) $editor->id, $found);
    }

    /**
     * Deleting every user in the context leaves the log rows behind, redacted.
     *
     * Same rule as the per-user path, checked separately because it is a different method with its
     * Own chance to reintroduce the delete.
     */
    public function test_deleting_the_whole_context_still_keeps_the_log(): void {
        global $DB;

        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $made = $this->make_generation((int) $user->id);

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertFalse($DB->record_exists('local_artqtml_generations', ['id' => $made['generationid']]));
        $this->assertTrue($DB->record_exists('local_artqtml_log', ['id' => $made['logid']]));
    }

    /**
     * The approved-userlist path deletes the named users and keeps the log rows.
     */
    public function test_the_approved_userlist_path_keeps_the_log(): void {
        global $DB;

        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $made = $this->make_generation((int) $user->id);

        $userlist = new approved_userlist(
            \context_system::instance(),
            'local_artqtml',
            [(int) $user->id]
        );
        provider::delete_data_for_users($userlist);

        $this->assertFalse($DB->record_exists('local_artqtml_generations', ['id' => $made['generationid']]));
        $this->assertTrue($DB->record_exists('local_artqtml_log', ['id' => $made['logid']]));
    }

    /**
     * AJAX rate-limit rows are deleted with the user's privacy data.
     */
    public function test_ajax_ratelimit_rows_are_deleted_for_user(): void {
        global $DB;

        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_artqtml_ajax_ratelimit', (object) [
            'userid' => $user->id,
            'action' => 'get_status',
            'windowstart' => time(),
            'hitcount' => 3,
        ]);

        provider::delete_data_for_user(new approved_contextlist(
            $user,
            'local_artqtml',
            [\context_system::instance()->id]
        ));

        $this->assertFalse($DB->record_exists('local_artqtml_ajax_ratelimit', ['userid' => $user->id]));
    }
}
