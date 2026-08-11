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
 * Unit tests for uploaded-file text extraction (TXT only in ArtQTML Light).
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\text_extractor
 */
final class text_extractor_test extends \advanced_testcase {
    /**
     * Create a stored_file with the given raw bytes and filename.
     *
     * @param string $filename drives the extension dispatch (x.txt, ...)
     * @param string $content raw bytes
     * @return \stored_file
     */
    protected function make_file(string $filename, string $content): \stored_file {
        $fs = get_file_storage();
        $record = [
            'contextid' => \context_system::instance()->id,
            'component' => 'local_artqtml',
            'filearea'  => 'unittest',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => $filename,
        ];
        return $fs->create_file_from_string($record, $content);
    }

    /**
     * A UTF-8 text file (accented Hungarian characters) passes through unchanged.
     */
    public function test_extract_txt_utf8_passthrough(): void {
        $this->resetAfterTest();

        $content = 'Az árvíztűrő tükörfúrógép.';
        $file = $this->make_file('utf8.txt', $content);

        $result = text_extractor::extract($file);
        $this->assertSame($content, $result);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    /**
     * A legacy ISO-8859-2 (Latin-2) file is detected and converted to valid UTF-8.
     */
    public function test_extract_txt_latin2_converted_to_utf8(): void {
        $this->resetAfterTest();

        $latin2 = 'B' . chr(0xE1) . 'r ' . chr(0xF5) . 'z';
        $file = $this->make_file('latin2.txt', $latin2);

        $result = text_extractor::extract($file);

        $this->assertTrue(mb_check_encoding($result, 'UTF-8'), 'output must be valid UTF-8');
        $this->assertStringContainsString("\u{00E1}", $result);
        $this->assertStringContainsString("\u{0151}", $result);
    }

    /**
     * An unsupported extension yields an empty string.
     */
    public function test_extract_unknown_extension_returns_empty(): void {
        $this->resetAfterTest();

        $file = $this->make_file('notes.md', 'Some markdown content.');
        $this->assertSame('', text_extractor::extract($file));
    }

    /**
     * A TXT whose bytes are mostly NULs is refused.
     */
    public function test_a_nul_heavy_txt_is_refused(): void {
        $this->resetAfterTest();

        $content = str_repeat("\0", 200) . 'hello';
        $report = text_extractor::extract_with_report($this->make_file('nul.txt', $content));
        $this->assertSame(extraction_result::STATUS_REJECTED, $report['status']);
        $this->assertSame(extraction_result::REASON_INVALID_STRUCTURE, $report['reason']);
        $this->assertSame('', text_extractor::extract($this->make_file('nul2.txt', $content)));
    }

    /**
     * TXT remains a supported upload extension in Light.
     */
    public function test_txt_is_supported(): void {
        $this->resetAfterTest();

        $this->assertTrue(text_extractor::is_supported_file($this->make_file('ok.txt', 'Plain text.')));
        $this->assertFalse(text_extractor::is_supported_file($this->make_file('notes.md', 'md')));
    }
}
