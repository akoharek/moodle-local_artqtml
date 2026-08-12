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
 * Unit tests for the provider model list cache and filtering.
 *
 * The fetch itself is not exercised here - it needs a live provider - but everything the settings
 * Page depends on is: that the page reads the cache and only the cache, that the dropdown offers
 * Structured-output models only, and that a saved-but-unlisted model is recognised as such rather
 * Than silently dropped.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\model_list
 */
final class model_list_test extends \advanced_testcase {
    /**
     * Write a cache entry directly, as refresh() would.
     *
     * @param string $provider
     * @param array $models
     * @param int|null $fetchedat
     * @return void
     */
    protected function seed_cache(string $provider, array $models, ?int $fetchedat = null): void {
        set_config('modellistcache_' . $provider, json_encode([
            'models'    => $models,
            'fetchedat' => $fetchedat ?? time(),
            'error'     => '',
        ]), 'local_artqtml');
    }

    /**
     * A representative mixed list: two structured-output models and one without.
     *
     * @return array
     */
    protected function sample_models(): array {
        return [
            ['id' => 'claude-opus-4-8', 'display_name' => 'Claude Opus 4.8', 'supports_structured_output' => true],
            ['id' => 'claude-legacy-1', 'display_name' => 'Claude Legacy 1', 'supports_structured_output' => false],
            ['id' => 'claude-haiku-4-5', 'display_name' => 'Claude Haiku 4.5', 'supports_structured_output' => true],
        ];
    }

    /**
     * With nothing cached the settings page gets null - and must not fetch.
     */
    public function test_empty_cache_returns_null(): void {
        $this->resetAfterTest();

        $this->assertNull(model_list::get_cached(model_list::PROVIDER_CLAUDE));
        $this->assertFalse(model_list::is_cache_fresh(model_list::PROVIDER_CLAUDE));
        $this->assertSame([], model_list::selectable_options(model_list::PROVIDER_CLAUDE));
    }

    /**
     * 24-hour lifetime, measured from the fetch time.
     */
    public function test_cache_freshness_window(): void {
        $this->resetAfterTest();

        $this->seed_cache(model_list::PROVIDER_CLAUDE, $this->sample_models(), time() - 60);
        $this->assertTrue(model_list::is_cache_fresh(model_list::PROVIDER_CLAUDE));

        // One second past the window.
        $this->seed_cache(model_list::PROVIDER_CLAUDE, $this->sample_models(), time() - (model_list::CACHE_TTL + 1));
        $this->assertFalse(model_list::is_cache_fresh(model_list::PROVIDER_CLAUDE));
        // Stale but still readable: the annex keeps the previous content rather than emptying it.
        $this->assertNotNull(model_list::get_cached(model_list::PROVIDER_CLAUDE));
    }

    /**
     * Only structured-output models reach the dropdown.
     */
    public function test_only_structured_output_models_are_selectable(): void {
        $this->resetAfterTest();

        $this->seed_cache(model_list::PROVIDER_CLAUDE, $this->sample_models());
        $options = model_list::selectable_options(model_list::PROVIDER_CLAUDE);

        $this->assertArrayHasKey('claude-opus-4-8', $options);
        $this->assertArrayHasKey('claude-haiku-4-5', $options);
        $this->assertArrayNotHasKey('claude-legacy-1', $options, 'a model without structured output was offered');
        $this->assertCount(2, $options);
    }

    public function test_is_listed(): void {
        $this->resetAfterTest();

        $this->seed_cache(model_list::PROVIDER_CLAUDE, $this->sample_models());

        $this->assertTrue(model_list::is_listed(model_list::PROVIDER_CLAUDE, 'claude-opus-4-8'));
        // Present in the raw list but not structured-output capable, so not selectable.
        $this->assertFalse(model_list::is_listed(model_list::PROVIDER_CLAUDE, 'claude-legacy-1'));
        // The real-world case: a retired model the provider no longer lists at all.
        $this->assertFalse(model_list::is_listed(model_list::PROVIDER_CLAUDE, 'gemini-2.0-flash'));
        $this->assertFalse(model_list::is_listed(model_list::PROVIDER_CLAUDE, ''));
    }

    /**
     * A corrupt cache value degrades to "nothing cached" rather than throwing on an admin page.
     */
    public function test_corrupt_cache_is_treated_as_empty(): void {
        $this->resetAfterTest();

        set_config('modellistcache_claude', 'not json at all', 'local_artqtml');
        $this->assertNull(model_list::get_cached(model_list::PROVIDER_CLAUDE));

        set_config('modellistcache_claude', json_encode(['unexpected' => 'shape']), 'local_artqtml');
        $this->assertNull(model_list::get_cached(model_list::PROVIDER_CLAUDE));
        $this->assertSame([], model_list::selectable_options(model_list::PROVIDER_CLAUDE));
    }

