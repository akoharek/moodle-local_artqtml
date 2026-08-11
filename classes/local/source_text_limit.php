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
 * The one place that decides how much source text this plugin will accept.
 *
 * WHY THIS CLASS EXISTS. Until 2026-08-04 the size of a source text was a display detail: the
 * upload page ran a live character/word/token counter in JavaScript and coloured it red past the
 * context window, and that was the whole of it. Nothing on the server ever compared a number to a
 * limit - not the form, not the upload handler, not the task that makes the API call. A pasted
 * document of any size was saved, hashed, and sent to the provider, and a crafted POST skipped even
 * the colouring.
 *
 * WHAT THE NUMBER IS, AND WHAT IT IS NOT. The estimate is characters divided by four - the same
 * approximation the counter has always shown the user, kept deliberately identical so the two
 * cannot disagree about the same text. It is NOT the provider's tokenizer: real tokenization is
 * model-specific, and Hungarian text in particular will not divide neatly by four. This is a
 * resource guard with a predictable number behind it, not a billing figure.
 *
 * Three things it is explicitly not:
 *
 *  - It is not a context-fit calculation. A request that passes this can still be too large for a
 *    model once the system prompt, the schema and the answer are added; that is what the 80%
 *    headroom is for, and it is a margin rather than a proof.
 *  - It is not connected to the monthly token budget ({@see token_budget}). That limits spending
 *    over a billing cycle; this limits one request's input.
 *  - It does not truncate. Silently shortening a teacher's material would produce questions about
 *    a document they did not upload, and nothing on screen would say so.
 *
 * Everything about the limit lives here - the /4 estimate, the 80% formula, the fallback context
 * window and the comparison - because the alternative is the same arithmetic in the form, the
 * upload handler, the AJAX endpoint and the task, drifting apart one edit at a time.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Estimates source-text size and answers whether it is over the configured limit.
 */
class source_text_limit {
    /**
     * Percentage of the generator's context window used as the automatic limit.
     *
     * The remaining fifth is not slack: the request also carries the system prompt, the response
     * schema and the provider envelope, and the model's own answer has to fit in the same window.
     *
     * @var int
     */
    public const AUTO_CONTEXT_PERCENT = 80;

    /** @var int characters per estimated token - the approximation js/textcounter.js has always shown. */
    public const CHARS_PER_TOKEN = 4;

    /** @var int context window assumed when the setting is missing or not positive. */
    public const DEFAULT_CONTEXT_WINDOW = 8192;

    /**
     * The effective source-text limit in estimated tokens.
     *
     * An administrator may set `maxsourcetokens` explicitly. Zero - the default - means "work it
     * out from the generator's context window", so a site that raises its context window does not
     * also have to remember to raise a second number that nothing links to the first.
     *
     * A negative or unreadable stored value falls back to the automatic mode rather than being
     * used, because the alternative to a nonsensical limit is a working one, not no limit at all.
     *
     * @return int always at least 1
     */
    public static function token_limit(): int {
        $explicit = (int) (get_config('local_artqtml', 'maxsourcetokens') ?: 0);
        if ($explicit > 0) {
            return $explicit;
        }

        $contextwindow = (int) (get_config('local_artqtml', 'generatorcontextwindow') ?: 0);
        if ($contextwindow <= 0) {
            $contextwindow = self::DEFAULT_CONTEXT_WINDOW;
        }

        return max(1, (int) floor($contextwindow * self::AUTO_CONTEXT_PERCENT / 100));
    }

    /**
     * Estimated token count for a piece of text.
     *
     * Counts Unicode characters via \core_text, not bytes: a Hungarian source text would otherwise
     * be over-counted by every accented letter, and the number the user sees would not match the
     * number the server compares.
     *
     * @param string $text
     * @return int 0 for an empty string
     */
    public static function estimate_tokens(string $text): int {
        $characters = \core_text::strlen($text);
        if ($characters <= 0) {
            return 0;
        }

        return (int) ceil($characters / self::CHARS_PER_TOKEN);
    }

    /**
     * The limit expressed in characters.
     *
     * NOT USED IN A DECISION ANYWHERE, and that is on purpose rather than an oversight - the
     * docblock used to claim it fed the messages and the browser counter, and neither is true.
     * Both work in tokens, because the token limit is the one the provider actually enforces and
     * the character figure is an estimate derived from it ({@see self::CHARS_PER_TOKEN}). This
     * exists so that a character figure, where one is wanted, comes from the same arithmetic as
     * everything else rather than from a second copy of it.
     *
     * @return int
     */
    public static function character_limit(): int {
        return self::token_limit() * self::CHARS_PER_TOKEN;
    }

    /**
     * Whether this text is over the limit.
     *
     * Text estimated at exactly the limit is accepted - the limit is a maximum, not a boundary to
     * stay under, and an off-by-one here would reject a document the counter had just shown as
     * being at the maximum.
     *
     * @param string $text
     * @return bool
     */
    public static function is_exceeded(string $text): bool {
        return self::estimate_tokens($text) > self::token_limit();
    }

    /**
     * Everything a message about this text needs, in one call.
     *
     * The error the user sees names all four numbers, because "too long" without them leaves them
     * guessing how much to cut.
     *
     * @param string $text
     * @return array{characters: int, estimatedtokens: int, tokenlimit: int, characterlimit: int, exceeded: bool}
     */
    public static function usage(string $text): array {
        $tokens = self::estimate_tokens($text);
        $limit = self::token_limit();

        return [
            'characters'      => \core_text::strlen($text),
            'estimatedtokens' => $tokens,
            'tokenlimit'      => $limit,
            'characterlimit'  => $limit * self::CHARS_PER_TOKEN,
            'exceeded'        => $tokens > $limit,
        ];
    }

    /**
     * The localised "too long" message for a piece of text, with its numbers filled in.
     *
     * Provided here so the four call sites that refuse an oversized text - the form, the upload
     * handler, the AJAX endpoint and the task - cannot each assemble a slightly different sentence
     * from the same facts.
     *
     * The message names sizes only. It never quotes the text itself: an error is displayed, logged
     * and sometimes mailed, and source material is exactly what should not travel that way.
     *
     * @param string $text
     * @return string
     */
    public static function error_message(string $text): string {
        $usage = self::usage($text);

        return get_string('errorsourcetexttoolongdetails', 'local_artqtml', (object) [
            'tokens'     => $usage['estimatedtokens'],
            'characters' => $usage['characters'],
            'limit'      => $usage['tokenlimit'],
        ]);
    }
}
