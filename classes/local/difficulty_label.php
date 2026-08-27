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
 * The single source of truth for the scale difficulty_label enum.
 *
 * The three machine keys live here and nowhere else: the Claude response schema
 * ({@see \local_artqtml\local\question_schema}), the save path and every UI display path all read
 * {@see self::VALUES} / {@see self::label()} from this class.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Canonical list + display helper for easy / medium / hard difficulty labels.
 */
class difficulty_label {
    /** @var string */
    public const EASY = 'easy';

    /** @var string */
    public const MEDIUM = 'medium';

    /** @var string */
    public const HARD = 'hard';

    /**
     * The three scale difficulty values, in canonical order.
     *
     * @var string[]
     */
    public const VALUES = [self::EASY, self::MEDIUM, self::HARD];

    /**
     * Lowercase aliases (English and Hungarian) mapped to canonical keys.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'easy' => self::EASY,
        'medium' => self::MEDIUM,
        'hard' => self::HARD,
        'könnyű' => self::EASY,
        'konnyu' => self::EASY,
        'közepes' => self::MEDIUM,
        'kozepes' => self::MEDIUM,
        'nehéz' => self::HARD,
        'nehez' => self::HARD,
    ];

    /**
     * Normalise a raw AI or legacy value to a valid key.
     *
     * @param string|null $value the raw returned/stored value
     * @param string|null $default returned when $value is empty or not recognised
     * @return string|null a member of {@see self::VALUES}, or $default
     */
    public static function normalise(?string $value, ?string $default = null): ?string {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        $key = mb_strtolower(trim($value), 'UTF-8');
        if (in_array($key, self::VALUES, true)) {
            return $key;
        }

        return self::ALIASES[$key] ?? $default;
    }

    /**
     * Localised display label for a stored or raw value.
     *
     * @param string $value raw or canonical value
     * @return string
     */
    public static function label(string $value): string {
        $normalised = self::normalise($value, null);
        if ($normalised === null) {
            return $value;
        }

        return get_string('scale_' . $normalised, 'local_artqtml');
    }
}