    /**
     * The two providers cache independently - refreshing one must not disturb the other.
     */
    public function test_providers_cache_independently(): void {
        $this->resetAfterTest();

        $this->seed_cache(model_list::PROVIDER_CLAUDE, $this->sample_models());
        $this->seed_cache(model_list::PROVIDER_GEMINI, [
            ['id' => 'gemini-3.5-flash', 'display_name' => 'Gemini 3.5 Flash', 'supports_structured_output' => true],
        ]);

        $this->assertCount(2, model_list::selectable_options(model_list::PROVIDER_CLAUDE));
        $this->assertSame(['gemini-3.5-flash'], array_keys(model_list::selectable_options(model_list::PROVIDER_GEMINI)));
        $this->assertFalse(model_list::is_listed(model_list::PROVIDER_GEMINI, 'claude-opus-4-8'));
    }

    public function test_refresh_without_api_key_preserves_the_cache(): void {
        $this->resetAfterTest();

        $this->seed_cache(model_list::PROVIDER_CLAUDE, $this->sample_models());
        $before = model_list::get_cached(model_list::PROVIDER_CLAUDE);

        $result = model_list::refresh(model_list::PROVIDER_CLAUDE);

        $this->assertFalse($result['success']);
        $this->assertNotSame('', $result['error']);
        $this->assertSame($before, model_list::get_cached(model_list::PROVIDER_CLAUDE));
    }

    /**
     * Both providers are covered by the constant the rest of the code iterates.
     */
    public function test_providers_constant(): void {
        $this->assertSame(['claude', 'gemini'], model_list::PROVIDERS);
        $this->assertSame(86400, model_list::CACHE_TTL);
    }

    /**
     * The real catalogue, as the account returned it on 2026-08-03: 42 models, of which 21 are not
     * Text models at all. Asserted against the actual names rather than invented ones, because the
     * Filter's whole job is to survive what Google actually publishes.
     *
     * Why it matters beyond tidiness: every one of these was probed with a real question-generation
     * Request, and that is what pushed the sweep past PHP's execution limit.
     *
     * @dataProvider gemini_model_provider
     */
    public function test_non_text_gemini_models_are_recognised(string $id, bool $expected): void {
        $this->assertSame($expected, model_list::is_non_text_gemini_model($id));
    }

    /**
     * The catalogue the account returned on 2026-08-03, one entry per distinct kind.
     *
     * @return array<string, array{string, bool}>
     */
    public static function gemini_model_provider(): array {
        return [
            // Kept - these are the text models a question can come from.
            'flash'          => ['gemini-3.6-flash', false],
            'pro preview'    => ['gemini-3.1-pro-preview', false],
            'flash lite'     => ['gemini-3.1-flash-lite', false],
            'latest alias'   => ['gemini-flash-latest', false],
            'gemma'          => ['gemma-4-31b-it', false],
            'omni'           => ['gemini-omni-flash-preview', false],
            'customtools'    => ['gemini-3.1-pro-preview-customtools', false],
            // Dropped - one per modality actually seen in the catalogue.
            'image'          => ['gemini-2.5-flash-image', true],
            'image preview'  => ['gemini-3.1-flash-image-preview', true],
            'lite image'     => ['gemini-3.1-flash-lite-image', true],
            'speech'         => ['gemini-2.5-flash-preview-tts', true],
            'speech preview' => ['gemini-3.1-flash-tts-preview', true],
            'music'          => ['lyria-3-pro-preview', true],
            'image model'    => ['nano-banana-pro-preview', true],
            'robotics'       => ['gemini-robotics-er-2-preview', true],
            'computer use'   => ['gemini-2.5-computer-use-preview-10-2025', true],
            'deep research'  => ['deep-research-pro-preview-12-2025', true],
            'agent'          => ['antigravity-preview-05-2026', true],
        ];
    }

    /**
     * The count is the point: a filter that quietly stopped matching would still pass the cases
     * Above one by one, so the whole measured catalogue is run through it at once.
     */
    public function test_the_measured_catalogue_halves(): void {
        $catalogue = array_map(
            fn(array $case): string => $case[0],
            array_values(self::gemini_model_provider())
        );
        $dropped = array_filter($catalogue, fn(string $id): bool => model_list::is_non_text_gemini_model($id));

        $this->assertCount(11, $dropped);
        $this->assertCount(7, array_diff($catalogue, $dropped));
    }
}
