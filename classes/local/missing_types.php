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
     * Read from countdiscrepancy, which save_questions_task::store_save_discrepancy() writes at
     * The only point where the answer is final - what actually reached the draft bank, not what
     * The model returned. A surplus is ignored here: it is a discrepancy worth showing, but there
     * Is nothing to re-run.
     *
     * @param \stdClass $generation
     * @return array<string, int> type code => number of questions still missing, largest first
     */
    public static function shortfall(\stdClass $generation): array {
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
     * The original settings, narrowed to the types that fell short.
     *
     * Every other type's row of the grid is zeroed, so the new generation's settings page opens
     * Asking only for what is missing. Note what this deliberately does not do: it does not
     * Decide, for a type that delivered some of its questions, WHICH difficulty level went
     * Missing. That cannot be measured - local_artqtml_questions.difficultylabel is free text
     * Written by the model, not the level key the teacher picked - and guessing it would be the
     * System making the teacher's decision again, which is the whole reason the grid exists.
     *
     * So the rule is: where the type asked for one level only, the shortfall unambiguously
     * Belongs to that level and the number is reduced to it. Where it spans several levels, the
     * Original row comes back unchanged and the teacher adjusts it on the settings page - which is
     * A page they are being shown anyway, precisely so they can.
     *
     * @param array $settings the original generation's decoded settings
     * @param array<string, int> $shortfall from {@see self::shortfall()}
     * @return array settings to store on the new generation
     */
    public static function narrowed_settings(array $settings, array $shortfall): array {
        $matrix = $settings['matrix'] ?? [];

        foreach (question_types::CODES as $code) {
            if (!isset($shortfall[$code])) {
                foreach (array_keys((array) ($matrix[$code] ?? [])) as $level) {
                    $matrix[$code][$level] = 0;
                }
                $settings['counts'][$code] = 0;
                continue;
            }

            $row = (array) ($matrix[$code] ?? []);
            $nonzero = array_keys(array_filter($row, static fn($count): bool => (int) $count > 0));
            if (count($nonzero) === 1) {
                $matrix[$code][$nonzero[0]] = $shortfall[$code];
            }

            $settings['counts'][$code] = $row === []
                // Free text mode has no grid; the shortfall is the whole answer there.
                ? $shortfall[$code]
                : array_sum($matrix[$code]);
        }

        $settings['matrix'] = $matrix;

        // The generation-wide per-level totals are derived from the grid in
        // local_artqtml_build_settings(); derived again here, from the same grid, so the stored
        // settings cannot describe a request the grid does not.
        // Easy/Medium/Hard scale totals.
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
     * "2 Multiple choice, 1 Ordering" - the shortfall in words, for the button's confirmation.
     *
     * @param array<string, int> $shortfall
     * @return string
     */
    public static function describe(array $shortfall): string {
        $parts = [];
        foreach ($shortfall as $code => $count) {
            $parts[] = $count . ' ' . question_types::label($code);
        }

        return implode(', ', $parts);
    }
}
