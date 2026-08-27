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

defined('MOODLE_INTERNAL') || die();

/**
 * Static check: every entry script sets up $PAGE.
 *
 * D-6: delete.php and modelaction.php act and redirect without rendering a page of their own, and
 * That is exactly why both had skipped $PAGE->set_context()/set_url(). It does not exempt them -
 * Format_string() reads the context, redirect() and the exception pages read the URL - and Moodle
 * Answers the omission with developer warnings, refusing even to redirect once it has printed
 * Them. A browser test covers the delete path; this one covers the shape, so the next
 * Act-and-redirect script cannot reintroduce it unnoticed.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class entry_script_page_setup_test extends \advanced_testcase {
    /**
     * A script that pulls in Moodle's config.php is reachable by URL: that is the definition of an
     * Entry script used here, so a newly added one is picked up without editing a list.
     *
     * @return string[] absolute paths, keyed by filename
     */
    private function entry_scripts(): array {
        $root = dirname(__DIR__, 2);
        $found = [];

        // The phpstan-bootstrap.php file pulls in config.php for static analysis only - it is never
        // Served
        // And renders nothing, so the $PAGE rule below does not apply to it. Named here rather
        // Than pattern-matched, so adding a second exception has to be a deliberate act.
        $notserved = ['phpstan-bootstrap.php'];

        foreach (glob($root . '/*.php') as $path) {
            if (in_array(basename($path), $notserved, true)) {
                continue;
            }

            $source = file_get_contents($path);
            if (strpos($source, "/../../config.php") !== false) {
                $found[basename($path)] = $path;
            }
        }

        return $found;
    }

    /**
     * Guard for the guard: if the discovery above ever stops finding scripts, the real assertions
     * Would pass vacuously over an empty list.
     *
     * @return void
     */
    public function test_entry_scripts_are_discovered(): void {
        $scripts = $this->entry_scripts();

        $this->assertGreaterThanOrEqual(8, count($scripts), 'entry-script discovery found too few files');
        $this->assertArrayHasKey('delete.php', $scripts);
        $this->assertArrayHasKey('modelaction.php', $scripts);
        $this->assertArrayNotHasKey('license.php', $scripts);
    }

    /**
     * Either the script sets both halves itself, or admin_externalpage_setup() does it for them.
     * Nothing else counts - a script that renders no page still needs both.
     *
     * @return void
     */
    public function test_every_entry_script_sets_up_page(): void {
        $missing = [];

        foreach ($this->entry_scripts() as $name => $path) {
            $source = file_get_contents($path);

            if (strpos($source, 'admin_externalpage_setup(') !== false) {
                continue;
            }

            $hascontext = strpos($source, '$PAGE->set_context(') !== false;
            $hasurl = strpos($source, '$PAGE->set_url(') !== false;

            if (!$hascontext || !$hasurl) {
                $lacks = [];
                if (!$hascontext) {
                    $lacks[] = '$PAGE->set_context()';
                }
                if (!$hasurl) {
                    $lacks[] = '$PAGE->set_url()';
                }
                $missing[] = $name . ' lacks ' . implode(' and ', $lacks);
            }
        }

        $this->assertSame(
            [],
            $missing,
            "every entry script must set up \$PAGE, including act-and-redirect ones:\n" . implode("\n", $missing)
        );
    }

    /**
     * Act-and-redirect scripts must reject GET and require POST + sesskey (S-05).
     *
     * @return void
     */
    public function test_act_and_redirect_scripts_require_post(): void {
        foreach (['delete.php', 'modelaction.php'] as $name) {
            $scripts = $this->entry_scripts();
            $this->assertArrayHasKey($name, $scripts);
            $source = file_get_contents($scripts[$name]);
            $this->assertStringContainsString('data_submitted()', $source, $name);
            $this->assertStringContainsString('require_sesskey()', $source, $name);
        }
    }

    /**
     * Approve.php must expose a GET navigation control back to the site-wide list page.
     *
     * @return void
     */
    public function test_approve_page_links_back_to_index(): void {
        $scripts = $this->entry_scripts();
        $this->assertArrayHasKey('approve.php', $scripts);

        $source = file_get_contents($scripts['approve.php']);
        $this->assertStringContainsString("/local/artqtml/index.php", $source);
        $this->assertStringContainsString("get_string('backtolist', 'local_artqtml')", $source);
        $this->assertStringContainsString('single_button', $source);
        $this->assertStringContainsString('artqtml-approve-backtolist', $source);
    }
}
