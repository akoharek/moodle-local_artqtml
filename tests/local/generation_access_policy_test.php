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
 * Unit tests for generation mutation authorisation.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\generation_access_policy
 */
final class generation_access_policy_test extends \advanced_testcase {
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
     * Assign only the named ArtQTM capabilities to a user at system context.
     *
     * @param \stdClass $user
     * @param string[] $capabilities frankenstyle capability names
     * @return void
     */
    private function grant_capabilities(\stdClass $user, array $capabilities): void {
        $roleid = create_role('artqtm mutate test ' . $user->id, 'artqtmmut' . $user->id, '');
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $this->context->id);
        }
        role_assign($roleid, $user->id, $this->context->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Owner with :use may mutate.
     */
    public function test_owner_with_use_can_mutate(): void {
        $owner = $this->getDataGenerator()->create_user();
        $this->grant_capabilities($owner, ['local/artqtml:use']);
        $this->setUser($owner);

        $generation = (object) ['userid' => (int) $owner->id];

        $this->assertTrue(generation_access_policy::can_mutate($generation, null, $this->context));
        generation_access_policy::require_can_mutate($generation, $this->context);
        $this->assertTrue(true);
    }

    /**
     * Non-owner with :use alone may not mutate.
     */
    public function test_non_owner_with_use_cannot_mutate(): void {
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->grant_capabilities($other, ['local/artqtml:use']);
        $this->setUser($other);

        $generation = (object) ['userid' => (int) $owner->id];

        $this->assertFalse(generation_access_policy::can_mutate($generation, null, $this->context));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('cannotmutateothers', 'local_artqtml'));
        generation_access_policy::require_can_mutate($generation, $this->context);
    }

    /**
     * Non-owner with :manageall may mutate.
     */
    public function test_manageall_can_mutate_others(): void {
        $owner = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        $this->grant_capabilities($manager, ['local/artqtml:use', 'local/artqtml:manageall']);
        $this->setUser($manager);

        $generation = (object) ['userid' => (int) $owner->id];

        $this->assertTrue(generation_access_policy::can_mutate($generation, null, $this->context));
        generation_access_policy::require_can_mutate($generation, $this->context);
        $this->assertTrue(true);
    }

    /**
     * generate.php view/read is gated by can_mutate (S2-01 residual).
     *
     * Non-owners with :use alone may open status/approve for collaboration but must not open
     * another user's STARTED draft on the settings page.
     */
    public function test_non_owner_cannot_open_settings_page(): void {
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->grant_capabilities($other, ['local/artqtml:use']);
        $this->setUser($other);

        $generation = (object) [
            'userid' => (int) $owner->id,
            'status' => generation_status::STARTED,
        ];

        $this->assertFalse(generation_access_policy::can_mutate($generation, null, $this->context));
    }

    /**
     * :configure alone never authorises mutation.
     */
    public function test_configure_only_cannot_mutate(): void {
        $owner = $this->getDataGenerator()->create_user();
        $this->grant_capabilities($owner, ['local/artqtml:configure']);
        $this->setUser($owner);

        $generation = (object) ['userid' => (int) $owner->id];

        $this->assertFalse(generation_access_policy::can_mutate($generation, null, $this->context));

        $this->expectException(\required_capability_exception::class);
        generation_access_policy::require_can_mutate($generation, $this->context);
    }
}
