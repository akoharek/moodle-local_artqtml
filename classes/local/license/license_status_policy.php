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
 * License business/status policy: which state a license is in, whether it blocks new
 * generations, cache refresh and the validated-question counter (split out of license_checker -
 * functional spec ch.10, Lic-004-014).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\license;

use local_artqtml\local\license\license_constants;

/**
 * Computes the live license state and enforces the edition-specific rules.
 */
class license_status_policy {
    /**
     * Compute the live status of the currently stored license. Re-verifies the signature on
     * every call (cheap, local-only RSA check) rather than trusting the cached "status" field,
     * per spec 10 "Licenszellenőrzés logikája": every checkpoint re-validates the signature.
     *
     * @return array{state: string, record: \stdClass, warning?: bool, daysremaining?: int,
     *      expiresat?: int, used?: int, limit?: int, remaining?: int, usedpct?: int,
     *      errorcode?: string}
     */
    public static function status(): array {
        $record = license_persistence::get_or_create_record();

        if (empty($record->edition)) {
            return ['state' => 'none', 'record' => $record, 'warning' => false];
        }

        $decoded = json_decode((string) $record->licensejson, true);
        if (!is_array($decoded) || !license_crypto::verify_signature($decoded)) {
            return ['state' => 'invalid', 'record' => $record, 'warning' => false];
        }

        // Lic-029: the licence names the site it was issued for. Checked after the signature, so the
        // value compared is always a signed one - editing the URL in the file invalidates it first.
        // Compared by host only: a scheme change (http -> https), a trailing slash or a "www."
        // prefix must not lock a customer out of a licence that is genuinely theirs.
        if (!self::site_matches((string) ($decoded['issued_to_url'] ?? ''))) {
            return ['state' => 'wrongsite', 'record' => $record, 'warning' => false];
        }

        // File integrity applies equally regardless of edition, so it's checked once here
        // rather than duplicated into each of the three edition-specific status methods below.
        $integrity = license_file_integrity::verify($record);
        if (!$integrity['ok']) {
            // Security: the modified/missing file lists themselves are never returned from here
            // (unlike before) - only an error code any caller can safely show to any user,
            // including an admin with :configure. The actual details are encrypted and logged by
            // license_file_integrity::integrity_error_code(), decryptable only by the vendor - see
            // tools/decrypt_integrity_log.php.
            return [
                'state'     => 'tampered',
                'record'    => $record,
                'warning'   => false,
                'errorcode' => license_file_integrity::integrity_error_code($integrity),
            ];
        }

        if ($record->edition === 'annual') {
            return self::annual_status($record);
        }

        if ($record->edition === 'question_limit') {
            return self::question_limit_status($record);
        }

        // Perpetual: signature validity is the only check (Lic-004).
        return ['state' => 'valid', 'record' => $record, 'warning' => false];
    }

    /**
     * Status computation for an "annual" edition license (Lic-005/007/008).
     *
     * @param \stdClass $record
     * @return array
     */
    protected static function annual_status(\stdClass $record): array {
        $now = time();

        // Lic-005 (C4): do not trust the signed expires_at on its own. An annual license is valid
        // for at most 365 days (YEARSECS) from its activation date, so cap the effective expiry at
        // activatedat + 365 days in addition to expires_at - whichever is earlier wins.
        $effectiveexpiry = $record->expiresat;
        if (!empty($record->activatedat)) {
            $yearlimit = (int) $record->activatedat + YEARSECS;
            if ($effectiveexpiry === null || $yearlimit < $effectiveexpiry) {
                $effectiveexpiry = $yearlimit;
            }
        }

        if ($effectiveexpiry === null || $now >= $effectiveexpiry) {
            return ['state' => 'expired', 'record' => $record, 'warning' => false];
        }

        $daysremaining = (int) floor(($effectiveexpiry - $now) / DAYSECS);
        $warningdays = (int) (get_config('local_artqtml', 'licenseannualwarningdays') ?: 30);

        return [
            'state'         => 'valid',
            'record'        => $record,
            'daysremaining' => $daysremaining,
            // Lic-025: the banner names the date, not the remaining days, so the effective expiry
            // has to travel with the status - it is not simply $record->expiresat, because the
            // activatedat + 365 rule above may move it earlier.
            'expiresat'     => $effectiveexpiry,
            'warning'       => $daysremaining <= $warningdays,
        ];
    }

    /**
     * Whether the licence was issued for this site (Lic-029).
     *
     * @param string $issuedtourl the licence's issued_to_url field
     * @return bool false when either side has no resolvable host - an unusable value must not pass
     */
    protected static function site_matches(string $issuedtourl): bool {
        global $CFG;

        $licencehost = self::normalise_host($issuedtourl);
        $sitehost = self::normalise_host((string) $CFG->wwwroot);

        if ($licencehost === '' || $sitehost === '') {
            return false;
        }

        return $licencehost === $sitehost;
    }

