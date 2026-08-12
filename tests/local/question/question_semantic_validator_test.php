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

use local_artqtml\local\question_types;

/**
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
        foreach (question_types::CODES as $type) {
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

        $this->assertNotNull(question_semantic_validator::validate('FE', $base + ['options' => [
            ['text' => 'a', 'correct' => false],
            ['text' => 'b', 'correct' => false],
        ]]));

        $this->assertNotNull(question_semantic_validator::validate('FE', $base + ['options' => [
            ['text' => 'a', 'correct' => true],
            ['text' => 'b', 'correct' => true],
        ]]));

        $this->assertNull(question_semantic_validator::validate('FE', $base + ['options' => [
            ['text' => 'a', 'correct' => true],
            ['text' => 'b', 'correct' => false],
        ]]));
    }

    /**
     * v20 #7: the admin-configured FE option-count range is enforced server-side.
     */
    public function test_fe_option_count_range_enforced(): void {
        $this->resetAfterTest();
        set_config('fefminoptions', 3, 'local_artqtml');
        set_config('fefmaxoptions', 4, 'local_artqtml');

        $twooptions = ['questiontext' => 'Q?', 'options' => [
            ['text' => 'a', 'correct' => true],
            ['text' => 'b', 'correct' => false],
        ]];
        $this->assertNotNull(question_semantic_validator::validate('FE', $twooptions));

        $threeoptions = ['questiontext' => 'Q?', 'options' => [
            ['text' => 'a', 'correct' => true],
            ['text' => 'b', 'correct' => false],
            ['text' => 'c', 'correct' => false],
        ]];
        $this->assertNull(question_semantic_validator::validate('FE', $threeoptions));
    }

    /**
     * Unsupported type codes (FT/RV/EH) are rejected.
     */
    public function test_unsupported_type_codes_are_rejected(): void {
        foreach (['FT', 'RV', 'EH'] as $type) {
            $reason = question_semantic_validator::validate($type, ['questiontext' => 'Q?']);
            $this->assertNotNull($reason, $type);
            $this->assertStringContainsString('unsupported type code', $reason);
        }
    }

    /**
     * v20 #7: SR (ordering) must have exactly the configured item count - the per-generation
     * override wins over the admin default when set.
     */
    public function test_sr_exact_item_count_enforced(): void {
        $this->resetAfterTest();
        set_config('sritemcount', 4, 'local_artqtml');

        $threeitems = ['questiontext' => 'Order these', 'items' => ['a', 'b', 'c']];

        $this->assertNotNull(question_semantic_validator::validate('SR', $threeitems));

        $fouritems = ['questiontext' => 'Order these', 'items' => ['a', 'b', 'c', 'd']];
        $this->assertNull(question_semantic_validator::validate('SR', $fouritems));

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

        $reason = question_semantic_validator::validate('IH', [
            'questiontext'  => 'Melyik növénycsaládot említi a szöveg szerint?',
            'correctanswer' => true,
        ]);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('source meta-reference', $reason);

        $this->assertNotNull(question_semantic_validator::validate('IH', [
            'questiontext'  => 'According to the text, apples are roses.',
            'correctanswer' => true,
        ]));

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

        $this->assertNull(question_semantic_validator::validate('IH', [
            'questiontext'  => 'Melyik növénycsaládba tartozik az alma?',
            'correctanswer' => true,
        ]));
    }
}
