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
 * Scheduled task that drives the AI generation/validation pipeline in the background
 * Runs every 5 minutes by default via cron, and can also be run
 * On demand - `admin/cli/scheduled_task.php --execute='\local_artqtml\task\process_pending_generations'`
 * - which is the supported way to get near-instant processing during manual testing instead of
 * Waiting for the next scheduled tick.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\task;

/**
 * Finds every generation currently waiting on an AI call and runs it through to completion.
 */
class process_pending_generations extends \core\task\scheduled_task {
    /**
     * Task name shown in the admin scheduled task list.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_process_pending_generations', 'local_artqtml');
    }

    /**
     * Execute the task.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        [$statussql, $statusparams] = \local_artqtml\local\generation_status::in_progress_sql();
        $pending = $DB->get_records_select(
            'local_artqtml_generations',
            $statussql . ' AND processingtoken IS NULL',
            $statusparams,
            'timecreated ASC'
        );

        $apitimeout = (int) (get_config('local_artqtml', 'apitimeout') ?: 60);
        $httpcycle = (generate_questions_task::MAX_HTTP_ATTEMPTS * $apitimeout)
            + generate_questions_task::MAX_BACKOFF_SECONDS;
        $pertaskbudget = (int) ceil(
            ($httpcycle * generate_questions_task::MAX_JSON_ATTEMPTS)   // Claude stage.
            + ($httpcycle * validate_questions_task::MAX_JSON_ATTEMPTS) // Gemini stage.
            + 30                                                        // Annex's fixed headroom.
        );
        set_time_limit($pertaskbudget * max(1, count($pending)));

        foreach ($pending as $generation) {
            $claimed = $this->claim((int) $generation->id);
            if ($claimed === null) {
                // Lost the race to another concurrent run (e.g. overlapping cron tick or a
                // manually triggered admin/cli/scheduled_task.php run) - skip it.
                continue;
            }

            $generationid = (int) $generation->id;

            // C-01: a plain try/finally only protects against catchable Throwables - a true PHP
            // fatal partway through process_one() (max_execution_time exceeded, memory
            // exhaustion) terminates the script without ever running the finally block, which
            // would leave processingtoken set forever and permanently block that generation from
            // ever being claimed again. register_shutdown_function() is the one mechanism PHP
            // guarantees still runs even after such a fatal, so it is the actual safety net here;
            // the $released guard just stops the normal-completion path from releasing twice.
            $released = false;
            $release = function () use (&$released, $generationid): void {
                if (!$released) {
                    $released = true;
                    $this->release($generationid);
                }
            };
            register_shutdown_function($release);

            try {
                $this->process_one($claimed);
            } finally {
                $release();
            }
        }
    }

    /**
     * Atomically claim a generation for processing by this run: only succeeds if the row is
     * Still unclaimed (processingtoken IS NULL) at the moment the UPDATE executes, so two
     * Concurrent runs racing on the same row can never both win (C-02).
     *
     * @param int $generationid
     * @return \stdClass|null the freshly claimed record, or null if another run claimed it first
     */
    protected function claim(int $generationid): ?\stdClass {
        global $DB;

        $token = random_string(32);

        $DB->execute(
            "UPDATE {local_artqtml_generations} SET processingtoken = ? WHERE id = ? AND processingtoken IS NULL",
            [$token, $generationid]
        );

        // Only the run whose UPDATE actually matched (WHERE processingtoken IS NULL) leaves the
        // row carrying its own token - a concurrent run's UPDATE affects zero rows once this one
        // has committed, so re-selecting by our own token is how we tell whether we won.
        return $DB->get_record('local_artqtml_generations', ['id' => $generationid, 'processingtoken' => $token]) ?: null;
    }

    /**
     * Release a generation's processing claim once this run is done with it (whatever the
     * Outcome), so a future tick can claim it again if it somehow needs another pass.
     *
     * @param int $generationid
     * @return void
     */
    protected function release(int $generationid): void {
        global $DB;

        $DB->set_field('local_artqtml_generations', 'processingtoken', null, ['id' => $generationid]);
    }

    /**
     * @param \stdClass $generation
     * @return void
     */
    protected function process_one(\stdClass $generation): void {
        global $DB;

        if ($generation->status === \local_artqtml\local\generation_status::GENERATING) {
            (new generate_questions_task())->process($generation);
            $generation = $DB->get_record('local_artqtml_generations', ['id' => $generation->id]);
        }

        if ($generation && $generation->status === \local_artqtml\local\generation_status::VALIDATING) {
            (new validate_questions_task())->process($generation);
            $generation = $DB->get_record('local_artqtml_generations', ['id' => $generation->id]);
        }

        if ($generation && $generation->status === \local_artqtml\local\generation_status::SAVING) {
            (new save_questions_task())->process($generation);
        }
    }
}
