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
 * Licence / date banner behaviour for Lic-025/026 and Glob-042 (2026-08-07).
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml_license_warning_banner
 * @covers     \local_artqtml\local\license\license_renderer
 */
final class license_banner_test extends \advanced_testcase {
    /**
     * Soft expiry warning renders yellow markup with the plugin datetimeformat (not locale short).
     */
    public function test_expiry_warning_banner_uses_datetimeformat(): void {
        $this->resetAfterTest();

        $expires = (new \DateTimeImmutable('2026-09-15 14:30:00', new \DateTimeZone('UTC')))->getTimestamp();

        // Glob-042 format contract shared by the licence panel and the expiry warning.
        $format = get_string('datetimeformat', 'local_artqtml');
        $this->assertSame('%Y.%m.%d %H:%M', $format);
        $formatted = userdate($expires, $format);
        $this->assertMatchesRegularExpression('/^\d{4}\.\d{2}\.\d{2} \d{2}:\d{2}$/', $formatted);

        // Renderer + banner must use datetimeformat (no Moodle locale short-date exception).
        $renderer = file_get_contents(__DIR__ . '/../../classes/local/license/license_renderer.php');
        $this->assertStringContainsString("get_string('datetimeformat', 'local_artqtml')", $renderer);
        $this->assertStringNotContainsString("get_string('strftimedate', 'langconfig')", $renderer);

        $lib = file_get_contents(__DIR__ . '/../../lib.php');
        $this->assertStringContainsString(
            "userdate((int) \$status['expiresat'], get_string('datetimeformat', 'local_artqtml'))",
            $lib
        );

        // Lic-025/026: list page always echoes the banner helper (soft warning included).
        $index = file_get_contents(__DIR__ . '/../../index.php');
        $this->assertStringContainsString('echo local_artqtml_license_warning_banner();', $index);
        $this->assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*\$licenseblocked\s*\)\s*\{[^}]*license_warning_banner/s',
            $index
        );
    }
}
