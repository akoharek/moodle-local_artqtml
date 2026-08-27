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
 * Unit tests for duplicate/near-duplicate source-text detection.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\duplicate_detector
 */
final class duplicate_detector_test extends \advanced_testcase {
    /**
     * Insert a generation row with a source text whose duplicate hash is pre-computed.
     *
     * @param string $sourcetext
     * @return int the new generation id
     */
    protected function seed_generation(string $sourcetext): int {
        global $DB;

        $now = time();
        return (int) $DB->insert_record('local_artqtml_generations', (object) [
            'userid'         => 2,
            'name'           => 'Fixture',
            'shortname'      => 'DUP1',
            'sourcetext'     => $sourcetext,
            'sourcetexthash' => duplicate_detector::hash($sourcetext),
            'status'         => 'completed',
            'timecreated'    => $now,
            'timemodified'   => $now,
        ]);
    }

    /**
     * Normalise() lowercases and collapses runs of whitespace to single spaces.
     */
    public function test_normalise_lowercases_and_collapses_whitespace(): void {
        $this->assertSame('hello world foo', duplicate_detector::normalise("  HELLO   world\n\tFoo  "));
    }

    /**
     * The text hash ignores case and whitespace differences (so trivially reformatted duplicates
     * Still collide), but differs for genuinely different content.
     */
    public function test_hash_is_normalisation_insensitive(): void {
        $this->assertSame(
            duplicate_detector::hash('The Quick Brown Fox'),
            duplicate_detector::hash("the   quick\nbrown fox")
        );
        $this->assertNotSame(
            duplicate_detector::hash('The quick brown fox'),
            duplicate_detector::hash('A slow green turtle')
        );
    }

    /**
     * Raw file-byte hashing is a plain sha1 of the exact bytes.
     */
    public function test_hash_file_bytes(): void {
        $bytes = "\x00\x01binary\xffcontent";
        $this->assertSame(sha1($bytes), duplicate_detector::hash_file_bytes($bytes));
    }

    /**
     * An exact (post-normalisation) match is found via the hash and reported at 100%.
     */
    public function test_find_match_exact(): void {
        $this->resetAfterTest();

        $text = 'Mitochondria are the powerhouse of the eukaryotic cell.';
        $id = $this->seed_generation($text);

        // Same text, only case/whitespace differs -> still an exact hash match.
        $match = duplicate_detector::find_match("mitochondria are the   powerhouse of the eukaryotic cell.");
        $this->assertNotNull($match);
        $this->assertSame($id, (int) $match->id);
        $this->assertSame(100, $match->similarity);
    }

    /**
     * A near-duplicate over the 0.90 Jaccard threshold is detected via shingling.
     */
    public function test_find_match_near_duplicate(): void {
        $this->resetAfterTest();

        // 100 distinct words -> ~96 five-word shingles.
        $words = [];
        for ($i = 0; $i < 100; $i++) {
            $words[] = 'word' . $i;
        }
        $stored = implode(' ', $words);
        $this->seed_generation($stored);

        // Same 100 words plus one appended -> shares all but one shingle (Jaccard ~0.99).
        $submitted = $stored . ' word100extra';
        $match = duplicate_detector::find_match($submitted);

        $this->assertNotNull($match);
        $this->assertGreaterThanOrEqual(90, $match->similarity);
    }

    /**
     * A generation editing its own unchanged text must not flag itself, so the excluded id is never returned.
     */
    public function test_find_match_excludes_self(): void {
        $this->resetAfterTest();

        $text = 'Photosynthesis converts light energy into chemical energy in plants.';
        $id = $this->seed_generation($text);

        $this->assertNull(duplicate_detector::find_match($text, $id));
    }

    /**
     * Genuinely unrelated text produces no match.
     */
    public function test_find_match_none_for_dissimilar(): void {
        $this->resetAfterTest();

        $this->seed_generation('The water cycle describes how water evaporates, condenses and precipitates.');

        $this->assertNull(duplicate_detector::find_match(
            'Newton\'s three laws of motion govern classical mechanics and dynamics.'
        ));
    }
}
