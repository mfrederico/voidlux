<?php
/**
 * Timeline — one list of everything that changed in a game, each with an undo. The game's own git is the record: a change made
 * in a page is a commit of its own (record(), trailers say what kind and about what), the builder's work is the commits up to
 * each of its checkpoints. Undo reverses that one entry's diff in a new commit, so everything since stays, and nothing is lost:
 * an undo can be undone. A diff that no longer applies, because later work touched the same lines, is refused and named.
 */
namespace app;

use \app\Bean;

class Timeline {
    private const COALESCE = 120; // seconds: a slider dragged or a form saved again and again is one change, not twenty

    /** Commits what a page just changed, only those paths (the builder may be mid-run). The commit's id, or null when nothing changed. */
    public static function record(string $slug, string $title, string $kind, string $who, array $paths, string $key = '', bool $coalesce = false): ?string {
        $d = GameStore::dir($slug); // only paths git has something to say about: a folder the game ignores (versions/) is not an error, and not a change
        $paths = array_values(array_filter($paths, fn($p) => (file_exists("$d/$p") || GameStore::git($d, ['ls-files', '--error-unmatch', '--', $p])[0] === 0) && trim(GameStore::git($d, ['status', '--porcelain', '--', $p])[1]) !== ''));
        if (!$paths) return null;
        $msg = ['-m', mb_substr($title, 0, 200), '-m', "Studio-Kind: {$kind}\nStudio-Who: " . preg_replace('/\s+/', ' ', $who) . ($key !== '' ? "\nStudio-Key: {$key}" : '')];
        for ($try = 0; $try < 6; $try++) { // a builder's commit holds git's lock for a moment
            GameStore::git($d, array_merge(['add', '--'], $paths));
            $amend = $coalesce && self::canAmend($d, $kind, $who, $key) ? ['--amend'] : [];
            [$rc, $out] = GameStore::git($d, array_merge(['commit', '-q', '--only'], $amend, $msg, ['--'], $paths));
            if ($rc === 0) { $sha = trim(GameStore::git($d, ['rev-parse', 'HEAD'])[1]); GameStore::emit($slug, 'timeline', ['text' => $title, 'sha' => $sha, 'kind' => $kind]); return $sha; }
            if (!str_contains($out, 'index.lock')) break; usleep(300000);
        }
        // the change itself is saved and the next checkpoint sweeps it up: only its own line in the timeline, and its undo, are lost
        GameStore::emit($slug, 'error', ['text' => "timeline: \"{$title}\" could not be committed: " . mb_substr($out, -300)]); error_log("ERROR Timeline::record {$slug}: {$out}");
        return null;
    }
    /** The last commit is this same change still going on: same kind, same thing, same person, recent, not checkpointed or pushed. */
    private static function canAmend(string $d, string $kind, string $who, string $key): bool {
        [$rc, $h] = GameStore::git($d, ['log', '-1', '--format=%at%x1f%B']); if ($rc !== 0) return false; [$at, $body] = explode("\x1f", $h, 2) + [1 => ''];
        $t = self::trailers($body); if (($t['kind'] ?? '') !== $kind || ($t['who'] ?? '') !== $who || ($t['key'] ?? '') !== $key || isset($t['undo']) || time() - (int) $at > self::COALESCE) return false;
        if (trim(GameStore::git($d, ['tag', '--points-at', 'HEAD'])[1]) !== '') return false;
        return GameStore::git($d, ['merge-base', '--is-ancestor', 'HEAD', '@{u}'])[0] !== 0;
    }
    private static function trailers(string $body): array {
        $o = []; foreach (['kind' => 'Kind', 'who' => 'Who', 'key' => 'Key', 'undo' => 'Undo', 'was' => 'Was'] as $k => $n) if (preg_match('/^Studio-' . $n . ': (.+)$/m', $body, $m)) $o[$k] = trim($m[1]); return $o;
    }

