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
 * Makes a PDF's numbered objects reachable, and nothing else.
 *
 * WHY A NEW LAYER RATHER THAN A BETTER PATTERN. The extractor deliberately did not resolve object
 * references: it scanned the raw file for stream blocks and read each one on its own. That was a
 * reasonable trade until the day a real file needed the font's /ToUnicode table, which is only
 * reachable along a chain of references:
 *
 *     page (/Type /Page)
 *       -> /Resources
 *            -> /Font << /F1 16 0 R >>
 *                 -> font object
 *                      -> /ToUnicode 717 0 R
 *                           -> the CMap stream
 *
 * No regular expression reaches the end of that. This class is the smallest thing that does.
 *
 * IT DOES NOT READ THE XREF TABLE. Scanning for `N 0 obj` directly works on a file whose
 * cross-reference table is damaged - which is common in files assembled by tools rather than
 * written by one - and on the measured file it found everything the table would have.
 *
 * WHERE AN OBJECT NUMBER IS DEFINED TWICE, THE LAST DEFINITION WINS. That is what an incremental
 * save means, and it is stated here because it would otherwise be decided silently by whichever
 * order the scan happened to run in.
 *
 * A SIDE EFFECT WORTH MORE THAN IT SOUNDS. Reading pages means reading only the pages' /Contents
 * streams. On the measured file the old approach inflated 23.47 MiB - images, fonts, everything -
 * to reach 0.16 MiB of text: 99.3% of the work produced nothing. That mattered beyond speed,
 * because the 32 MiB total-inflation limit added the same morning would have refused a slightly
 * more image-heavy presentation whose actual text is a fifth of a megabyte.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\pdf;

/**
 * An index of a PDF's numbered objects, including those inside object streams.
 */
class object_index {
    /** @var int most objects indexed before the file is treated as malformed. */
    public const MAX_OBJECTS = 65536;

    /** @var int most /ObjStm object streams expanded. */
    public const MAX_OBJSTM_STREAMS = 64;

    /** @var int most bytes one expanded object stream may occupy (16 MiB). */
    public const MAX_OBJSTM_BYTES = 16777216;

    /** @var int most pages processed. */
    public const MAX_PAGES = 2048;

    /**
     * Most bytes of object bodies kept in the index at once (128 MiB).
     *
     * Felt-036 / 2026-08-07: each body is a substr copy of the file (and ObjStm children are
     * copied again from inflated data). Count caps alone allow far more resident RAM than the
     * "streams one at a time" guarantee. Exceeding this throws {@see resource_limit_exception}
     * so the extractor refuses rather than falling back to the whole-file scan.
     *
     * @var int
     */
    public const MAX_INDEXED_BYTES = 134217728;

    /**
     * Most inflated ObjStm bytes expanded across the whole file (96 MiB).
     *
     * Per-stream {@see MAX_OBJSTM_BYTES} still applies; this is the running total so 64 streams
     * cannot each spend a full 16 MiB of inflation work.
     *
     * @var int
     */
    public const MAX_TOTAL_OBJSTM_BYTES = 100663296;

    /** @var array<int, string> object number to raw body. */
    protected array $objects = [];

    /** @var int[] page object numbers, in document order. */
    protected array $pages = [];

    /** @var int running total of strlen() of bodies currently in {@see $objects}. */
    protected int $indexedbytes = 0;

    /**
     * Effective indexed-bytes ceiling for this instance ({@see MAX_INDEXED_BYTES} unless overridden
     * via {@see build()} for tests).
     *
     * @var int
     */
    protected int $maxindexedbytes;

    /**
     * Private: instances come from {@see self::build()}.
     *
     * @param int|null $maxindexedbytes optional ceiling override (tests only)
     */
    protected function __construct(?int $maxindexedbytes = null) {
        $this->maxindexedbytes = $maxindexedbytes ?? self::MAX_INDEXED_BYTES;
    }

