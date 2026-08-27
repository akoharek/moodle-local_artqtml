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

namespace local_artqtml\local;

/**
 * Unit tests for the shared status -> progress-bar mapping.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\generation_progress
 */
final class generation_progress_test extends \advanced_testcase {
    /**
     * Every in-progress and completed status has a stage; the mapping is the 25/50/75/100 ladder
     * The specification describes, and it covers exactly the statuses that can show a bar.
     */
    public function test_stage_table_matches_the_status_constant(): void {
        $this->assertSame(
            [
                generation_status::GENERATING,
                generation_status::VALIDATING,
                generation_status::SAVING,
                generation_status::COMPLETED,
                generation_status::PARTIAL,
            ],
            array_keys(generation_progress::STAGES)
        );

        // Partial is the second stage to reach 100 - the pipeline did finish - and the only one that is not green there.
        $this->assertSame([25, 50, 75, 100, 100], array_column(generation_progress::STAGES, 'percent'));
        $this->assertSame('bg-warning', generation_progress::STAGES[generation_status::PARTIAL]['color']);

        // Every key is a real status, and the two statuses without a fixed stage are the ones
        // That genuinely have none: 'started' (nothing running yet) and 'failed' (percent derived).
        foreach (array_keys(generation_progress::STAGES) as $status) {
            $this->assertContains($status, generation_status::VALUES, "$status is not a real status");
        }
        $missing = array_diff(generation_status::VALUES, array_keys(generation_progress::STAGES));
        $this->assertSame(
            [generation_status::STARTED, generation_status::FAILED],
            array_values($missing)
        );
    }

    /**
     * The generating stage is subdivided by how many question types are done.
     */
    public function test_generating_percent_and_type_follow_the_loop(): void {
        // Nothing written yet (an older generation, or the first call still in flight).
        $this->assertSame(25, generation_progress::generating_percent(null));
        $this->assertSame(25, generation_progress::generating_percent(''));
        $this->assertSame(25, generation_progress::generating_percent(json_encode(['questions' => []])));
        $this->assertSame('', generation_progress::generating_type(null));

        $progress = static function (int $done, int $total, string $current): string {
            return json_encode(['generating' => ['done' => $done, 'total' => $total, 'current' => $current]]);
        };

        // Three types: 25 at the start, then a step per finished type, 45 at the end.
        $this->assertSame(25, generation_progress::generating_percent($progress(0, 3, 'IH')));
        $this->assertSame(32, generation_progress::generating_percent($progress(1, 3, 'FE')));
        $this->assertSame(38, generation_progress::generating_percent($progress(2, 3, 'SR')));
        $this->assertSame(45, generation_progress::generating_percent($progress(3, 3, 'SR')));

        // A single type never leaves the two ends.
        $this->assertSame(25, generation_progress::generating_percent($progress(0, 1, 'IH')));
        $this->assertSame(45, generation_progress::generating_percent($progress(1, 1, 'IH')));

        // The label names the type in flight, and the bar never runs past the stage it belongs to.
        $this->assertSame('FE', generation_progress::generating_type($progress(1, 3, 'FE')));
        $this->assertLessThanOrEqual(
            generation_progress::STAGES[generation_status::VALIDATING]['percent'],
            generation_progress::generating_percent($progress(9, 3, 'IH')),
            'a miscounted loop must not push the bar past the validating mark'
        );
    }

    /**
     * For_status() resolves every status, including the two without a fixed stage.
     */
    public function test_for_status_covers_every_status(): void {
        foreach (generation_status::VALUES as $status) {
            $stage = generation_progress::for_status($status);
            $this->assertArrayHasKey('percent', $stage);
            $this->assertArrayHasKey('color', $stage);
            $this->assertArrayHasKey('striped', $stage);
            $this->assertStringStartsWith('bg-', $stage['color']);
        }

        $this->assertSame(generation_progress::DEFAULT_STAGE, generation_progress::for_status(generation_status::STARTED));
        $this->assertSame(generation_progress::FAILED_STAGE, generation_progress::for_status(generation_status::FAILED));
        // A stage the bar can actually paint has a real percentage; the failed one does not.
        $this->assertNull(generation_progress::FAILED_STAGE['percent']);
    }

