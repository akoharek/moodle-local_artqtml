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
 * One running generation per person.
 *
 * WHAT IT DECIDES, in one sentence: whether this person already has another generation being
 * worked on right now - and if so, which one, so the refusal can name it and lead there.
 *
 * HOW THAT IS MADE TRUE, because it is not true of the stored row by itself: the start path in
 * `generate.php` WRITES `userid` to the starting user in the same locked step that sets
 * `generating`. From then on the row belongs to whoever started it, and this query - which reads
 * `userid` - is counting the right person's runs.
 *
 * THE VISIBLE SIDE EFFECT OF THAT WRITE, said here so it is not discovered later. `userid` is
 * also what the list page's "Létrehozó" column shows and what
 * {@see local_artqtml_owner_warning_banner()} names in its yellow "you are viewing someone
 * else's generation" bar. After a colleague starts a generation that somebody else created, both
 * name the colleague - the person who started it - not the original creator. The column keeps its
 * label; what it reports is now the last person to start the run.
 *
 * WHAT COUNTS AS RUNNING is {@see generation_status::IN_PROGRESS} - generating, validating, saving
 * read from there rather than re-listed here. `started` is deliberately NOT running
 * a teacher may keep as many drafts as they like, and a draft costs nothing until it is started.
 * The terminal three (completed, partial, failed) are finished by definition.
 *
 * WHAT THIS COSTS, written down before it is discovered. A generation stuck in `generating` - a
 * cron that died, a run cut off half-way - now locks the person it belongs to out of starting
 * anything at all, where before they would simply have started another one. The way out is the
 * status page's "Megszakítás" (Cancel) button, which rolls the generation back to `started`; that
 * is why both refusals below redirect the teacher to exactly that page rather than merely saying
 * no.
 *
 * WHAT RETRY DOES NOT DO, so the seam is written down rather than found. It asks this question
 * about `$USER`, but it does NOT rewrite `userid` to them - status.php's full-record write-back was
 * checks one person's allowance and leaves the row counted against another's. In the everyday case
 * (people retry their own) the two are the same person.
 *
 * @package    local_artqtml
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_artqtml\local;

/**
 * The single answer to "may this person start another generation right now?".
 */
class generation_start_policy {
    /**
 * This person's generation that is running right now, if there is one.
 *
 * WHAT TO PASS AS $userid: the user who pressed the button - `$USER->id` at both call sites.
 * It is matched against `local_artqtml_generations.userid`, which the start path sets to the
 * starting user, so the two are the same thing by construction (see the file comment above).
 *
 * @param int $userid the person whose allowance is being counted; matched on
 * local_artqtml_generations.userid
 * @param int $excludegenerationid the generation being started, left out of its own check
 * @return \stdClass|null id, name and status of the blocking generation, or null if free
 */
    public static function find_running(int $userid, int $excludegenerationid = 0): ?\stdClass {
        global $DB;

        [$statussql, $params] = generation_status::in_progress_sql();
        $params['startedby'] = $userid;
        $params['excludeid'] = $excludegenerationid;

        // Oldest first: with more than one in progress (possible from rows that predate this rule),
        // the one to wait for or cancel is the one that has been running longest.
        $records = $DB->get_records_select(
            'local_artqtml_generations',
            'userid = :startedby AND id <> :excludeid AND ' . $statussql,
            $params,
            'timecreated ASC, id ASC',
            'id, name, status, userid',
            0,
            1
        );

        $first = reset($records);

        return $first instanceof \stdClass ? $first : null;
    }
}
