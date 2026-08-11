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
 * A rolled-back attempt keeps its log row and loses its warning.
 *
 * Glob-040 says no log row is deleted, ever - and the abort path deleted the token-limit warnings
 * anyway. Removing the delete on its own would have been wrong in the other direction: the warning
 * is read back for the screen, so a leftover row would warn about an attempt that no longer exists.
 * Both halves are asserted here, because either one alone is a defect.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\token_budget
 */
final class token_budget_rollback_test extends \advanced_testcase {
    /**
     * Write one token-limit warning log row for a generation.
     *
     * @param int $generationid
     * @param array $data the row's data blob
     * @return int the log row id
     */
    protected function log_warning(int $generationid, array $data): int {
        global $DB;

        return (int) $DB->insert_record('local_artqtml_log', (object) [
            'generationid' => $generationid,
            'event'        => 'token_limit_warning',
            'data'         => json_encode($data),
            'timecreated'  => time(),
        ]);
    }

    /**
     * Before the rollback the warning shows; after it, the row is still there and the warning is not.
     */
    public function test_a_rolled_back_warning_survives_as_a_row_but_leaves_the_screen(): void {
        global $DB;

        $this->resetAfterTest();

        $generationid = 987654;
        $logid = $this->log_warning($generationid, ['stage' => 'generate', 'requested' => 6, 'actual' => 2]);

        $this->assertNotSame('', token_budget::warning_message($generationid));

        token_budget::mark_rolled_back($generationid);

        // Glob-040: the row is not deleted.
        $this->assertTrue($DB->record_exists('local_artqtml_log', ['id' => $logid]));

        // And it no longer speaks for the next attempt.
        $this->assertSame('', token_budget::warning_message($generationid));

        // The flag is in the data blob; the stage and its numbers are untouched.
        $data = json_decode((string) $DB->get_field('local_artqtml_log', 'data', ['id' => $logid]), true);
        $this->assertNotEmpty($data[token_budget::ROLLED_BACK_KEY]);
        $this->assertSame('generate', $data['stage']);
        $this->assertSame(6, $data['requested']);
    }

    /**
     * A warning logged after the rollback is shown again.
     *
     * The point of the flag is that it marks one attempt, not the generation - otherwise an abort
     * would silence every later warning for that generation, which is a quieter version of the same
     * defect.
     */
    public function test_a_warning_from_the_next_attempt_is_shown(): void {
        $this->resetAfterTest();

        $generationid = 987655;
        $this->log_warning($generationid, ['stage' => 'generate', 'requested' => 6, 'actual' => 2]);
        token_budget::mark_rolled_back($generationid);
        $this->assertSame('', token_budget::warning_message($generationid));

        $this->log_warning($generationid, ['stage' => 'validate', 'affected' => 3]);

        $this->assertNotSame('', token_budget::warning_message($generationid));
    }
}
