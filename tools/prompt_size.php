<?php
/**
 * Standalone dev/QA tool: measures what a generation actually sends to the generator API, and
 * projects what the same generation would cost if it were split into one call per question type.
 *
 * Written for the "one prompt or one per type?" decision (BL-33). The argument for splitting is
 * that the schema becomes unambiguous and the type/difficulty pairing becomes exact; the argument
 * against is that the source text is re-sent with every call. Which wins depends on how much of a
 * request the source text actually is - a number nobody had, so this prints it instead of
 * estimating it.
 *
 * It measures through the plugin's own code (`generate_questions_task::build_prompt()` and
 * `question_schema::build()`), reached by reflection because both are protected. A second
 * implementation here would be a second source of truth, and the whole point is to measure what
 * really goes out.
 *
 * Token figures are the plugin's own rough estimate - characters / 4, the same divisor
 * validate_questions_task uses for batching. Treat them as proportions, not invoices.
 *
 * NOT part of the shipped plugin - `tools/` is excluded from the deployment zip, from phpcs and
 * from phpstan.
 *
 * Usage, from the Moodle root inside the webserver container:
 *   php local/artqtml/tools/prompt_size.php --generationid=1258
 *   php local/artqtml/tools/prompt_size.php --latest
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

use local_artqtml\local\question_schema;
use local_artqtml\local\question_types;
use local_artqtml\task\generate_questions_task;

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

if (!empty($args['latest'])) {
    $rows = $DB->get_records('local_artqtml_generations', null, 'id DESC', 'id', 0, 1);
    $generationid = $rows ? (int) reset($rows)->id : 0;
} else {
    $generationid = (int) ($args['generationid'] ?? 0);
}

if ($generationid <= 0) {
    echo "Adj meg egy generálást: --generationid=<id> vagy --latest\n";
    exit(1);
}

$generation = $DB->get_record('local_artqtml_generations', ['id' => $generationid]);
if (!$generation) {
    echo "Nincs ilyen generálás: {$generationid}\n";
    exit(1);
}

$settings = json_decode((string) $generation->settings, true);
if (!is_array($settings)) {
    echo "A generálásnak nincs értelmezhető beállítása.\n";
    exit(1);
}

/**
 * Call generate_questions_task::build_prompt() for a settings array. Protected on purpose; this is
 * a measuring tool, and measuring a copy would measure the copy.
 *
 * @param \stdClass $generation
 * @param array $settings
 * @return string
 */
function artqtml_build_prompt(\stdClass $generation, array $settings): string {
    $task = new generate_questions_task();
    $method = new ReflectionMethod($task, 'build_prompt');
    $method->setAccessible(true);

    return (string) $method->invoke($task, $generation, $settings);
}

$chars = static fn(string $s): int => \core_text::strlen($s);
$tok = static fn(int $c): int => (int) round($c / 4);

$source = (string) $generation->sourcetext;
$sourcechars = $chars($source);

$system = artqtml_build_prompt($generation, $settings);
$schema = json_encode(question_schema::build($settings));

$syschars = $chars($system);
$schemachars = $chars((string) $schema);
$total = $syschars + $schemachars + $sourcechars;

$counts = $settings['counts'] ?? [];
$requested = [];
foreach (question_types::CODES as $code) {
    if ((int) ($counts[$code] ?? 0) > 0) {
        $requested[$code] = (int) $counts[$code];
    }
}

echo "Generálás {$generationid} — {$generation->shortname}\n";
echo "Kért típusok: " . ($requested
    ? implode(', ', array_map(static fn($c, $n) => "{$c}×{$n}", array_keys($requested), $requested))
    : '(egy sem)') . "\n\n";

printf("%-34s %10s %10s %7s\n", 'EGY HÍVÁS (a mai működés)', 'karakter', '~token', 'arány');
echo str_repeat('-', 65) . "\n";
printf("%-34s %10d %10d %6.1f%%\n", 'rendszerprompt', $syschars, $tok($syschars), 100 * $syschars / max($total, 1));
printf("%-34s %10d %10d %6.1f%%\n", 'válaszséma (output_config)', $schemachars, $tok($schemachars), 100 * $schemachars / max($total, 1));
printf("%-34s %10d %10d %6.1f%%\n", 'forrásszöveg (user üzenet)', $sourcechars, $tok($sourcechars), 100 * $sourcechars / max($total, 1));
printf("%-34s %10d %10d\n", 'ÖSSZESEN', $total, $tok($total));

if (count($requested) < 2) {
    echo "\nEgyetlen kérdéstípus — nincs mit szétbontani.\n";
    exit(0);
}

echo "\n";
printf("%-34s %10s %10s\n", 'TÍPUSONKÉNTI SZÉTBONTÁS', 'karakter', '~token');
echo str_repeat('-', 65) . "\n";

$splittotal = 0;
foreach ($requested as $code => $n) {
    $one = $settings;
    foreach (question_types::CODES as $c) {
        $one['counts'][$c] = ($c === $code) ? $n : 0;
    }
    $onesystem = $chars(artqtml_build_prompt($generation, $one));
    $oneschema = $chars((string) json_encode(question_schema::build($one)));
    $onetotal = $onesystem + $oneschema + $sourcechars;
    $splittotal += $onetotal;
    printf(
        "  %-32s %10d %10d   (prompt %d + séma %d + forrás %d)\n",
        $code . ' hívás',
        $onetotal,
        $tok($onetotal),
        $onesystem,
        $oneschema,
        $sourcechars
    );
}

printf("%-34s %10d %10d\n", 'SZÉTBONTVA ÖSSZESEN', $splittotal, $tok($splittotal));
echo "\n";
printf(
    "A szétbontás %.2f-szeres bemenetet jelent (+%d karakter, ~+%d token).\n",
    $splittotal / max($total, 1),
    $splittotal - $total,
    $tok($splittotal) - $tok($total)
);
printf(
    "Ebből a többletből %d karaktert (%.0f%%) a forrásszöveg %d-szeri újraküldése tesz ki.\n",
    (count($requested) - 1) * $sourcechars,
    100 * (count($requested) - 1) * $sourcechars / max($splittotal - $total, 1),
    count($requested) - 1
);
echo "\nA kimenet (a legenerált kérdések) mennyisége nem változik, csak a bemeneté.\n";
