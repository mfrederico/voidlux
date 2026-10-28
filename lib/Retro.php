<?php
/**
 * Retro — the forge asking itself, every hour, how to get better. Two halves, kept apart on purpose:
 *
 *   DETERMINISTIC (this class, PHP, no model): gather the hour from what the forge already records (each game's
 *   .studio/events.jsonl, its sketch jobs, its soaks), compute the measures, compare them with the hour before and
 *   with the seven-day baseline, apply the rules that turn numbers into findings, write the ledger entry
 *   (data/retro/<Y-m-d-H>.json), and send the notice. Everything here is reproducible from the files.
 *
 *   AGENTIC (one short read-only claude run, only when the hour had activity): given the measures, the findings and a
 *   few samples (errors, what producers said, the artist's notes), write the hour's note and at most three proposals,
 *   each naming the file it would change and the evidence. It may read the forge's code; it can change nothing. Its
 *   output is validated here (JSON, files exist) before it is kept.
 *
 * Measures: runs and cost by lane, failures (runs, soaks), art jobs (finished, recovered without a result file,
 * failed, durations), keeps (assets saved), cost per keep, producer messages, corrections (refine and revision jobs),
 * and the time from a take landing to its key being kept. A proposal becomes a change only when a person says so.
 */
namespace app;

class Retro {
    public const DIR = 'data/retro';
    public const RULES = 'the rules that turn measures into findings live in Retro::findings()';

    public static function dir(): string { $d = Paths::root() . '/' . self::DIR; if (!is_dir($d)) mkdir($d, 0775, true); return $d; }

    // ---- gathering: the window's facts from every game's files ----
    public static function gather(int $since, int $until): array {
        $out = ['games' => [], 'events' => [], 'jobs' => []];
        foreach (Bean::find('game') as $g) {
            $slug = (string) $g->slug; $out['games'][] = $slug; $f = GameStore::dir($slug) . '/.studio/events.jsonl';
            if (is_file($f)) { $h = fopen($f, 'r'); while (($line = fgets($h)) !== false) { $e = json_decode($line, true); if (!is_array($e)) continue; $t = (float) ($e['t'] ?? 0); if ($t < $since || $t >= $until) continue; $e['game'] = $slug; $out['events'][] = $e; } fclose($h); }
            try { foreach (GameStore::listSketches($slug)['jobs'] as $j) { if ($j['at'] < $since || $j['at'] >= $until) continue; $j['game'] = $slug; $dir = GameStore::sketchDir($slug, $j['id']); $j['doneAt'] = is_file("$dir/result.json") ? filemtime("$dir/result.json") : (($first = glob("$dir/out-*")) ? max(array_map('filemtime', $first)) : null); $j['recovered'] = $j['result'] && str_contains((string) ($j['result']['note'] ?? ''), 'finished without leaving a note'); $out['jobs'][] = $j; } } catch (\Throwable $e) { error_log('ERROR retro gather ' . $slug . ': ' . $e->getMessage()); }
        }
        return $out;
    }

