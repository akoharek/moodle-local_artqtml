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
 * Invoked from {@see process_pending_generations} once {@see generate_questions_task} and
 * {@see validate_questions_task} have both finished - see those classes for why the pipeline is
 * Split this way (generating/validating hold everything in $generation->pendingdata; nothing
 * Touches local_artqtml_questions or creates a real Moodle question until this stage runs).
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\task;

use local_artqtml\local\question_types;
use local_artqtml\local\question_importer;
use local_artqtml\local\difficulty_label;

/**
 * Writes a single generation's questions + validation results into local_artqtml_questions.
 */
class save_questions_task {
    use generation_status_trait;

    /**
     * Run the saving stage for one generation.
     *
     * @param \stdClass $generation the local_artqtml_generations record to save
     * @return void
     */
    public function process(\stdClass $generation): void {
        global $DB;

        $generationid = (int) $generation->id;
        $userid = (int) $generation->userid;

        try {
            $settings = json_decode((string) $generation->settings, true);
            if (!is_array($settings)) {
                throw new \moodle_exception('errormissingsettings', 'local_artqtml');
            }

            $pending = json_decode((string) $generation->pendingdata, true);
            if (!is_array($pending) || !is_array($pending['questions'] ?? null)) {
                throw new \moodle_exception('errormissingsettings', 'local_artqtml');
            }

            $rawquestions = $pending['questions'];
            $evaluations = is_array($pending['evaluations'] ?? null) ? $pending['evaluations'] : [];

            // The transaction is rolled back explicitly (and its exception rethrown) on any
            // Failure, so a partial batch of real questions/local_artqtml_questions rows is
            // Never left half-committed - the outer catch's cleanup then finds nothing to undo.
            $savedcount = 0;
            $transaction = $DB->start_delegated_transaction();
            try {
                $savedcount = $this->save_all($generation, $settings, $rawquestions, $evaluations);

                $generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid]);
                $generation->pendingdata = null;

                $shortfall = $this->store_save_discrepancy($generation, $settings, $userid);

                $this->set_status(
                    $generation,
                    $shortfall
                        ? \local_artqtml\local\generation_status::PARTIAL
                        : \local_artqtml\local\generation_status::COMPLETED
                );

                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }

            $this->log_event($generationid, 'generation_completed', ['questioncount' => $savedcount], $userid);

