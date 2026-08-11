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

namespace local_artqtml\local\question;

/**
 * Unit tests for M-07 semantic validation of AI-generated question data (technical annex ch.6,
 * v20 #6/#7 - server-side FE/FT/SR enforcement).
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_artqtml\local\question\question_semantic_validator
 */
final class question_semantic_validator_test extends \advanced_testcase {
    /**
     * Every supported type rejects a blank question stem (v20 #6).
     */
    public function test_blank_questiontext_rejected_for_every_type(): void {
        foreach (['IH', 'FE', 'FT', 'SR', 'RV', 'EH'] as $type) {
            $this->assertNotNull(
                question_semantic_validator::validate($type, ['questiontext' => '   ']),
                "$type should reject a blank questiontext"
            );
        }
    }

    /**
     * IH (true/false) needs a correctanswer field; with it, it passes.
     */
    public function test_ih_requires_correctanswer(): void {
        $this->assertNotNull(question_semantic_validator::validate('IH', ['questiontext' => 'Q?']));
        $this->assertNull(question_semantic_validator::validate('IH', [
            'questiontext'  => 'Q?',
            'correctanswer' => true,
        ]));
    }

    /**
     * FE (single choice) needs exactly one correct option and non-blank option texts.
     */
    public function test_fe_requires_exactly_one_correct(): void {
        $this->resetAfterTest();
        set_config('fefminoptions', 2, 'local_artqtml');
        set_config('fefmaxoptions', 5, 'local_artqtml');

        $base = ['questiontext' => 'Q?'];

        // Zero correct -> rejected.
        $this->assertNotNull(question_semantic_validator::validate('FE', $base + ['options' => [
            ['text' => 'a', 'correct' => false],
            ['text' => 'b', 'correct' => false],
        ]]));

        // Two correct -> rejected.
        $this->assertNotNull(question_semantic_validator::validate('FE', $base + ['options' => [
            ['text' => 'a', 'correct' => true],
            ['text' => 'b', 'correct' => true],
        ]]));

        // Exactly one correct -> valid.
        $this->assertNull(question_semantic_validator::validate('FE', $base + ['options' => [
            ['text' => 'a', 'correct' => true],
            ['text' => 'b', 'correct' => false],
        ]]));
    }

    /**
     * v20 #7: the admin-configured FE/FT option-count range is enforced server-side.
     */
    public function test_fe_option_count_range_enforced(): void {
        $this->resetAfterTest();
        set_config('fefminoptions', 3, 'local_artqtml');
        set_config('fefmaxoptions', 4, 'local_artqtml');

        $twooptions = ['questiontext' => 'Q?', 'options' => [
            ['text' => 'a', 'correct' => true],
            ['text' => 'b', 'correct' => false],
        ]];
        // Two options is below the configured minimum of three.
        $this->assertNotNull(question_semantic_validator::validate('FE', $twooptions));

        $threeoptions = ['questiontext' => 'Q?', 'options' => [
            ['text' => 'a', 'correct' => true],
            ['text' => 'b', 'correct' => false],
            ['text' => 'c', 'correct' => false],
        ]];
        $this->assertNull(question_semantic_validator::validate('FE', $threeoptions));
    }

    /**
     * FT (multi choice) needs at least two correct options and rejects blank option text.
     */
    public function test_ft_requires_at_least_two_correct_and_no_blank(): void {
        $this->resetAfterTest();
        set_config('fefminoptions', 2, 'local_artqtml');
        set_config('fefmaxoptions', 5, 'local_artqtml');

        // Only one correct -> rejected.
        $this->assertNotNull(question_semantic_validator::validate('FT', ['questiontext' => 'Q?', 'options' => [
            ['text' => 'a', 'correct' => true],
            ['text' => 'b', 'correct' => false],
        ]]));

        // Blank option text -> rejected.
        $this->assertNotNull(question_semantic_validator::validate('FT', ['questiontext' => 'Q?', 'options' => [
            ['text' => 'a', 'correct' => true],
            ['text' => '', 'correct' => true],
        ]]));

        // Two correct, all non-blank -> valid.
        $this->assertNull(question_semantic_validator::validate('FT', ['questiontext' => 'Q?', 'options' => [
            ['text' => 'a', 'correct' => true],
            ['text' => 'b', 'correct' => true],
            ['text' => 'c', 'correct' => false],
        ]]));
    }

