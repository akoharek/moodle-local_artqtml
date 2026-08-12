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
 * @package    local_artqtml
 * @license    http://Www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/artqtml:use', $context);
require_sesskey();

$generationid = required_param('id', PARAM_INT);

// This script renders no page of its own - it acts and redirects - but that does not exempt it
// From setting up $PAGE: format_string() below reads $PAGE->context to resolve filters, and
// Redirect() renders a full page (theme, favicon, notification) which reads $PAGE->url. Without
// These two lines Moodle emits "$PAGE->context was not set" and "did not call $PAGE->set_url()"
// Developer warnings on top of an otherwise successful delete. require_login() with no course
// Argument does not set them for a system-level script like this one.
$PAGE->set_url('/local/artqtml/delete.php', ['id' => $generationid]);
$PAGE->set_context($context);

$generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid], '*', MUST_EXIST);

\local_artqtml\local\generation_delete_policy::require_can_delete($generation, $context);

$indexurl = new moodle_url('/local/artqtml/index.php');

if (\local_artqtml\local\approve\question_deletion_service::has_moved_questions($generationid)) {
    \core\notification::error(get_string('cannotdeletemoved', 'local_artqtml'));
    redirect($indexurl);
}

$transaction = $DB->start_delegated_transaction();

\local_artqtml\local\generation_deletion::purge($generationid);

$transaction->allow_commit();

\local_artqtml\event\generation_deleted::create([
    'objectid' => $generationid,
    'context'  => $context,
])->trigger();

\core\notification::success(get_string('deletesuccess', 'local_artqtml', format_string($generation->name)));

redirect($indexurl);
