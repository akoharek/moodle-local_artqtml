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
 * Unit tests for the source-text size limit.
 *
 * The limit did not exist before 2026-08-04: the size of a source text was shown to the user by a
 * JavaScript counter and never compared to anything on the server. These tests pin the arithmetic,
 * because four call sites now depend on it giving the same answer every time.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\source_text_limit
 */
final class source_text_limit_test extends \advanced_testcase {
    /**
     * An explicit administrator setting wins.
     */
    public function test_an_explicit_limit_is_used(): void {
        $this->resetAfterTest();

        set_config('maxsourcetokens', 1234, 'local_artqtml');
        set_config('generatorcontextwindow', 8192, 'local_artqtml');

        $this->assertSame(1234, source_text_limit::token_limit());
    }

    /**
     * Zero means "work it out from the context window" - 80% of it.
     */
    public function test_zero_means_eighty_percent_of_the_context_window(): void {
        $this->resetAfterTest();

        set_config('maxsourcetokens', 0, 'local_artqtml');
        set_config('generatorcontextwindow', 8192, 'local_artqtml');

        $this->assertSame(6553, source_text_limit::token_limit());
    }

    /**
     * With no context window configured either, the documented fallback applies.
     *
     * Every call site asks this class rather than reading config itself, so "no configuration at
     * all" has to produce a working number rather than zero - a limit of zero would refuse every
     * source text on a freshly installed site.
     */
    public function test_a_missing_context_window_falls_back(): void {
        $this->resetAfterTest();

        unset_config('maxsourcetokens', 'local_artqtml');
        unset_config('generatorcontextwindow', 'local_artqtml');

        $expected = (int) floor(source_text_limit::DEFAULT_CONTEXT_WINDOW * 80 / 100);
        $this->assertSame($expected, source_text_limit::token_limit());
        $this->assertGreaterThan(0, source_text_limit::token_limit());
    }

    /**
     * A negative stored value falls back to automatic rather than being used.
     */
    public function test_a_negative_setting_falls_back_to_automatic(): void {
        $this->resetAfterTest();

        set_config('maxsourcetokens', -500, 'local_artqtml');
        set_config('generatorcontextwindow', 8192, 'local_artqtml');

        $this->assertSame(6553, source_text_limit::token_limit());
    }

    /**
     * The estimate: four characters per token, rounded up.
     *
     * @dataProvider estimate_provider
     * @param string $text
     * @param int $expected
     */
    public function test_the_token_estimate(string $text, int $expected): void {
        $this->assertSame($expected, source_text_limit::estimate_tokens($text));
    }

    /**
     * Cases for {@see self::test_the_token_estimate()}.
     *
     * @return array<string, array{string, int}>
     */
    public static function estimate_provider(): array {
        return [
            'empty'            => ['', 0],
            'one character'    => ['a', 1],
            'four characters'  => ['abcd', 1],
            'five characters'  => ['abcde', 2],
            'eight characters' => ['abcdefgh', 2],
            'accented letters' => ['áéíóöőúü', 2],
        ];
    }

    /**
     * Text exactly at the limit is accepted; one character more is not.
     *
     * The boundary matters because the counter shows the user the same number: refusing a text the
     * counter had just displayed as being at the maximum would look like a bug.
     */
    public function test_the_boundary_is_inclusive(): void {
        $this->resetAfterTest();

        set_config('maxsourcetokens', 10, 'local_artqtml');

        $atlimit = str_repeat('a', 40);
        $overlimit = str_repeat('a', 41);

        $this->assertSame(10, source_text_limit::estimate_tokens($atlimit));
        $this->assertFalse(source_text_limit::is_exceeded($atlimit));

        $this->assertSame(11, source_text_limit::estimate_tokens($overlimit));
        $this->assertTrue(source_text_limit::is_exceeded($overlimit));
    }

    /**
     * usage() reports every number the error message needs.
     */
    public function test_usage_reports_every_number(): void {
        $this->resetAfterTest();

        set_config('maxsourcetokens', 10, 'local_artqtml');

        $usage = source_text_limit::usage(str_repeat('a', 44));

        $this->assertSame(44, $usage['characters']);
        $this->assertSame(11, $usage['estimatedtokens']);
        $this->assertSame(10, $usage['tokenlimit']);
        $this->assertSame(40, $usage['characterlimit']);
        $this->assertTrue($usage['exceeded']);
    }

    /**
     * The character limit is the token limit expressed in the other unit.
     */
    public function test_the_character_limit_follows_the_token_limit(): void {
        $this->resetAfterTest();

        set_config('maxsourcetokens', 1000, 'local_artqtml');

        $this->assertSame(1000 * source_text_limit::CHARS_PER_TOKEN, source_text_limit::character_limit());
    }

    /**
     * Unrelated generator/validator config values must not change this limit.
     *
     * They are separate things that both count tokens, which is exactly the pair somebody will
     * later assume are connected. One limits a single request's input; the other limits spending
     * over a billing cycle.
     */
    public function test_the_monthly_budget_does_not_affect_this_limit(): void {
        $this->resetAfterTest();

        set_config('maxsourcetokens', 0, 'local_artqtml');
        set_config('generatorcontextwindow', 8192, 'local_artqtml');

        $before = source_text_limit::token_limit();

        set_config('generatortokenbudget', 5, 'local_artqtml');
        set_config('validatortokenbudget', 5, 'local_artqtml');
        set_config('validatorcontextwindow', 99, 'local_artqtml');

        $this->assertSame($before, source_text_limit::token_limit());
    }

    /**
     * The error message names the sizes and never quotes the text.
     *
     * It is shown on screen and written to the generation log, and source material has no business
     * in either place.
     */
    public function test_the_error_message_carries_numbers_not_content(): void {
        $this->resetAfterTest();

        set_config('maxsourcetokens', 10, 'local_artqtml');

        $secret = 'CONFIDENTIAL_TEACHING_MATERIAL_SENTINEL';
        $message = source_text_limit::error_message($secret . str_repeat(' filler', 20));

        $this->assertStringNotContainsString($secret, $message);
        $this->assertStringContainsString('10', $message);
    }
}
