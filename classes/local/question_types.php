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
 * Single source of truth for the three supported question types (IH/FE/SR).
 *
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Maps the spec's two-letter type codes (IH/FE/SR) to Moodle qtypes and lang strings.
 */
class question_types {
    /** @var string[] ordered list of the three type codes, as they should appear in the UI. */
    public const CODES = ['IH', 'FE', 'SR'];

    /** @var array<string,string> type code -> real Moodle qtype plugin name. */
    public const QTYPE = [
        'IH' => 'truefalse',
        'FE' => 'multichoice',
        'SR' => 'ordering',
    ];

    /** @var array<string,string> type code -> lang string key for the type label. */
    public const LANG_KEY = [
        'IH' => 'qtype_ih',
        'FE' => 'qtype_fe',
        'SR' => 'qtype_sr',
    ];

    /**
     * Human readable label for a type code, e.g. "Igaz/Hamis" (True/False).
     *
     * @param string $code one of self::CODES
     * @return string
     */
    public static function label(string $code): string {
        $key = self::LANG_KEY[$code] ?? null;
        return $key ? get_string($key, 'local_artqtml') : $code;
    }

    /**
     * Whether the "multiple attempts" toggle applies to this type.
     *
     * IH only has two possible answers, so retries are not meaningful.
     *
     * @param string $code one of self::CODES
     * @return bool
     */
    public static function supports_retry(string $code): bool {
        return $code !== 'IH';
    }

    /**
     * supports hints.
     *
     * @param string $code one of self::CODES
     * @return bool
     */
    public static function supports_hints(string $code): bool {
        return in_array($code, ['FE', 'SR'], true);
    }

    /**
     * Whether a per-answer explanation can be stored for this type at all.
     *
     * - FE is qtype_multichoice, which keeps one feedback per answer.
     * - IH is qtype_truefalse, whose two answers have feedbacktrue and feedbackfalse.
     * - SR is qtype_ordering, which has combined feedback only - a sequence item has no
     * Feedback field, so an explanation generated for one would have nowhere to go.
     *
     * @param string $code
     * @return bool
     */
    public static function supports_option_explanation(string $code): bool {
        return in_array($code, ['IH', 'FE'], true);
    }

    /**
     * format count discrepancy.
     *
     * @param array $discrepancies list of ['type' => code, 'requested' => int, 'received' => int]
     * @return string empty string if $discrepancies is empty
     */
    public static function format_count_discrepancy(array $discrepancies): string {
        if (empty($discrepancies)) {
            return '';
        }

        $requestedparts = [];
        $receivedparts = [];
        foreach ($discrepancies as $entry) {
            $label = self::label((string) ($entry['type'] ?? ''));
            $requestedparts[] = ((int) ($entry['requested'] ?? 0)) . ' ' . $label;
            $receivedparts[] = ((int) ($entry['received'] ?? 0)) . ' ' . $label;
        }

        return get_string('countdiscrepancywarning', 'local_artqtml', (object) [
            'requested' => implode(', ', $requestedparts),
            'received'  => implode(', ', $receivedparts),
        ]);
    }
}
