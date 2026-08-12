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
 * Model chooser for the Generator/Validator LLM tabs.
 *
 * Three states, decided entirely by what is in the cache:
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\admin;

use local_artqtml\local\model_list;

/**
 * A select populated from the cached provider model list.
 */
class setting_modelselect extends \admin_setting_configselect {
    /** @var string the provider this setting chooses a model for. */
    protected $provider;

    /**
     * Build the model chooser for one provider.
     *
     * @param string $name unique ascii name, e.g. 'local_artqtml/claudemodel'
     * @param string $visiblename localised name
     * @param string $description localised description
     * @param string $provider one of model_list::PROVIDERS
     */
    public function __construct(string $name, string $visiblename, string $description, string $provider) {
        $this->provider = $provider;

        parent::__construct($name, $visiblename, $description, '', null);
    }

    /**
     * Load the choices at render time from the cache (never from the network).
     *
     * @return bool
     */
    public function load_choices() {
        // Moodle calls this more than once per request; recomputing is harmless but pointless, and
        // the parent declares $choices as an array, so "already loaded" is "not empty" rather than
        // an is_array() check.
        if (!empty($this->choices)) {
            return true;
        }

        $this->choices = model_list::selectable_options($this->provider);

        $saved = (string) $this->get_setting();
        if ($saved !== '' && !array_key_exists($saved, $this->choices)) {
            $listfetched = model_list::get_cached($this->provider) !== null;
            $this->choices[$saved] = $listfetched
                ? $saved . ' — ' . get_string('modelunavailable_warning', 'local_artqtml')
                : $saved;
        }

        if (!array_key_exists('', $this->choices)) {
            $this->choices = ['' => get_string('choosedots')] + $this->choices;
        }

        return true;
    }

    /**
     * Render the field, or - before any successful connection test - an explanation instead.
     *
     * @param mixed $data
     * @param string $query
     * @return string
     */
    public function output_html($data, $query = '') {
        $cached = model_list::get_cached($this->provider);
        $saved = (string) $this->get_setting();

        if ($cached === null && $saved === '') {
            $notice = \html_writer::div(
                get_string('modelselectneedstest', 'local_artqtml'),
                'alert alert-info mb-0',
                ['data-testid' => 'artqtml-admin-modelneedstest-' . $this->provider]
            );

            return format_admin_setting($this, $this->visiblename, $notice, $this->description, true, '', null, $query);
        }

        $html = parent::output_html($data, $query);

        $sweep = \local_artqtml\local\model_check_log::latest_sweep($this->provider);
        if ($sweep !== null) {
            $html .= \html_writer::div(
                get_string('modelcheckswept', 'local_artqtml', (object) [
                    'checked' => $sweep['checked'],
                    'failed'  => $sweep['failed'],
                    'when'    => userdate($sweep['timecreated'], get_string('datetimeformat', 'local_artqtml')),
                ]),
                'small mt-2 ' . ($sweep['failed'] > 0 ? 'text-danger' : 'text-muted'),
                ['data-testid' => 'artqtml-admin-modelsweep-' . $this->provider]
            );
        } else if ($cached !== null) {
            $html .= \html_writer::div(
                get_string('modelchecknotswept', 'local_artqtml'),
                'small mt-2 text-muted',
                ['data-testid' => 'artqtml-admin-modelnotswept-' . $this->provider]
            );
        }

        if ($saved !== '') {
            if ($cached === null) {
                $html .= \html_writer::div(
                    get_string('modellistnotfetched', 'local_artqtml'),
                    'alert alert-info mt-2',
                    ['data-testid' => 'artqtml-admin-modellistnotfetched-' . $this->provider]
                );
            } else if (!model_list::is_listed($this->provider, $saved)) {
                $html .= \html_writer::div(
                    get_string('modelunavailable_warning', 'local_artqtml'),
                    'alert alert-warning mt-2',
                    ['data-testid' => 'artqtml-admin-modelunavailable-' . $this->provider]
                );
            }
        }

        return $html;
    }
}
