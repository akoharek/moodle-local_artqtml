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
 * Extracts plain text from an uploaded TXT/DOCX/PDF file (functional spec 5.2, Felt-009/010).
 *
 * Deliberately dependency-free: the target deployment is browser-only shared hosting with no
 * SSH/composer access (see CLAUDE.md "Deployment"), so a vendored composer library such as
 * PHPWord or Smalot/PdfParser cannot be installed after the fact. DOCX is a zip of XML, which
 * PHP's bundled ZipArchive + DOMDocument extensions handle natively. PDF text extraction here
 * is a best-effort scan of each stream object's content, inflating FlateDecode-compressed
 * streams via PHP's bundled zlib extension - a hard Moodle requirement, so no new dependency.
 *
 * WHAT CHANGED 2026-08-04, and why, because both halves matter.
 *
 * FIRST, RESOURCE LIMITS. The parser used to expand whatever it was given: `getFromName()` on a
 * DOCX entry of any declared size, `gzuncompress()` with no maximum output, every inflated PDF
 * stream held in one array until the end. A 2 MiB upload - the shipped default maximum - can
 * legitimately declare hundreds of megabytes of expansion, and nothing stood between that and the
 * server's memory. The limits below are hard-coded rather than administrable on purpose: the
 * `maxfilesize` setting may lower what can be uploaded, but no setting may switch off what
 * happens after it is.
 *
 * SECOND, AND LESS OBVIOUS: TEXT THE TEACHER CANNOT SEE. Stripping formatting is exactly what an
 * extractor is for, and it is also what makes a document a delivery mechanism. Two-point white
 * text, a hidden Word run, a footer, a PDF text-rendering mode of 3 - a person opening the file
 * sees nothing, and the model receives it as ordinary source material with the same standing as
 * the rest. Every earlier layer of prompt-injection defence assumes the source text is what the
 * teacher believes it is; this is where that assumption is actually checked.
 *
 * THE POLICY, as decided by András on 2026-08-04 and settled that afternoon: **every piece of text
 * the author wrote is extracted, and only machinery is left out.** This went through two wrong
 * shapes first, and both are worth recording because the reasoning is the feature's own.
 *
 * It began by REFUSING a file that carried hidden text, then by DROPPING the hidden runs and
 * keeping the rest. Both assumed the model would receive that text without anyone seeing it. That
 * is not how the product works: the extracted text is written into the source-text box on the
 * upload page, where the teacher reads and edits it before anything is submitted. Text that
 * reaches that box is not hidden any more, whatever the document did to conceal it - and the
 * screening that runs there applies to it exactly as to everything else.
 *
 * So the appearance-based judgements went: white or near-white colour, a font under four points,
 * `w:vanish`, `w:webHidden`, the invisible PDF rendering mode. They bought nothing, and they were
 * wrong in the expensive direction - this parser does not know what is behind the text, so white on
 * a dark slide read as invisible, and the invisible rendering mode is what an OCR layer over a scan
 * uses legitimately. Either would have silently emptied an ordinary document.
 *
 * WHAT IS STILL EXCLUDED is excluded by STRUCTURE, not by appearance, and the difference is the
 * whole point: a field instruction, deleted text, an embedded object (OLE, a control), and a PDF
 * object announcing JavaScript, a launch action or an embedded file. None of those is prose
 * somebody wrote; all of them would read as ordinary text once the markup is gone.
 *
 * HEADERS AND FOOTERS STAY OUT TOO, confirmed the same day. That one is not about danger: a footer
 * repeats on every page, and 21 copies of it is not what anyone means by the text of a document.
 *
 * THIRD, 2026-08-04 AFTERNOON (BL-48): PDF TEXT THAT IS NOT STORED AS TEXT. With an embedded CID
 * font - Microsoft Office's ordinary export, not an edge case - a page holds two-byte glyph
 * identifiers rather than characters, and they mean nothing without the font's own /ToUnicode
 * table. Measured on a real 21-page file: 64 characters recovered out of about 17,500. Reaching
 * that table needs the object graph resolved, which is what `pdf\object_index` and
 * `pdf\tounicode_cmap` do. When they cannot, the original whole-file scan runs unchanged - no file
 * that worked before may stop working because of this.
 *
 * FOURTH, 2026-08-06 (BL-59): THE PAGE ROUTE FOUND THE TEXT AND THREW IT AWAY. Two PDFs of the
 * same Hungarian teaching text, one from ReportLab and one from LibreOffice Writer, were both
 * refused with `notext` - 0 characters out of 21,383 - while a reference parser read the full text
 * out of each. The structure was read correctly and the /ToUnicode tables were built; the loss was
 * three steps further down, in three separate places, each of which is now covered by a test:
 *
 *  - `/Filter` IS A LIST APPLIED IN ORDER, and asking `strpos($dict, 'FlateDecode')` is not the
 *    same question. ReportLab writes `[ /ASCII85Decode /FlateDecode ]` on every page;
 *  - `/Length` MAY BE AN INDIRECT REFERENCE. LibreOffice writes `/Length 3 0 R`, which was read as
 *    the number three, so three bytes of a 3,441-byte stream reached zlib;
 *  - `\ddd` OCTAL ESCAPES were not unescaped, and a subset font's codes start at 1, so a page was
 *    almost entirely escapes. That one produced MORE characters, not fewer - rubbish that no
 *    counter complained about.
 *
 * The order matters for the lesson rather than the code: the first two produce nothing and are
 * loud, the third produces plausible-looking text and is silent. Only a real file found any of
 * them - fixtures written by hand carry the same assumptions as the code they test.
 *
 * WHAT THIS STILL CANNOT DO, stated so no reader takes more from it than it gives: text burned
 * into an image is not read, and a font with no /ToUnicode table cannot be decoded. Of the text
 * matrix it reads ONLY the vertical translation, and only to decide where a line ends. When a file
 * yields very little text for its size, the upload page says so - that warning is what turns a
 * silent failure into something the teacher can act on.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Dispatches to a TXT/DOCX/PDF specific extractor, within hard resource limits.
 *
 * WHERE THE LIMITS ARE SET, and why they are not tight. Every ceiling here exists to stop a
 * decompression bomb - a small file that expands until the server runs out of memory. None of them
 * exists to judge whether a document is a reasonable one to teach from, and on 2026-08-04 three of
 * them were doing the second job by accident: 256 archive entries refuses a screenshot-heavy DOCX,
 * and a 32 MiB inflation ceiling refuses a presentation only half again as large as one we had
 * already measured at 23.47 MiB.
 *
 * What actually catches a bomb is the RATIO ({@see self::MAX_COMPRESSION_RATIO}) and the per-item
 * ceilings, because a bomb is by definition enormous relative to its packed size. A count of
 * ordinary entries is not evidence of anything. So the counts are set where a real document cannot
 * plausibly reach them, and the ratio does the defending.
 */
