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
 * HTML/JS rendering for the draft approval page - split out of the
 * Approve.php controller. Presentation only: returns markup strings, performs no DB mutation;
 * Reads via approve_page_data and the lib.php badge helper.
 *
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local\approve;

use local_artqtml\local\question_types;
use local_artqtml\local\draft_bank;

/**
 * Renders the approve page's validation summary, question table, bulk buttons and inline scripts.
 */
class approve_renderer {
    /**
     * The four-status validation summary row of badges.
     *
     * @param array<string,int> $statuscounts from approve_page_data::status_counts()
     * @param int $statustotal
     * @return string
     */
    public static function validation_summary(array $statuscounts, int $statustotal): string {
        // JOV-F002: exactly four counters plus the total, each individually addressable so the
        // Element-count assertion can check "four + total", not just the rendered text.
        $html = \html_writer::start_div('artqtml-validationsummary mb-3', [
            'data-testid' => 'artqtml-approve-validationsummary',
        ]);
        foreach ($statuscounts as $statuskey => $statuscount) {
            $html .= \html_writer::span(
                \local_artqtml\local\validation_suggestion::label($statuskey) . ': ' . $statuscount,
                'badge ' . \local_artqtml\local\validation_suggestion::badge_class($statuskey) . ' mr-2',
                ['data-testid' => 'artqtml-approve-summary-count']
            );
        }
        $html .= \html_writer::span(
            get_string('validationsummarytotal', 'local_artqtml') . ': ' . $statustotal,
            'badge badge-light mr-2',
            ['data-testid' => 'artqtml-approve-summary-total']
        );
        $html .= \html_writer::end_div();

        return $html;
    }

