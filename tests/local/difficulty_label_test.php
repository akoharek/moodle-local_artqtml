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
 * Unit tests for scale difficulty_label normalisation and display.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\difficulty_label
 */
final class difficulty_label_test extends \advanced_testcase {
    /**
     * Canonical keys pass through unchanged.
     */
    public function test_normalise_accepts_canonical_values(): void {
        foreach (difficulty_label::VALUES as $value) {
            $this->assertSame($value, difficulty_label::normalise($value));
        }
    }

    /**
     * Case variants and Hungarian aliases map to canonical keys.
     */
    public function test_normalise_maps_aliases(): void {
        $this->assertSame(difficulty_label::EASY, difficulty_label::normalise('Easy'));
        $this->assertSame(difficulty_label::MEDIUM, difficulty_label::normalise('MEDIUM'));
        $this->assertSame(difficulty_label::HARD, difficulty_label::normalise(' Hard '));
        $this->assertSame(difficulty_label::EASY, difficulty_label::normalise('Könnyű'));
        $this->assertSame(difficulty_label::MEDIUM, difficulty_label::normalise('közepes'));
        $this->assertSame(difficulty_label::HARD, difficulty_label::normalise('NEHÉZ'));
    }

    /**
     * Unknown or empty values fall back to the supplied default.
     */
    public function test_normalise_fallback(): void {
        $this->assertNull(difficulty_label::normalise('bogus', null));
        $this->assertSame(difficulty_label::MEDIUM, difficulty_label::normalise('', difficulty_label::MEDIUM));
        $this->assertSame(difficulty_label::MEDIUM, difficulty_label::normalise(null, difficulty_label::MEDIUM));
        $this->assertSame(difficulty_label::EASY, difficulty_label::normalise('invalid', difficulty_label::EASY));
    }

    /**
     * label() renders localised scale strings, not raw machine keys.
     */
    public function test_label_uses_lang_strings(): void {
        $this->resetAfterTest();

        $this->assertSame('Easy', difficulty_label::label('easy'));
        $this->assertSame('Medium', difficulty_label::label('MEDIUM'));
        $this->assertSame('Hard', difficulty_label::label('nehéz'));
    }
}
