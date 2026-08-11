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
 * Single source of truth for the six supported question types (functional spec ch.1/3.3).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Maps the spec's two-letter type codes (IH/FE/FT/SR/EH/RV) to Moodle qtypes and lang strings.
 */
class question_types {
    /** @var string[] ordered list of the six type codes, as they should appear in the UI. */
    public const CODES = ['IH', 'FE', 'FT', 'SR', 'EH', 'RV'];

    /** @var array<string,string> type code -> real Moodle qtype plugin name. */
    public const QTYPE = [
        'IH' => 'truefalse',
        'FE' => 'multichoice',
        'FT' => 'multichoice',
        'SR' => 'ordering',
        'EH' => 'essay',
        'RV' => 'shortanswer',
    ];

    /** @var array<string,string> type code -> lang string key for the type label. */
    public const LANG_KEY = [
        'IH' => 'qtype_ih',
        'FE' => 'qtype_fe',
        'FT' => 'qtype_ft',
        'SR' => 'qtype_sr',
        'EH' => 'qtype_eh',
        'RV' => 'qtype_rv',
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
     * Whether the "multiple attempts" toggle (Beal-014) applies to this type.
     *
     * IH only has two possible answers, so retries are not meaningful (Beal-014/Admin-023).
     *
     * @param string $code one of self::CODES
     * @return bool
     */
    public static function supports_retry(string $code): bool {
        return $code !== 'IH';
    }

    /**
     * Whether Moodle actually supports per-question hints (question_hints) for this type
     * (M-24). Narrower than {@see self::supports_retry()}: qtype_essay's save_question_options()
     * never calls save_hints() at all (manually-graded, no auto "try again" mechanism), so a
     * hint would silently go nowhere for EH even though it does support the retry/penalty toggle.
     *
     * @param string $code one of self::CODES
     * @return bool
     */
    public static function supports_hints(string $code): bool {
        return in_array($code, ['FE', 'FT', 'SR', 'RV'], true);
    }

    /**
     * Whether a per-answer explanation can be stored for this type at all (BL-29).
     *
     * This is not a policy choice, it is where Moodle has a field:
     *
     * - FE and FT are qtype_multichoice, which keeps one feedback per answer. The plugin already
     *   writes an empty string into every one of them.
     * - IH is qtype_truefalse, whose two answers have feedbacktrue and feedbackfalse. Those carry
     *   the admin template today (Admin-022); when this switch is on, the AI's text replaces it.
     * - SR is qtype_ordering, which has **combined** feedback only - correct / partially correct /
     *   incorrect for the whole question. A sequence item has no feedback field, so an explanation
     *   generated for one would have nowhere to go. Measured on the Moodle 4.5 form, 2026-08-02.
     * - RV matches a single string and EH is marked by a person: neither has options to explain.
     *
     * @param string $code
     * @return bool
     */
    public static function supports_option_explanation(string $code): bool {
        return in_array($code, ['IH', 'FE', 'FT'], true);
    }

    /**
     * Render a stored count-discrepancy list (M-08) as a human-readable warning, e.g.
     * "Requested: 3 True/False, 2 Essay — Received: 5 True/False, 0 Essay."
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
