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
 * The single source of truth for the validation problem_category enum.
 *
 * The four fixed machine keys live here and nowhere else: the Gemini response schema
 * ({@see \local_artqtml\task\validate_questions_task::build_schema()}), the validator prompt
 * Assembly, and every UI display path all read {@see self::VALUES} / {@see self::label()} from
 * This class, so the schema's value set and the prompt's value set are guaranteed identical
 * (a technikai melléklet: "Ez a négy érték a JSON séma egyetlen forrása").
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Canonical list + display helper for the four validation problem categories.
 */
class problem_category {
    /** @var string the "no problem" category the AI returns for an acceptable question . */
    public const OK = 'ok';

    /**
     * The four fixed problem_category enum values, in canonical order.
     *
     * Exactly four members; none is an empty string (an empty string is not a permitted Gemini
     * Structured-output enum value - it fails schema validation with
     * "problem_category.enum[0]: cannot be empty"). Do not add a fifth or reorder without a spec
     * Change: PROB-F004 asserts this set verbatim.
     *
     * @var string[]
     */
    public const VALUES = ['ok', 'factual_error', 'ambiguous_wording', 'other'];

    /**
     * Normalise a raw value (e.g. a hallucinated or legacy category from Gemini) to a valid key.
     *
     * @param string|null $value the raw stored/returned value
     * @param string|null $default returned when $value is not one of the four keys
     * @return string|null a member of {@see self::VALUES}, or $default
     */
    public static function normalise(?string $value, ?string $default = null): ?string {
        return in_array((string) $value, self::VALUES, true) ? (string) $value : $default;
    }

    /**
     * label.
     *
     * @param string $value one of {@see self::VALUES}
     * @return string
     */
    public static function label(string $value): string {
        return get_string('problemcategory_' . $value, 'local_artqtml');
    }
}
