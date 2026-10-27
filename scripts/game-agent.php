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
$art = $mode === 'art'; // the art lane: a sprite job beside the builder, with its own prompt file and lock
$pf = $dir . '/.studio/' . ($art ? 'art-prompt.txt' : 'prompt.txt');
$prompt = is_file($pf) ? trim((string) file_get_contents($pf)) : '';
$state = $art ? \app\GameStore::artState($slug) : \app\GameStore::agentState($slug);
$state = array_merge($state, ['busy' => true, 'mode' => $mode, 'pid' => getmypid(), 'started' => date('c')]);
if ($art) \app\GameStore::saveArtState($slug, $state); else \app\GameStore::saveAgentState($slug, $state);
$agent = new \app\GameAgent($slug, $memberId, $art ? 'art' : 'build');
$outcome = 'finished';
try {
    switch ($mode) {
        case 'pipeline': $agent->pipeline($prompt); break;
        case 'review': $agent->reviewOnly(); break;
        case 'fix': $agent->fix(); break;
        case 'soak': { $p = array_map('intval', explode(' ', $prompt . ' ')); $agent->soakOnly($p[0] ?: 60, $p[1] ?: 4); break; }
        case 'art': $agent->art($prompt); break;
        default: $agent->chat($prompt);
    }
} catch (\Throwable $e) {
    $outcome = 'failed: ' . $e->getMessage();
    \app\GameStore::emit($slug, 'error', ['text' => 'runner: ' . $e->getMessage()]);
} finally {
    if ($art) {
        $s = \app\GameStore::artState($slug);
        if ((int) $s['pid'] === getmypid() || !\app\GameStore::alive((int) $s['pid'])) { $s['busy'] = false; $s['mode'] = ''; $s['pid'] = 0; \app\GameStore::saveArtState($slug, $s); }
        \app\GameStore::emit($slug, 'artdone', ['text' => $outcome]);
        \app\GameStore::startQueuedArt($slug); // the next sprite job that waited
    } else {
        $s = \app\GameStore::agentState($slug);
        if ((int) $s['pid'] === getmypid() || !\app\GameStore::alive((int) $s['pid'])) { $s['busy'] = false; $s['mode'] = ''; $s['pid'] = 0; \app\GameStore::saveAgentState($slug, $s); } // only my own lock
        \app\GameStore::emit($slug, 'idle', []);
        if ($mode !== 'soak') \app\GameNotices::finished($slug, $mode, $outcome, $memberId);
        \app\GameStore::startQueued($slug); // a run of its own that waited for this one
    }
}
