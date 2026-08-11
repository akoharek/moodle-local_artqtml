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
 * License DB persistence: the singleton license record and .lic upload
 * (split out of license_checker - functional spec ch.10).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\license;

use local_artqtml\local\license\license_constants;

/**
 * Reads/writes the single local_artqtml_license row and validates uploads.
 */
class license_persistence {
    /**
     * Fetch the singleton license record, creating an empty ("none") one if it doesn't exist.
     *
     * @return \stdClass
     */
    public static function get_or_create_record(): \stdClass {
        global $DB;

        $records = $DB->get_records('local_artqtml_license', null, 'id ASC', '*', 0, 1);
        if ($records) {
            return reset($records);
        }

        $now = time();
        $record = new \stdClass();
        $record->edition = null;
        $record->issuedto = null;
        $record->issuedtourl = null;
        $record->issuedat = null;
        $record->expiresat = null;
        $record->activatedat = null;
        $record->questionlimit = null;
        $record->questionsvalidated = 0;
        $record->licensejson = null;
        $record->status = 'none';
        $record->lastcheckedtime = null;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->id = $DB->insert_record('local_artqtml_license', $record);

        return $record;
    }

    /**
     * Parse an ISO 8601 (YYYY-MM-DD) date string into a UTC-midnight unix timestamp.
     *
     * @param mixed $value
     * @return int|null null if $value is empty/unparsable
     */
    protected static function parse_date($value): ?int {
        if (empty($value) || !is_string($value)) {
            return null;
        }

        $date = \DateTime::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $date !== false ? $date->getTimestamp() : null;
    }

    /**
     * Validate and store a newly uploaded .lic file (Lic-001/002/015).
     *
     * @param string $rawcontent raw content of the uploaded file
     * @return array{success: bool, error: ?string}
     */
    public static function upload(string $rawcontent): array {
        $decoded = json_decode(trim($rawcontent), true);
        if (!is_array($decoded)) {
            return ['success' => false, 'error' => get_string('errorlicenseinvalidjson', 'local_artqtml')];
        }

        $edition = $decoded['edition'] ?? '';
        if (!in_array($edition, license_constants::EDITIONS, true)) {
            return ['success' => false, 'error' => get_string('errorlicenseinvalidedition', 'local_artqtml')];
        }

        if (!license_crypto::verify_signature($decoded)) {
            return ['success' => false, 'error' => get_string('errorlicenseinvalidsignature', 'local_artqtml')];
        }

        // M-02: a validly-signed license could still be missing/malformed metadata (e.g. a
        // blank issued_to, or an unparsable issued_at) - checked once the signature itself is
        // known to be trustworthy, so this can't be used to smuggle a signature-bypass either.
        $issuedto = trim((string) ($decoded['issued_to'] ?? ''));
        $issuedtourl = trim((string) ($decoded['issued_to_url'] ?? ''));
        $issuedat = self::parse_date($decoded['issued_at'] ?? null);
        if ($issuedto === '' || $issuedtourl === '' || $issuedat === null) {
            return ['success' => false, 'error' => get_string('errorlicenseinvalidmetadata', 'local_artqtml')];
        }

        $expiresat = null;
        if ($edition === 'annual') {
            $expiresat = self::parse_date($decoded['expires_at'] ?? null);
            if ($expiresat === null) {
                return ['success' => false, 'error' => get_string('errorlicenseinvalidexpiry', 'local_artqtml')];
            }
        }

        $questionlimit = null;
        if ($edition === 'question_limit') {
            $questionlimit = (int) ($decoded['question_limit'] ?? 0);
            if ($questionlimit <= 0) {
                return ['success' => false, 'error' => get_string('errorlicenseinvalidlimit', 'local_artqtml')];
            }
        }

        global $DB;

        $record = self::get_or_create_record();
        $now = time();

        // Lic-005 (C4): activatedat anchors the annual 365-day enforcement window, so it must be
        // the FIRST activation time of this particular signed license. Re-uploading the identical
        // license (same edition/issued_to/issued_at) must not reset that clock - only a genuinely
        // different license (e.g. a renewal with a new issued_at) starts a fresh window.
        $sameexistinglicense = !empty($record->activatedat)
            && (string) $record->edition === $edition
            && (string) $record->issuedto === $issuedto
            && (int) $record->issuedat === (int) $issuedat;
        $activatedat = $sameexistinglicense ? (int) $record->activatedat : $now;

        $record->edition = $edition;
        $record->issuedto = $issuedto;
        $record->issuedtourl = $issuedtourl;
        $record->issuedat = $issuedat;
        $record->expiresat = $expiresat;
        $record->activatedat = $activatedat;
        $record->questionlimit = $questionlimit;
        // The questionsvalidated field is deliberately left untouched: it is a lifetime counter that
        // must survive license replacement (Lic-011).
        $record->licensejson = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $record->timemodified = $now;

        $DB->update_record('local_artqtml_license', $record);

        license_status_policy::refresh_cached_status();

        return ['success' => true, 'error' => null];
    }
}
