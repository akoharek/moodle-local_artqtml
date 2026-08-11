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
 * Reduces AI-generated question text to plain text, keeping only <sub> and <sup>.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\question;

/**
 * BL-55/BL-58: the model supplies wording, not appearance - and it does so BEFORE validation.
 *
 * WHY THIS IS ITS OWN CLASS, AND WHY IT RUNS WHERE IT RUNS. BL-55 put the cleaning in
 * question_form_builder, which is the save stage - the last step of generate -> validate -> save.
 * That fixed what the teacher's editor received and created a new fault nobody had before: the
 * validator judged the RAW text, so it spent its verdict on formatting the teacher would never
 * see. On 2026-08-06 a measured generation came back "Needs review", with the justification
 * complaining about a blue background - next to a question that had none, because the save stage
 * had already removed it. A complaint about something that is not there is worse than no
 * complaint: the teacher cannot act on it.
 *
 * So the cleaning moved to the parse step, where the model's answer first becomes data
 * (generate_questions_task). Everything downstream - the validator, the stored questiondata JSON,
 * the approval screen, the question bank - now sees the same text, and it is the text the teacher
 * will see. question_form_builder still calls clean() on every field: cleaning is idempotent, and
 * a second pass at the last door costs nothing while covering any path that does not come through
 * the parse step (legacy pendingdata written before this change, above all).
 */
class ai_text_cleaner {
    /**
     * The AI-authored plain-string fields of a question, whatever its type. Absent keys are
     * skipped, so one list serves all six types rather than six lists that can drift apart.
     */
    private const TEXT_FIELDS = [
        'questiontext',
        'generalfeedback',
        'hint1',
        'hint2',
        'explanationtrue',
        'explanationfalse',
        'answer',
        'graderinfo',
    ];

    /**
     * Reduce one AI-generated string to plain text, keeping only <sub> and <sup>.
     *
     * <sub> and <sup> are the one exception, decided by András on 2026-08-06: they are not
     * decoration but meaning - H<sub>2</sub>O and m<sup>2</sup> are wrong without them.
     *
     * The steps are ordered, and the order is the point:
     *
     * 1. Purify first. Beyond the security filtering this is what makes step 3 safe: a stray "<"
     *    in ordinary prose ("igaz-e, hogy x < 5") comes out of the purifier as "&lt;", so
     *    strip_tags() can no longer swallow the rest of the sentence as if it were a tag. Doing it
     *    the other way round loses text, silently.
     * 2. Turn block boundaries into newlines BEFORE the tags go, or "<p>Első</p><p>Második</p>"
     *    comes out as "ElsőMásodik" - the same words-run-together defect BL-48 fixed in the PDF
     *    reader.
     * 3. Strip everything except the two kept tags.
     * 4. Normalise the whitespace this leaves behind.
     *
     * Idempotent by construction, and it has to be: it now runs at the parse step AND again at the
     * save step. After one pass the only tags left are <sub>/<sup> and the only "<" characters are
     * entities, so a second pass has nothing left to do.
     *
     * Known consequence, recorded rather than discovered later: paragraph breaks become plain
     * newlines, and the field is still FORMAT_HTML, so a multi-paragraph explanation renders as
     * one paragraph. The words are all there; the paragraph structure is not.
     *
     * @param mixed $text expected string, but AI JSON output is trusted to have the right shape
     * @return string
     */
    public static function clean($text): string {
        $clean = clean_param((string) $text, PARAM_CLEANHTML);

        $clean = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $clean);
        $clean = preg_replace(
            '~</\s*(p|div|li|ul|ol|dl|dd|dt|h[1-6]|tr|td|th|table|thead|tbody|blockquote|pre)\s*>~i',
            "\n",
            $clean
        );

        $clean = strip_tags($clean, '<sub><sup>');

        // A non-breaking space is whitespace to a reader but not to preg's \s in UTF-8 mode, so
        // it is turned into an ordinary space before the runs below are collapsed.
        $clean = str_replace(['&nbsp;', "\xc2\xa0"], ' ', $clean);
        $clean = preg_replace('~[ \t]+~', ' ', $clean);
        $clean = preg_replace('~ *\n *~', "\n", $clean);
        $clean = preg_replace('~\n{3,}~', "\n\n", $clean);

        $clean = trim($clean);

        // Strip a leading "szöveg szerint / according to the text" clause when the model still
        // wrote one despite the prompt. Embedded occurrences are left for the semantic validator
        // to reject - cutting them out mid-sentence would leave broken Hungarian/English.
        return source_meta_reference::strip_leading($clean);
    }

    /**
     * Clean every AI-authored text field of one question, whatever its type.
     *
     * Deliberately conservative: only the known text fields are touched, and everything else -
     * `correct`, `correctanswer`, `difficulty_label`, `source_reference`, `type` - is passed
     * through untouched. A blanket walk over every string in the array would have run the
     * cleaner over machine values that are not prose, which is how a cleaner starts corrupting
     * the very data it was added to protect.
     *
     * @param array $question one decoded question, in the shape question_schema.php asks for
     * @return array the same question with its text fields cleaned
     */
    public static function clean_question(array $question): array {
        foreach (self::TEXT_FIELDS as $field) {
            if (isset($question[$field]) && is_string($question[$field])) {
                $question[$field] = self::clean($question[$field]);
            }
        }

        // FE/FT: the option the student clicks, and its per-option explanation.
        if (isset($question['options']) && is_array($question['options'])) {
            foreach ($question['options'] as $index => $option) {
                if (!is_array($option)) {
                    continue;
                }
                foreach (['text', 'explanation'] as $key) {
                    if (isset($option[$key]) && is_string($option[$key])) {
                        $question['options'][$index][$key] = self::clean($option[$key]);
                    }
                }
            }
        }

        // SR: apply_ordering() accepts both shapes ({text: ...} and a bare string), so both are
        // cleaned here rather than assuming the schema is always honoured.
        if (isset($question['items']) && is_array($question['items'])) {
            foreach ($question['items'] as $index => $item) {
                if (is_string($item)) {
                    $question['items'][$index] = self::clean($item);
                } else if (is_array($item) && isset($item['text']) && is_string($item['text'])) {
                    $question['items'][$index]['text'] = self::clean($item['text']);
                }
            }
        }

        return $question;
    }
}
