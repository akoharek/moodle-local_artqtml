<?php
/**
 * Dev-only CLI: seed Claude/Gemini API keys from environment variables.
 *
 * NOT part of the shipped plugin (excluded from the deployment zip). Use after a fresh Docker
 * install or when both config_plugins and the moodledata backup are empty.
 *
 * Usage (from moodle-docker):
 *   docker compose ... exec -e ARTQTML_CLAUDE_API_KEY=sk-ant-... \
 *     -e ARTQTML_GEMINI_API_KEY=AIza... webserver \
 *     php local/artqtml/tools/seed_dev_api_keys.php
 *
 * Optional: --force overwrites keys that are already configured.
 *
 * @package    local_artqtml
 * @copyright  2026 AR Tudásmenedzsment Kft.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_artqtml\local\api_key_backup;
use local_artqtml\local\encrypted_config;

[$options, $unrecognized] = cli_get_params(
    ['help' => false, 'force' => false],
    ['h' => 'help', 'f' => 'force']
);

if ($unrecognized) {
    $unrecognized = implode(' ', $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    echo "Seed Claude/Gemini API keys from environment variables.\n\n";
    echo "Variables:\n";
    echo "  ARTQTML_CLAUDE_API_KEY   Anthropic API key\n";
    echo "  ARTQTML_GEMINI_API_KEY   Google Gemini API key\n\n";
    echo "Options:\n";
    echo "  -h, --help    Print this help\n";
    echo "  -f, --force   Overwrite keys that are already set\n";
    exit(0);
}

cli_require_admin();

$map = [
    'claudeapikey' => getenv('ARTQTML_CLAUDE_API_KEY'),
    'geminiapikey' => getenv('ARTQTML_GEMINI_API_KEY'),
];

$wrote = 0;
foreach ($map as $name => $plain) {
    $plain = is_string($plain) ? trim($plain) : '';
    if ($plain === '') {
        continue;
    }

    $current = get_config('local_artqtml', $name);
    if (!$options['force'] && $current !== false && $current !== '') {
        cli_writeln("Skipped {$name}: already configured (use --force to overwrite).");
        continue;
    }

    try {
        $encrypted = \core\encryption::encrypt($plain);
    } catch (Throwable $e) {
        cli_error("Could not encrypt {$name}: " . $e->getMessage());
    }

    set_config($name, $encrypted, 'local_artqtml');
    encrypted_config::clear_failure($name);
    api_key_backup::backup_setting($name, $encrypted);
    cli_writeln("Set {$name}.");
    $wrote++;
}

if ($wrote === 0) {
    cli_writeln('Nothing to do. Set ARTQTML_CLAUDE_API_KEY and/or ARTQTML_GEMINI_API_KEY.');
    exit(1);
}

cli_writeln("Done ({$wrote} key(s)).");
