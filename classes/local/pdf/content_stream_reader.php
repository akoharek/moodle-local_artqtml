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
 * Reads the text out of one decompressed PDF content stream.
 *
 * IT NO LONGER JUDGES VISIBILITY, and that is the point of the class as it now stands. Until
 * 2026-08-04 it dropped text it believed a reader would not see: the invisible rendering mode, a
 * font under four points, a white fill. That was removed on András's decision, and the reasoning is
 * worth keeping because it is the reasoning of the whole feature.
 *
 * The extracted text is written into the source-text box on the upload page, where the teacher
 * reads and edits it before anything is submitted. Text that reaches that box is not hidden any
 * more, whatever the document did to conceal it - so guessing at colour bought nothing, while the
 * guesses themselves were unreliable: this parser does not know what is behind the text, so white
 * on a dark slide read as invisible, and the invisible rendering mode is what an OCR layer over a
 * scan uses legitimately. Both would have silently emptied ordinary documents.
 *
 * WHAT IS STILL EXCLUDED is a different thing entirely, and it is excluded by structure rather than
 * by appearance: an object announcing JavaScript, a launch action or an embedded file
 * ({@see \local_artqtml\local\text_extractor}). That is not prose somebody wrote.
 *
 * WHAT THE STATE MACHINE UNDERSTANDS:
 *
 *  - `q` / `Q`   save and restore state;
 *  - `Tf`        names the resource whose glyph table applies - the size is no longer read;
 *  - `Td` / `TD` / `Tm` / `T*` move the text position - only the VERTICAL component is read, and
 *                only to decide where a line ends;
 *  - `Tj` / `TJ` show text, as a literal `( )` string or a `< >` hexadecimal one. BOTH forms are
 *                decoded through the active font's /ToUnicode table, and both always produce valid
 *                UTF-8 ({@see self::decode_literal_string()} for why that sentence had to be
 *                written down rather than assumed).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\pdf;

/**
 * A graphics-state machine over one PDF content stream.
 */
class content_stream_reader {
    /** @var float vertical movement, in points, that starts a new line. */
    protected const LINE_BREAK_THRESHOLD = 0.5;

    /** @var array<string, mixed> the current state - only the active font resource name. */
    protected array $state = ['font' => null];

    /** @var array<int, array> saved states, from `q`. */
    protected array $stack = [];

    /** @var float|null the last vertical position, for the line-break rule. */
    protected ?float $lasty = null;

    /** @var array<string, array{bytes: int, map: array}> resource name to glyph table. */
    protected array $fonts = [];

    /** @var int glyph codes the tables resolved. */
    protected int $mapped = 0;

    /** @var int glyph codes the tables did not resolve. */
    protected int $unmapped = 0;

    /**
     * The operator pattern, read in document order.
     *
     * @return string
     */
    protected static function pattern(): string {
        return '/'
            . '(?P<q>\bq\b)'
            . '|(?P<Q>\bQ\b)'
            . '|\/(?P<fontname>[^\s\/]+)\s+(?P<tf>-?[\d.]+)\s+Tf'
            . '|(?P<tmx>-?[\d.]+)\s+-?[\d.]+\s+-?[\d.]+\s+-?[\d.]+\s+-?[\d.]+\s+(?P<tmy>-?[\d.]+)\s+Tm\b'
            . '|(?P<tdx>-?[\d.]+)\s+(?P<tdy>-?[\d.]+)\s+(?:Td|TD)\b'
            . '|(?P<tstar>\bT\*)'
            . '|(?P<tj>\((?:[^()\\\\]|\\\\.)*\))\s*Tj'
            . '|(?P<tjhex><[0-9A-Fa-f\s]*>)\s*Tj'
            . '|\[(?P<tjarray>(?:[^\[\]\\\\]|\\\\.)*)\]\s*TJ'
            . '/';
    }