    /**
     * The full question table: header (with sortable columns), one row per question (badges,
     * Validation detail, creator/editor, actions) and an inline collapsible detail row each.
     *
     * @param \core_renderer $output the page output renderer (passed in rather than pulled from
     *      the global $OUTPUT, as this is a plain helper, not a plugin_renderer_base)
     * @param \stdClass[] $questions from approve_page_data::questions()
     * @param string $sort current sort column
     * @param string $dir current sort direction
     * @param \moodle_url $pageurl
     * @param bool $candrafteditquestions whether to show the Edit/Preview actions (C9)
     * @param \stdClass $creator the generation owner (same for every row)
     * @param int $generationid
     * @return string
     */
    public static function questions_table(
        \core_renderer $output,
        array $questions,
        string $sort,
        string $dir,
        \moodle_url $pageurl,
        bool $candrafteditquestions,
        \stdClass $creator,
        int $generationid
    ): string {
        // Needed to tell "your own generation" from "someone else's" when deciding whether the Edit action prompts first.
        global $USER;

        $table = new \html_table();
        $table->head = [
            self::header_cell(
                'selectall',
                \html_writer::checkbox('selectall', 1, false, '', [
                    'id'          => 'artqtml-selectall',
                    'data-testid' => 'artqtml-approve-selectall',
                ])
            ),
            self::sortable_header_cell($pageurl, 'name', 'name', 'colquestionname', $sort, $dir),
            self::sortable_header_cell($pageurl, 'type', 'type', 'coltype', $sort, $dir),
            self::sortable_header_cell($pageurl, 'difficulty', 'difficulty', 'coldifficulty', $sort, $dir),
            self::sortable_header_cell($pageurl, 'validation', 'validation', 'colvalidationstatus', $sort, $dir),
            self::sortable_header_cell($pageurl, 'creator', 'creator', 'colcreatedby', $sort, $dir),
            self::sortable_header_cell($pageurl, 'lasteditedby', 'lasteditedby', 'collasteditedby', $sort, $dir),
            self::sortable_header_cell($pageurl, 'date', 'timecreated', 'coldate', $sort, $dir),
            self::header_cell('actions', get_string('colactions', 'local_artqtml')),
        ];
        $table->attributes['class'] = 'generaltable table table-striped artqtml-table';
        $table->colclasses = [
            0 => 'artqtml-col-select',
            1 => 'artqtml-col-name',
            2 => 'd-none d-md-table-cell',
            3 => 'd-none d-lg-table-cell',
            4 => 'artqtml-col-validation',
            5 => 'd-none d-xl-table-cell',
            6 => 'd-none d-xl-table-cell',
            7 => 'd-none d-lg-table-cell',
            8 => 'artqtml-col-actions',
        ];

        foreach ($questions as $question) {
            $typelabel = question_types::label($question->typecode);
            $qtype = question_types::QTYPE[$question->typecode] ?? $question->questiontype;
            $typeicon = $output->pix_icon('icon', '', 'qtype_' . $qtype, [
                'class'       => 'mr-1',
                'aria-hidden' => 'true',
                'data-testid' => 'artqtml-approve-typeicon',
            ]);

            if (!empty($question->edited)) {
                $statusbadge = \html_writer::span(
                    \local_artqtml\local\validation_suggestion::label(\local_artqtml\local\validation_suggestion::EDITED),
                    'badge ' . \local_artqtml\local\validation_suggestion::badge_class(
                        \local_artqtml\local\validation_suggestion::EDITED
                    )
                );
            } else {
                $statusbadge = \html_writer::span(
                    \local_artqtml\local\validation_suggestion::label($question->validationsuggestion),
                    'badge ' . \local_artqtml\local\validation_suggestion::badge_class($question->validationsuggestion)
                );
            }
            // The complete raw Gemini response for this question - displayed here in preference to the
            // Normalised problemcategory/justification columns, falling back to those (still validated
            // The same whitelist way below) for rows that predate validationdata or are not_evaluated.
            $validationdata = json_decode((string) $question->validationdata, true);
            if (is_array($validationdata)) {
                $displaycategory = (string) ($validationdata['problem_category'] ?? '');
                $displayjustification = (string) ($validationdata['justification'] ?? '');
            } else {
                $displaycategory = (string) $question->problemcategory;
                $displayjustification = (string) $question->justification;
            }
            $displaycategory = \local_artqtml\local\problem_category::normalise($displaycategory);

            $statuscell = $statusbadge;
            if ($question->validationsuggestion !== \local_artqtml\local\validation_suggestion::NOT_EVALUATED) {
                // PROB-F002: 'ok' shows its "No issue" label here too - not an empty cell, and
                // Distinct from the "Accepted" suggestion badge above it.
                if ($displaycategory !== null) {
                    $statuscell .= \html_writer::div(
                        s(\local_artqtml\local\problem_category::label($displaycategory)),
                        'small font-weight-bold mt-1 artqtml-problemcategory'
                    );
                }
                if ($displayjustification !== '') {
                    $justification = \core_text::substr($displayjustification, 0, 150) .
                        (\core_text::strlen($displayjustification) > 150 ? '...' : '');
                    $statuscell .= \html_writer::div(s($justification), 'small text-muted');
                }
            }

            if (empty($question->edited) && !empty($question->approved) && !empty($question->approvedby)) {
                $approver = \core_user::get_user($question->approvedby);
                if ($approver) {
                    $statuscell .= \html_writer::div(
                        get_string('approvedbylabel', 'local_artqtml', fullname($approver)),
                        'small text-muted'
                    );
                }
            }

            $checkboxattrs = ['class' => 'artqtml-rowselect', 'data-testid' => 'artqtml-approve-rowselect'];
            if ($question->movedout) {
                $checkboxattrs['disabled'] = 'disabled';
            }
            $checkbox = \html_writer::checkbox('questionids[]', $question->id, false, '', $checkboxattrs);

            $actions = [];
            if (!empty($question->questionbankid) && $candrafteditquestions) {
                if ($question->movedout) {
                    // After move: Open the destination question-bank listing. No Edit, no Preview.
                    $bankurl = approve_page_data::question_bank_url((int) $question->questionbankid);
                    if ($bankurl) {
                        $actions[] = \html_writer::link(
                            $bankurl,
                            get_string('actionopenquestion', 'local_artqtml'),
                            ['data-testid' => 'artqtml-approve-open-link']
                        );
                    }
                } else {
                    // Moodle 4.5: courseid (draft course). Moodle 5.1+: required cmid for mod_qbank.
                    $editparams = approve_page_data::question_edit_url_params(
                        (int) $question->questionbankid,
                        $pageurl
                    );
                    $editurl = new \moodle_url('/question/bank/editquestion/question.php', $editparams);
                    if ((int) $creator->id === (int) $USER->id) {
                        $actions[] = \html_writer::link($editurl, get_string('actionedit', 'local_artqtml'), [
                            'data-testid' => 'artqtml-approve-edit-link',
                        ]);
                    } else {
                        $actions[] = $output->action_link(
                            $editurl,
                            get_string('actionedit', 'local_artqtml'),
                            new \confirm_action(
                                get_string('confirmeditothersquestion', 'local_artqtml', fullname($creator))
                            ),
                            ['data-testid' => 'artqtml-approve-edit-link']
                        );
                    }

                    $previewurl = \qbank_previewquestion\helper::question_preview_url(
                        $question->questionbankid,
                        null,
                        null,
                        null,
                        null,
                        null,
                        $pageurl
                    );
                    $actions[] = \html_writer::link($previewurl, get_string('actionpreview', 'local_artqtml'), [
                        'target'      => '_blank',
                        'data-testid' => 'artqtml-approve-preview-link',
                    ]);
                }
            }

            if ($question->movedout) {
                $actions[] = \html_writer::span(get_string('moved_badge', 'local_artqtml'), 'badge badge-info', [
                    'data-testid' => 'artqtml-approve-moved-badge',
                ]);
            } else if (!empty($question->approved)) {
                // "a badge maga nem kattintható" - a plain span, with the revoke action as its own separate link beside it.
                $actions[] = \html_writer::span(get_string('approvedlabel', 'local_artqtml'), 'badge badge-success', [
                    'data-testid' => 'artqtml-approve-approved-badge',
                ]);
                $revokeurl = new \moodle_url('/local/artqtml/approve.php', [
                    'generationid'   => $generationid,
                    'revokequestion' => $question->id,
                    'sesskey'        => sesskey(),
                ]);
                $actions[] = \html_writer::link($revokeurl, get_string('revokeapproval', 'local_artqtml'), [
                    'data-testid' => 'artqtml-approve-revoke-link',
                ]);
                // Single-question move. Uses the shared category select in the form footer;
                // The server validates categoryvalue.
                $actions[] = \html_writer::tag('button', get_string('moveselected', 'local_artqtml'), [
                    'type'        => 'submit',
                    'name'        => 'movequestion',
                    'value'       => $question->id,
                    'class'       => 'btn btn-link p-0 align-baseline',
                    'data-testid' => 'artqtml-approve-move-button',
                ]);
            } else {
                $actions[] = \html_writer::tag('button', get_string('actionapprove', 'local_artqtml'), [
                    'type'        => 'submit',
                    'name'        => 'approvequestion',
                    'value'       => $question->id,
                    // No btn-sm: it sets font-size to 0.875rem, which rendered this control at
                    // 13.125px next to the 15px links beside it - measured on the page, not
                    // Guessed. Colour and weight already matched; the size was the whole
                    // Difference, and without btn-sm both come out at 15px / 22.5px line height.
                    'class'       => 'btn btn-link p-0 align-baseline',
                    'data-testid' => 'artqtml-approve-approve-button',
                ]);
            }

            if (!$question->movedout) {
                $deleteurl = new \moodle_url('/local/artqtml/approve.php', [
                    'generationid'   => $generationid,
                    'deletequestion' => $question->id,
                    'sesskey'        => sesskey(),
                ]);
                $actions[] = $output->action_link(
                    $deleteurl,
                    get_string('actiondelete', 'local_artqtml'),
                    new \confirm_action(get_string('confirmdeletequestion', 'local_artqtml')),
                    ['data-testid' => 'artqtml-approve-delete-link']
                );
            }

            $lasteditorname = '';
            if (!empty($question->lasteditedby)) {
                $lasteditor = \core_user::get_user($question->lasteditedby);
                if ($lasteditor) {
                    $lasteditorname = fullname($lasteditor);
                }
            }

            $detailsid = 'artqtml-details-' . $question->id;
            $nametoggle = \html_writer::link(
                '#',
                format_string($question->questioncode ?: $question->questiontext),
                [
                    'class'       => 'artqtml-question-toggle',
                    'data-target' => $detailsid,
                    'data-testid' => 'artqtml-approve-questionname',
                ]
            );

            $collapsedparts = [
                \html_writer::span($typelabel),
                \html_writer::span(s($question->difficultylabel)),
                \html_writer::span(
                    get_string('colcreatedby', 'local_artqtml') . ': ' . fullname($creator)
                ),
                \html_writer::span(userdate($question->timecreated, get_string('datetimeformat', 'local_artqtml'))),
            ];
            if ($lasteditorname !== '') {
                $collapsedparts[] = \html_writer::span(
                    get_string('collasteditedby', 'local_artqtml') . ': ' . $lasteditorname
                );
            }
            $namecell = $nametoggle . \html_writer::div(
                implode('', $collapsedparts),
                'artqtml-collapsed-meta d-xl-none text-muted'
            );

            $row = new \html_table_row([
                // The cell and the checkbox inside it must not share a testid, or every row-scoped
                // Lookup for the control resolves to two elements (the <td> and the <input>).
                self::cell('selectcell', $checkbox),
                self::cell('namecell', $namecell),
                // Icon AND type name, in this column only.
                self::cell('typecell', $typeicon . \html_writer::span($typelabel, '', [
                    'data-testid' => 'artqtml-approve-typelabel',
                ])),
                self::cell('difficultycell', s($question->difficultylabel)),
                self::cell('validationcell', $statuscell),
                self::cell('creatorcell', fullname($creator)),
                self::cell('lasteditedbycell', $lasteditorname),
                self::cell('datecell', userdate($question->timecreated, get_string('datetimeformat', 'local_artqtml'))),
                self::cell('actionscell', \html_writer::div(implode('', array_map(static function (string $action): string {
                    return \html_writer::span($action, 'artqtml-rowaction');
                }, $actions)), 'artqtml-rowactions')),
            ]);
            // A technikai melléklet "Teszthorgonyok" szakasza: every row carries a screen-scoped testid
            // Plus a content identifier, so a row assertion can select the question it means
            // Instead of silently running against the first match.
            $row->attributes['data-testid'] = 'artqtml-approve-row';
            $row->attributes['data-questioncode'] = $question->questioncode;
            $table->data[] = $row;

            $detailscell = new \html_table_cell(
                self::question_details_html($question->typecode, current_question::data_for($question))
            );
            $detailscell->colspan = count($table->head);
            $detailscell->attributes['class'] = 'artqtml-question-details d-none';
            // On html_table_cell the id is a dedicated property, not part of ->attributes - setting the
            // Latter silently produces a <td> with no id, leaving the toggle script's
            // GetElementById() lookup unable to find this row.
            $detailscell->id = $detailsid;
            $detailsrow = new \html_table_row([$detailscell]);
            $table->data[] = $detailsrow;
        }

        return \html_writer::table($table);
    }

