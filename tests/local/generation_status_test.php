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
 * Unit tests for the seven generation status values.
 *
 * requires one shared constant that every use site reads from, with the literal list
 * appearing nowhere else ("a literál lista sehol nem ismételhető meg"). The last assertion here
 * greps the whole plugin for a re-typed list, so a future re-inlining fails the build.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\generation_status
 */
final class generation_status_test extends \advanced_testcase {
    /**
 * exactly seven statuses, in pipeline order, no duplicates, none empty.
 */
    public function test_exactly_seven_statuses(): void {
        $this->assertSame(
            ['started', 'generating', 'validating', 'saving', 'completed', 'partial', 'failed'],
            generation_status::VALUES
        );
        $this->assertCount(7, generation_status::VALUES);
        $this->assertNotContains('', generation_status::VALUES);
        $this->assertSame(
            generation_status::VALUES,
            array_values(array_unique(generation_status::VALUES))
        );
    }

    /**
     * The named subsets are genuine subsets of the seven, and do not overlap.
     */
    public function test_subsets_are_consistent_with_values(): void {
        foreach (generation_status::IN_PROGRESS as $status) {
            $this->assertContains($status, generation_status::VALUES, "$status is not one of the seven");
        }
        foreach (generation_status::TERMINAL as $status) {
            $this->assertContains($status, generation_status::VALUES, "$status is not one of the seven");
        }
        $this->assertSame(
            [],
            array_intersect(generation_status::IN_PROGRESS, generation_status::TERMINAL),
            'a status cannot be both in progress and terminal'
        );
        $this->assertSame(['generating', 'validating', 'saving'], generation_status::IN_PROGRESS);
        $this->assertSame(
            ['completed', 'partial', 'failed'],
            generation_status::TERMINAL
        );
        $this->assertFalse(generation_status::is_in_progress(generation_status::PARTIAL));
        $this->assertTrue(generation_status::is_in_progress(generation_status::VALIDATING));
        $this->assertFalse(generation_status::is_in_progress(generation_status::STARTED));
        $this->assertFalse(generation_status::is_in_progress(generation_status::COMPLETED));
    }

    /**
     * every status has a real lang label, and 'started' shows as "Megkezdett" in
     * Hungarian - no raw machine key may reach the UI.
     */
    public function test_every_status_has_a_lang_label(): void {
        $this->resetAfterTest();

        foreach (generation_status::VALUES as $status) {
            $label = generation_status::label($status);
            $this->assertNotEmpty($label);
            $this->assertStringNotContainsString(
                '[[',
                $label,
                "status_$status is missing from the language file"
            );
            // The label must be a real word, not the machine key echoed back.
            $this->assertNotSame($status, $label, "status_$status must not render as its raw key");
        }

        $this->assertSame('Started', generation_status::label(generation_status::STARTED));

        // "A started státusz megjelenített neve »Megkezdett«". Asserted against the
        // shipped lang file rather than through get_string() under force_current_language('hu'):
        // the CI/PHPUnit install has only the English pack, so get_string() would silently fall
        // back to English and the assertion would test nothing. What the requirement actually
        // fixes is the string the plugin ships.
        $this->assertSame('Megkezdett', $this->shipped_string('hu', 'status_started'));
        $this->assertSame('Started', $this->shipped_string('en', 'status_started'));
    }

    /**
     * Read one string straight out of a shipped language file, independent of which language packs
     * happen to be installed in the test environment.
     *
     * @param string $lang 'en' or 'hu'
     * @param string $key the lang string identifier
     * @return string
     */
    protected function shipped_string(string $lang, string $key): string {
        $string = [];
        require(__DIR__ . '/../../lang/' . $lang . '/local_artqtml.php');

        $this->assertArrayHasKey($key, $string, "$key is missing from lang/$lang");

        return $string[$key];
    }

    /**
     * Every status maps to a badge class, and an unknown one degrades safely.
     */
    public function test_badge_class_covers_every_status(): void {
        foreach (generation_status::VALUES as $status) {
            $this->assertStringStartsWith('badge-', generation_status::badge_class($status));
        }
        $this->assertSame('badge-secondary', generation_status::badge_class('no-such-status'));
    }