    /**
     * v20 #7: SR (ordering) must have exactly the configured item count - the per-generation
     * override wins over the admin default when set.
     */
    public function test_sr_exact_item_count_enforced(): void {
        $this->resetAfterTest();
        set_config('sritemcount', 4, 'local_artqtml');

        $threeitems = ['questiontext' => 'Order these', 'items' => ['a', 'b', 'c']];

        // Three items, admin default is four -> rejected.
        $this->assertNotNull(question_semantic_validator::validate('SR', $threeitems));

        // Four items matches the admin default -> valid.
        $fouritems = ['questiontext' => 'Order these', 'items' => ['a', 'b', 'c', 'd']];
        $this->assertNull(question_semantic_validator::validate('SR', $fouritems));

        // The per-generation override (M-26) of three makes the three-item set valid instead.
        $this->assertNull(question_semantic_validator::validate('SR', $threeitems, ['sritemcount' => 3]));
    }

    /**
     * SR needs at least two items and rejects blank item text.
     */
    public function test_sr_minimum_items_and_blank_rejected(): void {
        $this->resetAfterTest();
        set_config('sritemcount', 2, 'local_artqtml');

        $this->assertNotNull(question_semantic_validator::validate('SR', ['questiontext' => 'Q', 'items' => ['only one']]));
        $this->assertNotNull(question_semantic_validator::validate('SR', ['questiontext' => 'Q', 'items' => ['a', '  ']]));
    }

    /**
     * RV (short answer) is unanswerable with no accepted answer.
     */
    public function test_rv_requires_answer(): void {
        $this->assertNotNull(question_semantic_validator::validate('RV', ['questiontext' => 'Q?', 'answer' => '']));
        $this->assertNull(question_semantic_validator::validate('RV', ['questiontext' => 'Q?', 'answer' => 'Paris']));
    }

    /**
     * EH (essay) needs only a non-blank stem.
     */
    public function test_eh_valid_with_stem(): void {
        $this->assertNull(question_semantic_validator::validate('EH', ['questiontext' => 'Discuss...']));
    }

    /**
     * v20 #6: an unknown type code is rejected outright rather than silently passed.
     */
    public function test_unknown_typecode_rejected(): void {
        $this->assertNotNull(question_semantic_validator::validate('ZZ', ['questiontext' => 'Q?']));
    }

    /**
     * Stems (and FE options / SR items) that still name the source document are rejected.
     */
    public function test_source_meta_reference_rejected(): void {
        $this->resetAfterTest();
        set_config('fefminoptions', 2, 'local_artqtml');
        set_config('fefmaxoptions', 5, 'local_artqtml');
        set_config('sritemcount', 2, 'local_artqtml');

        $reason = question_semantic_validator::validate('EH', [
            'questiontext' => 'Melyik növénycsaládot említi a szöveg szerint?',
        ]);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('source meta-reference', $reason);

        $this->assertNotNull(question_semantic_validator::validate('IH', [
            'questiontext'  => 'According to the text, apples are roses.',
            'correctanswer' => true,
        ]));

        // Leading clause alone would be stripped by the cleaner first; an option that still
        // carries the phrase after cleaning must fail here.
        $this->assertNotNull(question_semantic_validator::validate('FE', [
            'questiontext' => 'Melyik a helyes?',
            'options'      => [
                ['text' => 'A forrás alapján Rosaceae', 'correct' => true],
                ['text' => 'Poaceae', 'correct' => false],
            ],
        ]));

        $this->assertNotNull(question_semantic_validator::validate('SR', [
            'questiontext' => 'Rendezd sorba!',
            'items'        => ['Első', 'Based on the passage: második'],
        ]));

        $this->assertNull(question_semantic_validator::validate('EH', [
            'questiontext' => 'Melyik növénycsaládba tartozik az alma?',
        ]));
    }
}
