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
 * Pins: deleting a generation must NOT delete its diagnostic log rows.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\generation_deletion
 */
final class generation_deletion_test extends \advanced_testcase {
    public function test_deleting_a_generation_keeps_its_log_rows(): void {
        global $DB;
        $this->resetAfterTest();

        $userid = $this->getDataGenerator()->create_user()->id;
        $now = time();

        // No draftcategoryid: a generation that failed before building a draft bank is a real state,
        // And it keeps the test off question-bank setup - the point here is the log, not the bank.
        $generationid = (int) $DB->insert_record('local_artqtml_generations', (object) [
            'userid'       => $userid,
            'name'         => 'Log retention test',
            'shortname'    => 'logret',
            'status'       => generation_status::FAILED,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);

        foreach (['ih', 'fe'] as $typecode) {
            $DB->insert_record('local_artqtml_questions', (object) [
                'generationid'         => $generationid,
                'typecode'             => $typecode,
                'questiontype'         => 'truefalse',
                'validationsuggestion' => 'not_evaluated',
                'movedout'             => 0,
                'approved'             => 0,
                'edited'               => 0,
                'timecreated'          => $now,
            ]);
        }

        foreach (['ai_call_made', 'ai_call_failed'] as $event) {
            $DB->insert_record('local_artqtml_log', (object) [
                'generationid' => $generationid,
                'userid'       => $userid,
                'event'        => $event,
                'isretry'      => 0,
                'timecreated'  => $now,
            ]);
        }

        // A second generation whose log row must be left untouched - purge() targets one generation.
        $othergenid = (int) $DB->insert_record('local_artqtml_generations', (object) [
            'userid'       => $userid,
            'name'         => 'Other',
            'shortname'    => 'other',
            'status'       => generation_status::COMPLETED,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_artqtml_log', (object) [
            'generationid' => $othergenid,
            'userid'       => $userid,
            'event'        => 'ai_call_made',
            'isretry'      => 0,
            'timecreated'  => $now,
        ]);

        // Preconditions.
        $this->assertEquals(2, $DB->count_records('local_artqtml_questions', ['generationid' => $generationid]));
        $this->assertEquals(2, $DB->count_records('local_artqtml_log', ['generationid' => $generationid]));

        // Exercise the production deletion path.
        generation_deletion::purge($generationid);

        // 1. The generation row is gone.
        $this->assertFalse($DB->record_exists('local_artqtml_generations', ['id' => $generationid]));
        // 2. Its question rows are gone.
        $this->assertEquals(0, $DB->count_records('local_artqtml_questions', ['generationid' => $generationid]));
        $this->assertEquals(0, $DB->count_records('local_artqtml_log', ['generationid' => $generationid]));
        $this->assertEquals(2, $DB->count_records('local_artqtml_log', ['originalgenerationid' => $generationid]));

        // The other generation's log row was not touched - purge() targets one generation.
        $this->assertEquals(1, $DB->count_records('local_artqtml_log', ['generationid' => $othergenid]));
        $this->assertEquals(0, $DB->count_records('local_artqtml_log', ['originalgenerationid' => $othergenid]));

        // 4. The user id survives an ORDINARY deletion. This is not a data subject request, and
        // The entries have to stay reachable in that user's own GDPR export - which is exactly what
        // They were not, before the export stopped looking for them through the generations
        // Table.
        $this->assertEquals(
            0,
            $DB->count_records_select(
                'local_artqtml_log',
                'originalgenerationid = :gid AND userid IS NULL',
                ['gid' => $generationid]
            ),
            'an ordinary deletion must not anonymise the log - that is what a GDPR request does'
        );

        // 5. And the diagnostic payload is NOT redacted early. Deleting a generation is often the
        // Last step of investigating it, not the end of the investigation; the payload goes when
        // Its retention period ends.
        $remaining = $DB->get_records('local_artqtml_log', ['originalgenerationid' => $generationid]);
        foreach ($remaining as $entry) {
            $this->assertStringNotContainsString('payloadredacted', (string) $entry->data);
        }
    }
}