    // ---- measures: numbers, the same way every time ----
    public static function measures(array $g, int $since, int $until): array {
        $m = ['window' => ['since' => $since, 'until' => $until, 'hours' => round(($until - $since) / 3600, 2)], 'runs' => ['build' => 0, 'art' => 0], 'cost' => ['build' => 0.0, 'art' => 0.0, 'total' => 0.0], 'turns' => 0,
              'failures' => ['runs' => 0, 'errors' => 0, 'soaks' => 0, 'review_fails' => 0], 'soaks' => 0, 'soaks_unrun' => 0, 'soak_errors' => [], 'wasted' => 0.0, 'notes' => ['queued' => 0, 'answered' => 0, 'unanswered' => 0], 'keeps' => 0, 'producer_msgs' => 0, 'inbox_msgs' => 0,
              'art' => ['jobs' => 0, 'finished' => 0, 'recovered' => 0, 'failed' => 0, 'pending' => 0, 'saved_only' => 0, 'by_kind' => [], 'secs' => []], 'corrections' => 0, 'time_to_keep' => [], 'games_active' => []];
        $keepsByKey = [];
        foreach ($g['events'] as $e) {
            $k = $e['kind'] ?? ''; $m['games_active'][$e['game']] = true;
            if ($k === 'done') { $lane = ($e['label'] ?? '') === 'art' ? 'art' : 'build'; $m['runs'][$lane]++; $m['cost'][$lane] += (float) ($e['cost'] ?? 0); $m['turns'] += (int) ($e['turns'] ?? 0); if (empty($e['ok'])) $m['failures']['runs']++; }
            elseif ($k === 'error') { $m['failures']['errors']++; $m['wasted'] += (float) ($e['wasted'] ?? 0); }
            elseif ($k === 'soak') { if (($e['status'] ?? '') === 'no-browser') { $m['soaks_unrun']++; continue; } // never ran: not a fault in the game
              $m['soaks']++; if (empty($e['ok'])) { $m['failures']['soaks']++; $first = ''; foreach ((array) ($e['errors'] ?? []) as $er) { $first = strtok((string) (is_array($er) ? ($er['text'] ?? '') : $er), "\n"); if ($first !== '') break; } if ($first === '' && !empty($e['checks'])) $first = 'check: ' . (string) (((array) $e['checks'])[0]['what'] ?? ''); if ($first === '') $first = 'status ' . ($e['status'] ?? '?') . ' with no error text';
                $key = $e['game'] . ': ' . mb_substr($first, 0, 160); $m['soak_errors'][$key] = ['n' => ($m['soak_errors'][$key]['n'] ?? 0) + 1, 'runner' => (string) ($e['runner'] ?? 'server')]; } }
            elseif ($k === 'notes') { $m['notes']['answered'] += (int) ($e['answered'] ?? 0); $m['notes']['unanswered'] += count((array) ($e['missing'] ?? [])); }
            elseif ($k === 'stage' && !empty($e['failures'])) $m['failures']['review_fails'] += count((array) $e['failures']);
            elseif ($k === 'prompt') { if (($e['mode'] ?? '') === 'inbox') { $m['inbox_msgs']++; $m['notes']['queued']++; } elseif (($e['mode'] ?? '') !== 'art') $m['producer_msgs']++; }
            elseif ($k === 'note' && preg_match('/^sprite saved by .*: assets\/([^\s]+)/', (string) ($e['text'] ?? ''), $mm)) { $m['keeps']++; $key = preg_replace('/\.(rig\.)?[a-z0-9]+$/', '', $mm[1]); $keepsByKey[$key][] = (float) $e['t']; }
        }
        $m['cost']['total'] = round($m['cost']['build'] + $m['cost']['art'], 4); $m['cost']['build'] = round($m['cost']['build'], 4); $m['cost']['art'] = round($m['cost']['art'], 4);
        foreach ($g['jobs'] as $j) {
            $m['art']['jobs']++; $m['art']['by_kind'][$j['kind']] = ($m['art']['by_kind'][$j['kind']] ?? 0) + 1; $m['games_active'][$j['game']] = true;
            if ($j['saved']) { $m['art']['saved_only']++; continue; }
            if (!$j['result']) { $m['art']['pending']++; continue; }
            if (!empty($j['result']['ok'])) { $m['art']['finished']++; if ($j['recovered']) $m['art']['recovered']++; } else $m['art']['failed']++;
            if ($j['doneAt']) $m['art']['secs'][] = max(0, $j['doneAt'] - $j['at']);
            if (in_array($j['kind'], ['refine', 'revision'], true)) $m['corrections']++;
            if ($j['doneAt'] && !empty($keepsByKey[$j['key']])) { $after = array_filter($keepsByKey[$j['key']], fn($t) => $t >= $j['doneAt']); if ($after) $m['time_to_keep'][] = (int) (min($after) - $j['doneAt']); }
        }
        $med = fn(array $a) => $a ? (function ($a) { sort($a); $n = count($a); return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2; })($a) : null;
        $m['art']['median_secs'] = $med($m['art']['secs']); $m['art']['max_secs'] = $m['art']['secs'] ? max($m['art']['secs']) : null; unset($m['art']['secs']);
        $m['time_to_keep_median'] = $med($m['time_to_keep']); $m['time_to_keep_n'] = count($m['time_to_keep']); unset($m['time_to_keep']);
        $m['wasted'] = round($m['wasted'], 4); arsort($m['soak_errors']); $m['soak_errors'] = array_slice($m['soak_errors'], 0, 5, true);
        $m['cost_per_keep'] = $m['keeps'] ? round($m['cost']['total'] / $m['keeps'], 3) : null;
        $m['corrections_per_job'] = $m['art']['jobs'] ? round($m['corrections'] / $m['art']['jobs'], 2) : null;
        $m['games_active'] = array_keys($m['games_active']);
        return $m;
    }

