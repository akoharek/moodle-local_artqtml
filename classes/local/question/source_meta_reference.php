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
 * Detects and strips unprofessional "according to the text / szöveg szerint" meta-references.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\question;

/**
 * Quiz stems must not point at the source document (according-to-the-text meta-references).
 *
 * The student already knows the material comes from the course. Naming the source in the stem
 * Reads as AI scaffolding, not as a finished question. The generator prompt forbids it; this
 * Class is the server-side half: strip a leading clause when that is safe, and tell the semantic
 * Validator to reject anything that still carries the phrase.
 */
class source_meta_reference {
    /**
     * HU/EN phrases that mean "according to / based on the source document".
     */
    private const PHRASES = [
        // Hungarian.
        'szöveg szerint',
        'forrás szerint',
        'forrásszöveg szerint',
        'forrásanyag szerint',
        'dokumentum szerint',
        'leírás szerint',
        'szöveg alapján',
        'forrás alapján',
        'forrásszöveg alapján',
        'forrásanyag alapján',
        'dokumentum alapján',
        'leírás alapján',
        'megadott szöveg szerint',
        'megadott szöveg alapján',
        'fenti szöveg szerint',
        'fenti szöveg alapján',
        'fenti forrás szerint',
        'fenti forrás alapján',
        // English.
        'according to the text',
        'according to the source',
        'according to the passage',
        'according to the document',
        'according to the material',
        'according to the source text',
        'according to the provided text',
        'according to the above text',
        'according to the above',
        'based on the text',
        'based on the source',
        'based on the passage',
        'based on the document',
        'based on the material',
        'based on the source text',
        'based on the provided text',
        'as stated in the text',
        'as stated in the source',
        'as stated in the passage',
        'as per the text',
        'as per the source',
        'as per the passage',
    ];

    /**
     * Leading-clause patterns that can be removed without leaving a broken mid-sentence hole.
     *
     * Only the start of the stem/option is touched. Embedded phrases ("… a szöveg szerint?") stay
     * For the validator to reject - stripping those would produce ungrammatical leftovers.
     */
    private const LEADING_PATTERNS = [
        // Hungarian patterns: "According to the text, …" / "Based on the source text: …".
        '/^(?:a[z]?\s+)?(?:megadott\s+|fenti\s+)?'
            . '(?:szöveg|forrás(?:szöveg|anyag)?|dokumentum|leírás)\s+'
            . '(?:szerint|alapján)\s*[,:\-–—]?\s+/iu',
        // EN: "According to the text, …" / "Based on the passage: …".
        '/^(?:according to|based on|as (?:stated in|per))\s+the\s+'
            . '(?:(?:provided|above|source)\s+)?(?:text|source(?:\s+text)?|passage|document|material)'
            . '\s*[,:\-–—]?\s+/iu',
        // EN without "the": "According to source text, …" is uncommon; keep "According to above.".
        '/^according to the above\s*[,:\-–—]?\s+/iu',
    ];

    /**
     * Whether $text contains a banned source meta-reference phrase.
     *
     * @param string $text
     * @return bool
     */
    public static function contains(string $text): bool {
        $normalized = self::normalize($text);
        if ($normalized === '') {
            return false;
        }

        foreach (self::PHRASES as $phrase) {
            if (mb_strpos($normalized, self::normalize($phrase), 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip a leading meta-reference clause when the stem opens with one.
     *
     * Idempotent: a second pass finds nothing to remove. Does not invent content - if stripping
     * Would leave an empty string, the original text is returned unchanged so the validator can
     * Still see and reject the phrase.
     *
     * @param string $text
     * @return string
     */
    public static function strip_leading(string $text): string {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return $text;
        }

        $stripped = $trimmed;
        foreach (self::LEADING_PATTERNS as $pattern) {
            $candidate = preg_replace($pattern, '', $stripped, 1);
            if (is_string($candidate) && $candidate !== $stripped) {
                $stripped = $candidate;
                break;
            }
        }

        $stripped = trim($stripped);
        if ($stripped === '' || $stripped === $trimmed) {
            return $text;
        }

        // Restore sentence capitalisation after removing an opening clause.
        if (function_exists('mb_strtoupper') && function_exists('mb_substr')) {
            $first = mb_substr($stripped, 0, 1, 'UTF-8');
            $rest = mb_substr($stripped, 1, null, 'UTF-8');
            $stripped = mb_strtoupper($first, 'UTF-8') . $rest;
        } else {
            $stripped = ucfirst($stripped);
        }

        return $stripped;
    }

    /**
     * Collapse whitespace and lower-case for phrase matching.
     *
     * @param string $text
     * @return string
     */
    private static function normalize(string $text): string {
        $collapsed = preg_replace('/\s+/u', ' ', $text);
        if (!is_string($collapsed)) {
            $collapsed = $text;
        }

        return mb_strtolower(trim($collapsed), 'UTF-8');
    }
}
