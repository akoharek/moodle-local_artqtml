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
 * Unit tests for missing_types per-level shortfall.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\missing_types
 */
final class missing_types_test extends \advanced_testcase {
    /**
     * Matrix shortfall and narrowed settings honour each difficulty level separately.
     */
    public function test_matrix_shortfall_per_level(): void {
        global $DB;

        $this->resetAfterTest();

        $now = time();
        $settings = [
            'matrix' => [
                'IH' => ['easy' => 2, 'medium' => 2, 'hard' => 0],
                'FE' => ['easy' => 0, 'medium' => 0, 'hard' => 0],
                'SR' => ['easy' => 0, 'medium' => 0, 'hard' => 0],
            ],
            'counts' => ['IH' => 4, 'FE' => 0, 'SR' => 0],
            'difficulty' => ['mode' => 'scale', 'scale' => ['easy' => 2, 'medium' => 2, 'hard' => 0]],
        ];

        $generationid = $DB->insert_record('local_artqtml_generations', (object) [
            'userid' => 2,
            'name' => 'Test',
            'shortname' => 'TST1',
            'status' => 'partial',
            'settings' => json_encode($settings),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        foreach ([
            ['easy', 'TST1-IH-001'],
            ['easy', 'TST1-IH-002'],
            ['medium', 'TST1-IH-003'],
        ] as [$level, $code]) {
            $DB->insert_record('local_artqtml_questions', (object) [
                'generationid' => $generationid,
                'typecode' => 'IH',
                'questiontype' => 'truefalse',
                'questioncode' => $code,
                'questiontext' => 'Question',
                'difficultylabel' => $level,
                'validationsuggestion' => 'accepted',
                'timecreated' => $now,
            ]);
        }

        $generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid]);

        $matrixshortfall = missing_types::matrix_shortfall($generation);
        $this->assertSame(['medium' => 1], $matrixshortfall['IH']);

        $narrowed = missing_types::narrowed_settings($settings, $generation);
        $this->assertSame(0, $narrowed['matrix']['IH']['easy']);
        $this->assertSame(1, $narrowed['matrix']['IH']['medium']);
        $this->assertSame(1, $narrowed['counts']['IH']);
    }
}
