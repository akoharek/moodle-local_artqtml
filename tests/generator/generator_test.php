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

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the plugin data generator used by Behat.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml_generator
 */
final class generator_test extends \advanced_testcase {
    /**
     * A completed generation seed creates a real Moodle question in the draft bank.
     */
    public function test_setup_and_completed_question_land_in_the_draft_bank(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = self::getDataGenerator()->get_plugin_generator('local_artqtml');
        $draft = $generator->setup_for_teachers();
        $this->assertGreaterThan(0, (int) $draft->id);

        $owner = $this->getDataGenerator()->create_user();
        $generation = $generator->create_generation([
            'userid' => (int) $owner->id,
            'name'   => 'Seeded review',
            'status' => \local_artqtml\local\generation_status::COMPLETED,
        ]);
        $this->assertNotEmpty($generation->draftcategoryid);

        $question = $generator->create_question([
            'generationid'         => (int) $generation->id,
            'questioncode'         => 'SEED-IH-0001',
            'questiontext'         => 'Water is required for photosynthesis.',
            'validationsuggestion' => \local_artqtml\local\validation_suggestion::ACCEPTED,
        ]);
        $this->assertGreaterThan(0, (int) $question->questionbankid);
        $this->assertSame('SEED-IH-0001', $question->questioncode);
    }
}
