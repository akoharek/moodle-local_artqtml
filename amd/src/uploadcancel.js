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
 * Confirm before discarding upload form data via Cancel.
 *
 * @module     local_artqtml/uploadcancel
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    /**
     * @param {string} confirmmessage localised confirm dialog text
     */
    function init(confirmmessage) {
        var cancelbtn = document.querySelector('input[name="cancel"], button[name="cancel"]');
        if (!cancelbtn) {
            return;
        }

        cancelbtn.addEventListener('click', function(e) {
            // eslint-disable-next-line no-alert -- native confirm before discarding upload form
            if (!window.confirm(confirmmessage)) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);
    }

    return {
        init: init
    };
});
