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
 * Delete an AI quiz question generation, its draft question bank and its questions.
 *
 * List-016: available in every status. Jov-018: the draft bank and everything in it is
 * removed along with the generation. Glob-040 (V-06): the diagnostic log rows are the one
 * exception - they deliberately survive, so a failed generation stays investigable after it is
 * deleted.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/artqtml:use', $context);
require_sesskey();

$generationid = required_param('id', PARAM_INT);

// This script renders no page of its own - it acts and redirects - but that does not exempt it
// from setting up $PAGE: format_string() below reads $PAGE->context to resolve filters, and
// redirect() renders a full page (theme, favicon, notification) which reads $PAGE->url. Without
// these two lines Moodle emits "$PAGE->context was not set" and "did not call $PAGE->set_url()"
// developer warnings on top of an otherwise successful delete. require_login() with no course
// argument does not set them for a system-level script like this one.
$PAGE->set_url('/local/artqtml/delete.php', ['id' => $generationid]);
$PAGE->set_context($context);

$generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid], '*', MUST_EXIST);

// Product decision 2026-08-10: deletion is local/artqtml:use + ownership only.
// local/artqtml:configure never authorises delete (alone or as a bypass for others' generations).
// Glob-031 still lets any :use holder open a colleague's generation; destroying it stays with the
// owner. Shared with generate.php's abort-delete via generation_delete_policy.
\local_artqtml\local\generation_delete_policy::require_can_delete($generation, $context);

$indexurl = new moodle_url('/local/artqtml/index.php');

// Jov-043/List-016: "A Törlés művelet minden státuszban elérhető, megerősítő pop-up-pal - kivéve,
// ha a generálás tartalmaz a kérdésbankba már áthelyezett kérdést". The list page renders the
// Delete button disabled for such a generation; this is the server-side half of the same rule, so a
// replayed or hand-built URL cannot destroy the record of which generation the question bank's
// questions came from. Jov-042 is why it matters: the plugin never deletes from the question bank,
// so those questions would survive while their provenance did not.
if (\local_artqtml\local\approve\question_deletion_service::has_moved_questions($generationid)) {
    \core\notification::error(get_string('cannotdeletemoved', 'local_artqtml'));
    redirect($indexurl);
}

$transaction = $DB->start_delegated_transaction();

// Glob-040 (V-06): purge() deletes the draft bank, the questions and the generation row, but
// deliberately NOT the local_artqtml_log rows - the diagnostic trail must outlive the generation.
// The single deletion path lives in generation_deletion so this retention rule cannot drift between
// here and generate.php's abort-delete; do not re-add a log delete alongside this call.
\local_artqtml\local\generation_deletion::purge($generationid);

$transaction->allow_commit();

\local_artqtml\event\generation_deleted::create([
    'objectid' => $generationid,
    'context'  => $context,
])->trigger();

\core\notification::success(get_string('deletesuccess', 'local_artqtml', format_string($generation->name)));

redirect($indexurl);
