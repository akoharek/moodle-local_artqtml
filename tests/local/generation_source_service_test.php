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
 * Unit tests for the generation source write path.
 *
 * The test that matters most here is the stale one: a form opened on a draft and submitted after
 * The generation started running. That case is why the write moved out of upload.php in the first
 * Place - as a function declared inside a controller it could not be called at all.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\generation_source_service
 */
final class generation_source_service_test extends \advanced_testcase {
    /**
     * Insert a generation row directly, in a given status.
     *
     * @param string $status
     * @param int $userid
     * @return \stdClass the stored record
     */
    protected function make_generation(string $status, int $userid = 0): \stdClass {
        global $DB, $USER;

        $record = (object) [
            'userid'         => $userid > 0 ? $userid : (int) $USER->id,
            'name'           => 'Original name',
            'shortname'      => 'ORIG',
            'sourcetext'     => 'The original source text.',
            'sourcetexthash' => duplicate_detector::hash('The original source text.'),
            'sourcefilehash' => 'originalfilehash',
            'status'         => $status,
            'timecreated'    => 1000,
            'timemodified'   => 1000,
        ];
        $record->id = $DB->insert_record('local_artqtml_generations', $record);

        return $record;
    }

    /**
     * A new generation is created as a draft.
     */
    public function test_a_new_generation_is_created_as_a_draft(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $id = generation_source_service::save('Name', 'SHORT', 'Some text.', 0, 'filehash', 7);

        $record = $DB->get_record('local_artqtml_generations', ['id' => $id], '*', MUST_EXIST);

        $this->assertSame(generation_status::STARTED, $record->status);
        $this->assertSame('Name', $record->name);
        $this->assertSame(7, (int) $record->userid);
        $this->assertSame(duplicate_detector::hash('Some text.'), $record->sourcetexthash);
        $this->assertSame('filehash', $record->sourcefilehash);
    }

    /**
     * A draft's name, short name, source text and both hashes are all updated.
     */
    public function test_a_draft_is_updated_in_full(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $before = $this->make_generation(generation_status::STARTED);

        generation_source_service::save('New name', 'NEWSN', 'Replacement text.', (int) $before->id, 'newhash', 1);

        $after = $DB->get_record('local_artqtml_generations', ['id' => $before->id], '*', MUST_EXIST);

        $this->assertSame('New name', $after->name);
        $this->assertSame('NEWSN', $after->shortname);
        $this->assertSame('Replacement text.', $after->sourcetext);
        $this->assertSame(duplicate_detector::hash('Replacement text.'), $after->sourcetexthash);
        $this->assertSame('newhash', $after->sourcefilehash);
        $this->assertGreaterThan((int) $before->timemodified, (int) $after->timemodified);
        $this->assertSame(generation_status::STARTED, $after->status);
    }

    /**
     * Every non-draft status is refused, and NOTHING is written.
     *
     * The second half is the assertion that matters. A refusal that had already changed three
     * Fields before throwing would be worse than no refusal at all - the record would be
     * Half-rewritten with nothing on screen to say so.
     *
     * @dataProvider non_draft_status_provider
     * @param string $status
     */
    public function test_a_non_draft_generation_is_refused_and_untouched(string $status): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $before = $this->make_generation($status);

        try {
            generation_source_service::save('Hijacked', 'HIJACK', 'Replacement text.', (int) $before->id, 'newhash', 1);
            $this->fail("saving a '$status' generation must throw");
        } catch (\moodle_exception $e) {
            $this->assertSame('cannoteditsourcenondraft', $e->errorcode);
        }

        $after = $DB->get_record('local_artqtml_generations', ['id' => $before->id], '*', MUST_EXIST);

