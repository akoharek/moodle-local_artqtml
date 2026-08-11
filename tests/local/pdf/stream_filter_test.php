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
 * Unit tests for undoing a stream's /Filter chain.
 *
 * Every case comes from a measured file (2026-08-06, BL-59), not from imagining what a PDF might
 * declare.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\pdf\stream_filter
 */
final class stream_filter_test extends \advanced_testcase {
    /** @var int the limit the extractor passes; irrelevant to these fixtures but required. */
    protected const MAXBYTES = 16777216;

    /**
     * A two-filter chain is undone in the order the file declares it.
     *
     * THIS IS THE DEFECT, and it emptied a whole document. ReportLab writes
     * `/Filter [ /ASCII85Decode /FlateDecode ]` - zlib first, then ASCII85 armour on top - so the
     * armour has to come off first. The old test was `strpos($dict, 'FlateDecode') !== false`,
     * which handed the armoured text straight to gzuncompress. It failed, the stream was skipped,
     * and on the measured file EVERY page was armoured this way: 0 characters out of 21,383.
     *
     * The operand is the ASCII85 armour of the zlib compression of `Az alma egy gyumolcs`.
     */
    public function test_a_two_filter_chain_is_undone_in_order(): void {
        $dict = '<< /Filter [ /ASCII85Decode /FlateDecode ] /Length 37 >>';
        $data = 'GariV;Fo$R9iOYXY>e&r/BuGc8M12I8X]m&~>';

        $this->assertSame('Az alma egy gyumolcs', stream_filter::decode($dict, $data, self::MAXBYTES));
    }

    /**
     * The names come back in declaration order, which is the whole point of reading the array.
     */
    public function test_the_filter_names_keep_their_order(): void {
        $this->assertSame(
            ['ASCII85Decode', 'FlateDecode'],
            stream_filter::names('<< /Filter [ /ASCII85Decode /FlateDecode ] >>')
        );
    }

    /**
     * A single-name /Filter is read too - the form LibreOffice Writer writes.
     */
    public function test_a_single_filter_name_is_read(): void {
        $this->assertSame(['FlateDecode'], stream_filter::names('<</Length 3 0 R/Filter/FlateDecode>>'));
    }

    /**
     * A stream with no /Filter comes back exactly as it went in.
     *
     * The guard on the change: most fixtures in this suite, and plenty of real content streams,
     * carry no filter at all, and turning "no filter" into "cannot decode" would have emptied them.
     */
    public function test_an_unfiltered_stream_is_returned_unchanged(): void {
        $this->assertSame(
            'BT /F1 12 Tf (Alma) Tj ET',
            stream_filter::decode('<< /Length 25 >>', 'BT /F1 12 Tf (Alma) Tj ET', self::MAXBYTES)
        );
    }

    /**
     * A filter this code cannot undo says so, instead of passing the raw bytes on as text.
     *
     * /DCTDecode is a JPEG. Before this class the bytes fell through to the text-operator scanner,
     * which read whatever byte sequences happened to look like show-text operators - noise offered
     * to the teacher as teaching material.
     */
    public function test_an_unsupported_filter_returns_null(): void {
        $this->assertNull(stream_filter::decode('<< /Filter /DCTDecode >>', 'binary-image-data', self::MAXBYTES));
    }

    /**
     * Ordinary zlib compression still works on its own.
     */
    public function test_a_flate_stream_is_inflated(): void {
        $data = gzcompress('Az alma egy gyumolcs');

        $this->assertSame('Az alma egy gyumolcs', stream_filter::decode('<< /Filter /FlateDecode >>', $data, self::MAXBYTES));
    }

    /**
     * Corrupt compressed data returns null rather than an empty string.
     *
     * The caller skips a null stream; an empty string would have counted as a stream that
     * legitimately held no text.
     */
    public function test_corrupt_flate_data_returns_null(): void {
        $this->assertNull(stream_filter::decode('<< /Filter /FlateDecode >>', 'not compressed at all', self::MAXBYTES));
    }

    /**
     * /ASCIIHexDecode, including the odd final digit the spec says to pad with a zero.
     */
    public function test_asciihex_is_decoded_and_the_odd_digit_padded(): void {
        $this->assertSame('Alma', stream_filter::decode('<< /Filter /ASCIIHexDecode >>', '416C6D61>', self::MAXBYTES));
        $this->assertSame("\x41\x50", stream_filter::decode('<< /Filter /ASCIIHexDecode >>', '415>', self::MAXBYTES));
    }
}
