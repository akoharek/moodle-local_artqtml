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
 * Model chooser for the Generator/Validator LLM tabs (Admin-047/048/049/050/051).
 *
 * A select whose options come from the cached provider model list, never from a hardcoded array
 * and never from a synchronous network call. Replaces the free-text field, which Admin-048 forbids
 * outright: "A modell azonosítója kizárólag legördülőből választható; szabad szöveges beviteli mező
 * a Generátor és a Validátor LLM fülön nem jelenhet meg".
 *
 * Three states, decided entirely by what is in the cache:
 *
 *  - No cached list (no successful connection test yet): Admin-050 says the select must not appear
 *    at all, and the field explains that a connection test is needed first.
 *  - Cached list present: the structured-output capable models are offered.
 *  - Cached list present but the saved model is not in it: Admin-049 requires the saved value to
 *    stay selected and stay stored, with a visible warning. It is never silently dropped or
 *    overwritten - that is precisely how a working install would become mysteriously broken.
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

        // Admin-051: no factory default. "A claudemodel és a geminimodel beállításnak nincs gyári
        // alapértelmezett értéke: friss telepítésen mindkettő üresen indul." A baked-in default can
        // be dead on arrival, because availability differs by provider AND by API project, and
        // providers retire models - gemini-2.0-flash did exactly that while still being listed.
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

        // Admin-049: a saved model missing from the list stays selectable, so saving the form
        // cannot silently rewrite it to something else. It is only marked "unavailable", though,
        // when a list has actually been fetched and it is missing from THAT - with an empty cache no
        // list has been fetched, so the model gets a plain label and the availability warning is left
        // to output_html()'s neutral-vs-warning decision (Admin-049 corrected).
        $saved = (string) $this->get_setting();
        if ($saved !== '' && !array_key_exists($saved, $this->choices)) {
            $listfetched = model_list::get_cached($this->provider) !== null;
            $this->choices[$saved] = $listfetched
                ? $saved . ' — ' . get_string('modelunavailable_warning', 'local_artqtml')
                : $saved;
        }

        // An empty choice is always offered: Admin-051 starts both settings empty, and an admin
        // must be able to represent "not chosen yet" rather than being forced onto a model.
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

        // Admin-050: "Sikeres kapcsolatteszt előtt a legördülő nem jelenik meg; ilyenkor a felület
        // jelzi, hogy a modellválasztáshoz kapcsolatteszt szükséges." The cache is only ever
        // written by a successful fetch, so "no cache" is exactly "no successful test yet".
        if ($cached === null && $saved === '') {
            $notice = \html_writer::div(
                get_string('modelselectneedstest', 'local_artqtml'),
                'alert alert-info mb-0',
                ['data-testid' => 'artqtml-admin-modelneedstest-' . $this->provider]
            );

            return format_admin_setting($this, $this->visiblename, $notice, $this->description, true, '', null, $query);
        }

        $html = parent::output_html($data, $query);

        // BL-44: what the last structural sweep found, read from the check log rather than passed
        // back from the button. The button's own JavaScript reloads this page on success, so a
        // message returned by the call is wiped before anyone reads it - and routing it through a
        // session notification hung the request for over ten minutes (measured 2026-08-03, while
        // the probes themselves took 33 seconds). Rendering it here turns the reload from the thing
        // that destroyed the verdict into the thing that shows it, and it still says so tomorrow.
        // The summary is scoped to THIS plugin version, exactly like the exclusions it describes -
        // otherwise the two could contradict each other. A version bump reopens every excluded model
        // (deliberately: on 2026-08-03 the exclusions came from our own parser defect), so a sweep
        // made under an older build no longer describes the list being shown.
        //
        // Which leaves the case this branch exists for, and it is the one that bit on the day this
        // was written: after a version bump there is no sweep for the current build, and saying
        // NOTHING is worse than saying so. A silent settings page reads as "everything is fine".
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

        // Three states for a saved model, told apart by whether a list has been fetched at all
        // (get_cached() is null only when the 24-hour cache is empty - fresh install, before any
        // successful connection test). The availability warning presupposes a fetched list; applying
        // it to an empty cache was the Admin-049 defect - it labelled a working model unavailable.
        if ($saved !== '') {
            if ($cached === null) {
                // Empty cache: no list to check against, so no availability claim either way. A
                // neutral notice, not a false warning (Admin-049 corrected).
                $html .= \html_writer::div(
                    get_string('modellistnotfetched', 'local_artqtml'),
                    'alert alert-info mt-2',
                    ['data-testid' => 'artqtml-admin-modellistnotfetched-' . $this->provider]
                );
            } else if (!model_list::is_listed($this->provider, $saved)) {
                // A list WAS fetched and the saved model is not in it - the real Admin-049 case.
                // Name the problem where the admin is looking, not only in the option label.
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
