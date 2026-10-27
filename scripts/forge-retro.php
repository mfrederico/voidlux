#!/usr/bin/env php
<?php
/**
 * forge-retro.php — the forge's retrospective, on the clock (see lib/Retro.php for what is deterministic and what is agentic).
 *   php scripts/forge-retro.php                 the hour just ended: measures, findings, the agent's note and proposals, the notice
 *   php scripts/forge-retro.php --no-agent      the same without the agent (numbers and rule findings only)
 *   php scripts/forge-retro.php --quiet         no notice
 *   php scripts/forge-retro.php --weekly        the week rolled up
 *   php scripts/forge-retro.php --at=<unix>     a past hour (its end)
 * cron: 5 * * * * for the hour; 10 9 * * 0 --weekly
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../vendor/autoload.php';
chdir(dirname(__DIR__));
define('BASE_PATH', \app\Paths::root());
new \app\Bootstrap('conf/config.ini');
$opts = getopt('', ['no-agent', 'quiet', 'weekly', 'at::']);
$at = isset($opts['at']) ? (int) $opts['at'] : null;
$e = isset($opts['weekly']) ? \app\Retro::week($at, !isset($opts['quiet'])) : \app\Retro::hour($at, !isset($opts['no-agent']), !isset($opts['quiet']));
$m = $e['measures'];
printf("%s %s: build %d art %d $%.2f | art jobs %d (ok %d, recovered %d, failed %d) | keeps %d | findings %d | proposals %d%s\n", $e['kind'], $e['at'], $m['runs']['build'], $m['runs']['art'], $m['cost']['total'], $m['art']['jobs'], $m['art']['finished'], $m['art']['recovered'], $m['art']['failed'], $m['keeps'], count($e['findings']), count($e['proposals']), $e['agent'] ? sprintf(' | agent %s $%.3f%s', $e['agent']['ok'] ? 'ok' : 'FAILED', $e['agent']['cost'], $e['agent']['error'] ? ': ' . $e['agent']['error'] : '') : '');
foreach ($e['findings'] as $f) echo "  - [{$f['severity']}] {$f['text']}\n";
if ($e['note'] !== '') echo "  note: " . str_replace("\n", "\n        ", $e['note']) . "\n";
foreach ($e['proposals'] as $i => $p) echo '  ' . ($i + 1) . ". {$p['title']} ({$p['kind']}, {$p['file']}" . (empty($p['file_exists']) ? ', file not found' : '') . ")\n     {$p['change']}\n";