    // ---- rules: a finding is a measure crossing a line, with its evidence; no judgement needed to see it ----
    public static function findings(array $m, array $base, array $g): array {
        $f = []; $hours = max(0.1, (float) $m['window']['hours']); $bh = max(1, (float) ($base['window']['hours'] ?? 1));
        $perHourBase = fn($k) => ($base['cost']['total'] ?? 0) / $bh;
        if ($m['art']['recovered'] > 0) $f[] = ['rule' => 'artist-skipped-result', 'severity' => 'medium', 'text' => "the artist finished {$m['art']['recovered']} job(s) without writing result.json; the recovery step covered it, but the instruction is not landing", 'evidence' => array_values(array_map(fn($j) => $j['game'] . '/' . $j['id'], array_filter($g['jobs'], fn($j) => $j['recovered'])))];
        if ($m['art']['failed'] > 0) $f[] = ['rule' => 'art-jobs-failed', 'severity' => 'high', 'text' => "{$m['art']['failed']} art job(s) failed", 'evidence' => array_values(array_map(fn($j) => $j['game'] . '/' . $j['id'] . ': ' . mb_substr((string) ($j['result']['error'] ?? ''), 0, 160), array_filter($g['jobs'], fn($j) => $j['result'] && empty($j['result']['ok']) && !$j['saved'])))];
        if ($m['failures']['runs'] > 0 || $m['failures']['errors'] > 0) $f[] = ['rule' => 'runs-failed', 'severity' => 'high', 'text' => "{$m['failures']['runs']} run(s) ended badly, {$m['failures']['errors']} error event(s)", 'evidence' => array_slice(array_values(array_map(fn($e) => $e['game'] . ': ' . mb_substr((string) ($e['text'] ?? ''), 0, 160), array_filter($g['events'], fn($e) => ($e['kind'] ?? '') === 'error'))), 0, 6)];
        if ($m['failures']['soaks'] > 0) $f[] = ['rule' => 'soaks-failed', 'severity' => 'medium', 'text' => "{$m['failures']['soaks']} of {$m['soaks']} playtest(s) failed" . ($m['soaks_unrun'] ? " ({$m['soaks_unrun']} more never ran: no browser attached)" : ''), 'evidence' => array_map(fn($k, $v) => "{$v['n']}× {$k} [{$v['runner']}]", array_keys($m['soak_errors']), $m['soak_errors'])];
        elseif ($m['soaks_unrun'] > 2) $f[] = ['rule' => 'soaks-unrun', 'severity' => 'low', 'text' => "{$m['soaks_unrun']} playtest(s) never ran because no browser was attached: the builder's checks went unverified", 'evidence' => []];
        if ($m['wasted'] > 0.5) $f[] = ['rule' => 'wasted-spend', 'severity' => 'medium', 'text' => sprintf('$%.2f went on runs that died without a result', $m['wasted']), 'evidence' => array_slice(array_values(array_map(fn($e) => $e['game'] . ': ' . mb_substr((string) ($e['text'] ?? ''), 0, 200), array_filter($g['events'], fn($e) => ($e['kind'] ?? '') === 'error' && !empty($e['wasted'])))), 0, 4)];
        if ($m['notes']['unanswered'] > 0) $f[] = ['rule' => 'notes-unanswered', 'severity' => 'medium', 'text' => "{$m['notes']['unanswered']} queued producer note(s) were left unanswered by the run that got them", 'evidence' => array_slice(array_merge(...array_map(fn($e) => (array) ($e['missing'] ?? []), array_filter($g['events'], fn($e) => ($e['kind'] ?? '') === 'notes'))), 0, 5)];
        if ($m['cost']['total'] > 1 && $perHourBase('total') > 0 && $m['cost']['total'] / $hours > 2 * $perHourBase('total')) $f[] = ['rule' => 'cost-spike', 'severity' => 'medium', 'text' => sprintf('spend %.2f/h against a baseline of %.2f/h', $m['cost']['total'] / $hours, $perHourBase('total')), 'evidence' => []];
        if ($m['art']['max_secs'] !== null && $m['art']['max_secs'] > 300) $f[] = ['rule' => 'slow-art-job', 'severity' => 'low', 'text' => "the slowest art job took {$m['art']['max_secs']} s (median " . ($m['art']['median_secs'] ?? '?') . ' s)', 'evidence' => array_values(array_map(fn($j) => $j['game'] . '/' . $j['id'] . ' ' . $j['kind'] . ' ' . ($j['doneAt'] - $j['at']) . 's', array_filter($g['jobs'], fn($j) => $j['doneAt'] && $j['doneAt'] - $j['at'] > 300)))];
        if ($m['corrections_per_job'] !== null && $m['corrections_per_job'] >= 0.5 && $m['art']['jobs'] >= 4) $f[] = ['rule' => 'many-corrections', 'severity' => 'medium', 'text' => "{$m['corrections']} of {$m['art']['jobs']} art jobs were refinements or revisions: first takes are missing the mark", 'evidence' => array_slice(array_values(array_map(fn($j) => $j['game'] . '/' . $j['key'] . ': ' . mb_substr((string) $j['note'], 0, 120), array_filter($g['jobs'], fn($j) => in_array($j['kind'], ['refine', 'revision'], true)))), 0, 6)];
        if ($m['time_to_keep_median'] !== null && $m['time_to_keep_median'] > 600) $f[] = ['rule' => 'slow-to-keep', 'severity' => 'low', 'text' => 'a take waited a median ' . round($m['time_to_keep_median'] / 60) . ' min before its key was kept: a stall between result and keep', 'evidence' => []];
        if ($m['art']['finished'] >= 3 && $m['keeps'] === 0) $f[] = ['rule' => 'nothing-kept', 'severity' => 'medium', 'text' => "{$m['art']['finished']} takes landed and none was kept this hour", 'evidence' => []];
        if ($m['inbox_msgs'] > 0) $f[] = ['rule' => 'messages-mid-run', 'severity' => 'low', 'text' => "{$m['inbox_msgs']} message(s) arrived while the builder was busy and rode along with its next run", 'evidence' => []];
        if (!$m['runs']['build'] && !$m['runs']['art'] && !$m['art']['jobs'] && !$m['producer_msgs']) $f[] = ['rule' => 'quiet', 'severity' => 'none', 'text' => 'nothing happened in this window', 'evidence' => []];
        return $f;
    }

