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
 * Live character/word/token counter for the source text upload page.
 *
 * Plain JS (no AMD/grunt build), matching js/status.js's approach elsewhere in this plugin.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

window.ArtqtmlTextCounter = (function() {
    'use strict';

    /**
     * Rough token estimate: ~4 characters per token, a common approximation for Latin-script
     * text used only for the UI warning, not for actual API billing.
     *
     * @param {number} charcount
     * @return {number}
     */
    function estimateTokens(charcount) {
        return Math.ceil(charcount / 4);
    }

    /**
 * Wire up a textarea to update a counter region on every keystroke.
 *
 * THE CONTEXT-WINDOW COLOURING IS GONE, removed 2026-08-04 evening. It was the behaviour for
 * the case where no source-text limit applied, and there is no such case: the server's
 * source_text_limit::token_limit() derives a limit from the context window when none is set
 * explicitly and never returns less than 1. So the branch could not be reached, and a reader
 * comparing this file with the settings screen would have concluded the two disagreed.
 *
 * @param {string} textareaid
 * @param {string} counterid
 * @param {number} sourcetokenlimit the effective server-side source-text limit in estimated
 * tokens (: the warning is relative to this, not a flat count)
 * @param {string} labeltemplate the "textcounterlabel" lang string, pre-rendered server-side
 * @param {string} limittemplate the "textcounterlimitlabel" lang string, already carrying the
 * limit, appended after the counts
 * @param {string} errormessage the localised message shown by the browser when the text is
 * over the limit
 */
    function init(textareaid, counterid, sourcetokenlimit, labeltemplate, limittemplate, errormessage) {
        var textarea = document.getElementById(textareaid);
        var counter = document.getElementById(counterid);
        if (!textarea || !counter) {
            return;
        }

        var limit = sourcetokenlimit > 0 ? sourcetokenlimit : 0;
        var warnthreshold = limit * 0.9;

        function update() {
            var text = textarea.value;
            var chars = text.length;
            var words = text.trim() === '' ? 0 : text.trim().split(/\s+/).length;
            var tokens = estimateTokens(chars);

            var label = labeltemplate
                .replace('__CHARS__', chars)
                .replace('__WORDS__', words)
                .replace('__TOKENS__', tokens);
            if (limit > 0 && limittemplate) {
                label = label + ' \u2014 ' + limittemplate;
            }
            counter.textContent = label;

            counter.classList.remove('text-warning', 'text-danger');

            if (limit > 0) {
                if (tokens > limit) {
                    counter.classList.add('text-danger');
                    // Refuses an ordinary form submission and shows the reason at the field. Not a
                    // security control - it is a courtesy, so the user is not told about the size
                    // only after a page load.
                    textarea.setCustomValidity(errormessage || '');
                } else {
                    textarea.setCustomValidity('');
                    if (tokens >= warnthreshold) {
                        counter.classList.add('text-warning');
                    }
                }
            }
        }

        textarea.addEventListener('input', update);
        update();
    }

    return {init: init};
})();