    /**
     * A sortable header cell: the direction-aware sort link, wrapped in its test anchor.
     *
     * @param \moodle_url $pageurl
     * @param string $testkey short field name, appended to the artqtml-approve-header- prefix
     * @param string $columnkey an approve_page_data::sortable_columns() key
     * @param string $labelkey lang string key for the visible column label
     * @param string $currentsort
     * @param string $currentdir
     * @return \html_table_cell
     */
    protected static function sortable_header_cell(
        \moodle_url $pageurl,
        string $testkey,
        string $columnkey,
        string $labelkey,
        string $currentsort,
        string $currentdir
    ): \html_table_cell {
        return self::header_cell(
            $testkey,
            self::sort_header($pageurl, $columnkey, get_string($labelkey, 'local_artqtml'), $currentsort, $currentdir)
        );
    }

    /**
     * One header cell of the question table, tagged with its test anchor.
     *
     * @param string $key short field name, appended to the artqtml-approve-header- prefix
     * @param string $content already-escaped cell HTML
     * @return \html_table_cell
     */
    protected static function header_cell(string $key, string $content): \html_table_cell {
        $cell = new \html_table_cell($content);
        $cell->header = true;
        $cell->attributes['data-testid'] = 'artqtml-approve-header-' . $key;

        return $cell;
    }