class text_extractor {
    /** @var string[] the only extensions this plugin will open. */
    public const SUPPORTED_EXTENSIONS = ['txt', 'docx', 'pdf'];

    /**
     * @var int most entries a DOCX archive may declare before it is refused.
     *
     * Every image, font and relationship part is its own entry, so a 200-screenshot guide runs to
     * several hundred. The bomb this guards against declares tens of thousands.
     */
    protected const MAX_ARCHIVE_ENTRIES = 4096;

    /** @var int most bytes word/document.xml may expand to (16 MiB). */
    protected const MAX_DOCX_XML_BYTES = 16777216;

    /**
     * @var int most stream objects a PDF may contain.
     *
     * Counted on the fallback route only, where every image and font is a stream: a 40-page
     * illustrated document passes a thousand without being unusual.
     */
    protected const MAX_PDF_STREAMS = 8192;

    /** @var int most bytes one PDF stream may expand to (16 MiB). */
    protected const MAX_PDF_STREAM_BYTES = 16777216;

    /**
     * @var int most /Contents references read from one page.
     *
     * A page's /Contents is normally one stream; Office writes exactly one. The cap exists because
     * the list is attacker-controlled and may name the same object over and over - see
     * {@see self::page_content_streams()} for why this is a cap and not a deduplication.
     */
    protected const MAX_PAGE_CONTENT_STREAMS = 64;

    /**
     * @var int most bytes an uploaded file may be as it arrives (64 MiB).
     *
     * Separate from the inflation ceiling below, which it used to share. The two measure different
     * things - what the file weighs, and how much work reading it costs - and sharing one number
     * meant that raising the work ceiling would silently have raised the upload ceiling with it.
     * A plain-text file, which does not inflate at all, was being measured against an inflation
     * limit purely because that constant was the one in scope.
     */
    protected const MAX_SOURCE_FILE_BYTES = 67108864;

    /**
     * @var int most bytes one file may expand to in total (192 MiB).
     *
     * A WORK CEILING, NOT A MEMORY CEILING. Streams are inflated one at a time and dropped, so the
     * peak memory is one stream ({@see self::MAX_PDF_STREAM_BYTES}), not this total. The measured
     * 21-page file expanded 23.47 MiB, almost all of it images, and it is not a large document.
     */
    protected const MAX_TOTAL_INFLATED_BYTES = 201326592;

    /** @var int expanded:compressed ratio above which an entry is treated as a decompression bomb. */
    protected const MAX_COMPRESSION_RATIO = 100;

    /**
     * Fetch the real (non-directory) stored_file objects for a filepicker/filemanager
     * element's draft item id.
     *
     * moodleform has no get_draft_files() method - a filepicker element's submitted value
     * is just the draft area's itemid, so the actual files must be looked up via the File API
     * against the current user's draft file area.
     *
     * @param int $draftitemid the draft area item id (the raw value submitted by the element)
     * @return \stored_file[]
     */
    public static function draft_files(int $draftitemid): array {
        global $USER;

        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);

