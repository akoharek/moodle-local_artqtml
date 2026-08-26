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

defined('MOODLE_INTERNAL') || die();

/**
 * The draft root category must survive a change of interface language.
 *
 * The defect this guards, found on 2026-07-31 by switching the site to Hungarian and starting a
 * Generation. `get_root_category_id()` looked the shared root category up by its **name**, which
 * Is a lang string: created on an English site it read "ArtQTML", and the Hungarian
 * Lookup asked for "ArtQTML". Finding nothing, the code inserted a new root carrying the
 * Same fixed idnumber - and `question_categories` has a unique index on (contextid, idnumber), so
 * The write was rejected.
 *
 * What the user saw was "Error writing to database" on starting any generation, with nothing on
 * Screen connecting it to the language switch. The plugin had lost track of a category it created
 * Itself, because the handle it searched on was translated.
 *
 * Nothing else would catch this. Every test and every manual run had used one language throughout,
 * So the lookup always matched. It only appears when a site's language changes *after* the root
 * Category exists - which for a customer is a normal Thursday, not an edge case.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\draft_bank
 */
final class draft_bank_language_test extends \advanced_testcase {
    /**
     * Clear the per-request cache so a second call really hits the database.
     */
    private function forget_cached_root(): void {
        $property = new \ReflectionProperty(draft_bank::class, 'rootcategoryid');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    /**
     * The same root category is found in English and in Hungarian, and no second one is created.
     */
    public function test_the_root_category_survives_a_language_change(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        set_config('draftcourseid', $course->id, 'local_artqtml');

        force_current_language('en');
        $this->forget_cached_root();
        $first = draft_bank::get_root_category_id();
        $this->assertGreaterThan(0, $first);

        force_current_language('hu');
        $this->forget_cached_root();
        $second = draft_bank::get_root_category_id();

        $this->assertSame(
            $first,
            $second,
            'the root category was not found again after the interface language changed - the '
                . 'lookup is matching on something translated'
        );

        $this->assertSame(
            1,
            $DB->count_records('question_categories', ['idnumber' => draft_bank::ROOT_IDNUMBER]),
            'a second root category was created. In production this write fails outright against '
                . 'the unique (contextid, idnumber) index, and the user is shown a database error '
                . 'when they try to start a generation.'
        );
    }

    /**
     * A root created before the idnumber existed is adopted, not duplicated.
     *
     * Sites that ran an earlier version have a root category with no idnumber, under whatever name
     * Their language gave it. The fix has to take that one over rather than leave it orphaned and
     * Build a second beside it.
     */
    public function test_a_legacy_root_without_an_idnumber_is_adopted(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        set_config('draftcourseid', $course->id, 'local_artqtml');

        force_current_language('en');
        $this->forget_cached_root();
        $rootid = draft_bank::get_root_category_id();

        // Put it back the way an older version left it.
        $DB->set_field('question_categories', 'idnumber', '', ['id' => $rootid]);

        $this->forget_cached_root();
        $found = draft_bank::get_root_category_id();

        $this->assertSame($rootid, $found, 'the pre-idnumber root was not adopted');
        $this->assertSame(
            draft_bank::ROOT_IDNUMBER,
            $DB->get_field('question_categories', 'idnumber', ['id' => $rootid]),
            'the idnumber was not stamped onto the adopted root, so the next language change '
                . 'would hit the same failure again'
        );
    }
}
