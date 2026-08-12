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
 * Renders one filterable/sortable/paginated "generations" section of the list page.
 *
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * One sortable/filterable/paginated section of the list page.
 */
class generation_list {
    /**
     * Helper.
     *
     * @var string default sort column - newest generation first ( v3 #8), not the
     * Previous status-based default. Status remains available as an explicit, user-chosen sort
     * (see the "status"/"statusorder" SORTABLE entries and STATUS_ORDER below) - only the
     * Implicit default view changed.
     */
    protected const DEFAULT_SORT = 'timecreated';

    /** @var string default sort direction - paired with DEFAULT_SORT above. */
    protected const DEFAULT_DIR = 'DESC';

    /**
     * Helper.
     *
     * @var array<string,int> status -> sort weight for the status column's ordering.
     *
     * The seven status values themselves come from
     * {@see \local_artqtml\local\generation_status}; only the weights are list-page-specific.
     * Generation_status_test asserts these keys are exactly that class's VALUES.
     */
    protected const STATUS_ORDER = [
        generation_status::STARTED    => 0,
        generation_status::GENERATING => 0,
        generation_status::FAILED     => 0,
        generation_status::VALIDATING => 1,
        generation_status::SAVING     => 2,
        generation_status::COMPLETED  => 3,
        generation_status::PARTIAL    => 3,
    ];

    /** @var array<string,string> sortable column key -> SQL order-by expression. */
    protected const SORTABLE = [
        'name'        => 'g.name',
        'creator'     => 'creatorname',
        'timecreated' => 'g.timecreated',
        'status'      => 'statusorder',
        'statusorder' => 'statusorder',
        'questioncount' => 'questioncount',
        'pendingvalidation' => 'unvalidatedcount',
    ];

    /**
     * Render one section (filter bar + table + pagination) as HTML.
     *
     * @param string $prefix GET param prefix for this section, e.g. "mine" or "other"
     * @param int $viewerid the logged-in user's id
     * @param bool $onlymine true for "My generations", false for "Others' generations"
     * @param \moodle_url $baseurl the current page URL (without this section's params)
     * @return string
     */
    public static function render(string $prefix, int $viewerid, bool $onlymine, \moodle_url $baseurl): string {
        global $DB, $OUTPUT;

        $status = optional_param($prefix . '_status', '', PARAM_ALPHA);
        // HTML5 type=date posts Y-m-d; PARAM_TEXT + strict parse (not PARAM_RAW / strtotime).
        $datefrom = optional_param($prefix . '_datefrom', '', PARAM_TEXT);
        $dateto = optional_param($prefix . '_dateto', '', PARAM_TEXT);
        if (self::parse_filter_date($datefrom) === null) {
            $datefrom = '';
        }
        if (self::parse_filter_date($dateto) === null) {
            $dateto = '';
        }
        $creator = optional_param($prefix . '_creator', 0, PARAM_INT);
        $sort = optional_param($prefix . '_sort', self::DEFAULT_SORT, PARAM_ALPHA);
        $dir = optional_param($prefix . '_dir', self::DEFAULT_DIR, PARAM_ALPHA);
        $page = optional_param($prefix . '_page', 0, PARAM_INT);
        $perpage = (int) get_config('moodle', 'perpage') ?: 20;

        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        if (!isset(self::SORTABLE[$sort])) {
            $sort = self::DEFAULT_SORT;
        }

        [$where, $params] = self::build_where($viewerid, $onlymine, $status, $datefrom, $dateto, $creator);

        $namefields = \core_user\fields::for_name()->get_sql('u')->selects;
        $statuscase = self::status_order_case('g');
        // Real column, not just a display concatenation - needed so 'creator' is a valid
        // ORDER BY target (/024): u.firstname/u.lastname alone aren't sortable as a
        // Single key, and there is no "creatorname" column/alias anywhere else in the query.
        $creatorsort = 'LOWER(' . $DB->sql_concat('u.lastname', 'u.firstname') . ')';

        $countsql = "SELECT COUNT(1)
                       FROM {local_artqtml_generations} g
                       JOIN {user} u ON u.id = g.userid
                      WHERE $where";
        $total = $DB->count_records_sql($countsql, $params);

        $orderby = self::SORTABLE[$sort] . ' ' . $dir . ', g.timecreated DESC';
        $sql = "SELECT g.*$namefields, $statuscase AS statusorder, $creatorsort AS creatorname,
                       (SELECT COUNT(1) FROM {local_artqtml_questions} q WHERE q.generationid = g.id) AS questioncount,
                       (SELECT COUNT(1) FROM {local_artqtml_questions} q
                         WHERE q.generationid = g.id AND q.validationsuggestion = :notevaluated) AS unvalidatedcount,
                       (SELECT COUNT(1) FROM {local_artqtml_questions} q
                         WHERE q.generationid = g.id AND q.movedout = 1) AS movedoutcount
                  FROM {local_artqtml_generations} g
                  JOIN {user} u ON u.id = g.userid
                 WHERE $where
              ORDER BY $orderby";

        $params['notevaluated'] = validation_suggestion::NOT_EVALUATED;
        $generations = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

        $stateurl = self::section_url($baseurl, $prefix, [
            $prefix . '_status'   => $status !== '' ? $status : null,
            $prefix . '_datefrom' => $datefrom !== '' ? $datefrom : null,
            $prefix . '_dateto'   => $dateto !== '' ? $dateto : null,
            $prefix . '_creator'  => $creator > 0 ? $creator : null,
            $prefix . '_sort'     => $sort,
            $prefix . '_dir'      => $dir,
        ]);

        $out = '';
        $out .= self::render_filterbar($prefix, $baseurl, $status, $datefrom, $dateto, $creator, $onlymine, $sort, $dir, $viewerid);

        if (empty($generations)) {
            $out .= $OUTPUT->notification(get_string('nogenerations', 'local_artqtml'), 'info');
            return $out;
        }

        $candelete = $onlymine && has_capability('local/artqtml:use', \context_system::instance());
        $out .= self::render_table($prefix, $stateurl, $generations, $sort, $dir, $candelete);

        $pagingurl = self::section_url($stateurl, $prefix, [$prefix . '_page' => null]);
        $out .= $OUTPUT->paging_bar($total, $page, $perpage, $pagingurl, $prefix . '_page');

        return $out;
    }

