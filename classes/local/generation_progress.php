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
 * The single source of truth for the status page's progress-bar presentation (Gen-001/Gen-004).
 *
 * S-3: status.php and amd/src/status.js used to map a generation status onto a percentage, a
 * colour and a striping flag independently of each other. They agreed (25/50/75/100), but nothing
 * checked that they did, so a change to either would have desynchronised the server-rendered first
 * paint from the AJAX-updated view - the same two-source shape as the problem_category outage,
 * only across the PHP/JS boundary instead of prompt/schema.
 *
 * The mapping now lives here. status.php reads it directly and also serialises it into a
 * data-progress-config attribute on the status root, which status.js parses instead of owning a
 * copy. S-2 folds the JS TERMINAL_STATUSES list into the same payload, so
 * {@see \local_artqtml\local\generation_status::TERMINAL} is likewise stated once.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Status -> progress bar presentation, shared by the PHP render and the JS poll.
 */
class generation_progress {
    /**
     * Presentation per generation status.
     *
     * 'failed' is deliberately absent: its percentage is not a property of the status but of how
     * far the generation actually got, which only {@see self::failed_percent()} can work out.
     *
     * @var array<string, array{percent: int, color: string, striped: bool}>
     */
    public const STAGES = [
        generation_status::GENERATING => ['percent' => 25, 'color' => 'bg-primary', 'striped' => true],
        generation_status::VALIDATING => ['percent' => 50, 'color' => 'bg-primary', 'striped' => true],
        generation_status::SAVING     => ['percent' => 75, 'color' => 'bg-primary', 'striped' => true],
        generation_status::COMPLETED  => ['percent' => 100, 'color' => 'bg-success', 'striped' => false],
        // BL-35: the pipeline reached the end, so the bar is full - but not green. A run that
        // delivered less than was asked for is finished and is not a success, and those are two
        // different facts; showing it as 100% green was how nine empty FT generations passed for
        // completed ones.
        generation_status::PARTIAL    => ['percent' => 100, 'color' => 'bg-warning', 'striped' => false],
    ];

    /** @var array{percent: int, color: string, striped: bool} shown before any stage has started. */
    public const DEFAULT_STAGE = ['percent' => 0, 'color' => 'bg-secondary', 'striped' => false];

    /** @var array{percent: null, color: string, striped: bool} the failed state, percent computed. */
    public const FAILED_STAGE = ['percent' => null, 'color' => 'bg-danger', 'striped' => false];

    /**
     * Every colour class the bar can carry, so a re-render can clear the previous one.
     *
     * Derived rather than re-listed, so adding a stage colour above cannot leave a stale class
     * stuck on the element.
     *
     * @return string[]
     */
    public static function color_classes(): array {
        $colors = [self::DEFAULT_STAGE['color'], self::FAILED_STAGE['color']];
        foreach (self::STAGES as $stage) {
            $colors[] = $stage['color'];
        }

        return array_values(array_unique($colors));
    }

    /**
     * Presentation for one status, falling back to the pre-start default.
     *
     * @param string $status one of generation_status::VALUES
     * @return array{percent: int|null, color: string, striped: bool}
     */
    public static function for_status(string $status): array {
        if ($status === generation_status::FAILED) {
            return self::FAILED_STAGE;
        }

        return self::STAGES[$status] ?? self::DEFAULT_STAGE;
    }

    /**
     * How far through the generating stage the per-type loop has got, as a bar percentage
     * (BL-35).
     *
     * The generating stage is N API calls now, one per requested question type, and a bar that
     * sits at 25% through all of them tells the teacher nothing - six calls can take several
     * minutes, and the only honest thing the old bar said was "still working". The loop writes its
     * position into pendingdata before and after each type (nothing reads that column until
     * validating, so it is free to), and this turns it into a percentage between the generating
     * and validating marks: 25% before the first type finishes, 45% after the last. The pre-call
     * write is what names the type currently in flight; without it the label would only ever be
     * able to name the type that finished last.
     *
     * Falls back to the plain stage percentage when there is nothing to read - an older generation
     * mid-flight, or a single-type run that has not finished its one call.
     *
     * @param string|null $pendingdatajson the generation's raw pendingdata column
     * @return int
     */
    public static function generating_percent(?string $pendingdatajson): int {
        $start = self::STAGES[generation_status::GENERATING]['percent'];
        $end = self::STAGES[generation_status::VALIDATING]['percent'] - 5;

        $pendingdata = json_decode((string) $pendingdatajson, true);
        $progress = is_array($pendingdata) ? ($pendingdata['generating'] ?? null) : null;
        $total = (int) ($progress['total'] ?? 0);
        $done = (int) ($progress['done'] ?? 0);

        if ($total <= 0) {
            return $start;
        }

        return $start + (int) round(min($done, $total) / $total * ($end - $start));
    }

    /**
     * Which question type the generating loop is on, for the bar's label (BL-35).
     *
     * @param string|null $pendingdatajson
     * @return string empty when there is nothing in flight, or the generation predates the loop
     */
    public static function generating_type(?string $pendingdatajson): string {
        $pendingdata = json_decode((string) $pendingdatajson, true);
        $progress = is_array($pendingdata) ? ($pendingdata['generating'] ?? null) : null;

        return (string) ($progress['current'] ?? '');
    }

    /**
     * How far a failed generation actually got, as a bar percentage.
     *
     * M-15: nothing is written to local_artqtml_questions until the saving stage commits, so the
     * question count says nothing about progress. The shape of pendingdata does: both keys means
     * it reached saving, 'questions' alone means it failed validating, neither means it never got
     * past generating.
     *
     * @param string|null $pendingdatajson the generation's raw pendingdata column
     * @return int
     */
    public static function failed_percent(?string $pendingdatajson): int {
        $pendingdata = json_decode((string) $pendingdatajson, true);

        if (is_array($pendingdata) && array_key_exists('evaluations', $pendingdata)) {
            return self::STAGES[generation_status::SAVING]['percent'];
        }
        if (is_array($pendingdata) && array_key_exists('questions', $pendingdata)) {
            return self::STAGES[generation_status::VALIDATING]['percent'];
        }

        return self::STAGES[generation_status::GENERATING]['percent'];
    }

    /**
     * The payload status.php hands to amd/src/status.js as a data attribute.
     *
     * Everything the JS needs to render the bar, so it owns no copy of any of it.
     *
     * @return string JSON
     */
    public static function config_json(): string {
        return json_encode([
            'stages'       => self::STAGES,
            'failed'       => self::FAILED_STAGE,
            'colorClasses' => self::color_classes(),
            // S-2: the JS used to carry its own two-value copy of this list.
            'terminal'     => generation_status::TERMINAL,
        ]);
    }
}