    /**
     * Reduce a URL to a comparable host: lowercase, no scheme, no path, no leading "www.".
     *
     * parse_url() returns no host for a bare "example.org/moodle", so that shape is handled
     * explicitly rather than silently comparing as empty.
     *
     * @param string $url
     * @return string
     */
    protected static function normalise_host(string $url): string {
        $url = trim($url);
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            $host = preg_replace('#^([^/]*)/.*$#', '$1', $url);
        }

        $host = strtolower(trim((string) $host));

        return (string) preg_replace('#^www\.#', '', $host);
    }

    /**
     * How many licensed questions are still available, or null when the licence does not cap them.
     *
     * Lic-028: the generation form needs this before it lets a run start. Null is deliberately not
     * zero - a perpetual or annual licence has no question cap at all, and conflating "no limit"
     * with "nothing left" would block every generation on those editions.
     *
     * @return int|null null when the edition is not question_limit, or the licence is not valid
     */
    public static function remaining_questions(): ?int {
        $status = self::status();

        if ($status['state'] !== 'valid' || !isset($status['remaining'])) {
            return null;
        }

        return (int) $status['remaining'];
    }

    /**
     * Status computation for a "question_limit" edition license (Lic-006/007/008/011).
     *
     * @param \stdClass $record
     * @return array
     */
    protected static function question_limit_status(\stdClass $record): array {
        $used = (int) $record->questionsvalidated;
        $limit = (int) $record->questionlimit;

        if ($limit <= 0 || $used >= $limit) {
            return ['state' => 'exhausted', 'record' => $record, 'used' => $used, 'limit' => $limit, 'warning' => false];
        }

        $warningpct = (int) (get_config('local_artqtml', 'licensequestionwarningpct') ?: 80);
        $pct = (int) round(($used / $limit) * 100);

        return [
            'state'     => 'valid',
            'record'    => $record,
            'used'      => $used,
            'limit'     => $limit,
            'remaining' => max(0, $limit - $used),
            // Lic-026: the banner states the used percentage, so it travels with the status
            // rather than being recomputed at the render site from used/limit.
            'usedpct'   => $pct,
            'warning'   => $pct >= $warningpct,
        ];
    }

    /**
     * Whether starting a new generation should currently be blocked (Lic-009/010).
     *
     * Already-started generations are never blocked by this - callers only consult this at
     * the "start a new generation" checkpoints (list page button, upload.php entry).
     *
     * @return bool
     */
    public static function is_blocked(): bool {
        return in_array(self::status()['state'], license_constants::BLOCKING_STATES, true);
    }

    /**
     * Persist the current live status into the DB record (Lic-014: run once daily by
     * \local_artqtml\task\license_check_task).
     *
     * @return void
     */
    public static function refresh_cached_status(): void {
        global $DB;

        $status = self::status();
        $record = $status['record'];
        $record->status = $status['state'];
        $record->lastcheckedtime = time();

        $DB->update_record('local_artqtml_license', $record);
    }

    /**
     * Bump the lifetime AI-validated-questions counter (Lic-011). Called once per question
     * that receives a real (non not_evaluated) Gemini validation suggestion.
     *
     * While a question_limit edition is active, the stored counter is clamped at the license's
     * question_limit so a single over-limit batch cannot push it past the cap, per spec: "a
     * korlát elérésekor érkező validálási eredmény a számlálót nem lépi túl". The generation
     * itself is not blocked mid-flight - only new generations are blocked once exhausted.
     *
     * @param int $count number of newly-evaluated questions to add
     * @return void
     */
    public static function increment_validated(int $count): void {
        global $DB;

        if ($count <= 0) {
            return;
        }

        $record = license_persistence::get_or_create_record();

        // M-03: both branches use a single atomic UPDATE (no read-modify-write) so concurrent
        // callers can never lose an increment to a race. The question_limit branch clamps at
        // questionlimit entirely in SQL via CASE/WHEN rather than reading the current value into
        // PHP first and writing back a computed min().
        if ($record->edition === 'question_limit' && !empty($record->questionlimit)) {
            $DB->execute(
                "UPDATE {local_artqtml_license}
                    SET questionsvalidated = CASE
                        WHEN questionsvalidated + ? > questionlimit THEN questionlimit
                        ELSE questionsvalidated + ?
                    END
                  WHERE id = ?",
                [$count, $count, $record->id]
            );
            return;
        }

        $DB->execute(
            "UPDATE {local_artqtml_license} SET questionsvalidated = questionsvalidated + ? WHERE id = ?",
            [$count, $record->id]
        );
    }
}
