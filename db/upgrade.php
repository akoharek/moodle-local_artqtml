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
 * Upgrade steps for local_artqtml.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute local_artqtml upgrade steps between two given versions.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool always true
 */
function xmldb_local_artqtml_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // Glob-037/038: ANY step below that changes an admin-editable setting must first call
    // \local_artqtml\local\setting_backup::backup($setting, $version, $encrypted), which stores
    // the previous value under <setting>_backup_<version> (encrypted if the original is) and
    // queues the post-upgrade notice telling the administrator where to find it. The validator
    // prompt template is the administrator's own work; two earlier steps rewrote it in place with
    // no backup, and those cannot be fixed retroactively - this rule binds everything after them.

    if ($oldversion < 2026071601) {
        // Replace the old single-table design with generations/questions/log tables.
        //
        // B1: 'local_artqtml_requests' is the pre-2026071601 single-table design and is
        // genuinely legacy - it does not appear anywhere in the current install.xml, so dropping
        // it when present can never touch a live table. (A former guard also cross-checked this
        // name against the current-schema table list, but with the name hardcoded that check was
        // provably always true and thus dead code - the existence check below is the real guard.)
        $oldtable = new xmldb_table('local_artqtml_requests');
        if ($dbman->table_exists($oldtable)) {
            $dbman->drop_table($oldtable);
        }

        $generations = new xmldb_table('local_artqtml_generations');
        $generations->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $generations->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $generations->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $generations->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $generations->add_field('sourcetext', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $generations->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $generations->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $generations->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $generations->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $generations->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $generations->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $generations->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        if (!$dbman->table_exists($generations)) {
            $dbman->create_table($generations);
        }

        $questions = new xmldb_table('local_artqtml_questions');
        $questions->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $questions->add_field('generationid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $questions->add_field('questiontype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $questions->add_field('questiontext', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $questions->add_field('questiondata', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $questions->add_field('validationstatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'not_evaluated');
        $questions->add_field('validationdata', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $questions->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $questions->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $questions->add_key('generationid', XMLDB_KEY_FOREIGN, ['generationid'], 'local_artqtml_generations', ['id']);
        $questions->add_index('validationstatus', XMLDB_INDEX_NOTUNIQUE, ['validationstatus']);
        if (!$dbman->table_exists($questions)) {
            $dbman->create_table($questions);
        }

        $log = new xmldb_table('local_artqtml_log');
        $log->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $log->add_field('generationid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $log->add_field('event', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $log->add_field('data', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $log->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $log->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $log->add_key('generationid', XMLDB_KEY_FOREIGN, ['generationid'], 'local_artqtml_generations', ['id']);
        $log->add_index('event', XMLDB_INDEX_NOTUNIQUE, ['event']);
        if (!$dbman->table_exists($log)) {
            $dbman->create_table($log);
        }

        upgrade_plugin_savepoint(true, 2026071601, 'local', 'artqtml');
    }

    if ($oldversion < 2026071604) {
        $table = new xmldb_table('local_artqtml_generations');
        $field = new xmldb_field('settings', XMLDB_TYPE_TEXT, null, null, null, null, null, 'status');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026071604, 'local', 'artqtml');
    }

    if ($oldversion < 2026071608) {
        $table = new xmldb_table('local_artqtml_questions');
        $field = new xmldb_field(
            'questionbankid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'validationdata'
        );

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $key = new xmldb_key('questionbankid', XMLDB_KEY_FOREIGN, ['questionbankid'], 'question', ['id']);
        if (!$dbman->find_key_name($table, $key)) {
            $dbman->add_key($table, $key);
        }

        upgrade_plugin_savepoint(true, 2026071608, 'local', 'artqtml');
    }

    if ($oldversion < 2026071614) {
        $table = new xmldb_table('local_artqtml_generations');
        $field = new xmldb_field('error', XMLDB_TYPE_TEXT, null, null, null, null, null, 'settings');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026071614, 'local', 'artqtml');
    }

    if ($oldversion < 2026071615) {
        // Pivot from a course-scoped MVP to the site-wide plugin described in the functional
        // spec (Glob-022/023: local/artqtml:use and :configure are CONTEXT_SYSTEM
        // capabilities, the list page has no course concept).
        //
        // Cursor audit v3 (CRITICAL #1): this step used to drop_table()+create_table() all
        // three tables outright, which silently destroyed every existing generation/question/
        // log row on any site upgrading through this version - not just ALPHA dev data, but
        // anything a real install had accumulated between 2026071601 and this step. Rewritten
        // below as field-by-field add_field()/rename_field()/drop_field() calls (every one
        // guarded by field_exists()/find_key_name(), so a retried/partial upgrade never fails
        // on a change that already applied) so every pre-existing row survives. The old
        // course-scoped schema had no equivalent of shortname/typecode/questioncode/etc., so
        // those new NOTNULL columns backfill existing rows with an explicit empty-string
        // placeholder rather than inventing false historical data.
        $generations = new xmldb_table('local_artqtml_generations');

        // The courseid field no longer has a place in a site-wide data model - drop its key before
        // field, since a DB can't drop a column a foreign key still references.
        $courseidkey = new xmldb_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        if ($dbman->find_key_name($generations, $courseidkey)) {
            $dbman->drop_key($generations, $courseidkey);
        }
        $courseidfield = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        if ($dbman->field_exists($generations, $courseidfield)) {
            $dbman->drop_field($generations, $courseidfield);
        }

        $shortname = new xmldb_field('shortname', XMLDB_TYPE_CHAR, '8', null, XMLDB_NOTNULL, null, '', 'name');
        if (!$dbman->field_exists($generations, $shortname)) {
            $dbman->add_field($generations, $shortname);
        }

        $sourcetexthash = new xmldb_field('sourcetexthash', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'sourcetext');
        if (!$dbman->field_exists($generations, $sourcetexthash)) {
            $dbman->add_field($generations, $sourcetexthash);
        }

        // Both settings (2026071604) and error (2026071614) already exist by this point - only
        // draftcategoryid is genuinely new here.
        $draftcategoryid = new xmldb_field('draftcategoryid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'settings');
        if (!$dbman->field_exists($generations, $draftcategoryid)) {
            $dbman->add_field($generations, $draftcategoryid);
        }

        // The status vocabulary changed from started/pending to started/generating/validating/
        // saving/completed/failed - 'pending' (the old default) is not a value any of this
        // plugin's code recognises, so existing rows are normalised to its closest equivalent
        // ("created, not yet processing") rather than left holding a now-meaningless value.
        $DB->execute("UPDATE {local_artqtml_generations} SET status = 'started' WHERE status = 'pending'");

        $statusfield = new xmldb_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'started');
        $dbman->change_field_default($generations, $statusfield);

        $draftcategorykey = new xmldb_key('draftcategoryid', XMLDB_KEY_FOREIGN, ['draftcategoryid'], 'question_categories', ['id']);
        if (!$dbman->find_key_name($generations, $draftcategorykey)) {
            $dbman->add_key($generations, $draftcategorykey);
        }

        $sourcetexthashindex = new xmldb_index('sourcetexthash', XMLDB_INDEX_NOTUNIQUE, ['sourcetexthash']);
        if (!$dbman->index_exists($generations, $sourcetexthashindex)) {
            $dbman->add_index($generations, $sourcetexthashindex);
        }

        $questions = new xmldb_table('local_artqtml_questions');

        $questioncode = new xmldb_field('questioncode', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'generationid');
        if (!$dbman->field_exists($questions, $questioncode)) {
            $dbman->add_field($questions, $questioncode);
        }

        // No equivalent field existed before typecode (the two-letter IH/FE/FT/SR/EH/RV code)
        // was introduced - an empty-string placeholder is used for any row that predates it
        // rather than guessing a type from the free-text legacy questiontype field.
        $typecode = new xmldb_field('typecode', XMLDB_TYPE_CHAR, '2', null, XMLDB_NOTNULL, null, '', 'questioncode');
        if (!$dbman->field_exists($questions, $typecode)) {
            $dbman->add_field($questions, $typecode);
        }

        $difficultylabel = new xmldb_field('difficultylabel', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'questiontext');
        if (!$dbman->field_exists($questions, $difficultylabel)) {
            $dbman->add_field($questions, $difficultylabel);
        }

        $sourcereference = new xmldb_field('sourcereference', XMLDB_TYPE_TEXT, null, null, null, null, null, 'difficultylabel');
        if (!$dbman->field_exists($questions, $sourcereference)) {
            $dbman->add_field($questions, $sourcereference);
        }

        // The validationstatus field is renamed, not dropped-and-recreated, so every row's existing
        // validation result (accepted/rejected/not_evaluated/etc.) survives under its new name.
        $oldvalidationstatus = new xmldb_field(
            'validationstatus',
            XMLDB_TYPE_CHAR,
            '20',
            null,
            XMLDB_NOTNULL,
            null,
            'not_evaluated'
        );
        $newvalidationsuggestion = new xmldb_field(
            'validationsuggestion',
            XMLDB_TYPE_CHAR,
            '20',
            null,
            XMLDB_NOTNULL,
            null,
            'not_evaluated'
        );
        if ($dbman->field_exists($questions, $oldvalidationstatus) && !$dbman->field_exists($questions, $newvalidationsuggestion)) {
            $dbman->rename_field($questions, $oldvalidationstatus, 'validationsuggestion');
        }

        $problemcategory = new xmldb_field(
            'problemcategory',
            XMLDB_TYPE_CHAR,
            '50',
            null,
            null,
            null,
            null,
            'validationsuggestion'
        );
        if (!$dbman->field_exists($questions, $problemcategory)) {
            $dbman->add_field($questions, $problemcategory);
        }

        $justification = new xmldb_field('justification', XMLDB_TYPE_TEXT, null, null, null, null, null, 'problemcategory');
        if (!$dbman->field_exists($questions, $justification)) {
            $dbman->add_field($questions, $justification);
        }

        $confidence = new xmldb_field('confidence', XMLDB_TYPE_INTEGER, '3', null, null, null, null, 'justification');
        if (!$dbman->field_exists($questions, $confidence)) {
            $dbman->add_field($questions, $confidence);
        }

        // The questionbankid field and its key already exist from step 2026071608 - nothing to do here.

        $movedout = new xmldb_field('movedout', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'questionbankid');
        if (!$dbman->field_exists($questions, $movedout)) {
            $dbman->add_field($questions, $movedout);
        }

        // The old index name tracked the field it was created for - rename_field() does not
        // rename indexes built on that field, so the stale "validationstatus"-named index (if
        // it still exists under that name) is dropped before adding the new one.
        $oldindex = new xmldb_index('validationstatus', XMLDB_INDEX_NOTUNIQUE, ['validationsuggestion']);
        if ($dbman->index_exists($questions, $oldindex)) {
            $dbman->drop_index($questions, $oldindex);
        }
        $validationsuggestionindex = new xmldb_index('validationsuggestion', XMLDB_INDEX_NOTUNIQUE, ['validationsuggestion']);
        if (!$dbman->index_exists($questions, $validationsuggestionindex)) {
            $dbman->add_index($questions, $validationsuggestionindex);
        }

        $movedoutindex = new xmldb_index('movedout', XMLDB_INDEX_NOTUNIQUE, ['movedout']);
        if (!$dbman->index_exists($questions, $movedoutindex)) {
            $dbman->add_index($questions, $movedoutindex);
        }

        $log = new xmldb_table('local_artqtml_log');

        $userid = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'generationid');
        if (!$dbman->field_exists($log, $userid)) {
            $dbman->add_field($log, $userid);
        }

        $calltype = new xmldb_field('calltype', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'event');
        if (!$dbman->field_exists($log, $calltype)) {
            $dbman->add_field($log, $calltype);
        }

        $provider = new xmldb_field('provider', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'calltype');
        if (!$dbman->field_exists($log, $provider)) {
            $dbman->add_field($log, $provider);
        }

        $httpstatus = new xmldb_field('httpstatus', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'provider');
        if (!$dbman->field_exists($log, $httpstatus)) {
            $dbman->add_field($log, $httpstatus);
        }

        $tokensinput = new xmldb_field('tokensinput', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'httpstatus');
        if (!$dbman->field_exists($log, $tokensinput)) {
            $dbman->add_field($log, $tokensinput);
        }

        $tokensoutput = new xmldb_field('tokensoutput', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'tokensinput');
        if (!$dbman->field_exists($log, $tokensoutput)) {
            $dbman->add_field($log, $tokensoutput);
        }

        $jsonattempt = new xmldb_field('jsonattempt', XMLDB_TYPE_INTEGER, '2', null, null, null, '1', 'tokensoutput');
        if (!$dbman->field_exists($log, $jsonattempt)) {
            $dbman->add_field($log, $jsonattempt);
        }

        $isretry = new xmldb_field('isretry', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'jsonattempt');
        if (!$dbman->field_exists($log, $isretry)) {
            $dbman->add_field($log, $isretry);
        }

        $requestid = new xmldb_field('requestid', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'isretry');
        if (!$dbman->field_exists($log, $requestid)) {
            $dbman->add_field($log, $requestid);
        }

        $result = new xmldb_field('result', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'requestid');
        if (!$dbman->field_exists($log, $result)) {
            $dbman->add_field($log, $result);
        }

        $errormessage = new xmldb_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null, 'result');
        if (!$dbman->field_exists($log, $errormessage)) {
            $dbman->add_field($log, $errormessage);
        }

        $useridkey = new xmldb_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        if (!$dbman->find_key_name($log, $useridkey)) {
            $dbman->add_key($log, $useridkey);
        }

        upgrade_plugin_savepoint(true, 2026071615, 'local', 'artqtml');
    }

    if ($oldversion < 2026071700) {
        // Licensz rendszer (functional spec ch.10, Lic-001-015): singleton table holding the
        // currently uploaded, digitally signed license state.
        $license = new xmldb_table('local_artqtml_license');
        if (!$dbman->table_exists($license)) {
            $license->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $license->add_field('edition', XMLDB_TYPE_CHAR, '20', null, null, null, null);
            $license->add_field('issuedto', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $license->add_field('issuedtourl', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $license->add_field('issuedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $license->add_field('expiresat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $license->add_field('activatedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $license->add_field('questionlimit', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $license->add_field('questionsvalidated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $license->add_field('licensejson', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $license->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'none');
            $license->add_field('lastcheckedtime', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $license->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $license->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $license->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($license);
        }

        upgrade_plugin_savepoint(true, 2026071700, 'local', 'artqtml');
    }

    if ($oldversion < 2026071715) {
        // Explicit human approval step, separate from the AI's validationsuggestion and from
        // moving: a question must be approved before it can be moved to a real question bank.
        $table = new xmldb_table('local_artqtml_questions');
        $field = new xmldb_field('approved', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'movedout');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026071715, 'local', 'artqtml');
    }

    if ($oldversion < 2026072014) {
        // C-02: atomic claiming so two concurrent process_pending_generations runs can never
        // both process the same generation - see process_pending_generations::claim().
        $table = new xmldb_table('local_artqtml_generations');
        $field = new xmldb_field('processingtoken', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'error');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026072014, 'local', 'artqtml');
    }

    if ($oldversion < 2026072015) {
        // M-20: a teacher editing a draft question resets it to not_evaluated (a real,
        // documented validationsuggestion value) rather than a synthetic 'edited' value that
        // validate_questions_task's own "not yet evaluated" query never matched - this new flag
        // is what now drives the UI's "Edited" badge instead.
        $table = new xmldb_table('local_artqtml_questions');
        $field = new xmldb_field('edited', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'approved');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Any existing row previously marked with the old synthetic 'edited' suggestion value
        // is migrated onto the new flag so it keeps showing as "Edited" in the UI.
        $DB->execute(
            "UPDATE {local_artqtml_questions} SET edited = 1, validationsuggestion = 'not_evaluated'
              WHERE validationsuggestion = 'edited'"
        );

        upgrade_plugin_savepoint(true, 2026072015, 'local', 'artqtml');
    }

    if ($oldversion < 2026072016) {
        // M-19: duplicate detection also matches on the raw uploaded file's byte hash (in
        // addition to the existing extracted-text hash), so re-uploading the exact same file is
        // always caught even if text extraction happens to produce slightly different output.
        $table = new xmldb_table('local_artqtml_generations');
        $field = new xmldb_field('sourcefilehash', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'sourcetexthash');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('sourcefilehash', XMLDB_INDEX_NOTUNIQUE, ['sourcefilehash']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026072016, 'local', 'artqtml');
    }

    if ($oldversion < 2026072017) {
        // M-30: track who last edited/approved each question, not just that it happened.
        $table = new xmldb_table('local_artqtml_questions');

        $editedby = new xmldb_field('lasteditedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'edited');
        if (!$dbman->field_exists($table, $editedby)) {
            $dbman->add_field($table, $editedby);
        }

        $approvedby = new xmldb_field('approvedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'lasteditedby');
        if (!$dbman->field_exists($table, $approvedby)) {
            $dbman->add_field($table, $approvedby);
        }

        $editedbykey = new xmldb_key('lasteditedby', XMLDB_KEY_FOREIGN, ['lasteditedby'], 'user', ['id']);
        if (!$dbman->find_key_name($table, $editedbykey)) {
            $dbman->add_key($table, $editedbykey);
        }

        $approvedbykey = new xmldb_key('approvedby', XMLDB_KEY_FOREIGN, ['approvedby'], 'user', ['id']);
        if (!$dbman->find_key_name($table, $approvedbykey)) {
            $dbman->add_key($table, $approvedbykey);
        }

        upgrade_plugin_savepoint(true, 2026072017, 'local', 'artqtml');
    }

    if ($oldversion < 2026072018) {
        // M-15: holds the in-flight Claude/Gemini results between the generating/validating/
        // saving pipeline stages, since nothing is written to local_artqtml_questions until
        // the new saving stage commits it all in one transaction.
        $table = new xmldb_table('local_artqtml_generations');
        $pendingdata = new xmldb_field('pendingdata', XMLDB_TYPE_TEXT, null, null, null, null, null, 'processingtoken');
        if (!$dbman->field_exists($table, $pendingdata)) {
            $dbman->add_field($table, $pendingdata);
        }

        // M-08: requested-vs-received per-type question count transparency.
        $countdiscrepancy = new xmldb_field('countdiscrepancy', XMLDB_TYPE_TEXT, null, null, null, null, null, 'pendingdata');
        if (!$dbman->field_exists($table, $countdiscrepancy)) {
            $dbman->add_field($table, $countdiscrepancy);
        }

        upgrade_plugin_savepoint(true, 2026072018, 'local', 'artqtml');
    }

    if ($oldversion < 2026072021) {
        // Stores the complete raw Gemini evaluation object for a question, alongside (not
        // instead of) the existing normalised problemcategory/justification/confidence columns.
        $table = new xmldb_table('local_artqtml_questions');
        $field = new xmldb_field('validationdata', XMLDB_TYPE_TEXT, null, null, null, null, null, 'confidence');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026072021, 'local', 'artqtml');
    }

    if ($oldversion < 2026072022) {
        // The tokenwarningthreshold setting's own documented purpose (a per-upload estimated-token
        // warning)
        // was already superseded by generatorcontextwindow-relative warnings in upload.php/
        // js/textcounter.js - it has had no code reading it since, so the setting itself (not
        // just a stray get_config() call) is removed here rather than given a new meaning.
        unset_config('tokenwarningthreshold', 'local_artqtml');

        upgrade_plugin_savepoint(true, 2026072022, 'local', 'artqtml');
    }

    if ($oldversion < 2026072023) {
        // Glob-032: pairs with the existing lasteditedby - without a timestamp, "the most
        // recently edited question in this generation" (for the list page's "Modified by"
        // column) cannot actually be determined once more than one question has been edited.
        $table = new xmldb_table('local_artqtml_questions');
        $field = new xmldb_field('lasteditedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'lasteditedby');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026072023, 'local', 'artqtml');
    }

    if ($oldversion < 2026072402) {
        // B2: license integrity-violation log rows are not tied to any generation, so they now
        // store generationid = NULL instead of an invalid 0 foreign key. Relax the column to
        // allow NULL (matching install.xml). The generationid foreign key is unaffected - NULL
        // values are simply not range-checked by the key.
        $table = new xmldb_table('local_artqtml_log');
        $field = new xmldb_field('generationid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'id');
        $key = new xmldb_key('generationid', XMLDB_KEY_FOREIGN, ['generationid'], 'local_artqtml_generations', ['id']);

        // The foreign key's underlying index blocks altering the column directly.
        if ($dbman->find_key_name($table, $key)) {
            $dbman->drop_key($table, $key);
        }

        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_notnull($table, $field);
        }

        $dbman->add_key($table, $key);

        // Repair any integrity-violation rows written before this fix with the old 0 sentinel.
        $DB->set_field_select('local_artqtml_log', 'generationid', null, 'generationid = 0');

        upgrade_plugin_savepoint(true, 2026072402, 'local', 'artqtml');
    }

    if ($oldversion < 2026072501) {
        // Val-019/Val-028/Val-029: the problem_category enum used an empty string for "no
        // problem", which is not a permitted Gemini structured-output enum value and made every
        // validation call fail schema validation ("problem_category.enum[0]: cannot be empty").
        // The enum is now the four fixed keys ok/factual_error/ambiguous_wording/other.

        // Part (a): migrate stored validation results off the empty string and any legacy
        // category key onto the four-key set. Accepted -> 'ok' (no problem); any other
        // actually-validated question with an empty/legacy category -> 'other'; a not-yet-
        // evaluated question keeps no category at all (NULL, never '' - the field is not shown
        // for it anyway, PROB-F001).
        // Frozen historical snapshot of the four keys - deliberately inlined, NOT read from
        // \local_artqtml\local\problem_category::VALUES: an upgrade step must keep behaving the
        // way it did when it ran, even if that constant (the live single source of truth for the
        // schema and prompt) is ever changed later.
        $validkeys = ['ok', 'factual_error', 'ambiguous_wording', 'other'];
        $rs = $DB->get_recordset('local_artqtml_questions', null, '', 'id, validationsuggestion, problemcategory');
        foreach ($rs as $rec) {
            if (in_array((string) $rec->problemcategory, $validkeys, true)) {
                continue; // Already one of the four keys - leave it.
            }
            if ($rec->validationsuggestion === 'accepted') {
                $new = 'ok';
            } else if (in_array($rec->validationsuggestion, ['needs_review', 'rejected'], true)) {
                $new = 'other';
            } else {
                $new = null; // Not-yet-evaluated / unknown: no category yet.
            }
            $DB->set_field('local_artqtml_questions', 'problemcategory', $new, ['id' => $rec->id]);
        }
        $rs->close();

        // Part (b): the four keys are now owned by code and appended to the prompt at build time,
        // so an admin-saved validator template must no longer carry the "empty if accepted"
        // stipulation (Admin-021). Strip that whole problem_category clause from the stored
        // override, leaving every other admin edit intact; if no phrase is present, it's untouched.
        $rawtemplate = (string) get_config('local_artqtml', 'validatorprompttemplate');
        if ($rawtemplate !== '') {
            try {
                $tpl = \core\encryption::decrypt($rawtemplate);
            } catch (\Throwable $e) {
                $tpl = $rawtemplate; // Pre-encryption legacy plaintext.
            }
            $new = preg_replace('/;\s*a\s+problem_category\b.*?empty if accepted\)?;?/is', ';', $tpl);
            $new = preg_replace('/,\s*egy\s+problem_category\b.*?üres ha elfogadható\)?/isu', '', $new);
            $new = preg_replace('/\(?\s*(empty if accepted|üres ha elfogadható)\s*\)?/iu', '', $new);
            $new = preg_replace('/\s{2,}/u', ' ', $new);
            $new = trim(preg_replace('/\s+([;,.])/u', '$1', $new));
            if ($new !== $tpl) {
                try {
                    $store = \core\encryption::encrypt($new);
                } catch (\Throwable $e) {
                    // Encryption unavailable - store plaintext; the reader of the day
                    // transparently falls back to reading a non-decryptable value as-is.
                    $store = $new;
                }
                set_config('validatorprompttemplate', $store, 'local_artqtml');
            }
        }

        upgrade_plugin_savepoint(true, 2026072501, 'local', 'artqtml');
    }

    if ($oldversion < 2026072600) {
        // Val-017/Admin-021: the three suggestion values (accepted/needs_review/rejected) used to
        // be spelled out as prose in the validator prompt template while the response schema read
        // them from a code constant - the same two-source arrangement that let problem_category
        // drift. They are now owned by code and appended at prompt-build time
        // (validate_questions_task::build_system_instruction()), so an admin-saved template must
        // no longer carry the value list.
        //
        // Strip only the "of/értéket ("accepted", "needs_review" or "rejected")" enumeration,
        // leaving every other admin edit - including any wording they added around it - intact.
        // A template that never mentioned the values is left completely untouched.
        $rawtemplate = (string) get_config('local_artqtml', 'validatorprompttemplate');
        if ($rawtemplate !== '') {
            try {
                $tpl = \core\encryption::decrypt($rawtemplate);
            } catch (\Throwable $e) {
                $tpl = $rawtemplate; // Pre-encryption legacy plaintext.
            }

            // The English shipped default asked the model to return a suggestion of one of the three
            // named values, followed by a short justification. Only the enumeration of those values
            // is removed here, so the sentence keeps asking for a suggestion and a justification.
            $new = preg_replace(
                '/\ba\s+suggestion\s+of\s+"?accepted"?\s*,\s*"?needs_review"?\s*,?\s*(?:or\s+)?"?rejected"?\s*;?/i',
                'a suggestion,',
                $tpl
            );
            // The Hungarian shipped default names the same three values in parentheses after the
            // word suggestion. The parenthesised part goes, the rest of the sentence stays as the
            // administrator wrote it.
            $new = preg_replace(
                '/(\begy\s+suggestion\s+értéket)\s*\(\s*"?accepted"?\s*,\s*"?needs_review"?\s*(?:vagy|és)?\s*"?rejected"?\s*\)/iu',
                '$1',
                $new
            );
            // Any remaining bare parenthesised/quoted triple, whatever wording surrounds it.
            $new = preg_replace(
                '/\(?\s*"?accepted"?\s*,\s*"?needs_review"?\s*,?\s*(?:or|vagy|és)?\s*"?rejected"?\s*\)?/iu',
                '',
                $new
            );
            $new = preg_replace('/\s{2,}/u', ' ', $new);
            $new = trim(preg_replace('/\s+([;,.])/u', '$1', $new));

            if ($new !== $tpl) {
                try {
                    $store = \core\encryption::encrypt($new);
                } catch (\Throwable $e) {
                    // Encryption unavailable - store plaintext; the reader of the day
                    // transparently falls back to reading a non-decryptable value as-is.
                    $store = $new;
                }
                set_config('validatorprompttemplate', $store, 'local_artqtml');
            }
        }

        upgrade_plugin_savepoint(true, 2026072600, 'local', 'artqtml');
    }

    if ($oldversion < 2026072602) {
        // Admin-061/063: the diagnostic log the scheduled and manual model checks write to.
        // Deliberately its own table rather than a reuse of local_artqtml_log: that one is keyed
        // by generationid and is deleted along with its generation, whereas these entries belong to
        // the site's configuration health and must outlive any generation.
        $table = new xmldb_table('local_artqtml_modelcheck');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('provider', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('model', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('checktype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('result', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('errorcode', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('duration', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        // Named triggertype, not trigger: TRIGGER is a reserved word and cannot be referenced
        // unquoted in
        // MariaDB or PostgreSQL, which would break the Configurable Reports queries this table
        // exists to serve. See the deviation note in docs/model_selector_report.md.
        $table->add_field('triggertype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('provider_timecreated', XMLDB_INDEX_NOTUNIQUE, ['provider', 'timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072602, 'local', 'artqtml');
    }

    if ($oldversion < 2026072800) {
        // D-3: earlier versions created a generation's draft category with parent = 0 - Moodle's
        // own marker for a context's single hidden "top" category - so those contexts now hold two
        // parent = 0 rows and question_get_top_category() throws on any question bank page that
        // walks them. The repair re-parents them under their context's real top; it never deletes,
        // because the questions inside are real.
        //
        // The work lives in its own class so it can be unit-tested; that class is frozen to this
        // step and must not be edited for a later repair.
        $repaired = \local_artqtml\local\upgrade\draft_category_reparent::run();

        if ($repaired > 0) {
            // Worth a line in the upgrade output: this touches question bank data, and an admin
            // seeing a question bank suddenly work again deserves to know why.
            mtrace('local_artqtml: re-parented ' . $repaired
                . ' draft question categories that were left at parent = 0 by an earlier version.');
        }

        upgrade_plugin_savepoint(true, 2026072800, 'local', 'artqtml');
    }

    if ($oldversion < 2026073001) {
        // Jov-036: create the narrow role that lets a generator edit their own draft questions in
        // Moodle's native editor. Until now this needed an administrator to enrol the user in the
        // draft course as an editingteacher, by hand, before the Edit and Preview links did
        // anything but lead to a permission error.
        //
        // Existing users are deliberately NOT back-assigned here. The role is granted when a
        // generation starts, so anyone still working gets it on their next run; assigning it to
        // every past generator would hand the capability to people who may no longer need it, and
        // an upgrade step is the wrong place to make that call.
        \local_artqtml\local\draft_role::ensure_role();

        upgrade_plugin_savepoint(true, 2026073001, 'local', 'artqtml');
    }

    if ($oldversion < 2026073002) {
        // Jov-047: refresh the draft-editing role's name and description from the language pack.
        // create_role() writes both into the role table at creation time (lib/accesslib.php), so a
        // corrected language string does not reach a role that already exists - and the one created
        // hours earlier described a narrower role than the one that shipped: it said the user may
        // edit "their own" questions, from the first attempt at this feature, before Glob-031 moved
        // it to every generation with a confirmation prompt.
        //
        // Deliberately an upgrade step and not part of draft_role::grant(): refreshing on every
        // generation would silently overwrite an administrator who had renamed the role on purpose.
        // An upgrade is the moment where that is a considered act.
        $roleid = (int) $DB->get_field('role', 'id', [
            'shortname' => \local_artqtml\local\draft_role::SHORTNAME,
        ]);

        if ($roleid) {
            $DB->update_record('role', (object) [
                'id'          => $roleid,
                'name'        => get_string('draftrolename', 'local_artqtml'),
                'description' => get_string('draftroledescription', 'local_artqtml'),
            ]);
        }

        upgrade_plugin_savepoint(true, 2026073002, 'local', 'artqtml');
    }

    if ($oldversion < 2026073101) {
        // Admin-066/067: the generator system prompt moves out of the lang packs and out of the
        // code, into plain-text admin settings. Three things happen here, in this order.
        //
        // 1. The existing template is decrypted and rewritten as plaintext. Until now it was
        // stored as sodium ciphertext, which is why nothing could read it without going through
        // the decrypting reader of the day - including the administrator who wrote it.
        // 2. Any admin edit is preserved. A site that customised its template keeps exactly what
        // it had; only the storage format changes.
        // 3. The nine new fragment settings are seeded from db/prompt_defaults.php, which is the
        // only time that file is ever read.
        //
        // Glob-037/038: setting_backup::backup() runs before the template is rewritten, and
        // queues the post-upgrade notice. The fragments are new settings with no previous value,
        // so there is nothing to back up for them.
        $stored = get_config('local_artqtml', 'generatorprompttemplate');
        if ($stored !== false && $stored !== '') {
            \local_artqtml\local\setting_backup::backup('generatorprompttemplate', 2026073101, true);

            try {
                $plain = \core\encryption::decrypt((string) $stored);
            } catch (\Throwable $e) {
                // Pre-encryption legacy plaintext, or a value this site cannot decrypt. Either
                // way the bytes on hand are the best available, and the backup above holds the
                // original untouched.
                $plain = (string) $stored;
            }
            set_config('generatorprompttemplate', $plain, 'local_artqtml');
        }
        // No else branch: prompt_seed::apply() below fills an empty template from the shipped
        // text. Writing it here as well would be the same rule in two places - and it made
        // setting_backup_test fail, because a second template write with no backup in front of it
        // is indistinguishable from the mistake that guard exists to catch.

        // The nine fragment settings are new here, so all of them are empty and all get seeded.
        // The rule itself lives in prompt_seed so that install, this step and every later upgrade
        // share one implementation rather than three copies that could drift apart.
        \local_artqtml\local\prompt_seed::apply();

        upgrade_plugin_savepoint(true, 2026073101, 'local', 'artqtml');
    }

    if ($oldversion < 2026073102) {
        // Every future version does this, not just this one: fill any prompt setting that is
        // empty from db/prompt_defaults.php, and leave anything else untouched.
        //
        // The rule is deliberately blunt. A stored value that differs from the shipped text is
        // either an administrator's edit or an older shipped version, and nothing here can tell
        // those apart - so both are kept. The cost is that a site which never customised its
        // prompt also never receives an improved one; the alternative cost would be overwriting a
        // customer's tuned prompt during a routine upgrade, which is worse and invisible.
        //
        // An empty setting is the one case where writing is right: a prompt with a hole in it
        // still produces a valid request, so nothing would report the gap.
        \local_artqtml\local\prompt_seed::apply();

        upgrade_plugin_savepoint(true, 2026073102, 'local', 'artqtml');
    }

    if ($oldversion < 2026073103) {
        // Beal-018: the "source text + internet" option is gone, so its prompt text is a config
        // row nothing reads. Removed rather than left behind - a setting no code consults is the
        // kind of thing a later reader has to research before they can ignore it.
        //
        // Generations created while the option existed keep 'internet' in their stored settings.
        // Nothing rewrites those: generate_questions_task maps the value to the own-knowledge
        // text, which is the closer of the two remaining to what that teacher asked for.
        unset_config('promptknowledgeinternet', 'local_artqtml');

        upgrade_plugin_savepoint(true, 2026073103, 'local', 'artqtml');
    }

    if ($oldversion < 2026073106) {
        // Admin-066/067 for the validator, mirroring what 2026073101/02 did for the generator.
        //
        // The stored template is decrypted and rewritten as plaintext, and the three clauses the
        // code used to append become settings of their own. An admin's edited template is kept -
        // only the storage format changes - and prompt_seed fills whatever is empty.
        //
        // One thing does not survive verbatim, and should not: the old template did not carry the
        // {{SUGGESTION_INSTRUCTION}}, {{CATEGORY_INSTRUCTION}} and {{LANGUAGE_INSTRUCTION}}
        // placeholders, because the code appended those clauses after it. A migrated template
        // therefore has them appended once, in the order the code used, so the prompt a site
        // sends after the upgrade is the prompt it sent before it.
        $stored = get_config('local_artqtml', 'validatorprompttemplate');
        if ($stored !== false && $stored !== '') {
            \local_artqtml\local\setting_backup::backup('validatorprompttemplate', 2026073106, true);

            try {
                $plain = \core\encryption::decrypt((string) $stored);
            } catch (\Throwable $e) {
                $plain = (string) $stored;
            }

            if (strpos($plain, '{{LANGUAGE_INSTRUCTION}}') === false) {
                $plain = rtrim($plain) . "\n\n{{SUGGESTION_INSTRUCTION}}\n\n{{CATEGORY_INSTRUCTION}}"
                    . "\n\n{{LANGUAGE_INSTRUCTION}}\n";
            }

            set_config('validatorprompttemplate', $plain, 'local_artqtml');
        }

        \local_artqtml\local\prompt_seed::apply();

        upgrade_plugin_savepoint(true, 2026073106, 'local', 'artqtml');
    }

    if ($oldversion < 2026080101) {
        // BL-31: the SR item-count fragment said "exactly N items", and the model obeyed it over
        // the source text - measured six times across three runs, every time on a sentence that
        // supports three items, every time with an invented fourth ("(sötét irány)", "(nincs több
        // szín)", "(a legsötétebb árnyalat)" and three more). None is an item; each leaves the
        // question with no correct answer. The count is a ceiling now (Admin-036, spec v37).
        //
        // prompt_seed::apply() cannot deliver this on its own: its rule is "fill empty, leave
        // anything else alone", because a stored value that differs from the shipped text is
        // either an admin's edit or an older shipped string and it cannot tell them apart. Here it
        // can, because the previous shipped string is known exactly - so the replacement is
        // conditional on a byte-for-byte match. A site that edited its fragment keeps its own
        // wording, and keeps the defect, which is the right trade: its text is its decision.
        //
        // Glob-037/038: back up first, so the administrator has the previous value and is told
        // where it went. Not encrypted - this setting has always been plaintext.
        $old = 'For SR questions, provide exactly {{SR_ITEM_COUNT}} items to put in order.';
        $stored = get_config('local_artqtml', 'promptitemcount');

        if ($stored !== false && (string) $stored === $old) {
            \local_artqtml\local\setting_backup::backup('promptitemcount', 2026080101, false);

            $shipped = \local_artqtml\local\prompt_seed::shipped();
            if (isset($shipped['promptitemcount'])) {
                set_config('promptitemcount', $shipped['promptitemcount'], 'local_artqtml');
            }
        }

        // Unchanged rule for everything else: an empty prompt setting is filled from the shipped
        // text, and a customised one is left alone.
        \local_artqtml\local\prompt_seed::apply();

        upgrade_plugin_savepoint(true, 2026080101, 'local', 'artqtml');
    }

    if ($oldversion < 2026080102) {
        // Admin-069: promptdifficultyscale and promptdifficultybloom are new settings, so they are
        // empty everywhere and the standing rule seeds them. Nothing existing is touched - which is
        // why this step needs no backup: a setting with no previous value has nothing to lose.
        //
        // They matter more than a fragment usually does. Before them the prompt carried the
        // difficulty labels and no definition at all ("Difficulty: Easy: 2, Medium: 2, Hard: 2"),
        // and describe_difficulty() built that line in code, so there was nowhere for an
        // administrator to say what the levels mean even if they wanted to.
        \local_artqtml\local\prompt_seed::apply();

        upgrade_plugin_savepoint(true, 2026080102, 'local', 'artqtml');
    }

    if ($oldversion < 2026080103) {
        // Val-031/Val-032: two criteria the validator never had - whether a question actually
        // reaches its own difficulty label, and whether it is well formed in its own language.
        // Measured: across 181 questions the validator raised the level zero times while a human
        // review found 72 mismatches, and it accepted two questions that are ungrammatical in
        // Hungarian, one of them at 95% confidence.
        //
        // The two clauses are new settings, so prompt_seed::apply() below seeds them. What it
        // cannot do is put their placeholders into a template an administrator has edited - and a
        // clause with nowhere to go is a clause that silently does nothing. So the placeholders are
        // appended when they are missing, the same shape as the 2026073106 step used for the three
        // clauses that moved out of the code.
        //
        // Glob-037/038: back up before touching the template, because it may be the administrator's
        // own text. Not encrypted - plaintext since 2026073106.
        $stored = get_config('local_artqtml', 'validatorprompttemplate');
        if ($stored !== false && trim((string) $stored) !== '') {
            $plain = (string) $stored;
            $missing = [];
            if (strpos($plain, '{{DIFFICULTY_INSTRUCTION}}') === false) {
                $missing[] = '{{DIFFICULTY_INSTRUCTION}}';
            }
            if (strpos($plain, '{{WORDING_INSTRUCTION}}') === false) {
                $missing[] = '{{WORDING_INSTRUCTION}}';
            }

            if ($missing !== []) {
                \local_artqtml\local\setting_backup::backup('validatorprompttemplate', 2026080103, false);
                set_config(
                    'validatorprompttemplate',
                    rtrim($plain) . "\n\n" . implode("\n\n", $missing) . "\n",
                    'local_artqtml'
                );
            }
        }

        \local_artqtml\local\prompt_seed::apply();

        upgrade_plugin_savepoint(true, 2026080103, 'local', 'artqtml');
    }

    if ($oldversion < 2026080104) {
        // BL-31, reversed after measurement. Step 2026080101 replaced the SR item-count fragment's
        // "exactly N items" with a ceiling wording, to stop the model inventing a fourth item when
        // the source text supplies three. It worked and cost too much: the filler disappeared and
        // the yield fell from 36/36 questions to 14/48, then to 4/24 with a shorter wording. Told
        // to use only what the source supports, the model declines to write most questions rather
        // than write them short.
        //
        // Decided (András): the generator keeps the quota, and the filler is caught by the
        // validator instead (Val-033 below), because a flagged wrong question is worth more than a
        // right question that was never written.
        //
        // Both wordings this repository shipped or tested are named exactly, so a site that has
        // either of them gets the quota back and a site with its own wording keeps it.
        $ceilings = [
            'For SR questions, provide at most {{SR_ITEM_COUNT}} items to put in order. '
                . 'Use fewer if the source text does not support that many. '
                . 'Never invent an item that is not in the source text, and never add a placeholder, '
                . 'a label or a note about the list as if it were an item.',
            'For SR questions, provide at most {{SR_ITEM_COUNT}} items to put in order, '
                . 'using only items the source text actually supports.',
        ];
        $stored = (string) get_config('local_artqtml', 'promptitemcount');
        if (in_array($stored, $ceilings, true)) {
            \local_artqtml\local\setting_backup::backup('promptitemcount', 2026080104, false);
            $shipped = \local_artqtml\local\prompt_seed::shipped();
            if (isset($shipped['promptitemcount'])) {
                set_config('promptitemcount', $shipped['promptitemcount'], 'local_artqtml');
            }
        }

        // Val-033: the validator's half of that decision - flag an ordering question whose items
        // are not all named by the source text. Same placeholder handling as 2026080103.
        $template = get_config('local_artqtml', 'validatorprompttemplate');
        if (
            $template !== false
            && trim((string) $template) !== ''
            && strpos((string) $template, '{{ITEMSOURCE_INSTRUCTION}}') === false
        ) {
            \local_artqtml\local\setting_backup::backup('validatorprompttemplate', 2026080104, false);
            set_config(
                'validatorprompttemplate',
                rtrim((string) $template) . "\n\n{{ITEMSOURCE_INSTRUCTION}}\n",
                'local_artqtml'
            );
        }

        \local_artqtml\local\prompt_seed::apply();

        upgrade_plugin_savepoint(true, 2026080104, 'local', 'artqtml');
    }

    if ($oldversion < 2026080105) {
        // BL-34: a per-generation diagnostics flag. When it is on, the generating and validating
        // stages write the full request and response payloads into local_artqtml_log, where a
        // query can reach them.
        //
        // Why this was needed. FT returned zero questions eight times running, the obvious
        // explanation (an ambiguous response schema) was tested and ruled out, and what is left -
        // did the model return nothing, return the wrong type, or return something the importer
        // dropped - cannot be told apart from the interface. `debugmode` writes a file into
        // dataroot, which is fine for someone sitting at the machine and useless for everything
        // else: not per generation, and no report can read it.
        //
        // Off by default, and the field is only editable by a user with local/artqtml:configure.
        // That is the containment: the payloads are large, and a teacher who turned this on for
        // every generation would grow the log without anyone having decided to.
        $table = new xmldb_table('local_artqtml_generations');
        $field = new xmldb_field('diagnostics', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'countdiscrepancy');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026080105, 'local', 'artqtml');
    }

    if ($oldversion < 2026080106) {
        // BL-30, solved. The FE/FT option-count fragment said how many options to provide and
        // nothing about how many of them are correct, so the model wrote FE-shaped questions when
        // asked for FT - one correct option - and question_semantic_validator rejected every one:
        // "multichoiceset (FT): expected at least 2 correct options, got 1". Nine FT generations,
        // six rejections each, all reported as Completed.
        //
        // Replaced only where the stored value is exactly the string this repository shipped, so a
        // site that wrote its own keeps it. Glob-037/038: backed up first.
        $old = 'For FE and FT questions, provide between {{OPTION_MIN}} and {{OPTION_MAX}} answer options.';
        if ((string) get_config('local_artqtml', 'promptoptioncount') === $old) {
            \local_artqtml\local\setting_backup::backup('promptoptioncount', 2026080106, false);
            $shipped = \local_artqtml\local\prompt_seed::shipped();
            if (isset($shipped['promptoptioncount'])) {
                set_config('promptoptioncount', $shipped['promptoptioncount'], 'local_artqtml');
            }
        }

        \local_artqtml\local\prompt_seed::apply();

        upgrade_plugin_savepoint(true, 2026080106, 'local', 'artqtml');
    }

    if ($oldversion < 2026080301) {
        // BL-47: the "Seed" setting is gone, and so is the prompt line that carried it.
        //
        // It promised reproducibility it could not deliver. The value never went to the API as a
        // sampling parameter - the Claude Messages API has no `seed` parameter at all - it only
        // ever reached the model as the prompt line "Seed: <n>". Measured on 2026-08-03: changing
        // it from 42 to 77 and re-running the same cell on the same source text returned two of
        // six questions word-for-word identical and no new material.
        //
        // Two things have to happen here, and the second is the one that would be forgotten. The
        // stored config is easy. The stored PROMPT TEMPLATE is the administrator's own text, and it
        // still contains the "Seed: {{SEED}}" line - with the substitution gone, that line would
        // reach the model verbatim, braces and all, on every generation.
        unset_config('seed', 'local_artqtml');

        // Only touch the template where the line is exactly what this repository shipped, so an
        // administrator who wrote their own wording keeps it and is left to remove it themselves.
        // Glob-037/038: back up before rewriting, so the previous value survives and the
        // administrator is told where it is.
        $template = (string) get_config('local_artqtml', 'generatorprompttemplate');
        if (strpos($template, 'Seed: {{SEED}}') !== false) {
            \local_artqtml\local\setting_backup::backup('generatorprompttemplate', 2026080301, false);
            $stripped = str_replace(["\nSeed: {{SEED}}\n", "Seed: {{SEED}}\n", "\nSeed: {{SEED}}"], "\n", $template);
            $stripped = str_replace('Seed: {{SEED}}', '', $stripped);
            set_config('generatorprompttemplate', $stripped, 'local_artqtml');
        }

        upgrade_plugin_savepoint(true, 2026080301, 'local', 'artqtml');
    }

    if ($oldversion < 2026080303) {
        // BL-44: which plugin version a model check ran under.
        //
        // A structural failure takes a model out of the dropdown, and that has to be revocable by
        // us, not only by the provider. On 2026-08-03 Sonnet 5 and Opus 5 failed the check in the
        // morning and worked in the afternoon - the defect was on our side, in how the response was
        // read. Had the exclusion been permanent, our own fix would not have brought them back.
        //
        // So an exclusion is scoped to the plugin version that produced it, and a version bump
        // reopens every excluded model for checking. Adding a column is safe here; the table's own
        // comment names renaming and dropping as the breaking changes, because administrators read
        // it through Configurable Reports.
        $table = new xmldb_table('local_artqtml_modelcheck');
        $field = new xmldb_field('pluginversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'triggertype');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026080303, 'local', 'artqtml');
    }

    if ($oldversion < 2026080401) {
        // 2026-08-04: two new prompt fragments have to exist on sites that already have the plugin.
        //
        // They are what the system prompt now says INSTEAD of a teacher's own free-text difficulty
        // description and per-type instruction, which used to be substituted into it directly. If
        // these two keys were missing, the generator would drop that sentence and the model would
        // simply never be told that a teacher preference exists in the user message - the security
        // fix would hold, and the feature it protects would quietly stop working.
        //
        // prompt_seed::apply() only fills keys that are empty or absent, so an administrator's own
        // edited prompt text is never overwritten by this step.
        \local_artqtml\local\prompt_seed::apply();

        upgrade_plugin_savepoint(true, 2026080401, 'local', 'artqtml');
    }

    if ($oldversion < 2026080405) {
        // Glob-040 keeps a generation's log entries when the generation is deleted, on purpose:
        // the log is the record of what the plugin did, and deleting a generation does not undo
        // having done it. What it left behind was a `generationid` pointing at a row that no
        // longer existed - a foreign key that was false, and the only way to find those entries
        // again.
        //
        // The id now moves to `originalgenerationid` at deletion time and `generationid` becomes
        // NULL. The entry survives, it is still findable, and nothing claims a relationship that
        // is not there.
        $table = new xmldb_table('local_artqtml_log');

        $field = new xmldb_field(
            'originalgenerationid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'generationid'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('originalgenerationid', XMLDB_INDEX_NOTUNIQUE, ['originalgenerationid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Existing sites already carry these broken references - every generation deleted before
        // today left some. They are migrated rather than left alone, because a half-migrated table
        // is worse than either state: a later reader would have to know the date to interpret it.
        //
        // Read through a recordset rather than get_records(): on a site that has been generating
        // for months this can be a large number of rows, and the point of the exercise is not to
        // exchange a data problem for a memory one. No log row is deleted here or anywhere in this
        // step.
        $sql = "SELECT l.id, l.generationid
                  FROM {local_artqtml_log} l
             LEFT JOIN {local_artqtml_generations} g ON g.id = l.generationid
                 WHERE l.generationid IS NOT NULL AND g.id IS NULL";

        $orphans = $DB->get_recordset_sql($sql);
        try {
            foreach ($orphans as $orphan) {
                $DB->update_record('local_artqtml_log', (object) [
                    'id'                   => $orphan->id,
                    'originalgenerationid' => $orphan->generationid,
                    'generationid'         => null,
                ]);
            }
        } finally {
            $orphans->close();
        }

        upgrade_plugin_savepoint(true, 2026080405, 'local', 'artqtml');
    }

    if ($oldversion < 2026080703) {
        // Frankenstyle rename local_aiquizgen → local_artqtml: if an install still has the old
        // table/registry names (e.g. config_plugins was remapped before this upgrade so Moodle
        // treated the plugin as an upgrade rather than a fresh install), finish the cut here.
        // Fresh sites that never had aiquizgen are a no-op.
        \local_artqtml\local\component_rename::migrate_if_needed();

        upgrade_plugin_savepoint(true, 2026080703, 'local', 'artqtml');
    }

    if ($oldversion < 2026081004) {
        // Ban source-document meta-references in stems ("szöveg szerint" / "according to the text").
        //
        // New always-on generator fragment (promptnosourcemetaref): prompt_seed fills it on sites
        // that do not have it yet. The wording check on the AI validator is also extended; only
        // replace where the stored value is exactly the previously shipped sentence, so an
        // administrator's edit is kept (Glob-037/038).
        //
        // Savepoint is 2026081004: main already shipped 1003 (shortname help / BL-60) with no
        // upgrade step — sites at 1002 or 1003 still need this seed.
        \local_artqtml\local\prompt_seed::apply();

        $oldwording =
            'Check that the question, its answers and its feedback are correct, natural writing in the '
            . 'language of the source text - grammatical, idiomatic, and free of words that do not '
            . 'belong. A garbled or ungrammatical question is a defect even when it is factually '
            . 'correct; report it as needs_review with the problem named in the justification. A '
            . 'question that contains its own answer is the same kind of defect.';
        if ((string) get_config('local_artqtml', 'validationpromptwording') === $oldwording) {
            \local_artqtml\local\setting_backup::backup('validationpromptwording', 2026081004, false);
            $shipped = \local_artqtml\local\prompt_seed::shipped();
            if (isset($shipped['validationpromptwording'])) {
                set_config('validationpromptwording', $shipped['validationpromptwording'], 'local_artqtml');
            }
        }

        upgrade_plugin_savepoint(true, 2026081004, 'local', 'artqtml');
    }

    if ($oldversion < 2026081006) {
        // Finding #4: drop free-form debugfilepath. The PHP debug file log always uses
        // $CFG->dataroot/local_artqtml/debug.log; leftover admin config is ignored and removed
        // so it cannot be mistaken for still being in use. API traffic / diagnostics remain in DB.
        unset_config('debugfilepath', 'local_artqtml');

        upgrade_plugin_savepoint(true, 2026081006, 'local', 'artqtml');
    }

    if ($oldversion < 2026081010) {
        // Finding #10: per-page custom CSS editor removed. Branding belongs to the Moodle theme;
        // drop any leftover css_* config rows so they do not linger in config_plugins.
        foreach (['css_list', 'css_upload', 'css_settings', 'css_status', 'css_approve', 'css_license'] as $name) {
            unset_config($name, 'local_artqtml');
        }

        upgrade_plugin_savepoint(true, 2026081010, 'local', 'artqtml');
    }

    return true;
}
