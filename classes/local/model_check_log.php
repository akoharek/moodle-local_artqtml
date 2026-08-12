<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// It under the terms of the GNU General Public License as published by
// The Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// But WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// Along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Writer for the model-check diagnostic log.
 *
 * No dependency is taken on block_configurable_reports; without it the log is written exactly the
 * Same way and is simply read from SQL.
 *
 * No retention policy and no purge. Two entries a day is roughly 730 rows a year.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Appends model-check results to local_artqtml_modelcheck.
 */
class model_check_log {
    /** @var string the table name - part of the documented public interface. */
    public const TABLE = 'local_artqtml_modelcheck';

    /** @var string availability check: is the configured model in the freshly fetched list. */
    public const CHECK_AVAILABILITY = 'availability';

    /** @var string structure check: a live structured-output probe validated against our schema. */
    public const CHECK_STRUCTURE = 'structure';

    /** @var string the daily scheduled task ran this check. */
    public const TRIGGER_SCHEDULED = 'scheduled';

    /** @var string an administrator pressed "Run check" . */
    public const TRIGGER_MANUAL = 'manual';

    /** @var string check outcome. */
    public const RESULT_SUCCESS = 'success';

    /** @var string check outcome. */
    public const RESULT_FAILURE = 'failure';

    /**
     * @var string the provider was busy or unreachable, so the check learned nothing.
     */
    public const RESULT_TRANSIENT = 'transient';

    /** @var int cap on the stored error message - a shortened detail, never the raw response. */
    protected const MAX_ERROR_LENGTH = 500;

    /**
     * Record one check result and return the row id.
     *
     * @param string $provider anthropic or gemini
     * @param string $model the model identifier checked
     * @param string $checktype self::CHECK_*
     * @param bool $success
     * @param string $errormessage shortened detail; the raw provider response must never be passed
     * @param int $durationms call duration in milliseconds
     * @param string $trigger self::TRIGGER_*
     * @param bool $transient the call failed because the provider was busy or unreachable, which
     * Says nothing about the model - recorded as self::RESULT_TRANSIENT and ignored by
     * Excluded_models() and latest_sweep()
     * @return array{id: int, errorcode: string} the row id and, on failure, its error code
     */
    public static function record(
        string $provider,
        string $model,
        string $checktype,
        bool $success,
        string $errormessage,
        int $durationms,
        string $trigger,
        bool $transient = false
    ): array {
        global $DB;

        $record = (object) [
            'timecreated'  => time(),
            'provider'     => $provider,
            'model'        => $model,
            'checktype'    => $checktype,
            'result'       => $success
                ? self::RESULT_SUCCESS
                : ($transient ? self::RESULT_TRANSIENT : self::RESULT_FAILURE),
            'errorcode'    => null,
            'errormessage' => $success ? null : \core_text::substr($errormessage, 0, self::MAX_ERROR_LENGTH),
            'duration'     => max(0, $durationms),
            // See the class docblock and db/upgrade.php: named triggertype because TRIGGER is a
            // Reserved word and would be unusable from the Configurable Reports queries this table
            // Exists to serve.
            'triggertype'  => $trigger,
            'pluginversion' => (int) get_config('local_artqtml', 'version'),
        ];

        $id = (int) $DB->insert_record(self::TABLE, $record);

        $errorcode = '';
        if (!$success) {
            $errorcode = self::error_code($id);
            $DB->set_field(self::TABLE, 'errorcode', $errorcode, ['id' => $id]);
        }

        return ['id' => $id, 'errorcode' => $errorcode];
    }

    /**
     * Build the AIQ-YYYYMMDD-XXXX code for a log row.
     *
     * Same format as the licence system's. The four-digit part is the log row id, so an
     * Administrator reading the code off the warning bar can find the entry that produced it.
     * Ids past 9999 wrap in the displayed code but the row is still findable by date.
     *
     * @param int $logid
     * @return string
     */
    public static function error_code(int $logid): string {
        return sprintf('AIQ-%s-%04d', date('Ymd'), $logid % 10000);
    }

