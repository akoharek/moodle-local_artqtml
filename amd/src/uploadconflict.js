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
 * Moodle's filepicker element assigns its hidden draftitemid input a real, non-zero value as
 * soon as it renders - before any file is picked - and that value does not change when a file
 * is added or removed. Whether a file is actually attached can only be read from the
 * filepicker's own filelist DOM, never from the hidden input's value/zero-ness. The hidden input
 * is only ever written here at form submission time, to make upload.php ignore a "dropped" file.
 *
 * @module     local_artqtml/uploadconflict
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax'], function($, Ajax) {
    'use strict';

    /**
     * Call the local_artqtml_extract_text external function via core/ajax.
     *
     *
     * @param {number} draftitemid
     * @return {Promise<{text: string, success: boolean, reason: string, message: string}>} the extraction result
     */
    function callExtractText(draftitemid) {
        return Ajax.call([{
            methodname: 'local_artqtml_extract_text',
            args: {draftitemid: draftitemid}
        }])[0];
    }

    var sharedHasFile = function() {
        return false;
    };

    /**
     * Wire up the conflict detection and file-text extraction.
     *
     * @param {string} textareaid id of the source text textarea
     * @param {string} filehiddenid id of the filepicker element's hidden draftitemid input
     * @param {{filepromptmessage: string, textpromptmessage: string, fileignorednote: string,
     *      extractfailedmessage: string}} messages
     * @return {void}
     */
    function init(textareaid, filehiddenid, messages) {
        var textarea = document.getElementById(textareaid);
        var filehidden = document.getElementById(filehiddenid);
        if (!textarea || !filehidden) {
            return;
        }

        var fitem = filehidden.closest('.fitem');
        var filelist = fitem ? fitem.querySelector('.filepicker-filelist') : null;

        var warnedtext = false;
        var note = null;
        var filedetached = false;
        var lastfilehref = null;
        var lastknowngoodvalue = textarea.value;
        var suppressconflict = false;

        /**
         * The currently attached file's link element in the filepicker's own filelist DOM.
         *
         * @return {Element|null}
         */
        function currentFileLink() {
            return filelist ? filelist.querySelector('a[href*="draftfile.php"]') : null;
        }

        /**
         * Whether a file is currently attached and not "dropped".
         *
         * @return {boolean}
         */
        function hasFile() {
            return !filedetached && !!currentFileLink();
        }
        sharedHasFile = hasFile;

        /**
         * Show the note under the filepicker, creating it on first use.
         *
         *
         * @param {string} text the message to show
         * @return {void}
         */
        function showNote(text) {
            if (!note) {
                note = document.createElement('div');
                note.className = 'text-muted small mt-1';
                note.setAttribute('data-region', 'artqtml-file-ignored-note');
                if (fitem) {
                    fitem.appendChild(note);
                }
            }
            // Reassigned rather than kept, because a second file can turn a "loaded" note into an
            // "ignored" one and vice versa.
            note.textContent = text;
        }

        /**
         * Mark the currently attached file as detached (its draftitemid gets zeroed at form
         * submission), and say why in a way that matches what just happened.
         *
         * @param {boolean} loaded true when the file's text was loaded into the textarea
         * @return {void}
         */
        function dropFile(loaded) {
            filedetached = true;
            showNote(loaded ? messages.fileloadednote : messages.fileignorednote);
        }

        /**
         * Programmatically set the textarea's value without re-triggering the
         * "user typed over an attached file" conflict check.
         *
         * @param {string} value
         * @return {void}
         */
        function setText(value) {
            textarea.value = value;
            lastknowngoodvalue = value;
            // Programmatic value changes must not re-enter the "user typed over an attached
            // file" conflict check below - only genuine user input should trigger that.
            suppressconflict = true;
            textarea.dispatchEvent(new Event('input', {bubbles: true}));
            suppressconflict = false;
        }

        /**
         * Extract a picked file's text and load it into the textarea, dropping the file
         * either way (success or failure) so it's never silently included alongside the
         * loaded text.
         *
         * @param {string} draftitemid
         * @return {void}
         */
        function loadExtractedTextThenDropFile(draftitemid) {
            callExtractText(draftitemid).then(function(result) {
                dropFile(result.success === true);
                if (!result.success) {
                    // Nothing is loaded into the textarea: the response carries no text at all,
                    // and putting a partial document there would be worse than putting none.
                    // Whatever the teacher had typed stays where it was - the failure message
                    // tells them to paste their text into this field, so it must still hold it.
                    // textContent/alert rather than innerHTML - the message is localised plain
                    // text and must not be able to render markup.
                    // eslint-disable-next-line no-alert -- plain-text server message for teacher
                    window.alert(result.message);
                    return null;
                }
                setText(result.text);
                return null;
            }).catch(function() {
                dropFile();
                // eslint-disable-next-line no-alert -- plain-text fallback when extract AJAX fails
                window.alert(messages.extractfailedmessage);
            });
        }

        if (filelist) {
            lastfilehref = currentFileLink() ? currentFileLink().href : null;

            var observer = new MutationObserver(function() {
                var link = currentFileLink();
                var href = link ? link.href : null;
                if (href === lastfilehref) {
                    return;
                }
                lastfilehref = href;
                if (!href) {
                    // File removed via the filepicker's own UI, nothing to react to.
                    return;
                }

                // A new (or replacement) file just appeared - it supersedes any earlier drop.
                filedetached = false;
                var draftitemid = filehidden.value;

                if (textarea.value.trim() === '') {
                    loadExtractedTextThenDropFile(draftitemid);
                    return;
                }
                // eslint-disable-next-line no-alert -- teacher chooses file vs existing textarea text
                if (window.confirm(messages.filepromptmessage)) {
                    loadExtractedTextThenDropFile(draftitemid);
                } else {
                    dropFile();
                }
            });
            observer.observe(filelist, {childList: true, subtree: true});
        }

        textarea.addEventListener('input', function() {
            if (suppressconflict) {
                return;
            }
            if (!hasFile()) {
                lastknowngoodvalue = textarea.value;
                return;
            }
            if (warnedtext) {
                return;
            }
            warnedtext = true;
            // eslint-disable-next-line no-alert -- teacher chooses text vs attached file
            if (window.confirm(messages.textpromptmessage)) {
                dropFile();
                lastknowngoodvalue = textarea.value;
            } else {
                // Revert, then re-dispatch so the character/word counter and the Continue
                // button's enabled state (separate 'input' listeners on this same textarea)
                // re-sync to the reverted value. warnedtext already guards re-entering this
                // branch, so this can't loop.
                textarea.value = lastknowngoodvalue;
                textarea.dispatchEvent(new Event('input', {bubbles: true}));
            }
        });

        var form = textarea.form;
        if (form) {
            form.addEventListener('submit', function() {
                if (filedetached) {
                    filehidden.value = '0';
                }
            });
        }
    }

    /**
     * Whether a file is currently attached and not "dropped".
     *
     * @return {boolean}
     */
    function hasFile() {
        return sharedHasFile();
    }

    return {
        init: init,
        hasFile: hasFile
    };
});
