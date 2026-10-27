#!/usr/bin/env php
<?php
/**
 * The agent runner for one game, started detached by the web (GameStore::startRunner) and run to the end of its mode:
 *   php scripts/game-agent.php <slug> chat|pipeline|review|fix|soak <memberId>
 * The prompt (chat, or the producer's note to the planner) is read from games/<slug>/.studio/prompt.txt. Progress goes to
 * .studio/events.jsonl; the lock is .studio/agent.json (busy, pid). When it ends, the owner gets a Communications
 * notice saying how it went.
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../vendor/autoload.php';
chdir(dirname(__DIR__));
define('BASE_PATH', \app\Paths::root());
new \app\Bootstrap('conf/config.ini');
[$slug, $mode, $memberId] = [(string) ($argv[1] ?? ''), (string) ($argv[2] ?? 'chat'), (int) ($argv[3] ?? 0)];
$dir = \app\GameStore::dir($slug);
$prompt = is_file($dir . '/.studio/prompt.txt') ? trim((string) file_get_contents($dir . '/.studio/prompt.txt')) : '';
$state = \app\GameStore::agentState($slug);
$state = array_merge($state, ['busy' => true, 'mode' => $mode, 'pid' => getmypid(), 'started' => date('c')]);
\app\GameStore::saveAgentState($slug, $state);
$agent = new \app\GameAgent($slug, $memberId);
$outcome = 'finished';
try {
    switch ($mode) {
        case 'pipeline': $agent->pipeline($prompt); break;
        case 'review': $agent->reviewOnly(); break;
        case 'fix': $agent->fix(); break;
        case 'soak': { $p = array_map('intval', explode(' ', $prompt . ' ')); $agent->soakOnly($p[0] ?: 60, $p[1] ?: 4); break; }
        default: $agent->chat($prompt);
    }
} catch (\Throwable $e) {
    $outcome = 'failed: ' . $e->getMessage();
    \app\GameStore::emit($slug, 'error', ['text' => 'runner: ' . $e->getMessage()]);
} finally {
    $s = \app\GameStore::agentState($slug);
    if ((int) $s['pid'] === getmypid() || !\app\GameStore::alive((int) $s['pid'])) { $s['busy'] = false; $s['mode'] = ''; $s['pid'] = 0; \app\GameStore::saveAgentState($slug, $s); } // only my own lock: a runner started after a Stop must not be cleared by the one dying
    \app\GameStore::emit($slug, 'idle', []);
    if ($mode !== 'soak') \app\GameNotices::finished($slug, $mode, $outcome, $memberId);
    \app\GameStore::startQueued($slug); // a sprite job (or another run of its own) that waited for this one
}
