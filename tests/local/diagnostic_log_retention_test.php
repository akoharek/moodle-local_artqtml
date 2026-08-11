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
 * Unit tests for the diagnostic payload lifecycle.
 *
 * The assertion running through all of these is the same one: the log ROW survives everything,
 * the PAYLOAD does not. Glob-040 keeps the record of what the plugin did; nothing decided that the
 * teacher's material inside it should be kept forever too.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\diagnostic_log_retention
 */
final class diagnostic_log_retention_test extends \advanced_testcase {
    /**
     * Insert a log row.
     *
     * @param string $event
     * @param array|string|null $data the data field, encoded if an array is given
     * @param int|null $timecreated
     * @param int|null $userid
     * @return int the row id
     */
    protected function make_log($event, $data, ?int $timecreated = null, ?int $userid = null): int {
        global $DB;

        return (int) $DB->insert_record('local_artqtml_log', (object) [
            'event'       => $event,
            'userid'      => $userid,
            'data'        => is_array($data) ? json_encode($data) : $data,
            'timecreated' => $timecreated ?? time(),
        ]);
    }

    /**
     * A full diagnostics payload as the pipeline writes it.
     *
     * @return array
     */
    protected function full_payload(): array {
        return [
            'calltype'     => 'generate',
            'jsonattempt'  => 1,
            'httpstatus'   => 200,
            'curlerror'    => '',
            'systemprompt' => 'SYSTEMPROMPT_SENTINEL',
            'schema'       => ['type' => 'object'],
            'responsebody' => 'RESPONSEBODY_SENTINEL',
        ];
    }

    /**
     * The retention period falls back to the default rather than to "forever".
     */
    public function test_the_retention_period_never_becomes_unlimited(): void {
        $this->resetAfterTest();

        unset_config('diagnosticretentiondays', 'local_artqtml');
        $this->assertSame(30, diagnostic_log_retention::retention_days());

        foreach ([0, -5, 'nonsense', ''] as $bad) {
            set_config('diagnosticretentiondays', $bad, 'local_artqtml');
            $this->assertSame(
                diagnostic_log_retention::DEFAULT_RETENTION_DAYS,
                diagnostic_log_retention::retention_days(),
                'a misconfigured value must not mean "keep forever"'
            );
        }

        set_config('diagnosticretentiondays', 7, 'local_artqtml');
        $this->assertSame(7, diagnostic_log_retention::retention_days());
    }

    /**
     * Redaction removes the heavy keys and keeps the technical record.
     */
    public function test_redaction_removes_the_payload_and_keeps_the_record(): void {
        $decoded = json_decode(diagnostic_log_retention::redact_data(json_encode($this->full_payload())), true);

        $this->assertArrayNotHasKey('systemprompt', $decoded);
        $this->assertArrayNotHasKey('schema', $decoded);
        $this->assertArrayNotHasKey('responsebody', $decoded);

        $this->assertSame('generate', $decoded['calltype']);
        $this->assertSame(1, $decoded['jsonattempt']);
        $this->assertSame(200, $decoded['httpstatus']);
        $this->assertArrayHasKey('curlerror', $decoded);

        $this->assertTrue($decoded['payloadredacted']);
        $this->assertIsInt($decoded['payloadredactedat']);
    }

    /**
     * Redacting twice changes nothing, including the timestamp.
     *
     * The daily task passes over the same rows for as long as they exist. Rewriting the timestamp
     * each night would destroy the only record of when the payload actually went, and would
     * rewrite every old row every night for nothing.
     */
    public function test_redaction_is_idempotent(): void {
        $once = diagnostic_log_retention::redact_data(json_encode($this->full_payload()), 1000);
        $twice = diagnostic_log_retention::redact_data($once, 2000);

        $this->assertSame($once, $twice);
        $this->assertSame(1000, json_decode($twice, true)['payloadredactedat']);
    }

    /**
     * Malformed data does not throw, and is not preserved on the grounds of being unreadable.
     */
    public function test_malformed_data_is_replaced_rather_than_kept(): void {
        $result = diagnostic_log_retention::redact_data('{"systemprompt": "SENTINEL", broken');

        $this->assertStringNotContainsString('SENTINEL', $result);

        $decoded = json_decode($result, true);
        $this->assertTrue($decoded['payloadredacted']);
        $this->assertSame('invalid_json', $decoded['payloadredactionreason']);
    }

