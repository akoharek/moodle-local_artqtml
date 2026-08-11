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
 * Writer for the model-check diagnostic log (Admin-061/062/063).
 *
 * Admin-063: "A plugin a diagnosztikai log adatait biztosítja, nem a megjelenítést: az
 * adminisztrátor a bejegyzéseket Configurable Reports lekérdezéssel tekinti meg. [...] A log tábla
 * sémája dokumentált, stabil interfész." So the column names here are a public contract - renaming
 * or dropping one breaks administrators' saved reports and needs a documented version bump.
 *
 * No dependency is taken on block_configurable_reports; without it the log is written exactly the
 * same way and is simply read from SQL.
 *
 * Admin-062: no retention policy and no purge. Two entries a day is roughly 730 rows a year.
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

    /** @var string an administrator pressed "Run check" (Admin-054). */
    public const TRIGGER_MANUAL = 'manual';

    /** @var string check outcome. */
    public const RESULT_SUCCESS = 'success';

    /** @var string check outcome. */
    public const RESULT_FAILURE = 'failure';

    /**
     * @var string the provider was busy or unreachable, so the check learned nothing.
     *
     * A third value rather than a new column: `result` is already a short string the Configurable
     * Reports queries read as-is, and Admin-061/063 make the column *set* the stable interface, not
     * the values in it. MEASURED 2026-08-03 - a "currently experiencing high demand" reply had
     * struck a working model off the dropdown until the next version bump.
     */
    public const RESULT_TRANSIENT = 'transient';

    /** @var int cap on the stored error message - a shortened detail, never the raw response. */
    protected const MAX_ERROR_LENGTH = 500;

    /**
     * Record one check result and return the row id.
     *
     * The error code is assigned after insert, because Admin-057 derives its numeric part from the
     * row id so that a code shown on screen can be looked up in the log.
     *
     * @param string $provider anthropic or gemini
     * @param string $model the model identifier checked
     * @param string $checktype self::CHECK_*
     * @param bool $success
     * @param string $errormessage shortened detail; the raw provider response must never be passed
     * @param int $durationms call duration in milliseconds
     * @param string $trigger self::TRIGGER_*
     * @param bool $transient the call failed because the provider was busy or unreachable, which
     *      says nothing about the model - recorded as self::RESULT_TRANSIENT and ignored by
     *      excluded_models() and latest_sweep()
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
            // The raw provider response is never stored here, and on failure only a shortened
            // excerpt of it reaches the error message field. The requirement says so in as many
            // words, Admin-061: "A nyers szolgáltatói válasz nem tárolódik, hiba esetén rövidített
            // részlet kerül a hibaüzenet mezőbe".
            'errormessage' => $success ? null : \core_text::substr($errormessage, 0, self::MAX_ERROR_LENGTH),
            'duration'     => max(0, $durationms),
            // See the class docblock and db/upgrade.php: named triggertype because TRIGGER is a
            // reserved word and would be unusable from the Configurable Reports queries this table
            // exists to serve.
            'triggertype'  => $trigger,
            // BL-44: the version this verdict belongs to. An exclusion made by a plugin defect must
            // not outlive the fix - see the upgrade step that added this column.
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
     * Build the AIQ-YYYYMMDD-XXXX code for a log row (Admin-057).
     *
     * Same format as the licence system's. The four-digit part is the log row id, so an
     * administrator reading the code off the warning bar can find the entry that produced it.
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
     * The log records the vendor (anthropic/gemini) while the settings and the model list use the
     * provider key (claude/gemini). The mapping lived only inside model_checker, which was fine
     * while nothing else read the table by provider - BL-44 changed that, and a second copy of a
     * mapping is exactly the shape that produced this item in the first place.
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
     * BL-44. The verdict has to reach the administrator, and the obvious route turned out to be a
     * trap: the button's JavaScript reloads the page on success (Admin-050, so the dropdown appears
     * from the freshly written cache), which erases anything the call returned. Putting it in a
     * session notification instead hung the request outright - measured 2026-08-03, the probes
     * finished in 33 seconds and the browser waited over ten minutes.
     *
     * Reading it back from the log on render sidesteps both. The reload that used to destroy the
     * message is now the thing that shows it, and it keeps showing it tomorrow.
     *
     * "Newest sweep" is every row sharing the newest timestamp's minute, which is what a sweep
     * looks like in this table: one row per model, seconds apart.
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
        // and the list can never tell the administrator two different things.
        $newest = 0;
        $latest = [];
        foreach ($rows as $row) {
            $newest = max($newest, (int) $row->timecreated);
            // Same reason as excluded_models(): a transient row is not a verdict, so it neither
            // counts towards the models checked nor hides the real answer underneath it.
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
     * BL-44. A model whose structural check failed has no business in the dropdown: choosing it
     * means every generation fails, and the API call is billed regardless. But the exclusion is
     * scoped to the plugin version that produced it, and that is the point of the pluginversion
     * column - on 2026-08-03 two models failed in the morning and passed in the afternoon, because
     * the defect was in how the plugin read the reply. A permanent exclusion would have survived
     * our own fix.
     *
     * Only the NEWEST verdict per model counts. A model that failed and was later re-checked
     * successfully is not excluded; without that, a fixed model could never come back within the
     * same version.
     *
     * One query rather than one per model: this runs while an administrator waits for the settings
     * page to render.
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

        // Ascending order means the last row seen for a model is its newest verdict. A transient
        // row is skipped rather than treated as the newest one: "the provider was busy" is not a
        // verdict, and letting it overwrite the previous answer would silently re-admit a model
        // that really had failed - or, as measured on 2026-08-03, exclude one that had not.
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
