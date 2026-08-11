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
 * Unit tests for generation deletion authorisation.
 *
 * Product decision 2026-08-10: delete requires local/artqtml:use and ownership.
 * local/artqtml:configure never authorises deletion.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\generation_delete_policy
 */
final class generation_delete_policy_test extends \advanced_testcase {
    /** @var \context_system */
    private $context;

    /**
     * Fresh users and a clean capability slate for every test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->context = \context_system::instance();
    }

    /**
     * Assign only the named ArtQTML capabilities to a user at system context.
     *
     * Uses a fresh role each time so :use and :configure can be granted independently - the
     * manager archetype has both, which would blur the configure-only case.
     *
     * @param \stdClass $user
     * @param string[] $capabilities frankenstyle capability names
     * @return void
     */
    private function grant_capabilities(\stdClass $user, array $capabilities): void {
        $roleid = create_role('artqtml delete test ' . $user->id, 'artqtmldel' . $user->id, '');
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $this->context->id);
        }
        role_assign($roleid, $user->id, $this->context->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Owner with :use may delete.
     */
    public function test_owner_with_use_can_delete(): void {
        $owner = $this->getDataGenerator()->create_user();
        $this->grant_capabilities($owner, ['local/artqtml:use']);
        $this->setUser($owner);

        $generation = (object) ['userid' => (int) $owner->id];

        $this->assertTrue(generation_delete_policy::can_delete($generation, null, $this->context));
        generation_delete_policy::require_can_delete($generation, $this->context);
        $this->assertTrue(true, 'require_can_delete does not throw for the owner with :use');
    }

    /**
     * Non-owner with :use may not delete.
     */
    public function test_non_owner_with_use_cannot_delete(): void {
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->grant_capabilities($other, ['local/artqtml:use']);
        $this->setUser($other);

        $generation = (object) ['userid' => (int) $owner->id];

        $this->assertFalse(generation_delete_policy::can_delete($generation, null, $this->context));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/cannotdeleteothers|own generations/i');
        generation_delete_policy::require_can_delete($generation, $this->context);
    }

    /**
     * :configure alone never authorises deletion - even of the caller's own generation.
     */
    public function test_configure_only_cannot_delete(): void {
        $owner = $this->getDataGenerator()->create_user();
        $this->grant_capabilities($owner, ['local/artqtml:configure']);
        $this->setUser($owner);

        $generation = (object) ['userid' => (int) $owner->id];

        $this->assertFalse(generation_delete_policy::can_delete($generation, null, $this->context));

        $this->expectException(\required_capability_exception::class);
        generation_delete_policy::require_can_delete($generation, $this->context);
    }

    /**
     * Holding both :configure and :use does not unlock a colleague's generation.
     */
    public function test_configure_plus_use_non_owner_cannot_delete(): void {
        $owner = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        $this->grant_capabilities($manager, ['local/artqtml:use', 'local/artqtml:configure']);
        $this->setUser($manager);

        $generation = (object) ['userid' => (int) $owner->id];

        $this->assertFalse(generation_delete_policy::can_delete($generation, null, $this->context));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/cannotdeleteothers|own generations/i');
        generation_delete_policy::require_can_delete($generation, $this->context);
    }

    /**
     * Manager who owns the generation may delete - because of :use + ownership, not :configure.
     */
    public function test_configure_plus_use_owner_can_delete(): void {
        $manager = $this->getDataGenerator()->create_user();
        $this->grant_capabilities($manager, ['local/artqtml:use', 'local/artqtml:configure']);
        $this->setUser($manager);

        $generation = (object) ['userid' => (int) $manager->id];

        $this->assertTrue(generation_delete_policy::can_delete($generation, null, $this->context));
        generation_delete_policy::require_can_delete($generation, $this->context);
        $this->assertTrue(true, 'owner with both caps may delete via :use + ownership');
    }

    /**
     * Entry paths and the list UI must call the policy / not reintroduce a :configure delete branch.
     *
     * Static shape checks so a later edit that bypasses the policy fails without needing a browser.
     */
    public function test_entry_paths_and_list_ui_use_ownership_rule(): void {
        $root = dirname(__DIR__, 2);

        $delete = file_get_contents($root . '/delete.php');
        $this->assertStringContainsString('generation_delete_policy::require_can_delete', $delete);
        $this->assertStringContainsString("require_capability('local/artqtml:use'", $delete);
        // Comments may name :configure to forbid it; the entry gate must never require it.
        $this->assertDoesNotMatchRegularExpression(
            "/require_capability\\('local\\/artqtml:configure'/",
            $delete
        );

        $generate = file_get_contents($root . '/generate.php');
        $this->assertStringContainsString('generation_delete_policy::require_can_delete', $generate);

        $list = file_get_contents($root . '/classes/local/generation_list.php');
        $this->assertStringContainsString(
            "\$candelete = \$onlymine && has_capability('local/artqtml:use'",
            $list
        );
        $this->assertStringNotContainsString(
            "\$candelete = \$onlymine || has_capability('local/artqtml:configure'",
            $list
        );
    }
}
