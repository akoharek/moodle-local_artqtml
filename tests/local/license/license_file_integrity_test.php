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

namespace local_artqtml\local\license;

/**
 * Unit tests for license file-integrity (manifest) checking (functional spec ch.10).
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\license\license_file_integrity
 */
final class license_file_integrity_test extends \advanced_testcase {
    /**
     * Build a license record carrying the given files manifest as its licensejson.
     *
     * @param array|null $files null for a pre-integrity license with no files key at all
     * @return \stdClass
     */
    protected function record_with_manifest(?array $files): \stdClass {
        $decoded = ['edition' => 'perpetual'];
        if ($files !== null) {
            $decoded['files'] = $files;
        }
        return (object) ['licensejson' => json_encode($decoded)];
    }

    /**
     * A license with no files manifest at all (pre-integrity format) is treated as passing, so
     * already-issued licenses keep working unchanged.
     */
    public function test_verify_passes_when_no_manifest(): void {
        $result = license_file_integrity::verify($this->record_with_manifest(null));

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['modified']);
        $this->assertSame([], $result['missing']);
        $this->assertSame([], $result['extra']);
    }

    /**
     * A manifest-listed file whose current contents don't match its recorded hash is flagged as
     * modified.
     */
    public function test_verify_detects_modified_file(): void {
        // The version.php file really exists in the plugin, but this hash is deliberately wrong.
        $record = $this->record_with_manifest([
            ['path' => 'version.php', 'hash' => str_repeat('0', 64)],
        ]);

        $result = license_file_integrity::verify($record);

        $this->assertFalse($result['ok']);
        $this->assertContains('version.php', $result['modified']);
    }

    /**
     * A manifest-listed file that no longer exists on disk is flagged as missing.
     */
    public function test_verify_detects_missing_file(): void {
        $record = $this->record_with_manifest([
            ['path' => 'classes/does/not/exist.php', 'hash' => str_repeat('a', 64)],
        ]);

        $result = license_file_integrity::verify($record);

        $this->assertFalse($result['ok']);
        $this->assertContains('classes/does/not/exist.php', $result['missing']);
    }

    /**
     * A manifest path escaping the plugin directory is never followed - it is treated as missing.
     */
    public function test_verify_rejects_path_traversal(): void {
        $record = $this->record_with_manifest([
            ['path' => '../../config.php', 'hash' => str_repeat('a', 64)],
        ]);

        $result = license_file_integrity::verify($record);

        $this->assertContains('../../config.php', $result['missing']);
    }

    /**
     * BL-10: COPYRIGHT.txt is covered by the manifest, even though it is not a .php file.
     *
     * This is the requirement the copyright file was created under - a file stating the terms that
     * could be edited or deleted with nothing noticing would state nothing. The assertion is made
     * through verify()'s "extra" list rather than by calling the enumerator: a manifest that does
     * not name COPYRIGHT.txt must report it as an unlisted file on disk, which is exactly what an
     * attacker replacing it would produce.
     */
    public function test_copyright_file_is_covered_by_the_manifest(): void {
        $record = $this->record_with_manifest([
            ['path' => 'version.php', 'hash' => hash_file('sha256', __DIR__ . '/../../../version.php')],
        ]);

        $result = license_file_integrity::verify($record);

        $this->assertContains('COPYRIGHT.txt', $result['extra']);
    }

    /**
     * BL-10: the two enumerators must name the same extra files.
     *
     * There are two implementations of the same file selection on purpose - the plugin class runs
     * inside Moodle, tools/generate_license.php runs as plain CLI with no bootstrap and cannot read
     * the class's constant. Duplication that nothing checks is duplication that drifts, and the
     * failure mode is expensive and remote: every installation reports a missing or an extra file
     * on every admin page, and the cause is two lines in two files nobody is looking at together.
     */
    public function test_both_enumerators_name_the_same_extra_files(): void {
        $script = file_get_contents(__DIR__ . '/../../../tools/generate_license.php');
        $this->assertNotFalse($script, 'tools/generate_license.php must be readable.');

        $matched = preg_match(
            '/const\s+ARTQTML_MANIFEST_EXTRA_FILES\s*=\s*\[(.*?)\];/s',
            $script,
            $matches
        );
        $this->assertSame(1, $matched, 'tools/generate_license.php must declare ARTQTML_MANIFEST_EXTRA_FILES.');

        preg_match_all("/'([^']+)'/", $matches[1], $names);
        $fromscript = $names[1];

        $reflection = new \ReflectionClass(license_file_integrity::class);
        $fromclass = $reflection->getConstant('MANIFEST_EXTRA_FILES');

        sort($fromscript);
        sort($fromclass);

        $this->assertSame($fromclass, $fromscript);
    }

    /**
     * The vendor-facing error code has the documented AIQ-YYYYMMDD-<logid> shape and logs an
     * (encrypted) violation row.
     */
    public function test_integrity_error_code_format_and_logging(): void {
        global $DB;
        $this->resetAfterTest();

        $code = license_file_integrity::integrity_error_code([
            'modified' => ['version.php'],
            'missing'  => [],
            'extra'    => [],
        ]);

        $this->assertMatchesRegularExpression('/^AIQ-\d{8}-\d+$/', $code);
        // An encrypted violation row was recorded; its plaintext file list is never stored.
        $rows = $DB->get_records('local_artqtml_log', ['event' => license_constants::INTEGRITY_VIOLATION_EVENT]);
        $this->assertNotEmpty($rows);
        $row = reset($rows);
        $this->assertStringNotContainsString('version.php', (string) $row->data);
    }
}
