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
 * The English and Hungarian language packs must hold the same keys.
 *
 * Moodle silently falls back to English for a missing Hungarian key, so a pack that drifts never
 * Fails loudly - a missing translation looks like one nobody got around to, indefinitely. Nothing
 * Else in the suite would notice, so this is the guard: the same defect class the plugin has closed
 * Repeatedly in its PHP value sets - one set maintained in two places with nothing checking them.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class lang_parity_test extends \advanced_testcase {
    /**
     * Neither pack may hold a key the other lacks, in either direction.
     */
    public function test_both_packs_hold_the_same_keys(): void {
        $en = self::keys_of('en');
        $hu = self::keys_of('hu');

        // Guard against a vacuous pass: if the parser silently returned nothing, array_diff of two
        // Empty sets is also empty and this test would "pass" while checking nothing. This is a
        // Floor to catch a broken parse, NOT a pin on the exact count - it does not fail on a
        // Legitimate key addition.
        $this->assertGreaterThan(100, count($en), 'Parsed suspiciously few keys from lang/en - the parser is probably broken.');
        $this->assertGreaterThan(100, count($hu), 'Parsed suspiciously few keys from lang/hu - the parser is probably broken.');

        $missingfromhu = array_values(array_diff($en, $hu));
        $missingfromen = array_values(array_diff($hu, $en));

        $problems = [];
        if ($missingfromhu) {
            $problems[] = 'missing from hu: ' . implode(', ', $missingfromhu);
        }
        if ($missingfromen) {
            $problems[] = 'missing from en: ' . implode(', ', $missingfromen);
        }

        $this->assertSame(
            [],
            $problems,
            "The language packs have drifted apart:\n  " . implode("\n  ", $problems)
        );
    }

    /**
     * Deliberately separate from: each pack's keys must stay C-sorted.
     */
    public function test_each_pack_is_c_sorted(): void {
        foreach (['en', 'hu'] as $lang) {
            $keys = self::keys_of($lang, false);
            for ($i = 1, $n = count($keys); $i < $n; $i++) {
                // A byte comparison (strcmp) is LC_ALL=C order - the order the packs are kept in.
                $this->assertLessThan(
                    0,
                    strcmp($keys[$i - 1], $keys[$i]),
                    "lang/$lang is not C-sorted: '{$keys[$i]}' should come before '{$keys[$i - 1]}'."
                );
            }
        }
    }

    /**
     * Extract the $string[...] keys from a language pack by PARSING, never by including it.
     *
     * Including these files is wrong twice over (the prompt's requirement 2): they assign into
     * $string[...], so including both in one process would have the second overwrite the first, and
     * Including either pollutes the test's own scope. token_get_all() parses the PHP without
     * Executing it - and, unlike a regex, it cannot be fooled by the text "$string[" appearing
     * Inside a translated value, because a value is a single string token, not a variable followed
     * By a bracket. It is also the mechanism ai_request_test already uses for static source checks.
     *
     * @param string $lang 'en' or 'hu'
     * @param bool $unique return the unique key set (for parity) or every key in file order (sort)
     * @return string[]
     */
    private static function keys_of(string $lang, bool $unique = true): array {
        $path = __DIR__ . '/../../lang/' . $lang . '/local_artqtml.php';
        $tokens = token_get_all(file_get_contents($path));
        $keys = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$string') {
                continue;
            }
            // Expect: $string [ '<key>' ...  - skip whitespace, require the bracket, take the literal.
            $j = self::skip_whitespace($tokens, $i + 1, $count);
            if ($j >= $count || $tokens[$j] !== '[') {
                continue;
            }
            $j = self::skip_whitespace($tokens, $j + 1, $count);
            if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                // The key literal, e.g. 'movesuccess' or "privacy:metadata"; plugin keys carry no
                // Escapes, so stripping the surrounding quotes is exact.
                $keys[] = substr($tokens[$j][1], 1, -1);
            }
        }

        return $unique ? array_values(array_unique($keys)) : $keys;
    }

    /**
     * Index of the next non-whitespace token at or after $from.
     *
     * @param array $tokens
     * @param int $from
     * @param int $count
     * @return int
     */
    private static function skip_whitespace(array $tokens, int $from, int $count): int {
        while ($from < $count && is_array($tokens[$from]) && $tokens[$from][0] === T_WHITESPACE) {
            $from++;
        }
        return $from;
    }
}
