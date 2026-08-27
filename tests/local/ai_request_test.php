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
 * Unit tests for the single-source structured-output request.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\ai_request
 */
final class ai_request_test extends \advanced_testcase {
    /**
     * The probe and the generator must send the same envelope, headers and parameter name.
     */
    public function test_probe_and_generator_build_the_same_shape(): void {
        $generator = ai_request::claude('claude-opus-4-8', 'key', 8192, 'system', 'source', [
            'type' => 'object', 'properties' => ['questions' => ['type' => 'array']], 'required' => ['questions'],
        ]);
        $probe = ai_request::claude('claude-opus-4-8', 'key', 128, 'p', 'p', [
            'type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok'],
        ]);

        $this->assertSame($generator['url'], $probe['url']);
        $this->assertSame($generator['headers'], $probe['headers']);
        $this->assertSame(array_keys($generator['payload']), array_keys($probe['payload']));

        $this->assertNotSame(
            $generator['payload']['output_config']['format']['schema'],
            $probe['payload']['output_config']['format']['schema']
        );
    }

    /**
     * Anthropic deprecated output_format in favour of output_config.format. Measured across all
     * Eleven dropdown models: output_format without the beta header is a 400 on every one of them,
     * And output_config.format without any header is a 200 on every one of them.
     */
    public function test_claude_uses_output_config_and_no_beta_header(): void {
        $request = ai_request::claude('claude-opus-4-8', 'key', 128, 's', 'u', ['type' => 'object']);

        $this->assertArrayHasKey('output_config', $request['payload']);
        $this->assertArrayNotHasKey('output_format', $request['payload']);
        $this->assertSame('json_schema', $request['payload']['output_config']['format']['type']);

        foreach ($request['headers'] as $header) {
            $this->assertStringNotContainsStringIgnoringCase('anthropic-beta', $header);
        }
    }

    /**
     * The API key reaches the right header for each provider, and the Gemini model is URL-encoded.
     */
    public function test_endpoints_and_auth_headers_per_provider(): void {
        $claude = ai_request::claude('m', 'secret', 1, 's', 'u', ['type' => 'object']);
        $this->assertSame(ai_request::URL_CLAUDE, $claude['url']);
        $this->assertContains('x-api-key: secret', $claude['headers']);

        $gemini = ai_request::gemini('gemini-3.5-flash', 'secret', 's', 'u', ['type' => 'object']);
        $this->assertStringEndsWith('/gemini-3.5-flash:generateContent', $gemini['url']);
        $this->assertContains('x-goog-api-key: secret', $gemini['headers']);
        $this->assertArrayHasKey('responseSchema', $gemini['payload']['generationConfig']);
    }

    /**
     * A deeply nested schema, used to prove both provider rules reach every object.
     *
     * @return array
     */
    protected function nested_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'questions' => [
                    'type' => 'array',
                    'items' => [
                        'anyOf' => [
                            ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
                            [
                                'type' => 'object',
                                'properties' => ['nested' => ['type' => 'object', 'properties' => []]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Anthropic rejects the whole request if any object anywhere lacks additionalProperties:false,
     * With a message that looks nothing like the mistake. The rule is applied in one place so that
     * No caller can forget it - the probe's hand-written schema was the first that did.
     */
    public function test_claude_schema_reaches_every_nested_object(): void {
        $hardened = ai_request::claude_schema($this->nested_schema());

        $this->assertFalse($hardened['additionalProperties']);
        $branches = $hardened['properties']['questions']['items']['anyOf'];
        $this->assertFalse($branches[0]['additionalProperties']);
        $this->assertFalse($branches[1]['additionalProperties']);
        $this->assertFalse($branches[1]['properties']['nested']['additionalProperties']);
    }

    /**
     * Gemini's rule is the opposite one, and a live 400 proves it: "Unknown name
     * 'additionalProperties' at 'generation_config.response_schema': Cannot find field."
     * ResponseSchema is an OpenAPI subset, so hardening a schema for Gemini breaks it.
     */
    public function test_gemini_schema_removes_what_claude_requires(): void {
        $stripped = ai_request::gemini_schema(ai_request::claude_schema($this->nested_schema()));

        $this->assertArrayNotHasKey('additionalProperties', $stripped);
        $branches = $stripped['properties']['questions']['items']['anyOf'];
        $this->assertArrayNotHasKey('additionalProperties', $branches[0]);
        $this->assertArrayNotHasKey('additionalProperties', $branches[1]);
        $this->assertArrayNotHasKey('additionalProperties', $branches[1]['properties']['nested']);
    }

    /**
     * The second keyword of the same family, and the one that cost a whole sweep.
     *
     * 2026-08-03: every one of the 42 Gemini models came back `failure` in about 150 ms with
     * "Unknown name "const" at 'generation_config.response_schema...'", which excluded all of them
     * From the validator's dropdown at once. No model was ever reached - the API rejected the
     * Request. question_schema::build() pins each question's type with `['const' => $typecode]`,
     * And the OpenAPI subset expresses that as a single-entry `enum` instead.
     *
     * Asserted on the *production* schema rather than a fixture, because a fixture only proves the
     * Fixture: a `const` added to question_schema tomorrow has to fail this test, not a live sweep.
     */
    public function test_gemini_schema_leaves_no_const_in_the_production_schema(): void {
        $settings = ['counts' => [], 'types' => []];
        foreach (question_types::CODES as $code) {
            $settings['counts'][$code] = 1;
            $settings['types'][$code] = ['hintenabled' => true, 'feedbackenabled' => true];
        }
        $converted = ai_request::gemini_schema(question_schema::build($settings));

        $this->assertStringNotContainsString(
            '"const"',
            json_encode($converted),
            'A const key survived the conversion; Gemini rejects the whole request over it.'
        );

        // The meaning has to survive the conversion, not just the keyword disappear. Collected by
        // Value rather than asserted at a fixed index, so the test does not quietly depend on the
        // Order build() happens to emit its branches in.
        $pinned = [];
        foreach ($converted['properties']['questions']['items']['anyOf'] as $branch) {
            $this->assertSame('string', $branch['properties']['type']['type']);
            $this->assertCount(1, $branch['properties']['type']['enum']);
            $pinned[] = $branch['properties']['type']['enum'][0];
        }
        sort($pinned);
        $expected = question_types::CODES;
        sort($expected);
        $this->assertSame($expected, $pinned);
    }

    /**
     * The walker reaches every node, so Claude's rule has to guard itself: additionalProperties on
     * A string is a new way to fail a live call, and nothing else would catch it.
     */
    public function test_claude_schema_does_not_touch_scalar_properties(): void {
        $hardened = ai_request::claude_schema([
            'type'       => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]);

        $this->assertFalse($hardened['additionalProperties']);
        $this->assertArrayNotHasKey('additionalProperties', $hardened['properties']['name']);
    }

    /**
     * The production schema already complies with Anthropic's rule, so applying it must change
     * Nothing - if it ever does, question_schema has grown an object that would fail the live call.
     */
    public function test_production_schema_is_already_compliant(): void {
        $settings = ['counts' => [], 'types' => []];
        foreach (question_types::CODES as $code) {
            $settings['counts'][$code] = 2;
            $settings['types'][$code] = ['hintenabled' => true, 'feedbackenabled' => true];
        }
        $schema = question_schema::build($settings);

        $this->assertSame($schema, ai_request::claude_schema($schema));
    }

    /**
     * A hard rejection blocks; a deprecation notice on a successful call does not. Getting this
     * Backwards is what turned a self-inflicted 400 into a site-wide block.
     */
    public function test_classify_separates_deprecation_from_rejection(): void {
        $this->assertSame(ai_request::OUTCOME_OK, ai_request::classify(200, ['content' => []])['outcome']);

        $deprecated = ai_request::classify(200, [
            'warnings' => [['type' => 'deprecation', 'message' => 'output_format is deprecated']],
        ]);
        $this->assertSame(ai_request::OUTCOME_DEPRECATED, $deprecated['outcome']);
        $this->assertStringContainsString('deprecated', $deprecated['message']);

        $rejected = ai_request::classify(400, ['error' => ['message' => 'This field is deprecated.']]);
        $this->assertSame(ai_request::OUTCOME_REJECTED, $rejected['outcome']);
        $this->assertSame('This field is deprecated.', $rejected['message']);

        // An unparseable body still classifies, rather than throwing on a page nobody can then fix.
        $this->assertSame(ai_request::OUTCOME_REJECTED, ai_request::classify(500, null)['outcome']);
    }

    /**
     * The rule this whole class enforces: exactly one place builds a provider request.
     *
     * A static scan, because the defect it guards against is a second construction path appearing
     * Somewhere else - which is invisible until the two drift. Six instances of that pattern have
     * Been found in this plugin; the probe was the first we introduced ourselves.
     */
    public function test_nothing_else_builds_a_provider_request(): void {
        $root = realpath(__DIR__ . '/../..');
        $allowed = [$root . '/classes/local/ai_request.php'];

        // Literals that only a hand-built generation/validation request would contain. The model
        // List and connection test call the providers' /models endpoints, which is a different
        // Request with no schema and no beta header, so those are deliberately not covered here.
        $markers = [
            'output_format',
            'output_config',
            'anthropic-beta',
            'responseSchema',
            ':generateContent',
            '/v1/messages',
        ];

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/classes'));
        foreach ($iterator as $fileinfo) {
            $path = $fileinfo->getPathname();
            if (substr($path, -4) !== '.php' || in_array($path, $allowed, true)) {
                continue;
            }
            // Comments may name the parameters - it is the code that must not rebuild the request.
            $code = '';
            foreach (token_get_all(file_get_contents($path)) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= is_array($token) ? $token[1] : $token;
                }
            }
            foreach ($markers as $marker) {
                if (strpos($code, $marker) !== false) {
                    $offenders[] = str_replace($root . '/', '', $path) . ' (' . $marker . ')';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'only ai_request may build a provider request: ' . implode(', ', $offenders)
        );
    }

    /**
     * A reasoning model puts its thinking first, and the answer after it.
     */
    public function test_extract_text_survives_a_thinking_block(): void {
        $withthinking = [
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Let me consider the source text.'],
                ['type' => 'text', 'text' => '{"questions":[]}'],
            ],
        ];
        $this->assertSame(
            '{"questions":[]}',
            ai_request::extract_text(model_list::PROVIDER_CLAUDE, $withthinking),
            'a thinking block in front must not hide the answer behind it'
        );

        // The plain single-block reply must keep behaving exactly as it did before.
        $plain = ['content' => [['type' => 'text', 'text' => 'plain']]];
        $this->assertSame('plain', ai_request::extract_text(model_list::PROVIDER_CLAUDE, $plain));

        // Gemini marks reasoning with thought:true instead of a separate type.
        $gemini = [
            'candidates' => [
                ['content' => ['parts' => [
                    ['thought' => true, 'text' => 'reasoning'],
                    ['text' => 'the answer'],
                ]]],
            ],
        ];
        $this->assertSame('the answer', ai_request::extract_text(model_list::PROVIDER_GEMINI, $gemini));

        // An envelope carrying no text at all yields null rather than an empty string, so the
        // Caller can tell "nothing came back" from "the model returned an empty answer".
        $this->assertNull(ai_request::extract_text(model_list::PROVIDER_CLAUDE, ['content' => []]));
        $this->assertNull(ai_request::extract_text(model_list::PROVIDER_CLAUDE, null));
    }

    /**
     * The truncation signal, which the two providers spell differently and nested differently.
     */
    public function test_hit_token_limit_reads_both_providers(): void {
        $this->assertTrue(ai_request::hit_token_limit(
            model_list::PROVIDER_CLAUDE,
            ['stop_reason' => 'max_tokens']
        ));
        $this->assertTrue(ai_request::hit_token_limit(
            model_list::PROVIDER_GEMINI,
            ['candidates' => [['finishReason' => 'MAX_TOKENS']]]
        ));

        // A normal completion is not a truncation, and neither is an unreadable body.
        $this->assertFalse(ai_request::hit_token_limit(
            model_list::PROVIDER_CLAUDE,
            ['stop_reason' => 'end_turn']
        ));
        $this->assertFalse(ai_request::hit_token_limit(
            model_list::PROVIDER_GEMINI,
            ['candidates' => [['finishReason' => 'STOP']]]
        ));
        $this->assertFalse(ai_request::hit_token_limit(model_list::PROVIDER_CLAUDE, null));
    }

    /**
     * 's real point: exactly one place knows where the answer sits in the envelope.
     *
     * The sibling of the request-building guard above, and it exists for a sharper reason. Until
     * 2026-08-03 this knowledge was written out three times - generation, validation, model check -
     * And they agreed only by coincidence. They agreed on something wrong.
     *
     * The direction of the danger is what makes a static scan worth it: fix the model check alone
     * And it will pass a model that generation then fails on, so the connection-test button would
     * Promise something untrue. A copy reappearing anywhere is invisible until exactly that
     * Happens, which is why this is checked in the source rather than left to review.
     */
    public function test_nothing_else_reads_the_response_envelope(): void {
        $root = realpath(__DIR__ . '/../..');
        $allowed = [$root . '/classes/local/ai_request.php'];

        // The envelope paths and the truncation signals, written as they appear in code. Anything
        // Reaching into the provider's reply by hand will contain one of them. The stop reason is
        // In here because it was the second copy found: Claude spells it `stop_reason`/`max_tokens`
        // At the top level, Gemini `finishReason`/`MAX_TOKENS` two levels down, and the two were
        // Compared by hand in two files - a difference of spelling is exactly what survives a copy.
        $markers = [
            "['content'][0]",
            "['candidates'][0]",
            "'stop_reason'",
            'finishReason',
        ];

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/classes'));
        foreach ($iterator as $fileinfo) {
            $path = $fileinfo->getPathname();
            if (substr($path, -4) !== '.php' || in_array($path, $allowed, true)) {
                continue;
            }
            // Comments may quote the shape while explaining it; only code is the offence.
            $code = '';
            foreach (token_get_all(file_get_contents($path)) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= is_array($token) ? $token[1] : $token;
                }
            }
            $stripped = preg_replace('/\s+/', '', $code);
            foreach ($markers as $marker) {
                if (strpos($stripped, str_replace(' ', '', $marker)) !== false) {
                    $offenders[] = str_replace($root . '/', '', $path) . ' (' . $marker . ')';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'only ai_request::extract_text may read the provider envelope, otherwise the model '
                . 'check can pass a model that generation fails on: ' . implode(', ', $offenders)
        );
    }

    /**
     * Every request this class builds carries the security guard, on both providers.
     *
     * The guard is the one piece of prompt text an administrator cannot edit away, so the test
     * That matters is not "does the constant exist" but "does it reach the payload on both sides".
     * Claude and Gemini put the system instruction in different places, and the guard was added
     * To each of them separately - which is exactly the shape of change that silently covers one
     * Provider and not the other.
     */
    public function test_both_providers_carry_the_security_guard(): void {
        $schema = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok']];

        $claude = ai_request::claude('claude-opus-4-8', 'key', 128, 'Base system prompt.', 'user', $schema);
        $gemini = ai_request::gemini('gemini-3-pro', 'key', 'Base system prompt.', 'user', $schema);

        $claudesystem = ai_request::system_from_payload($claude['payload']);
        $geminisystem = ai_request::system_from_payload($gemini['payload']);

        $this->assertNotNull($claudesystem);
        $this->assertNotNull($geminisystem);

        // The same guard text, not two similar ones.
        $guard = substr($claudesystem, strpos($claudesystem, 'Security boundary:'));
        $this->assertNotSame('', $guard);
        $this->assertStringContainsString($guard, $geminisystem);

        // The caller's own prompt survives, and comes first.
        $this->assertStringStartsWith('Base system prompt.', $claudesystem);
        $this->assertStringStartsWith('Base system prompt.', $geminisystem);
    }

    /**
     * The guard appears exactly once, however many times the prompt passes through.
     *
     * Idempotence is not decoration here: the same system prompt is built in one place and sent
     * From three, and a guard that stacked would cost tokens on every call and tell the model the
     * Instruction is negotiable.
     */
    public function test_the_guard_is_applied_exactly_once(): void {
        $once = ai_request::harden_system_prompt('Base system prompt.');
        $twice = ai_request::harden_system_prompt($once);
        $thrice = ai_request::harden_system_prompt($twice);

        $this->assertSame($once, $twice);
        $this->assertSame($once, $thrice);
        $this->assertSame(1, substr_count($once, 'Security boundary:'));

        $schema = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok']];
        $payload = ai_request::claude('claude-opus-4-8', 'key', 128, $once, 'user', $schema)['payload'];
        $this->assertSame(1, substr_count((string) ai_request::system_from_payload($payload), 'Security boundary:'));
    }

    /**
     * An empty system prompt still produces the guard, and nothing else.
     */
    public function test_an_empty_system_prompt_still_gets_the_guard(): void {
        $hardened = ai_request::harden_system_prompt('');

        $this->assertStringStartsWith('Security boundary:', $hardened);
        $this->assertSame(1, substr_count($hardened, 'Security boundary:'));

        // Trailing whitespace must not produce a prompt that starts with blank lines.
        $this->assertSame($hardened, ai_request::harden_system_prompt("   \n\n  "));
    }

    /**
     * Hardening does not disturb what the rest of the payload is for.
     *
     * The API key stays in the header and never appears in the body - the same assertion the
     * Privacy and diagnostics work relies on - and the schema still reaches its provider-specific
     * Field. Both are checked here because this change touched the payload builder.
     */
    public function test_hardening_leaves_the_key_and_schema_alone(): void {
        $schema = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok']];

        $claude = ai_request::claude('claude-opus-4-8', 'secret-key', 128, 'system', 'user', $schema);
        $gemini = ai_request::gemini('gemini-3-pro', 'secret-key', 'system', 'user', $schema);

        $this->assertStringNotContainsString('secret-key', json_encode($claude['payload']));
        $this->assertStringNotContainsString('secret-key', json_encode($gemini['payload']));

        $this->assertArrayHasKey('schema', $claude['payload']['output_config']['format']);
        $this->assertArrayHasKey('responseSchema', $gemini['payload']['generationConfig']);
    }

    /**
     * JSON API errors keep error.message; non-JSON bodies are capped.
     */
    public function test_error_message_from_body_json_and_non_json(): void {
        $json = json_encode(['error' => ['message' => 'Rate limit exceeded']]);
        $this->assertSame('Rate limit exceeded', ai_request::error_message_from_body($json));

        $longhtml = str_repeat('X', 600);
        $truncated = ai_request::error_message_from_body($longhtml);
        $this->assertSame(501, \core_text::strlen($truncated));
        $this->assertStringEndsWith('…', $truncated);
        $this->assertSame(str_repeat('X', 500), \core_text::substr($truncated, 0, 500));

        $longjson = json_encode(['status' => str_repeat('Y', 600)]);
        $truncatedjson = ai_request::error_message_from_body($longjson);
        $this->assertSame(501, \core_text::strlen($truncatedjson));
        $this->assertStringEndsWith('…', $truncatedjson);
    }
}
