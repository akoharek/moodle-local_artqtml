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
 * Live total and token estimate for the question settings page.
 *
 * BL-35: the step1/step2 difference indicator is gone with the two-axis form it compared.
 *
 * @module     local_artqtml/generatesettings
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/str'], function($, Str) {
    'use strict';

    /** @var {string[]} the six spec type codes (functional spec ch.1), matching count_<code> fields. */
    var TYPE_CODES = ['IH', 'FE', 'FT', 'SR', 'EH', 'RV'];

    /**
     * @var {Object} the three levels each difficulty mode offers.
     *
     * BL-35: these used to name generation-wide fields in step 1 (bloom_remember, scale_easy...).
     * They now name the columns of the step 2 grid - one count per type per level - because which
     * type got the easy question used to be decided by nobody.
     */
    var MODE_LEVELS = {
        scale: ['easy', 'medium', 'hard'],
        bloom: ['remember', 'understand', 'apply']
    };

    /** @var {string} sentinel-substituted "~__N__ tokens" template, replaced once Str resolves. */
    var tokensTemplate = '~__N__';

    /**
     * Read an element's numeric value by its Moodle-generated id ("id_" + element name),
     * returning 0 if the element doesn't exist or isn't currently visible (hideIf-hidden
     * fields stay in the DOM but should not count towards the total).
     *
     * @param {string} elementname the mform element name, e.g. "count_IH"
     * @return {number}
     */
    function fieldValue(elementname) {
        var el = document.getElementById('id_' + elementname);
        if (!el || el.offsetParent === null) {
            return 0;
        }
        return parseInt(el.value, 10) || 0;
    }

    /**
     * Read an advcheckbox field's checked state by its Moodle-generated id, the checkbox
     * equivalent of fieldValue() above (false if the element doesn't exist, or isn't currently
     * visible).
     *
     * @param {string} elementname the mform element name, e.g. "feedback_IH"
     * @return {boolean}
     */
    function fieldChecked(elementname) {
        var el = document.getElementById('id_' + elementname);
        if (!el || el.offsetParent === null) {
            return false;
        }
        return !!el.checked;
    }

    /**
     * Recompute the question total and the submit button's enabled state.
     *
     * @param {{step2total: string}} labels lang-string label for the single live total region,
     *      so it reads e.g. "Összesen: 2" instead of a bare "2"
     * @param {number} tokenbudget admin-configured monthly token budget (Beal-019/020/021),
     *      0/negative means unlimited - no progress bar/percentage in that case
     * @param {number} tokenwarningpct admin-configured warning threshold, percent of tokenbudget
     */
    function update(labels, tokenbudget, tokenwarningpct) {
        var modeEl = document.getElementById('id_difficultymode');
        var mode = modeEl ? modeEl.value : 'scale';

        // BL-35: one total. The active mode's grid (or free-text per-type fields) holds the counts;
        // there is nothing left to cross-check against a step-1 total.
        var total = 0;
        if (MODE_LEVELS[mode]) {
            TYPE_CODES.forEach(function(code) {
                MODE_LEVELS[mode].forEach(function(level) {
                    total += fieldValue('matrix_' + code + '_' + level);
                });
            });
        } else {
            TYPE_CODES.forEach(function(code) {
                total += fieldValue('count_' + code);
            });
        }

        var region = document.getElementById('artqtml-step2total');
        if (region) {
            region.textContent = labels.step2total + ': ' + total;
        }

        // BL-35: one question is all that is left to ask - has the teacher asked for anything at
        // all. The old rule (Beal-009) also required the total to equal the previous generation's
        // total for the same source text, which was the enforcement arm of the X/Y difference
        // indicator. That indicator went with the two-axis form; the rule outlived it by an
        // afternoon, invisible, and the first thing it blocked was the "generate the missing
        // types" follow-up, which asks for less on purpose. Removed with András's decision,
        // 2026-08-01: a grey button with no explanation on the page is not a warning.
        var submitbutton = document.getElementById('artqtml-submitbutton');
        var enabled = total > 0;

        if (submitbutton) {
            submitbutton.disabled = !enabled;
        }

        var tokenregion = document.getElementById('artqtml-tokenestimate');
        if (tokenregion) {
            // Gen-027: ~60 extra tokens per question for whichever types have their own
            // "feedback" checkbox on (Gen-025 - no longer a single generation-wide switch), on
            // top of the flat 300-token baseline every question costs regardless of feedback.
            var estimate = 0;
            TYPE_CODES.forEach(function(code) {
                var count = 0;
                if (MODE_LEVELS[mode]) {
                    MODE_LEVELS[mode].forEach(function(level) {
                        count += fieldValue('matrix_' + code + '_' + level);
                    });
                } else {
                    count = fieldValue('count_' + code);
                }
                var perquestion = 300 + (fieldChecked('feedback_' + code) ? 60 : 0);
                estimate += count * perquestion;
            });
            var estimatetext = tokensTemplate.replace('__N__', estimate);
            if (tokenbudget > 0) {
                var pct = Math.min(100, Math.round((estimate / tokenbudget) * 100));
                var barclass = pct >= 100 ? 'bg-danger' : (pct >= tokenwarningpct ? 'bg-warning' : 'bg-success');
                tokenregion.innerHTML =
                    '<div class="progress"><div class="progress-bar ' + barclass + '" style="width: ' + pct + '%">' +
                    estimatetext + '</div></div>';
            } else {
                tokenregion.innerHTML = '<div class="text-muted">' + estimatetext + '</div>';
            }
        }
    }

    /**
     * Find the real question-settings <form>, via a field guaranteed to be inside it.
     *
     * "Generálás indítása" and "Mentés és kilépés" are plain (type=button) elements rendered
     * outside generate_form's own <form> so they can share a row with the Törlés/Vissza links
     * (see generate.php). A form="..." attribute pointing at a hardcoded id was tried first and
     * turned out unreliable across Moodle themes/versions, so instead this locates the actual
     * <form> element at runtime and submits it directly - works regardless of what id/name
     * Moodle happens to render it with.
     *
     * @return {HTMLFormElement|null}
     */
    function findForm() {
        var anchor = document.getElementById('id_difficultymode');
        return anchor ? anchor.closest('form') : null;
    }

    /**
     * Set the hidden artqtmlaction field and submit the real form.
     *
     * Looked up via form.elements by name, not document.getElementById('id_artqtmlaction'):
     * unlike visible mform elements, Moodle does not render an "id_<name>" id on hidden
     * elements (it renders with no id at all), so the getElementById lookup always failed
     * silently and "Mentés és kilépés" ended up submitting with the field's original
     * 'generate' value - always taking the start-generation branch in generate.php.
     *
     * @param {string} action 'generate' or 'save'
     */
    function submitForm(action) {
        var form = findForm();
        if (!form) {
            return;
        }

        var actionfield = form.elements.namedItem('artqtmlaction');
        if (actionfield) {
            actionfield.value = action;
        }

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    /**
     * Wire up a single document-level delegated listener, so the totals recompute on any
     * change anywhere in the form regardless of which specific field triggered it. Also wires
     * the "Generálás indítása", "Vissza" and "Megszakít" buttons.
     *
     * @param {{step2total: string}} labels
     * @param {{backconfirm: string}} messages confirm() text for "Vissza" (Beal-023).
     *      "Törlés és kilépés" submits #artqtml-abortdelete-form (POST + sesskey).
     * @param {number} tokenbudget admin-configured monthly token budget (Beal-019/020/021)
     * @param {number} tokenwarningpct admin-configured warning threshold, percent of tokenbudget
     * @return {void}
     */
    function init(labels, messages, tokenbudget, tokenwarningpct) {
        var handler = function() {
            update(labels, tokenbudget, tokenwarningpct);
        };

        // Fetched via core/str (not hardcoded here) so the estimate reads correctly in every
        // installed language; '__N__' is a sentinel substituted with the live estimate on every
        // recompute, matching this plugin's textcounter.js template convention.
        Str.get_string('estimatedtokenslabel', 'local_artqtml', '__N__').then(function(str) {
            tokensTemplate = str;
            return str;
        }).catch(function() {
            return null;
        }).always(function() {
            $(document).on('input change', handler);
            update(labels, tokenbudget, tokenwarningpct);
        });

        var submitbutton = document.getElementById('artqtml-submitbutton');
        if (submitbutton) {
            submitbutton.addEventListener('click', function() {
                submitForm('generate');
            });
        }

        // Beal-023/024: a single confirm(), then save (unvalidated, like "Mentés és kilépés")
        // and redirect back to the source text page - generate.php tells the two apart via the
        // artqtmlaction hidden field's value ('back' vs 'save').
        var backbutton = document.getElementById('artqtml-backbutton');
        if (backbutton) {
            backbutton.addEventListener('click', function() {
                if (window.confirm(messages.backconfirm)) {
                    submitForm('back');
                }
            });
        }

        // Beal-025: "Megszakít" opens a single modal with all three outcomes, instead of each
        // living behind its own separate always-visible button/confirm().
        var modal = document.getElementById('artqtml-abortmodal');
        var abortbutton = document.getElementById('artqtml-abortbutton');
        if (abortbutton && modal) {
            abortbutton.addEventListener('click', function() {
                modal.style.display = 'block';
            });
        }
        var deletebutton = document.getElementById('artqtml-abortmodal-delete');
        if (deletebutton) {
            deletebutton.addEventListener('click', function() {
                var form = document.getElementById('artqtml-abortdelete-form');
                if (form) {
                    form.submit();
                }
            });
        }
        var modalsavebutton = document.getElementById('artqtml-abortmodal-save');
        if (modalsavebutton) {
            modalsavebutton.addEventListener('click', function() {
                submitForm('save');
            });
        }
        var cancelbutton = document.getElementById('artqtml-abortmodal-cancel');
        if (cancelbutton && modal) {
            cancelbutton.addEventListener('click', function() {
                modal.style.display = 'none';
            });
        }
    }

    return {init: init};
});
