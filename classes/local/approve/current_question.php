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

namespace local_artqtml\local\approve;

/**
 * What the question says *now*, in the shape the approve page's detail panel already reads.
 *
 * BL-28, second half. The panel used to render `local_artqtml_questions.questiondata` - the JSON
 * the AI returned at generation time - and nothing ever updated it. Measured on 2026-08-02: after a
 * teacher rewrote an answer option in Moodle's editor, the panel still listed the option they had
 * replaced. That panel is what a teacher reads before pressing Approve, so it was showing one
 * version and approving another.
 *
 * The fix keeps the stored copy as what it is - the record of what the AI produced, which the
 * validator judged and the privacy export describes - and resolves what is displayed from Moodle at
 * read time. Derived, not stored twice, so the two cannot drift; the same rule the question grid
 * follows for its counts.
 *
 * **The reason the old code gave for reading the stored copy does not hold.** Its docblock said
 * this "still works for a not-yet-imported/rejected row too" - but a row only exists here once
 * `question_importer::create()` has returned an id, and a semantically rejected question never gets
 * a row at all (`save_questions_task::save_all()` logs it and moves on). Every row has a Moodle
 * question. What can happen is the reverse: someone deletes the question in the bank afterwards,
 * which is why the stored copy stays as the fallback.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class current_question {
    /**
     * The detail-panel data for one approve-page row, live where possible.
     *
     * @param \stdClass $row a local_artqtml_questions record
     * @return array in the same shape as the stored questiondata JSON
     */
    public static function data_for(\stdClass $row): array {
        $stored = json_decode((string) ($row->questiondata ?? ''), true);
        $stored = is_array($stored) ? $stored : [];

        $questionid = (int) ($row->questionbankid ?? 0);
        if ($questionid <= 0) {
            return $stored;
        }

        try {
            $question = \question_bank::load_question($questionid);
        } catch (\Throwable $e) {
            // The question is gone from the bank, or its qtype is no longer installed. The stored
            // copy is then the only thing left that describes it, and showing that is better than
            // showing an empty panel - but it is a fallback, not the normal path.
            return $stored;
        }

        return self::from_definition((string) ($row->typecode ?? ''), $question) + $stored;
    }

    /**
     * Map a loaded Moodle question definition into the stored JSON's shape.
     *
     * Only the keys the detail panel reads are produced; anything not mapped falls through to the
     * stored copy at the call site, so an unmapped key degrades to the old behaviour rather than to
     * a blank.
     *
     * @param string $typecode IH/FE/FT/SR/EH/RV
     * @param \question_definition $question
     * @return array
     */
    protected static function from_definition(string $typecode, \question_definition $question): array {
        $data = [];

        switch ($typecode) {
            case 'IH':
                // True/False keeps the verdict on the definition itself, not in the answers.
                $data['correctanswer'] = !empty($question->rightanswer);
                // BL-29: and its two per-answer explanations in named feedback fields.
                $data['explanationtrue'] = self::plain($question->truefeedback ?? '');
                $data['explanationfalse'] = self::plain($question->falsefeedback ?? '');
                break;

            case 'FE':
            case 'FT':
                $options = [];
                foreach (self::answers_of($question) as $answer) {
                    $options[] = [
                        'text'    => self::plain($answer->answer),
                        // A partially-credited option in an FT question is still a correct one;
                        // "not wrong" is what the badge means here, so any positive fraction counts.
                        'correct' => (float) $answer->fraction > 0,
                        // BL-29: Moodle keeps the per-option explanation in the answer's feedback.
                        'explanation' => self::plain($answer->feedback ?? ''),
                    ];
                }
                $data['options'] = $options;
                break;

            case 'SR':
                // Ordering stores the correct sequence in the answers' fraction field (1, 2, 3 …)
                // and loads them ordered by it, so the definition's order *is* the answer.
                $items = [];
                foreach (self::answers_of($question) as $answer) {
                    $items[] = ['text' => self::plain($answer->answer)];
                }
                $data['items'] = $items;
                break;

            case 'RV':
                $answers = self::answers_of($question);
                $first = reset($answers);
                $data['answer'] = $first ? self::plain($first->answer) : '';
                break;

            case 'EH':
                $data['graderinfo'] = self::plain($question->graderinfo ?? '');
                break;
        }

        // Hints and general feedback are the same two keys for every type, and both are declared
        // on question_definition itself - array and string, never null - so no fallback is
        // reachable here.
        $hints = array_values($question->hints);
        $data['hint1'] = isset($hints[0]) ? self::plain($hints[0]->hint) : '';
        $data['hint2'] = isset($hints[1]) ? self::plain($hints[1]->hint) : '';
        $data['generalfeedback'] = self::plain($question->generalfeedback);

        return $data;
    }

    /**
     * The definition's answers, as a plain list.
     *
     * @param \question_definition $question
     * @return array
     */
    protected static function answers_of(\question_definition $question): array {
        return array_values((array) ($question->answers ?? []));
    }

    /**
     * Moodle's HTML, reduced to the plain text the stored JSON always held.
     *
     * The detail panel escapes what it is given, because the AI's output is plain text. Moodle
     * stores the same fields as HTML, so handing them over unchanged printed the markup itself -
     * `<p>Bőséges napfény</p>` on the screen, seen on 2026-08-02 the first time this ran. Stripping
     * here rather than un-escaping there keeps this class's promise: the array it returns is in the
     * shape the stored JSON was, and the panel needs no knowledge of where it came from.
     *
     * @param string|null $html
     * @return string
     */
    protected static function plain(?string $html): string {
        return trim(html_to_text((string) $html, 0, false));
    }
}