    // ---- the brief the agent gets: measures, findings, and a few samples; nothing it has to go looking for ----
    public static function brief(array $m, array $prev, array $base, array $f, array $g, ?array $last): array {
        $errors = array_slice(array_values(array_map(fn($e) => $e['game'] . ': ' . mb_substr((string) ($e['text'] ?? ''), 0, 300), array_filter($g['events'], fn($e) => ($e['kind'] ?? '') === 'error'))), 0, 8);
        $said = array_slice(array_values(array_map(fn($e) => $e['game'] . ' (' . ($e['mode'] ?? '') . '): ' . mb_substr((string) ($e['text'] ?? ''), 0, 240), array_filter($g['events'], fn($e) => ($e['kind'] ?? '') === 'prompt'))), 0, 12);
        $notes = array_slice(array_values(array_map(fn($j) => $j['game'] . '/' . $j['kind'] . ' ' . $j['key'] . ': ' . mb_substr((string) ($j['result']['note'] ?? ''), 0, 200), array_filter($g['jobs'], fn($j) => $j['result']))), 0, 10);
        $dones = array_slice(array_values(array_map(fn($e) => $e['game'] . ' ' . ($e['label'] ?? '') . ' ' . (empty($e['ok']) ? 'FAILED' : 'ok') . ' $' . round((float) ($e['cost'] ?? 0), 2) . ': ' . mb_substr(str_replace("\n", ' ', (string) ($e['text'] ?? '')), 0, 200), array_filter($g['events'], fn($e) => ($e['kind'] ?? '') === 'done'))), 0, 10);
        return ['measures' => $m, 'previous_hour' => $prev ? ['cost' => $prev['cost'], 'runs' => $prev['runs'], 'art' => ['jobs' => $prev['art']['jobs'], 'finished' => $prev['art']['finished'], 'failed' => $prev['art']['failed']], 'keeps' => $prev['keeps']] : null,
                'baseline_7d_per_hour' => $base ? ['cost' => round(($base['cost']['total'] ?? 0) / max(1, $base['window']['hours']), 3), 'art_jobs' => round(($base['art']['jobs'] ?? 0) / max(1, $base['window']['hours']), 2), 'keeps' => round(($base['keeps'] ?? 0) / max(1, $base['window']['hours']), 2), 'recovered_rate' => ($base['art']['finished'] ?? 0) ? round(($base['art']['recovered'] ?? 0) / $base['art']['finished'], 2) : null] : null,
                'findings' => $f, 'samples' => ['errors' => $errors, 'producers_said' => $said, 'artist_notes' => $notes, 'runs' => $dones], 'last_proposals' => $last ? array_map(fn($p) => ['title' => $p['title'] ?? '', 'status' => $p['status'] ?? 'open'], (array) ($last['proposals'] ?? [])) : []];
    }

