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
 * data - the common skeleton, the per-type fields, hints, and the associated text helpers
 * (split out of question_importer - technical annex ch.6).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\question;

use local_artqtml\local\question_types;

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
        // M-25: shown regardless of correctness, unlike the per-type correct/incorrect feedback
        // templates below (which are admin-configured text, not AI-generated per question).
        // Gen-026: maxLength in question_schema.php is advisory only for Claude, so this is
        // truncated (and logged) defensively regardless of whether the model honoured it.
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
                self::apply_multichoice($form, $data, true, $feedbackenabled);
                break;
            case 'FT':
                self::apply_multichoice($form, $data, false, $feedbackenabled);
                break;
            case 'SR':
                self::apply_ordering($form, $data);
                break;
            case 'RV':
                self::apply_shortanswer($form, $data, $feedbackenabled);
                break;
            case 'EH':
                $form->penalty = 0;
                self::apply_essay($form, $data);
                break;
        }

        // M-24/Gen-022: only actually applied to the real question when this generation has the
        // per-type "hint" switch on - the AI is always asked for both hints (question_schema.php
        // requires them unconditionally, a static schema not varied per generation), but they're
        // otherwise simply left unused here.
        if (question_types::supports_hints($typecode) && !empty($typesettings['hintenabled'])) {
            // Gen-023/024: two progressive hints, matching Moodle's own multi-attempt hint
            // mechanism (question_hints, shown one per failed attempt) - hint1 is the general
            // nudge shown first, hint2 the more specific one shown if the student is still stuck.
            // Order is preserved even if one is blank (unlikely - both are required schema
            // fields) rather than only appending non-blank ones in encounter order.
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
                // harmless to always set for shortanswer too, where it's simply ignored.
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
     * BL-55: reduce AI-generated text to plain text, keeping only <sub> and <sup>.
     *
     * Why this is not just the sanitiser. Every field below already went through
     * clean_param(PARAM_CLEANHTML), which runs Moodle's HTML Purifier - and that is a SECURITY
     * filter: it removes script and other attackable markup, but deliberately keeps benign
     * formatting. Moodle does not narrow the purifier's allowed CSS properties, so a
     * `<span style="background-color: blue">` in the model's answer passed straight through and
     * reached the teacher's editor as real formatting. Decided by András, 2026-08-06: the
     * generator supplies wording, not appearance - anything the model dresses its text up in
     * gets removed here.
     *
     * <sub> and <sup> are the one exception, also his decision: they are not decoration but
     * meaning - H<sub>2</sub>O and m<sup>2</sup> are wrong without them.
     *
     * The steps are ordered, and the order is the point:
     *
     * 1. Purify first. Beyond the security filtering, this is what makes step 3 safe: a stray
     *    "<" in ordinary prose ("igaz-e, hogy x < 5") comes out of the purifier as "&lt;", so
     *    strip_tags() can no longer swallow the rest of the sentence as if it were a tag. Doing
     *    it the other way round loses text, silently.
     * 2. Turn block boundaries into newlines BEFORE the tags go, or "<p>Első</p><p>Második</p>"
     *    comes out as "ElsőMásodik" - the same words-run-together defect BL-48 fixed in the PDF
     *    reader.
     * 3. Strip everything except the two kept tags.
     * 4. Normalise the whitespace this leaves behind.
     *
     * Known consequence, recorded rather than discovered later: paragraph breaks become plain
     * newlines, and the field is still FORMAT_HTML, so a multi-paragraph explanation renders as
     * one paragraph. The words are all there; the paragraph structure is not.
     *
     * @param mixed $text expected string, but AI JSON output is trusted to have the right shape
     * @return string
     */
    protected static function clean_ai_text($text): string {
        return ai_text_cleaner::clean($text);
    }

    /**
     * Gen-026: enforce generalfeedback's 250-character limit server-side - question_schema.php's
     * maxLength is only advisory for Claude, not an enforced constraint, so this is the actual
     * backstop. Truncation is logged (not silently applied) since it means the model didn't
     * follow the schema, which may be worth an admin's attention if it happens often.
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
     * Populate qtype_truefalse-specific form fields (technical annex 6.2).
     *
     * @param \stdClass $form
     * @param array $data expects boolean 'correctanswer'
     * @param bool $feedbackenabled
     * @return void
     */
    protected static function apply_truefalse(\stdClass $form, array $data, bool $feedbackenabled): void {
        $form->correctanswer = !empty($data['correctanswer']) ? 1 : 0;

        // BL-29: True/False keeps its two answers' feedback in named fields rather than a list, so
        // the per-answer explanation lands here. When the AI wrote one it wins over the admin
        // template - that is the point of the switch, and it is a deliberate exception to
        // Admin-022: the template says the same sentence to every student of every True/False
        // question, and this says something about the statement they just judged.
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
     * Populate qtype_multichoice-specific form fields (technical annex 6.3).
     *
     * @param \stdClass $form
     * @param array $data expects 'options' => [['text' => ..., 'correct' => bool], ...]
     * @param bool $single true for FE (single-answer), false for FT (multi-answer)
     * @param bool $feedbackenabled
     * @return void
     */
    protected static function apply_multichoice(\stdClass $form, array $data, bool $single, bool $feedbackenabled): void {
        $options = $data['options'] ?? [];

        $correctcount = 0;
        foreach ($options as $option) {
            if (!empty($option['correct'])) {
                $correctcount++;
            }
        }
        $correctcount = max($correctcount, 1);

        $answers = [];
        $fractions = [];
        $feedbacks = [];
        foreach ($options as $option) {
            $answers[] = ['text' => self::clean_ai_text($option['text'] ?? ''), 'format' => FORMAT_HTML, 'itemid' => 0];
            $iscorrect = !empty($option['correct']);
            if ($single) {
                $fractions[] = $iscorrect ? 1.0 : 0.0;
            } else {
                $fractions[] = $iscorrect ? round(1.0 / $correctcount, 7) : 0.0;
            }
            // BL-29: Moodle's per-option feedback column, which every generation until 2026-08-02
            // filled with an empty string. When the teacher asked for explanations, this is where
            // the AI's sentence for THIS option goes - the one thing that tells a student why the
            // answer they picked is wrong. Absent (switch off, or an older stored question), it
            // stays empty and the behaviour is exactly what it was.
            $feedbacks[] = [
                'text'   => self::clean_ai_text($option['explanation'] ?? ''),
                'format' => FORMAT_HTML,
                'itemid' => 0,
            ];
        }

        $form->single = $single ? 1 : 0;
        $form->shuffleanswers = 1;
        $form->answernumbering = 'abc';
        $form->showstandardinstruction = 0;
        // FT (multi-answer) is the only type where "partially correct" is a real, distinct
        // grading outcome (Admin-022) - FE (single-answer) has no such state, so it stays empty.
        $partialfeedback = ($feedbackenabled && !$single)
            ? (string) (get_config('local_artqtml', 'feedback_ft_partial') ?: '')
            : '';

        $form->correctfeedback = [
            'text'   => $feedbackenabled ? self::feedback_template($single ? 'FE' : 'FT', true) : '',
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];
        $form->partiallycorrectfeedback = ['text' => $partialfeedback, 'format' => FORMAT_HTML, 'itemid' => 0];
        $form->incorrectfeedback = [
            'text'   => $feedbackenabled ? self::feedback_template($single ? 'FE' : 'FT', false) : '',
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];
        $form->shownumcorrect = 0;
        $form->answer = $answers;
        $form->fraction = $fractions;
        $form->feedback = $feedbacks;
    }

    /**
     * Populate qtype_ordering-specific form fields (technical annex 6.4).
     *
     * Field names/defaults verified against Moodle 4.5 core source
     * (question/type/ordering/questiontype.php, question.php); qtype_ordering is bundled in
     * core as of this version. save_question_options() recomputes 'fraction' internally.
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
        $form->gradingtype = $gradingtype; // Admin-037 default.
        $form->showgrading = 1;
        $form->numberingstyle = $numberingstyle; // Admin-038 default.
        $form->correctfeedback = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $form->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $form->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
    }

    /**
     * Populate qtype_shortanswer-specific form fields (technical annex 6.6).
     *
     * @param \stdClass $form
     * @param array $data expects 'answer' => string (the single AI-provided correct answer)
     * @param bool $feedbackenabled
     * @return void
     */
    protected static function apply_shortanswer(\stdClass $form, array $data, bool $feedbackenabled): void {
        $answer = self::clean_ai_text($data['answer'] ?? '');

        $form->usecase = 0;
        $form->answer = [$answer];
        $form->fraction = [1.0];
        $form->feedback = [[
            'text'   => $feedbackenabled ? self::feedback_template('RV', true) : '',
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ]];
    }

    /**
     * Populate qtype_essay-specific form fields (technical annex 6.5).
     *
     * @param \stdClass $form
     * @param array $data expects 'graderinfo' => string
     * @return void
     */
    protected static function apply_essay(\stdClass $form, array $data): void {
        $form->responseformat = 'editor';
        $form->responserequired = 1;
        $form->responsefieldlines = 15;
        $form->attachments = 0;
        $form->attachmentsrequired = 0;
        $form->maxbytes = 0;
        $form->filetypeslist = '';
        $form->graderinfo = [
            'text'   => self::clean_ai_text($data['graderinfo'] ?? ''),
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];
        $form->responsetemplate = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
    }

    /**
     * Fetch the admin-configured feedback template for a type/correctness (Admin-022, Beal-013).
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
