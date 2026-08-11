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
 * M-07 semantic validation of AI-generated question data (split out of question_importer -
 * technical annex ch.6).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\question;

/**
 * Checks that schema-valid AI JSON is also semantically usable before it is ever imported.
 */
class question_semantic_validator {
    /**
     * M-07: semantic validation of AI-generated question data, run before it is ever imported
     * into a real Moodle question - catches AI output that is structurally well-formed JSON
     * (already schema-validated) but semantically broken in a way that would silently create a
     * useless or unanswerable question.
     *
     * @param string $typecode IH/FE/FT/SR/EH/RV
     * @param array $data decoded per-type fields from the AI response
     * @param array $typesettings this type's generation settings - only SR's per-generation
     *      'sritemcount' override (M-26) is read, to enforce the exact item count (v20 #7)
     * @return string|null null if valid, otherwise a short human-readable reason it was rejected
     *      (logged as a question_rejected event, never shown to a user - kept in English)
     */
    public static function validate(string $typecode, array $data, array $typesettings = []): ?string {
        // V20 #6: every supported type needs a non-blank question text - an empty stem is an
        // unanswerable, useless question regardless of type.
        if (trim((string) ($data['questiontext'] ?? '')) === '') {
            return $typecode . ': empty questiontext';
        }

        // Source-document meta-references ("szöveg szerint", "according to the text") are
        // unprofessional scaffolding. The prompt forbids them and the cleaner strips a leading
        // clause; anything still present in the stem is rejected here rather than imported.
        if (source_meta_reference::contains((string) $data['questiontext'])) {
            return $typecode . ': questiontext contains source meta-reference';
        }

        switch ($typecode) {
            case 'IH':
                if (!isset($data['correctanswer'])) {
                    return 'truefalse: missing correctanswer';
                }
                return null;

            case 'FE':
            case 'FT':
                $options = $data['options'] ?? [];
                // V20 #6: reject an empty option array or any blank option text outright.
                if (!is_array($options) || count($options) === 0) {
                    return "multichoice ($typecode): no options";
                }
                foreach ($options as $option) {
                    if (trim((string) ($option['text'] ?? '')) === '') {
                        return "multichoice ($typecode): blank option text";
                    }
                    if (source_meta_reference::contains((string) ($option['text'] ?? ''))) {
                        return "multichoice ($typecode): option text contains source meta-reference";
                    }
                }

                $correctcount = 0;
                foreach ($options as $option) {
                    if (!empty($option['correct'])) {
                        $correctcount++;
                    }
                }
                if ($typecode === 'FE' && $correctcount !== 1) {
                    return "multichoice (FE): expected exactly 1 correct option, got $correctcount";
                }
                if ($typecode === 'FT' && $correctcount < 2) {
                    return "multichoiceset (FT): expected at least 2 correct options, got $correctcount";
                }

                // V20 #7: enforce the admin-configured FE/FT option-count range server-side.
                $min = (int) (get_config('local_artqtml', 'fefminoptions') ?: 2);
                $max = (int) (get_config('local_artqtml', 'fefmaxoptions') ?: 5);
                $count = count($options);
                if ($count < $min || $count > $max) {
                    return "multichoice ($typecode): $count options outside the configured range {$min}-{$max}";
                }
                return null;

            case 'SR':
                $items = $data['items'] ?? [];
                if (!is_array($items) || count($items) < 2) {
                    return 'ordering (SR): expected at least 2 items, got ' . (is_array($items) ? count($items) : 0);
                }
                // V20 #6: reject any blank item text (items may be strings or {text: ...}).
                foreach ($items as $item) {
                    $text = is_array($item) ? ($item['text'] ?? '') : $item;
                    if (trim((string) $text) === '') {
                        return 'ordering (SR): blank item text';
                    }
                    if (source_meta_reference::contains((string) $text)) {
                        return 'ordering (SR): item text contains source meta-reference';
                    }
                }
                // V20 #7: enforce the exact configured item count - the per-generation override
                // (M-26) if set (> 0), otherwise the admin default.
                $override = (int) ($typesettings['sritemcount'] ?? 0);
                $expected = $override > 0 ? $override : (int) (get_config('local_artqtml', 'sritemcount') ?: 4);
                if (count($items) !== $expected) {
                    return 'ordering (SR): expected exactly ' . $expected . ' items, got ' . count($items);
                }
                return null;

            case 'RV':
                // V20 #6: a short-answer question with no accepted answer is unanswerable.
                if (trim((string) ($data['answer'] ?? '')) === '') {
                    return 'shortanswer (RV): empty answer';
                }
                return null;

            case 'EH':
                // Essay: the non-blank question text checked above is the only hard requirement
                // (graderinfo is optional marker guidance).
                return null;

            default:
                // V20 #6: an unknown/unsupported type code must be rejected, not silently passed.
                return 'unsupported type code: ' . $typecode;
        }
    }
}
