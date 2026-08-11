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
 * The model blocking state (Admin-055, Admin-065, Glob-036).
 *
 * Admin-055 is the load-bearing rule: "A blokkoló állapotot kizárólag a modellellenőrzés írja -
 * akár ütemezett, akár kézi futásról van szó. A normál generálási forgalom soha nem állítja be, még
 * HTTP 404 esetén sem." So exactly two callers may write here, both in model_checker; a 404 during
 * generation puts that generation into the error state (Admin-058) and touches nothing else.
 *
 * Two distinct blocked situations exist and Admin-065 requires the warning to tell them apart,
 * because they need different administrator actions:
 *
 *  - NOT CONFIGURED: no model is set at all. Since Admin-051 removed the factory defaults, this is
 *    the state of every fresh install, and the fix is to choose a model.
 *  - UNUSABLE: a model is set but the check found it withdrawn or the API structure changed. The
 *    fix is to choose a different model.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * Reads and writes the per-provider blocking state.
 */
class model_blocking {
    /** @var string no model is configured for this provider (Admin-065). */
    public const REASON_NOT_CONFIGURED = 'notconfigured';

    /** @var string a model is configured but the check found it unusable (Glob-036). */
    public const REASON_UNUSABLE = 'unusable';

    /**
     * Record that a provider's model check failed (Admin-055).
     *
     * @param string $provider
     * @param string $model the model that failed, for the message
     * @param string $checktype model_check_log::CHECK_*
     * @param string $errorcode the AIQ-YYYYMMDD-XXXX code from the log entry
     * @return void
     */
    public static function block(string $provider, string $model, string $checktype, string $errorcode): void {
        set_config(self::key($provider), json_encode([
            'reason'    => self::REASON_UNUSABLE,
            'model'     => $model,
            'checktype' => $checktype,
            'errorcode' => $errorcode,
            'since'     => time(),
        ]), 'local_artqtml');
    }

    /**
     * Clear a provider's blocking state after a successful check (Admin-054/055).
     *
     * @param string $provider
     * @return void
     */
    public static function clear(string $provider): void {
        unset_config(self::key($provider), 'local_artqtml');
    }

    /**
     * The blocking state for one provider, or null when it is not blocked.
     *
     * The "no model configured" case is derived rather than stored: it is a fact about the setting,
     * so deriving it means it cannot go stale against the setting the way a stored copy would.
     *
     * @param string $provider
     * @return array{reason: string, model: string, checktype: string, errorcode: string, since: int}|null
     */
    public static function state(string $provider): ?array {
        $model = (string) get_config('local_artqtml', $provider === model_list::PROVIDER_CLAUDE ? 'claudemodel' : 'geminimodel');
        if ($model === '') {
            // Admin-065: an unset model is a blocking state in its own right, and since Admin-051
            // removed the factory defaults this is what a fresh install looks like.
            return [
                'reason'    => self::REASON_NOT_CONFIGURED,
                'model'     => '',
                'checktype' => '',
                'errorcode' => '',
                'since'     => 0,
            ];
        }

        $raw = get_config('local_artqtml', self::key($provider));
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Whether either provider is blocked - i.e. whether new generations may start at all.
     *
     * @return bool
     */
    public static function is_blocked(): bool {
        foreach (model_list::PROVIDERS as $provider) {
            if (self::state($provider) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The warning messages to show, one per blocked provider (Glob-036, Admin-065).
     *
     * @return string[]
     */
    public static function messages(): array {
        $messages = [];

        foreach (model_list::PROVIDERS as $provider) {
            $state = self::state($provider);
            if ($state === null) {
                continue;
            }

            $key = $provider === model_list::PROVIDER_CLAUDE ? 'modelblocked_generator' : 'modelblocked_validator';
            if ($state['reason'] === self::REASON_NOT_CONFIGURED) {
                $key = $provider === model_list::PROVIDER_CLAUDE
                    ? 'modelnotconfigured_generator'
                    : 'modelnotconfigured_validator';
                $messages[] = get_string($key, 'local_artqtml');
                continue;
            }

            // Admin-056/057: name the affected model and carry the traceable error code.
            $messages[] = get_string($key, 'local_artqtml', $state['errorcode']);
        }

        return $messages;
    }

    /**
     * Config key holding one provider's blocking state.
     *
     * @param string $provider
     * @return string
     */
    protected static function key(string $provider): string {
        return 'modelblocked_' . $provider;
    }
}
