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
 * Unit tests for the draft-editing role.
 *
 * The point of this role is what it does NOT carry, so the assertions are mostly about absence:
 * The exact capability set, and no enrolment. A role that quietly grew a fourth capability would
 * Pass a "the Edit link works" test and still be the failure this class was written to avoid.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\draft_role
 */
final class draft_role_test extends \advanced_testcase {
    /**
     * Create a course and point the plugin's draft-course setting at it.
     *
     * @return \stdClass the course
     */
    protected function configure_draft_course(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        set_config('draftcourseid', $course->id, 'local_artqtml');

        return $course;
    }

    /**
     * The role is created once and looked up thereafter - the install step, the upgrade step and
     * Every grant() all call ensure_role(), so a second call must not produce a second role.
     */
    public function test_ensure_role_is_idempotent(): void {
        global $DB;

        $this->resetAfterTest();

        $first = draft_role::ensure_role();
        $second = draft_role::ensure_role();

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $DB->count_records('role', ['shortname' => draft_role::SHORTNAME]));
    }

    /**
     * The capability set is the whole point: two, and no more. The guard is against growth - a
     * third capability added "just to make something work" is how a narrow role becomes a broad
     * one, and it would pass every functional test.
     */
    public function test_the_role_grants_exactly_two_capabilities(): void {
        global $DB;

        $this->resetAfterTest();

        $roleid = draft_role::ensure_role();

        $granted = $DB->get_records_menu(
            'role_capabilities',
            ['roleid' => $roleid, 'permission' => CAP_ALLOW],
            'capability',
            'capability, permission'
        );

        $this->assertSame(
            ['moodle/course:view', 'moodle/question:useall'],
            array_keys($granted)
        );
        $this->assertArrayNotHasKey('moodle/question:editall', $granted);
        // Named individually as well, because these are the ones an editingteacher enrolment would
        // have brought along - the breadth this role exists instead of.
        $this->assertArrayNotHasKey('moodle/course:update', $granted);
        $this->assertArrayNotHasKey('moodle/course:manageactivities', $granted);
        $this->assertArrayNotHasKey('moodle/grade:edit', $granted);
        $this->assertArrayNotHasKey('moodle/course:enrolreview', $granted);
    }

    /**
     * A course context and nothing else: the role is only ever assigned on the draft course, and a
     * Role assignable at system level would be a different, much larger grant.
     */
    public function test_the_role_is_assignable_on_courses_only(): void {
        $this->resetAfterTest();

        $roleid = draft_role::ensure_role();

        // Cast with intval, because get_role_contextlevels() hands the levels back as they came out
        // Of the database - strings - while CONTEXT_COURSE is an int constant. Comparing them
        // Loosely would hide a genuine type change here, so the cast is explicit and assertSame
        // Stays.
        $levels = array_map('intval', array_values(get_role_contextlevels($roleid)));

        $this->assertSame([CONTEXT_COURSE], $levels);
    }

    public function test_grant_gives_the_capabilities_without_enrolling(): void {
        $this->resetAfterTest();

        $course = $this->configure_draft_course();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_course::instance($course->id);

        $this->assertTrue(draft_role::grant((int) $user->id));

        $this->assertTrue(has_capability('moodle/course:view', $context, $user));
        $this->assertTrue(has_capability('moodle/question:useall', $context, $user));
        $this->assertFalse(has_capability('moodle/question:editall', $context, $user));

        $this->assertFalse(has_capability('moodle/course:update', $context, $user));
        $this->assertFalse(is_enrolled($context, $user));
    }

    /**
     * Granting twice must not stack up role assignments - grant() runs on every generation, not
     * Just the first.
     */
    public function test_grant_does_not_duplicate_the_assignment(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->configure_draft_course();
        $user = $this->getDataGenerator()->create_user();

        draft_role::grant((int) $user->id);
        draft_role::grant((int) $user->id);

        $count = $DB->count_records('role_assignments', [
            'roleid'    => draft_role::ensure_role(),
            'userid'    => $user->id,
            'contextid' => \context_course::instance($course->id)->id,
        ]);

        $this->assertSame(1, $count);
    }

    /**
     * With no draft course configured there is nothing to assign against. Generation is blocked in
     * That state anyway; the point is that grant() says so instead of throwing.
     */
    public function test_grant_is_a_no_op_without_a_draft_course(): void {
        global $DB;

        $this->resetAfterTest();

        set_config('draftcourseid', 0, 'local_artqtml');
        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(draft_role::grant((int) $user->id));
        $this->assertSame(0, $DB->count_records('role_assignments', ['userid' => $user->id]));
    }

    /**
     * revoke_if_idle drops the assignment once the user has no draft work left.
     */
    public function test_revoke_if_idle_removes_assignment_when_no_draft_work(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->configure_draft_course();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_course::instance($course->id);

        $this->assertTrue(draft_role::grant((int) $user->id));
        $this->assertTrue(has_capability('moodle/question:useall', $context, $user));

        draft_role::revoke_if_idle((int) $user->id);

        $this->assertFalse(has_capability('moodle/question:useall', $context, $user));
    }

    /**
     * revoke_if_idle keeps the assignment while unmoved draft questions remain.
     */
    public function test_revoke_if_idle_keeps_assignment_while_draft_questions_remain(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->configure_draft_course();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_course::instance($course->id);

        draft_role::grant((int) $user->id);

        $generationid = (int) $DB->insert_record('local_artqtml_generations', (object) [
            'userid' => $user->id,
            'name' => 'Revoke fixture',
            'shortname' => 'REV',
            'status' => generation_status::COMPLETED,
            'draftcategoryid' => 99,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_artqtml_questions', (object) [
            'generationid' => $generationid,
            'questiontype' => 'truefalse',
            'questiontext' => 'Fixture',
            'questiondata' => '{}',
            'movedout' => 0,
            'timecreated' => time(),
        ]);

        draft_role::revoke_if_idle((int) $user->id);

        $this->assertTrue(has_capability('moodle/question:useall', $context, $user));
    }
}
