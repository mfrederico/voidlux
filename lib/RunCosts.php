<?php
/**
 * RunCosts — what a run is likely to cost, from what runs here have cost: the `done` events in every game's
 * .studio/events.jsonl, the same figures the game page's Spent shows. Worked out once a day (data/run-costs.json), so a page
 * can ask freely. Too little history gives no figure and says so: an invented estimate is worse than none.
 */
namespace app;

class RunCosts {
    private const TTL = 86400; private const MIN_RUNS = 3; private const KEEP = 100; // recent runs only: long sessions cost more before the builder's handoff

    /** {what: what the run does, cost: its likely cost in words, usual, high (null without enough history)} for a mode: new|pipeline|fix|review|chat. */
    public static function estimate(string $slug, string $mode): array {
        $t = self::table(); $k = $t['kinds'];
        if ($mode === 'new') return self::said('Plans the game, builds every task, playtests it and has it reviewed.', $t['games']);
        if ($mode === 'chat') return self::said('The builder does what you ask.', $k['chat'] ?? null);
        if ($mode === 'review') return self::said('An independent reviewer checks every acceptance criterion.', $k['review'] ?? null);
        if ($mode === 'fix') { $e = self::said('Runs a playtest, which is free. If it fails, the builder fixes it and tests again; that part costs money.', $k['task'] ?? null); $e['cost'] = 'If it needs fixing: ' . lcfirst($e['cost']); return $e; }
        if ($mode !== 'pipeline') throw new \InvalidArgumentException("no estimate for {$mode}: it is new, pipeline, fix, review or chat");
        $tasks = GameStore::tasks($slug); $open = count(array_filter($tasks, fn($x) => !$x['done']));
        if (!$tasks) return self::said('Plans the game, builds every task, playtests it and has it reviewed.', $t['games']);
        $review = $k['review'] ?? null; $rework = $k['rework'] ?? $k['task'] ?? null;
        if (!$open) return self::said('Every task is done, so this playtests the game and has it reviewed. If the review fails, the builder reworks it, up to twice.', $review && $rework ? ['n' => $review['n'], 'usual' => $review['usual'], 'high' => $review['high'] + 2 * $rework['high']] : null);
        $task = $k['task'] ?? null; $what = 'Builds the ' . ($open === 1 ? 'open task' : "{$open} open tasks") . ', playtests the game and has it reviewed.';
        return self::said($what, $task && $review ? ['n' => $task['n'], 'usual' => $open * $task['usual'] + $review['usual'], 'high' => $open * $task['high'] + $review['high']] : null);
    }
    private static function said(string $what, ?array $s): array {
        if (!$s) return ['what' => $what, 'cost' => 'There are too few earlier runs here to estimate its cost.', 'usual' => null, 'high' => null];
        return ['what' => $what, 'cost' => 'Usually about ' . self::money($s['usual']) . ', up to ' . self::money($s['high']) . ' (from ' . $s['n'] . ' earlier ' . ($s['n'] === 1 ? 'run' : 'runs') . ' here).', 'usual' => round($s['usual'], 2), 'high' => round($s['high'], 2)];
    }
    public static function money(float $v): string { return $v >= 10 ? '$' . round($v) : '$' . number_format($v, 2); }

    /** {kinds: {chat|task|plan|review|rework|fix|art: {n, usual, high}}, games: the same for a whole game's build, or null}. */
    public static function table(): array {
        $f = GameStore::root() . '/data/run-costs.json';
        if (is_file($f) && time() - filemtime($f) < self::TTL) { $c = json_decode((string) file_get_contents($f), true); if (is_array($c) && isset($c['kinds'])) return $c; }
        $runs = []; $games = [];
        foreach (glob(GameStore::gamesDir() . '/*/.studio/events.jsonl') ?: [] as $p) {
            if (str_starts_with(basename(dirname($p, 2)), 'zz-')) continue; // a throwaway test game
            $total = 0.0; $planned = false; $h = fopen($p, 'r'); if (!$h) throw new \RuntimeException("cannot read {$p}");
            while (($l = fgets($h)) !== false) {
                if (!str_contains($l, '"kind":"done"')) continue;
                $e = json_decode($l, true); if (!is_array($e) || (float) ($e['cost'] ?? 0) <= 0) continue;
                $label = (string) ($e['label'] ?? ''); $kind = preg_match('/^T\d+$/', $label) ? 'task' : $label;
                if (!in_array($kind, ['chat', 'task', 'plan', 'review', 'rework', 'fix', 'art'], true)) continue;
                $runs[$kind][] = [(float) ($e['t'] ?? 0), (float) $e['cost']];
                if ($kind === 'plan') $planned = true; if (!in_array($kind, ['chat', 'art'], true)) $total += (float) $e['cost'];
            }
            fclose($h);
            if ($planned && $total > 0) $games[] = $total; // a game the pipeline built: what its build came to, without the chat and the art
        }
        $kinds = [];
        foreach ($runs as $kind => $list) { usort($list, fn($a, $b) => $b[0] <=> $a[0]); $s = self::spread(array_column(array_slice($list, 0, self::KEEP), 1)); if ($s) $kinds[$kind] = $s; }
        $t = ['at' => date('c'), 'kinds' => $kinds, 'games' => self::spread($games)];
        GameStore::writeAtomic($f, json_encode($t, JSON_PRETTY_PRINT));
        return $t;
    }
    private static function spread(array $v): ?array {
        $n = count($v); if ($n < self::MIN_RUNS) return null; sort($v);
        return ['n' => $n, 'usual' => round($v[(int) floor(($n - 1) / 2)], 2), 'high' => round($v[max(0, (int) ceil($n * 0.9) - 1)], 2)];
    }
}
