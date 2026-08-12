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
                    // the dropdown only exists once a test has succeeded, and it is built server-side from the cache that test populated.
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
