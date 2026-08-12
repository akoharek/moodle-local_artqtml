<?php
/**
 * Standalone dev/QA tool: prints the raw pipeline state of recent generations, so a generation
 * That is stuck can be told apart from one that is merely slow.
 *
 * Why this exists. `process_pending_generations` selects on two columns at once - an in-progress
 * Status AND `processingtoken IS NULL` - and the interface shows neither. A generation displaying
 * "Kérdések generálása" for an hour is therefore ambiguous from the screen alone: it may be
 * Waiting for the next tick, or it may carry a claim token from a run that never released it, in
 * Which case no future tick will ever pick it up. C-01 in that task installs a
 * Register_shutdown_function() as a safety net, but that net does not run when the process is
 * Killed outright rather than exiting.
 *
 * Read-only: this script writes nothing. Use --release to clear a stuck claim (that one does
 * Write, and says exactly what it changed).
 *
 * NOT part of the shipped plugin - `tools/` is excluded from the deployment zip, from phpcs and
 * From phpstan.
 *
 * Usage, from the Moodle root inside the webserver container:
 *   php local/artqtml/tools/generation_state.php
 *   php local/artqtml/tools/generation_state.php --limit=30
 *   php local/artqtml/tools/generation_state.php --stuck
 *   php local/artqtml/tools/generation_state.php --release=1302
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

$limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 15;

if (!empty($args['release'])) {
    $id = (int) $args['release'];
    $row = $DB->get_record('local_artqtml_generations', ['id' => $id], 'id, name, status, processingtoken');
    if (!$row) {
        echo "Nincs ilyen generálás: {$id}\n";
        exit(1);
    }
    if ($row->processingtoken === null) {
        echo "A {$id} nem hordoz igényt (processingtoken már NULL) - nincs mit felszabadítani.\n";
        exit(0);
    }
    $DB->set_field('local_artqtml_generations', 'processingtoken', null, ['id' => $id]);
    echo "A {$id} igénye felszabadítva. Státusz: {$row->status}. "
        . "A következő ütemezett futás fel fogja venni, ha a státusz még folyamatban van.\n";
    exit(0);
}

$rows = $DB->get_records(
    'local_artqtml_generations',
    null,
    'id DESC',
    'id, name, shortname, status, processingtoken, draftcategoryid, timecreated, timemodified',
    0,
    $limit
);

$inprogress = \local_artqtml\local\generation_status::IN_PROGRESS;
$now = time();
$stuck = [];

printf("%-6s %-22s %-12s %-8s %-10s %s\n", 'ID', 'Rövid név', 'Státusz', 'Igény', 'Kora', 'Felveszi a következő futás?');
echo str_repeat('-', 100) . "\n";

foreach ($rows as $r) {
    $claimed = $r->processingtoken !== null && $r->processingtoken !== '';
    $isinprogress = in_array($r->status, $inprogress, true);
    $age = $now - (int) $r->timemodified;
    $agetext = $age > 3600 ? round($age / 3600, 1) . ' óra' : round($age / 60) . ' perc';

    if (!$isinprogress) {
        $verdict = '— (nem folyamatban)';
    } else if ($claimed) {
        $verdict = 'NEM — igényt hordoz';
        $stuck[] = (int) $r->id;
    } else {
        $verdict = 'igen';
    }

    printf(
        "%-6d %-22s %-12s %-8s %-10s %s\n",
        $r->id,
        \core_text::substr((string) $r->shortname, 0, 22),
        $r->status,
        $claimed ? 'van' : '-',
        $agetext,
        $verdict
    );
}

if (!empty($args['stuck'])) {
    echo "\n";
    if ($stuck === []) {
        echo "Nincs beragadt generálás a vizsgált {$limit} sorban.\n";
    } else {
        echo "Beragadt (folyamatban van, de igényt hordoz): " . implode(', ', $stuck) . "\n";
        echo "Felszabadítás egyesével: php local/artqtml/tools/generation_state.php --release=<id>\n";
    }
}
