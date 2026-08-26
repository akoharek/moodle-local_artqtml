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
 * Creates a real Moodle question object from AI-generated question data.
 *
 * The implementation now lives in four focused classes under local_artqtml\local\question\*
 * (semantic validator / form builder / creator / multichoice fraction normalizer); this class
 * Remains as the stable public facade its three callers keep using unchanged:
 * Save_questions_task (create/validate) and observer.php
 * (recompute_multichoice_fractions). The three public method signatures below are the contract -
 * Do not add new logic here: delegate to the appropriate question\* class instead.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

use local_artqtml\local\question\question_semantic_validator;
use local_artqtml\local\question\question_creator;
use local_artqtml\local\question\multichoice_fraction_normalizer;

defined('MOODLE_INTERNAL') || die();

/**
 * Stable public facade for AI-question semantic validation, creation and post-edit normalisation.
 */
class question_importer {
    /**
     * Semantic validation of AI-generated question data, run before it is ever imported.
     *
     * @param string $typecode IH/FE/SR
     * @param array $data decoded per-type fields from the AI response
     * @param array $typesettings this type's generation settings (only SR's sritemcount override
     * Is read -; optional to preserve the existing two-argument callers
     * @return string|null null if valid, otherwise a short human-readable reason it was rejected
     */
    public static function validate(string $typecode, array $data, array $typesettings = []): ?string {
        return question_semantic_validator::validate($typecode, $data, $typesettings);
    }

    /**
     * Create a new question in the given category from AI-generated data.
     *
     * @param string $typecode IH/FE/SR
     * @param array $data decoded per-type fields from the AI response
     * @param int $categoryid target question_categories.id (the generation's draft bank)
     * @param string $questioncode plugin-generated name, e.g. BIO1-IH-0001
     * @param array $typesettings this type's generation settings (feedback/retry/negation)
     * @param int $userid the generation's owner
     * @param int $generationid only used to attribute a truncation log entry
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
        return question_creator::create(
            $typecode,
            $data,
            $categoryid,
            $questioncode,
            $typesettings,
            $userid,
            $generationid
        );
    }

    /**
     * recompute multichoice fractions.
     *
     * @param int $questionid the real question.id (local_artqtml_questions.questionbankid)
     * @return void
     */
    public static function recompute_multichoice_fractions(int $questionid): void {
        multichoice_fraction_normalizer::recompute($questionid);
    }
}
