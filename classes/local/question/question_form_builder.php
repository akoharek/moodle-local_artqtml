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
 * Builds the form object Moodle's qtype save_question() API expects from AI-generated question
 * Data - the common skeleton, the per-type fields, hints, and the associated text helpers
 * (split out of question_importer - ).
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\question;

use local_artqtml\local\question_types;

defined('MOODLE_INTERNAL') || die();

/**
 * Maps AI-generated per-type question data onto the shape save_question() consumes.
 */
class question_form_builder {
    /**
     * Build the qtype-agnostic + qtype-specific "form" object save_question() expects.
     *
     * @param string $typecode
     * @param array $data decoded questiondata JSON
     * @param \stdClass $category the target question_categories record
     * @param string $questioncode
     * @param array $typesettings
     * @param int $userid
     * @param int $generationid
     * @return \stdClass
     */
    public static function build(
        string $typecode,
        array $data,
        \stdClass $category,
        string $questioncode,
        array $typesettings,
        int $userid,
        int $generationid = 0
    ): \stdClass {
        $feedbackenabled = !empty($typesettings['feedbackenabled']);

        $form = new \stdClass();
        $form->category = $category->id . ',' . $category->contextid;
        $form->name = $questioncode !== '' ? $questioncode : self::make_name($data['questiontext'] ?? '');
        $form->questiontext = ['text' => self::clean_ai_text($data['questiontext'] ?? ''), 'format' => FORMAT_HTML, 'itemid' => 0];
        $generalfeedback = $feedbackenabled
            ? self::truncate_feedback((string) ($data['generalfeedback'] ?? ''), $questioncode, $generationid, $userid)
            : '';
        $form->generalfeedback = [
            'text'   => self::clean_ai_text($generalfeedback),
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];
        $form->defaultmark = 1.0;
        $form->penalty = !empty($typesettings['retryenabled'])
            ? max(0, min(1, ((int) ($typesettings['retrypenalty'] ?? 33)) / 100))
            : 0.3333333;
        $form->length = 1;
        $form->idnumber = null;
        $form->createdby = $userid;

        switch ($typecode) {
            case 'IH':
                self::apply_truefalse($form, $data, $feedbackenabled);
                break;
            case 'FE':
                self::apply_multichoice($form, $data, $feedbackenabled);
                break;
            case 'SR':
                self::apply_ordering($form, $data);
                break;
        }

        if (question_types::supports_hints($typecode) && !empty($typesettings['hintenabled'])) {
            $hints = [];
            $hintclearwrong = [];
            $hintshownumcorrect = [];
            foreach (['hint1', 'hint2'] as $key) {
                $hinttext = trim(self::clean_ai_text($data[$key] ?? ''));
                if ($hinttext === '') {
                    continue;
                }
                $hints[] = ['text' => $hinttext, 'format' => FORMAT_HTML, 'itemid' => 0];
                // Only read by save_hints() when $withparts is true (multichoice/ordering) -
                // Harmless to always set for shortanswer too, where it's simply ignored.
                $hintclearwrong[] = 0;
                $hintshownumcorrect[] = 0;
            }

            if (!empty($hints)) {
                $form->hint = $hints;
                $form->hintclearwrong = $hintclearwrong;
                $form->hintshownumcorrect = $hintshownumcorrect;
            }
        }

        return $form;
    }

    /**
     * Reduce AI-generated text to plain text, keeping only <sub> and <sup>.
     *
     * The steps are ordered, and the order is the point:
     *
     * 1. Purify first. Beyond the security filtering, this is what makes step 3 safe: a stray
     * "<" in ordinary prose ("igaz-e, hogy x < 5") comes out of the purifier as "&lt;", so
     * Strip_tags() can no longer swallow the rest of the sentence as if it were a tag. Doing
     * It the other way round loses text, silently.
     * 2. Turn block boundaries into newlines BEFORE the tags go, or "<p>First</p><p>Second</p>"
     * comes out as "FirstSecond" - the same words-run-together defect fixed when block tags
     * Were stripped without inserting separators.
     * 3. Strip everything except the two kept tags.
     * 4. Normalise the whitespace this leaves behind.
     *
     * Known consequence, recorded rather than discovered later: paragraph breaks become plain
     * Newlines, and the field is still FORMAT_HTML, so a multi-paragraph explanation renders as
     * One paragraph. The words are all there; the paragraph structure is not.
     *
     * @param mixed $text expected string, but AI JSON output is trusted to have the right shape
     * @return string
     */
    protected static function clean_ai_text($text): string {
        return ai_text_cleaner::clean($text);
    }

    /**
     * truncate feedback.
     *
     * @param string $text
     * @param string $questioncode for the log entry, e.g. BIO1-IH-0001
     * @param int $generationid 0 to skip logging (no generation context available)
     * @param int $userid the generation's owner, for the log entry
     * @return string
     */
    protected static function truncate_feedback(string $text, string $questioncode, int $generationid, int $userid): string {
        if (\core_text::strlen($text) <= 250) {
            return $text;
        }

        $truncated = \core_text::substr($text, 0, 250);

        if ($generationid > 0) {
            global $DB;
            $DB->insert_record('local_artqtml_log', (object) [
                'generationid' => $generationid,
                'userid'       => $userid,
                'event'        => 'generalfeedback_truncated',
                'data'         => json_encode([
                    'questioncode'   => $questioncode,
                    'originallength' => \core_text::strlen($text),
                ]),
                'timecreated'  => time(),
            ]);
        }

        return $truncated;
    }