    public static function agentPrompt(array $brief): string {
        return "Rules of this forge that proposals must respect: never launch Chrome (headless or not) on the server; playtests run in a producer's open browser, and 'no-browser' means none was attached, not that the game failed. Builders and artists run in a bubblewrap jail. Proposals are applied by a person, never automatically.\n"
             . "You are the forge's hourly retrospective. The forge is this app (a tiknix app at " . Paths::root() . "): producers describe arcade games, an AI builder builds them (lib/GameAgent.php, prompts there and in kit/AGENTS.md and the games' AGENTS.md), and an AI artist makes sprites, rigs and motions from sketches (prompts in lib/GameStore.php startSketch; the kit in kit/js; the sprite page in kit/editor/sprites). You may READ any file here to ground a proposal; you change nothing.\n"
             . "The hour's measures, how they compare, the rule-based findings and a few samples are in this JSON:\n" . json_encode($brief, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
             . "Write the hour's note (120 words at most, plain, what happened and what it cost, no praise) and at most THREE proposals, only ones the evidence supports: each the smallest change that fixes a class of problem, naming the exact file and the change, its kind (prompt | guide | harness | ux | kit | pipeline), its evidence from the brief, and what measure should move if it works. Prefer deterministic fixes (a check, a contract, a default) over longer prompts. If nothing is worth changing, propose nothing and say why in the note. Do not repeat a last proposal still marked open unless new evidence makes it urgent.\n"
             . "Answer with ONLY this JSON, no prose around it: {\"note\": \"...\", \"proposals\": [{\"title\": \"...\", \"kind\": \"...\", \"file\": \"relative/path\", \"change\": \"...\", \"evidence\": \"...\", \"measure\": \"...\"}]}";
    }

    /** The one agentic step: a short, read-only run; its JSON validated before it is believed. */
    public static function ask(array $brief): array {
        $root = Paths::root(); [$env, $bin, $note] = GameAgent::environment($root);
        $model = (string) GameStore::cfg('retro_model', 'claude-sonnet-5-5');
        $cmd = [$bin, '-p', self::agentPrompt($brief), '--output-format', 'json', '--max-turns', '12', '--permission-mode', 'default', '--allowedTools', 'Read', 'Grep', 'Glob', '--model', $model];
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]; $proc = proc_open($cmd, $desc, $pipes, $root, $env);
        if (!is_resource($proc)) return ['ok' => false, 'error' => 'the retro agent did not start'];
        fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $rc = proc_close($proc);
        $j = json_decode((string) $out, true); if (!is_array($j)) return ['ok' => false, 'error' => "the retro agent answered no JSON (exit {$rc}): " . mb_substr(trim($err ?: $out), -400)];
        $text = (string) ($j['result'] ?? ''); $cost = (float) ($j['total_cost_usd'] ?? 0);
        if (!preg_match('/\{.*\}/s', $text, $mm)) return ['ok' => false, 'error' => 'the retro agent wrote no JSON object', 'cost' => $cost, 'raw' => mb_substr($text, 0, 600)];
        $a = json_decode($mm[0], true); if (!is_array($a)) return ['ok' => false, 'error' => 'the retro agent\'s JSON does not parse', 'cost' => $cost, 'raw' => mb_substr($text, 0, 600)];
        $props = [];
        foreach (array_slice((array) ($a['proposals'] ?? []), 0, 3) as $p) { if (!is_array($p)) continue; $file = trim((string) ($p['file'] ?? '')); $props[] = ['title' => mb_substr((string) ($p['title'] ?? ''), 0, 120), 'kind' => preg_replace('/[^a-z]/', '', (string) ($p['kind'] ?? '')), 'file' => $file, 'file_exists' => $file !== '' && is_file($root . '/' . ltrim($file, '/')), 'change' => mb_substr((string) ($p['change'] ?? ''), 0, 1200), 'evidence' => mb_substr((string) ($p['evidence'] ?? ''), 0, 600), 'measure' => mb_substr((string) ($p['measure'] ?? ''), 0, 200), 'status' => 'open']; }
        return ['ok' => true, 'note' => mb_substr((string) ($a['note'] ?? ''), 0, 1500), 'proposals' => $props, 'cost' => $cost, 'model' => $model, 'login' => $note];
    }

