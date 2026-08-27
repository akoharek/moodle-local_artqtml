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
 * Unit tests for the source-editability rule.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\generation_edit_policy
 */
final class generation_edit_policy_test extends \advanced_testcase {
    /**
     * Draft is editable; every other status the plugin has is not.
     *
     * Driven from generation_status::VALUES rather than a hand-written list, so a status added
     * later is covered by this test on the day it is added rather than the day somebody
     * remembers to come back here.
     */
    public function test_only_a_draft_is_editable(): void {
        foreach (generation_status::VALUES as $status) {
            $editable = generation_edit_policy::can_edit_source((object) ['status' => $status]);

            if ($status === generation_status::STARTED) {
                $this->assertTrue($editable, 'a draft must stay editable');
            } else {
                $this->assertFalse($editable, "'$status' must not be editable from the upload page");
            }
        }
    }

    /**
     * An unknown or missing status is not editable.
     *
     * The rule is a whitelist for exactly this reason: anything the code does not recognise fails
     * closed. A deny-list would have let both of these through.
     */
    public function test_an_unrecognised_status_is_not_editable(): void {
        $this->assertFalse(generation_edit_policy::can_edit_source((object) ['status' => 'somethingnew']));
        $this->assertFalse(generation_edit_policy::can_edit_source((object) ['status' => '']));
        $this->assertFalse(generation_edit_policy::can_edit_source(new \stdClass()));
    }

    /**
     * The assertion form passes silently on a draft.
     */
    public function test_requiring_editability_passes_for_a_draft(): void {
        generation_edit_policy::require_source_editable((object) ['status' => generation_status::STARTED]);

        $this->assertTrue(true, 'no exception is thrown for a draft');
    }

    /**
     * And throws the specific exception - not a generic one - for everything else.
     *
     * The error code matters beyond tidiness: upload.php catches this one by code and turns it
     * into a redirect, and rethrows anything else. A generic exception here would be swallowed as
     * if it were this case.
     *
     * @dataProvider non_draft_status_provider
     * @param string $status
     */
    public function test_requiring_editability_throws_for_everything_else(string $status): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/draft/i');

        generation_edit_policy::require_source_editable((object) ['status' => $status]);
    }

    /**
     * Every status except the draft one.
     *
     * @return array<string, array{string}>
     */
    public static function non_draft_status_provider(): array {
        $cases = [];
        foreach (generation_status::VALUES as $status) {
            if ($status !== generation_status::STARTED) {
                $cases[$status] = [$status];
            }
        }

        return $cases;
    }

    /**
     * The rule looks at status and nothing else - ownership is enforced separately.
     */
    public function test_the_rule_does_not_consider_ownership(): void {
        $mine = (object) ['status' => generation_status::STARTED, 'userid' => 1];
        $theirs = (object) ['status' => generation_status::STARTED, 'userid' => 999];

        $this->assertSame(
            generation_edit_policy::can_edit_source($mine),
            generation_edit_policy::can_edit_source($theirs)
        );
        $this->assertTrue(generation_edit_policy::can_edit_source($theirs));
    }
}
