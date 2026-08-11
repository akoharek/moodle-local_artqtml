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
 * Detects duplicate/near-duplicate source text uploads (functional spec Felt-021/022/023,
 * technical annex 5.3).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Hash-based exact match plus shingling/Jaccard near-duplicate detection.
 */
class duplicate_detector {
    /** @var int shingle size in words for the similarity check. */
    protected const SHINGLE_SIZE = 5;

    /** @var float similarity threshold that triggers the duplicate popup (Felt-022). */
    protected const THRESHOLD = 0.90;

    /** @var int how many of the most recent other generations to compare against. */
    protected const MAX_COMPARISONS = 200;

    /**
     * Normalise text for hashing/shingling: lowercase, collapse whitespace.
     *
     * @param string $text
     * @return string
     */
    public static function normalise(string $text): string {
        $text = \core_text::strtolower(trim($text));
        return preg_replace('/\s+/', ' ', $text);
    }

    /**
     * sha1 hash of the normalised text, used for exact-match detection (Felt-021).
     *
     * @param string $text
     * @return string
     */
    public static function hash(string $text): string {
        return sha1(self::normalise($text));
    }

    /**
     * A file fingerprint independent of whatever text extraction produces from the file (M-19).
     *
     * WHAT GOES IN CHANGED ON 2026-08-04. The caller used to read the whole upload back out of
     * storage and pass the raw bytes; it now passes Moodle's own content hashes, which the File
     * API has already computed. This function still just sha1s what it is given - but the value
     * stored for a given file is therefore not the value that was stored for it before that date.
     * That is harmless because nothing compares this column (see {@see self::find_match()}, which
     * decides on the text hash), and it would stop being harmless the moment something did.
     *
     * @param string $bytes the file identity to fingerprint
     * @return string
     */
    public static function hash_file_bytes(string $bytes): string {
        return sha1($bytes);
    }

    /**
     * Find an existing generation whose source text is an exact or near-duplicate match.
     *
     * C2 (Felt-021): duplicate detection is intentionally based on the SHA-1 hash of the
     * extracted, normalised source TEXT (sourcetexthash) - never on a hash of the raw uploaded
     * file's bytes (sourcefilehash). The draft file is not a reliable input at detection time:
     * a submission may be pasted text with no file at all, the same content may arrive in a
     * different file format/encoding, and the draft file can be removed before the generation is
     * (re)saved. The extracted text is the only representation consistent across all of those
     * cases, so sourcefilehash is deliberately not consulted here.
     *
     * @param string $text the newly submitted source text
     * @param int $excludeid a generation id to never match against (Beal-023/024: editing an
     *      existing "started" generation's own unchanged text must not flag itself as a dup)
     * @return \stdClass|null the matched generation record plus a 'similarity' (0-100) field,
     *      or null if no match was found
     */
    public static function find_match(string $text, int $excludeid = 0): ?\stdClass {
        global $DB;

        $normalised = self::normalise($text);
        if ($normalised === '') {
            return null;
        }

        $hash = sha1($normalised);

        $exactparams = ['hash' => $hash];
        $exactwhere = 'sourcetexthash = :hash';
        if ($excludeid > 0) {
            $exactwhere .= ' AND id <> :excludeid';
            $exactparams['excludeid'] = $excludeid;
        }
        $exact = $DB->get_record_select(
            'local_artqtml_generations',
            $exactwhere,
            $exactparams,
            '*',
            IGNORE_MULTIPLE
        );
        if ($exact) {
            $exact->similarity = 100;
            return $exact;
        }

        $shingles = self::shingles($normalised);
        if (empty($shingles)) {
            return null;
        }

        $candidatewhere = $excludeid > 0 ? 'id <> :excludeid' : '';
        $candidateparams = $excludeid > 0 ? ['excludeid' => $excludeid] : [];
        $candidates = $DB->get_records_select(
            'local_artqtml_generations',
            $candidatewhere,
            $candidateparams,
            'timecreated DESC',
            'id, userid, name, sourcetext, timecreated, status',
            0,
            self::MAX_COMPARISONS
        );

        $best = null;
        $bestscore = 0.0;
        foreach ($candidates as $candidate) {
            $candidateshingles = self::shingles(self::normalise((string) $candidate->sourcetext));
            $score = self::jaccard($shingles, $candidateshingles);
            if ($score > $bestscore) {
                $bestscore = $score;
                $best = $candidate;
            }
        }

        if ($best !== null && $bestscore >= self::THRESHOLD) {
            $best->similarity = (int) round($bestscore * 100);
            return $best;
        }

        return null;
    }

    /**
     * Build the set of word n-gram shingles for a normalised text.
     *
     * @param string $normalised
     * @return array<string,bool> set (keys only matter) of shingle strings
     */
    protected static function shingles(string $normalised): array {
        $words = preg_split('/\s+/', $normalised, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < self::SHINGLE_SIZE) {
            return $normalised === '' ? [] : [$normalised => true];
        }

        $shingles = [];
        $count = count($words) - self::SHINGLE_SIZE + 1;
        for ($i = 0; $i < $count; $i++) {
            $shingles[implode(' ', array_slice($words, $i, self::SHINGLE_SIZE))] = true;
        }

        return $shingles;
    }

    /**
     * Jaccard similarity between two shingle sets: |A n B| / |A u B|.
     *
     * @param array<string,bool> $a
     * @param array<string,bool> $b
     * @return float
     */
    protected static function jaccard(array $a, array $b): float {
        if (empty($a) || empty($b)) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($a, $b));
        $union = count($a) + count($b) - $intersection;

        return $union > 0 ? $intersection / $union : 0.0;
    }
}
