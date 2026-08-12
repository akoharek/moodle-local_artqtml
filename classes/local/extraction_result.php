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
 * Outcome of reading text from an uploaded document.
 *
 * Distinguishes empty documents, unreadable files, oversize files, and hidden-text rejections
 * so the upload page can show the right message. Reason codes are a closed set mapped to
 * localised strings at display time; they never carry document contents.
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
            // Optional extract metrics retained for diagnostics / logging.
            // Unused counters stay at zero unless a caller passes them.
            'pagecount'      => (int) ($metrics['pagecount'] ?? 0),
            'fontcount'      => (int) ($metrics['fontcount'] ?? 0),
            'mappedglyphs'   => (int) ($metrics['mappedglyphs'] ?? 0),
            'unmappedglyphs' => (int) ($metrics['unmappedglyphs'] ?? 0),
        ];
    }
}
