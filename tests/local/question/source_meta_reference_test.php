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

namespace local_artqtml\local\question;

/**
 * Source-document meta-references must be detectable and safely stripable at the stem start.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\question\source_meta_reference
 */
final class source_meta_reference_test extends \advanced_testcase {
    /**
     * HU and EN meta-reference phrases are detected case-insensitively.
     *
     * @dataProvider contains_provider
     * @param string $text
     * @param bool $expected
     */
    public function test_contains(string $text, bool $expected): void {
        $this->assertSame($expected, source_meta_reference::contains($text), $text);
    }

    /**
     * Data provider for test_contains.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function contains_provider(): array {
        return [
            'hu szerint' => ['Melyik növénycsaládot említi a szöveg szerint?', true],
            'hu alapján' => ['A forrás alapján mi a helyes sorrend?', true],
            'hu leading' => ['A szöveg szerint az alma rózsaféle.', true],
            'en according' => ['According to the text, apples are roses.', true],
            'en based on' => ['Based on the passage what is pectin?', true],
            'en source text' => ['According to the source text, water boils at 100C.', true],
            'clean hu' => ['Melyik növénycsaládba tartozik az alma?', false],
            'clean en' => ['Which plant family does the apple belong to?', false],
            'word text alone' => ['What does the word text mean here?', false],
            'empty' => ['', false],
        ];
    }

    /**
     * A leading clause is stripped; an embedded phrase is left for the validator.
     */
    public function test_strip_leading_only(): void {
        $this->assertSame(
            'Az alma rózsaféle.',
            source_meta_reference::strip_leading('A szöveg szerint az alma rózsaféle.')
        );
        $this->assertSame(
            'Apples belong to Rosaceae.',
            source_meta_reference::strip_leading('According to the text, apples belong to Rosaceae.')
        );

        $embedded = 'Melyik növénycsaládot említi a szöveg szerint?';
        $this->assertSame($embedded, source_meta_reference::strip_leading($embedded));

        $clean = 'Melyik növénycsaládba tartozik az alma?';
        $this->assertSame($clean, source_meta_reference::strip_leading($clean));
    }

    /**
     * Stripping twice changes nothing.
     */
    public function test_strip_leading_is_idempotent(): void {
        $once = source_meta_reference::strip_leading('Based on the passage: pectin binds water.');
        $twice = source_meta_reference::strip_leading($once);
        $this->assertSame($once, $twice);
        $this->assertFalse(source_meta_reference::contains($once));
    }
}
