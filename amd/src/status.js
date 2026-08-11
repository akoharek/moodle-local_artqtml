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
 * Polling for the local_artqtml status page (functional spec ch.5).
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
     * S-3/S-2: the stage -> percent/colour/striping map, the colour classes and the terminal
     * status list all come from PHP via data-progress-config, emitted by
     * \local_artqtml\local\generation_progress. This module deliberately owns no copy of any
     * of them: they used to be maintained here AND in status.php with nothing checking the two
     * against each other, so a change to either would silently desynchronise the server-rendered
     * first paint from this AJAX-updated view.
     *
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
                // M-15: which stage it reached is derived server-side (get_status.php) from
                // pendingdata's shape, not from questioncount (nothing is saved to
                // local_artqtml_questions until the saving stage commits it all).
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

                // BL-35: the generating stage is one API call per requested question type, so it
                // advances within itself and the label names the type in flight. Six calls used to
                // look like one bar stuck at 25% for several minutes.
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

                // Gen-004 (S-4): the stage text belongs below the bar, not inside it. Written
                // into its own region so a 25% bar can never clip its own label away.
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
            // Gen-011: reveal the green success notification and refresh its embedded question
            // count to the final value (the banner may have been rendered server-side, hidden,
            // with a mid-generation count of 0 before the saving stage committed anything).
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
            // BL-35: unlike completed and failed, this outcome cannot be revealed by unhiding a
            // region that is already on the page. The partial notice names which types fell short
            // and carries the "generate the missing types" button, whose confirmation text and
            // sesskey link are built from a shortfall that did not exist when this page was
            // rendered. Reloading is what shows the teacher the real thing rather than a
            // half-populated copy of it.
            //
            // The data-initialstatus guard is not decoration. init() polls once even when the page
            // was rendered in a terminal status (that first poll is what reveals Continue on an
            // already-completed page), so without it a partly successful generation reloads,
            // polls, reloads - which is exactly what the screen did before this condition was
            // added.
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

        // M-08: appears live as soon as the generating stage finishes, without a page reload.
        // BL-35: except on a partly successful run, where the partial notice already prints this
        // same sentence as part of explaining itself - unhiding this region there put two
        // identical amber boxes on the screen, one above the other.
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
            // Recoverable rollback (Abort / Finding #5 security gate) returns status to started
            // while this page is still open — leave for the draft settings page and stop polling.
            if (statusdata.status === 'started') {
                if (statusdata.restarturl) {
                    window.location.assign(statusdata.restarturl);
                }
                return null;
            }
            // C8: keep polling until a definitive terminal status is reached; a successful poll
            // also clears any accumulated error backoff (the next poll starts at errorcount 0).
            // S-2: the terminal list comes from generation_status::TERMINAL via the same
            // data-progress-config payload, not a copy maintained here.
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