    /**
     * Read one stream, appending its text to the accumulator.
     *
     * @param string $streamtext one decompressed content stream
     * @param string[] $text accumulator for the extracted text, appended to by reference
     * @param array $fonts resource name to ['bytes' => int, 'map' => array], from /ToUnicode
     * @param int $mapped incremented for each glyph code the table resolved
     * @param int $unmapped incremented for each glyph code it did not
     * @return void
     */
    public static function read(
        string $streamtext,
        array &$text,
        array $fonts = [],
        int &$mapped = 0,
        int &$unmapped = 0
    ): void {
        $reader = new self();
        $reader->fonts = $fonts;

        $found = preg_match_all(self::pattern(), $streamtext, $matches, PREG_SET_ORDER);
        if ($found === false || preg_last_error() !== PREG_NO_ERROR) {
            return;
        }

        foreach ($matches as $match) {
            $reader->apply($match, $text);
        }

        $mapped += $reader->mapped;
        $unmapped += $reader->unmapped;
    }

    /**
     * Dispatch one matched operator.
     *
     * @param array $match one PREG_SET_ORDER entry
     * @param string[] $text the accumulator
     * @return void
     */
    protected function apply(array $match, array &$text): void {
        if (($match['q'] ?? '') !== '') {
            $this->stack[] = $this->state;
            return;
        }
        if (($match['Q'] ?? '') !== '') {
            if ($this->stack) {
                $this->state = array_pop($this->stack);
            }
            return;
        }
        if ($this->apply_text_state($match)) {
            return;
        }
        if ($this->apply_position($match, $text)) {
            return;
        }

        $this->show($match, $text);
    }

    /**
     * Apply `Tf`, which names the font resource whose glyph table decodes the hexadecimal strings.
     *
     * The size operand is matched but not stored: it was only ever read to decide whether text was
     * too small to be meant for reading, and that judgement is gone.
     *
     * @param array $match
     * @return bool true if this match was a text-state operator
     */
    protected function apply_text_state(array $match): bool {
        if (($match['tf'] ?? '') !== '') {
            $this->state['font'] = $match['fontname'] ?? null;
            return true;
        }

        return false;
    }

    /**
     * Apply `Tm`, `Td`, `TD` and `T*`: a line break when the text moves down, a space when it moves
     * along.
     *
     * THE VERTICAL RULE was measured rather than assumed. Joining with nothing leaves a page number
     * stuck to the following sentence (`4Sokan azt gondolják`); breaking at every positioning
     * operator splits words apart (`2026\n-\nban`), because a hyphen is routinely its own text
     * object at the same height. Breaking only on real vertical movement produces both `2026-ban`
     * and the page number on its own line.
     *
     * A HORIZONTAL RULE WAS TRIED ON 2026-08-04 AND REMOVED, which is worth a sentence so that it
     * is not reinvented. The symptom that suggested it - `A fenségeséssokoldalúgyümölcs` - looked
     * like missing word gaps, so a space was inserted wherever the text moved along without moving
     * down. Measured on the file that showed the symptom, it changed the output by nine characters
     * out of 4,768 and fixed nothing. The words were not being separated by movement at all: they
     * were separated by a space that {@see self::show()} was throwing away. Inserting spaces on
     * horizontal movement would have been a guess kept alive by a fix that came from somewhere
     * else.
     *
     * @param array $match
     * @param string[] $text the accumulator
     * @return bool true if this match was a positioning operator
     */
    protected function apply_position(array $match, array &$text): bool {
        $newy = null;
        if (($match['tmy'] ?? '') !== '') {
            $newy = (float) $match['tmy'];
        } else if (($match['tdy'] ?? '') !== '') {
            $newy = ($this->lasty ?? 0.0) + (float) $match['tdy'];
        } else if (($match['tstar'] ?? '') !== '') {
            $newy = ($this->lasty ?? 0.0) - 1.0;
        }

        if ($newy === null) {
            return false;
        }

        if ($this->lasty !== null && abs($newy - $this->lasty) > self::LINE_BREAK_THRESHOLD) {
            $text[] = "\n";
        }
        $this->lasty = $newy;

        return true;
    }

    /**
     * Apply `Tj` and `TJ`, keeping what would have been visible.
     *
     * @param array $match
     * @param string[] $text the accumulator
     * @return void
     */
    protected function show(array $match, array &$text): void {
        foreach ($this->shown_strings($match) as $piece) {
            // A PIECE THAT IS NOTHING BUT A SPACE IS KEPT, and this line used to throw it away -
            // `trim($piece) !== ''`. It looked like tidiness and it was the reason a real document
            // came out as `A fenségeséssokoldalúgyümölcs`: a word processor writes the space
            // between two words as its own show-text operation surprisingly often, so discarding
            // whitespace-only pieces discarded the word boundaries themselves. Only genuinely
            // empty output is skipped now - which is what an undecodable glyph produces.
            if ($piece !== '') {
                $text[] = $piece;
            }
        }
    }

