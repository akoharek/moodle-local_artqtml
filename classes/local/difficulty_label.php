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
 * The single source of truth for the difficulty_label enum (scale and Bloom).
 *
 * The machine keys live here and nowhere else: the Claude response schema
 * ({@see \local_artqtml\local\question_schema}), the save path and every UI display path all read
 * {@see self::values_for_settings()} / {@see self::label()} from this class.
 *
 * Scale mode uses easy/medium/hard. Bloom mode uses remember/understand/apply. The schema enum is
 * the full vocabulary for the mode - counts stay in the prompt; a level the teacher did not ask for
 * may still come back and is stored as returned for the approver to judge.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Canonical list + display helper for difficulty labels (scale and Bloom).
 */
class difficulty_label {
    /** @var string */
    public const EASY = 'easy';

    /** @var string */
    public const MEDIUM = 'medium';

    /** @var string */
    public const HARD = 'hard';

    /** @var string Bloom: recall a stated fact. */
    public const REMEMBER = 'remember';

    /** @var string Bloom: explain / rephrase / connect. */
    public const UNDERSTAND = 'understand';

    /** @var string Bloom: use in a new situation. */
    public const APPLY = 'apply';

    /**
     * The three scale difficulty values, in canonical order.
     *
     * @var string[]
     */
    public const VALUES = [self::EASY, self::MEDIUM, self::HARD];

    /**
     * The three Bloom difficulty values, in canonical order.
     *
     * @var string[]
     */
    public const BLOOM_VALUES = [self::REMEMBER, self::UNDERSTAND, self::APPLY];

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
        'remember' => self::REMEMBER,
        'understand' => self::UNDERSTAND,
        'apply' => self::APPLY,
        'emlékezés' => self::REMEMBER,
        'emlekezes' => self::REMEMBER,
        'megértés' => self::UNDERSTAND,
        'megertes' => self::UNDERSTAND,
        'alkalmazás' => self::APPLY,
        'alkalmazas' => self::APPLY,
    ];

    /**
     * Schema vocabulary for this generation: full scale or full Bloom set from the mode.
     *
     * Does not narrow to requested counts - those belong in the prompt. The model may return any
     * level in the mode's vocabulary; that value is what gets stored.
     *
     * @param array $settings generation settings
     * @return string[]
     */
    public static function values_for_settings(array $settings): array {
        $mode = (string) ($settings['difficulty']['mode'] ?? 'scale');

        return $mode === 'bloom' ? self::BLOOM_VALUES : self::VALUES;
    }

    /**
     * Normalise a raw AI or legacy value to a valid key.
     *
     * Case / alias cleanup only - does not rewrite one level into another (e.g. remember stays
     * remember even when the teacher asked only for apply).
     *
     * @param string|null $value the raw returned/stored value
     * @param string|null $default returned when $value is empty or not recognised
     * @return string|null a known key, or $default
     */
    public static function normalise(?string $value, ?string $default = null): ?string {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        $key = mb_strtolower(trim($value), 'UTF-8');
        if (in_array($key, self::VALUES, true) || in_array($key, self::BLOOM_VALUES, true)) {
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

        if (in_array($normalised, self::BLOOM_VALUES, true)) {
            return get_string('bloom_' . $normalised, 'local_artqtml');
        }

        return get_string('scale_' . $normalised, 'local_artqtml');
    }
}
