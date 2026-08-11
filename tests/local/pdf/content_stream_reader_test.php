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

namespace local_artqtml\local\pdf;

/**
 * Unit tests for reading text out of one PDF content stream.
 *
 * Every case here comes from a measured failure on a real Word-exported document, not from
 * imagining what a PDF might do.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\pdf\content_stream_reader
 */
final class content_stream_reader_test extends \advanced_testcase {
    /**
     * A one-byte subset font: `!` is A, `"` is a space, `#` is e-acute.
     *
     * @return array<string, array{bytes: int, map: array<int, string>}>
     */
    protected function subset_font(): array {
        return ['F1' => ['bytes' => 1, 'map' => [0x21 => 'A', 0x22 => ' ', 0x23 => 'é']]];
    }

    /**
     * A `( )` string is decoded through the glyph table, exactly as a `<hex>` one is.
     *
     * THE DEFECT THIS COVERS was the largest one in the feature: BL-48 taught the reader to decode
     * hexadecimal operands and left the literal ones alone, and a Word export writes every page as
     * literal operands with one-byte subset codes. The whole document came out as punctuation.
     */
    public function test_a_literal_string_is_decoded_through_the_glyph_table(): void {
        $text = [];
        content_stream_reader::read('BT /F1 12 Tf (!"#) Tj ET', $text, $this->subset_font());

        $this->assertSame('A é', implode('', $text));
    }

    /**
     * A code the table does not know falls back to its own byte, read as CP1252.
     *
     * Dropping it instead would delete the spaces and punctuation that subset tables sometimes
     * leave out - and the result must still be valid UTF-8, which a raw CP1252 byte is not.
     */
    public function test_an_unmapped_code_falls_back_to_its_byte_as_utf8(): void {
        $text = [];
        content_stream_reader::read("BT /F1 12 Tf (!\xE9) Tj ET", $text, $this->subset_font());

        $out = implode('', $text);

        $this->assertSame('Aé', $out);
        $this->assertSame(1, preg_match('//u', $out), 'the output must be valid UTF-8');
    }

    /**
     * With no glyph table at all the bytes are still returned as valid UTF-8.
     *
     * This is the whole-file fallback route, which has no font information whatsoever. Before this,
     * a single CP1252 byte travelled to the web service and Moodle's response cleaning rejected
     * the entire call - so the teacher was told the file contained no text.
     */
    public function test_bytes_without_a_table_become_valid_utf8(): void {
        $text = [];
        content_stream_reader::read("BT /F1 12 Tf (K\xF6rte) Tj ET", $text);

        $out = implode('', $text);

        $this->assertSame('Körte', $out);
        $this->assertSame(1, preg_match('//u', $out), 'the output must be valid UTF-8');
    }

    /**
     * A show-text operation that is nothing but a space is kept.
     *
     * A word processor writes the space between two words as its own operation surprisingly often.
     * Discarding whitespace-only pieces as "empty" produced `A fenségeséssokoldalúgyümölcs`.
     */
    public function test_a_space_only_piece_is_not_discarded(): void {
        $text = [];
        content_stream_reader::read(
            'BT /F1 12 Tf (Alma) Tj ( ) Tj (Korte) Tj ET',
            $text,
            ['F1' => ['bytes' => 1, 'map' => []]]
        );

        $this->assertSame('Alma Korte', implode('', $text));
    }

    /**
     * Text moving down the page starts a new line; text staying on it does not.
     */
    public function test_only_vertical_movement_breaks_the_line(): void {
        $text = [];
        content_stream_reader::read(
            "BT 1 0 0 1 100 700 Tm (2026) Tj 1 0 0 1 140 700 Tm (-ban) Tj "
                . "1 0 0 1 100 680 Tm (masodik sor) Tj ET",
            $text
        );

        $this->assertSame("2026-ban\nmasodik sor", implode('', $text));
    }

    /**
     * An octal escape is one byte, not four characters.
     *
     * THE DEFECT THIS COVERS is the quietest of the three that emptied a PDF on 2026-08-06
     * (BL-59), because it did not empty anything - it filled the document with rubbish that no
     * counter complained about. A subset font's glyph codes start at 1, and a PDF literal string
     * writes any unprintable byte in octal, so a ReportLab page is almost entirely `\001\002\003`.
     * Left alone, those FOUR bytes went into the glyph table as the codes for backslash, zero, zero
     * and one - all four of which a full table knows - so the file produced 28,507 characters of
     * `Bevezet\001s a n\002v` instead of 21,589 characters of `Bevezetés a növénytanba`.
     */
    public function test_an_octal_escape_is_one_byte(): void {
        $text = [];
        content_stream_reader::read(
            "BT /F1 12 Tf (\\041\\042\\043) Tj ET",
            $text,
            $this->subset_font()
        );

        $this->assertSame('A é', implode('', $text));
    }

    /**
     * An escaped backslash before an opening parenthesis stays a backslash.
     *
     * The old unescaping ran six str_replace() calls in sequence, and the `\(` rule fired before
     * the `\\` one - so `\\(`, which is a legal PDF string, collapsed to a single `(`.
     */
    public function test_an_escaped_backslash_is_not_confused_with_an_escaped_parenthesis(): void {
        // The operand is a backslash-escaped backslash followed by a literal opening parenthesis.
        $this->assertSame('\\(', content_stream_reader::unescape_pdf_string('\\\\('));
    }

    /**
     * A backslash at the end of a line is a continuation: both it and the newline disappear.
     */
    public function test_a_line_continuation_disappears(): void {
        $this->assertSame('Almakorte', content_stream_reader::unescape_pdf_string("Alma\\\nkorte"));
    }

    /**
     * The mapped/unmapped counters report what the tables actually resolved.
     */
    public function test_the_glyph_counters_are_reported(): void {
        $text = [];
        $mapped = 0;
        $unmapped = 0;
        content_stream_reader::read(
            "BT /F1 12 Tf (!\xE9) Tj ET",
            $text,
            $this->subset_font(),
            $mapped,
            $unmapped
        );

        $this->assertSame(1, $mapped);
        $this->assertSame(1, $unmapped);
    }
}
