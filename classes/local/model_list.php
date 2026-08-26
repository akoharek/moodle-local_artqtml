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
 * Provider model list: fetch, normalise, filter and cache.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Fetches, filters and caches the providers' model lists.
 */
class model_list {
    /** @var string the generator provider key, as used by api_key_store and the settings. */
    public const PROVIDER_CLAUDE = 'claude';

    /** @var string the validator provider key. */
    public const PROVIDER_GEMINI = 'gemini';

    /** @var string[] both providers, in settings-tab order. */
    public const PROVIDERS = [self::PROVIDER_CLAUDE, self::PROVIDER_GEMINI];

    /** @var int cache lifetime in seconds (: 24 hours). */
    public const CACHE_TTL = 86400;

    /**
     * Helper.
     *
     * @var string[] id fragments that mark a Gemini model as not a text model.
     *
     * Gemini's catalogue is one list for every modality, and `supportedGenerationMethods` does not
     * Separate them: a speech or image model answers `generateContent` exactly like a text model
     * Does, so the method check alone lets all of them through. Anthropic's list needs no
     * Equivalent because it publishes `capabilities.structured_outputs` per model.
     *
     *
     * Matched as substrings against the bare model id, deliberately: Google's naming puts the
     * Modality in the id itself, and a new `gemini-4-flash-image` has to be excluded on the day it
     * Appears, without an edit here. The cost of that choice is that a text model whose name
     * Happens to contain one of these fragments would be excluded too - which is why the list is
     * Kept to modality words rather than anything that could occur incidentally.
     */
    public const GEMINI_NON_TEXT_MARKERS = [
        'antigravity',
        'computer-use',
        'deep-research',
        '-image',
        'lyria',
        'nano-banana',
        'robotics',
        '-tts',
    ];

    /**
     * Helper.
     *
     * @var int page size requested from Anthropic.
     *
     * The endpoint defaults to 20 and caps at 1000. Raising the limit is not by itself enough -
     * The annex is explicit that the list length is not guaranteed - so {@see self::fetch_claude()}
     * Follows the after_id cursor to the end regardless of this value.
     */
    public const ANTHROPIC_PAGE_SIZE = 1000;

    /** @var int hard stop on cursor pages, so a malformed has_more can never loop forever. */
    protected const MAX_PAGES = 20;

    /**
     * The cached model list for a provider, or null if nothing usable is cached.
     *
     * The returned shape is {models: list<array{id, display_name, supports_structured_output}>,
     * Fetchedat: int, error: string}. It is deliberately typed loosely: the value is json_decode'd
     * From stored config, so it is only that shape if nothing corrupted it, and the runtime guards
     * Below - not the annotation - are what make a corrupt cache harmless on an admin page.
     *
     * @param string $provider one of self::PROVIDERS
     * @return array<string, mixed>|null
     */
    public static function get_cached(string $provider): ?array {
        $raw = get_config('local_artqtml', self::cache_key($provider));
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || !is_array($decoded['models'] ?? null)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Whether the cached list is present and still within its 24-hour lifetime.
     *
     * @param string $provider
     * @return bool
     */
    public static function is_cache_fresh(string $provider): bool {
        $cached = self::get_cached($provider);

        return $cached !== null && (time() - (int) ($cached['fetchedat'] ?? 0)) < self::CACHE_TTL;
    }

    /**
     * Fetch from the provider and replace the cache.
     *
     * Only the "Refresh models" button and the scheduled model check call this.
     *
     * On failure the previous cache content is deliberately left in place (annex, "Hibakezelés":
     * "Ha a lekérés meghiúsul, a korábbi gyorsítótár-tartalom marad érvényben"), so a transient
     * Provider outage does not empty the dropdown of an admin who is mid-configuration.
     *
     * @param string $provider one of self::PROVIDERS
     * @return array{success: bool, models: array, error: string}
     */
    public static function refresh(string $provider): array {
        $apikey = api_key_store::get($provider === self::PROVIDER_CLAUDE ? 'claude' : 'gemini');
        if ($apikey === '') {
            return ['success' => false, 'models' => [], 'error' => get_string('errormissingapikey', 'local_artqtml')];
        }

        $result = $provider === self::PROVIDER_CLAUDE
            ? self::fetch_claude($apikey)
            : self::fetch_gemini($apikey);

        if (!$result['success']) {
            return $result;
        }

        set_config(self::cache_key($provider), json_encode([
            'models'    => $result['models'],
            'fetchedat' => time(),
            'error'     => '',
        ]), 'local_artqtml');

        return $result;
    }

    /**
     * The models a dropdown may offer: cached, and structured-output capable only.
     *
     * @param string $provider
     * @return array<string, string> model id => display label, ready for a select
     */
    public static function selectable_options(string $provider): array {
        $cached = self::get_cached($provider);
        if ($cached === null) {
            return [];
        }

        $excluded = model_check_log::excluded_models($provider);

        $options = [];
        foreach ($cached['models'] as $model) {
            if (in_array((string) $model['id'], $excluded, true)) {
                continue;
            }
            if (empty($model['supports_structured_output'])) {
                continue;
            }
            $id = (string) $model['id'];
            $label = (string) ($model['display_name'] ?? '');
            $options[$id] = ($label !== '' && $label !== $id) ? $label . ' (' . $id . ')' : $id;
        }

        ksort($options);

        return $options;
    }

    /**
     * Whether a model id is present and selectable in the cached list.
     *
     * @param string $provider
     * @param string $modelid
     * @return bool
     */
    public static function is_listed(string $provider, string $modelid): bool {
        return $modelid !== '' && array_key_exists($modelid, self::selectable_options($provider));
    }

    /**
     * Anthropic GET /v1/models, following the after_id cursor to the end.
     *
     * @param string $apikey
     * @return array{success: bool, models: array, error: string}
     */
    protected static function fetch_claude(string $apikey): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $timeout = (int) (get_config('local_artqtml', 'apitimeout') ?: 60);
        $models = [];
        $afterid = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $url = 'https://api.anthropic.com/v1/models?limit=' . self::ANTHROPIC_PAGE_SIZE;
            if ($afterid !== null) {
                $url .= '&after_id=' . rawurlencode($afterid);
            }

            $curl = new \curl();
            $curl->setHeader(['x-api-key: ' . $apikey, 'anthropic-version: 2023-06-01']);
            $body = (string) $curl->get($url, [], [
                'CURLOPT_TIMEOUT' => $timeout, 'CURLOPT_CONNECTTIMEOUT' => 15,
            ]);
            $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);