    /**
     * One body cell of the question table, tagged with its test anchor.
     *
     * @param string $key short field name, appended to the artqtml-approve- prefix
     * @param string $content already-escaped cell HTML
     * @return \html_table_cell
     */
    protected static function cell(string $key, string $content): \html_table_cell {
        $cell = new \html_table_cell($content);
        $cell->attributes['data-testid'] = 'artqtml-approve-' . $key;

        return $cell;
    }

    /**
     * The inline script wiring each question name toggle to show/hide its detail row.
     *
     * @return string
     */
    public static function toggle_script(): string {
        return \html_writer::script(
            "document.querySelectorAll('.artqtml-question-toggle').forEach(function(link) {" .
                "link.addEventListener('click', function(e) {" .
                    "e.preventDefault();" .
                    "var target = document.getElementById(link.getAttribute('data-target'));" .
                    "if (target) { target.classList.toggle('d-none'); }" .
                "});" .
            "});"
        );
    }

    /**
     * bulk action buttons.
     *
     * @param \core_renderer $output the page output renderer (passed in rather than pulled from
     * The global $OUTPUT, as this is a plain helper, not a plugin_renderer_base)
     * @param int $eligibleforapproval count for the approve-all button label/disabled state
     * @param array<string,string> $categoryoptions move-target options ("categoryid,contextid" => label)
     * @return string
     */
    public static function bulk_action_buttons(\core_renderer $output, int $eligibleforapproval, array $categoryoptions): string {
        $approveallattrs = [
            'type'        => 'submit',
            'name'        => 'bulkaction',
            'value'       => 'allaccepted',
            'class'       => 'btn btn-secondary',
            'data-testid' => 'artqtml-approve-approveall-button',
        ];
        if ($eligibleforapproval === 0) {
            $approveallattrs['disabled'] = 'disabled';
        }
        $html = \html_writer::start_div('artqtml-bulkactions artqtml-bulkapprove mb-3', [
            'data-testid' => 'artqtml-approve-bulk-approveblock',
        ]);
        $html .= \html_writer::tag(
            'button',
            get_string('approveallaccepted', 'local_artqtml', $eligibleforapproval),
            $approveallattrs
        );
        $html .= \html_writer::end_div();

        $html .= \html_writer::start_div('artqtml-bulkactions artqtml-bulkmove mb-3', [
            'data-testid' => 'artqtml-approve-bulk-moveblock',
        ]);

        if (empty($categoryoptions)) {
            $html .= $output->notification(get_string('nocategories', 'local_artqtml'), 'warning');
        } else {
            $html .= \html_writer::start_div('form-inline artqtml-bulkcategory');
            $html .= \html_writer::label(
                get_string('selectcategory', 'local_artqtml'),
                'artqtml-categoryvalue',
                true,
                ['class' => 'mr-2']
            );
            // Deliberately NOT required="required": the <select> lives in the same form as every
            // Other control on this page. The move path validates the value server-side.
            $html .= \html_writer::select($categoryoptions, 'categoryvalue', '', ['' => 'choosedots'], [
                'id'          => 'artqtml-categoryvalue',
                'class'       => 'mr-2',
                'data-testid' => 'artqtml-approve-category-select',
            ]);
            $html .= \html_writer::end_div();
        }

        // The confirmation must show how many questions are actually selected at click time.
        $html .= \html_writer::tag(
            'button',
            get_string('bulkdelete', 'local_artqtml'),
            ['type' => 'submit', 'name' => 'bulkaction', 'value' => 'delete', 'class' => 'btn btn-outline-danger',
             'disabled' => 'disabled', 'data-selectionrequired' => '1',
             'data-testid' => 'artqtml-approve-delete-button',
             'data-confirmmessage' => get_string('confirmbulkdelete', 'local_artqtml', '__COUNT__')]
        );
        $html .= \html_writer::end_div();

        return $html;
    }

