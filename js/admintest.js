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
 * "Test connection" + dynamic model list buttons on the Generator/Validator LLM admin tabs
 * (Admin-011/012/017/018). Plain JS (no AMD/grunt build).
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

window.ArtqtmlAdminTest = (function() {
    'use strict';

    /**
     * Call the local_artqtml_test_connection external function.
     *
     * @param {string} provider 'claude' or 'gemini'
     * @return {Promise<{success: boolean, message: string, models: string[]}>}
     */
    function callTestConnection(provider) {
        var url = M.cfg.wwwroot + '/lib/ajax/service.php?sesskey=' +
            encodeURIComponent(M.cfg.sesskey) + '&info=local_artqtml_test_connection';

        var body = JSON.stringify([{
            index: 0,
            methodname: 'local_artqtml_test_connection',
            args: {provider: provider}
        }]);

        return fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: body,
            credentials: 'same-origin'
        }).then(function(response) {
            return response.json();
        }).then(function(results) {
            var result = results && results[0];
            if (!result || result.error) {
                throw new Error(
                    (result && result.exception && result.exception.message) || window.M.artqtml_admintest.errorunknown
                );
            }
            return result.data;
        });
    }

    /**
     * Wire the "Test connection" button on one LLM tab.
     *
     * Admin-048: this no longer populates a model control. It used to fill its own <select> and
     * mirror the choice into a text input, leaving two model controls on the tab at once. The
     * model field is now itself a select fed from the cached list (setting_modelselect), so a
     * successful test refreshes that cache server-side and the page reloads to pick it up.
     *
     * @param {string} provider 'claude' or 'gemini'
     * @param {string} buttonid id of the test button
     * @param {string} statusid id of the status span
     * @return {void}
     */
    function init(provider, buttonid, statusid) {
        var button = document.getElementById(buttonid);
        var status = document.getElementById(statusid);

        if (!button || !status) {
            return;
        }

        button.addEventListener('click', function() {
            status.textContent = (window.M && M.artqtml_admintest)
                ? M.artqtml_admintest.testing : '...';

            callTestConnection(provider).then(function(data) {
                status.textContent = data.message;
                status.className = 'ml-2 ' + (data.success ? 'text-success' : 'text-danger');
                if (data.success) {
                    // Admin-050: the dropdown only exists once a test has succeeded, and it is
                    // built server-side from the cache that test populated.
                    window.location.reload();
                }
                return null;
            }).catch(function() {
                status.textContent = (window.M && M.artqtml_admintest)
                    ? M.artqtml_admintest.errorunknown : 'Error';
                status.className = 'ml-2 text-danger';
            });
        });
    }

    return {init: init};
})();
