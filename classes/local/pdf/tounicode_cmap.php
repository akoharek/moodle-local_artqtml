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
 * Turns a PDF font's /ToUnicode CMap into a glyph-code to character table.
 *
 * WHY THIS IS NEEDED AT ALL. A PDF does not have to store text as text. With an embedded CID font -
 * which is what Microsoft Office writes on export, and therefore the ordinary case rather than an
 * edge one - a page's content stream holds `<0048006500...> Tj`: two-byte GLYPH IDENTIFIERS, whose
 * numbers mean nothing outside that one font. Read as characters they are noise; read through the
 * font's own /ToUnicode table they are the text.
 *
 * Measured on a real 21-page teaching PDF: 6,781 hexadecimal strings against 3,029 literal ones,
 * and the extractor recovered 64 characters out of roughly 17,500 - everything it found came from
 * the single font that happened to use a conventional encoding.
 *
 * WHAT IT PARSES, and all of it because the measured file uses all of it:
 *
 *  - `codespacerange` - whether codes are one or two bytes. Identity-H is always two, but that is
 *    a property of that encoding rather than a rule, so it is read rather than assumed.
 *  - `bfchar` - single code to value pairs.
 *  - `bfrange` in BOTH of its forms - a contiguous range with a base value, and a range with an
 *    explicit array of values. The measured file uses each, so handling one is not enough.
 *
 * The target values are UTF-16BE, may be several code points for one glyph (a ligature), and may
 * be a surrogate pair. They are decoded as UTF-16BE rather than treated as bytes, which is what
 * keeps Hungarian accented characters and typographic quotation marks intact.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\pdf;

/**
 * Parser for a single /ToUnicode CMap stream.
 */
class tounicode_cmap {
    /** @var int most entries one CMap may define. */
    public const MAX_CMAP_ENTRIES = 65536;

    /**
     * How many bytes one glyph code occupies in this font's strings.
     *
     * Read from the codespace range rather than assumed: a hex string is split into codes by this
     * number, so getting it wrong turns the whole page into noise rather than failing visibly.
     *
     * @param string $cmaptext the decompressed CMap
     * @return int 1 or 2, defaulting to 2 (Identity-H, the common case)
     */
    public static function code_bytes(string $cmaptext): int {
        if (preg_match('/begincodespacerange(.*?)endcodespacerange/s', $cmaptext, $match)) {
            if (preg_match('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $match[1], $range)) {
                // Two hex digits per byte.
                return max(1, min(2, (int) (strlen($range[1]) / 2)));
            }
        }

        return 2;
    }

    /**
     * Build the code to character table.
     *
     * @param string $cmaptext the decompressed CMap
     * @return array<int, string> glyph code to UTF-8 string
     */
    public static function parse(string $cmaptext): array {
        $map = [];

        self::parse_bfchar($cmaptext, $map);
        self::parse_bfrange($cmaptext, $map);

        return $map;
    }

    /**
     * Read every `bfchar` block into the map.
     *
     * @param string $cmaptext
     * @param array $map modified in place
     * @return void
     */
    protected static function parse_bfchar(string $cmaptext, array &$map): void {
        if (!preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmaptext, $blocks)) {
            return;
        }

        foreach ($blocks[1] as $block) {
            if (!preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $block, $pairs, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($pairs as $pair) {
                if (count($map) >= self::MAX_CMAP_ENTRIES) {
                    return;
                }
                $map[hexdec($pair[1])] = self::utf16be_to_utf8($pair[2]);
            }
        }
    }

    /**
     * Read every `bfrange` block into the map, in both of its forms.
     *
     * @param string $cmaptext
     * @param array $map modified in place
     * @return void
     */
    protected static function parse_bfrange(string $cmaptext, array &$map): void {
        if (!preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmaptext, $blocks)) {
            return;
        }

        foreach ($blocks[1] as $block) {
            // Form 2 first - `<lo> <hi> [<a> <b> <c>]` - because its bracketed list would
            // otherwise be partly consumed by the simpler pattern.
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*\[(.*?)\]/s', $block, $arrays, PREG_SET_ORDER)) {
                foreach ($arrays as $range) {
                    $code = hexdec($range[1]);
                    if (preg_match_all('/<([0-9A-Fa-f]*)>/', $range[3], $values)) {
                        foreach ($values[1] as $value) {
                            if (count($map) >= self::MAX_CMAP_ENTRIES) {
                                return;
                            }
                            $map[$code++] = self::utf16be_to_utf8($value);
                        }
                    }
                }
                $block = preg_replace('/<[0-9A-Fa-f]+>\s*<[0-9A-Fa-f]+>\s*\[.*?\]/s', '', $block);
            }

            // Form 1 - `<lo> <hi> <base>` - a contiguous run counting up from the base value.
            $pattern = '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/';
            if (!preg_match_all($pattern, (string) $block, $ranges, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($ranges as $range) {
                $lo = hexdec($range[1]);
                $hi = hexdec($range[2]);
                $base = $range[3];

                // A range whose end is before its start, or which is absurdly wide, is a malformed
                // CMap rather than a very large font.
                if ($hi < $lo || ($hi - $lo) > self::MAX_CMAP_ENTRIES) {
                    continue;
                }

                for ($code = $lo; $code <= $hi; $code++) {
                    if (count($map) >= self::MAX_CMAP_ENTRIES) {
                        return;
                    }
                    $map[$code] = self::utf16be_to_utf8(self::increment_hex($base, $code - $lo));
                }
            }
        }
    }

    /**
     * Add an offset to the last code point of a hex value.
     *
     * Only the final code unit advances: a base of several code points is a ligature, and a range
     * built on one counts up from its last character.
     *
     * @param string $hex UTF-16BE hex, without angle brackets
     * @param int $offset
     * @return string
     */
    protected static function increment_hex(string $hex, int $offset): string {
        if ($offset === 0 || strlen($hex) < 4) {
            return $hex;
        }

        $prefix = substr($hex, 0, -4);
        $last = hexdec(substr($hex, -4)) + $offset;

        return $prefix . str_pad(strtoupper(dechex($last & 0xFFFF)), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Decode a UTF-16BE hex value to UTF-8.
     *
     * Decoded as UTF-16BE rather than byte by byte, because the values carry accented Hungarian
     * characters, typographic quotation marks, ligatures of several code points, and occasionally
     * surrogate pairs - none of which survive being treated as bytes.
     *
     * @param string $hex without angle brackets; may be empty
     * @return string UTF-8, empty when the value is unusable
     */
    protected static function utf16be_to_utf8(string $hex): string {
        if ($hex === '' || strlen($hex) % 2 !== 0) {
            return '';
        }

        $bytes = @hex2bin($hex);
        if ($bytes === false) {
            return '';
        }

        // An odd number of bytes is not valid UTF-16 at all.
        if (strlen($bytes) % 2 !== 0) {
            return '';
        }

        $converted = @iconv('UTF-16BE', 'UTF-8//IGNORE', $bytes);

        return is_string($converted) ? $converted : '';
    }
}
