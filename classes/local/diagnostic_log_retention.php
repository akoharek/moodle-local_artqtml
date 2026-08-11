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
 * How long the diagnostic payload lives, and how it is removed when it stops.
 *
 * THE TWO THINGS THIS SEPARATES. A `diagnostics_call` log entry holds two very different kinds of
 * data in one row. There is the technical record - which provider, what HTTP status, how many
 * tokens, which attempt - and there is the payload: the full system prompt, the response schema and
 * the raw provider response. The first is small, has no personal content, and is the reason the
 * log exists at all. The second can contain a teacher's material, is large, and was being kept
 * indefinitely because nothing had ever decided otherwise.
 *
 * The rule is therefore not "delete old logs" - it is that the ROW is permanent and the PAYLOAD
 * is not. Redaction empties the three heavy keys and leaves everything else exactly as it was, so a
 * question asked six months later - did this call succeed, what did it cost, which model was it -
 * still has an answer.
 *
 * WHY THERE IS NO "UNLIMITED" SETTING. A retention period an administrator can set to zero is a
 * retention period nobody has, and the value of this data falls off far faster than its
 * sensitivity does. The minimum is one day; the default is thirty.
 *
 * WHY IT IS IDEMPOTENT. The scheduled task runs daily over the same rows for as long as they exist.
 * A redaction that rewrote `payloadredactedat` on every pass would destroy the only evidence of
 * when the payload actually went, and would rewrite every old row every night for no reason.
 *
 * GDPR IS A SEPARATE ENTRY POINT, not a shorter retention period. A deletion request does not
 * wait for a timer: {@see self::redact_for_user()} redacts immediately and nulls the user id, and
 * the technical row stays - anonymous, and still counting towards what the plugin did.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * The single lifecycle owner of the diagnostics_call payload.
 */
class diagnostic_log_retention {
    /** @var string the only event whose data field this class touches. */
    public const EVENT = 'diagnostics_call';

    /** @var int days the raw payload is kept when nothing is configured. */
    public const DEFAULT_RETENTION_DAYS = 30;

    /** @var int the shortest period an administrator may configure. */
    public const MIN_RETENTION_DAYS = 1;

    /** @var string[] the payload keys removed on redaction - the large or sensitive ones. */
    protected const SENSITIVE_KEYS = ['systemprompt', 'schema', 'responsebody'];

    /**
     * How many days the raw payload is kept.
     *
     * Anything missing, non-numeric or below the minimum falls back to the default rather than
     * being used. A misconfigured value must not become "keep forever" by accident.
     *
     * @return int at least MIN_RETENTION_DAYS
     */
    public static function retention_days(): int {
        $configured = get_config('local_artqtml', 'diagnosticretentiondays');

        if (!is_numeric($configured) || (int) $configured < self::MIN_RETENTION_DAYS) {
            return self::DEFAULT_RETENTION_DAYS;
        }

        return (int) $configured;
    }

    /**
     * Remove the heavy keys from one payload, leaving the technical record intact.
     *
     * @param string|null $json the stored data field
     * @param int|null $redactedat timestamp to record, defaults to now
     * @return string|null the new data field
     */
    public static function redact_data(?string $json, ?int $redactedat = null): ?string {
        $decoded = json_decode((string) $json, true);

        if (!is_array($decoded)) {
            // Unreadable, or not an object. The raw value is NOT kept "just in case": it is the
            // thing being removed, and its being malformed is not a reason to hold on to it.
            return self::encode([
                'payloadredacted'         => true,
                'payloadredactedat'       => $redactedat ?? time(),
                'payloadredactionreason'  => 'invalid_json',
            ]);
        }

        // Already done. Returning it untouched is what makes a daily task over a growing table
        // free, and it preserves the original redaction timestamp.
        if (!empty($decoded['payloadredacted']) && !self::has_sensitive_keys($decoded)) {
            return $json;
        }

        foreach (self::SENSITIVE_KEYS as $key) {
            unset($decoded[$key]);
        }

        $decoded['payloadredacted'] = true;
        if (!isset($decoded['payloadredactedat'])) {
            $decoded['payloadredactedat'] = $redactedat ?? time();
        }

        return self::encode($decoded);
    }

    /**
     * Redact every diagnostics_call payload past its retention period.
     *
     * @param int|null $now the moment to measure from, for tests
     * @return int rows actually changed
     */
    public static function purge_expired(?int $now = null): int {
        global $DB;

        $threshold = ($now ?? time()) - (self::retention_days() * DAYSECS);

        $recordset = $DB->get_recordset_select(
            'local_artqtml_log',
            'event = :event AND timecreated < :threshold',
            ['event' => self::EVENT, 'threshold' => $threshold],
            'id ASC',
            'id, data'
        );

        $changed = 0;
        try {
            foreach ($recordset as $record) {
                if (!self::needs_redaction($record->data)) {
                    continue;
                }

                try {
                    $DB->set_field('local_artqtml_log', 'data', self::redact_data($record->data), ['id' => $record->id]);
                    $changed++;
                } catch (\Throwable $e) {
                    // One bad row does not stop the sweep, and the row id is all that is reported:
                    // the payload is precisely what must not travel into a log line about the
                    // payload.
                    debugging(
                        'local_artqtml: could not redact diagnostics log row ' . (int) $record->id,
                        DEBUG_NORMAL
                    );
                }
            }
        } finally {
            $recordset->close();
        }

        return $changed;
    }

