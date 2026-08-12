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
 * Polling for the local_artqtml status page.
 *
 * Calls the local_artqtml_get_status external function via core/ajax.
 *
 * @module     local_artqtml/status
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {
    'use strict';

    var POLL_INTERVAL_MS = 3000;

    // C8: transient AJAX failures must not permanently stop polling. On each consecutive error we
    // wait progressively longer (5s, then 10s, then 30s, capped at 30s) before retrying, and keep
    // polling until the generation reaches a definitive terminal status.
    var ERROR_BACKOFF_MS = [5000, 10000, 30000];

    var SELECTORS = {
        ROOT: '[data-region="artqtml-status"]',
        PROGRESS_BAR: '[data-region="progressbar"]',
        QUESTION_COUNT: '[data-region="question-count"]',
        CONTINUE: '[data-region="continue"]',
        ERROR: '[data-region="error"]',
        ERROR_TECHNICAL: '[data-region="error-technical"]',
        ABORT_BUTTON: '[data-region="abortbutton"]',
        STAGE_LABEL: '[data-region="stagelabel"]'
    };

    /**
 * @param {Element} root
 * @return {{stages: Object, failed: Object, colorClasses: Array<string>, terminal: Array<string>}}
 */
    function progressConfig(root) {
        return JSON.parse(root.getAttribute('data-progress-config'));
    }

    /**
     * Call the local_artqtml_get_status external function via core/ajax.
     *
     * @param {number} generationid
     * @return {Promise<{status: string, questioncount: number, unvalidatedcount: number,
     *      error: string, tokenwarningmessage: string}>}
     */
    function callGetStatus(generationid) {
        return Ajax.call([{
            methodname: 'local_artqtml_get_status',
            args: {id: generationid}
        }])[0];
    }

    /**
     * Update the stage list, question count and action regions for the given status.
     *
     * @param {Element} root
     * @param {{status: string, questioncount: number, unvalidatedcount: number, error: string}} statusdata
     * @return {void}
     */
    function updateUi(root, statusdata) {
        var bar = root.querySelector(SELECTORS.PROGRESS_BAR);
        var abortbutton = root.querySelector(SELECTORS.ABORT_BUTTON);
        var config = progressConfig(root);

        if (bar) {
            var percent;
            var color;
            var striped;
            var label;

            if (statusdata.status === 'failed') {
                percent = statusdata.failedpercent;
                color = config.failed.color;
                striped = config.failed.striped;
                label = root.getAttribute('data-label-failed');
            } else if (config.stages[statusdata.status]) {
                var info = config.stages[statusdata.status];
                percent = info.percent;
                color = info.color;
                striped = info.striped;
                label = root.getAttribute('data-label-' + statusdata.status);

                if (statusdata.status === 'generating') {
                    if (typeof statusdata.generatingpercent === 'number' && statusdata.generatingpercent > 0) {
                        percent = statusdata.generatingpercent;
                    }
                    if (statusdata.generatingtypelabel) {
                        label = label + ' - ' + statusdata.generatingtypelabel;
                    }
                }
            }

            if (percent !== undefined) {
                config.colorClasses.forEach(function(cls) {
                    bar.classList.remove(cls);
                });
                bar.classList.add(color);
                bar.classList.toggle('progress-bar-striped', striped);
                bar.classList.toggle('progress-bar-animated', striped);
                bar.style.width = percent + '%';
                bar.setAttribute('aria-valuenow', percent);

                var stagelabel = root.querySelector(SELECTORS.STAGE_LABEL);
                if (stagelabel) {
                    stagelabel.textContent = label + ' (' + percent + '%)';
                }
            }
        }

        if (statusdata.status === 'completed') {
            var continueregion = root.querySelector(SELECTORS.CONTINUE);
            if (continueregion) {
                continueregion.classList.remove('d-none');
            }
            var successregion = document.querySelector('[data-region="success"]');
            if (successregion) {
                successregion.classList.remove('d-none');
            }
            var successcount = document.querySelector('[data-region="success-count"]');
            if (successcount) {
                successcount.textContent = statusdata.questioncount;
            }
            if (abortbutton) {
                abortbutton.style.display = 'none';
            }
        } else if (statusdata.status === 'partial' && root.getAttribute('data-initialstatus') !== 'partial') {
            window.location.reload();
        } else if (statusdata.status === 'failed') {
            var errorregion = root.querySelector(SELECTORS.ERROR);
            if (errorregion) {
                errorregion.classList.remove('d-none');
            }
            var errortechnical = root.querySelector(SELECTORS.ERROR_TECHNICAL);
            if (errortechnical && statusdata.error) {
                errortechnical.textContent = statusdata.error;
            }
            if (abortbutton) {
                abortbutton.style.display = 'none';
            }
        }

        var countregion = root.querySelector(SELECTORS.QUESTION_COUNT);
        if (countregion) {
            countregion.textContent = statusdata.questioncount;
        }

        if (statusdata.tokenwarningmessage) {
            var tokenwarning = document.querySelector('[data-region="tokenwarning"]');
            var tokenwarningtext = document.querySelector('[data-region="tokenwarning-text"]');
            if (tokenwarningtext) {
                tokenwarningtext.textContent = statusdata.tokenwarningmessage;
            }
            if (tokenwarning) {
                tokenwarning.classList.remove('d-none');
            }
        }

        if (statusdata.countdiscrepancymessage && statusdata.status !== 'partial') {
            var countdiscrepancy = document.querySelector('[data-region="countdiscrepancy"]');
            var countdiscrepancytext = document.querySelector('[data-region="countdiscrepancy-text"]');
            if (countdiscrepancytext) {
                countdiscrepancytext.textContent = statusdata.countdiscrepancymessage;
            }
            if (countdiscrepancy) {
                countdiscrepancy.classList.remove('d-none');
            }
        }
    }

    /**
     * Poll the status endpoint, rescheduling itself until a terminal status is reached.
     *
     * @param {Element} root
     * @param {number} generationid
     * @param {number} errorcount number of consecutive failed polls so far (drives the backoff)
     * @return {void}
     */
    function poll(root, generationid, errorcount) {
        callGetStatus(generationid).then(function(statusdata) {
            updateUi(root, statusdata);
            if (statusdata.status === 'started') {
                if (statusdata.restarturl) {
                    window.location.assign(statusdata.restarturl);
                }
                return null;
            }
            if (progressConfig(root).terminal.indexOf(statusdata.status) === -1) {
                setTimeout(function() {
                    poll(root, generationid, 0);
                }, POLL_INTERVAL_MS);
            }
            return null;
        }).catch(function(error) {
            if (window.console && window.console.error) {
                window.console.error('local_artqtml status polling error:', error);
            }
            // C8: a single AJAX error must not stop polling permanently. Retry with exponential
            // backoff (5s, then 10s, then 30s, capped at 30s); only a terminal status returned by
            // a successful poll ends the loop.
            var delay = ERROR_BACKOFF_MS[Math.min(errorcount, ERROR_BACKOFF_MS.length - 1)];
            setTimeout(function() {
                poll(root, generationid, errorcount + 1);
            }, delay);
        });
    }

    /**
     * Locate the status root element and start polling.
     *
     * @return {void}
     */
    function init() {
        var root = document.querySelector(SELECTORS.ROOT);
        if (!root) {
            return;
        }

        var generationid = parseInt(root.getAttribute('data-generationid'), 10);
        if (!generationid) {
            return;
        }

        poll(root, generationid, 0);
    }

    return {init: init};
});
