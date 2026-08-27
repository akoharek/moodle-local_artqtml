<?php
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
 * Ask again for the question types a partly successful generation did not deliver.
 *
 * Not a retry of the original generation: that one is finished, its questions are real and are
 * waiting for approval, and re-running it would throw them away. This starts a NEW generation on
 * the same source text, with the grid narrowed to what is missing - which is also why it goes
 * through the duplicate check like any other new generation, and why it stops on the settings page
 * instead of calling the API. Both are András's decisions, 2026-08-01:
 *
 * - the teacher presses the button; the system never re-runs anything by itself;
 * - the duplicate check stays, because the teacher may come back to this days later, and being
 *   told what already exists for this text is the entire point of that screen.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_artqtml\local\duplicate_detector;
use local_artqtml\local\generation_access_policy;
use local_artqtml\local\generation_list;
use local_artqtml\local\generation_status;
use local_artqtml\local\missing_types;

require_login();

defined('MOODLE_INTERNAL') || die();

$context = context_system::instance();
require_capability('local/artqtml:use', $context);

$generationid = required_param('generationid', PARAM_INT);
$confirmed = optional_param('artqtmconfirmdup', 0, PARAM_BOOL);

// State-changing: POST + sesskey only (button on status.php is a POST single_button).
if (!data_submitted()) {
    throw new moodle_exception('invalidrequest');
}
require_sesskey();

$generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid], '*', MUST_EXIST);
generation_access_policy::require_can_mutate($generation, $context);
$statusurl = new moodle_url('/local/artqtml/status.php', ['generationid' => $generationid]);

$PAGE->set_url('/local/artqtml/retrytypes.php', ['generationid' => $generationid]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('retrymissingtypes', 'local_artqtml'));
$PAGE->set_heading(get_string('retrymissingtypes', 'local_artqtml'));

// The same three gates upload.php puts in front of starting any new generation - this creates one,
// so it cannot be a way around them.
if (!get_config('local_artqtml', 'enabled')) {
    redirect(new moodle_url('/local/artqtml/index.php'));
}

if ($generation->status !== generation_status::PARTIAL) {
    redirect($statusurl);
}

$shortfall = missing_types::shortfall($generation);
if ($shortfall === []) {
    // Nothing recorded as missing - the button should not have been reachable, so say nothing
    // clever and just go back rather than creating an empty generation.
    redirect($statusurl);
}

$settings = json_decode((string) $generation->settings, true);
if (!is_array($settings)) {
    // A partial generation always has settings - it ran. Nothing sensible to say if it somehow
    // does not, and inventing a grid would be worse than going back.
    redirect($statusurl);
}

/**
 * Create the follow-up generation and send the teacher to its settings page.
 *
 * Status stays "started": nothing is queued, no API call is made. The new row carries the narrowed
 * settings, which is what generate.php's existing set_data() branch reads to fill the grid in -
 * the same path a resumed generation uses, rather than a second prefill mechanism beside it.
 *
 * @param stdClass $source the partly successful generation being followed up
 * @param array $settings its decoded settings
 * @param array<string, int> $shortfall
 * @return void does not return - redirects
 */
function local_artqtml_create_followup(stdClass $source, array $settings, array $shortfall): void {
    global $DB, $USER;

    $record = new stdClass();
    $record->userid = $USER->id;
    $record->name = get_string('retrymissingtypesname', 'local_artqtml', format_string($source->name));
    // the shortname is alphanumeric, 8 characters, and ends up inside every
    // generated question code - so it cannot simply be the original with a suffix bolted on. Seven
    // characters of the original plus '2' keeps it recognisable, legal and distinguishable from
    // the questions the first run already produced. The teacher can change it on the settings page.
    $record->shortname = \core_text::substr((string) $source->shortname, 0, 7) . '2';
    $record->sourcetext = $source->sourcetext;
    $record->sourcetexthash = $source->sourcetexthash;
    $record->sourcefilehash = $source->sourcefilehash;
    $record->settings = json_encode(missing_types::narrowed_settings($settings, $source));
    $record->status = generation_status::STARTED;
    $record->timecreated = time();
    $record->timemodified = $record->timecreated;

    $newid = $DB->insert_record('local_artqtml_generations', $record);

    // Written on the source generation, not the new one: the fact worth being able to find later
    // is that this partial run was followed up, and by which generation.
    $log = new stdClass();
    $log->generationid = $source->id;
    $log->userid = $USER->id;
    $log->event = 'followup_created';
    $log->data = json_encode(['newgenerationid' => $newid, 'missing' => $shortfall]);
    $log->timecreated = time();
    $DB->insert_record('local_artqtml_log', $log);

    redirect(new moodle_url('/local/artqtml/generate.php', ['id' => $newid]));
}

// the duplicate screen. Unlike upload.php this compares against every generation
// including the source one - being told "you already generated questions from this text on
// Saturday, and here it is" is exactly the information the teacher needs before spending money on
// the same text again.
$match = $confirmed ? null : duplicate_detector::find_match((string) $generation->sourcetext, 0);

if ($match === null) {
    local_artqtml_create_followup($generation, $settings, $shortfall);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('duplicatefound', 'local_artqtml'));
echo html_writer::tag('p', get_string('duplicatefounddesc', 'local_artqtml', (object) [
    'creator'    => fullname(\core_user::get_user($match->userid)),
    'date'       => userdate($match->timecreated, get_string('datetimeformat', 'local_artqtml')),
    'name'       => format_string($match->name),
    'similarity' => $match->similarity,
    'status'     => get_string('status_' . $match->status, 'local_artqtml'),
]));
echo html_writer::tag('p', get_string('retrymissingtypesdesc', 'local_artqtml', missing_types::describe($generation)));

$continueurl = new moodle_url('/local/artqtml/retrytypes.php');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $continueurl->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'generationid', 'value' => $generationid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'artqtmconfirmdup', 'value' => 1]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag(
    'button',
    get_string('duplicatecontinue', 'local_artqtml'),
    ['type' => 'submit', 'class' => 'btn btn-primary mr-2']
);
echo html_writer::end_tag('form');

echo html_writer::link(generation_list::open_url($match), get_string('duplicateopenexisting', 'local_artqtml'), [
    'class' => 'btn btn-secondary',
]);
echo $OUTPUT->footer();
