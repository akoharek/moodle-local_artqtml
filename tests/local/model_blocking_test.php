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
 * Unit tests for the model blocking state.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\model_blocking
 */
final class model_blocking_test extends \advanced_testcase {
    /**
     * Set both models so the derived "not configured" state is out of the way.
     *
     * @return void
     */
    protected function configure_models(): void {
        set_config('claudemodel', 'claude-opus-4-8', 'local_artqtml');
        set_config('geminimodel', 'gemini-3.5-flash', 'local_artqtml');
    }

    public function test_unset_model_blocks_with_its_own_message(): void {
        $this->resetAfterTest();

        set_config('claudemodel', '', 'local_artqtml');
        set_config('geminimodel', '', 'local_artqtml');

        $this->assertTrue(model_blocking::is_blocked());

        $state = model_blocking::state(model_list::PROVIDER_CLAUDE);
        $this->assertNotNull($state);
        $this->assertSame(model_blocking::REASON_NOT_CONFIGURED, $state['reason']);

        $messages = model_blocking::messages();
        $this->assertCount(2, $messages);
        // Expect the "not configured" wording, not the "unusable" wording.
        $this->assertStringContainsString('No generator model is configured', $messages[0]);
        $this->assertStringNotContainsString('unusable', $messages[0]);
    }

    /**
     * With both models set and no failure recorded, nothing is blocked.
     */
    public function test_configured_and_healthy_is_not_blocked(): void {
        $this->resetAfterTest();
        $this->configure_models();

        $this->assertFalse(model_blocking::is_blocked());
        $this->assertNull(model_blocking::state(model_list::PROVIDER_CLAUDE));
        $this->assertSame([], model_blocking::messages());
    }

    /**
 * a blocked provider names the model and carries the traceable code.
 */
    public function test_block_records_reason_model_and_code(): void {
        $this->resetAfterTest();
        $this->configure_models();

        model_blocking::block(
            model_list::PROVIDER_GEMINI,
            'gemini-3.5-flash',
            model_check_log::CHECK_STRUCTURE,
            'AIQ-20260726-0042'
        );

        $this->assertTrue(model_blocking::is_blocked());
        $state = model_blocking::state(model_list::PROVIDER_GEMINI);
        $this->assertSame(model_blocking::REASON_UNUSABLE, $state['reason']);
        $this->assertSame('gemini-3.5-flash', $state['model']);
        $this->assertSame('AIQ-20260726-0042', $state['errorcode']);

        $messages = model_blocking::messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('validator model is unusable', $messages[0]);
        $this->assertStringContainsString('AIQ-20260726-0042', $messages[0]);

        // The generator is unaffected - the state is per provider.
        $this->assertNull(model_blocking::state(model_list::PROVIDER_CLAUDE));
    }

    /**
 * a successful check clears it again.
 */
    public function test_clear_unblocks(): void {
        $this->resetAfterTest();
        $this->configure_models();

        model_blocking::block(
            model_list::PROVIDER_CLAUDE,
            'claude-opus-4-8',
            model_check_log::CHECK_AVAILABILITY,
            'AIQ-20260726-0001'
        );
        $this->assertTrue(model_blocking::is_blocked());

        model_blocking::clear(model_list::PROVIDER_CLAUDE);
        $this->assertFalse(model_blocking::is_blocked());
        $this->assertNull(model_blocking::state(model_list::PROVIDER_CLAUDE));
    }

    /**
 * A static scan, because the rule is about who may call block()/clear() - the generation and
 * validation tasks must not, no matter what HTTP status they see.
 */
    public function test_only_the_model_checker_writes_the_blocking_state(): void {
        $root = realpath(__DIR__ . '/../..');
        $allowed = [
            $root . '/classes/local/model_blocking.php', // The class itself.
            $root . '/classes/local/model_checker.php', // 's sole permitted writer.
        ];

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/classes'));
        foreach ($iterator as $fileinfo) {
            $path = $fileinfo->getPathname();
            if (substr($path, -4) !== '.php' || in_array($path, $allowed, true)) {
                continue;
            }
            if (preg_match('/model_blocking::(block|clear)\s*\(/', file_get_contents($path))) {
                $offenders[] = str_replace($root . '/', '', $path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'only the model check may set or clear the blocking state' . implode(', ', $offenders)
        );
    }

    /**
     * A corrupt stored state degrades to "not blocked" rather than throwing on every page.
     */
    public function test_corrupt_state_is_treated_as_unblocked(): void {
        $this->resetAfterTest();
        $this->configure_models();

        set_config('modelblocked_claude', 'not json', 'local_artqtml');
        $this->assertNull(model_blocking::state(model_list::PROVIDER_CLAUDE));
        $this->assertFalse(model_blocking::is_blocked());
    }
}