        return $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);
    }

    /**
     * Whether this file is one the plugin will open at all.
     *
     * The filepicker's `accepted_types` is a convenience for the browser and nothing more - it is
     * client-side, and a direct POST never sees it. This is the server's answer, and it checks the
     * file's actual first bytes rather than trusting either the extension or the browser-supplied
     * MIME type, which the client controls.
     *
     * @param \stored_file $file
     * @return bool
     */
    public static function is_supported_file(\stored_file $file): bool {
        $extension = self::extension($file);
        if (!in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            return false;
        }

        // Read a short prefix rather than the file: enough to check a signature, not enough to
        // matter for memory.
        $prefix = self::read_prefix($file, 8);

        if ($extension === 'pdf') {
            return strncmp($prefix, '%PDF-', 5) === 0;
        }

        if ($extension === 'docx') {
            // Every DOCX is a ZIP; "PK\x03\x04" is the local file header.
            return strncmp($prefix, "PK\x03\x04", 4) === 0;
        }

        // TXT: refuse anything that announces itself as an executable or an archive. A plain text
        // file has no signature of its own, so this is a deny-list of the shapes that clearly are
        // not text, plus the NUL-density check inside the TXT reader.
        foreach (["\x7fELF", "MZ", "PK\x03\x04", "%PDF-", "\x1f\x8b"] as $signature) {
            if (strncmp($prefix, $signature, strlen($signature)) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract text from a stored draft file, reporting what happened.
     *
     * @param \stored_file $file the uploaded file
     * @return array{status: string, text: string, reason: string, metrics: array} see extraction_result
     */
    public static function extract_with_report(\stored_file $file): array {
        $metrics = ['sourcebytes' => (int) $file->get_filesize()];

        if (!self::is_supported_file($file)) {
            return extraction_result::rejected(extraction_result::REASON_UNSUPPORTED_TYPE, $metrics);
        }

        switch (self::extension($file)) {
            case 'txt':
                return self::extract_txt($file, $metrics);
            case 'docx':
                return self::extract_docx($file, $metrics);
            case 'pdf':
                return self::extract_pdf($file, $metrics);
            default:
                return extraction_result::rejected(extraction_result::REASON_UNSUPPORTED_TYPE, $metrics);
        }
    }

    /**
     * Extract plain text from a stored draft file.
     *
     * Kept so that callers which genuinely only want the text keep working. It returns an empty
     * string for every non-success outcome, which is precisely the ambiguity
     * {@see self::extract_with_report()} exists to remove - so the upload page and the AJAX
     * endpoint use that one, and this remains for convenience rather than for decisions.
     *
     * @param \stored_file $file the uploaded file
     * @return string extracted text, empty string if extraction failed/produced nothing/was refused
     */
    public static function extract(\stored_file $file): string {
        $result = self::extract_with_report($file);

        return $result['status'] === extraction_result::STATUS_OK ? $result['text'] : '';
    }

    /**
     * The file's normalised extension.
     *
     * @param \stored_file $file
     * @return string
     */
    protected static function extension(\stored_file $file): string {
        return \core_text::strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
    }

    /**
     * Read the first few bytes of a file without loading it.
     *
     * @param \stored_file $file
     * @param int $length
     * @return string empty if the file could not be opened
     */
    protected static function read_prefix(\stored_file $file, int $length): string {
        $handle = $file->get_content_file_handle();
        if (!is_resource($handle)) {
            return '';
        }

        $prefix = (string) fread($handle, $length);
        fclose($handle);

        return $prefix;
    }

    /**
     * Extract text from a plain-text file, converting to UTF-8 if needed.
     *
     * @param \stored_file $file
     * @param array $metrics
     * @return array the extraction result
     */
    protected static function extract_txt(\stored_file $file, array $metrics): array {
        if ((int) $file->get_filesize() > self::MAX_SOURCE_FILE_BYTES) {
            return extraction_result::rejected(extraction_result::REASON_RESOURCE_LIMIT, $metrics);
        }

        $content = $file->get_content();
        $metrics['expandedbytes'] = strlen($content);

        // A binary file renamed to .txt. Checked on a sample rather than the whole content: a
        // genuine text file has no NUL bytes at all, so a handful in the first kilobytes is
        // already conclusive.
        $sample = substr($content, 0, 4096);
        if ($sample !== '' && substr_count($sample, "\0") > 0) {
            return extraction_result::rejected(extraction_result::REASON_INVALID_STRUCTURE, $metrics);
        }

        // Moodle's core_text has no encoding-detection helper (detect_encoding() does not exist), so use
        // PHP's native mb_detect_encoding() - as Moodle core itself does. Strict mode reliably
        // validates the multi-byte structure of real UTF-8; anything that fails that check is
        // treated as a single-byte legacy encoding, with ISO-8859-2 (Latin-2) as the Hungarian
        // default fallback ahead of the Western Windows-1252/ISO-8859-1. Only encoding names
        // mbstring actually recognises may be listed here (e.g. Windows-1250 is not one of them),
        // but core_text::convert() below still handles the full set core supports. A false result
        // (undecidable) leaves the content untouched, i.e. assumed already UTF-8.
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-2', 'Windows-1252', 'ISO-8859-1'], true);
        if ($encoding !== false && $encoding !== 'UTF-8') {
            $content = \core_text::convert($content, $encoding, 'UTF-8');
        }

        return extraction_result::ok($content, $metrics);
    }

    /**
     * Extract the visible body text of a DOCX file.
     *
     * Two things happen here that did not before, and both are security decisions rather than
     * parsing improvements:
     *
     * ONLY `/w:document/w:body` IS READ. Headers, footers, comments, footnotes, endnotes,
     * document properties and custom XML are not, and are not to be added later without revisiting
     * this comment. They are conventional places to put decoration, which makes them conventional
     * places to hide an instruction, and none of them is what a teacher means by "the text of my
     * document". If the content of a footer is genuinely wanted, it can be pasted in.
     *
     * FORMATTING IS NOT READ AT ALL. Whatever a run looks like, its text is kept - see the class
     * docblock for why that judgement was removed. What is skipped is skipped by structure: field
     * instructions, deleted text and embedded objects.
     *
     * @param \stored_file $file
     * @param array $metrics
     * @return array the extraction result
     */
    protected static function extract_docx(\stored_file $file, array $metrics): array {
        // Before copy_content_to_temp(), not after: the copy is the first thing that spends
        // resources on an oversized file, and it spends them on disk where nothing else guards.
        // The txt and pdf routes have always checked here; this one did not, which is the whole
        // of BL-50's first half.
        if ((int) $file->get_filesize() > self::MAX_SOURCE_FILE_BYTES) {
            return extraction_result::rejected(extraction_result::REASON_RESOURCE_LIMIT, $metrics);
        }

        $tmpfile = $file->copy_content_to_temp('local_artqtml', 'docx_');
        if ($tmpfile === false) {
            return extraction_result::rejected(extraction_result::REASON_INVALID_STRUCTURE, $metrics);
        }

        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmpfile) !== true) {
                return extraction_result::rejected(extraction_result::REASON_INVALID_STRUCTURE, $metrics);
            }

            try {
                $metrics['archiveentries'] = $zip->numFiles;
                if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                    return extraction_result::rejected(extraction_result::REASON_RESOURCE_LIMIT, $metrics);
                }

                // The whole point of the preflight: ask the archive how big the entry claims to be
                // BEFORE asking for it. getFromName() on an entry declaring 4 GiB allocates 4 GiB.
                $stat = $zip->statName('word/document.xml');
                if ($stat === false) {
                    return extraction_result::rejected(extraction_result::REASON_INVALID_STRUCTURE, $metrics);
                }

                $declared = (int) $stat['size'];
                $compressed = (int) $stat['comp_size'];
                $metrics['expandedbytes'] = $declared;

                if ($declared > self::MAX_DOCX_XML_BYTES) {
                    return extraction_result::rejected(extraction_result::REASON_RESOURCE_LIMIT, $metrics);
                }
                if ($compressed > 0 && ($declared / $compressed) > self::MAX_COMPRESSION_RATIO) {
                    return extraction_result::rejected(extraction_result::REASON_RESOURCE_LIMIT, $metrics);
                }

                $xml = $zip->getFromName('word/document.xml');
                if ($xml === false) {
                    return extraction_result::rejected(extraction_result::REASON_INVALID_STRUCTURE, $metrics);
                }

                // The declared size is the archive's claim; this is what actually arrived.
                if (strlen($xml) > self::MAX_DOCX_XML_BYTES) {
                    return extraction_result::rejected(extraction_result::REASON_RESOURCE_LIMIT, $metrics);
                }
                $metrics['expandedbytes'] = strlen($xml);
            } finally {
                $zip->close();
            }

            return self::docx_body_text($xml, $metrics);
        } finally {
            if (file_exists($tmpfile)) {
                unlink($tmpfile);
            }
        }
    }

    /**
     * Read word/document.xml and return its visible body text, or a rejection.
     *
     * @param string $xml the document part
     * @param array $metrics
     * @return array the extraction result
     */
    protected static function docx_body_text(string $xml, array $metrics): array {
        // An XML declaration of intent to load something else. There is no legitimate DOCTYPE in
        // a WordprocessingML part, and every entity-expansion attack starts with one.
        if (stripos($xml, '<!DOCTYPE') !== false) {
            return extraction_result::rejected(extraction_result::REASON_INVALID_STRUCTURE, $metrics);
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            // LIBXML_NONET: no external entity may be fetched over the network. LIBXML_NOENT is
            // deliberately NOT passed - it would expand entities rather than leave them alone.
            if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                return extraction_result::rejected(extraction_result::REASON_INVALID_STRUCTURE, $metrics);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $body = $xpath->query('/w:document/w:body')->item(0);
        if ($body === null) {
            return extraction_result::rejected(extraction_result::REASON_INVALID_STRUCTURE, $metrics);
        }

        $paragraphs = [];

        foreach ($xpath->query('.//w:p', $body) as $paragraph) {
            $pieces = [];
            foreach ($xpath->query('.//w:r', $paragraph) as $run) {
                // Deleted text and field instructions are not document content: the first is what
                // the author removed, the second is machinery (a MERGEFIELD, an INCLUDETEXT). Both
                // are invisible in Word and both would read as ordinary prose once stripped.
                if ($xpath->query('ancestor::w:del', $run)->length > 0) {
                    continue;
                }
                if ($xpath->query('w:instrText', $run)->length > 0) {
                    continue;
                }
                // 2026-08-04: an embedded object - an OLE package, an ActiveX control, an
                // attached file - is not the author's prose, and the "text" inside one is
                // whatever the embedding put there. Skipped for the same reason as a field
                // instruction: it reads as ordinary material once the markup is stripped, and
                // it is not something the teacher wrote.
                if ($xpath->query('.//w:object | .//w:control | .//w:embedObj', $run)->length > 0) {
                    continue;
                }

                $text = '';
                foreach ($xpath->query('w:t', $run) as $node) {
                    $text .= $node->textContent;
                }
                if (trim($text) !== '') {
                    $pieces[] = $text;
                }
            }

            $line = trim(implode('', $pieces));
            if ($line !== '') {
                $paragraphs[] = $line;
            }
        }

        // EVERY RUN THE AUTHOR WROTE IS KEPT, whatever formatting it carries. Vanished, two-point
        // and white runs were dropped here until 2026-08-04, and that judgement is gone with the
        // PDF one: the extracted text lands in the source-text box, where the teacher reads it, so
        // there is nothing left for the parser to protect them from by guessing at appearance -
        // and its guesses were wrong in the expensive direction, silently emptying documents.
        //
        // What is still excluded is excluded by STRUCTURE, above: a field instruction, deleted
        // text, an embedded object. None of those is prose somebody wrote.
        return extraction_result::ok(implode("\n", $paragraphs), $metrics);
    }

    /**
     * Best-effort extraction of readable text from a PDF's content streams.
     *
     * Not a full PDF parser: it does not resolve object cross-references or walk `/Contents`, it
     * finds every `<< ... >> stream ... endstream` block in the raw file and undoes each one's
     * declared `/Filter` chain ({@see \local_artqtml\local\pdf\stream_filter}). Non-text streams
     * (images, fonts) simply contribute no show-text operators. Scanned/image-only PDFs, encrypted
     * content streams or a filter this code cannot undo still yield little or no text, which the
     * calling page treats the same as any other empty extraction.
     *
     * Three things changed on 2026-08-04:
     *
     *  - every inflate call carries a maximum output length, and the running total is capped;
     *  - streams are processed one at a time and discarded, instead of being collected into an
     *    array that was only consumed at the end;
     *  - each stream's text operators are read through a small graphics-state machine
     *    ({@see \local_artqtml\local\pdf\content_stream_reader}), which tracks the active font so
     *    that glyph identifiers can be decoded, and reads vertical movement so that lines break
     *    where the document breaks them.
     *
     * @param \stored_file $file
     * @param array $metrics
     * @return array the extraction result
     */
    protected static function extract_pdf(\stored_file $file, array $metrics): array {
        if ((int) $file->get_filesize() > self::MAX_SOURCE_FILE_BYTES) {
            return extraction_result::rejected(extraction_result::REASON_RESOURCE_LIMIT, $metrics);
        }

        $content = $file->get_content();

        // 2026-08-04, BL-48: the page-driven route. A PDF does not have to store text as text -
        // with an embedded CID font, which is what Office writes on export, a page holds two-byte
        // glyph identifiers that mean nothing without the font's own /ToUnicode table. Measured on
        // a real 21-page file: 64 characters recovered out of about 17,500, all of them from the
        // one font that happened to use a conventional encoding.
        //
        // Reaching that table means resolving object references, which is what pdf\object_index
        // does. If it cannot - a structure it does not understand, a file with no page objects -
        // the ORIGINAL whole-file scan below runs unchanged. That fallback is the point: no file
        // that works today may stop working because of this.
        //
        // AN EMPTY RESULT NO LONGER FALLS BACK, decided by András on 2026-08-05: a document that
        // cannot be read is refused, not partially processed. Between 2026-08-04 evening and that
        // decision it did fall back, on the reasoning that a file whose fonts carry no /ToUnicode
        // table decodes to nothing here while the old scan would still recover whatever the file
        // stores conventionally - partial text, but text. What that produced in practice was a
        // teacher holding a fragment with nothing on screen to say so, which is the failure BL-48
        // was opened for. The fragment is now refused with a message instead.
        //
        // ONLY AN UNREADABLE STRUCTURE STILL FALLS BACK - no page objects, or none the index can
        // resolve. That is not a damaged file: it is what a simple or older PDF looks like, and
        // the old whole-file scan reads it correctly.
        $paged = self::extract_pdf_by_page($content, $metrics);
        if ($paged !== null) {
            // Felt-036: the page path has its own object index; drop the raw file bytes before
            // returning so peak RSS is not "file + full index + result" for longer than needed.
            unset($content);
            if ($paged['status'] === extraction_result::STATUS_EMPTY) {
                return extraction_result::rejected(extraction_result::REASON_NO_TEXT, $paged['metrics']);
            }

            return $paged;
        }

        $foundstreams = preg_match_all(
            '/(<<(?:[^<>]|<<[^<>]*>>)*>>)\s*stream\r?\n/',
            $content,
            $dictmatches,
            PREG_OFFSET_CAPTURE
        );

        // A pattern that gave up rather than finished. Treating that as "no streams" would send
        // the whole raw file to the fallback scan below, which is the expensive path - so it is
        // reported as what it is.
        if ($foundstreams === false || preg_last_error() !== PREG_NO_ERROR) {
            return extraction_result::rejected(extraction_result::REASON_RESOURCE_LIMIT, $metrics);
        }

        $text = [];
        $inflatedtotal = 0;
        $streamcount = 0;

        if ($foundstreams) {
            foreach ($dictmatches[0] as $index => $match) {
                $streamcount++;
                if ($streamcount > self::MAX_PDF_STREAMS) {
                    $metrics['streamcount'] = $streamcount;
                    $metrics['expandedbytes'] = $inflatedtotal;
                    return extraction_result::rejected(extraction_result::REASON_RESOURCE_LIMIT, $metrics);
                }

                $dict = $dictmatches[1][$index][0];
                $streamstart = $match[1] + strlen($match[0]);
                $endpos = strpos($content, 'endstream', $streamstart);
                if ($endpos === false) {
                    continue;
                }
                // The PDF spec allows a single trailing EOL before the "endstream" keyword,
                // which isn't part of the stream's actual data.
                $streamdata = rtrim(substr($content, $streamstart, $endpos - $streamstart), "\r\n");

                if (strlen($streamdata) > self::MAX_PDF_STREAM_BYTES) {
                    $metrics['streamcount'] = $streamcount;
                    return extraction_result::rejected(extraction_result::REASON_RESOURCE_LIMIT, $metrics);
                }

                // 2026-08-04: an object that carries executable content rather than page text is
                // skipped outright. The page-driven route never sees these - it reads only
                // /Contents - but THIS fallback scans every stream in the file, so a document's
                // JavaScript action, launch action or embedded file could otherwise have its
                // contents read as if it were teaching material. None of it is text the teacher
                // wrote, and none of it belongs in a prompt.
                if (self::dictionary_carries_code($dict)) {
                    continue;
                }

                // The whole declared /Filter chain, in order - not just "does the word FlateDecode
                // appear somewhere" (2026-08-06, BL-59). A stream with no filter comes back
                // unchanged; one this code cannot undo comes back null and is skipped, rather than
                // having its raw bytes read as if they were text.
                $inflated = \local_artqtml\local\pdf\stream_filter::decode(
                    $dict,
                    $streamdata,
                    self::MAX_PDF_STREAM_BYTES
                );
                if ($inflated === null) {
                    continue;
                }

                $inflatedtotal += strlen($inflated);
                if ($inflatedtotal > self::MAX_TOTAL_INFLATED_BYTES) {
                    $metrics['streamcount'] = $streamcount;
                    $metrics['expandedbytes'] = $inflatedtotal;
                    return extraction_result::rejected(extraction_result::REASON_RESOURCE_LIMIT, $metrics);
                }

                // Consumed here and dropped - the previous version held every inflated stream in
                // one array until the whole file had been read.
                self::extract_text_operators($inflated, $text);
                unset($inflated);
            }
        } else {
            // No recognizable stream objects at all (malformed/non-standard PDF structure) -
            // fall back to scanning the whole raw file, same as this extractor always did.
            self::extract_text_operators($content, $text);
        }

        $metrics['streamcount'] = $streamcount;
        $metrics['expandedbytes'] = $inflatedtotal;

        return extraction_result::ok(self::join_pieces($text), $metrics);
    }

    /**
     * The page-driven route: resolve each page's fonts and read only its own content streams.
     *
     * Returns null when the file's structure cannot be read, which is the signal for the caller to
     * fall back to the original whole-file scan.
     *
     * Only the pages' /Contents streams are inflated. On the measured file the old approach
     * expanded 23.47 MiB - every image and font in the document - to reach 0.16 MiB of text, and
     * would have hit the 32 MiB total-inflation ceiling on a slightly more image-heavy
     * presentation whose actual text is a fifth of a megabyte.
     *
     * @param string $content the whole PDF
     * @param array $metrics
     * @return array|null the extraction result, or null to fall back
     */
    protected static function extract_pdf_by_page(string $content, array $metrics): ?array {
        try {
            $index = \local_artqtml\local\pdf\object_index::build($content, $metrics);
        } catch (\local_artqtml\local\pdf\resource_limit_exception $e) {
            // A limit is a decision, not a failure to read the file. Returning null here would send
            // the document to the whole-file scan, which is the more expensive route - the one the
            // limit exists to prevent (2026-08-05, BL-49).
            return extraction_result::rejected(extraction_result::REASON_RESOURCE_LIMIT, $metrics);
        }

        if ($index === null || $index->pages() === []) {
            return null;
        }

        $pages = [];
        $inflatedtotal = 0;
        $fontcount = 0;
        $mapped = 0;
        $unmapped = 0;

        foreach ($index->pages() as $pagenumber) {
            $page = $index->get($pagenumber);
            if ($page === null) {
                continue;
            }

            $fonts = self::page_fonts($index, $page, $fontcount);

            $pieces = [];
            // The generator below yields one inflated stream at a time, so the stream in hand is
            // the only one alive. The running total is therefore checked with one stream in
            // memory, not after a page's whole set has been built - which is what BL-49 was about.
            foreach (self::page_content_streams($index, $page) as $stream) {
                $inflatedtotal += strlen($stream);
                if ($inflatedtotal > self::MAX_TOTAL_INFLATED_BYTES) {
                    $metrics['expandedbytes'] = $inflatedtotal;
                    return extraction_result::rejected(extraction_result::REASON_RESOURCE_LIMIT, $metrics);
                }

                // Consumed here and dropped, the same shape as the whole-file route below.
                self::extract_text_operators($stream, $pieces, $fonts, $mapped, $unmapped);
                unset($stream);
            }

            $pagetext = self::join_pieces($pieces);
            if ($pagetext !== '') {
                $pages[] = $pagetext;
            }
        }

        $metrics['expandedbytes'] = $inflatedtotal;
        $metrics['fontcount'] = $fontcount;
        $metrics['mappedglyphs'] = $mapped;
        $metrics['unmappedglyphs'] = $unmapped;

        // Left out, not refused - see docx_body_text() for the reasoning, which is the same on
        // both formats.
        return extraction_result::ok(implode("\n\n", $pages), $metrics);
    }

    /**
     * Resolve one page's /Resources /Font dictionary into resource name to glyph table.
     *
     * @param \local_artqtml\local\pdf\object_index $index
     * @param string $page the page object body
     * @param int $fontcount incremented for each font that yielded a table
     * @return array<string, array{bytes: int, map: array}>
     */
    protected static function page_fonts(
        \local_artqtml\local\pdf\object_index $index,
        string $page,
        int &$fontcount
    ): array {
        $resources = $index->dictionary_value($page, 'Resources');
        if ($resources === null) {
            return [];
        }

        $fontdict = $index->dictionary_value($resources, 'Font');
        if ($fontdict === null) {
            return [];
        }

        $fonts = [];
        if (!preg_match_all('/\/([^\s\/\[\]<>]+)\s+(\d+)\s+\d+\s+R/', $fontdict, $entries, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($entries as $entry) {
            $fontobject = $index->get((int) $entry[2]);
            if ($fontobject === null || !preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $fontobject, $ref)) {
                continue;
            }

            $cmapobject = $index->get((int) $ref[1]);
            if ($cmapobject === null) {
                continue;
            }

            $cmaptext = \local_artqtml\local\pdf\object_index::stream_data($cmapobject, $index);
            if ($cmaptext === null) {
                continue;
            }

            $cmaptext = \local_artqtml\local\pdf\stream_filter::decode(
                $cmapobject,
                $cmaptext,
                self::MAX_PDF_STREAM_BYTES
            );
            if ($cmaptext === null) {
                continue;
            }

            $map = \local_artqtml\local\pdf\tounicode_cmap::parse($cmaptext);
            if ($map === []) {
                unset($cmaptext);
                continue;
            }

            $fonts[$entry[1]] = [
                'bytes' => \local_artqtml\local\pdf\tounicode_cmap::code_bytes($cmaptext),
                'map'   => $map,
            ];
            unset($cmaptext);
            $fontcount++;
        }

        return $fonts;
    }

    /**
     * One page's decompressed content streams, one at a time.
     *
     * /Contents may be a single reference or an array of them. The measured file uses one per page
     * throughout, so the array form is handled but is NOT measured.
     *
     * THIS YIELDS RATHER THAN RETURNING AN ARRAY, and the difference is the whole of BL-49. The
     * array version inflated every one of a page's streams and held them all until the caller had
     * finished, so the peak was the page's total rather than its largest stream - and since the
     * same object may be referenced any number of times, and each reference was inflated again,
     * there was no ceiling at all. Twenty references to one object expanding to 15 MiB stood at
     * about 300 MiB. Yielded, each stream is read, consumed by the caller and dropped before the
     * next one is inflated, so the peak is one stream: at most MAX_PDF_STREAM_BYTES.
     *
     * THE REFERENCE COUNT IS CAPPED RATHER THAN DEDUPLICATED, which was a choice between the two
     * things the security report offered. A page's /Contents array is a concatenation: the same
     * object listed twice legitimately contributes its text twice, so deduplicating would silently
     * change what a valid document extracts to. The cap changes nothing for a real file - Office
     * writes one stream per page - and the repeated-inflation cost is bounded by it and by the
     * running MAX_TOTAL_INFLATED_BYTES total the caller keeps. The number is a judgement, not a
     * measurement: no file with more than a handful was available to measure.
     *
     * @param \local_artqtml\local\pdf\object_index $index
     * @param string $page the page object body
     * @return \Generator<int, string> each inflated stream in turn
     */
    protected static function page_content_streams(
        \local_artqtml\local\pdf\object_index $index,
        string $page
    ): \Generator {
        $numbers = [];

        if (preg_match('/\/Contents\s*\[(.*?)\]/s', $page, $match)) {
            if (preg_match_all('/(\d+)\s+\d+\s+R/', $match[1], $refs)) {
                $numbers = array_map('intval', $refs[1]);
            }
        } else if (preg_match('/\/Contents\s+(\d+)\s+\d+\s+R/', $page, $match)) {
            $numbers = [(int) $match[1]];
        }

        $numbers = array_slice($numbers, 0, self::MAX_PAGE_CONTENT_STREAMS);

        foreach ($numbers as $number) {
            $object = $index->get($number);
            if ($object === null) {
                continue;
            }

            $data = \local_artqtml\local\pdf\object_index::stream_data($object, $index);
            if ($data === null) {
                continue;
            }

            $data = \local_artqtml\local\pdf\stream_filter::decode($object, $data, self::MAX_PDF_STREAM_BYTES);
            if ($data === null) {
                continue;
            }

            yield $data;
            unset($data);
        }
    }

    /**
     * Join the collected pieces of one page or stream.
     *
     * NO SEPARATOR BETWEEN PIECES. This is what produced `K ar ak t er ek - - M ent or` from the
     * measured file: the old code put a space between every operand, which is survivable for
     * literal strings and destroys glyph-by-glyph decoded text completely. Line breaks come only
     * from the vertical-position rule in {@see self::extract_text_operators()}, and they are
     * already in the array when this runs.
     *
     * @param string[] $pieces
     * @return string
     */
    protected static function join_pieces(array $pieces): string {
        $joined = implode('', $pieces);
        $collapsed = preg_replace("/\n{3,}/", "\n\n", $joined);
        $text = trim(is_string($collapsed) ? $collapsed : $joined);

        // A LAST GUARANTEE THAT WHAT LEAVES HERE IS UTF-8, and it is a net rather than the fix.
        // The fix is in content_stream_reader, which decodes each string as it reads it. This line
        // exists because of what happened when nothing did: a single 0xA5 byte out of a Hungarian
        // PDF travelled through the extractor, through the upload page and into the web service
        // response, where Moodle's own cleaning rejected the whole call - and the teacher was told
        // the file contained no text. A malformed byte should cost that byte, not the document.
        return preg_match('//u', $text) === 1 ? $text : (string) @iconv('UTF-8', 'UTF-8//IGNORE', $text);
    }

    /**
     * Whether a PDF object dictionary announces executable or embedded content.
     *
     * These are not page text and never should be read as it: a JavaScript action, an automatic
     * action fired on opening, a launch action, or an embedded file. The names are matched on the
     * dictionary rather than on the stream, so the stream is never expanded at all.
     *
     * This is a filter over what the extractor READS. It is not a claim that the PDF is safe to
     * open in a reader - the plugin does not open PDFs, it reads text out of them, and what a
     * viewer would do with these objects is outside its reach entirely.
     *
     * @param string $dict the object's dictionary text
     * @return bool
     */
    protected static function dictionary_carries_code(string $dict): bool {
        foreach (['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/OpenAction', '/AA', '/RichMedia'] as $marker) {
            if (strpos($dict, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pull the text out of one (already-decompressed) content stream.
     *
     * A thin delegate since 2026-08-04. The state machine that used to live here grew to 147 lines
     * and 38 branches while it learned glyph decoding, the visibility rules and the line-break
     * rule; it is now {@see \local_artqtml\local\pdf\content_stream_reader}, one short method
     * per thing the graphics state is for. The split itself changed no behaviour; the visibility
     * rules were removed afterwards, in a separate step, and that is what changed behaviour.
     *
     * This wrapper stays because both PDF routes call it, and a name that says what it does at the
     * call site is worth more than one fewer indirection.
     *
     * @param string $streamtext one decompressed content stream
     * @param string[] $text accumulator for visible text, appended to by reference
     * @param array $fonts resource name to ['bytes' => int, 'map' => array], from /ToUnicode
     * @param int $mapped incremented for each glyph code the table resolved
     * @param int $unmapped incremented for each glyph code it did not
     * @return void
     */
    protected static function extract_text_operators(
        string $streamtext,
        array &$text,
        array $fonts = [],
        int &$mapped = 0,
        int &$unmapped = 0
    ): void {
        \local_artqtml\local\pdf\content_stream_reader::read(
            $streamtext,
            $text,
            $fonts,
            $mapped,
            $unmapped
        );
    }
}
