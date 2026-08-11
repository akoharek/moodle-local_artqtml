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
 * Orchestrates creating a real Moodle question from AI-generated data, in a generation's
 * isolated draft bank category (Gen-005, technical annex ch.6) - split out of question_importer.
 *
 * Uses question_bank::get_qtype($qtype)->save_question(), the same stable entry point
 * Moodle's own question edit forms and import formats use.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\question;

use local_artqtml\local\question_types;

/**
 * Loads the category, resolves the qtype, builds the form and saves the real question.
 */
class question_creator {
    /**
     * Create a new question in the given category from AI-generated data.
     *
     * @param string $typecode IH/FE/FT/SR/EH/RV
     * @param array $data decoded per-type fields from the AI response (technical annex 3.3)
     * @param int $categoryid target question_categories.id (the generation's draft bank)
     * @param string $questioncode plugin-generated name, e.g. BIO1-IH-0001
     * @param array $typesettings this type's generation settings (feedback/retry/negation)
     * @param int $userid the generation's owner (Gen-005/M-06) - questions are created here from
     *      a background scheduled task with no logged-in user of its own, so $USER cannot be
     *      relied on for authorship; the caller must always pass the owning generation's userid
     * @param int $generationid Gen-026: only used to attribute a local_artqtml_log entry if
     *      generalfeedback needs truncating - not otherwise part of question creation
     * @return int the id of the newly created question table row
     */
    public static function create(
        string $typecode,
        array $data,
        int $categoryid,
        string $questioncode,
        array $typesettings,
        int $userid,
        int $generationid = 0
    ): int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/question/engine/bank.php');

        $category = $DB->get_record('question_categories', ['id' => $categoryid], '*', MUST_EXIST);

        $qtype = question_types::QTYPE[$typecode] ?? null;
        if ($qtype === null) {
            throw new \moodle_exception('errorunsupportedqtype', 'local_artqtml', '', $typecode);
        }

        $form = question_form_builder::build($typecode, $data, $category, $questioncode, $typesettings, $userid, $generationid);

        $question = new \stdClass();
        $question->qtype = $qtype;
        $question->createdby = $userid;
        $question->modifiedby = $userid;

        $qtypeobj = \question_bank::get_qtype($qtype);
        $saved = $qtypeobj->save_question($question, $form);

        return (int) $saved->id;
    }
}