    // ---- the ledger ----
    public static function entries(int $limit = 48): array { $out = []; foreach (array_slice(array_reverse(glob(self::dir() . '/*.json') ?: []), 0, $limit) as $f) { $j = json_decode((string) file_get_contents($f), true); if (is_array($j)) { $j['file'] = basename($f); $out[] = $j; } } return $out; }
    public static function last(): ?array { $e = self::entries(1); return $e[0] ?? null; }

    /** One hour's retrospective: measured and ruled deterministically, narrated by the agent only if there was activity. */
    public static function hour(?int $until = null, bool $withAgent = true, bool $notify = true): array {
        $until = $until ?? (int) (floor(time() / 3600) * 3600); $since = $until - 3600;
        $g = self::gather($since, $until); $m = self::measures($g, $since, $until);
        $gp = self::gather($since - 3600, $since); $prev = self::measures($gp, $since - 3600, $since);
        $gb = self::gather($until - 7 * 86400, $until); $base = self::measures($gb, $until - 7 * 86400, $until);
        $f = self::findings($m, $base, $g); $last = self::last();
        $entry = ['at' => date('c', $until), 'kind' => 'hour', 'measures' => $m, 'previous' => ['cost' => $prev['cost'], 'runs' => $prev['runs'], 'keeps' => $prev['keeps'], 'art_jobs' => $prev['art']['jobs']], 'findings' => $f, 'note' => '', 'proposals' => [], 'agent' => null];
        $quiet = count($f) === 1 && $f[0]['rule'] === 'quiet';
        if ($withAgent && !$quiet && (string) GameStore::cfg('retro_agent', '1') === '1') { $a = self::ask(self::brief($m, $prev, $base, $f, $g, $last)); $entry['agent'] = ['ok' => $a['ok'], 'cost' => $a['cost'] ?? 0, 'error' => $a['error'] ?? null, 'model' => $a['model'] ?? null]; if ($a['ok']) { $entry['note'] = $a['note']; $entry['proposals'] = $a['proposals']; } }
        elseif ($quiet) $entry['note'] = 'A quiet hour: no runs, no art jobs, no messages.';
        file_put_contents(self::dir() . '/' . date('Y-m-d-H', $until) . '.json', json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        if ($notify && !$quiet) self::notify($entry);
        return $entry;
    }

    /** The week rolled up: measures summed, proposals listed with their status, so the hourly notes add up to something. */
    public static function week(?int $until = null, bool $notify = true): array {
        $until = $until ?? (int) (floor(time() / 3600) * 3600); $since = $until - 7 * 86400;
        $g = self::gather($since, $until); $m = self::measures($g, $since, $until); $f = self::findings($m, $m, $g);
        $props = []; foreach (self::entries(200) as $e) if (($e['kind'] ?? '') === 'hour' && strtotime($e['at']) >= $since) foreach ((array) $e['proposals'] as $p) $props[] = $p + ['from' => $e['at']];
        $entry = ['at' => date('c', $until), 'kind' => 'week', 'measures' => $m, 'findings' => $f, 'note' => sprintf('The week: %d build runs and %d art runs for $%.2f; %d art jobs (%d finished, %d recovered without a result file, %d failed); %d assets kept (%s per keep); %d producer messages; %d proposals made.', $m['runs']['build'], $m['runs']['art'], $m['cost']['total'], $m['art']['jobs'], $m['art']['finished'], $m['art']['recovered'], $m['art']['failed'], $m['keeps'], $m['cost_per_keep'] === null ? 'n/a' : '$' . $m['cost_per_keep'], $m['producer_msgs'], count($props)), 'proposals' => $props, 'agent' => null];
        file_put_contents(self::dir() . '/week-' . date('Y-\WW', $until) . '.json', json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        if ($notify) self::notify($entry);
        return $entry;
    }

    /** The notice: to the forge's root member, as a Communications thread about the forge; by email when mail is configured. */
    public static function notify(array $e): void {
        $root = Bean::findOne('member', 'level = ? ORDER BY id ASC', [1]); if (!$root) return;
        $m = $e['measures']; $url = GameStore::baseUrl() . '/forge/retro';
        $h = '<p><b>' . ($e['kind'] === 'week' ? 'The week' : 'The hour to ' . date('H:00', strtotime($e['at']))) . '</b>: ' . $m['runs']['build'] . ' build + ' . $m['runs']['art'] . ' art runs, $' . number_format($m['cost']['total'], 2) . ' · ' . $m['art']['jobs'] . ' art jobs · ' . $m['keeps'] . ' kept' . ($m['cost_per_keep'] !== null ? ' ($' . $m['cost_per_keep'] . ' per keep)' : '') . '</p>';
        if ($e['note'] !== '') $h .= '<p>' . nl2br(htmlspecialchars($e['note'])) . '</p>';
        $fs = array_filter((array) $e['findings'], fn($f) => $f['rule'] !== 'quiet'); if ($fs) { $h .= '<p><b>Findings</b></p><ul>'; foreach ($fs as $f) $h .= '<li>' . htmlspecialchars($f['text']) . '</li>'; $h .= '</ul>'; }
        if (!empty($e['proposals'])) { $h .= '<p><b>Proposals</b> (nothing changes until you say so)</p><ol>'; foreach ($e['proposals'] as $p) $h .= '<li><b>' . htmlspecialchars($p['title']) . '</b> <i>' . htmlspecialchars($p['kind']) . ' · ' . htmlspecialchars($p['file']) . (empty($p['file_exists']) && !empty($p['file']) ? ' (file not found)' : '') . '</i><br>' . htmlspecialchars($p['change']) . '<br><small>evidence: ' . htmlspecialchars($p['evidence']) . ' · measure: ' . htmlspecialchars($p['measure']) . '</small></li>'; $h .= '</ol>'; }
        $h .= '<p><a href="' . htmlspecialchars($url) . '">The ledger</a></p>';
        try { \app\services\NotifyService::create()->to((string) $root->email, (string) ($root->username ?? ''))->subject('Forge retro: ' . ($e['kind'] === 'week' ? 'the week' : date('D H:00', strtotime($e['at']))) . ' — ' . count($fs) . ' finding(s), ' . count((array) $e['proposals']) . ' proposal(s)')->owner((int) $root->id)->send($h); }
        catch (\Throwable $ex) { error_log('ERROR Retro::notify: ' . $ex->getMessage()); }
    }
}