    /**
     * Build the SQL WHERE clause and params for a section's filters.
     *
     * @param int $viewerid logged-in user id
     * @param bool $onlymine true = "mine" section, false = "others" section
     * @param string $status status filter value, '' for any
     * @param string $datefrom Y-m-d date filter (created on/after), '' for none
     * @param string $dateto Y-m-d date filter (created on/before), '' for none
     * @param int $creator creator userid filter, 0 for any
     * @return array{0:string,1:array}
     */
    protected static function build_where(
        int $viewerid,
        bool $onlymine,
        string $status,
        string $datefrom,
        string $dateto,
        int $creator
    ): array {
        $where = $onlymine ? 'g.userid = :viewerid' : 'g.userid <> :viewerid';
        $params = ['viewerid' => $viewerid];

        if ($status !== '') {
            $where .= ' AND g.status = :status';
            $params['status'] = $status;
        }
        $fromts = self::parse_filter_date($datefrom);
        if ($fromts !== null) {
            $where .= ' AND g.timecreated >= :datefrom';
            $params['datefrom'] = $fromts;
        }
        $tots = self::parse_filter_date($dateto);
        if ($tots !== null) {
            // Inclusive end of that calendar day (same behaviour as former "23:59:59").
            $where .= ' AND g.timecreated <= :dateto';
            $params['dateto'] = $tots + DAYSECS - 1;
        }
        if ($creator > 0) {
            $where .= ' AND g.userid = :creator';
            $params['creator'] = $creator;
        }

        return [$where, $params];
    }

    /**
     * Parse a list-filter date (HTML5 type=date / ISO Y-m-d) into a local-midnight unix timestamp.
     *
     * Rejects empty, malformed, or non-canonical values so loose strtotime aliases never apply.
     *
     * @param string $value
     * @return int|null null if empty or not a valid Y-m-d
     */
    protected static function parse_filter_date(string $value): ?int {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = \DateTime::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        $errors = \DateTime::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $date->getTimestamp();
    }

    /**
     * Build a SQL CASE expression mapping status to the default sort weight.
     *
     * @param string $alias table alias for local_artqtml_generations
     * @return string
     */
    protected static function status_order_case(string $alias): string {
        $cases = [];
        foreach (self::STATUS_ORDER as $status => $weight) {
            $cases[] = "WHEN '$status' THEN $weight";
        }
        return "CASE $alias.status " . implode(' ', $cases) . ' ELSE 9 END';
    }