    /**
     * A failed generation's percentage comes from pendingdata's shape, not a question count.
     */
    public function test_failed_percent_reads_pendingdata_shape(): void {
        $this->assertSame(25, generation_progress::failed_percent(null));
        $this->assertSame(25, generation_progress::failed_percent(''));
        $this->assertSame(25, generation_progress::failed_percent(json_encode([])));
        $this->assertSame(50, generation_progress::failed_percent(json_encode(['questions' => []])));
        $this->assertSame(
            75,
            generation_progress::failed_percent(json_encode(['questions' => [], 'evaluations' => []]))
        );
    }

    /**
     * Color_classes() covers every colour the bar can carry, so a re-render always clears the
     * Previous one - a colour present in a stage but missing here would stick on the element.
     */
    public function test_color_classes_cover_every_stage(): void {
        $classes = generation_progress::color_classes();

        $this->assertContains(generation_progress::DEFAULT_STAGE['color'], $classes);
        $this->assertContains(generation_progress::FAILED_STAGE['color'], $classes);
        foreach (generation_progress::STAGES as $stage) {
            $this->assertContains($stage['color'], $classes);
        }
        $this->assertSame($classes, array_values(array_unique($classes)), 'no duplicates');
    }

    /**
     * The payload handed to amd/src/status.js carries everything the JS needs, so it can own no
     * Copy of any of it.
     */
    public function test_config_json_carries_everything_the_js_needs(): void {
        $config = json_decode(generation_progress::config_json(), true);

        $this->assertIsArray($config);
        $this->assertSame(array_keys(generation_progress::STAGES), array_keys($config['stages']));
        $this->assertSame(generation_progress::color_classes(), $config['colorClasses']);
        // The terminal list is generation_status::TERMINAL, not a second copy.
        $this->assertSame(generation_status::TERMINAL, $config['terminal']);
        $this->assertSame(generation_progress::FAILED_STAGE['color'], $config['failed']['color']);

        foreach ($config['stages'] as $status => $stage) {
            $this->assertSame(generation_progress::STAGES[$status]['percent'], $stage['percent']);
            $this->assertSame(generation_progress::STAGES[$status]['color'], $stage['color']);
            $this->assertSame(generation_progress::STAGES[$status]['striped'], $stage['striped']);
        }
    }

    /**
     * Amd/src/status.js must not re-acquire its own copy of the stage mapping.
     */
    public function test_status_js_holds_no_copy_of_the_stage_mapping(): void {
        $js = file_get_contents(__DIR__ . '/../../amd/src/status.js');

        $this->assertStringNotContainsString('STAGE_INFO', $js, 'the JS stage table is back');
        $this->assertDoesNotMatchRegularExpression(
            '/percent:\s*\d+/',
            $js,
            'amd/src/status.js declares its own stage percentages instead of reading data-progress-config'
        );
        // The colour classes likewise come from PHP.
        $this->assertDoesNotMatchRegularExpression(
            "/'bg-(primary|success|secondary|danger)'\s*,\s*'bg-/",
            $js,
            'amd/src/status.js re-lists the bar colour classes'
        );
        $this->assertStringContainsString('data-progress-config', $js, 'the JS no longer reads the shared config');
    }

    /**
     * The terminal status list likewise appears only in the PHP constant.
     */
    public function test_status_js_holds_no_copy_of_the_terminal_statuses(): void {
        $js = file_get_contents(__DIR__ . '/../../amd/src/status.js');

        $this->assertStringNotContainsString('TERMINAL_STATUSES', $js);
        $this->assertDoesNotMatchRegularExpression(
            "/\[\s*'(completed|failed)'\s*,\s*'(completed|failed)'\s*\]/",
            $js,
            'amd/src/status.js re-lists the terminal statuses instead of reading them from PHP'
        );
    }
}