    /**
     * The decoded strings one show-text operator would put on the page.
     *
     * @param array $match
     * @return string[]
     */
    protected function shown_strings(array $match): array {
        if (($match['tj'] ?? '') !== '') {
            return [$this->decode_literal_string(substr($match['tj'], 1, -1))];
        }

        if (($match['tjhex'] ?? '') !== '') {
            return [$this->decode_hex_string($match['tjhex'])];
        }

        if (!isset($match['tjarray']) || $match['tjarray'] === '') {
            return [];
        }

        $pattern = '/\((?:[^()\\\\]|\\\\.)*\)|<[0-9A-Fa-f\s]*>/';
        if (!preg_match_all($pattern, $match['tjarray'], $submatches)) {
            return [];
        }

        $shown = [];
        foreach ($submatches[0] as $submatch) {
            $shown[] = $submatch[0] === '<'
                ? $this->decode_hex_string($submatch)
                : $this->decode_literal_string(substr($submatch, 1, -1));
        }

        return $shown;
    }

    /**
     * Decode a `( ... )` operand into UTF-8.
     *
     * THE GLYPH TABLE APPLIES HERE TOO, and until 2026-08-04 evening it did not - which made this
     * the single largest defect in the feature, because it is the ordinary case rather than the
     * exotic one. BL-48 assumed that a font needing /ToUnicode would store its text as `<hex>`;
     * measured on a Word-exported PDF, every page stores it as `( )` with ONE-BYTE SUBSET CODES,
     * and the table is just as necessary. Without it the first page of that file read
     *
     *     !"#"$%&'()"*%&'+,(-(./"(0+121+0"+1"$3-4,5&41"
     *
     * and with it
     *
     *     A körte: A gyümölcsök királynője
     *
     * A CODE THE TABLE DOES NOT KNOW FALLS BACK TO ITS OWN BYTE, read as CP1252. That is what this
     * parser did for every byte before the table existed, so a font with a thin or unreadable table
     * is no worse off than it was; and it is why the fallback is not simply "drop it", which would
     * have silently deleted the spaces and punctuation that subset tables sometimes omit.
     *
     * THE RESULT IS ALWAYS VALID UTF-8, which is not a detail. A PDF literal string holds bytes in
     * the font's own encoding, and a Hungarian document is full of bytes that are not valid UTF-8 -
     * 0xA5 in the measured file. Those bytes travelled all the way to the web service, where
     * Moodle's own response cleaning rejected the call outright ("Invalid response value
     * detected"), so the upload page reported that no text could be extracted. The document was
     * read correctly and thrown away at the last step.
     *
     * @param string $raw the operand without its parentheses
     * @return string UTF-8
     */
    protected function decode_literal_string(string $raw): string {
        $bytes = self::unescape_pdf_string($raw);
        $fontname = $this->state['font'];
        $font = ($fontname !== null && isset($this->fonts[$fontname])) ? $this->fonts[$fontname] : null;

        if ($font === null) {
            return self::bytes_to_utf8($bytes);
        }

        $width = max(1, (int) $font['bytes']);
        $map = $font['map'];
        $out = '';

        foreach (str_split($bytes, $width) as $chunk) {
            if (strlen($chunk) < $width) {
                break;
            }

            $code = 0;
            for ($i = 0; $i < $width; $i++) {
                $code = ($code << 8) | ord($chunk[$i]);
            }

            if (isset($map[$code])) {
                $out .= $map[$code];
                $this->mapped++;
                continue;
            }

            $this->unmapped++;
            if ($width === 1) {
                $out .= self::bytes_to_utf8($chunk);
            }
        }

        return $out;
    }