    /**
     * Render the filter bar for one section (-013).
     *
     * Filters auto-submit on change via a small inline script, giving a "real time"
     * Feel without a full AJAX rewrite of the table.
     *
     * @param string $prefix
     * @param \moodle_url $baseurl
     * @param string $status
     * @param string $datefrom
     * @param string $dateto
     * @param int $creator
     * @param bool $onlymine
     * @param string $sort current sort column, carried forward so filtering doesn't reset it
     * @param string $dir current sort direction, carried forward for the same reason
     * @param int $viewerid the logged-in user's id (m-06: this - not $creator, the currently
     *      selected filter value - is who "Others' generations" must exclude from itself)
     * @return string
     */
    protected static function render_filterbar(
        string $prefix,
        \moodle_url $baseurl,
        string $status,
        string $datefrom,
        string $dateto,
        int $creator,
        bool $onlymine,
        string $sort,
        string $dir,
        int $viewerid
    ): string {
        global $DB, $OUTPUT;

        $formid = 'artqtml-filter-' . $prefix;
        $html = \html_writer::start_tag('form', [
            'method' => 'get',
            'action' => $baseurl->out_omit_querystring(),
            'class'  => 'form-inline artqtml-filterbar mb-2',
            'id'     => $formid,
        ]);
        foreach ($baseurl->params() as $name => $value) {
            $html .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        $html .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $prefix . '_sort', 'value' => $sort]);
        $html .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $prefix . '_dir', 'value' => $dir]);

        $statusoptions = ['' => get_string('filterany', 'local_artqtml')];
        foreach (generation_status::VALUES as $statusvalue) {
            $statusoptions[$statusvalue] = generation_status::label($statusvalue);
        }
        $html .= \html_writer::select(
            $statusoptions,
            $prefix . '_status',
            $status,
            null,
            ['class' => 'mr-2', 'aria-label' => get_string('filterstatus', 'local_artqtml')]
        );

        $html .= \html_writer::empty_tag('input', [
            'type' => 'date', 'name' => $prefix . '_datefrom', 'value' => $datefrom,
            'class' => 'form-control mr-2', 'aria-label' => get_string('filterdatefrom', 'local_artqtml'),
        ]);
        $html .= \html_writer::empty_tag('input', [
            'type' => 'date', 'name' => $prefix . '_dateto', 'value' => $dateto,
            'class' => 'form-control mr-2', 'aria-label' => get_string('filterdateto', 'local_artqtml'),
        ]);

        if (!$onlymine) {
            $creatorids = $DB->get_records_sql(
                "SELECT DISTINCT g.userid FROM {local_artqtml_generations} g WHERE g.userid <> :viewerid",
                ['viewerid' => $viewerid]
            );
            $creatoroptions = ['0' => get_string('filterany', 'local_artqtml')];
            foreach (array_keys($creatorids) as $userid) {
                $user = \core_user::get_user($userid);
                if ($user) {
                    $creatoroptions[$userid] = fullname($user);
                }
            }
            $html .= \html_writer::select(
                $creatoroptions,
                $prefix . '_creator',
                (string) $creator,
                null,
                ['class' => 'mr-2', 'aria-label' => get_string('filtercreator', 'local_artqtml')]
            );
        }

        $html .= \html_writer::tag(
            'button',
            get_string('filterapply', 'local_artqtml'),
            ['type' => 'submit', 'class' => 'btn btn-secondary btn-sm']
        );
        $html .= \html_writer::end_tag('form');

        $html .= \html_writer::script(
            "document.querySelectorAll('#$formid select, #$formid input[type=date]').forEach(function(el) {" .
            "el.addEventListener('change', function() { document.getElementById('$formid').submit(); }); });"
        );

        return $html;
    }

    /**
     * Render the generations table for one section (/008, /015/016).
     *
     * @param string $prefix
     * @param \moodle_url $baseurl
     * @param \stdClass[] $generations rows from the section query
     * @param string $currentsort
     * @param string $currentdir
     * @param bool $candelete
     * @return string
     */
    protected static function render_table(
        string $prefix,
        \moodle_url $baseurl,
        array $generations,
        string $currentsort,
        string $currentdir,
        bool $candelete
    ): string {
        global $OUTPUT;

        $columns = [
            'name'          => get_string('colname', 'local_artqtml'),
            'creator'       => get_string('colcreatedby', 'local_artqtml'),
            'timecreated'   => get_string('coldate', 'local_artqtml'),
            'status'        => get_string('colstatus', 'local_artqtml'),
            'questioncount' => get_string('colquestioncount', 'local_artqtml'),
        ];

        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table table-striped artqtml-table';
        $table->colclasses = [
            0 => 'artqtml-col-name',
            1 => 'd-none d-lg-table-cell',
            2 => 'd-none d-lg-table-cell',
            3 => 'artqtml-col-status',
            4 => 'd-none d-md-table-cell',
            5 => 'd-none d-lg-table-cell',
            6 => 'artqtml-col-actions',
        ];
        $table->head = [];
        foreach ($columns as $key => $label) {
            $table->head[] = self::sort_header($prefix, $baseurl, $key, $label, $currentsort, $currentdir);
        }
        $table->head[] = self::sort_header(
            $prefix,
            $baseurl,
            'pendingvalidation',
            get_string('colpendingvalidation', 'local_artqtml'),
            $currentsort,
            $currentdir
        );
        // Not sortable: an actions column holds controls, not an orderable value.
        $table->head[] = get_string('colactions', 'local_artqtml');

        foreach ($generations as $generation) {
            // The badge shows the lang label ('started' -> "Megkezdett" / "Started")
            // Never the raw status key.
            $statusbadge = \html_writer::span(
                generation_status::label($generation->status),
                'badge ' . generation_status::badge_class($generation->status)
            );

            $openurl = self::open_url($generation);
            $openlink = \html_writer::link($openurl, get_string('actionopen', 'local_artqtml'), [
                'data-testid' => 'artqtml-list-open-link',
            ]);

            $actions = $openlink;
            $deletereason = '';
            if ($candelete) {
                if ((int) $generation->movedoutcount > 0) {
                    $deletereason = get_string('cannotdeletemoved', 'local_artqtml');
                    $actions .= ' | ' . \html_writer::tag(
                        'button',
                        get_string('actiondelete', 'local_artqtml'),
                        [
                            'type'        => 'button',
                            'class'       => 'btn btn-link p-0 align-baseline text-muted',
                            'disabled'    => 'disabled',
                            'title'       => $deletereason,
                            'data-testid' => 'artqtml-list-delete-disabled',
                        ]
                    );
                } else {
                    $deleteurl = new \moodle_url('/local/artqtml/delete.php', [
                        'id'      => $generation->id,
                        'sesskey' => sesskey(),
                    ]);
                    $deleteaction = new \confirm_action(
                        get_string('deleteconfirm', 'local_artqtml', format_string($generation->name))
                    );
                    $actions .= ' | ' . $OUTPUT->action_link(
                        $deleteurl,
                        get_string('actiondelete', 'local_artqtml'),
                        $deleteaction,
                        ['class' => 'text-danger', 'data-testid' => 'artqtml-list-delete-link']
                    );
                }
            }

            $actionscell = \html_writer::div($actions, 'artqtml-rowactions');
            if ($deletereason !== '') {
                $actionscell .= \html_writer::div($deletereason, 'small text-muted mt-1', [
                    'data-testid' => 'artqtml-list-delete-reason',
                ]);
            }

            $collapsedparts = [
                \html_writer::span(fullname($generation)),
                \html_writer::span(userdate($generation->timecreated, get_string('datetimeformat', 'local_artqtml'))),
                \html_writer::span(
                    get_string('colquestioncount', 'local_artqtml') . ': ' . $generation->questioncount
                ),
                \html_writer::span(
                    get_string('colpendingvalidation', 'local_artqtml') . ': ' .
                        $generation->unvalidatedcount . '/' . $generation->questioncount
                ),
            ];
            $namecell = format_string($generation->name) . \html_writer::div(
                implode('', $collapsedparts),
                'artqtml-collapsed-meta d-lg-none text-muted'
            );

            $row = new \html_table_row([
                $namecell,
                fullname($generation),
                userdate($generation->timecreated, get_string('datetimeformat', 'local_artqtml')),
                $statusbadge,
                $generation->questioncount,
                $generation->unvalidatedcount . '/' . $generation->questioncount,
                $actionscell,
            ]);
            // A technikai melléklet "Teszthorgonyok" szakasza: row anchor + content identifier, so a row
            // Assertion can select the generation it means rather than the first match.
            $row->attributes['data-testid'] = 'artqtml-list-row';
            $row->attributes['data-generationid'] = $generation->id;
            $table->data[] = $row;
        }

        return \html_writer::table($table);
    }

    /**
     * Build a clickable, direction-aware sort header for one column (/008).
     *
     * @param string $prefix
     * @param \moodle_url $baseurl
     * @param string $columnkey
     * @param string $label
     * @param string $currentsort
     * @param string $currentdir
     * @return string
     */
    protected static function sort_header(
        string $prefix,
        \moodle_url $baseurl,
        string $columnkey,
        string $label,
        string $currentsort,
        string $currentdir
    ): string {
        $sortkey = $columnkey === 'status' ? 'statusorder' : $columnkey;
        $isactive = $currentsort === $sortkey || ($sortkey === 'statusorder' && $currentsort === 'status');
        $newdir = ($isactive && $currentdir === 'ASC') ? 'DESC' : 'ASC';

        $url = self::section_url($baseurl, $prefix, [
            $prefix . '_sort' => $sortkey,
            $prefix . '_dir'  => $newdir,
            $prefix . '_page' => null,
        ]);

        $arrow = '';
        if ($isactive) {
            $arrow = ' ' . ($currentdir === 'ASC' ? '&#9650;' : '&#9660;');
        }

        return \html_writer::link($url, $label . $arrow);
    }

    /**
     * Return the open-URL for a generation, contextual to its current status.
     *
     * Public: upload.php's duplicate-warning panel ("Megnyitom a meglévőt") links to the same
     * Generation and must land on the same page the list page would, so the status->destination
     * Rule stays stated in exactly one place. Do not re-derive it at the call site.
     *
     * @param \stdClass $generation needs ->id and ->status
     * @return \moodle_url
     */
    public static function open_url(\stdClass $generation): \moodle_url {
        // No re-listing of the seven statuses - completed goes to the approval page, the
        // In-progress trio plus failed and partial go to the status page, and anything else
        // ('started') falls through to the settings page it can be resumed from.
        if ($generation->status === generation_status::COMPLETED) {
            return new \moodle_url('/local/artqtml/approve.php', ['generationid' => $generation->id]);
        }
        if (
            generation_status::is_in_progress($generation->status)
            || $generation->status === generation_status::FAILED
            || $generation->status === generation_status::PARTIAL
        ) {
            return new \moodle_url('/local/artqtml/status.php', ['generationid' => $generation->id]);
        }

        return new \moodle_url('/local/artqtml/generate.php', ['id' => $generation->id]);
    }

    /**
     * The same status->destination rule as {@see self::open_url()}, resolved from an id alone.
     *
     * Public: the event classes' get_url() only ever hold the generation's id (objectid), and a
     * Log entry is read long after the event fired - so the link must lead where the generation
     * Can be acted on now, not where it was relevant at the time. Keeping the lookup here means
     * The rule itself still exists in exactly one place.
     *
     * @param int $generationid
     * @return \moodle_url|null null if the generation has since been deleted; core's log report
     *      renders such an entry unlinked, which beats linking to a page that would fail
     */
    public static function open_url_by_id(int $generationid): ?\moodle_url {
        global $DB;

        $generation = $DB->get_record(
            'local_artqtml_generations',
            ['id' => $generationid],
            'id, status'
        );
        if (!$generation) {
            return null;
        }

        return self::open_url($generation);
    }

    /**
     * Build a URL for this section only, overriding/removing the given params.
     *
     * @param \moodle_url $baseurl
     * @param string $prefix
     * @param array<string,mixed> $overrides null values remove the param
     * @return \moodle_url
     */
    protected static function section_url(\moodle_url $baseurl, string $prefix, array $overrides): \moodle_url {
        $url = new \moodle_url($baseurl);
        foreach ($overrides as $name => $value) {
            if ($value === null) {
                $url->remove_params($name);
            } else {
                $url->param($name, $value);
            }
        }
        return $url;
    }
}
