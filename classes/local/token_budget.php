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
 * Monthly token budget tracking per API (functional spec Admin-030-033).
 *
 * Usage is derived from local_artqtml_log rows rather than a separate counter table:
 * every successful, non-retry AI call already logs tokensinput/tokensoutput there
 * (Val-009: JSON-fallback retry tokens are excluded from the budget via isretry=1).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Computes and displays monthly token usage against the configured budget.
 */
class token_budget {
    /**
     * @var string data-blob key marking a warning whose attempt was rolled back.
     *
     * Kept next to both the writer and the reader on purpose: the flag is the only thing that keeps
     * a surviving log row (Glob-040) off a later run's screen, and a name that drifted between the
     * two would silently bring the stale warning back.
     */
    public const ROLLED_BACK_KEY = 'rolledback';

    /**
     * Start of the current billing cycle, based on the configured cycle-start day of month.
     *
     * @return int unix timestamp
     */
    public static function cycle_start(): int {
        $day = (int) (get_config('local_artqtml', 'tokencyclestartday') ?: 1);
        $day = max(1, min(28, $day));

        $now = new \DateTime('now', \core_date::get_server_timezone_object());
        $currentday = (int) $now->format('j');

        if ($currentday < $day) {
            $now->modify('-1 month');
        }
        $now->setDate((int) $now->format('Y'), (int) $now->format('n'), $day);
        $now->setTime(0, 0, 0);

        return $now->getTimestamp();
    }

    /**
     * Tokens used by one provider's non-retry calls since the cycle started.
     *
     * @param string $provider 'claude' or 'gemini'
     * @return int
     */
    public static function used(string $provider): int {
        global $DB;

        $sql = "SELECT COALESCE(SUM(COALESCE(tokensinput, 0) + COALESCE(tokensoutput, 0)), 0)
                  FROM {local_artqtml_log}
                 WHERE provider = :provider AND isretry = 0 AND timecreated >= :cyclestart";

        return (int) $DB->get_field_sql($sql, ['provider' => $provider, 'cyclestart' => self::cycle_start()]);
    }

    /**
     * Configured monthly budget for one provider (0 = unlimited).
     *
     * @param string $provider 'claude' or 'gemini'
     * @return int
     */
    public static function budget(string $provider): int {
        $key = $provider === 'claude' ? 'generatortokenbudget' : 'validatortokenbudget';
        return (int) (get_config('local_artqtml', $key) ?: 0);
    }

    /**
     * Whether a provider's budget has been exhausted (Admin-030: blocks new generations).
     *
     * @param string $provider 'claude' or 'gemini'
     * @return bool
     */
    public static function is_exceeded(string $provider): bool {
        $budget = self::budget($provider);
        return $budget > 0 && self::used($provider) >= $budget;
    }

    /**
     * Build the incident-specific token-limit warning message for a generation, if any was
     * logged (Val-015/Val-022, TC-Val-017/TC-Val-024), falling back to a generic message if a
     * warning was logged without the stage-specific data this expects.
     *
     * @param int $generationid
     * @return string empty string if no token-limit warning has been logged for this generation
     */
    public static function warning_message(int $generationid): string {
        global $DB;

        $logs = $DB->get_records('local_artqtml_log', [
            'generationid' => $generationid,
            'event'        => 'token_limit_warning',
        ]);
        if (!$logs) {
            return '';
        }

        $generatedata = null;
        $validateaffected = 0;
        // Counted rather than inferred from the two variables below: the fall-through at the end of
        // this method returns a GENERIC warning for a row it could not read the stage from, which is
        // right for a malformed row and wrong for a row that was deliberately set aside. Without
        // this counter, marking every warning rolled back still produced a warning - which is what
        // this method's own test caught (2026-08-05, BL-52).
        $considered = 0;
        foreach ($logs as $log) {
            $data = json_decode((string) $log->data, true) ?: [];
            // A warning from an attempt that was rolled back describes work that no longer exists,
            // so it must not appear on the next run's screen - but the ROW stays (Glob-040). See
            // self::mark_rolled_back().
            if (!empty($data[self::ROLLED_BACK_KEY])) {
                continue;
            }
            $considered++;
            if (($data['stage'] ?? '') === 'generate') {
                $generatedata = $data;
            } else if (($data['stage'] ?? '') === 'validate') {
                $validateaffected += (int) ($data['affected'] ?? 0);
            }
        }

        if ($considered === 0) {
            return '';
        }

        if ($generatedata !== null) {
            return get_string('warningtokenlimitgenerate', 'local_artqtml', (object) [
                'requested' => (int) ($generatedata['requested'] ?? 0),
                'actual'    => (int) ($generatedata['actual'] ?? 0),
            ]);
        }
        if ($validateaffected > 0) {
            return get_string('warningtokenlimitvalidate', 'local_artqtml', $validateaffected);
        }

        return get_string('warningtokenlimitgeneric', 'local_artqtml');
    }

