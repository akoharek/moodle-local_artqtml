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
 * Keeps the upload page's Continue button disabled until the Generation name, Shortname, and
 * Source text (or an uploaded file) are all filled in (Felt-025).
 *
 * Plain JS (no AMD/grunt build), matching this plugin's other JS files.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

window.ArtqtmlContinueButton = (function() {
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
        var submitbtn = document.querySelector('input[type="submit"][name="submitbutton"], button[type="submit"][name="submitbutton"]');
        if (!name || !shortname || !textarea || !submitbtn) {
            return;
        }

        // Defer to uploadconflict.js's notion of "is a file actually attached" (it tracks
        // drop/replace state that a simple DOM/value check here can't see) so both scripts
        // agree on what will actually be submitted.
        function hasFile() {
            return !!window.ArtqtmlUploadConflict && window.ArtqtmlUploadConflict.hasFile();
        }

        var fitem = filehidden ? filehidden.closest('.fitem') : null;
        var filelist = fitem ? fitem.querySelector('.filepicker-filelist') : null;

        function update() {
            var ready = name.value.trim() !== '' && shortname.value.trim() !== '' &&
                (textarea.value.trim() !== '' || hasFile());
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

    return {init: init};
})();
