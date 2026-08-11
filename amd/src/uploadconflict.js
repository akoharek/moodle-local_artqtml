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
 * Extracts a picked file's text into the source text box (Felt-010/011), and warns the user
 * when they mix the two source-text input modes on the upload page (Felt-012/013/014).
 *
 * Moodle's filepicker element assigns its hidden draftitemid input a real, non-zero value as
 * soon as it renders - before any file is picked - and that value does not change when a file
 * is added or removed. Whether a file is actually attached can only be read from the
 * filepicker's own filelist DOM, never from the hidden input's value/zero-ness. The hidden input
 * is only ever written here at form submission time, to make upload.php ignore a "dropped" file.
 *
 * A confirm() popup fires when either input mode would overwrite the other:
 *  - a file is picked while the textarea already has typed/pasted content (Felt-012): confirming
 *    replaces the box's content with the file's extracted text - and only once that text has
 *    arrived, so a refused file leaves the typed content standing; declining drops the file.
 *  - the textarea is typed/pasted into while a file is already picked (Felt-013): confirming
 *    drops the file and keeps typing; declining reverts the textarea to its pre-edit content and
 *    keeps the file.
 * When the textarea is empty, a newly picked file's text is loaded straight in with no prompt.
 *
 * @module     local_artqtml/uploadconflict
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax'], function($, Ajax) {
    'use strict';

    /**
     * Call the local_artqtml_extract_text external function via core/ajax.
     *
     * Returns the whole response rather than just the text, because since 2026-08-04 the endpoint
     * can refuse a document - as too large, unreadable, or of an unsupported type.
     * It answers with empty text and a localised explanation instead of throwing, so the teacher
     * is told what to do about it; a thrown error would arrive here as a generic failure.
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
         * The text is a parameter because the same "the file itself is not submitted" mechanism
         * covers two situations that mean opposite things to the teacher: either the file's text
         * WAS loaded into the box, or the file is being ignored in favour of what they typed.
         * Until 2026-08-06 both said "the uploaded file will be ignored; only your typed text will
         * be used" - which, on a successful extraction, contradicted what the teacher could see
         * happening in the box right in front of them.
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
                    window.alert(result.message);
                    return null;
                }
                setText(result.text);
                return null;
            }).catch(function() {
                dropFile();
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
                if (window.confirm(messages.filepromptmessage)) {
                    // The textarea is deliberately NOT cleared here. The prompt promises the
                    // typed text will be *replaced* by the file's text, and until the endpoint
                    // answers there is nothing to replace it with. Clearing first meant a refused
                    // file (BL-53) left the box empty and the failure message then advised pasting
                    // the text into the very field it had just emptied. setText() below overwrites
                    // on success, so the end state is unchanged; only the failure path differs.
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

        // Only touch the hidden field at the very last moment, so Moodle's own filepicker JS
        // is never fighting our own writes to it while the user is still interacting.
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
     * js/continuebutton.js (still a plain, non-AMD script) reads this via the same
     * window.ArtqtmlUploadConflict global this module always exposed, so both scripts agree
     * on what will actually be submitted - migrating this module to AMD must not require
     * migrating that one in lockstep.
     *
     * @return {boolean}
     */
    function hasFile() {
        return sharedHasFile();
    }

    window.ArtqtmlUploadConflict = {
        init: init,
        hasFile: hasFile
    };

    return {
        init: init,
        hasFile: hasFile
    };
});
