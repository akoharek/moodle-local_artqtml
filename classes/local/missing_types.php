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
 * What a partly successful generation still owes the teacher, and the settings that would ask for it again.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Reads a partial generation's recorded shortfall and narrows its settings down to it.
 */
class missing_types {
    /**
     * How many questions of each type the generation asked for and did not get.
     *
     * Prefers a per-level recount from the stored settings matrix and saved questions
     * ({@see matrix_shortfall()}). Falls back to countdiscrepancy when settings are unavailable.
     *
     * @param \stdClass $generation
     * @return array<string, int> type code => number of questions still missing, largest first
     */
    public static function shortfall(\stdClass $generation): array {
        $matrixshortfall = self::matrix_shortfall($generation);
        if ($matrixshortfall !== []) {
            $missing = [];
            foreach ($matrixshortfall as $code => $levels) {
                $missing[$code] = array_sum($levels);
            }
            arsort($missing);

            return $missing;
        }

        return self::shortfall_from_discrepancy($generation);
    }

    /**
     * Per-type, per-difficulty-level shortfall from the settings matrix and saved questions.
     *
     * @param \stdClass $generation
     * @return array<string, array<string, int>> type => level => missing count
     */
    public static function matrix_shortfall(\stdClass $generation): array {
        $settings = json_decode((string) $generation->settings, true);
        if (!is_array($settings)) {
            return [];
        }

        $matrix = $settings['matrix'] ?? [];
        $saved = self::saved_by_type_level((int) $generation->id);
        $shortfall = [];

        foreach (question_types::CODES as $code) {
            $row = (array) ($matrix[$code] ?? []);
            $requestedtotal = (int) ($settings['counts'][$code] ?? 0);
            $rowsum = array_sum(array_map('intval', $row));

            if ($rowsum === 0 && $requestedtotal > 0) {
                $received = array_sum($saved[$code] ?? []);
                $short = $requestedtotal - $received;
                if ($short > 0) {
                    $shortfall[$code] = ['' => $short];
                }
                continue;
            }

            foreach ($row as $level => $requested) {
                $requested = (int) $requested;
                if ($requested <= 0) {
                    continue;
                }
                $received = (int) ($saved[$code][$level] ?? 0);
                $short = $requested - $received;
                if ($short > 0) {
                    $shortfall[$code][$level] = $short;
                }
            }
        }

        return $shortfall;
    }

    /**
     * The original settings, narrowed to the types and levels that fell short.
     *
     * @param array $settings the original generation's decoded settings
     * @param \stdClass $generation the partly successful source generation
     * @return array settings to store on the new generation
     */
    public static function narrowed_settings(array $settings, \stdClass $generation): array {
        $matrixshortfall = self::matrix_shortfall($generation);
        if ($matrixshortfall === []) {
            $matrixshortfall = self::legacy_matrix_shortfall($settings, self::shortfall_from_discrepancy($generation));
        }

        $matrix = $settings['matrix'] ?? [];

        foreach (question_types::CODES as $code) {
            if (!isset($matrixshortfall[$code])) {
                foreach (array_keys((array) ($matrix[$code] ?? [])) as $level) {
                    $matrix[$code][$level] = 0;
                }
                $settings['counts'][$code] = 0;
                continue;
            }

            $levels = $matrixshortfall[$code];
            if (array_key_exists('', $levels)) {
                $settings['counts'][$code] = (int) $levels[''];
                continue;
            }

            foreach (array_keys((array) ($matrix[$code] ?? [])) as $level) {
                $matrix[$code][$level] = (int) ($levels[$level] ?? 0);
            }
            $settings['counts'][$code] = array_sum($matrix[$code]);
        }

        $settings['matrix'] = $matrix;

        foreach (['easy', 'medium', 'hard'] as $level) {
            $sum = 0;
            foreach ($matrix as $bytype) {
                $sum += (int) ($bytype[$level] ?? 0);
            }
            $settings['difficulty']['scale'][$level] = $sum;
        }

        return $settings;
    }

