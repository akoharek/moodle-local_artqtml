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
 * The outcome of trying to read text out of an uploaded document.
 *
 * WHY THIS EXISTS. `text_extractor::extract()` returned a string, and an empty string had to stand
 * for four completely different situations: a document with no text in it, a file the parser could
 * not open, a file too large to expand safely, and a file deliberately carrying text the reader
 * cannot see. The upload page treated all four the same way - "extraction produced nothing" - so a
 * document that was refused for a security reason looked exactly like an empty one.
 *
 * WHAT IS NOT A REASON, and was for half of 2026-08-04: hidden text. A document carrying a
 * vanished, two-point or white run was first refused outright, then quietly stripped of it. Both
 * are gone - the text goes into the source-text box, where the teacher reads it, so there was
 * nothing for the parser to protect anyone from by guessing at appearance.
 *
 * The reason codes are a closed set, and deliberately technical rather than descriptive: they are
 * matched in code and mapped to a localised message at the point of display. What they never carry
 * is any part of the document - not the hidden text that caused a rejection, not a file path, not a
 * parser warning. A rejection message is shown on screen and may be logged, and a document's
 * contents have no business in either.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * The closed set of extraction outcomes and reasons, with the helpers that build them.
 */
class extraction_result {
    /** @var string text was read successfully. */
    public const STATUS_OK = 'ok';

    /** @var string the file was readable and simply contained no text. */
    public const STATUS_EMPTY = 'empty';

    /** @var string the file was refused; see the reason. */
    public const STATUS_REJECTED = 'rejected';

    /** @var string the extension is not one this plugin reads. */
    public const REASON_UNSUPPORTED_TYPE = 'unsupportedtype';

    /** @var string the file does not have the structure its extension claims. */
    public const REASON_INVALID_STRUCTURE = 'invalidstructure';

    /** @var string expanding the file would exceed a hard processing limit. */
    public const REASON_RESOURCE_LIMIT = 'resourcelimit';

    /**
     * @var string the document was read successfully and holds no text this extractor can reach.
     *
     * Separate from an empty result rather than the same thing, because the two are answered
     * differently: an empty result used to send the document to the older whole-file scan in the
     * hope of partial text. Decided by András on 2026-08-05: a document that cannot be read is
     * refused, not partially processed. The teacher is told, instead of being handed an empty box.
     */
    public const REASON_NO_TEXT = 'notext';

    /**
     * A successful (or empty) result.
     *
     * @param string $text the extracted text
     * @param array $metrics measured counters
     * @return array{status: string, text: string, reason: string, metrics: array}
     */
    public static function ok(string $text, array $metrics = []): array {
        return [
            'status'  => trim($text) === '' ? self::STATUS_EMPTY : self::STATUS_OK,
            'text'    => $text,
            'reason'  => '',
            'metrics' => self::metrics($metrics),
        ];
    }

    /**
     * A rejection.
     *
     * Takes no text parameter at all, so that a refused document's contents cannot reach a caller
     * by accident - the shape of the function is the guarantee, not a rule somebody has to follow.
     *
     * @param string $reason one of the REASON_* constants
     * @param array $metrics measured counters - numbers only
     * @return array{status: string, text: string, reason: string, metrics: array}
     */
    public static function rejected(string $reason, array $metrics = []): array {
        return [
            'status'  => self::STATUS_REJECTED,
            'text'    => '',
            'reason'  => $reason,
            'metrics' => self::metrics($metrics),
        ];
    }

    /**
     * The localised message for a reason code.
     *
     * @param string $reason one of the REASON_* constants
     * @return string
     */
    public static function message(string $reason): string {
        $keys = [
            self::REASON_UNSUPPORTED_TYPE  => 'errorfileunsupportedtype',
            self::REASON_INVALID_STRUCTURE => 'errorfileinvalidstructure',
            self::REASON_RESOURCE_LIMIT    => 'errorfileresourcelimit',
            // Deliberately the same string as the unknown-reason default: it already says exactly
            // what this case needs - nothing could be extracted, paste the text instead - and a
            // second wording for the same advice is a second thing to keep in step.
            self::REASON_NO_TEXT           => 'errorfileextractionfailed',
        ];

        return get_string($keys[$reason] ?? 'errorfileextractionfailed', 'local_artqtml');
    }

    /**
     * Fill in every metric key so callers never have to test for one.
     *
     * @param array $metrics
     * @return array<string, int>
     */
    protected static function metrics(array $metrics): array {
        return [
            'sourcebytes'    => (int) ($metrics['sourcebytes'] ?? 0),
            'expandedbytes'  => (int) ($metrics['expandedbytes'] ?? 0),
            'streamcount'    => (int) ($metrics['streamcount'] ?? 0),
            'archiveentries' => (int) ($metrics['archiveentries'] ?? 0),
            // Optional extract metrics retained for diagnostics / logging. Light only accepts
            // plain TXT, so PDF glyph counters stay at zero unless a caller passes them.
            'pagecount'      => (int) ($metrics['pagecount'] ?? 0),
            'fontcount'      => (int) ($metrics['fontcount'] ?? 0),
            'mappedglyphs'   => (int) ($metrics['mappedglyphs'] ?? 0),
            'unmappedglyphs' => (int) ($metrics['unmappedglyphs'] ?? 0),
        ];
    }
}