            \local_artqtml\event\generation_completed::create([
                'objectid' => $generationid,
                'context'  => \context_system::instance(),
            ])->trigger();
        } catch (\Throwable $e) {
            debugging('local_artqtml: saving for generation ' . $generationid . ' failed: ' . $e->getMessage(), DEBUG_NORMAL);
            $this->rollback($generationid, $e->getMessage(), $userid);
        }
    }

    /**
     * Helper.
     *
     * @param \stdClass $generation
     * @param array $settings decoded settings JSON
     * @param array $rawquestions raw question arrays as returned by Claude, keyed by pseudo-id
     * @param array $evaluations pseudo-id => evaluation fields map from validate_questions_task
     * @return int number of questions actually saved
     */
    /**
     * store save discrepancy.
     *
     * @param \stdClass $generation the generation, already reloaded inside the save transaction
     * @param array $settings decoded settings, holding the requested per-type counts
     * @param int $userid
     * @return bool true if fewer questions were saved than requested, for any type
     */
    protected function store_save_discrepancy(\stdClass $generation, array $settings, int $userid): bool {
        global $DB;

        $saved = [];
        foreach ($DB->get_records('local_artqtml_questions', ['generationid' => $generation->id], '', 'id, typecode') as $row) {
            $saved[$row->typecode] = ($saved[$row->typecode] ?? 0) + 1;
        }

        $discrepancies = [];
        foreach (question_types::CODES as $code) {
            $requested = (int) ($settings['counts'][$code] ?? 0);
            $received = (int) ($saved[$code] ?? 0);
            if ($requested !== $received) {
                $discrepancies[] = ['type' => $code, 'requested' => $requested, 'received' => $received];
            }
        }

        $generation->countdiscrepancy = $discrepancies === [] ? null : json_encode($discrepancies);
        $DB->set_field(
            'local_artqtml_generations',
            'countdiscrepancy',
            $generation->countdiscrepancy,
            ['id' => $generation->id]
        );

        if ($discrepancies === []) {
            return false;
        }

        $this->log_event($generation->id, 'save_count_discrepancy', ['discrepancies' => $discrepancies], $userid);

        // Only a shortfall makes a generation partly successful. More questions than requested is
        // Also a discrepancy worth showing - it happened once, 7 delivered against 6 asked for -
        // But the teacher has lost nothing, so the run is complete.
        foreach ($discrepancies as $entry) {
            if ($entry['received'] < $entry['requested']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Save every generated question into the question bank and record its validation verdict.
     *
     * @param \stdClass $generation the generation row being saved
     * @param array $settings the stored generation settings
     * @param array $rawquestions the questions returned by the model, keyed by pseudo id
     * @param array $evaluations the validator's verdicts, keyed by the same pseudo id
     * @return int the number of questions actually saved
     */
    protected function save_all(\stdClass $generation, array $settings, array $rawquestions, array $evaluations): int {
        global $DB;

        $userid = (int) $generation->userid;
        $sequence = array_fill_keys(question_types::CODES, 0);
        $savedcount = 0;

        foreach ($rawquestions as $pseudoid => $question) {
            if (!is_array($question)) {
                continue;
            }
            $typecode = $question['type'] ?? '';
            if (!in_array($typecode, question_types::CODES, true)) {
                continue;
            }

            $typesettings = $settings['types'][$typecode] ?? [];

            $rejectreason = question_importer::validate($typecode, $question, $typesettings);
            if ($rejectreason !== null) {
                $this->log_event($generation->id, 'question_rejected', [
                    'typecode' => $typecode,
                    'reason'   => $rejectreason,
                ], $userid);
                continue;
            }

            $sequence[$typecode]++;
            $questioncode = sprintf('%s-%s-%04d', $generation->shortname, $typecode, $sequence[$typecode]);

            $questionbankid = question_importer::create(
                $typecode,
                $question,
                (int) $generation->draftcategoryid,
                $questioncode,
                $typesettings,
                $userid,
                (int) $generation->id
            );

            $evaluation = $evaluations[$pseudoid] ?? null;

            $normaliseddifficulty = difficulty_label::normalise(
                isset($question['difficulty_label']) ? (string) $question['difficulty_label'] : null,
                difficulty_label::MEDIUM
            );
            $question['difficulty_label'] = $normaliseddifficulty;

            $record = new \stdClass();
            $record->generationid = $generation->id;
            $record->questioncode = $questioncode;
            $record->typecode = $typecode;
            $record->questiontype = question_types::QTYPE[$typecode];
            $record->questiontext = (string) ($question['questiontext'] ?? '');
            $record->difficultylabel = $normaliseddifficulty;
            $record->sourcereference = (string) ($question['source_reference'] ?? '');
            $record->questiondata = json_encode($question);
            $record->validationsuggestion = $evaluation['validationsuggestion'] ?? 'not_evaluated';
            $record->problemcategory = $evaluation['problemcategory'] ?? null;
            $record->justification = $evaluation['justification'] ?? null;
            $record->confidence = $evaluation['confidence'] ?? null;
            $record->validationdata = isset($evaluation['validationdata']) ? json_encode($evaluation['validationdata']) : null;
            $record->questionbankid = $questionbankid;
            $record->movedout = 0;
            $record->approved = 0;
            $record->edited = 0;
            $record->timecreated = time();

            $DB->insert_record('local_artqtml_questions', $record);
            $savedcount++;
        }

        return $savedcount;
    }

    /**
     * Full rollback on unrecoverable failure: delete any real questions already committed (there
     * Won't be any - the transaction in {@see self::process()} rolls those back itself - this is
     * Defensive, matching the same cleanup {@see generate_questions_task::rollback()} and
     * {@see validate_questions_task::process()}'s failure path use), delete the draft category,
     * And return the generation to a retryable failed state.
     *
     * @param int $generationid
     * @param string $errormessage
     * @param int|null $userid
     * @return void
     */
    protected function rollback(int $generationid, string $errormessage, ?int $userid = null): void {
        global $DB;

        $generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid]);
        if (!$generation) {
            return;
        }

        $transaction = $DB->start_delegated_transaction();
        if (!empty($generation->draftcategoryid)) {
            \local_artqtml\local\draft_bank::delete((int) $generation->draftcategoryid);
            $generation->draftcategoryid = null;
        }
        $DB->delete_records('local_artqtml_questions', ['generationid' => $generationid]);
        $transaction->allow_commit();

        $this->set_status($generation, \local_artqtml\local\generation_status::FAILED, $errormessage);
        $this->log_event($generationid, 'error', ['message' => $errormessage], $userid ?? (int) $generation->userid);
    }
}
