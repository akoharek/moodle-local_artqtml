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
 * This file is a SEED, not a source. It is read once, by install.php and by the upgrade step that
 * introduces these settings, and written into `config_plugins`. From that moment the database is
 * the only place the prompt lives.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

return [

    'generatorprompttemplate' =>
        "You are generating quiz questions for a Moodle course, based on the source text "
        . "provided as the user message.\n"
        . "\n"
        . "Generate the questions in the language of the source text.\n"
        . "\n"
        . "Generate exactly the following questions: {{QUESTION_COUNTS}}\n"
        . "Difficulty: {{DIFFICULTY_MODE}}\n"
        . "Knowledge source policy: {{KNOWLEDGE_SOURCE}}\n"
        . "{{NEGATION_INSTRUCTION}}\n"
        . "Per-type instructions: {{TYPE_INSTRUCTIONS}}\n",

    'promptknowledgesourceonly' =>
        'Only use facts found in the source text. Do not introduce outside information.',

    'promptnegation' =>
        'Wrap any negation word(s) in the question text (e.g. "not", "except") in <strong> HTML tags.',

    'promptnosourcemetaref' =>
        'Do not write meta-references to the source document in the question stem or answer '
        . 'options. Phrases such as "szöveg szerint", "a szöveg alapján", "a forrás szerint", '
        . '"according to the text", "based on the passage", "according to the source", or '
        . '"based on the document" are unprofessional in a quiz question - the student already '
        . 'knows the material comes from the course. Ask the question directly.',

    // Joins {{TYPE_INSTRUCTIONS}} only when the generation contains FE questions.
    'promptoptioncount' =>
        'For FE questions, provide between {{OPTION_MIN}} and {{OPTION_MAX}} answer options. '
        . 'An FE question has exactly one correct option.',

    'promptdifficultyscale' =>
        "Easy: {{EASY}}, Medium: {{MEDIUM}}, Hard: {{HARD}}.\n"
        . "The difficulty level is not a label on the question - it is the mental operation the "
        . "student has to perform. Build each question to match its level:\n"
        . "- Easy: the answer is stated outright in a single sentence of the source text. The "
        . "student locates it and recalls it.\n"
        . "- Medium: the answer cannot be copied from one sentence. It requires joining "
        . "information from two or more different places in the source text, or recognising that a "
        . "statement contradicts what the text says.\n"
        . "- Hard: the answer requires weighing several statements at once - checking multiple "
        . "claims against the text, distinguishing two things the text treats separately and that "
        . "are easily confused, or reaching a conclusion the text supports but never states.\n"
        . "Two things to avoid, because they make the levels meaningless: giving all three levels "
        . "the same operation and only changing the topic, and dressing an Easy question in a "
        . "story or a scenario and calling it Hard. A scenario that does not change the mental "
        . "operation does not change the difficulty.",

    'promptitemcount' =>
        'For SR questions, provide exactly {{SR_ITEM_COUNT}} items to put in order.',

    'promptfeedbackcorrect' =>
        'For {{TYPE}} questions, when the answer is correct, feedback in this style should apply: {{FEEDBACK}}',

    'promptfeedbackincorrect' =>
        'For {{TYPE}} questions, when the answer is incorrect, feedback in this style should apply: {{FEEDBACK}}',

    'promptoptionexplanation' =>
        "Write an explanation for EVERY answer option, addressed to a student who chose that "
        . "option.\n"
        . "- For an incorrect option: say what makes it wrong, against the source text. Name the "
        . "confusion it rests on where there is one - a similar term, a different paragraph, a "
        . "detail that belongs to something else.\n"
        . "- For the correct option: say briefly what makes it right.\n"
        . "Each explanation stands on its own and must make sense without the others. Do not "
        . "repeat the question, do not repeat the general feedback, and do not write \"this is "
        . "incorrect\" without saying why. Keep each one to at most two sentences.",

    'promptoptionexplanationtruefalse' =>
        "This is a True/False question: it has two options and rests on a single claim, so an "
        . "explanation that states that claim tells the student what the general feedback already "
        . "told them.\n"
        . "Write the two explanations for the reader instead of for the claim. For the option that "
        . "is wrong, name the misreading a student who chose it most likely made - the sentence "
        . "that looks like it says the opposite, the near-synonym, the detail that belongs "
        . "elsewhere in the source text. For the option that is right, name what in the source "
        . "settles it, and say it in different words from the general feedback.",

    'validatorprompttemplate' =>
        "You are an independent reviewer of AI-generated Moodle quiz questions. For each question, "
        . "judge whether it is factually correct against the source text, unambiguous, and "
        . "internally consistent for its question type. Return a suggestion, a short "
        . "justification, and a confidence score 0-100.\n"
        . "\n"
        . "{{SUGGESTION_INSTRUCTION}}\n"
        . "\n"
        . "{{CATEGORY_INSTRUCTION}}\n"
        . "\n"
        . "{{LANGUAGE_INSTRUCTION}}\n"
        . "\n"
        . "{{DIFFICULTY_INSTRUCTION}}\n"
        . "\n"
        . "{{WORDING_INSTRUCTION}}\n"
        . "\n"
        . "{{ITEMSOURCE_INSTRUCTION}}\n",

    'validationpromptitemsource' =>
        'For ordering (SR) questions, check every item in the list against the source text. Each '
        . 'item must be something the source text actually names as part of that sequence. An item '
        . 'that is a placeholder, a label, a note about the list itself, or simply a step the '
        . 'source text does not mention makes the question unanswerable, because an ordering '
        . 'question has no distractors - the student has to place every item, including that one. '
        . 'Report such a question as needs_review, name the offending item in the justification, '
        . 'and say what the list should contain instead.',

    'validationpromptdifficulty' =>
        "Each question carries a difficulty_label. Judge whether the question actually demands the "
        . "mental operation that label stands for, using these definitions - the same ones the "
        . "question was written from:\n"
        . "\n"
        . "{{DIFFICULTY_DEFINITIONS}}\n"
        . "\n"
        . "Two mismatches to look for in particular. A question labelled above the lowest level "
        . "whose answer is still a single sentence located in the source text is mislabelled. A "
        . "question wrapped in a scenario or a story is not thereby harder: if the answer is the "
        . "same sentence it would have been without the story, the level is the lower one. Report a "
        . "mismatch as needs_review and say in the justification which level the question actually "
        . "reaches.",

    'validationpromptwording' =>
        'Check that the question, its answers and its feedback are correct, natural writing in the '
        . 'language of the source text - grammatical, idiomatic, and free of words that do not '
        . 'belong. A garbled or ungrammatical question is a defect even when it is factually '
        . 'correct; report it as needs_review with the problem named in the justification. A '
        . 'question that contains its own answer is the same kind of defect. Also report as '
        . 'needs_review any stem or answer option that names the source document with a '
        . 'meta-reference such as "szöveg szerint", "a szöveg alapján", "according to the text", '
        . '"based on the passage", or "according to the source" - those phrases are unprofessional '
        . 'scaffolding, not question wording.',

    'validationpromptsuggestion' =>
        'For the suggestion field, use exactly one of these values: {{SUGGESTION_VALUES}}. Use '
        . '"accepted" when the question can be used as generated, "needs_review" when it needs a '
        . "teacher's correction, and \"rejected\" when it should be discarded. The field is required "
        . 'and must never be an empty string.',

    'validationpromptcategory' =>
        'For the problem_category field, use exactly one of these values: {{PROBLEM_CATEGORIES}}. '
        . 'Use "ok" when the question is acceptable and has no problem. The field is required and '
        . 'must never be an empty string.',

    'validationpromptlanguage' =>
        'Write the justification field in the same language as the source text. This applies to the '
        . 'free-text justification only: never translate the suggestion or problem_category values, '
        . 'which must stay exactly one of the listed values.',

    'promptjsoninvalid' =>
        'Your previous response was not valid JSON. Respond with ONLY valid JSON matching the '
        . 'required schema, with no additional commentary.',
];
