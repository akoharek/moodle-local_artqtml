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
 * Enable the upload Continue button only when required fields are filled.
 *
 * @module     local_artqtml/continuebutton
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['local_artqtml/uploadconflict'], function(UploadConflict) {
    'use strict';

    /**
     * Wire up the Continue button's enabled state to the required fields.
     *
     * @param {string} nameid Generation name text field id
     * @param {string} shortnameid Shortname text field id
     * @param {string} textareaid Source text textarea id
     * @param {string} filehiddenid filepicker element's hidden draftitemid input id
     */
    function init(nameid, shortnameid, textareaid, filehiddenid) {
        var name = document.getElementById(nameid);
        var shortname = document.getElementById(shortnameid);
        var textarea = document.getElementById(textareaid);
        var filehidden = document.getElementById(filehiddenid);
        var submitbtn = document.querySelector(
            'input[type="submit"][name="submitbutton"], button[type="submit"][name="submitbutton"]'
        );
        if (!name || !shortname || !textarea || !submitbtn) {
            return;
        }

        var fitem = filehidden ? filehidden.closest('.fitem') : null;
        var filelist = fitem ? fitem.querySelector('.filepicker-filelist') : null;

        /**
         * Enable or disable the Continue button from current field values.
         */
        function update() {
            var ready = name.value.trim() !== '' && shortname.value.trim() !== '' &&
                (textarea.value.trim() !== '' || UploadConflict.hasFile());
            submitbtn.disabled = !ready;
        }

        [name, shortname, textarea].forEach(function(el) {
            el.addEventListener('input', update);
        });
        if (filelist) {
            new MutationObserver(update).observe(filelist, {childList: true, subtree: true});
        }

        update();
    }

    return {
        init: init
    };
});