    /**
     * The sweep redacts expired diagnostics and leaves everything else alone.
     */
    public function test_the_sweep_touches_only_expired_diagnostics(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('diagnosticretentiondays', 30, 'local_artqtml');

        $now = time();
        $old = $this->make_log('diagnostics_call', $this->full_payload(), $now - (40 * DAYSECS));
        $recent = $this->make_log('diagnostics_call', $this->full_payload(), $now - (5 * DAYSECS));
        $otherevent = $this->make_log('ai_call_made', $this->full_payload(), $now - (40 * DAYSECS));

        $changed = diagnostic_log_retention::purge_expired($now);

        $this->assertSame(1, $changed);

        // Expired: payload gone, row still there.
        $this->assertTrue($DB->record_exists('local_artqtml_log', ['id' => $old]));
        $this->assertStringNotContainsString(
            'SYSTEMPROMPT_SENTINEL',
            $DB->get_field('local_artqtml_log', 'data', ['id' => $old])
        );

        // Not yet expired: still fully available for debugging.
        $this->assertStringContainsString(
            'SYSTEMPROMPT_SENTINEL',
            $DB->get_field('local_artqtml_log', 'data', ['id' => $recent])
        );

        // A different event: not this class's business, whatever its age.
        $this->assertStringContainsString(
            'SYSTEMPROMPT_SENTINEL',
            $DB->get_field('local_artqtml_log', 'data', ['id' => $otherevent])
        );
    }

    /**
     * Running the sweep again finds nothing left to do.
     */
    public function test_the_sweep_is_idempotent(): void {
        $this->resetAfterTest();
        set_config('diagnosticretentiondays', 30, 'local_artqtml');

        $now = time();
        $this->make_log('diagnostics_call', $this->full_payload(), $now - (40 * DAYSECS));

        $this->assertSame(1, diagnostic_log_retention::purge_expired($now));
        $this->assertSame(0, diagnostic_log_retention::purge_expired($now));
    }

    /**
     * A deletion request anonymises the row and empties the payload, without deleting either.
     *
     * Nulling the user id alone was what the provider used to do, and it was not enough: the row
     * still held the person's own uploaded material in the payload.
     */
    public function test_a_users_rows_are_anonymised_and_redacted_but_kept(): void {
        global $DB;

        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $diagnostics = $this->make_log('diagnostics_call', $this->full_payload(), time(), (int) $user->id);
        $plain = $this->make_log('generation_started', ['x' => 1], time(), (int) $user->id);
        $other = $this->make_log('diagnostics_call', $this->full_payload(), time(), 999999);

        $changed = diagnostic_log_retention::redact_for_user((int) $user->id);

        $this->assertSame(2, $changed);

        foreach ([$diagnostics, $plain] as $id) {
            $row = $DB->get_record('local_artqtml_log', ['id' => $id], '*', MUST_EXIST);
            $this->assertNull($row->userid);
        }

        $this->assertStringNotContainsString(
            'SYSTEMPROMPT_SENTINEL',
            $DB->get_field('local_artqtml_log', 'data', ['id' => $diagnostics])
        );
        // A non-diagnostics event's data is not this class's to rewrite.
        $this->assertSame('{"x":1}', $DB->get_field('local_artqtml_log', 'data', ['id' => $plain]));

        // Another user's row is untouched.
        $this->assertSame(999999, (int) $DB->get_field('local_artqtml_log', 'userid', ['id' => $other]));
    }

    /**
     * The site-wide sweep anonymises everything and deletes nothing.
     */
    public function test_the_site_wide_sweep_keeps_every_row(): void {
        global $DB;

        $this->resetAfterTest();

        $this->make_log('diagnostics_call', $this->full_payload(), time(), 5);
        $this->make_log('generation_started', ['x' => 1], time(), 6);
        $this->make_log('licence_violation', null, time(), null);

        $before = $DB->count_records('local_artqtml_log');
        diagnostic_log_retention::redact_all();

        $this->assertSame($before, $DB->count_records('local_artqtml_log'));
        $this->assertSame(0, $DB->count_records_select('local_artqtml_log', 'userid IS NOT NULL'));
        $like = $DB->sql_like('data', ':needle');
        $this->assertSame(
            0,
            $DB->count_records_select('local_artqtml_log', $like, ['needle' => '%SYSTEMPROMPT_SENTINEL%'])
        );
    }
}
