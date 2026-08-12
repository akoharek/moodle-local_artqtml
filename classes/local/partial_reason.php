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
 * User-facing reasons why a partly successful generation fell short.
 *
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Builds plain-language reasons from log rows the pipeline already writes.
 *
 * After a successful save, pendingdata is cleared and countdiscrepancy only stores
 * Requested/received counts. The why lives in local_artqtml_log:
 * Type_generation_failed, question_rejected, and claude_call_completed outcomes.
 * Raw semantic-validator English strings stay in the log; this class maps them to
 * Lang strings rather than printing them.
 */
class partial_reason {
    /**
     * Plain-text reason lines for the partial status panel, one cause per shortfall type.
     *
     * Priority per type: API/content failure, then semantic rejects, then model undershoot.
     * Types with no logged cause are omitted - the count line already names them.
     *
     * @param int $generationid
     * @return string[]
     */
    public static function messages(int $generationid): array {
        global $DB;

        $failed = [];
        $rejected = [];
        $outcomes = [];

        $logs = $DB->get_records_select(
            'local_artqtml_log',
            'generationid = ? AND event IN (?, ?, ?)',
            [$generationid, 'type_generation_failed', 'question_rejected', 'claude_call_completed'],
            'id ASC'
        );

        foreach ($logs as $log) {
            $data = json_decode((string) $log->data, true);
            if (!is_array($data)) {
                continue;
            }

            if ($log->event === 'type_generation_failed') {
                $code = (string) ($data['typecode'] ?? '');
                if (in_array($code, question_types::CODES, true)) {
                    $failed[$code] = (string) ($data['kind'] ?? 'transport');
                }
                continue;
            }

            if ($log->event === 'question_rejected') {
                $code = (string) ($data['typecode'] ?? '');
                if (in_array($code, question_types::CODES, true)) {
                    $rejected[$code] = ($rejected[$code] ?? 0) + 1;
                }
                continue;
            }

            if ($log->event === 'claude_call_completed' && is_array($data['outcomes'] ?? null)) {
                $outcomes = $data['outcomes'];
            }
        }

        $settingscounts = self::requested_counts($generationid);
        $messages = [];

        foreach (question_types::CODES as $code) {
            $label = question_types::label($code);

            if (isset($failed[$code])) {
                $stringid = $failed[$code] === 'content'
                    ? 'generationpartialreasoncontent'
                    : 'generationpartialreasontransport';
                $messages[] = get_string($stringid, 'local_artqtml', $label);
                continue;
            }

            if (($rejected[$code] ?? 0) > 0) {
                $messages[] = get_string('generationpartialreasonrejected', 'local_artqtml', (object) [
                    'type'  => $label,
                    'count' => $rejected[$code],
                ]);
                continue;
            }

            $outcome = is_array($outcomes[$code] ?? null) ? $outcomes[$code] : null;
            if ($outcome === null || ($outcome['result'] ?? '') !== 'ok') {
                continue;
            }

            $got = (int) ($outcome['count'] ?? 0);
            $wanted = (int) ($settingscounts[$code] ?? 0);
            if ($wanted > 0 && $got < $wanted) {
                $messages[] = get_string('generationpartialreasonundershoot', 'local_artqtml', (object) [
                    'type'   => $label,
                    'got'    => $got,
                    'wanted' => $wanted,
                ]);
            }
        }

        return $messages;
    }

    /**
     * Markup for the partial panel: a short heading plus a list, or empty when nothing useful
     * Can be said beyond the requested/received line.
     *
     * @param int $generationid
     * @return string HTML fragment (already escaped via get_string / s())
     */
    public static function render(int $generationid): string {
        $messages = self::messages($generationid);
        if ($messages === []) {
            return '';
        }

        $items = '';
        foreach ($messages as $message) {
            $items .= \html_writer::tag('li', $message);
        }

        return \html_writer::tag('p', get_string('generationpartialreasonheading', 'local_artqtml'), [
                'class' => 'mb-1 mt-2 font-weight-bold',
            ]) . \html_writer::tag('ul', $items, ['class' => 'mb-2', 'data-region' => 'partial-reasons']);
    }

    /**
     * Per-type requested counts from the generation's settings JSON.
     *
     * @param int $generationid
     * @return array<string, int>
     */
    protected static function requested_counts(int $generationid): array {
        global $DB;

        $settingsjson = $DB->get_field('local_artqtml_generations', 'settings', ['id' => $generationid]);
        $settings = json_decode((string) $settingsjson, true);
        if (!is_array($settings)) {
            return [];
        }

        $counts = [];
        foreach (question_types::CODES as $code) {
            $counts[$code] = (int) ($settings['counts'][$code] ?? 0);
        }

        return $counts;
    }
}