    /**
     * Mark this generation's token-limit warnings as belonging to a rolled-back attempt.
     *
     * WHY THIS EXISTS INSTEAD OF A DELETE. `status.php`'s rollback used to delete these rows
     * outright, which contradicted Glob-040 in as many words: the log row survives the generation,
     * and nothing removes it. The delete was not gratuitous, though - {@see self::warning_message()}
     * reads every warning row for the generation, so a leftover row from a discarded attempt would
     * have put a warning on the next run's screen about work that no longer exists.
     *
     * So the row stays and is annotated, which is what the security report asked for: redact, do
     * not remove. Every technical field the log carries - provider, HTTP status, token counts,
     * attempt number, request id, outcome - is untouched; only the data blob gains a flag, and the
     * reader skips flagged rows (2026-08-05, BL-52).
     *
     * @param int $generationid
     * @return void
     */
    public static function mark_rolled_back(int $generationid): void {
        global $DB;

        $logs = $DB->get_records('local_artqtml_log', [
            'generationid' => $generationid,
            'event'        => 'token_limit_warning',
        ]);

        foreach ($logs as $log) {
            $data = json_decode((string) $log->data, true) ?: [];
            if (!empty($data[self::ROLLED_BACK_KEY])) {
                continue;
            }
            $data[self::ROLLED_BACK_KEY] = time();

            $DB->update_record('local_artqtml_log', (object) [
                'id'   => $log->id,
                'data' => json_encode($data),
            ]);
        }
    }

    /**
     * Render the Token kezelés admin tab's read-only usage summary (Admin-032, Lic-012 style
     * progress bars), for both providers.
     *
     * @return string
     */
    public static function render_usage_summary(): string {
        $warningpct = (int) (get_config('local_artqtml', 'tokenbudgetwarningpct') ?: 80);

        $html = '';
        foreach (['claude' => 'generatortokenlabel', 'gemini' => 'validatortokenlabel'] as $provider => $labelkey) {
            $used = self::used($provider);
            $budget = self::budget($provider);
            $pct = $budget > 0 ? min(100, (int) round(($used / $budget) * 100)) : 0;
            $barclass = $pct >= 100 ? 'bg-danger' : ($pct >= $warningpct ? 'bg-warning' : 'bg-success');

            $html .= \html_writer::tag('strong', get_string($labelkey, 'local_artqtml'));
            if ($budget > 0) {
                $html .= \html_writer::div(
                    \html_writer::div(
                        $used . ' / ' . $budget,
                        'progress-bar ' . $barclass,
                        ['style' => 'width: ' . $pct . '%']
                    ),
                    'progress mb-3'
                );
            } else {
                $html .= \html_writer::div(get_string('tokenunlimited', 'local_artqtml', $used), 'mb-3 text-muted');
            }
        }

        return $html;
    }
}