    /**
     * Read bytes in a simple font's built-in encoding as UTF-8.
     *
     * CP1252 rather than Latin-1: it is what /WinAnsiEncoding means, which is what a PDF written on
     * a desktop word processor declares, and it differs from Latin-1 exactly in the range where
     * curly quotes and dashes live - the characters a document is most likely to contain.
     *
     * @param string $bytes
     * @return string UTF-8, never invalid
     */
    protected static function bytes_to_utf8(string $bytes): string {
        if ($bytes === '') {
            return '';
        }

        // Already valid UTF-8 (a plain ASCII string always is) - converting it again would turn
        // every accented character into mojibake.
        if (preg_match('//u', $bytes) === 1) {
            return $bytes;
        }

        $converted = @iconv('CP1252', 'UTF-8//TRANSLIT', $bytes);

        return is_string($converted) ? $converted : '';
    }

    /**
     * Decode a `<...>` operand through the active font's /ToUnicode table.
     *
     * Without a table the codes are glyph identifiers and mean nothing outside their font, so the
     * operand contributes nothing rather than contributing noise - which is what the extractor did
     * before this existed, and why a 17,500-character document yielded 64.
     *
     * @param string $hex the operand including its angle brackets
     * @return string UTF-8
     */
    protected function decode_hex_string(string $hex): string {
        $fontname = $this->state['font'];
        if ($fontname === null || !isset($this->fonts[$fontname])) {
            return '';
        }

        $digits = preg_replace('/[^0-9A-Fa-f]/', '', $hex);
        if (!is_string($digits) || $digits === '') {
            return '';
        }

        $width = $this->fonts[$fontname]['bytes'] * 2;
        $map = $this->fonts[$fontname]['map'];
        $out = '';

        foreach (str_split($digits, $width) as $chunk) {
            if (strlen($chunk) < $width) {
                break;
            }
            $code = hexdec($chunk);
            if (isset($map[$code])) {
                $out .= $map[$code];
                $this->mapped++;
            } else {
                $this->unmapped++;
            }
        }

        return $out;
    }

    /**
     * Unescape PDF literal string escape sequences.
     *
     * THE OCTAL ESCAPE IS THE ONE THAT MATTERS, and leaving it out cost a whole document
     * (2026-08-06, BL-59). A PDF literal string writes any byte outside the printable range as
     * `\ddd` in octal - and a subset font's glyph codes START AT 1, so a ReportLab-produced page
     * is almost entirely `\001\002\003`. Left alone, those four bytes went into the glyph table as
     * the CODES for backslash, zero, zero and one, all four of which a full table happens to know,
     * so nothing looked wrong: the file produced 28,507 characters of `Bevezet\001s a n\002v...`
     * instead of 21,589 characters of `Bevezetés a növénytanba`. No count and no error said so.
     *
     * IT IS ALSO ONE PASS NOW, rather than six str_replace() calls in sequence. Run in order, the
     * old form turned `\\(` - an escaped backslash followed by an opening parenthesis, which is a
     * legal string - into a single `(`, because the `\\(` rule fired before the `\\\\` one.
     *
     * @param string $raw
     * @return string
     */
    public static function unescape_pdf_string(string $raw): string {
        static $simple = [
            'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C",
            '(' => '(', ')' => ')', '\\' => '\\',
        ];

        $out = '';
        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            if ($raw[$i] !== '\\') {
                $out .= $raw[$i];
                continue;
            }

            $i++;
            if ($i >= $length) {
                break;
            }

            $char = $raw[$i];

            if (isset($simple[$char])) {
                $out .= $simple[$char];
                continue;
            }

            if ($char >= '0' && $char <= '7') {
                $value = 0;
                for ($digits = 0; $digits < 3 && $i < $length && $raw[$i] >= '0' && $raw[$i] <= '7'; $digits++) {
                    $value = $value * 8 + (ord($raw[$i]) - 48);
                    $i++;
                }
                $i--;
                $out .= chr($value & 0xFF);
                continue;
            }

            // A backslash before an end-of-line is a line continuation: both disappear.
            if ($char === "\n") {
                continue;
            }
            if ($char === "\r") {
                if ($i + 1 < $length && $raw[$i + 1] === "\n") {
                    $i++;
                }
                continue;
            }

            // Any other escaped character stands for itself.
            $out .= $char;
        }

        return $out;
    }
}