    /**
     * normalise() accepts the seven and rejects anything else (including the retired pending/
     * processing names the pre-v24 specification listed).
     */
    public function test_normalise(): void {
        foreach (generation_status::VALUES as $status) {
            $this->assertSame($status, generation_status::normalise($status));
        }
        $this->assertNull(generation_status::normalise('pending'));
        $this->assertNull(generation_status::normalise('processing'));
        $this->assertNull(generation_status::normalise(''));
        $this->assertNull(generation_status::normalise(null));
        $this->assertSame(
            generation_status::STARTED,
            generation_status::normalise('bogus', generation_status::STARTED)
        );
    }

    /**
     * The list page's status sort weights cover exactly the seven statuses - no more, no fewer.
     */
    public function test_list_page_sort_order_keys_match_the_constant(): void {
        $order = new \ReflectionClassConstant(generation_list::class, 'STATUS_ORDER');
        $keys = array_keys($order->getValue());

        sort($keys);
        $values = generation_status::VALUES;
        sort($values);

        $this->assertSame($values, $keys, 'generation_list::STATUS_ORDER must key on exactly the seven statuses');
    }

    /**
     * the literal seven-value list appears in exactly one file - this constant's own
     * definition. Anything else re-typing it (a filter builder, a SQL string, a CLI validator)
     * fails here.
     */
    public function test_no_file_outside_the_constant_repeats_the_literal_list(): void {
        $root = realpath(__DIR__ . '/../..');
        $allowed = [
            // The single source of truth itself.
            $root . '/classes/local/generation_status.php',
            // This test, which asserts the list verbatim.
            __FILE__,
            // Frozen historical upgrade steps must keep behaving as they did when they ran.
            $root . '/db/upgrade.php',
        ];

        $offenders = [];
        foreach ($this->plugin_php_files($root) as $file) {
            if (in_array($file, $allowed, true)) {
                continue;
            }
            $contents = file_get_contents($file);
            // Any two adjacent members of the list quoted next to each other is enough to catch a
            // re-typed list, without tripping on a legitimate single-value comparison.
            if (
                preg_match(
                    "/'(started|generating|validating|saving|completed|partial|failed)'" .
                    "\s*,\s*" .
                    "'(started|generating|validating|saving|completed|partial|failed)'/",
                    $contents
                )
            ) {
                $offenders[] = str_replace($root . '/', '', $file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'these files re-type the status list instead of reading \local_artqtml\local\generation_status: '
                . implode(', ', $offenders)
        );
    }

    /**
 * every use site the requirement names - the list page, the status page, the
 * scheduled tasks and the filters - reads the statuses from the shared constant.
 */
    public function test_every_use_site_reads_from_the_shared_constant(): void {
        $root = realpath(__DIR__ . '/../..');
        $usesites = [
            // List page: the status filter options and the status badge.
            'classes/local/generation_list.php',
            // Status page: the progress/stage rendering.
            'status.php',
            // Scheduled task: which statuses it claims and drives forward.
            'classes/task/process_pending_generations.php',
        ];

        foreach ($usesites as $relative) {
            $path = $root . '/' . $relative;
            $this->assertFileExists($path, "$relative no longer exists - update this test's use-site list");
            $this->assertMatchesRegularExpression(
                '/\bgeneration_status::/',
                file_get_contents($path),
                "$relative must read the statuses from \\local_artqtml\\local\\generation_status"
            );
        }
    }

    /**
     * Every shipped PHP file of the plugin, excluding vendor/node_modules and the visual baselines.
     *
     * @param string $root
     * @return string[]
     */
    protected function plugin_php_files(string $root): array {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileinfo) {
            $path = $fileinfo->getPathname();
            if (substr($path, -4) !== '.php') {
                continue;
            }
            if (preg_match('#/(node_modules|vendor|\.git)/#', $path)) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);

        return $files;
    }
}
