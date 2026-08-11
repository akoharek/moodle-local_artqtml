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

namespace local_artqtml\local\upgrade;

/**
 * Unit tests for the D-3 repair: draft categories left at parent = 0 by earlier versions.
 *
 * The invariant every case below checks is Moodle's own: a context has exactly one question
 * category with parent = 0. That is what question_get_top_category() relies on, and violating it
 * is what broke the demo site's question bank.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\upgrade\draft_category_reparent
 */
final class draft_category_reparent_test extends \advanced_testcase {
    /**
     * Insert a question category.
     *
     * @param int $contextid
     * @param int $parent
     * @param string $name
     * @return int the new question_categories.id
     */
    private function make_category(int $contextid, int $parent, string $name): int {
        global $DB;

        return (int) $DB->insert_record('question_categories', (object) [
            'name'        => $name,
            'contextid'   => $contextid,
            'info'        => '',
            'infoformat'  => FORMAT_HTML,
            'stamp'       => make_unique_id_code(),
            'parent'      => $parent,
            'sortorder'   => 999,
        ]);
    }

    /**
     * Insert a generation pointing at the given draft category.
     *
     * @param int|null $draftcategoryid
     * @return int the new local_artqtml_generations.id
     */
    private function make_generation(?int $draftcategoryid): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_artqtml_generations', (object) [
            'userid'          => 2,
            'name'            => 'Reparent fixture',
            'shortname'       => 'REPAR',
            'status'          => 'completed',
            'draftcategoryid' => $draftcategoryid,
            'timecreated'     => $now,
            'timemodified'    => $now,
        ]);
    }

    /**
     * How many parent = 0 rows a context has. One is correct; anything else is the defect.
     *
     * @param int $contextid
     * @return int
     */
    private function count_tops(int $contextid): int {
        global $DB;

        return $DB->count_records('question_categories', ['contextid' => $contextid, 'parent' => 0]);
    }

    /**
     * The common case seen on the demo: a real top already exists, and the draft category sits
     * beside it. The draft category must end up under that existing top - not become a new one,
     * and not be deleted.
     *
     * @return void
     */
    public function test_draft_category_is_reparented_under_the_existing_top(): void {
        global $DB;

        $this->resetAfterTest();

        $contextid = (int) \context_system::instance()->id;
        $top = $this->make_category($contextid, 0, 'top');
        $draft = $this->make_category($contextid, 0, 'AI draft: Demo (DEMO)');
        $this->make_generation($draft);

        $this->assertSame(2, $this->count_tops($contextid), 'precondition: the defect is present');

        $repaired = draft_category_reparent::run();

        $this->assertSame(1, $repaired);
        $this->assertSame(1, $this->count_tops($contextid));
        $this->assertSame($top, (int) $DB->get_field('question_categories', 'parent', ['id' => $draft]));
        $this->assertTrue($DB->record_exists('question_categories', ['id' => $draft]));
    }

    /**
     * The case draft_bank's docblock describes: no question category had ever been created in that
     * context, so the draft category became the context's top. A real top has to be created before
     * the draft one can hang under it.
     *
     * @return void
     */
    public function test_a_real_top_is_created_when_the_draft_category_was_the_only_one(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $contextid = (int) \context_course::instance($course->id)->id;
        $DB->delete_records('question_categories', ['contextid' => $contextid]);

        $draft = $this->make_category($contextid, 0, 'AI draft: Only (ONLY)');
        $this->make_generation($draft);

        $this->assertSame(1, $this->count_tops($contextid), 'precondition: the draft category is the only root');

        $this->assertSame(1, draft_category_reparent::run());

        $this->assertSame(1, $this->count_tops($contextid), 'still exactly one root afterwards');

        $newtopid = (int) $DB->get_field('question_categories', 'parent', ['id' => $draft]);
        $this->assertNotSame(0, $newtopid, 'the draft category must no longer be a root');
        $this->assertSame('top', $DB->get_field('question_categories', 'name', ['id' => $newtopid]));
        $this->assertSame($contextid, (int) $DB->get_field('question_categories', 'contextid', ['id' => $newtopid]));
    }

    /**
     * Two leftovers in one context: both must land under the same real top, and neither may be
     * mistaken for the other's top.
     *
     * @return void
     */
    public function test_two_leftovers_in_one_context_share_the_real_top(): void {
        global $DB;

        $this->resetAfterTest();

        $contextid = (int) \context_system::instance()->id;
        $top = $this->make_category($contextid, 0, 'top');
        $first = $this->make_category($contextid, 0, 'AI draft: One (ONE)');
        $second = $this->make_category($contextid, 0, 'AI draft: Two (TWO)');
        $this->make_generation($first);
        $this->make_generation($second);

        $this->assertSame(2, draft_category_reparent::run());

        $this->assertSame(1, $this->count_tops($contextid));
        $this->assertSame($top, (int) $DB->get_field('question_categories', 'parent', ['id' => $first]));
        $this->assertSame($top, (int) $DB->get_field('question_categories', 'parent', ['id' => $second]));
    }

    /**
     * A correctly nested draft category - what current code produces - must be left alone. Without
     * this, a repair that re-parented everything it found would still pass the cases above.
     *
     * @return void
     */
    public function test_correctly_nested_draft_categories_are_untouched(): void {
        global $DB;

        $this->resetAfterTest();

        $contextid = (int) \context_system::instance()->id;
        $top = $this->make_category($contextid, 0, 'top');
        $root = $this->make_category($contextid, $top, 'ArtQTML');
        $draft = $this->make_category($contextid, $root, 'AI draft: Fine (FINE)');
        $this->make_generation($draft);

        $this->assertSame(0, draft_category_reparent::run());

        $this->assertSame($root, (int) $DB->get_field('question_categories', 'parent', ['id' => $draft]));
        $this->assertSame(1, $this->count_tops($contextid));
    }

    /**
     * A generation with no draft category at all (never started, or already cleaned up) must not
     * make the repair touch anything.
     *
     * @return void
     */
    public function test_a_generation_without_a_draft_category_is_ignored(): void {
        $this->resetAfterTest();

        $this->make_generation(null);

        $this->assertSame(0, draft_category_reparent::run());
    }
}