    /**
     * Build an index from the raw file.
     *
     * NULL MEANS "CANNOT BE READ", AND NOTHING ELSE. A hard limit throws
     * {@see resource_limit_exception} instead, because the caller answers null by running the older
     * and more expensive whole-file scan - which is exactly what a limit is there to prevent. The
     * two used to share the null return, so exceeding a limit made the extractor do more work
     * rather than less (2026-08-05, BL-49).
     *
     * @param string $content the whole PDF
     * @param array $metrics measured counters, added to in place
     * @param int|null $maxindexedbytes optional {@see MAX_INDEXED_BYTES} override for unit tests
     * @return self|null null when the structure cannot be read
     * @throws resource_limit_exception when a hard limit is exceeded
     */
    public static function build(string $content, array &$metrics, ?int $maxindexedbytes = null): ?self {
        $index = new self($maxindexedbytes);

        // Objects written directly into the file body.
        if (!preg_match_all('/(\d+)\s+\d+\s+obj\b/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($matches[0] as $i => $match) {
            if (count($index->objects) >= self::MAX_OBJECTS) {
                throw new resource_limit_exception('MAX_OBJECTS');
            }

            $number = (int) $matches[1][$i][0];
            $start = $match[1] + strlen($match[0]);
            $end = strpos($content, 'endobj', $start);
            if ($end === false) {
                continue;
            }

            // Last definition wins - see the class docblock.
            $index->store_object($number, substr($content, $start, $end - $start));
        }

        if ($index->objects === []) {
            return null;
        }

        $index->expand_object_streams();

        // Pages are collected after the object streams are expanded, because on the measured file
        // the page and font objects live inside them - without that step the chain breaks at its
        // first link.
        foreach ($index->objects as $number => $body) {
            if (preg_match('/\/Type\s*\/Page\b(?![s])/', $body)) {
                if (count($index->pages) >= static::MAX_PAGES) {
                    throw new resource_limit_exception('MAX_PAGES');
                }
                $index->pages[] = $number;
            }
        }

        $metrics['pagecount'] = count($index->pages);
        $metrics['indexedbytes'] = $index->indexedbytes;

        return $index;
    }

    /**
     * Store one object body, enforcing {@see MAX_INDEXED_BYTES}.
     *
     * @param int $number
     * @param string $body
     * @return void
     * @throws resource_limit_exception
     */
    protected function store_object(int $number, string $body): void {
        if (isset($this->objects[$number])) {
            $this->indexedbytes -= strlen($this->objects[$number]);
        }

        $newtotal = $this->indexedbytes + strlen($body);
        if ($newtotal > $this->maxindexedbytes) {
            throw new resource_limit_exception('MAX_INDEXED_BYTES');
        }

        $this->objects[$number] = $body;
        $this->indexedbytes = $newtotal;
    }

    /**
     * Drop one object body from the index (and its contribution to the byte total).
     *
     * @param int $number
     * @return void
     */
    protected function release_object(int $number): void {
        if (!isset($this->objects[$number])) {
            return;
        }

        $this->indexedbytes -= strlen($this->objects[$number]);
        unset($this->objects[$number]);
    }

    /**
     * One object's raw body.
     *
     * @param int $number
     * @return string|null
     */
    public function get(int $number): ?string {
        return $this->objects[$number] ?? null;
    }

    /**
     * The page object numbers, in document order.
     *
     * @return int[]
     */
    public function pages(): array {
        return $this->pages;
    }

    /**
     * Read a dictionary value that is either inline or a reference, following the reference.
     *
     * @param string $body the object body to read from
     * @param string $key the dictionary key, without the slash
     * @return string|null the dictionary text (including the outer << >>), or null
     */
    public function dictionary_value(string $body, string $key): ?string {
        // A reference, as in `/Resources 12 0 R`.
        if (preg_match('/\/' . preg_quote($key, '/') . '\s+(\d+)\s+\d+\s+R\b/', $body, $match)) {
            $referenced = $this->get((int) $match[1]);
            if ($referenced === null) {
                return null;
            }
            $offset = strpos($referenced, '<<');

            return $offset === false ? null : self::balanced_dictionary($referenced, $offset);
        }

        // Inline, as in `/Resources << ... >>`.
        if (preg_match('/\/' . preg_quote($key, '/') . '\s*<</', $body, $match, PREG_OFFSET_CAPTURE)) {
            $offset = strpos($body, '<<', $match[0][1]);

            return $offset === false ? null : self::balanced_dictionary($body, $offset);
        }

        return null;
    }

    /**
     * Read one complete `<< ... >>` dictionary, counting nesting.
     *
     * THIS IS NOT A CONVENIENCE. The obvious non-greedy `<<.*?>>` is WRONG on a real file, and the
     * measurement showed exactly how: a page's /Resources dictionary contains /ExtGState, whose own
     * closing `>>` ends the non-greedy match - so /Font, which comes after it, is never seen and the
     * whole chain silently produces nothing. That was the prototype's first failure.
     *
     * @param string $body
     * @param int $offset position of the opening `<<`
     * @return string the dictionary text including both delimiters
     */
    public static function balanced_dictionary(string $body, int $offset): string {
        $depth = 0;
        $length = strlen($body);

        for ($i = $offset; $i < $length - 1; $i++) {
            $pair = substr($body, $i, 2);
            if ($pair === '<<') {
                $depth++;
                $i++;
            } else if ($pair === '>>') {
                $depth--;
                $i++;
                if ($depth === 0) {
                    return substr($body, $offset, $i + 1 - $offset);
                }
            }
        }

        return substr($body, $offset);
    }

    /**
     * Expand every /ObjStm object stream and index the objects inside it.
     *
     * @return void
     * @throws resource_limit_exception when a hard limit is exceeded
     */
    protected function expand_object_streams(): void {
        $expanded = 0;
        $objstmbytes = 0;

        // Snapshot keys first: parents are released after a successful expand, which must not
        // disturb the iteration that discovers them.
        $objstmnumbers = [];
        foreach ($this->objects as $number => $body) {
            if (strpos($body, '/ObjStm') !== false) {
                $objstmnumbers[] = $number;
            }
        }

        foreach ($objstmnumbers as $objstmnumber) {
            $body = $this->objects[$objstmnumber] ?? null;
            if ($body === null) {
                continue;
            }

            if (++$expanded > static::MAX_OBJSTM_STREAMS) {
                throw new resource_limit_exception('MAX_OBJSTM_STREAMS');
            }

            $data = self::stream_data($body, $this);
            if ($data === null) {
                continue;
            }

            $data = stream_filter::decode($body, $data, static::MAX_OBJSTM_BYTES);
            if ($data === null) {
                continue;
            }

            if (strlen($data) > static::MAX_OBJSTM_BYTES) {
                throw new resource_limit_exception('MAX_OBJSTM_BYTES');
            }

            $objstmbytes += strlen($data);
            if ($objstmbytes > static::MAX_TOTAL_OBJSTM_BYTES) {
                throw new resource_limit_exception('MAX_TOTAL_OBJSTM_BYTES');
            }

            if (!preg_match('/\/N\s+(\d+)/', $body, $nmatch) || !preg_match('/\/First\s+(\d+)/', $body, $fmatch)) {
                unset($data);
                continue;
            }

            $count = (int) $nmatch[1];
            $first = (int) $fmatch[1];
            $header = substr($data, 0, $first);

            if (!preg_match_all('/(\d+)\s+(\d+)/', $header, $pairs, PREG_SET_ORDER)) {
                unset($data);
                continue;
            }

            for ($i = 0; $i < $count && $i < count($pairs); $i++) {
                if (count($this->objects) >= static::MAX_OBJECTS) {
                    throw new resource_limit_exception('MAX_OBJECTS');
                }

                $number = (int) $pairs[$i][1];
                $start = $first + (int) $pairs[$i][2];
                $end = isset($pairs[$i + 1])
                    ? $first + (int) $pairs[$i + 1][2]
                    : strlen($data);

                // An object written directly in the file body takes precedence over one inside an
                // object stream: a direct write is what an incremental save produces.
                if (!isset($this->objects[$number])) {
                    $this->store_object($number, substr($data, $start, $end - $start));
                }
            }

            // Felt-036: the parent ObjStm body is unused after its children are indexed - drop it
            // so the inflated source is not kept beside the copies.
            $this->release_object($objstmnumber);
            unset($data);
        }
    }

    /**
     * The raw bytes between `stream` and `endstream` in an object body.
     *
     * /Length IS AUTHORITATIVE WHEN IT IS THERE, and reading it is not a refinement - it is the
     * difference between a font's glyph table decoding and not decoding. The obvious way to find
     * the end of a stream is to look for `endstream` and trim the newline before it, and that is
     * what this did until 2026-08-04 evening. It corrupts compressed data whose LAST BYTE HAPPENS
     * TO BE 0x0A or 0x0D, which is not a rare accident: one font in the measured file lost exactly
     * one byte that way, zlib refused the truncated stream, the font silently had no /ToUnicode
     * table, and its text - the document's title - came out as `!"#"$%&'()`.
     *
     * Falling back to the trim when /Length is absent or wrong is deliberate: a file assembled by
     * a tool rather than written by one may carry a stale length, and being approximately right
     * beats returning nothing.
     *
     * /LENGTH MAY BE AN INDIRECT REFERENCE, and reading it as a number is not a near miss - it is
     * the difference between a whole document and nothing (2026-08-06, BL-59). LibreOffice Writer
     * writes `/Length 3 0 R`: the length lives in its own object, because the writer does not know
     * it until the stream is finished. The pattern above matched that as the number THREE, three
     * bytes came back out of a 3,441-byte compressed stream, zlib refused it, and the page was
     * skipped. On the measured file TEN of the fourteen streams are written this way, including
     * every page's content - so the file extracted to 0 characters out of 21,541.
     *
     * @param string $body
     * @param self|null $index needed only to follow an indirect /Length; without it such a stream
     *                         falls back to the trim
     * @return string|null
     */
    public static function stream_data(string $body, ?self $index = null): ?string {
        if (!preg_match('/stream\r?\n/', $body, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $end = strpos($body, 'endstream', $start);
        if ($end === false) {
            return null;
        }

        $head = substr($body, 0, $match[0][1]);
        $declared = null;

        if (preg_match('/\/Length\s+(\d+)\s+\d+\s+R\b/', $head, $ref)) {
            // An indirect length. If it cannot be followed, the trim below is used - NOT the first
            // number of the reference, which is an object number and means nothing as a size.
            $lengthobject = $index === null ? null : $index->get((int) $ref[1]);
            if ($lengthobject !== null && preg_match('/\d+/', $lengthobject, $value)) {
                $declared = (int) $value[0];
            }
        } else if (preg_match('/\/Length\s+(\d+)\b/', $head, $length)) {
            $declared = (int) $length[1];
        }

        if ($declared !== null && $declared > 0 && $start + $declared <= $end) {
            return substr($body, $start, $declared);
        }

        return rtrim(substr($body, $start, $end - $start), "\r\n");
    }
}