    /**
     * The inline script for the select-all checkbox and the live bulk-delete confirm count (m-05).
     *
     * @return string
     */
    public static function selection_script(): string {
        return \html_writer::script("
document.addEventListener('DOMContentLoaded', function() {
    var selectall = document.getElementById('artqtml-selectall');
    var selectable = function() {
        return document.querySelectorAll('.artqtml-rowselect:not(:disabled)');
    };
    var syncBulkButtons = function() {
        var anyselected = document.querySelectorAll('.artqtml-rowselect:not(:disabled):checked').length > 0;
        document.querySelectorAll('[data-selectionrequired]').forEach(function(button) {
            button.disabled = !anyselected;
        });
    };
    if (selectall) {
        selectall.addEventListener('change', function() {
            selectable().forEach(function(checkbox) {
                checkbox.checked = selectall.checked;
            });
            syncBulkButtons();
        });
    }
    selectable().forEach(function(checkbox) {
        checkbox.addEventListener('change', syncBulkButtons);
    });
    syncBulkButtons();
    document.querySelectorAll('[data-confirmmessage]').forEach(function(button) {
        button.addEventListener('click', function(e) {
            var count = document.querySelectorAll('.artqtml-rowselect:not(:disabled):checked').length;
            var message = button.getAttribute('data-confirmmessage').replace('__COUNT__', count);
            if (!window.confirm(message)) {
                e.preventDefault();
            }
        });
    });
});
");
    }

    /**
     * Build a clickable, direction-aware sort header link for one approve table column.
     *
     * @param \moodle_url $pageurl
     * @param string $columnkey
     * @param string $label
     * @param string $currentsort
     * @param string $currentdir
     * @return string
     */
    protected static function sort_header(
        \moodle_url $pageurl,
        string $columnkey,
        string $label,
        string $currentsort,
        string $currentdir
    ): string {
        $isactive = $currentsort === $columnkey;
        $newdir = ($isactive && $currentdir === 'ASC') ? 'DESC' : 'ASC';

        $url = new \moodle_url($pageurl, ['qsort' => $columnkey, 'qdir' => $newdir]);
        $url->remove_params('qpage');

        $arrow = $isactive ? ' ' . ($currentdir === 'ASC' ? '&#9650;' : '&#9660;') : '';

        return \html_writer::link($url, $label . $arrow);
    }

    /**
     * Build the inline expand/collapse detail block for one question: its answers/items, hints and general feedback.
     *
     * @param string $typecode IH/FE/SR
     * @param array $questiondata question data in the stored JSON's shape
     * @return string
     */
    protected static function question_details_html(string $typecode, array $questiondata): string {
        $parts = [];

        switch ($typecode) {
            case 'IH':
                $answerlabel = !empty($questiondata['correctanswer'])
                    ? get_string('true', 'local_artqtml')
                    : get_string('false', 'local_artqtml');
                $parts[] = \html_writer::tag(
                    'p',
                    \html_writer::tag('strong', get_string('detailscorrectanswer', 'local_artqtml')) . ' ' . $answerlabel
                );
                foreach (['explanationtrue' => 'true', 'explanationfalse' => 'false'] as $key => $labelkey) {
                    $explanation = trim((string) ($questiondata[$key] ?? ''));
                    if ($explanation === '') {
                        continue;
                    }
                    $parts[] = \html_writer::tag('p', \html_writer::tag(
                        'strong',
                        get_string($labelkey, 'local_artqtml') . ' — '
                            . get_string('detailsexplanation', 'local_artqtml')
                    ) . ' ' . s($explanation));
                }
                break;

            case 'FE':
                $items = [];
                foreach ((array) ($questiondata['options'] ?? []) as $option) {
                    $text = s((string) ($option['text'] ?? ''));
                    $iscorrect = !empty($option['correct']);
                    $explanation = trim((string) ($option['explanation'] ?? ''));
                    $items[] = \html_writer::tag(
                        'li',
                        $text . ($iscorrect
                            ? ' ' . \html_writer::span(
                                get_string('detailscorrect', 'local_artqtml'),
                                'badge badge-success'
                            )
                            : '')
                        . ($explanation !== ''
                            ? \html_writer::div(
                                \html_writer::tag('em', s($explanation)),
                                'text-muted small'
                            )
                            : '')
                    );
                }
                $parts[] = \html_writer::tag('ul', implode('', $items));
                break;

            case 'SR':
                $items = [];
                foreach ((array) ($questiondata['items'] ?? []) as $item) {
                    $text = is_array($item) ? ($item['text'] ?? '') : $item;
                    $items[] = \html_writer::tag('li', s((string) $text));
                }
                $parts[] = \html_writer::tag('ol', implode('', $items));
                break;
        }

        $hint1 = trim((string) ($questiondata['hint1'] ?? ''));
        $hint2 = trim((string) ($questiondata['hint2'] ?? ''));
        if ($hint1 !== '') {
            $parts[] = \html_writer::tag('p', \html_writer::tag(
                'strong',
                get_string('detailshint1', 'local_artqtml')
            ) . ' ' . s($hint1));
        }
        if ($hint2 !== '') {
            $parts[] = \html_writer::tag('p', \html_writer::tag(
                'strong',
                get_string('detailshint2', 'local_artqtml')
            ) . ' ' . s($hint2));
        }

        $generalfeedback = trim((string) ($questiondata['generalfeedback'] ?? ''));
        if ($generalfeedback !== '') {
            $parts[] = \html_writer::tag(
                'p',
                \html_writer::tag('strong', get_string('detailsgeneralfeedback', 'local_artqtml')) . ' ' . s($generalfeedback)
            );
        }

        return implode('', $parts);
    }
}
