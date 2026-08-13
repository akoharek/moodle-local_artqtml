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
 * Test data generator for local_artqtml.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_artqtml\local\draft_bank;
use local_artqtml\local\draft_role;
use local_artqtml\local\duplicate_detector;
use local_artqtml\local\encrypted_config;
use local_artqtml\local\generation_status;
use local_artqtml\local\question\question_creator;
use local_artqtml\local\question_types;
use local_artqtml\local\validation_suggestion;

/**
 * Creates generations and draft questions without calling an LLM.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_artqtml_generator extends component_generator_base {
    /**
     * Enable the plugin, point it at a draft course, and store dummy models/keys.
     *
     * @return \stdClass the draft course
     */
    public function setup_for_teachers(): \stdClass {
        $course = $this->datagenerator->create_course([
            'fullname'  => 'ArtQTML draft',
            'shortname' => 'ARTQDRAFT',
            'visible'   => 0,
        ]);
        set_config('enabled', '1', 'local_artqtml');
        set_config('draftcourseid', (string) $course->id, 'local_artqtml');
        set_config('claudemodel', 'claude-sonnet-4-5', 'local_artqtml');
        set_config('geminimodel', 'gemini-2.5-flash', 'local_artqtml');
        set_config('claudeapikey', 'sk-ant-behat-test-key', 'local_artqtml');
        set_config('geminiapikey', 'behat-gemini-test-key', 'local_artqtml');
        encrypted_config::get('claudeapikey');
        encrypted_config::get('geminiapikey');
        draft_role::ensure_role();

        return $course;
    }

    /**
     * Insert a generation row. Creates a draft bank when the status needs one.
     *
     * @param array $record
     * @return \stdClass
     */
    public function create_generation(array $record): \stdClass {
        global $DB, $USER;

        $userid = (int) ($record['userid'] ?? $USER->id);
        $name = (string) ($record['name'] ?? 'Behat generation');
        $shortname = strtoupper((string) ($record['shortname'] ?? 'BEHAT1'));
        $status = (string) ($record['status'] ?? generation_status::COMPLETED);
        $sourcetext = (string) ($record['sourcetext'] ?? 'Behat source text about photosynthesis and plant cells.');
        $now = time();

        $settings = $record['settings'] ?? json_encode([
            'knowledgesource' => 'sourceonly',
            'matrix_IH_easy'  => 1,
            'matrix_FE_easy'  => 0,
            'matrix_SR_easy'  => 0,
        ]);
        if (is_array($settings)) {
            $settings = json_encode($settings);
        }

        $id = (int) $DB->insert_record('local_artqtml_generations', (object) [
            'userid'           => $userid,
            'name'             => $name,
            'shortname'        => $shortname,
            'sourcetext'       => $sourcetext,
            'sourcetexthash'   => duplicate_detector::hash($sourcetext),
            'status'           => $status,
            'settings'         => $settings,
            'error'            => $record['error'] ?? null,
            'countdiscrepancy' => $record['countdiscrepancy'] ?? null,
            'timecreated'      => $now,
            'timemodified'     => $now,
        ]);

        $generation = $DB->get_record('local_artqtml_generations', ['id' => $id], '*', MUST_EXIST);
        $needsdraft = in_array($status, [
            generation_status::COMPLETED,
            generation_status::PARTIAL,
            generation_status::GENERATING,
            generation_status::VALIDATING,
            generation_status::SAVING,
            generation_status::FAILED,
        ], true);

        if ($needsdraft && draft_bank::is_configured()) {
            $generation->draftcategoryid = draft_bank::create($generation);
            $DB->update_record('local_artqtml_generations', $generation);
            draft_role::grant($userid);
        }

        return $DB->get_record('local_artqtml_generations', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Create a course question category the approve page can offer as a move target.
     *
     * Uses core's generator so Moodle 5.1+ remaps the course context into a mod_qbank module.
     *
     * @param int $courseid
     * @param string $name
     * @return \stdClass
     */
    public function create_move_target_category(int $courseid, string $name = 'Biology questions'): \stdClass {
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->datagenerator->get_plugin_generator('core_question');

        return $questiongenerator->create_question_category([
            'contextid' => \context_course::instance($courseid)->id,
            'name'      => $name,
        ]);
    }

    /**
     * Insert a plugin question, optionally as a real Moodle question in the draft bank.
     *
     * @param array $record
     * @return \stdClass
     */
    public function create_question(array $record): \stdClass {
        global $DB;

        $generationid = (int) ($record['generationid'] ?? 0);
        if ($generationid < 1) {
            throw new coding_exception('create_question requires generationid');
        }
        $generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid], '*', MUST_EXIST);

        $typecode = (string) ($record['typecode'] ?? 'IH');
        $questiontext = (string) ($record['questiontext'] ?? 'Chlorophyll is used in photosynthesis.');
        $questioncode = (string) ($record['questioncode'] ?? $generation->shortname . '-' . $typecode . '-0001');
        $suggestion = (string) ($record['validationsuggestion'] ?? validation_suggestion::ACCEPTED);
        $createbank = !array_key_exists('createbank', $record) || !empty($record['createbank']);

        $questiondata = $record['questiondata'] ?? ['questiontext' => $questiontext, 'correctanswer' => true];
        if (is_array($questiondata)) {
            $questiondatajson = json_encode($questiondata);
            $dataarray = $questiondata;
        } else {
            $questiondatajson = (string) $questiondata;
            $dataarray = json_decode($questiondatajson, true) ?: ['questiontext' => $questiontext, 'correctanswer' => true];
        }

        $questionbankid = null;
        if ($createbank && !empty($generation->draftcategoryid)) {
            $questionbankid = question_creator::create(
                $typecode,
                $dataarray,
                (int) $generation->draftcategoryid,
                $questioncode,
                [],
                (int) $generation->userid,
                $generationid
            );
        }

        $approved = (int) ($record['approved'] ?? 0);
        $qid = (int) $DB->insert_record('local_artqtml_questions', (object) [
            'generationid'         => $generationid,
            'questioncode'         => $questioncode,
            'typecode'             => $typecode,
            'questiontype'         => question_types::QTYPE[$typecode] ?? 'truefalse',
            'questiontext'         => $questiontext,
            'difficultylabel'      => $record['difficultylabel'] ?? 'Easy',
            'questiondata'         => $questiondatajson,
            'validationsuggestion' => $suggestion,
            'problemcategory'      => $record['problemcategory'] ?? null,
            'justification'        => $record['justification'] ?? null,
            'confidence'           => $record['confidence'] ?? 80,
            'questionbankid'       => $questionbankid,
            'movedout'             => (int) ($record['movedout'] ?? 0),
            'approved'             => $approved,
            'approvedby'           => $approved ? (int) $generation->userid : null,
            'edited'               => 0,
            'timecreated'          => time(),
        ]);

        return $DB->get_record('local_artqtml_questions', ['id' => $qid], '*', MUST_EXIST);
    }
}