            if ($httpcode !== 200) {
                return ['success' => false, 'models' => [], 'error' => self::error_message($body, $httpcode)];
            }

            $decoded = json_decode($body, true);
            $data = is_array($decoded) ? ($decoded['data'] ?? []) : [];
            if (!is_array($data) || $data === []) {
                break;
            }

            foreach ($data as $model) {
                $id = (string) ($model['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $models[] = [
                    'id'           => $id,
                    'display_name' => (string) ($model['display_name'] ?? $id),
                    // The annex names capabilities.structured_outputs as the filter basis. Absent
                    // Means "not advertised", which is treated as unsupported rather than assumed.
                    'supports_structured_output' => !empty($model['capabilities']['structured_outputs']),
                ];
                $afterid = $id;
            }

            if (empty($decoded['has_more'])) {
                break;
            }
        }

        return ['success' => true, 'models' => $models, 'error' => ''];
    }

    /**
     * Is this Gemini model id one of the non-text modalities?
     *
     * Public so the check is asserted directly rather than through a live API call - the filter it
     * Guards is the difference between a sweep that finishes and one that does not.
     *
     * @param string $id the bare model id, without the "models/" prefix
     * @return bool
     */
    public static function is_non_text_gemini_model(string $id): bool {
        foreach (self::GEMINI_NON_TEXT_MARKERS as $marker) {
            if (strpos($id, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gemini's model list endpoint, following the pageToken cursor.
     *
     * @param string $apikey
     * @return array{success: bool, models: array, error: string}
     */
    protected static function fetch_gemini(string $apikey): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $timeout = (int) (get_config('local_artqtml', 'apitimeout') ?: 60);
        $models = [];
        $pagetoken = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200';
            if ($pagetoken !== null) {
                $url .= '&pageToken=' . rawurlencode($pagetoken);
            }

            $curl = new \curl();
            $curl->setHeader(['x-goog-api-key: ' . $apikey]);
            $body = (string) $curl->get($url, [], [
                'CURLOPT_TIMEOUT' => $timeout, 'CURLOPT_CONNECTTIMEOUT' => 15,
            ]);
            $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);

            if ($httpcode !== 200) {
                return ['success' => false, 'models' => [], 'error' => self::error_message($body, $httpcode)];
            }

            $decoded = json_decode($body, true);
            $data = is_array($decoded) ? ($decoded['models'] ?? []) : [];
            if (!is_array($data) || $data === []) {
                break;
            }

            foreach ($data as $model) {
                // Gemini prefixes ids with "models/"; the settings store the bare id, which is also
                // What the generateContent URL is built from.
                $id = preg_replace('#^models/#', '', (string) ($model['name'] ?? ''));
                if ($id === '') {
                    continue;
                }
                if (self::is_non_text_gemini_model($id)) {
                    continue;
                }
                $methods = (array) ($model['supportedGenerationMethods'] ?? []);
                $models[] = [
                    'id'           => $id,
                    'display_name' => (string) ($model['displayName'] ?? $id),
                    // The annex filters Gemini "a támogatott generálási metódusok alapján".
                    // GenerateContent is the method the plugin's structured-output calls use.
                    'supports_structured_output' => in_array('generateContent', $methods, true),
                ];
            }

            $pagetoken = (string) ($decoded['nextPageToken'] ?? '');
            if ($pagetoken === '') {
                break;
            }
        }

        return ['success' => true, 'models' => $models, 'error' => ''];
    }

    /**
     * A short, user-safe error message from a provider error body.
     *
     * @param string $body
     * @param int $httpcode
     * @return string
     */
    protected static function error_message(string $body, int $httpcode): string {
        $decoded = json_decode($body, true);
        $message = (string) ($decoded['error']['message'] ?? '');

        return $message !== ''
            ? \core_text::substr($message, 0, 300)
            : get_string('errorapirequest', 'local_artqtml', 'HTTP ' . $httpcode);
    }

    /**
     * Config key holding a provider's cached list.
     *
     * @param string $provider
     * @return string
     */
    protected static function cache_key(string $provider): string {
        return 'modellistcache_' . $provider;
    }
}