    /**
     * "2 Multiple choice (Easy), 1 Ordering (Medium)" - the shortfall in words.
     *
     * @param \stdClass $generation
     * @return string
     */
    public static function describe(\stdClass $generation): string {
        $matrixshortfall = self::matrix_shortfall($generation);
        if ($matrixshortfall === []) {
            return self::describe_type_totals(self::shortfall_from_discrepancy($generation));
        }

        $parts = [];
        foreach ($matrixshortfall as $code => $levels) {
            if (array_key_exists('', $levels)) {
                $parts[] = $levels[''] . ' ' . question_types::label($code);
                continue;
            }
            foreach ($levels as $level => $count) {
                $parts[] = $count . ' ' . question_types::label($code) . ' (' . self::level_label($level) . ')';
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Saved question counts grouped by type code and normalised difficulty level key.
     *
     * @param int $generationid
     * @return array<string, array<string, int>>
     */
    protected static function saved_by_type_level(int $generationid): array {
        global $DB;

        $saved = [];
        $records = $DB->get_recordset(
            'local_artqtml_questions',
            ['generationid' => $generationid],
            '',
            'typecode, difficultylabel'
        );
        foreach ($records as $row) {
            $code = (string) $row->typecode;
            if (!in_array($code, question_types::CODES, true)) {
                continue;
            }
            $level = self::level_key_from_label((string) $row->difficultylabel);
            if ($level === null) {
                continue;
            }
            $saved[$code][$level] = ($saved[$code][$level] ?? 0) + 1;
        }
        $records->close();

        return $saved;
    }

    /**
     * Map a stored difficultylabel to a matrix level key.
     *
     * @param string $label
     * @return string|null
     */
    protected static function level_key_from_label(string $label): ?string {
        $canonical = difficulty_label::normalise($label, null);
        if ($canonical !== null) {
            return $canonical;
        }

        return null;
    }

    /**
     * Localised label for a matrix level key.
     *
     * @param string $level
     * @return string
     */
    protected static function level_label(string $level): string {
        if (in_array($level, difficulty_label::VALUES, true)) {
            return difficulty_label::label($level);
        }

        return $level;
    }

    /**
     * Type-level shortfall from countdiscrepancy (legacy path).
     *
     * @param \stdClass $generation
     * @return array<string, int>
     */
    protected static function shortfall_from_discrepancy(\stdClass $generation): array {
        $discrepancies = json_decode((string) $generation->countdiscrepancy, true);
        if (!is_array($discrepancies)) {
            return [];
        }

        $missing = [];
        foreach ($discrepancies as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $code = (string) ($entry['type'] ?? '');
            $short = (int) ($entry['requested'] ?? 0) - (int) ($entry['received'] ?? 0);
            if ($short > 0 && in_array($code, question_types::CODES, true)) {
                $missing[$code] = $short;
            }
        }

        arsort($missing);

        return $missing;
    }

    /**
     * Build a single-level matrix shortfall from type totals when only countdiscrepancy exists.
     *
     * @param array $settings
     * @param array $typeshortfall per-type shortfall counts
     * @return array matrix shortfall keyed by type then difficulty
     */
    protected static function legacy_matrix_shortfall(array $settings, array $typeshortfall): array {
        $matrix = $settings['matrix'] ?? [];
        $result = [];

        foreach ($typeshortfall as $code => $short) {
            $row = (array) ($matrix[$code] ?? []);
            $nonzero = array_keys(array_filter($row, static fn($count): bool => (int) $count > 0));
            if (count($nonzero) === 1) {
                $result[$code] = [$nonzero[0] => $short];
            } else if ($row === []) {
                $result[$code] = ['' => $short];
            }
        }

        return $result;
    }

    /**
     * Format per-type shortfall counts for user-facing messages.
     *
     * @param array $shortfall per-type shortfall counts
     * @return string
     */
    protected static function describe_type_totals(array $shortfall): string {
        $parts = [];
        foreach ($shortfall as $code => $count) {
            $parts[] = $count . ' ' . question_types::label($code);
        }

        return implode(', ', $parts);
    }
}
