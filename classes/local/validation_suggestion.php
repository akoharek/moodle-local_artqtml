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
 * The single source of truth for the validator's suggestion enum.
 *
 * The three machine keys the validator may return live here and nowhere else: the Gemini response
 * Schema ({@see \local_artqtml\task\validate_questions_task::build_schema()}), the validator
 * Prompt assembly and every UI display path all read {@see self::VALUES} / {@see self::label()}
 * From this class, so the schema's value set and the prompt's value set are guaranteed identical.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Canonical list + display helper for the validator's suggestion values.
 */
class validation_suggestion {
    /** @var string the question is acceptable as generated ( "Elfogadható"). */
    public const ACCEPTED = 'accepted';

    /** @var string the question needs a teacher's edit before use ( "Módosítandó"). */
    public const NEEDS_REVIEW = 'needs_review';

    /** @var string the question should be discarded ( "Törlendő"). */
    public const REJECTED = 'rejected';

    /**
     * Helper.
     *
     * @var string the plugin's own "no verdict yet" marker (/ "Nem értékelt").
     */
    public const NOT_EVALUATED = 'not_evaluated';

    /**
     * Helper.
     *
     * @var string display-only marker for an edited question .
     *
     * Never stored in validationsuggestion; the approve page renders it in place of the AI verdict
     * When the `edited` flag is set.
     */
    public const EDITED = 'edited';

    /**
     * The three suggestion values the validator may return, in canonical order.
     *
     * Exactly three members, and this is the set the Gemini response schema enum is built from.
     * Do not add a fourth or reorder without a spec change: validate_questions_suggestion_test
     * Asserts this set verbatim and checks the assembled prompt names exactly these.
     *
     * @var string[]
     */
    public const VALUES = [self::ACCEPTED, self::NEEDS_REVIEW, self::REJECTED];

    /**
     * Every value that can appear in the UI: the three AI verdicts plus the two plugin-side markers.
     *
     * @var string[]
     */
    public const DISPLAY = [self::ACCEPTED, self::NEEDS_REVIEW, self::REJECTED, self::NOT_EVALUATED];

    /**
     * Normalise a raw value (e.g. a hallucinated suggestion from Gemini) to a valid key.
     *
     * @param string|null $value the raw returned/stored value
     * @param string|null $default returned when $value is not one of the three keys
     * @return string|null a member of {@see self::VALUES}, or $default
     */
    public static function normalise(?string $value, ?string $default = null): ?string {
        return in_array((string) $value, self::VALUES, true) ? (string) $value : $default;
    }

    /**
     * label.
     *
     * @param string $value one of {@see self::DISPLAY}, or self::EDITED
     * @return string
     */
    public static function label(string $value): string {
        return get_string('validationstatus_' . $value, 'local_artqtml');
    }

    /**
     * Suggestion -> Bootstrap badge CSS class (: green/amber/red/grey).
     *
     * @param string $value one of {@see self::DISPLAY}, or self::EDITED
     * @return string
     */
    public static function badge_class(string $value): string {
        $map = [
            self::ACCEPTED      => 'badge-success',
            self::NEEDS_REVIEW  => 'badge-warning',
            self::REJECTED      => 'badge-danger',
            self::NOT_EVALUATED => 'badge-secondary',
            self::EDITED        => 'badge-info',
        ];

        return $map[$value] ?? 'badge-secondary';
    }
}
