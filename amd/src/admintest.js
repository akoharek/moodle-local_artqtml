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
 * Admin "Test connection" button for Claude/Gemini tabs.
 *
 * @module     local_artqtml/admintest
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {
    'use strict';

    /**
     * Call the local_artqtml_test_connection external function.
     *
     * @param {string} provider 'claude' or 'gemini'
     * @return {Promise<{success: boolean, message: string, models: string[]}>}
     */
    function callTestConnection(provider) {
        return Ajax.call([{
            methodname: 'local_artqtml_test_connection',
            args: {provider: provider}
        }])[0];
    }

    /**
     * Wire the "Test connection" button on one LLM tab.
     *
     * @param {string} provider 'claude' or 'gemini'
     * @param {string} buttonid id of the test button
     * @param {string} statusid id of the status span
     * @param {string} testinglabel shown while the request is in flight
     * @param {string} errorunknownlabel shown when the request fails
     * @return {void}
     */
    function initButton(provider, buttonid, statusid, testinglabel, errorunknownlabel) {
        var button = document.getElementById(buttonid);
        var status = document.getElementById(statusid);

        if (!button || !status) {
            return;
        }

        button.addEventListener('click', function() {
            status.textContent = testinglabel;

            callTestConnection(provider).then(function(data) {
                status.textContent = data.message;
                status.className = 'ms-2 ' + (data.success ? 'text-success' : 'text-danger');
                if (data.success) {
                    // The dropdown only exists once a test has succeeded, and it is built server-side
                    // from the cache that test populated.
                    window.location.reload();
                }
                return null;
            }).catch(function() {
                status.textContent = errorunknownlabel;
                status.className = 'ms-2 text-danger';
            });
        });
    }

    return {
        initButton: initButton
    };
});
