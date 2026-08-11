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
 * Unit tests for reaching a PDF's numbered objects.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\pdf\object_index
 */
final class object_index_test extends \advanced_testcase {
    /**
     * A stream whose own last byte is a newline keeps it, because /Length says how long it is.
     *
     * THIS IS THE TEST FOR A REAL DEFECT, not a hypothetical: trimming the newline before
     * `endstream` cost one byte of a Flate-compressed glyph table in a measured file, zlib refused
     * the truncated stream, and the document's title came out as `!"#"$%&'()`. One byte.
     */
    public function test_the_declared_length_wins_over_trimming_the_final_newline(): void {
        $body = "<< /Length 3 >>\nstream\nab\n\nendstream";

        $this->assertSame("ab\n", object_index::stream_data($body));
    }

    /**
     * Without a usable /Length the newline before `endstream` is still trimmed.
     *
     * A file assembled by a tool rather than written by one may carry a stale length, and being
     * approximately right beats returning nothing.
     */
    public function test_a_missing_length_falls_back_to_trimming(): void {
        $body = "<< /Filter /FlateDecode >>\nstream\nabc\nendstream";

        $this->assertSame('abc', object_index::stream_data($body));
    }

    /**
     * A length that runs past `endstream` is not believed.
     */
    public function test_a_length_longer_than_the_stream_is_ignored(): void {
        $body = "<< /Length 9999 >>\nstream\nabc\nendstream";

        $this->assertSame('abc', object_index::stream_data($body));
    }

    /**
     * An indirect /Length is followed to the object that holds the number.
     *
     * THE DEFECT THIS COVERS emptied a whole document (2026-08-06, BL-59). LibreOffice Writer
     * writes `/Length 3 0 R`, because it does not know how long a compressed stream is until it has
     * finished writing it. The old pattern read that as the number THREE: three bytes came back out
     * of a 3,441-byte zlib stream, zlib refused the fragment, the page was skipped - and on the
     * measured file ten of the fourteen streams are written this way, including every page's
     * content. 0 characters out of 21,541.
     */
    public function test_an_indirect_length_is_followed(): void {
        $metrics = [];
        $pdf = "%PDF-1.4\n"
            . "1 0 obj\n<< /Type /Page /Contents 2 0 R >>\nendobj\n"
            . "2 0 obj\n<< /Length 3 0 R >>\nstream\nab\n\nendstream\nendobj\n"
            . "3 0 obj\n3\nendobj\n";

        $index = object_index::build($pdf, $metrics);

        $this->assertNotNull($index);
        $this->assertSame("ab\n", object_index::stream_data($index->get(2), $index));
    }

    /**
     * An indirect /Length that cannot be followed falls back to trimming - not to the object number.
     *
     * The boundary of the fix above, and the half that is easy to get wrong: `3 0 R` must not be
     * read as "three bytes" just because the index is not at hand. Being approximately right beats
     * returning three bytes of a stream that is not three bytes long.
     */
    public function test_an_indirect_length_without_the_index_falls_back_to_trimming(): void {
        $body = "<< /Length 3 0 R >>\nstream\nabcdef\nendstream";

        $this->assertSame('abcdef', object_index::stream_data($body));
    }

    /**
     * A dictionary is read whole, past a nested one that closes early.
     *
     * The obvious non-greedy `<<.*?>>` stops at /ExtGState's closing braces and never sees /Font,
     * which is the link the whole font chain hangs from.
     */
    public function test_a_nested_dictionary_does_not_end_the_outer_one(): void {
        $body = '<< /ExtGState << /GS1 5 0 R >> /Font << /F1 7 0 R >> >>';

        $this->assertSame($body, object_index::balanced_dictionary($body, 0));
    }

    /**
     * Felt-036: indexed object bodies are capped; the limit refuses rather than indexing more.
     *
     * Passes a tiny ceiling into {@see object_index::build()} so the fixture stays small without a
     * subclass (Moodle PHPCS rejects `@phpstan-consistent-constructor` on `new static()`).
     */
    public function test_indexed_bytes_limit_refuses(): void {
        $metrics = [];
        $pdf = "%PDF-1.4\n"
            . "1 0 obj\n" . str_repeat('A', 60) . "\nendobj\n"
            . "2 0 obj\n" . str_repeat('B', 60) . "\nendobj\n";

        try {
            object_index::build($pdf, $metrics, 100);
            $this->fail('Expected resource_limit_exception');
        } catch (resource_limit_exception $e) {
            $this->assertSame('MAX_INDEXED_BYTES', $e->debuginfo);
        }
    }

    /**
     * Felt-036: after an ObjStm is expanded, its parent body is dropped; children remain.
     */
    public function test_objstm_parent_is_released_after_expand(): void {
        $metrics = [];
        // Uncompressed ObjStm: one nested page dictionary at offset 0 of the stream payload.
        // Header "20 0\n" is 5 bytes (/First = 5); body is the page dict.
        $payload = "20 0\n<< /Type /Page >>";
        $first = 5;
        $length = strlen($payload);
        $pdf = "%PDF-1.4\n"
            . "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            . "2 0 obj\n<< /Type /Pages /Kids [20 0 R] /Count 1 >>\nendobj\n"
            . "10 0 obj\n<< /Type /ObjStm /N 1 /First {$first} /Length {$length} >>\n"
            . "stream\n{$payload}\nendstream\nendobj\n";

        $index = object_index::build($pdf, $metrics);

        $this->assertNotNull($index);
        $this->assertNull($index->get(10), 'ObjStm parent must be released after expand');
        $this->assertNotNull($index->get(20), 'Nested object must remain');
        $this->assertSame([20], $index->pages());
        $this->assertArrayHasKey('indexedbytes', $metrics);
        $this->assertGreaterThan(0, $metrics['indexedbytes']);
    }
}
