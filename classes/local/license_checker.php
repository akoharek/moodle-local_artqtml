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
 * Offline, digitally signed license verification (functional spec ch.10, Lic-001-015).
 *
 * The implementation now lives in five focused classes under local_artqtml\local\license\*
 * (crypto / file integrity / persistence / status policy / renderer); this class remains as the
 * stable public facade every existing caller (upload.php, lib.php, generate.php, license.php,
 * approve.php, the scheduled tasks, the privacy provider ...) keeps using unchanged - its public
 * static method signatures and the three public constants below are the contract. Do not add new
 * logic here: delegate to the appropriate license\* class instead.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

use local_artqtml\local\license\license_constants;
use local_artqtml\local\license\license_crypto;
use local_artqtml\local\license\license_file_integrity;
use local_artqtml\local\license\license_persistence;
use local_artqtml\local\license\license_status_policy;
use local_artqtml\local\license\license_renderer;

/**
 * Stable public facade for license verification, storage and reporting.
 */
class license_checker {
    // V20 #12: the canonical values live in license_constants (a dependency-free leaf) so the
    // implementation classes no longer depend on this facade for them. These public consts are
    // kept as thin aliases purely for backward compatibility with existing external callers
    // (e.g. lib.php's warning banner reads license_checker::BLOCKING_STATES).

    /** @var string[] valid values of the license file's "edition" field. */
    public const EDITIONS = license_constants::EDITIONS;

    /** @var string[] status()['state'] values that block starting new generations (Lic-009). */
    public const BLOCKING_STATES = license_constants::BLOCKING_STATES;

    /** @var string local_artqtml_log.event value for an encrypted integrity violation record. */
    public const INTEGRITY_VIOLATION_EVENT = license_constants::INTEGRITY_VIOLATION_EVENT;

    /**
     * Fetch the singleton license record, creating an empty ("none") one if it doesn't exist.
     *
     * @return \stdClass
     */
    public static function get_or_create_record(): \stdClass {
        return license_persistence::get_or_create_record();
    }

    /**
     * Build the canonical, deterministically-ordered JSON payload that gets signed/verified.
     *
     * @param array $fields decoded license fields
     * @return string
     */
    public static function canonical_payload(array $fields): string {
        return license_crypto::canonical_payload($fields);
    }

    /**
     * Verify a decoded license file's digital signature against the embedded public key.
     *
     * @param array $decoded json_decode()-d content of the .lic file (assoc array)
     * @return bool
     */
    public static function verify_signature(array $decoded): bool {
        return license_crypto::verify_signature($decoded);
    }

    /**
     * Compare the current license's recorded file hashes against the plugin's actual files.
     *
     * @return array{ok: bool, modified: string[], missing: string[], extra: string[]}
     */
    public static function verify_file_integrity(): array {
        return license_file_integrity::verify(license_persistence::get_or_create_record());
    }

    /**
     * Validate and store a newly uploaded .lic file (Lic-001/002/015).
     *
     * @param string $rawcontent raw content of the uploaded file
     * @return array{success: bool, error: ?string}
     */
    public static function upload(string $rawcontent): array {
        return license_persistence::upload($rawcontent);
    }

    /**
     * Compute the live status of the currently stored license.
     *
     * @return array{state: string, record: \stdClass, warning?: bool, daysremaining?: int,
     *      expiresat?: int, used?: int, limit?: int, remaining?: int, usedpct?: int,
     *      errorcode?: string}
     */
    public static function status(): array {
        return license_status_policy::status();
    }

    /**
     * Whether starting a new generation should currently be blocked (Lic-009/010).
     *
     * @return bool
     */
    public static function is_blocked(): bool {
        return license_status_policy::is_blocked();
    }

    /**
     * How many licensed questions are still available, or null when the licence does not cap them
     * (Lic-028).
     *
     * @return int|null
     */
    public static function remaining_questions(): ?int {
        return license_status_policy::remaining_questions();
    }

    /**
     * Persist the current live status into the DB record (Lic-014).
     *
     * @return void
     */
    public static function refresh_cached_status(): void {
        license_status_policy::refresh_cached_status();
    }

    /**
     * Bump the lifetime AI-validated-questions counter (Lic-011).
     *
     * @param int $count number of newly-evaluated questions to add
     * @return void
     */
    public static function increment_validated(int $count): void {
        license_status_policy::increment_validated($count);
    }

    /**
     * Render the Licensz tab's status panel (Lic-003/012).
     *
     * @return string
     */
    public static function render_status_panel(): string {
        return license_renderer::status_panel();
    }

    /**
     * Render the Licensz tab's "File integrity" section.
     *
     * @return string
     */
    public static function render_file_integrity_panel(): string {
        return license_renderer::file_integrity_panel();
    }

    /**
     * Human-readable "AIQ-YYYYMMDD-<id>" error code for the current tampered state.
     *
     * @param array{modified: string[], missing: string[]} $integrity
     * @return string
     */
    public static function integrity_error_code(array $integrity): string {
        return license_file_integrity::integrity_error_code($integrity);
    }

    /**
     * Build the downloadable encrypted integrity report (a .enc file for the vendor).
     *
     * @return array{error_code: string, content: string}|null
     */
    public static function export_integrity_report(): ?array {
        return license_file_integrity::export_integrity_report();
    }
}