    /**
     * Derive a short question bank name from the question text (fallback if no code was set).
     *
     * @param string $questiontext
     * @return string
     */
    protected static function make_name(string $questiontext): string {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($questiontext)));
        $plain = \core_text::substr($plain, 0, 80);

        return $plain !== '' ? $plain : get_string('pluginname', 'local_artqtml');
    }

    /**
     * Populate qtype_truefalse-specific form fields.
     *
     * @param \stdClass $form
     * @param array $data expects boolean 'correctanswer'
     * @param bool $feedbackenabled
     * @return void
     */
    protected static function apply_truefalse(\stdClass $form, array $data, bool $feedbackenabled): void {
        $form->correctanswer = !empty($data['correctanswer']) ? 1 : 0;

        $explanationtrue = self::clean_ai_text($data['explanationtrue'] ?? '');
        $explanationfalse = self::clean_ai_text($data['explanationfalse'] ?? '');

        $form->feedbacktrue = [
            'text'   => $explanationtrue !== ''
                ? $explanationtrue
                : ($feedbackenabled ? self::feedback_template('IH', true) : ''),
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];
        $form->feedbackfalse = [
            'text'   => $explanationfalse !== ''
                ? $explanationfalse
                : ($feedbackenabled ? self::feedback_template('IH', false) : ''),
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];
    }

    /**
     * Populate qtype_multichoice-specific form fields for FE (single-answer).
     *
     * @param \stdClass $form
     * @param array $data expects 'options' => [['text' => ..., 'correct' => bool], ...]
     * @param bool $feedbackenabled
     * @return void
     */
    protected static function apply_multichoice(\stdClass $form, array $data, bool $feedbackenabled): void {
        $options = $data['options'] ?? [];

        $answers = [];
        $fractions = [];
        $feedbacks = [];
        foreach ($options as $option) {
            $answers[] = ['text' => self::clean_ai_text($option['text'] ?? ''), 'format' => FORMAT_HTML, 'itemid' => 0];
            $fractions[] = !empty($option['correct']) ? 1.0 : 0.0;
            $feedbacks[] = [
                'text'   => self::clean_ai_text($option['explanation'] ?? ''),
                'format' => FORMAT_HTML,
                'itemid' => 0,
            ];
        }

        $form->single = 1;
        $form->shuffleanswers = 1;
        $form->answernumbering = 'abc';
        $form->showstandardinstruction = 0;

        $form->correctfeedback = [
            'text'   => $feedbackenabled ? self::feedback_template('FE', true) : '',
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];
        $form->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $form->incorrectfeedback = [
            'text'   => $feedbackenabled ? self::feedback_template('FE', false) : '',
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];
        $form->shownumcorrect = 0;
        $form->answer = $answers;
        $form->fraction = $fractions;
        $form->feedback = $feedbacks;
    }

    /**
     * Populate qtype_ordering-specific form fields.
     *
     * Field names/defaults verified against Moodle 4.5 core source
     * (question/type/ordering/questiontype.php, question.php); qtype_ordering is bundled in
     * Core as of this version. save_question_options() recomputes 'fraction' internally.
     *
     * @param \stdClass $form
     * @param array $data expects 'items' => [{text: string}, ...] in correct order
     * @return void
     */
    protected static function apply_ordering(\stdClass $form, array $data): void {
        $items = $data['items'] ?? [];

        $answers = [];
        foreach ($items as $item) {
            $text = is_array($item) ? ($item['text'] ?? '') : $item;
            $answers[] = ['text' => self::clean_ai_text($text), 'format' => FORMAT_HTML, 'itemid' => 0];
        }

        $gradingtype = (int) (get_config('local_artqtml', 'orderinggradingtype') ?: 0);
        $numberingstyle = (string) (get_config('local_artqtml', 'orderingnumberingtype') ?: 'none');

        $form->answer = $answers;
        $form->layouttype = 0; // Means qtype_ordering_question::LAYOUT_VERTICAL.
        $form->selecttype = 0; // Means qtype_ordering_question::SELECT_ALL.
        $form->selectcount = 0; // 0 means "all items".
        $form->gradingtype = $gradingtype;
        $form->showgrading = 1;
        $form->numberingstyle = $numberingstyle;
        $form->correctfeedback = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $form->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $form->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
    }

    /**
     * Fetch the admin-configured feedback template for a type/correctness.
     *
     * @param string $typecode
     * @param bool $correct
     * @return string
     */
    protected static function feedback_template(string $typecode, bool $correct): string {
        $setting = 'feedback_' . strtolower($typecode) . '_' . ($correct ? 'correct' : 'incorrect');
        return (string) (get_config('local_artqtml', $setting) ?: '');
    }
}
