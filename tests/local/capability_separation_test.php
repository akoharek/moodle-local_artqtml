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

defined('MOODLE_INTERNAL') || die();

/**
 * Pins the mutual exclusion of local/artqtml:use and local/artqtml:configure.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class capability_separation_test extends \advanced_testcase {
    /** @var \context_system */
    private $context;

    /**
     * Fresh capability slate.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->context = \context_system::instance();
    }

    /**
     * Assign only the named ArtQTML capabilities (independent of archetypes).
     *
     * @param \stdClass $user
     * @param string[] $capabilities
     * @return void
     */
    private function grant_capabilities(\stdClass $user, array $capabilities): void {
        $roleid = create_role('artqtml capsep ' . $user->id, 'artqtmlsep' . $user->id, '');
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $this->context->id);
        }
        role_assign($roleid, $user->id, $this->context->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Use alone grants use and never configure.
     */
    public function test_use_only_has_use_not_configure(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->grant_capabilities($user, ['local/artqtml:use']);

        $this->assertTrue(has_capability('local/artqtml:use', $this->context, $user));
        $this->assertFalse(has_capability('local/artqtml:configure', $this->context, $user));
    }

    /**
     * Configure alone grants configure and never use.
     */
    public function test_configure_only_has_configure_not_use(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->grant_capabilities($user, ['local/artqtml:configure']);

        $this->assertTrue(has_capability('local/artqtml:configure', $this->context, $user));
        $this->assertFalse(has_capability('local/artqtml:use', $this->context, $user));
    }

    /**
     * Both capabilities may be held together; each still means what it means alone.
     */
    public function test_both_capabilities_are_independent(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->grant_capabilities($user, ['local/artqtml:use', 'local/artqtml:configure']);

        $this->assertTrue(has_capability('local/artqtml:use', $this->context, $user));
        $this->assertTrue(has_capability('local/artqtml:configure', $this->context, $user));
    }

    /**
     * Generation entry scripts require :use (never :configure as a substitute).
     */
    public function test_generation_entry_scripts_require_use(): void {
        $root = dirname(__DIR__, 2);
        $scripts = [
            'index.php',
            'upload.php',
            'generate.php',
            'approve.php',
            'status.php',
            'delete.php',
            'retrytypes.php',
        ];

        foreach ($scripts as $name) {
            $source = file_get_contents($root . '/' . $name);
            $this->assertStringContainsString(
                "require_capability('local/artqtml:use'",
                $source,
                "$name must require local/artqtml:use"
            );
            // No alternate gate that lets configure-only through in place of use.
            $this->assertDoesNotMatchRegularExpression(
                "/require_capability\\('local\\/artqtml:configure'/",
                $source,
                "$name must not require :configure as its entry gate (generation flow)"
            );
        }
    }

    /**
     * Admin entry scripts require :configure (directly or via admin_externalpage_setup).
     */
    public function test_admin_entry_scripts_require_configure(): void {
        $root = dirname(__DIR__, 2);

        $modelaction = file_get_contents($root . '/modelaction.php');
        $this->assertStringContainsString("require_capability('local/artqtml:configure'", $modelaction);
        $this->assertStringNotContainsString("require_capability('local/artqtml:use'", $modelaction);

        $settings = file_get_contents($root . '/settings.php');
        $this->assertStringContainsString("'local/artqtml:configure'", $settings);
        $this->assertMatchesRegularExpression(
            "/has_capability\\('local\\/artqtml:configure'/",
            $settings,
            'settings.php must register for :configure holders, not only hassiteconfig'
        );
    }

    /**
     * External functions declare the matching capability area.
     */
    public function test_external_functions_declare_correct_capabilities(): void {
        $root = dirname(__DIR__, 2);
        $services = file_get_contents($root . '/db/services.php');

        $this->assertMatchesRegularExpression(
            "/local_artqtml_get_status.*?capabilities'\\s*=>\\s*'local\\/artqtml:use'/s",
            $services
        );
        $this->assertMatchesRegularExpression(
            "/local_artqtml_extract_text.*?capabilities'\\s*=>\\s*'local\\/artqtml:use'/s",
            $services
        );
        $this->assertMatchesRegularExpression(
            "/local_artqtml_test_connection.*?capabilities'\\s*=>\\s*'local\\/artqtml:configure'/s",
            $services
        );

        $getstatus = file_get_contents($root . '/classes/external/get_status.php');
        $this->assertStringContainsString("require_capability('local/artqtml:use'", $getstatus);

        $extract = file_get_contents($root . '/classes/external/extract_text.php');
        $this->assertStringContainsString("require_capability('local/artqtml:use'", $extract);

        $testconn = file_get_contents($root . '/classes/external/test_connection.php');
        $this->assertStringContainsString("require_capability('local/artqtml:configure'", $testconn);
        $this->assertStringNotContainsString("require_capability('local/artqtml:use'", $testconn);
    }

    /**
     * Global navigation link is :use only — configure-only users must not get a generations entry.
     */
    public function test_navigation_is_gated_on_use_not_configure(): void {
        $hooks = file_get_contents(dirname(__DIR__, 2) . '/classes/hook_callbacks.php');
        $this->assertMatchesRegularExpression(
            "/function extend_primary_navigation.*?has_capability\\('local\\/artqtml:use'/s",
            $hooks
        );
    }

    /**
     * Capabilities declare non-zero Moodle RISK bitmasks (security-policy follow-up).
     */
    public function test_capabilities_declare_risk_bitmasks(): void {
        global $CFG;
        require($CFG->dirroot . '/local/artqtml/db/access.php');

        $this->assertSame(
            RISK_SPAM | RISK_XSS | RISK_PERSONAL,
            $capabilities['local/artqtml:use']['riskbitmask']
        );
        $this->assertSame(
            RISK_CONFIG | RISK_XSS | RISK_DATALOSS | RISK_PERSONAL,
            $capabilities['local/artqtml:configure']['riskbitmask']
        );
    }
}
