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
 * Live total for the question settings page (ArtQTML Light: IH/FE/SR, scale only).
 *
 * @module     local_artqtml/generatesettings
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery'], function($) {
    'use strict';

    /** @var {string[]} Light type codes matching matrix_<code>_<level> fields. */
    var TYPE_CODES = ['IH', 'FE', 'SR'];

    /** @var {Object} scale levels only. */
    var MODE_LEVELS = {
        scale: ['easy', 'medium', 'hard']
    };

    /**
     * Read an element's numeric value by its Moodle-generated id ("id_" + element name).
     *
     * @param {string} elementname the mform element name, e.g. "matrix_IH_easy"
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
     * Recompute the question total and the submit button's enabled state.
     *
     * @param {{step2total: string}} labels lang-string label for the live total region
     */
    function update(labels) {
        var total = 0;
        TYPE_CODES.forEach(function(code) {
            MODE_LEVELS.scale.forEach(function(level) {
                total += fieldValue('matrix_' + code + '_' + level);
            });
        });

        var region = document.getElementById('artqtml-step2total');
        if (region) {
            region.textContent = labels.step2total + ': ' + total;
        }

        var submitbutton = document.getElementById('artqtml-submitbutton');
        if (submitbutton) {
            submitbutton.disabled = !(total > 0);
        }
    }

    /**
     * Find the real question-settings <form>, via a field guaranteed to be inside it.
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
     * Wire up totals and the generate / back / abort buttons.
     *
     * @param {{step2total: string}} labels
     * @param {{backconfirm: string}} messages confirm() text for "Vissza"
     * @return {void}
     */
    function init(labels, messages) {
        var handler = function() {
            update(labels);
        };

        $(document).on('input change', handler);
        update(labels);

        var submitbutton = document.getElementById('artqtml-submitbutton');
        if (submitbutton) {
            submitbutton.addEventListener('click', function() {
                submitForm('generate');
            });
        }

        var backbutton = document.getElementById('artqtml-backbutton');
        if (backbutton) {
            backbutton.addEventListener('click', function() {
                if (window.confirm(messages.backconfirm)) {
                    submitForm('back');
                }
            });
        }

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