    /** Newest first: {id (its newest commit), oldest, title, kind (numbers|rule|art|sound|concept|builder|undo…), key, who, at, cost, files, undone, undoes, canUndo, why}. */
    public static function entries(string $slug, int $max = 40): array {
        $d = GameStore::dir($slug);
        [$rc, $log] = GameStore::git($d, ['log', '--first-parent', '-' . ($max * 6), '--format=%H%x1f%P%x1f%at%x1f%s%x1f%B%x1e']); if ($rc !== 0) return [];
        $tags = []; [, $tl] = GameStore::git($d, ['tag', '-l', 'cp-*', '--format=%(*objectname)%1f%(objectname)%1f%(refname:short)%1f%(subject)']);
        foreach (array_filter(explode("\n", $tl)) as $l) { [$a, $b, $n, $s] = explode("\x1f", $l, 4) + [1 => '', 2 => '', 3 => '']; $tags[$a !== '' ? $a : $b][] = ['tag' => $n, 'label' => $s]; }
        $costs = self::builderCosts($slug); $out = []; $run = null;
        $flush = function () use (&$run, &$out) { if ($run) { $out[] = $run; $run = null; } };
        foreach (array_filter(array_map('trim', explode("\x1e", $log))) as $row) {
            [$sha, $parents, $at, $subject, $body] = explode("\x1f", $row, 5) + [1 => '', 2 => '', 3 => '', 4 => '']; $t = self::trailers($body); $parent = explode(' ', trim($parents))[0];
            if (isset($t['kind'])) { $flush(); $out[] = ['id' => $sha, 'parent' => $parent, 'title' => $subject, 'kind' => $t['kind'], 'key' => $t['key'] ?? '', 'who' => $t['who'] ?? '', 'at' => (int) $at, 'cost' => null, 'undoes' => $t['undo'] ?? '']; continue; }
            if (isset($tags[$sha]) && $run) $flush();
            if (!$run) $run = ['id' => $sha, 'parent' => $parent, 'title' => self::words($subject, $tags[$sha] ?? []), 'kind' => 'builder', 'key' => '', 'who' => 'the builder', 'at' => (int) $at, 'cost' => null, 'undoes' => ''];
            else { $run['parent'] = $parent; if (preg_match('/^(after chat:|checkpoint$|before rolling)/', $run['title'])) $run['title'] = self::words($subject, []); }
            foreach ($tags[$sha] ?? [] as $x) if (isset($costs[$x['tag']])) $run['cost'] = round(($run['cost'] ?? 0) + $costs[$x['tag']], 2);
            if (count($out) >= $max) break;
        }
        $flush(); $out = array_slice($out, 0, $max);
        // a builder entry points at the notes it was sent: those sent after the builder entry before it and by the end of this one
        $g = GameStore::row($slug); $sent = $g ? Bean::find('gamenote', "game_id = ? AND state IN ('sent', 'done') AND sent_at IS NOT NULL", [(int) $g->id]) : []; $byAt = []; foreach ($sent as $n) if (($t = strtotime((string) $n->sentAt)) !== false) $byAt[(int) $n->id] = $t;
        $since = 0; for ($i = count($out) - 1; $i >= 0; $i--) { if ($out[$i]['kind'] !== 'builder') continue; $ids = []; foreach ($byAt as $id => $t) if ($t > $since && $t <= $out[$i]['at']) $ids[] = $id; $out[$i]['notes'] = $ids; $since = $out[$i]['at']; }
        $busy = GameStore::agentState($slug)['busy']; $undone = [];
        foreach ($out as $i => $e) {
            $out[$i]['undone'] = isset($undone[$e['id']]); if ($e['undoes'] !== '' && !$out[$i]['undone']) $undone[$e['undoes']] = true;
            $out[$i]['canUndo'] = !$out[$i]['undone'] && $e['parent'] !== '' && !$busy;
            $out[$i]['why'] = $out[$i]['undone'] ? '' : ($busy ? 'The builder is working: wait for it, or stop it.' : ($e['parent'] === '' ? 'This is where the game began.' : ''));
            $out[$i]['files'] = $e['parent'] !== '' && $i < 40 ? count(array_filter(explode("\n", GameStore::git($d, ['diff', '--name-only', $e['parent'], $e['id']])[1]))) : 0;
        }
        return $out;
    }
    private static function words(string $subject, array $tags): string { return $subject !== '' ? $subject : (string) ($tags[0]['label'] ?? 'A change'); }
    /** What the builder's runs cost, by the checkpoint that ended them. */
    private static function builderCosts(string $slug): array {
        $f = GameStore::dir($slug) . '/.studio/events.jsonl'; if (!is_file($f)) return []; $h = fopen($f, 'r'); if (!$h) throw new \RuntimeException("cannot read {$f}"); $out = []; $sum = 0.0;
        while (($l = fgets($h)) !== false) {
            if (str_contains($l, '"kind":"done"')) { $e = json_decode($l, true); if (is_array($e) && ($e['label'] ?? '') !== 'art') $sum += (float) ($e['cost'] ?? 0); }
            elseif (str_contains($l, '"kind":"checkpoint"')) { $e = json_decode($l, true); if (is_array($e)) { $out[(string) ($e['tag'] ?? '')] = $sum; $sum = 0.0; } }
        }
        fclose($h); return $out;
    }

