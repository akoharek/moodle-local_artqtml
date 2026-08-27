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
 * Regression tests for status.php action URLs (F-08).
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class status_page_test extends \advanced_testcase {
    /**
     * FAILED secondary CTA must return to the list, not generate.php (bounce loop).
     */
    public function test_failed_secondary_cta_links_to_list(): void {
        $source = file_get_contents(dirname(__DIR__, 2) . '/status.php');
        $this->assertNotFalse($source);

        $this->assertMatchesRegularExpression(
            '/\$failedactions\s*=.*?html_writer::link\(\$backurl,\s*get_string\(\'backtolist\'/s',
            $source,
            'Failed-state secondary button must link to index.php via backtolist'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$failedactions\s*=.*?generate\.php/s',
            $source,
            'Failed-state secondary button must not link to generate.php'
        );
    }
}
