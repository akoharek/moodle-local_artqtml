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
 * The single source of truth for the status page's progress-bar presentation.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Status -> progress bar presentation, shared by the PHP render and the JS poll.
 */
class generation_progress {
    /**
     * Presentation per generation status.
     *
     * 'failed' is deliberately absent: its percentage is not a property of the status but of how
     * Far the generation actually got, which only {@see self::failed_percent()} can work out.
     *
     * @var array<string, array{percent: int, color: string, striped: bool}>
     */
    public const STAGES = [
        generation_status::GENERATING => ['percent' => 25, 'color' => 'bg-primary', 'striped' => true],
        generation_status::VALIDATING => ['percent' => 50, 'color' => 'bg-primary', 'striped' => true],
        generation_status::SAVING     => ['percent' => 75, 'color' => 'bg-primary', 'striped' => true],
        generation_status::COMPLETED  => ['percent' => 100, 'color' => 'bg-success', 'striped' => false],
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
     * Stuck on the element.
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
     * How far through the generating stage the per-type loop has got, as a bar percentage.
     *
     * The generating stage is N API calls now, one per requested question type, and a bar that
     * Sits at 25% through all of them tells the teacher nothing - six calls can take several
     * Minutes, and the only honest thing the old bar said was "still working". The loop writes its
     * Position into pendingdata before and after each type (nothing reads that column until
     * Validating, so it is free to), and this turns it into a percentage between the generating
     * And validating marks: 25% before the first type finishes, 45% after the last. The pre-call
     * Write is what names the type currently in flight; without it the label would only ever be
     * Able to name the type that finished last.
     *
     * Falls back to the plain stage percentage when there is nothing to read - an older generation
     * Mid-flight, or a single-type run that has not finished its one call.
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
     * Which question type the generating loop is on, for the bar's label.
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
            // The JS used to carry its own two-value copy of this list.
            'terminal'     => generation_status::TERMINAL,
        ]);
    }
}
