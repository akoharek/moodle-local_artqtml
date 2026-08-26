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
 * Partial-panel reasons are read from existing log rows, not from a new column.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\partial_reason
 */
final class partial_reason_test extends \advanced_testcase {
    /**
     * Create a generation with the given per-type requested counts.
     *
     * @param array<string, int> $counts
     * @return int generation id
     */
    protected function make_generation(array $counts): int {
        global $DB;

        $now = time();
        return (int) $DB->insert_record('local_artqtml_generations', (object) [
            'userid'       => 2,
            'name'         => 'Partial reason fixture',
            'shortname'    => 'PART',
            'status'       => generation_status::PARTIAL,
            'settings'     => json_encode(['counts' => $counts]),
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Insert one lifecycle log row.
     *
     * @param int $generationid
     * @param string $event
     * @param array $data
     * @return void
     */
    protected function log_event(int $generationid, string $event, array $data): void {
        global $DB;

        $DB->insert_record('local_artqtml_log', (object) [
            'generationid' => $generationid,
            'userid'       => 2,
            'event'        => $event,
            'data'         => json_encode($data),
            'timecreated'  => time(),
        ]);
    }

    /**
     * A content failure for one type becomes a usable sentence naming that type.
     */
    public function test_content_failure_is_named(): void {
        $this->resetAfterTest();

        $id = $this->make_generation(['SR' => 12, 'FE' => 3]);
        $this->log_event($id, 'type_generation_failed', [
            'typecode' => 'SR',
            'kind'     => 'content',
            'message'  => 'whatever the exception said',
        ]);

        $messages = partial_reason::messages($id);
        $this->assertSame(
            [get_string('generationpartialreasoncontent', 'local_artqtml', question_types::label('SR'))],
            $messages
        );
    }

    /**
     * Semantic rejects are counted per type without leaking the English technical reason.
     */
    public function test_rejects_are_counted_without_raw_reason(): void {
        $this->resetAfterTest();

        $id = $this->make_generation(['FE' => 6]);
        $this->log_event($id, 'question_rejected', [
            'typecode' => 'FE',
            'reason'   => 'multichoice (FE): expected exactly 1 correct option, got 0',
        ]);
        $this->log_event($id, 'question_rejected', [
            'typecode' => 'FE',
            'reason'   => 'multichoice (FE): expected exactly 1 correct option, got 0',
        ]);

        $messages = partial_reason::messages($id);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('2', $messages[0]);
        $this->assertStringNotContainsString('multichoiceset', $messages[0]);
        $this->assertStringNotContainsString('expected at least 2 correct', $messages[0]);
    }

    /**
     * A type failure wins over rejects for the same type - one cause, not two.
     */
    public function test_failure_outranks_rejects_for_the_same_type(): void {
        $this->resetAfterTest();

        $id = $this->make_generation(['FE' => 3]);
        $this->log_event($id, 'type_generation_failed', [
            'typecode' => 'FE',
            'kind'     => 'transport',
            'message'  => 'HTTP 503',
        ]);
        $this->log_event($id, 'question_rejected', [
            'typecode' => 'FE',
            'reason'   => 'multichoice (FE): blank option text',
        ]);

        $messages = partial_reason::messages($id);
        $this->assertCount(1, $messages);
        $this->assertSame(
            get_string('generationpartialreasontransport', 'local_artqtml', question_types::label('FE')),
            $messages[0]
        );
    }

    /**
     * Claude returned some, but fewer than asked - and nothing was rejected afterwards.
     */
    public function test_undershoot_from_claude_outcomes(): void {
        $this->resetAfterTest();

        $id = $this->make_generation(['IH' => 6]);
        $this->log_event($id, 'claude_call_completed', [
            'questioncount' => 2,
            'outcomes'      => [
                'IH' => ['result' => 'ok', 'count' => 2],
            ],
        ]);

        $messages = partial_reason::messages($id);
        $this->assertCount(1, $messages);
        $this->assertSame(
            get_string('generationpartialreasonundershoot', 'local_artqtml', (object) [
                'type'   => question_types::label('IH'),
                'got'    => 2,
                'wanted' => 6,
            ]),
            $messages[0]
        );
    }

    /**
     * No relevant log rows means an empty reason block - the count line already covers the gap.
     */
    public function test_no_logs_means_no_reason(): void {
        $this->resetAfterTest();

        $id = $this->make_generation(['IH' => 4]);
        $this->assertSame([], partial_reason::messages($id));
        $this->assertSame('', partial_reason::render($id));
    }

    /**
     * Render wraps the lines in a list the status panel can drop in as-is.
     */
    public function test_render_wraps_messages(): void {
        $this->resetAfterTest();

        $id = $this->make_generation(['SR' => 1]);
        $this->log_event($id, 'type_generation_failed', [
            'typecode' => 'SR',
            'kind'     => 'content',
            'message'  => 'empty',
        ]);

        $html = partial_reason::render($id);
        $this->assertStringContainsString('data-region="partial-reasons"', $html);
        $this->assertStringContainsString(get_string('generationpartialreasonheading', 'local_artqtml'), $html);
        $this->assertStringContainsString('<li>', $html);
    }
}
