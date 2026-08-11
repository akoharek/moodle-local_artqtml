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
 * License tab HTML rendering - presentation only, no business logic (split out of
 * license_checker - functional spec ch.10, Lic-003/012).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\license;

use local_artqtml\local\license\license_constants;

/**
 * Renders the License admin tab's status and file-integrity panels.
 */
class license_renderer {
    /**
     * Render the Licensz tab's status panel: type, institution, dates, current state, and a
     * usage progress bar for question_limit editions (Lic-003/012).
     *
     * @return string
     */
    public static function status_panel(): string {
        $status = license_status_policy::status();
        $record = $status['record'];

        if (empty($record->edition)) {
            return \html_writer::tag('p', get_string('licensenonuploaded', 'local_artqtml'), ['class' => 'text-muted']);
        }

        $blocked = in_array($status['state'], license_constants::BLOCKING_STATES, true);
        $badgeclass = $blocked ? 'badge-danger' : (!empty($status['warning']) ? 'badge-warning' : 'badge-success');

        // Glob-042 / Lic-025 (2026-08-07): licence panel dates use the same plugin datetimeformat
        // as every other UI date (ÉÉÉÉ.HH.NN óó:pp), not Moodle's locale short-date.
        $dateformat = get_string('datetimeformat', 'local_artqtml');

        $rows = [];
        $rows[] = [
            get_string('licenseedition', 'local_artqtml'),
            get_string('licenseedition_' . $record->edition, 'local_artqtml'),
        ];
        $rows[] = [get_string('licenseissuedto', 'local_artqtml'), \s($record->issuedto)];
        $rows[] = [get_string('licenseissuedtourl', 'local_artqtml'), \s($record->issuedtourl)];
        $rows[] = [
            get_string('licenseissuedat', 'local_artqtml'),
            $record->issuedat ? userdate($record->issuedat, $dateformat) : '-',
        ];
        $rows[] = [
            get_string('licensestatus', 'local_artqtml'),
            \html_writer::span(get_string('licensestate_' . $status['state'], 'local_artqtml'), 'badge ' . $badgeclass),
        ];

        if ($record->edition === 'annual') {
            $rows[] = [
                get_string('licenseexpiresat', 'local_artqtml'),
                $record->expiresat ? userdate($record->expiresat, $dateformat) : '-',
            ];
            if (isset($status['daysremaining'])) {
                $rows[] = [get_string('licensedaysremaining', 'local_artqtml'), $status['daysremaining']];
            }
        }

        $table = new \html_table();
        // Glob-034: fluid + wrapping, never wider than its container.
        $table->attributes['class'] = 'generaltable artqtml-table';
        $table->data = $rows;
        $html = \html_writer::table($table);

        if ($record->edition === 'question_limit') {
            $used = $status['used'] ?? (int) $record->questionsvalidated;
            $limit = $status['limit'] ?? (int) $record->questionlimit;
            $pct = $limit > 0 ? min(100, (int) round(($used / $limit) * 100)) : 100;
            $barclass = $status['state'] === 'exhausted' ? 'bg-danger' : (!empty($status['warning']) ? 'bg-warning' : 'bg-success');

            $html .= \html_writer::div(
                get_string('licensequestionusage', 'local_artqtml', (object) ['used' => $used, 'limit' => $limit]),
                'mb-1'
            );
            $html .= \html_writer::div(
                \html_writer::div('', 'progress-bar ' . $barclass, ['style' => 'width: ' . $pct . '%']),
                'progress mb-3'
            );
        }

        return $html;
    }

    /**
     * Render the Licensz tab's "File integrity" section: a green checkmark if every file in the
     * license's manifest matches, or - security: never the modified/missing file names/paths
     * themselves, to any user including an admin with :configure - a generic message, an error
     * code, and a button to download the encrypted report the vendor can decrypt.
     *
     * Renders nothing (empty string) when there's no license, or the current license predates
     * file integrity checking (no "files" manifest to check against) - there's nothing
     * meaningful to report either way.
     *
     * @return string
     */
    public static function file_integrity_panel(): string {
        $record = license_persistence::get_or_create_record();
        if (empty($record->edition)) {
            return '';
        }

        $decoded = json_decode((string) $record->licensejson, true);
        if (!is_array($decoded) || empty($decoded['files'])) {
            return '';
        }

        $integrity = license_file_integrity::verify($record);

        if ($integrity['ok']) {
            return \html_writer::div(
                '✓ ' . get_string('licensefileintegrityok', 'local_artqtml'),
                'alert alert-success'
            );
        }

        $errorcode = license_file_integrity::integrity_error_code($integrity);

        $html = \html_writer::div(
            get_string('licensetampered_generic', 'local_artqtml') . ' ' .
            get_string('licensetampered_errorcode', 'local_artqtml', $errorcode),
            'alert alert-danger'
        );

        $exporturl = new \moodle_url('/local/artqtml/license.php', [
            'exportintegrity' => 1,
            'sesskey'         => sesskey(),
        ]);
        $html .= \html_writer::div(
            \html_writer::link(
                $exporturl,
                get_string('licenseexportintegrity', 'local_artqtml'),
                ['class' => 'btn btn-outline-danger btn-sm']
            ),
            'mb-3'
        );

        return $html;
    }
}
