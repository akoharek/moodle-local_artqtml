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
 * Unit tests for the fixed-path PHP debug file logger (security finding #4).
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\debug_logger
 */
final class debug_logger_test extends \advanced_testcase {
    /**
     * Path resolution always lands under dataroot/local_artqtml/debug.log.
     */
    public function test_path_is_fixed_under_dataroot(): void {
        global $CFG;

        $this->resetAfterTest();

        // Legacy free-form config must never influence the resolved path.
        set_config('debugfilepath', '/tmp/artqtml-evil-debug.log', 'local_artqtml');

        $this->assertSame(
            $CFG->dataroot . '/local_artqtml/debug.log',
            debug_logger::path()
        );
    }

    /**
     * When debug mode is off, nothing is written — including to a legacy configured path.
     */
    public function test_log_is_noop_when_debugmode_off(): void {
        $this->resetAfterTest();

        set_config('debugmode', 0, 'local_artqtml');
        set_config('debugfilepath', '/tmp/artqtml-evil-debug.log', 'local_artqtml');

        $fixed = debug_logger::path();
        if (file_exists($fixed)) {
            unlink($fixed);
        }

        debug_logger::log('should-not-appear');

        $this->assertFileDoesNotExist('/tmp/artqtml-evil-debug.log');
        $this->assertFalse(is_file($fixed));
    }

    /**
     * Writes go only to the fixed dataroot path, with an ArtQTML line prefix; arbitrary paths ignored.
     */
    public function test_log_writes_fixed_path_and_ignores_legacy_config(): void {
        $this->resetAfterTest();

        $evil = make_temp_directory('local_artqtml_debug_logger_test') . '/evil.log';
        set_config('debugmode', 1, 'local_artqtml');
        set_config('debugfilepath', $evil, 'local_artqtml');

        $fixed = debug_logger::path();
        if (file_exists($fixed)) {
            unlink($fixed);
        }

        debug_logger::log('finding-four-sentinel');

        $this->assertFileDoesNotExist($evil);
        $this->assertFileExists($fixed);

        $contents = file_get_contents($fixed);
        $this->assertStringContainsString('[local_artqtml]', $contents);
        $this->assertStringContainsString('finding-four-sentinel', $contents);
        $this->assertDoesNotMatchRegularExpression('/^[^\\[]/', $contents);
    }
}