    /** Reverses one entry in a new commit. Returns {id, kind, key, title}. Refuses, naming why, when the builder is at work or later work overlaps it. */
    public static function undo(string $slug, string $id, string $who): array {
        if (!preg_match('/^[a-f0-9]{40}$/', $id)) throw new \InvalidArgumentException('That is not an entry of the timeline.');
        if (GameStore::agentState($slug)['busy']) throw new \RuntimeException('The builder is working on this game: wait for it, or stop it, then undo.');
        return Cast::locked($slug, function () use ($slug, $id, $who) {
            $e = null; foreach (self::entries($slug, 200) as $x) if ($x['id'] === $id) $e = $x;
            if (!$e) throw new \InvalidArgumentException('That change is too far back to undo from here: use Roll back on the game page.');
            if (!$e['canUndo']) throw new \InvalidArgumentException($e['why'] !== '' ? $e['why'] : 'That change is already undone.');
            $d = GameStore::dir($slug);
            GameStore::git($d, ['add', '-A']); if (GameStore::git($d, ['diff', '--cached', '--quiet'])[0] !== 0) GameStore::git($d, ['commit', '-q', '-m', 'Changes from the editors', '-m', "Studio-Kind: other\nStudio-Who: the editors"], true); // what is unsaved stays, in its own entry
            $patch = GameStore::studioDir($slug) . '/undo-' . substr($id, 0, 12) . '.patch';
            try {
                exec('git -C ' . escapeshellarg($d) . ' diff --binary --full-index ' . escapeshellarg($id) . ' ' . escapeshellarg($e['parent']) . ' > ' . escapeshellarg($patch) . ' 2>/dev/null', $o, $rc);
                if ($rc !== 0) throw new \RuntimeException("git could not read that change ({$rc})");
                if (filesize($patch) === 0) throw new \InvalidArgumentException('That change altered nothing, so there is nothing to undo.');
                [$rc, $out] = GameStore::git($d, ['apply', '--index', '--check', $patch]);
                if ($rc !== 0) { $file = preg_match('/patch failed: ([^:\s]+)/', $out, $m) || preg_match('/error: ([^:\s]+):/', $out, $m) ? $m[1] : ''; throw new \RuntimeException('Later work changed the same ' . ($file !== '' ? "part of {$file}" : 'files') . ', so this cannot be undone on its own. Undo the later change first.'); }
                GameStore::git($d, ['apply', '--index', $patch], true);
                $title = str_starts_with($e['title'], 'Undid: ') ? 'Put back: ' . substr($e['title'], 7) : 'Undid: ' . $e['title'];
                GameStore::git($d, ['commit', '-q', '-m', mb_substr($title, 0, 200), '-m', "Studio-Kind: undo\nStudio-Who: " . preg_replace('/\s+/', ' ', $who) . ($e['key'] !== '' ? "\nStudio-Key: {$e['key']}" : '') . "\nStudio-Undo: {$id}\nStudio-Was: {$e['kind']}"], true);
            } finally { @unlink($patch); }
            $sha = trim(GameStore::git($d, ['rev-parse', 'HEAD'])[1]);
            GameStore::emit($slug, 'timeline', ['text' => $title, 'sha' => $sha, 'kind' => 'undo']); GameStore::activity($slug, $who, $title);
            $was = $e['kind'] !== 'undo' ? $e['kind'] : (string) (self::trailers(GameStore::git($d, ['log', '-1', '--format=%B', $id])[1])['was'] ?? ''); // undoing an undo puts the same kind of thing back
            return ['id' => $sha, 'kind' => $was, 'key' => $e['key'], 'key' => $e['key'], 'title' => $title, 'undoes' => $id];
        });
    }
}
