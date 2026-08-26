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
 * Semantic validation of AI-generated question data (split out of question_importer - ).
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\question;

defined('MOODLE_INTERNAL') || die();

/**
 * Checks that schema-valid AI JSON is also semantically usable before it is ever imported.
 */
class question_semantic_validator {
    /**
     * validate.
     *
     * @param string $typecode IH/FE/SR
     * @param array $data decoded per-type fields from the AI response
     * @param array $typesettings this type's generation settings - only SR's per-generation
     * 'sritemcount' override is read, to enforce the exact item count
     * @return string|null null if valid, otherwise a short human-readable reason it was rejected
     * (logged as a question_rejected event, never shown to a user - kept in English)
     */
    public static function validate(string $typecode, array $data, array $typesettings = []): ?string {
        if (trim((string) ($data['questiontext'] ?? '')) === '') {
            return $typecode . ': empty questiontext';
        }

        // Source-document meta-references ("szöveg szerint", "according to the text") are
        // Unprofessional scaffolding. The prompt forbids them and the cleaner strips a leading
        // Clause; anything still present in the stem is rejected here rather than imported.
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
                $options = $data['options'] ?? [];
                // Reject an empty option array or any blank option text outright.
                if (!is_array($options) || count($options) === 0) {
                    return 'multichoice (FE): no options';
                }
                foreach ($options as $option) {
                    if (trim((string) ($option['text'] ?? '')) === '') {
                        return 'multichoice (FE): blank option text';
                    }
                    if (source_meta_reference::contains((string) ($option['text'] ?? ''))) {
                        return 'multichoice (FE): option text contains source meta-reference';
                    }
                }

                $correctcount = 0;
                foreach ($options as $option) {
                    if (!empty($option['correct'])) {
                        $correctcount++;
                    }
                }
                if ($correctcount !== 1) {
                    return "multichoice (FE): expected exactly 1 correct option, got $correctcount";
                }

                // Enforce the admin-configured FE option-count range server-side.
                $min = (int) (get_config('local_artqtml', 'fefminoptions') ?: 2);
                $max = (int) (get_config('local_artqtml', 'fefmaxoptions') ?: 5);
                $count = count($options);
                if ($count < $min || $count > $max) {
                    return "multichoice (FE): $count options outside the configured range {$min}-{$max}";
                }
                return null;

            case 'SR':
                $items = $data['items'] ?? [];
                if (!is_array($items) || count($items) < 2) {
                    return 'ordering (SR): expected at least 2 items, got ' . (is_array($items) ? count($items) : 0);
                }
                // Reject any blank item text (items may be strings or {text: ...}).
                foreach ($items as $item) {
                    $text = is_array($item) ? ($item['text'] ?? '') : $item;
                    if (trim((string) $text) === '') {
                        return 'ordering (SR): blank item text';
                    }
                    if (source_meta_reference::contains((string) $text)) {
                        return 'ordering (SR): item text contains source meta-reference';
                    }
                }
                // Enforce the exact configured item count - the per-generation override if set (> 0), otherwise the admin default.
                $override = (int) ($typesettings['sritemcount'] ?? 0);
                $expected = $override > 0 ? $override : (int) (get_config('local_artqtml', 'sritemcount') ?: 4);
                if (count($items) !== $expected) {
                    return 'ordering (SR): expected exactly ' . $expected . ' items, got ' . count($items);
                }
                return null;

            default:
                // An unknown/unsupported type code must be rejected, not silently passed.
                return 'unsupported type code: ' . $typecode;
        }
    }
}
