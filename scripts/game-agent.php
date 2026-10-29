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
        try { foreach (\app\Cast::settle($slug) as $d) \app\GameStore::emit($slug, 'note', ['text' => "3D cast: {$d}"]); } catch (\Throwable $e) { \app\GameStore::emit($slug, 'error', ['text' => '3D cast: ' . $e->getMessage()]); } // a cast list or batch model that just landed
        \app\GameStore::startQueuedArt($slug); // the next sprite job that waited
    } else {
        $s = \app\GameStore::agentState($slug);
        if ((int) $s['pid'] === getmypid() || !\app\GameStore::alive((int) $s['pid'])) { $s['busy'] = false; $s['mode'] = ''; $s['pid'] = 0; \app\GameStore::saveAgentState($slug, $s); } // only my own lock
        \app\GameStore::emit($slug, 'idle', []);
        if ($mode !== 'soak') \app\GameNotices::finished($slug, $mode, $outcome, $memberId);
        if (!\app\GameStore::startQueued($slug) && $mode !== 'soak') { // nothing else queued: notes that arrived during this run get a run of their own now
            $waiting = \app\GameStore::peekNotes($slug); $fresh = array_filter($waiting, fn($n) => (int) ($n['tries'] ?? 0) < 2);
            if ($fresh) { try { \app\GameStore::startRunner($slug, 'chat', 'The producer sent notes while you were busy; they are listed below, numbered. Handle each one.', 0); } catch (\Throwable $e) { \app\GameStore::emit($slug, 'error', ['text' => 'the queued notes could not start a run: ' . $e->getMessage()]); } }
            elseif ($waiting) \app\GameStore::emit($slug, 'notes', ['text' => count($waiting) . ' note(s) left unanswered after two tries; they stay queued for the next run you start', 'missing' => array_map(fn($n) => mb_substr((string) $n['text'], 0, 120), $waiting), 'answered' => 0, 'total' => count($waiting)]);
        }
    }
}
