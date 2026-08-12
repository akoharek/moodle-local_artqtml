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
 * Shared HTTP-level exponential backoff for Claude/Gemini adhoc tasks.
 *
 * The HTTP-level retry (max 3 attempts: immediate, 2s, 4s + 0-20% jitter) and the JSON-fallback
 * Retry (max 2 attempts, independent counter) are separate mechanisms — this trait only
 * Implements the former. Callers loop the JSON-fallback themselves around a call to
 * {@see self::http_with_backoff()}.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\task;

/**
 * HTTP retry/backoff helper for AI adhoc tasks.
 */
trait retry_trait {
    /**
     * Helper.
     *
     * @var int[] HTTP status codes considered retryable (Claude 429/500/504/529, Gemini 429/500/503/504).
     *
     * Shared with {@see \local_artqtml\local\ai_request::TRANSIENT_HTTP} so "provider busy"
     * Is not recorded as "this model cannot be used".
     */
    protected const RETRYABLE_HTTP = \local_artqtml\local\ai_request::TRANSIENT_HTTP;

    /** @var int maximum HTTP-level attempts. */
    public const MAX_HTTP_ATTEMPTS = 3;

    /**
     * Helper.
     *
     * @var float worst-case total backoff sleep across one exhausted HTTP retry cycle, in seconds.
     *
     * Annex 2.4: 2 s before attempt 2 and 4 s before attempt 3, each with up to 20% jitter, so
     * 2 x 1.2 + 4 x 1.2. Public so process_pending_generations can size its time limit from the
     * Same numbers {@see self::backoff_sleep()} actually sleeps for, rather than a copy.
     */
    public const MAX_BACKOFF_SECONDS = 7.2;

    /**
     * Run an HTTP request callable with exponential backoff on retryable status codes.
     *
     * @param callable $requestfn () => array{httpcode:int, body:string, curlerror:string}
     * @param int $generationid
     * @param string $calltype 'generate' or 'validate'
     * @param string $provider 'claude' or 'gemini'
     * @param int|null $userid
     * @return array{httpcode:int, body:string, curlerror:string, attempts:int} the last attempt's result
     */
    protected function http_with_backoff(
        callable $requestfn,
        int $generationid,
        string $calltype,
        string $provider,
        ?int $userid = null
    ): array {
        $result = ['httpcode' => 0, 'body' => '', 'curlerror' => '', 'attempts' => 0];

        for ($attempt = 1; $attempt <= self::MAX_HTTP_ATTEMPTS; $attempt++) {
            $result = $requestfn();
            $result['attempts'] = $attempt;

            $retryable = $result['curlerror'] !== '' || in_array((int) $result['httpcode'], self::RETRYABLE_HTTP, true);
            if (!$retryable || $attempt === self::MAX_HTTP_ATTEMPTS) {
                break;
            }

            $this->log_ai_call($generationid, $calltype, $provider, [
                'httpstatus'   => $result['httpcode'],
                'isretry'      => true,
                'result'       => 'error',
                'errormessage' => $result['curlerror'] !== ''
                    ? $result['curlerror']
                    : $this->extract_error_message($result['body']),
            ], $userid);

            $this->backoff_sleep($attempt);
        }

        return $result;
    }

    /**
     * Extract the technical error.message from a Claude/Gemini error response body - both
     * Providers use the same {"error": {"message": "..."}} shape.
     *
     * @param string $body
     * @return string
     */
    protected function extract_error_message(string $body): string {
        $decoded = json_decode($body, true);
        return (string) ($decoded['error']['message'] ?? $body);
    }

    /**
     * Sleep for the backoff duration before HTTP retry attempt $attempt + 1.
     *
     * Attempt 1 -> immediate (no sleep), attempt 2 -> ~2s, attempt 3 -> ~4s, each with
     * 0-20% jitter ( table).
     *
     * @param int $attempt the attempt number that just failed (1-based)
     * @return void
     */
    protected function backoff_sleep(int $attempt): void {
        $base = $attempt === 1 ? 2 : 4;
        $jitter = $base * (random_int(0, 20) / 100);
        usleep((int) round(($base + $jitter) * 1_000_000));
    }

    /**
     * Whether an HTTP status code is retryable.
     *
     * @param int $httpcode
     * @return bool
     */
    protected function is_retryable_http(int $httpcode): bool {
        return in_array($httpcode, self::RETRYABLE_HTTP, true);
    }

    /**
     * is nonretryable client error.
     *
     * @param int $httpcode
     * @return bool
     */
    protected function is_nonretryable_client_error(int $httpcode): bool {
        return $httpcode >= 400 && $httpcode < 500 && $httpcode !== 429;
    }
}
