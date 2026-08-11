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

/**
 * Undoes a stream's /Filter chain, in the order the file declares it.
 *
 * WHY THIS EXISTS, MEASURED RATHER THAN IMAGINED (2026-08-06, BL-59). Until this class, every one
 * of the four places that decompress a stream asked the same question the same way:
 *
 *     if (strpos($object, 'FlateDecode') !== false) { $data = gzuncompress($data); }
 *
 * That is not "is this stream compressed with zlib" - it is "does the word FlateDecode appear
 * anywhere in the dictionary". It gets two ordinary files wrong:
 *
 *  - /Filter IS A LIST, and it is applied IN ORDER. A PDF written by ReportLab - the library that
 *    produced one of the two measured files - declares `/Filter [ /ASCII85Decode /FlateDecode ]`:
 *    the bytes are zlib-compressed and then ASCII85-armoured, so the ASCII85 has to come off
 *    first. Handing the armoured text straight to gzuncompress fails, the stream is skipped, and
 *    on that file EVERY page content stream is armoured this way. The document extracted to
 *    nothing at all - 0 characters out of 21,383.
 *  - A FILTER THIS CODE CANNOT UNDO IS NOW SAID SO. Before, a stream with, say, /DCTDecode (a
 *    JPEG) fell through to `$inflated = $streamdata` and the raw image bytes were scanned for text
 *    operators. That is noise being read as teaching material.
 *
 * WHAT IT HANDLES, and only because a measured file uses it: /FlateDecode, /ASCII85Decode,
 * /ASCIIHexDecode. Anything else returns null, which every caller answers by skipping the stream.
 * That is deliberate: an unknown filter means the bytes are not text, and guessing produces
 * garbage rather than a gap.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\pdf;

/**
 * Decoder for a PDF stream's declared filter chain.
 */
class stream_filter {
    /**
     * The filter names a stream dictionary declares, in application order.
     *
     * /Filter is either a single name or an array of them. The array form is the one that matters:
     * it is what carries the order, and the order is what the old substring test threw away.
     *
     * THE SEARCH STOPS AT THE `stream` KEYWORD. Callers pass whatever they have - two of them hold
     * the dictionary alone, two hold the whole object body with its compressed bytes attached. A
     * `/Filter` found inside those bytes would be a coincidence read as a declaration, so the bytes
     * are cut off before the search rather than trusted not to contain the word.
     *
     * @param string $dict the stream object's dictionary text, or the whole object body
     * @return string[] filter names without their leading slash; empty when there is no /Filter
     */
    public static function names(string $dict): array {
        if (preg_match('/stream\r?\n/', $dict, $found, PREG_OFFSET_CAPTURE)) {
            $dict = substr($dict, 0, $found[0][1]);
        }

        if (preg_match('/\/Filter\s*\[(.*?)\]/s', $dict, $match)) {
            if (preg_match_all('/\/(\w+)/', $match[1], $found)) {
                return $found[1];
            }

            return [];
        }

        if (preg_match('/\/Filter\s*\/(\w+)/', $dict, $match)) {
            return [$match[1]];
        }

        return [];
    }

    /**
     * Undo every declared filter, in order.
     *
     * @param string $dict the stream object's dictionary text
     * @param string $data the raw stream bytes
     * @param int $maxbytes the largest output one inflate call may produce
     * @return string|null the decoded bytes, or null when a filter cannot be undone
     */
    public static function decode(string $dict, string $data, int $maxbytes): ?string {
        foreach (self::names($dict) as $name) {
            switch ($name) {
                case 'FlateDecode':
                    // The second argument is the whole point: without it a stream may declare any
                    // expanded size it likes and PHP will allocate it.
                    $inflated = @gzuncompress($data, $maxbytes);
                    if ($inflated === false) {
                        // Some non-compliant writers omit the zlib header/checksum and emit a raw
                        // deflate stream instead - fall back to that.
                        $inflated = @gzinflate($data, $maxbytes);
                    }
                    if ($inflated === false) {
                        return null;
                    }
                    $data = $inflated;
                    break;

                case 'ASCII85Decode':
                    $decoded = self::ascii85_decode($data);
                    if ($decoded === null) {
                        return null;
                    }
                    $data = $decoded;
                    break;

                case 'ASCIIHexDecode':
                    $decoded = self::asciihex_decode($data);
                    if ($decoded === null) {
                        return null;
                    }
                    $data = $decoded;
                    break;

                default:
                    // An image codec, an encryption filter, a compression this code does not
                    // implement. Not text, and not worth guessing at - see the class docblock.
                    return null;
            }

            if (strlen($data) > $maxbytes) {
                return null;
            }
        }

        return $data;
    }

    /**
     * Undo /ASCII85Decode.
     *
     * Written out rather than delegated because PHP has no base85 of its own, and the one variant
     * that matters is the PDF one: optional `<~` opener, `~>` terminator, `z` for four zero bytes,
     * whitespace ignored anywhere, and a final group of fewer than five characters that carries one
     * fewer output byte than it has input characters.
     *
     * @param string $data the armoured bytes
     * @return string|null null when the input contains a character ASCII85 cannot hold
     */
    protected static function ascii85_decode(string $data): ?string {
        $data = (string) preg_replace('/\s/', '', $data);

        if (strncmp($data, '<~', 2) === 0) {
            $data = substr($data, 2);
        }
        $end = strpos($data, '~>');
        if ($end !== false) {
            $data = substr($data, 0, $end);
        }

        $out = '';
        $group = [];
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $char = ord($data[$i]);

            if ($char === 0x7A && $group === []) {
                $out .= "\0\0\0\0";
                continue;
            }

            if ($char < 0x21 || $char > 0x75) {
                return null;
            }

            $group[] = $char - 33;
            if (count($group) === 5) {
                $out .= self::ascii85_group($group, 4);
                $group = [];
            }
        }

        if ($group !== []) {
            $kept = count($group) - 1;
            $group = array_pad($group, 5, 84);
            $out .= self::ascii85_group($group, $kept);
        }

        return $out;
    }

    /**
     * One five-character ASCII85 group as up to four bytes.
     *
     * @param int[] $group five values, each already reduced by 33
     * @param int $keep how many of the four output bytes are real
     * @return string
     */
    protected static function ascii85_group(array $group, int $keep): string {
        $value = 0;
        foreach ($group as $digit) {
            $value = $value * 85 + $digit;
        }

        $bytes = chr(($value >> 24) & 0xFF)
            . chr(($value >> 16) & 0xFF)
            . chr(($value >> 8) & 0xFF)
            . chr($value & 0xFF);

        return substr($bytes, 0, $keep);
    }

    /**
     * Undo /ASCIIHexDecode.
     *
     * @param string $data the hexadecimal text
     * @return string|null
     */
    protected static function asciihex_decode(string $data): ?string {
        $end = strpos($data, '>');
        if ($end !== false) {
            $data = substr($data, 0, $end);
        }

        $digits = (string) preg_replace('/[^0-9A-Fa-f]/', '', $data);
        if (strlen($digits) % 2 !== 0) {
            // The spec says an odd final digit is padded with a zero.
            $digits .= '0';
        }

        $decoded = @hex2bin($digits);

        return $decoded === false ? null : $decoded;
    }
}
