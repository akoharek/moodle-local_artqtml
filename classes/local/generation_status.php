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
 * Single source of truth for generation status values.
 *
 * List page, status page, scheduled tasks and filters all read {@see self::VALUES} /
 * {@see self::IN_PROGRESS} from here.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Canonical list + display helper for the seven generation statuses.
 */
class generation_status {
    /** @var string queued, or picked up by the task but not yet calling an API. */
    public const STARTED = 'started';

    /** @var string the Claude generation call is running. */
    public const GENERATING = 'generating';

    /** @var string the Gemini validation call is running. */
    public const VALIDATING = 'validating';

    /** @var string the questions are being committed to the draft bank. */
    public const SAVING = 'saving';

    /** @var string the pipeline finished successfully. */
    public const COMPLETED = 'completed';

    /** @var string the pipeline failed after exhausting its retries. */
    public const FAILED = 'failed';

    /**
     * Helper.
     *
     * @var string the pipeline finished, but delivered fewer questions than were asked for.
     */
    public const PARTIAL = 'partial';

    /**
     * The seven generation status values, in pipeline order.
     *
     * @var string[]
     */
    public const VALUES = [
        self::STARTED,
        self::GENERATING,
        self::VALIDATING,
        self::SAVING,
        self::COMPLETED,
        self::PARTIAL,
        self::FAILED,
    ];

    /**
     * The statuses the scheduled task actively drives forward, i.e. "work in progress".
     *
     * Process_pending_generations claims rows in these states, and status.php shows a live
     * (polling) progress bar for them. A strict subset of {@see self::VALUES}.
     *
     * @var string[]
     */
    public const IN_PROGRESS = [self::GENERATING, self::VALIDATING, self::SAVING];

    /**
     * The statuses at which the pipeline has stopped for good, one way or the other.
     *
     * @var string[]
     */
    public const TERMINAL = [self::COMPLETED, self::PARTIAL, self::FAILED];

    /**
     * Normalise a raw value (e.g. a query-string filter or a legacy stored value) to a valid key.
     *
     * @param string|null $value the raw stored/submitted value
     * @param string|null $default returned when $value is not one of the seven statuses
     * @return string|null a member of {@see self::VALUES}, or $default
     */
    public static function normalise(?string $value, ?string $default = null): ?string {
        return in_array((string) $value, self::VALUES, true) ? (string) $value : $default;
    }

    /**
     * Whether the scheduled task still has work to do on this status.
     *
     * @param string $value
     * @return bool
     */
    public static function is_in_progress(string $value): bool {
        return in_array($value, self::IN_PROGRESS, true);
    }

    /**
     * Human-readable label for a status, from a lang string - the raw machine key (e.g. 'started')
     * Must never reach the UI. The 'started' label is localized (e.g. "Started" in English).
     *
     * @param string $value one of {@see self::VALUES}
     * @return string
     */
    public static function label(string $value): string {
        return get_string('status_' . $value, 'local_artqtml');
    }

    /**
     * Status -> Bootstrap badge CSS class, for the list page and the status page.
     *
     * @param string $value one of {@see self::VALUES}
     * @return string
     */
    public static function badge_class(string $value): string {
        $map = [
            self::STARTED    => 'badge-secondary',
            self::GENERATING => 'badge-info',
            self::VALIDATING => 'badge-info',
            self::SAVING     => 'badge-info',
            self::COMPLETED  => 'badge-success',
            self::FAILED     => 'badge-danger',
        ];

        return $map[$value] ?? 'badge-secondary';
    }

    /**
     * An SQL fragment matching the in-progress statuses, plus its named parameters.
     *
     * Lets the scheduled task build its WHERE clause from {@see self::IN_PROGRESS} instead of
     * Inlining the list into a SQL string.
     *
     * @param string $field the column to match, qualified if needed
     * @param string $prefix named-parameter prefix, so several fragments can coexist in one query
     * @return array{0: string, 1: array<string,string>} [sql, params]
     */
    public static function in_progress_sql(string $field = 'status', string $prefix = 'ginprog'): array {
        global $DB;

        [$sql, $params] = $DB->get_in_or_equal(self::IN_PROGRESS, SQL_PARAMS_NAMED, $prefix);

        return [$field . ' ' . $sql, $params];
    }
}
