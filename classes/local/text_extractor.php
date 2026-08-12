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
 * Extracts plain text from an uploaded TXT file.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * TXT-only extractor within a hard source-file size limit.
 */
class text_extractor {
    /** @var string[] the only extensions this plugin will open. */
    public const SUPPORTED_EXTENSIONS = ['txt'];

    /**
     * @var int most bytes an uploaded file may be as it arrives (64 MiB).
     */
    protected const MAX_SOURCE_FILE_BYTES = 67108864;

    /**
     * Fetch the real (non-directory) stored_file objects for a filepicker/filemanager
     * element's draft item id.
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

        $prefix = self::read_prefix($file, 8);

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

        return self::extract_txt($file, $metrics);
    }

    /**
     * Extract plain text from a stored draft file.
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

        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-2', 'Windows-1252', 'ISO-8859-1'], true);
        if ($encoding !== false && $encoding !== 'UTF-8') {
            $content = \core_text::convert($content, $encoding, 'UTF-8');
        }

        return extraction_result::ok($content, $metrics);
    }
}
