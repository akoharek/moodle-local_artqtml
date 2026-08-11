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

namespace local_artqtml\local\license;

/**
 * Unit tests for the license status/business policy (functional spec ch.10, Lic-004-011).
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\license\license_status_policy
 */
final class license_status_policy_test extends \advanced_testcase {
    /**
     * Invoke one of the protected per-edition status helpers directly, so the activation-window
     * arithmetic can be tested without needing a live, correctly-signed license on disk.
     *
     * @param string $method annual_status|question_limit_status
     * @param \stdClass $record
     * @return array
     */
    protected function invoke_status(string $method, \stdClass $record): array {
        $reflection = new \ReflectionMethod(license_status_policy::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke(null, $record);
    }

    /**
     * With no license ever uploaded, the state is "none".
     */
    public function test_status_none_when_no_license(): void {
        $this->resetAfterTest();
        $status = license_status_policy::status();
        $this->assertSame('none', $status['state']);
    }

    /**
     * A stored license with an unverifiable signature is "invalid".
     */
    public function test_status_invalid_when_signature_broken(): void {
        global $DB;
        $this->resetAfterTest();

        $record = license_persistence::get_or_create_record();
        $record->edition = 'perpetual';
        $record->licensejson = json_encode(['edition' => 'perpetual', 'signature' => 'bogus']);
        $DB->update_record('local_artqtml_license', $record);

        $this->assertSame('invalid', license_status_policy::status()['state']);
    }

    /**
     * Lic-005 (C4): an annual license is valid for at most 365 days from activation, even if its
     * signed expires_at is further out - the effective expiry is capped at activatedat + 365 days.
     */
    public function test_annual_effective_expiry_capped_at_activation_plus_365(): void {
        $this->resetAfterTest();

        $record = (object) [
            'activatedat' => time(),
            // Signed expiry is ~400 days out, but the activation cap should win at ~365 days.
            'expiresat'   => time() + (400 * DAYSECS),
        ];

        $status = $this->invoke_status('annual_status', $record);

        $this->assertSame('valid', $status['state']);
        // Capped at activatedat + YEARSECS -> ~365 days remaining, definitely under 400.
        $this->assertLessThanOrEqual(366, $status['daysremaining']);
        $this->assertGreaterThanOrEqual(364, $status['daysremaining']);
    }

    /**
     * Once the activation cap is in the past the annual license reads as expired, even though its
     * signed expires_at is still in the future.
     */
    public function test_annual_expired_past_activation_cap(): void {
        $this->resetAfterTest();

        $record = (object) [
            'activatedat' => time() - (400 * DAYSECS),
            'expiresat'   => time() + (400 * DAYSECS),
        ];

        $this->assertSame('expired', $this->invoke_status('annual_status', $record)['state']);
    }

    /**
     * When the signed expires_at is earlier than the activation cap it wins, and a near expiry
     * raises the warning flag (Lic-007).
     */
    public function test_annual_warning_when_close_to_expiry(): void {
        $this->resetAfterTest();
        set_config('licenseannualwarningdays', 30, 'local_artqtml');

        $record = (object) [
            'activatedat' => time(),
            'expiresat'   => time() + (10 * DAYSECS),
        ];

        $status = $this->invoke_status('annual_status', $record);
        $this->assertSame('valid', $status['state']);
        $this->assertTrue($status['warning']);
        $this->assertLessThanOrEqual(10, $status['daysremaining']);
    }

    /**
     * A question_limit license is exhausted once the validated counter reaches the limit (Lic-006).
     */
    public function test_question_limit_exhausted_at_limit(): void {
        $this->resetAfterTest();

        $atlimit = $this->invoke_status('question_limit_status', (object) [
            'questionsvalidated' => 100,
            'questionlimit'      => 100,
        ]);
        $this->assertSame('exhausted', $atlimit['state']);

        $under = $this->invoke_status('question_limit_status', (object) [
            'questionsvalidated' => 40,
            'questionlimit'      => 100,
        ]);
        $this->assertSame('valid', $under['state']);
        $this->assertSame(60, $under['remaining']);
    }

    /**
     * Lic-011: the lifetime counter increments atomically, and is clamped at the limit for a
     * question_limit edition so a single over-limit batch can never push it past the cap.
     */
    public function test_increment_validated_clamps_at_limit(): void {
        global $DB;
        $this->resetAfterTest();

        $record = license_persistence::get_or_create_record();
        $record->edition = 'question_limit';
        $record->questionlimit = 100;
        $record->questionsvalidated = 0;
        $DB->update_record('local_artqtml_license', $record);

        license_status_policy::increment_validated(40);
        $this->assertSame(40, (int) $DB->get_field('local_artqtml_license', 'questionsvalidated', ['id' => $record->id]));

        // A batch that would overshoot is clamped to exactly the limit.
        license_status_policy::increment_validated(1000);
        $this->assertSame(100, (int) $DB->get_field('local_artqtml_license', 'questionsvalidated', ['id' => $record->id]));
    }

    /**
     * For a non-question_limit edition the counter is a plain lifetime tally with no clamp.
     */
    public function test_increment_validated_unbounded_for_perpetual(): void {
        global $DB;
        $this->resetAfterTest();

        $record = license_persistence::get_or_create_record();
        $record->edition = 'perpetual';
        $DB->update_record('local_artqtml_license', $record);

        license_status_policy::increment_validated(5);
        license_status_policy::increment_validated(7);
        $this->assertSame(12, (int) $DB->get_field('local_artqtml_license', 'questionsvalidated', ['id' => $record->id]));

        // A zero/negative count is a no-op.
        license_status_policy::increment_validated(0);
        $this->assertSame(12, (int) $DB->get_field('local_artqtml_license', 'questionsvalidated', ['id' => $record->id]));
    }

    /**
     * Lic-009: "none" (and every other blocking state) blocks starting a new generation.
     */
    public function test_is_blocked_when_no_license(): void {
        $this->resetAfterTest();
        $this->assertTrue(license_status_policy::is_blocked());
    }

    /**
     * Lic-025: the banner names the expiry date, so the status has to carry it - and it must be
     * the EFFECTIVE expiry, not the signed one, because the activatedat + 365 cap can move it
     * earlier. Asserting the signed date here would pass while the banner showed a later date
     * than the licence actually runs to.
     */
    public function test_annual_status_carries_the_effective_expiry(): void {
        $this->resetAfterTest();

        $activated = time() - (10 * DAYSECS);
        $status = $this->invoke_status('annual_status', (object) [
            'activatedat' => $activated,
            // Deliberately later than the activation cap, so the cap is what must win.
            'expiresat'   => $activated + (2 * YEARSECS),
        ]);

        $this->assertSame('valid', $status['state']);
        $this->assertArrayHasKey('expiresat', $status);
        $this->assertSame($activated + YEARSECS, $status['expiresat']);
    }

    /**
     * Invoke one of the protected site-matching helpers.
     *
     * @param string $method
     * @param string $arg
     * @return mixed
     */
    protected function invoke_site(string $method, string $arg) {
        $reflection = new \ReflectionMethod(license_status_policy::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke(null, $arg);
    }

    /**
     * Lic-029: the licence is bound to a site by host, not by the literal URL string.
     *
     * The point of the data set is the tolerated differences. A licence issued before the site
     * moved to https, or written with a trailing slash or a "www." prefix, still belongs to that
     * site - locking the customer out over any of those would be a defect, not enforcement.
     */
    public function test_site_matching_compares_hosts_not_url_strings(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->wwwroot = 'https://demo453.elearning.co.hu';

        $matching = [
            'https://demo453.elearning.co.hu',
            'http://demo453.elearning.co.hu',
            'https://demo453.elearning.co.hu/',
            'https://www.demo453.elearning.co.hu',
            'https://demo453.elearning.co.hu/moodle',
            'demo453.elearning.co.hu',
            'DEMO453.Elearning.CO.HU',
        ];
        foreach ($matching as $url) {
            $this->assertTrue($this->invoke_site('site_matches', $url), "should match: $url");
        }

        $notmatching = [
            'https://other.elearning.co.hu',
            'https://demo452.elearning.co.hu',
            'https://elearning.co.hu',
            '',
        ];
        foreach ($notmatching as $url) {
            $this->assertFalse($this->invoke_site('site_matches', $url), "should not match: $url");
        }
    }

    /**
     * A licence with no usable host must not pass. Returning true on an empty value would turn the
     * whole check off for exactly the licences that carry no site at all.
     */
    public function test_site_matching_fails_closed_on_an_empty_site_url(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->wwwroot = '';

        $this->assertFalse($this->invoke_site('site_matches', 'https://demo453.elearning.co.hu'));
    }

    /**
     * Lic-028: the generation form asks how many questions are left before letting a run start.
     *
     * The null case is the point: a perpetual or annual licence caps nothing, and returning 0 there
     * would block every generation on those editions - the failure would look like an exhausted
     * quota on a licence that has none.
     */
    public function test_remaining_questions_is_null_without_a_question_cap(): void {
        $this->resetAfterTest();

        // No licence at all: not a question_limit edition, so there is no cap to report.
        $this->assertNull(license_status_policy::remaining_questions());
    }

    /**
     * Lic-026/Lic-027: the banner states the used percentage, so the status carries it, and the
     * threshold's shipped default is 80. The 79/80 pair is the point of the test - a default that
     * silently drifted to 90 would still pass a single "warns at 95%" assertion.
     */
    public function test_question_limit_status_carries_used_percentage_and_warns_at_the_default(): void {
        $this->resetAfterTest();

        $at = $this->invoke_status('question_limit_status', (object) [
            'questionsvalidated' => 80,
            'questionlimit'      => 100,
        ]);
        $this->assertSame(80, $at['usedpct']);
        $this->assertTrue($at['warning'], 'the shipped default threshold is 80%');

        $under = $this->invoke_status('question_limit_status', (object) [
            'questionsvalidated' => 79,
            'questionlimit'      => 100,
        ]);
        $this->assertSame(79, $under['usedpct']);
        $this->assertFalse($under['warning']);
    }
}
