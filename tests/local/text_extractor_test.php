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
 * Unit tests for uploaded-file text extraction, focusing on the TXT encoding path
 * (functional spec 5.2, Felt-009/010 - the mb_detect_encoding UTF-8/Latin-2 fix).
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
     * @param string $filename drives the extension dispatch (x.txt, x.md, ...)
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
     * A legacy ISO-8859-2 (Latin-2) file is detected and converted to valid UTF-8 - the fix for
     * the non-existent core_text::detect_encoding() call (text_extractor.php).
     */
    public function test_extract_txt_latin2_converted_to_utf8(): void {
        $this->resetAfterTest();

        // The Hungarian phrase "Bar oz" with accents, encoded as ISO-8859-2: the accented a is
        // byte 0xE1 and the double-acute o is byte 0xF5. Built from raw bytes so this test file's
        // own encoding can't influence the input.
        $latin2 = 'B' . chr(0xE1) . 'r ' . chr(0xF5) . 'z';
        $file = $this->make_file('latin2.txt', $latin2);

        $result = text_extractor::extract($file);

        $this->assertTrue(mb_check_encoding($result, 'UTF-8'), 'output must be valid UTF-8');
        $this->assertStringContainsString("\u{00E1}", $result); // Accented a.
        $this->assertStringContainsString("\u{0151}", $result); // Double-acute o.
    }

    /**
     * An unsupported extension yields an empty string (treated as an empty-field case, not an
     * error).
     */
    public function test_extract_unknown_extension_returns_empty(): void {
        $this->resetAfterTest();

        $file = $this->make_file('notes.md', 'Some markdown content.');
        $this->assertSame('', text_extractor::extract($file));
    }

    /**
     * Build a minimal DOCX in memory.
     *
     * Small hand-written parts rather than a real Word file: the assertions here are about what
     * the parser does with specific WordprocessingML constructs, and a fixture that shows exactly
     * those constructs is both readable and small enough not to matter for test memory.
     *
     * @param string $body the contents of w:body
     * @param string $styles the contents of w:styles, if any
     * @param array $extra additional archive entries, name => contents
     * @return \stored_file
     */
    protected function make_docx(string $body, string $styles = '', array $extra = []): \stored_file {
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $document = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<w:document ' . $ns . '><w:body>' . $body . '</w:body></w:document>';

        $path = make_temp_directory('local_artqtml_test') . '/' . uniqid('docx_', true) . '.docx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $document);
        if ($styles !== '') {
            $zip->addFromString(
                'word/styles.xml',
                '<?xml version="1.0" encoding="UTF-8"?><w:styles ' . $ns . '>' . $styles . '</w:styles>'
            );
        }
        foreach ($extra as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        $file = $this->make_file(uniqid('doc', true) . '.docx', file_get_contents($path));
        unlink($path);

        return $file;
    }

    /**
     * An ordinary paragraph is read out of the document body.
     */
    public function test_docx_body_text_is_extracted(): void {
        $this->resetAfterTest();

        $file = $this->make_docx('<w:p><w:r><w:t>Az alma egy gyümölcs.</w:t></w:r></w:p>');
        $report = text_extractor::extract_with_report($file);

        $this->assertSame(extraction_result::STATUS_OK, $report['status']);
        $this->assertSame('Az alma egy gyümölcs.', $report['text']);
    }

    /**
     * Header and footer parts are not read, even when they are in the archive.
     *
     * A deliberate policy rather than a parser limitation: a footer is a conventional place to put
     * decoration, which makes it a conventional place to hide an instruction, and it is not what a
     * teacher means by the text of their document.
     */
    public function test_docx_header_and_footer_are_not_extracted(): void {
        $this->resetAfterTest();

        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $part = static function (string $tag, string $text) use ($ns): string {
            return '<?xml version="1.0" encoding="UTF-8"?><w:' . $tag . ' ' . $ns . '>'
                . '<w:p><w:r><w:t>' . $text . '</w:t></w:r></w:p></w:' . $tag . '>';
        };

        $file = $this->make_docx(
            '<w:p><w:r><w:t>Body text.</w:t></w:r></w:p>',
            '',
            [
                'word/header1.xml' => $part('hdr', 'HEADER_SENTINEL'),
                'word/footer1.xml' => $part('ftr', 'FOOTER_SENTINEL'),
            ]
        );

        $report = text_extractor::extract_with_report($file);

        $this->assertSame(extraction_result::STATUS_OK, $report['status']);
        $this->assertStringNotContainsString('HEADER_SENTINEL', $report['text']);
        $this->assertStringNotContainsString('FOOTER_SENTINEL', $report['text']);
        $this->assertSame('Body text.', $report['text']);
    }

    /**
     * A run's formatting never removes its text - whatever the formatting is.
     *
     * These are the five shapes that were dropped until 2026-08-04: vanished, web-hidden, a
     * two-point font, a white colour, and each of those inherited from a style. All of them are
     * kept now, because the extracted text goes into the source-text box where the teacher reads
     * it - and the parser's guesses about appearance were wrong in the expensive direction, since
     * it cannot know what is behind the text.
     *
     * @dataProvider hidden_run_provider
     * @param string $body the w:body contents
     * @param string $styles the w:styles contents
     */
    public function test_formatting_never_removes_a_runs_text(string $body, string $styles): void {
        $this->resetAfterTest();

        $visible = '<w:p><w:r><w:t>Ez a látható mondat.</w:t></w:r></w:p>';
        $report = text_extractor::extract_with_report($this->make_docx($body . $visible, $styles));

        $this->assertSame(extraction_result::STATUS_OK, $report['status']);
        $this->assertStringContainsString('HIDDEN_SENTINEL', $report['text']);
        $this->assertStringContainsString('Ez a látható mondat.', $report['text']);
    }

    /**
     * Cases for {@see self::test_a_hidden_run_refuses_the_document()}.
     *
     * @return array<string, array{string, string}>
     */
    public static function hidden_run_provider(): array {
        $run = static function (string $props): string {
            return '<w:p><w:r><w:rPr>' . $props . '</w:rPr><w:t>HIDDEN_SENTINEL</w:t></w:r></w:p>';
        };
        $styled = static function (string $styleid): string {
            return '<w:p><w:r><w:rPr><w:rStyle w:val="' . $styleid . '"/></w:rPr>'
                . '<w:t>HIDDEN_SENTINEL</w:t></w:r></w:p>';
        };
        $style = static function (string $id, string $props, string $basedon = ''): string {
            return '<w:style w:styleId="' . $id . '">'
                . ($basedon !== '' ? '<w:basedOn w:val="' . $basedon . '"/>' : '')
                . '<w:rPr>' . $props . '</w:rPr></w:style>';
        };

        return [
            'vanish'               => [$run('<w:vanish/>'), ''],
            'webHidden'            => [$run('<w:webHidden/>'), ''],
            // Half-points: w:sz of 4 is a two-point font, the case named in the report.
            'two point font'       => [$run('<w:sz w:val="4"/>'), ''],
            'white text'           => [$run('<w:color w:val="FFFFFF"/>'), ''],
            'near white text'      => [$run('<w:color w:val="FEFEFE"/>'), ''],
            'inherited from style' => [$styled('Sneaky'), $style('Sneaky', '<w:vanish/>')],
            'inherited via basedOn' => [
                $styled('Child'),
                $style('Parent', '<w:color w:val="FFFFFF"/>') . $style('Child', '', 'Parent'),
            ],
        ];
    }

    /**
     * A circular basedOn chain terminates instead of looping.
     *
     * Two styles based on each other is a two-line edit in styles.xml, and a naive resolver on it
     * does not return - which on an upload path is a denial of service, not a tidy-up.
     */
    public function test_a_circular_style_chain_terminates(): void {
        $this->resetAfterTest();

        $styles = '<w:style w:styleId="A"><w:basedOn w:val="B"/><w:rPr/></w:style>'
            . '<w:style w:styleId="B"><w:basedOn w:val="A"/><w:rPr/></w:style>';

        $report = text_extractor::extract_with_report($this->make_docx(
            '<w:p><w:r><w:rPr><w:rStyle w:val="A"/></w:rPr><w:t>Visible text.</w:t></w:r></w:p>',
            $styles
        ));

        $this->assertSame(extraction_result::STATUS_OK, $report['status']);
        $this->assertSame('Visible text.', $report['text']);
    }

    /**
     * Deleted text and field instructions are left out of the extracted text.
     */
    public function test_tracked_deletions_and_field_instructions_are_skipped(): void {
        $this->resetAfterTest();

        $body = '<w:p><w:r><w:t>Kept.</w:t></w:r>'
            . '<w:del><w:r><w:t>DELETED_SENTINEL</w:t></w:r></w:del>'
            . '<w:r><w:instrText>INSTR_SENTINEL</w:instrText></w:r></w:p>';

        $report = text_extractor::extract_with_report($this->make_docx($body));

        $this->assertSame(extraction_result::STATUS_OK, $report['status']);
        $this->assertStringNotContainsString('DELETED_SENTINEL', $report['text']);
        $this->assertStringNotContainsString('INSTR_SENTINEL', $report['text']);
    }

    /**
     * A DOCTYPE declaration refuses the file.
     *
     * There is no legitimate DOCTYPE in a WordprocessingML part, and every entity-expansion attack
     * begins with one.
     */
    public function test_a_doctype_refuses_the_document(): void {
        $this->resetAfterTest();

        $path = make_temp_directory('local_artqtml_test') . '/' . uniqid('docx_', true) . '.docx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0"?><!DOCTYPE w:document [<!ENTITY x "y">]>'
                . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                . '<w:body><w:p><w:r><w:t>Text.</w:t></w:r></w:p></w:body></w:document>'
        );
        $zip->close();
        $file = $this->make_file(uniqid('doc', true) . '.docx', file_get_contents($path));
        unlink($path);

        $report = text_extractor::extract_with_report($file);

        $this->assertSame(extraction_result::STATUS_REJECTED, $report['status']);
        $this->assertSame(extraction_result::REASON_INVALID_STRUCTURE, $report['reason']);
    }

    /**
     * A file that is not a ZIP at all is refused as malformed rather than as unsupported.
     */
    public function test_a_docx_that_is_not_a_zip_is_refused(): void {
        $this->resetAfterTest();

        $report = text_extractor::extract_with_report($this->make_file('broken.docx', 'not a zip at all'));

        $this->assertSame(extraction_result::STATUS_REJECTED, $report['status']);
        $this->assertSame(extraction_result::REASON_UNSUPPORTED_TYPE, $report['reason']);
    }

    /**
     * The extension alone does not decide: the file's own first bytes are checked.
     */
    public function test_the_file_signature_is_checked_not_just_the_extension(): void {
        $this->resetAfterTest();

        $this->assertFalse(text_extractor::is_supported_file($this->make_file('fake.pdf', 'PK' . "\x03\x04" . 'zip')));
        $this->assertFalse(text_extractor::is_supported_file($this->make_file('fake.docx', '%PDF-1.7')));
        $this->assertFalse(text_extractor::is_supported_file($this->make_file('fake.txt', "\x7fELF binary")));
        $this->assertFalse(text_extractor::is_supported_file($this->make_file('notes.rtf', 'plain text')));

        $this->assertTrue(text_extractor::is_supported_file($this->make_file('notes.txt', 'plain text')));
    }

    /**
     * A binary file renamed to .txt is refused rather than read as text.
     */
    public function test_a_nul_heavy_txt_is_refused(): void {
        $this->resetAfterTest();

        $report = text_extractor::extract_with_report(
            $this->make_file('binary.txt', "some text\x00\x00\x00 and more")
        );

        $this->assertSame(extraction_result::STATUS_REJECTED, $report['status']);
        $this->assertSame(extraction_result::REASON_INVALID_STRUCTURE, $report['reason']);
    }

    /**
     * Ordinary PDF show-text operators are still read - both forms.
     */
    public function test_pdf_show_text_operators_are_extracted(): void {
        $this->resetAfterTest();

        $pdf = "%PDF-1.4\n<< /Length 1 >>\nstream\n"
            . "BT /F1 12 Tf (Hello) Tj ET\n"
            . "BT /F1 12 Tf [(Wor) -20 (ld)] TJ ET\n"
            . "endstream\n";

        $report = text_extractor::extract_with_report($this->make_file('doc.pdf', $pdf));

        $this->assertSame(extraction_result::STATUS_OK, $report['status']);
        $this->assertStringContainsString('Hello', $report['text']);
        $this->assertStringContainsString('Wor', $report['text']);
    }

    /**
     * PDF formatting never removes text either - same decision, same reasoning.
     *
     * The invisible rendering mode is the case worth naming: it is what an OCR layer over a scanned
     * page uses, so dropping it would have emptied exactly the documents this extractor was
     * improved for.
     *
     * @dataProvider invisible_pdf_provider
     * @param string $operators the content-stream operators before the show-text call
     */
    public function test_pdf_formatting_never_removes_text(string $operators): void {
        $this->resetAfterTest();

        $pdf = "%PDF-1.4\n<< /Length 1 >>\nstream\n"
            . "BT " . $operators . " (HIDDEN_SENTINEL) Tj ET\n"
            . "BT /F1 12 Tf (Visible sentence) Tj ET\n"
            . "endstream\n";

        $report = text_extractor::extract_with_report($this->make_file('doc.pdf', $pdf));

        $this->assertSame(extraction_result::STATUS_OK, $report['status']);
        $this->assertStringContainsString('HIDDEN_SENTINEL', $report['text']);
        $this->assertStringContainsString('Visible sentence', $report['text']);
    }

    /**
     * An object announcing executable content is never read as text.
     *
     * The page-driven route only reads /Contents and never sees these, but the whole-file fallback
     * scans every stream - so without this a document's JavaScript action would have been read as
     * if it were teaching material.
     */
    public function test_executable_objects_are_not_read_as_text(): void {
        $this->resetAfterTest();

        $pdf = "%PDF-1.4\n"
            . "<< /S /JavaScript /JS (CODE_SENTINEL) >>\nstream\n(CODE_SENTINEL) Tj\nendstream\n"
            . "<< /Length 1 >>\nstream\nBT /F1 12 Tf (Ordinary text) Tj ET\nendstream\n";

        $report = text_extractor::extract_with_report($this->make_file('doc.pdf', $pdf));

        $this->assertStringNotContainsString('CODE_SENTINEL', $report['text']);
        $this->assertStringContainsString('Ordinary text', $report['text']);
    }

    /**
     * Cases for {@see self::test_invisible_pdf_text_refuses_the_document()}.
     *
     * @return array<string, array{string}>
     */
    public static function invisible_pdf_provider(): array {
        return [
            'rendering mode 3' => ['/F1 12 Tf 3 Tr'],
            'two point font'   => ['/F1 2 Tf'],
            'white rgb fill'   => ['/F1 12 Tf 1 1 1 rg'],
            'white grey fill'  => ['/F1 12 Tf 1 g'],
            'white cmyk fill'  => ['/F1 12 Tf 0 0 0 0 k'],
        ];
    }

    /**
     * q/Q keep the state stack balanced, so text after a save/restore pair is still read.
     *
     * The stack no longer carries a visibility judgement - it carries the active font, which is
     * what decodes the hexadecimal strings. Getting it wrong there loses text just as completely.
     */
    public function test_the_graphics_state_stack_is_restored(): void {
        $this->resetAfterTest();

        $pdf = "%PDF-1.4\n<< /Length 1 >>\nstream\n"
            . "q /F1 12 Tf 1 1 1 rg Q\n"
            . "BT /F1 12 Tf (Visible after restore) Tj ET\n"
            . "endstream\n";

        $report = text_extractor::extract_with_report($this->make_file('doc.pdf', $pdf));

        $this->assertSame(extraction_result::STATUS_OK, $report['status']);
        $this->assertStringContainsString('Visible after restore', $report['text']);
    }

    /**
     * The legacy string-only entry point returns nothing for a refused document.
     *
     * It cannot report a reason, which is why the upload page and the AJAX endpoint use
     * extract_with_report() instead - but it must not leak refused content either.
     */
    public function test_the_legacy_extract_returns_nothing_for_a_refused_file(): void {
        $this->resetAfterTest();

        $this->assertSame('', text_extractor::extract($this->make_file('broken.docx', 'not a zip at all')));
    }

    /**
     * A stored_file that reports an enormous size while holding a tiny, valid prefix.
     *
     * The point is to reach the size guard without writing 64 MiB to disk in a unit test. The
     * prefix is real, so is_supported_file() accepts the file on its signature and the route under
     * test is genuinely entered.
     *
     * @param string $filename drives the extension dispatch
     * @param string $prefix the file's first bytes, which must match the type's signature
     * @return \stored_file
     */
    protected function make_oversized_file(string $filename, string $prefix): \stored_file {
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            $this->fail('Could not open an in-memory stream for the fixture.');
        }
        fwrite($handle, $prefix);
        rewind($handle);

        $file = $this->createMock(\stored_file::class);
        $file->method('get_filename')->willReturn($filename);
        $file->method('get_content_file_handle')->willReturn($handle);
        // One byte over the limit, so the assertion is about the boundary and not about the size.
        $file->method('get_filesize')->willReturn(67108864 + 1);

        return $file;
    }

    /**
     * Every route refuses an oversized file before it opens it.
     *
     * BL-50: the DOCX route used to copy the file to a temp file as its very first act, so an
     * oversized document was written to disk before anything looked at its size. The TXT and PDF
     * routes always checked. This asserts the behaviour for all three, so the next reader does not
     * have to work out which one was the odd one.
     *
     * @dataProvider oversized_file_provider
     * @param string $filename
     * @param string $prefix
     */
    public function test_an_oversized_file_is_refused_by_every_route(string $filename, string $prefix): void {
        $this->resetAfterTest();

        $report = text_extractor::extract_with_report($this->make_oversized_file($filename, $prefix));

        $this->assertSame(extraction_result::STATUS_REJECTED, $report['status']);
        $this->assertSame(extraction_result::REASON_RESOURCE_LIMIT, $report['reason']);
    }

    /**
     * One case per supported type, with that type's real signature.
     *
     * @return array[]
     */
    public static function oversized_file_provider(): array {
        return [
            'docx' => ['huge.docx', "PK\x03\x04"],
            'pdf'  => ['huge.pdf', '%PDF-1.7'],
            'txt'  => ['huge.txt', 'Az alma '],
        ];
    }

    /**
     * A one-page PDF whose /Contents array names the given content-stream objects.
     *
     * Deliberately minimal: no /Length, so the stream's end is found by the trim fallback, and no
     * /Resources, so no font lookup happens. That keeps the fixture about the /Contents list.
     *
     * @param int[] $references object numbers to list in /Contents, in order
     * @param string[] $streams object number to that object's show-text operators
     * @return string the raw PDF
     */
    protected function make_paged_pdf(array $references, array $streams): string {
        $refs = implode(' ', array_map(static fn($n) => $n . ' 0 R', $references));
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Page /Contents [ " . $refs . " ] >>\nendobj\n";

        foreach ($streams as $number => $operators) {
            $pdf .= $number . " 0 obj\n<< >>\nstream\n" . $operators . "\nendstream\nendobj\n";
        }

        return $pdf;
    }

    /**
     * A page may not name more content streams than the cap allows.
     *
     * BL-49: /Contents is attacker-controlled and may name the same object any number of times,
     * each of which used to be inflated and held. The generator means only one is in memory at a
     * time; this cap is what bounds the repeated work. 100 references, 64 allowed.
     */
    public function test_a_page_reads_at_most_the_capped_number_of_content_streams(): void {
        $this->resetAfterTest();

        $pdf = $this->make_paged_pdf(
            array_fill(0, 100, 2),
            [2 => 'BT /F1 12 Tf (X) Tj ET']
        );

        $report = text_extractor::extract_with_report($this->make_file('repeated.pdf', $pdf));

        $this->assertSame(extraction_result::STATUS_OK, $report['status']);
        $this->assertSame(64, substr_count($report['text'], 'X'));
    }

    /**
     * The ordinary case still works: a page's several distinct streams are all read, in order.
     *
     * The guard on the change above - turning the array into a generator must not lose a stream.
     */
    public function test_a_pages_distinct_content_streams_are_all_read(): void {
        $this->resetAfterTest();

        $pdf = $this->make_paged_pdf(
            [2, 3, 4],
            [
                2 => 'BT /F1 12 Tf (Az alma) Tj ET',
                3 => 'BT /F1 12 Tf ( egy) Tj ET',
                4 => 'BT /F1 12 Tf ( gyumolcs) Tj ET',
            ]
        );

        $report = text_extractor::extract_with_report($this->make_file('pages.pdf', $pdf));

        $this->assertSame(extraction_result::STATUS_OK, $report['status']);
        $this->assertStringContainsString('Az alma egy gyumolcs', $report['text']);
    }

    /**
     * A structural limit refuses the document instead of starting the more expensive route.
     *
     * This is the half of BL-49 that is easy to get backwards. object_index::build() answers both
     * "this structure cannot be read" and "a hard limit was exceeded", and the caller answers the
     * first by running the older whole-file scan. While both returned null, exceeding a limit made
     * the extractor do MORE work, not less. 2,049 page objects, against a limit of 2,048.
     */
    public function test_a_structural_limit_refuses_rather_than_falling_back(): void {
        $this->resetAfterTest();

        $pdf = "%PDF-1.4\n";
        for ($number = 1; $number <= 2049; $number++) {
            $pdf .= $number . " 0 obj\n<< /Type /Page >>\nendobj\n";
        }
        // Text the whole-file scan would have found, so a fallback would be visible in the result
        // rather than having to be inferred from the status.
        $pdf .= "3000 0 obj\n<< >>\nstream\nBT /F1 12 Tf (FALLBACK_SENTINEL) Tj ET\nendstream\nendobj\n";

        $report = text_extractor::extract_with_report($this->make_file('manypages.pdf', $pdf));

        $this->assertSame(extraction_result::STATUS_REJECTED, $report['status']);
        $this->assertSame(extraction_result::REASON_RESOURCE_LIMIT, $report['reason']);
        $this->assertStringNotContainsString('FALLBACK_SENTINEL', $report['text']);
    }

    /**
     * A readable page structure that yields no text refuses the document, and says so.
     *
     * Decided by András on 2026-08-05. Until then this fell back to the whole-file scan for
     * whatever partial text it could find, and the teacher was given a fragment with nothing on
     * screen to say it was one.
     */
    public function test_a_page_structure_that_yields_no_text_is_refused(): void {
        $this->resetAfterTest();

        // A valid page whose content stream draws but shows no text, plus text elsewhere in the
        // file that only the whole-file scan would reach.
        $pdf = $this->make_paged_pdf([2], [2 => '0 0 100 100 re f'])
            . "3000 0 obj\n<< >>\nstream\nBT /F1 12 Tf (FALLBACK_SENTINEL) Tj ET\nendstream\nendobj\n";

        $report = text_extractor::extract_with_report($this->make_file('notext.pdf', $pdf));

        $this->assertSame(extraction_result::STATUS_REJECTED, $report['status']);
        $this->assertSame(extraction_result::REASON_NO_TEXT, $report['reason']);
        $this->assertStringNotContainsString('FALLBACK_SENTINEL', $report['text']);
    }

    /**
     * A PDF with no page objects at all still falls back and still yields its text.
     *
     * The boundary of the decision above, and the reason it is a boundary: this is not a damaged
     * file. Simple and older PDFs look like this, and the whole-file scan reads them correctly.
     */
    public function test_a_pdf_without_page_objects_still_falls_back(): void {
        $this->resetAfterTest();

        $pdf = "%PDF-1.4\n<< /Length 1 >>\nstream\nBT /F1 12 Tf (Regi szerkezet) Tj ET\nendstream\n";

        $report = text_extractor::extract_with_report($this->make_file('old.pdf', $pdf));

        $this->assertSame(extraction_result::STATUS_OK, $report['status']);
        $this->assertStringContainsString('Regi szerkezet', $report['text']);
    }

    /**
     * Cases for {@see self::test_a_word_processor_pdf_yields_its_whole_text()}.
     *
     * TWO FILES, TWO DIFFERENT WRITERS, ONE SYMPTOM. Both hold the same 21,383-character Hungarian
     * teaching text, both were refused with `notext` on 2026-08-06, and each failed for its own
     * reason - which is why both are kept rather than one standing in for the other.
     *
     * @return array<string, array{string, int}>
     */
    public static function word_processor_pdf_provider(): array {
        return [
            // Every page carries the filter chain /ASCII85Decode + /FlateDecode, and its one-byte
            // subset codes are written as octal escapes.
            'ReportLab' => ['reportlab-ascii85.pdf', 21000],
            // Ten of its fourteen streams declare their length as an indirect reference.
            'LibreOffice Writer' => ['libreoffice-indirect-length.pdf', 21000],
        ];
    }

    /**
     * A PDF exported from a word processor yields its text - the whole of it.
     *
     * THIS IS THE REGRESSION GUARD FOR BL-59, and it is a real file rather than a constructed one
     * on purpose: all three defects it covers were invisible to fixtures built by hand, because a
     * fixture built by hand carries the assumptions of the code it is testing. Measured after the
     * fix: 21,589 and 21,568 characters, against 21,334 and 21,541 read by pypdf out of the same
     * two files, and 21,383 in the plain-text original.
     *
     * The lower bound is deliberately loose. What has to hold is that the document comes through,
     * not that a whitespace rule produces one particular number - an exact count would fail on
     * every future improvement to the line-break rule and find nothing.
     *
     * @dataProvider word_processor_pdf_provider
     * @param string $fixture the file name under tests/fixtures/pdf
     * @param int $minimum the fewest characters the document must yield
     */
    public function test_a_word_processor_pdf_yields_its_whole_text(string $fixture, int $minimum): void {
        $this->resetAfterTest();

        $pdf = file_get_contents(__DIR__ . '/../fixtures/pdf/' . $fixture);
        $this->assertNotFalse($pdf, 'the fixture could not be read');

        $report = text_extractor::extract_with_report($this->make_file($fixture, $pdf));

        $this->assertSame(extraction_result::STATUS_OK, $report['status']);
        $this->assertGreaterThan($minimum, \core_text::strlen($report['text']));

        // Read, not merely present: an accented Hungarian sentence out of the running text.
        $this->assertStringContainsString('Bevezetés a növénytanba', $report['text']);
        $this->assertStringContainsString('fotoszintézis', $report['text']);

        // The octal escape, which produced text that looked plausible and was not: without it this
        // file came out as `Bevezet\001s a n\002v`.
        $this->assertStringNotContainsString('\\0', $report['text']);

        // What reaches the teacher's box has to be UTF-8, or Moodle's response cleaning refuses
        // the whole call and the page reports that no text could be extracted.
        $this->assertSame(1, preg_match('//u', $report['text']));
    }

    /**
     * Too many archive entries refuses the DOCX (BL-52, L-7).
     *
     * The limit is 4,096 entries. A real teaching document with screenshots was measured at well
     * under that on 2026-08-04, which is why the number is where it is - so this fixture has to be
     * deliberately absurd to reach it, and that is the point: the guard exists for the absurd case.
     */
    public function test_too_many_archive_entries_are_refused(): void {
        $this->resetAfterTest();

        $extra = [];
        for ($i = 0; $i < 4100; $i++) {
            $extra['media/img' . $i . '.bin'] = 'x';
        }

        $report = text_extractor::extract_with_report(
            $this->make_docx('<w:p><w:r><w:t>Szoveg.</w:t></w:r></w:p>', '', $extra)
        );

        $this->assertSame(extraction_result::STATUS_REJECTED, $report['status']);
        $this->assertSame(extraction_result::REASON_RESOURCE_LIMIT, $report['reason']);
    }

    /**
     * A high compression ratio refuses the DOCX, and this is the guard that actually stops a bomb.
     *
     * Recorded on 2026-08-04 and worth keeping visible: a decompression bomb is defined by being
     * enormous relative to its packed size, so the RATIO catches it. The entry count does not - an
     * ordinary document's entry count proves nothing either way, which is why that limit is
     * generous and this one is not.
     */
    public function test_a_high_compression_ratio_is_refused(): void {
        $this->resetAfterTest();

        // Highly repetitive body: deflate takes it far below 1/100th of its expanded size.
        $body = '<w:p><w:r><w:t>' . str_repeat('A', 4000000) . '</w:t></w:r></w:p>';

        $report = text_extractor::extract_with_report($this->make_docx($body));

        $this->assertSame(extraction_result::STATUS_REJECTED, $report['status']);
        $this->assertSame(extraction_result::REASON_RESOURCE_LIMIT, $report['reason']);
    }

    /**
     * An ordinary document is nowhere near any of these limits.
     *
     * The counterpart the other two need. A limit nobody can reach is not proof of anything; what
     * has to hold is that the limits stop the absurd case and let a real teaching document through
     * - which is the mistake this project already made once, when 256 entries and a 32 MiB ceiling
     * would have refused a presentation full of screenshots (2026-08-04).
     */
    public function test_an_ordinary_document_is_not_refused_by_any_limit(): void {
        $this->resetAfterTest();

        $extra = [];
        for ($i = 0; $i < 40; $i++) {
            $extra['media/image' . $i . '.png'] = str_repeat('kep-adat-', 2000);
        }

        $report = text_extractor::extract_with_report($this->make_docx(
            '<w:p><w:r><w:t>' . str_repeat('A korte rostban gazdag gyumolcs. ', 200) . '</w:t></w:r></w:p>',
            '',
            $extra
        ));

        $this->assertSame(extraction_result::STATUS_OK, $report['status']);
        $this->assertStringContainsString('rostban gazdag', $report['text']);
    }
}