    /**
     * Anonymise and redact every log row belonging to one user.
     *
     * The GDPR entry point. It finds rows by user id, which is what makes the entries of a
     * generation that has since been deleted reachable at all - they have no generation to be
     * found through any more.
     *
     * @param int $userid
     * @return int rows changed
     */
    public static function redact_for_user(int $userid): int {
        global $DB;

        $recordset = $DB->get_recordset('local_artqtml_log', ['userid' => $userid], 'id ASC', 'id, event, data');

        $changed = 0;
        try {
            foreach ($recordset as $record) {
                $update = (object) ['id' => $record->id, 'userid' => null];
                if ($record->event === self::EVENT) {
                    $update->data = self::redact_data($record->data);
                }
                $DB->update_record('local_artqtml_log', $update);
                $changed++;
            }
        } finally {
            $recordset->close();
        }

        return $changed;
    }

    /**
     * Anonymise and redact the log rows of a set of generations, live or already deleted.
     *
     * Both id columns are searched, because by the time a deletion request is processed a
     * generation may already have been deleted for ordinary reasons - in which case its entries
     * carry the id in `originalgenerationid` instead.
     *
     * @param int[] $generationids
     * @return int rows changed
     */
    public static function redact_for_generation_ids(array $generationids): int {
        global $DB;

        $generationids = array_values(array_filter(array_map('intval', $generationids)));
        if ($generationids === []) {
            return 0;
        }

        [$insql, $params] = $DB->get_in_or_equal($generationids, SQL_PARAMS_NAMED, 'gid');
        [$insql2, $params2] = $DB->get_in_or_equal($generationids, SQL_PARAMS_NAMED, 'ogid');

        $recordset = $DB->get_recordset_select(
            'local_artqtml_log',
            "generationid $insql OR originalgenerationid $insql2",
            array_merge($params, $params2),
            'id ASC',
            'id, event, data'
        );

        $changed = 0;
        try {
            foreach ($recordset as $record) {
                $update = (object) ['id' => $record->id, 'userid' => null];
                if ($record->event === self::EVENT) {
                    $update->data = self::redact_data($record->data);
                }
                $DB->update_record('local_artqtml_log', $update);
                $changed++;
            }
        } finally {
            $recordset->close();
        }

        return $changed;
    }

    /**
     * Anonymise every log row on the site and redact every diagnostic payload.
     *
     * For "delete everything in this context". The rows stay: they are the plugin's record of its
     * own operation, and that is not personal data once the user id is gone.
     *
     * @return int rows changed
     */
    public static function redact_all(): int {
        global $DB;

        $recordset = $DB->get_recordset('local_artqtml_log', null, 'id ASC', 'id, event, data, userid');

        $changed = 0;
        try {
            foreach ($recordset as $record) {
                $update = (object) ['id' => $record->id, 'userid' => null];
                if ($record->event === self::EVENT) {
                    $update->data = self::redact_data($record->data);
                }
                $DB->update_record('local_artqtml_log', $update);
                $changed++;
            }
        } finally {
            $recordset->close();
        }

        return $changed;
    }

    /**
     * Whether this stored data field still holds anything that redaction would remove.
     *
     * @param string|null $json
     * @return bool
     */
    protected static function needs_redaction(?string $json): bool {
        $decoded = json_decode((string) $json, true);

        if (!is_array($decoded)) {
            // Not decodable: only worth rewriting if it has not already been marked.
            return trim((string) $json) !== '';
        }

        return self::has_sensitive_keys($decoded) || empty($decoded['payloadredacted']);
    }

    /**
     * Whether a decoded payload still carries any of the heavy keys.
     *
     * @param array $decoded
     * @return bool
     */
    protected static function has_sensitive_keys(array $decoded): bool {
        foreach (self::SENSITIVE_KEYS as $key) {
            if (array_key_exists($key, $decoded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Encode a payload back to the stored form.
     *
     * @param array $payload
     * @return string
     */
    protected static function encode(array $payload): string {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Encoding cannot realistically fail on the small metadata array that is left after
        // redaction, but a false here would write the string "false" into the column - so the
        // fallback is the minimum valid marker rather than whatever json_encode returned.
        return is_string($encoded)
            ? $encoded
            : '{"payloadredacted":true,"payloadredactionreason":"encode_failed"}';
    }
}