        $this->assertSame($before->name, $after->name);
        $this->assertSame($before->shortname, $after->shortname);
        $this->assertSame($before->sourcetext, $after->sourcetext);
        $this->assertSame($before->sourcetexthash, $after->sourcetexthash);
        $this->assertSame($before->sourcefilehash, $after->sourcefilehash);
        $this->assertSame((int) $before->timemodified, (int) $after->timemodified);
    }

    /**
     * Every status except the draft one.
     *
     * @return array<string, array{string}>
     */
    public static function non_draft_status_provider(): array {
        $cases = [];
        foreach (generation_status::VALUES as $status) {
            if ($status !== generation_status::STARTED) {
                $cases[$status] = [$status];
            }
        }

        return $cases;
    }

    /**
     * The stale form: a draft that started running while the form was open.
     *
     * This is the sequence the fix exists for, and it needs no attacker - the tool is site-wide,
     * So the second tab can belong to a different teacher. Modelled by changing the status between
     * "the form was built" and "the form was submitted", which is exactly what a race does, minus
     * The timing.
     *
     * Without the re-read inside save(), step 4 rewrites the source text of a generation Claude is
     * Reading at that moment - and which Gemini will read again afterwards to judge the questions
     * Against it.
     */
    public function test_a_form_submitted_after_the_generation_started_writes_nothing(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // 1-2. The record is a draft when the form is opened and filled in.
        $before = $this->make_generation(generation_status::STARTED);

        // 3. Another tab starts the generation.
        $DB->set_field('local_artqtml_generations', 'status', generation_status::GENERATING, ['id' => $before->id]);

        // 4. The first tab submits.
        try {
            generation_source_service::save('Stale', 'STALE', 'Stale text.', (int) $before->id, null, 1);
            $this->fail('a stale form must not write');
        } catch (\moodle_exception $e) {
            $this->assertSame('cannoteditsourcenondraft', $e->errorcode);
        }

        $after = $DB->get_record('local_artqtml_generations', ['id' => $before->id], '*', MUST_EXIST);

        $this->assertSame($before->sourcetext, $after->sourcetext);
        $this->assertSame($before->sourcetexthash, $after->sourcetexthash);
        $this->assertSame($before->name, $after->name);
    }

    /**
     * The same for the duplicate-confirmation path.
     *
     * That path carries the generation id through the session, from one request to the next. The
     * Session remembers which generation was being edited; it cannot remember what state it is in
     * Now, and it must not be trusted to.
     */
    public function test_a_stale_duplicate_confirmation_writes_nothing(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $before = $this->make_generation(generation_status::STARTED);

        // The pending data was prepared while the generation was a draft.
        $pending = ['name' => 'Confirmed', 'shortname' => 'CONF', 'sourcetext' => 'Confirmed text.'];

        $DB->set_field('local_artqtml_generations', 'status', generation_status::COMPLETED, ['id' => $before->id]);

        try {
            generation_source_service::save(
                $pending['name'],
                $pending['shortname'],
                $pending['sourcetext'],
                (int) $before->id,
                null,
                1
            );
            $this->fail('a stale confirmation must not write');
        } catch (\moodle_exception $e) {
            $this->assertSame('cannoteditsourcenondraft', $e->errorcode);
        }

        $after = $DB->get_record('local_artqtml_generations', ['id' => $before->id], '*', MUST_EXIST);

        $this->assertSame($before->sourcetext, $after->sourcetext);
        $this->assertSame($before->name, $after->name);
    }

    /**
     * A colleague's draft is still editable - the refusal is about status, never about ownership.
     */
    public function test_a_colleagues_draft_is_still_editable(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $other = $this->getDataGenerator()->create_user();
        $before = $this->make_generation(generation_status::STARTED, (int) $other->id);

        generation_source_service::save('Edited by a colleague', 'COLL', 'New text.', (int) $before->id, null, 1);

        $after = $DB->get_record('local_artqtml_generations', ['id' => $before->id], '*', MUST_EXIST);

        $this->assertSame('Edited by a colleague', $after->name);
        // And the owner is not silently reassigned to whoever edited it.
        $this->assertSame((int) $other->id, (int) $after->userid);
    }
}
