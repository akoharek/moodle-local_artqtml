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
 * The single source of truth for the validator's suggestion enum (Val-017/Val-018).
 *
 * The three machine keys the validator may return live here and nowhere else: the Gemini response
 * schema ({@see \local_artqtml\task\validate_questions_task::build_schema()}), the validator
 * prompt assembly and every UI display path all read {@see self::VALUES} / {@see self::label()}
 * from this class, so the schema's value set and the prompt's value set are guaranteed identical.
 *
 * This is the same fix that {@see \local_artqtml\local\problem_category} applies to the
 * problem_category enum, applied to the second value set that had the same two-source problem: the
 * three values used to be spelled out as English prose inside the admin-editable prompt template
 * while the schema read them from a code constant, with nothing checking the two against each other.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Canonical list + display helper for the validator's suggestion values.
 */
class validation_suggestion {
    /** @var string the question is acceptable as generated (Val-017 "Elfogadható"). */
    public const ACCEPTED = 'accepted';

    /** @var string the question needs a teacher's edit before use (Val-017 "Módosítandó"). */
    public const NEEDS_REVIEW = 'needs_review';

    /** @var string the question should be discarded (Val-017 "Törlendő"). */
    public const REJECTED = 'rejected';

    /**
     * @var string the plugin's own "no verdict yet" marker (Val-013/Val-017 "Nem értékelt").
     *
     * Deliberately NOT part of {@see self::VALUES}: the validator never returns it. It is the
     * column default for a question that has not been validated, and the value a question reverts
     * to when a teacher edits it (Jov-026). It has a label, so it appears in {@see self::DISPLAY}.
     */
    public const NOT_EVALUATED = 'not_evaluated';

    /**
     * @var string display-only marker for an edited question (Jov-027).
     *
     * Never stored in validationsuggestion; the approve page renders it in place of the AI verdict
     * when the `edited` flag is set.
     */
    public const EDITED = 'edited';

    /**
     * The three suggestion values the validator may return, in canonical order (Val-017).
     *
     * Exactly three members, and this is the set the Gemini response schema enum is built from.
     * Do not add a fourth or reorder without a spec change: validate_questions_suggestion_test
     * asserts this set verbatim and checks the assembled prompt names exactly these.
     *
     * @var string[]
     */
    public const VALUES = [self::ACCEPTED, self::NEEDS_REVIEW, self::REJECTED];

    /**
     * Every value that can appear in the UI: the three AI verdicts plus the two plugin-side markers.
     *
     * Drives the approve page's status-count summary, which must list all four storable states
     * (Val-017's "Elfogadható / Módosítandó / Törlendő / Nem értékelt").
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
     * Human-readable label for a suggestion key, from a lang string (Val-017) - the raw machine
     * key (e.g. "needs_review") must never reach the UI.
     *
     * @param string $value one of {@see self::DISPLAY}, or self::EDITED
     * @return string
     */
    public static function label(string $value): string {
        return get_string('validationstatus_' . $value, 'local_artqtml');
    }

    /**
     * Suggestion -> Bootstrap badge CSS class (Jov-007: green/amber/red/grey).
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