    /**
     * The provider name as this table stores it.
     *
     * @param string $provider one of model_list::PROVIDERS
     * @return string the value stored in the provider column
     */
    public static function stored_provider(string $provider): string {
        return $provider === model_list::PROVIDER_CLAUDE ? 'anthropic' : 'gemini';
    }

    /**
     * A one-line summary of the newest structural sweep, for the settings page.
     *
     * "Newest sweep" is every row sharing the newest timestamp's minute, which is what a sweep
     * Looks like in this table: one row per model, seconds apart.
     *
     * @param string $provider one of model_list::PROVIDERS
     * @return array{checked: int, failed: int, timecreated: int}|null null if never checked
     */
    public static function latest_sweep(string $provider): ?array {
        global $DB;

        $rows = $DB->get_records_select(
            self::TABLE,
            'provider = :provider AND checktype = :checktype AND pluginversion = :version',
            [
                'provider'  => self::stored_provider($provider),
                'checktype' => self::CHECK_STRUCTURE,
                'version'   => (int) get_config('local_artqtml', 'version'),
            ],
            'timecreated DESC, id DESC',
            'id, model, result, timecreated'
        );
        if (!$rows) {
            return null;
        }

        // The newest verdict per model, which is what the dropdown is filtered on - so the summary
        // And the list can never tell the administrator two different things.
        $newest = 0;
        $latest = [];
        foreach ($rows as $row) {
            $newest = max($newest, (int) $row->timecreated);
            // Same reason as excluded_models(): a transient row is not a verdict, so it neither
            // Counts towards the models checked nor hides the real answer underneath it.
            if ($row->result === self::RESULT_TRANSIENT) {
                continue;
            }
            if (!isset($latest[$row->model])) {
                $latest[$row->model] = $row->result;
            }
        }

        $failed = 0;
        foreach ($latest as $result) {
            if ($result === self::RESULT_FAILURE) {
                $failed++;
            }
        }

        return ['checked' => count($latest), 'failed' => $failed, 'timecreated' => $newest];
    }

    /**
     * Model ids this plugin version has proved it cannot read.
     *
     * Only the NEWEST verdict per model counts. A model that failed and was later re-checked
     * Successfully is not excluded; without that, a fixed model could never come back within the
     * Same version.
     *
     * One query rather than one per model: this runs while an administrator waits for the settings
     * Page to render.
     *
     * @param string $provider one of model_list::PROVIDERS
     * @return string[] model ids, empty when nothing has been excluded
     */
    public static function excluded_models(string $provider): array {
        global $DB;

        $rows = $DB->get_records_select(
            self::TABLE,
            'provider = :provider AND checktype = :checktype AND pluginversion = :version',
            [
                'provider'  => self::stored_provider($provider),
                'checktype' => self::CHECK_STRUCTURE,
                'version'   => (int) get_config('local_artqtml', 'version'),
            ],
            'timecreated ASC, id ASC',
            'id, model, result, timecreated'
        );

        $latest = [];
        foreach ($rows as $row) {
            if ($row->result === self::RESULT_TRANSIENT) {
                continue;
            }
            $latest[$row->model] = $row->result;
        }

        $excluded = [];
        foreach ($latest as $model => $result) {
            if ($result === self::RESULT_FAILURE) {
                $excluded[] = (string) $model;
            }
        }

        return $excluded;
    }

    /**
     * The most recent entry for a provider, or null.
     *
     * @param string $provider
     * @return \stdClass|null
     */
    public static function latest_for_provider(string $provider): ?\stdClass {
        global $DB;

        $rows = $DB->get_records(self::TABLE, ['provider' => $provider], 'timecreated DESC, id DESC', '*', 0, 1);
        $row = reset($rows);

        return $row ?: null;
    }
}
