#!/usr/bin/env php
<?php
/**
 * forge-sweep.php — every minute, start whatever is waiting with nothing running to carry it: queued notes for a builder
 * that is idle, runs queued behind a builder run that is gone, sprite jobs queued behind an art run that is gone. The
 * runner's own after-run hook does this when a run ends normally; the sweep covers a runner that was killed, a restart,
 * or a note that landed in the instant between two runs. It also settles the 3D cast (lib/Cast.php): models a batch made are kept. Deterministic and cheap: file reads, and a run only when one
 * is due. A note it has started twice (tries) is left for a person, as the hook does.
 *   cron: * * * * *   (scripts/install-retro-cron.sh installs it)
 *   php scripts/forge-sweep.php --dry   says what it would start
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../vendor/autoload.php';
chdir(dirname(__DIR__));
define('BASE_PATH', \app\Paths::root());
new \app\Bootstrap('conf/config.ini');
$dry = in_array('--dry', $argv, true); $did = []; $kept = [];
foreach (\app\Bean::find('game') as $g) {
    $slug = (string) $g->slug; if (!is_dir(\app\GameStore::dir($slug))) continue;
    try {
        $s = \app\GameStore::agentState($slug); // agentState already clears a lock whose process is gone
        if (!$s['busy']) {
            $q = \app\GameStore::studioDir($slug) . '/queue.jsonl';
            if (is_file($q) && trim((string) file_get_contents($q)) !== '') { $did[] = "$slug: a queued run"; if (!$dry) \app\GameStore::startQueued($slug); }
            else { $notes = array_filter(\app\GameStore::peekNotes($slug), fn($n) => (int) ($n['tries'] ?? 0) < 2);
                // a note gets a minute's grace: the run that just ended starts it itself, and a burst of notes goes as one run
                $f = \app\GameStore::studioDir($slug) . '/inbox.jsonl'; $fresh = is_file($f) && time() - filemtime($f) < 45;
                if ($notes && !$fresh) { $did[] = "$slug: " . count($notes) . ' queued note(s)'; if (!$dry) { \app\GameStore::emit($slug, 'note', ['text' => 'the sweep found ' . count($notes) . ' note(s) waiting with nothing running: a run for them starts']); \app\GameStore::startRunner($slug, 'chat', 'The producer sent notes while you were busy; they are listed below, numbered. Handle each one.', 0); } } }
        }
        if (!$dry && (is_file(\app\Cast::path($slug)) || is_file(\app\GameStore::studioDir($slug) . "/cast-list.json"))) foreach (\app\Cast::settle($slug) as $d) $kept[] = "$slug: $d"; if (!$dry && is_dir(\app\GameStore::dir($slug) . '/sketches') && ($n = \app\Cast::strips($slug))) $kept[] = "$slug: $n 3D thumbnail(s)"; // upkeep, not starts: the retrospective counts only "started" lines as runs the after-run hook missed // 3D cast jobs that landed with no runner end to settle them
        $aq = \app\GameStore::studioDir($slug) . '/art-queue.jsonl'; // queued sprite jobs and free slots in the art lane: they start
        if (is_file($aq) && trim((string) file_get_contents($aq)) !== '') { if ($dry) $did[] = "$slug: queued sprite jobs"; elseif ($n = \app\GameStore::startQueuedArt($slug)) $did[] = "$slug: $n queued sprite job(s)"; }
    } catch (\Throwable $e) { fwrite(STDERR, date('c') . " ERROR sweep $slug: " . $e->getMessage() . "\n"); }
}
if ($did) echo date('c') . ($dry ? ' would start: ' : ' started: ') . implode('; ', $did) . "\n";
if ($kept) echo date('c') . ' upkeep: ' . implode('; ', $kept) . "\n";
