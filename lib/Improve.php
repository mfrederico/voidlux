<?php
/**
 * Improve — the rest of the self-improvement loop, after the retrospective (lib/Retro.php) has observed and proposed.
 * The human stands at two gates and nowhere else:
 *
 *   1. DECIDE   a proposal is approved (and built at once), declined with a reason, or left for later. Decisions and
 *               reasons are kept (data/improve/decisions.jsonl) and fed to the retro's agent, so it learns what is wanted.
 *   2. BUILD    (agentic, isolated) an improver agent works on a branch improve/<id> in its own checkout
 *               (data/improve/<id>/wt, a git worktree), inside the jail, and makes the smallest change. Then, deterministic:
 *               every changed file is linted, the change is committed, the diff is kept. Nothing live has changed.
 *   3. ACCEPT   the human reads the diff and merges or rejects. Merge (deterministic): the live forge must be clean,
 *               the branch is fast-forwarded in (rebased first if main moved), the changed files are linted again, the
 *               retro runs dry, the site's front door and sign-in answer 200; any failure resets main to where it was.
 *               Success is pushed to origin.
 *   4. VERIFY   (deterministic) each proposal names the finding rule it targets; a day after merging, the retro compares
 *               how often that rule fired in the day before and the day after: helped, no effect, or worse.
 *
 * The retro's own code is a file like any other, so proposals can improve how the forge observes and proposes.
 */
namespace app;

class Improve {
    public static function dir(): string { $d = Paths::root() . '/data/improve'; if (!is_dir($d)) mkdir($d, 0775, true); return $d; }
    private static function stateFile(string $id): string { return self::dir() . '/' . self::safe($id) . '.json'; }
    public static function safe(string $id): string { return preg_replace('/[^0-9a-z-]/', '-', strtolower($id)); }
    public static function state(string $id): array { $f = self::stateFile($id); return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : []; }
    private static function save(string $id, array $s): void { $s['id'] = $id; $s['updated'] = date('c'); file_put_contents(self::stateFile($id), json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"); self::setLedgerStatus($id, (string) ($s['status'] ?? 'open')); }

    // ---- proposals, wherever they sit in the ledger: id = "<entry>#<index>" ----
    public static function all(int $limit = 200): array {
        $out = [];
        foreach (array_slice(array_reverse(glob(Retro::dir() . '/2*.json') ?: []), 0, $limit) as $f) {
            $e = json_decode((string) file_get_contents($f), true); if (!is_array($e)) continue;
            foreach ((array) ($e['proposals'] ?? []) as $i => $p) { $id = basename($f, '.json') . '#' . $i; $out[$id] = $p + ['id' => $id, 'from' => $e['at'] ?? '', 'improve' => self::state($id)]; }
        }
        return $out;
    }
    public static function find(string $id): array { [$file, $i] = array_pad(explode('#', $id, 2), 2, ''); $f = Retro::dir() . '/' . basename($file) . '.json'; $e = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        $p = $e['proposals'][(int) $i] ?? null; if (!is_array($p) || !preg_match('/^[0-9-]+$/', $file) || !ctype_digit($i)) throw new \InvalidArgumentException("no proposal $id"); return $p + ['id' => $id, 'from' => $e['at'] ?? '']; }
    private static function setLedgerStatus(string $id, string $status): void { [$file, $i] = explode('#', $id, 2); $f = Retro::dir() . '/' . basename($file) . '.json'; $e = json_decode((string) @file_get_contents($f), true); if (!is_array($e) || !isset($e['proposals'][(int) $i])) return; $e['proposals'][(int) $i]['status'] = $status; file_put_contents($f, json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"); }
    /** What waits on a person: open proposals, and built changes to review. */
    public static function inbox(): array { $o = ['decide' => 0, 'review' => 0]; foreach (self::all(96) as $p) { $s = $p['improve']['status'] ?? ($p['status'] ?? 'open'); if ($s === 'open') $o['decide']++; elseif ($s === 'built') $o['review']++; } return $o; }

    // ---- 1. decide ----
    public static function decide(string $id, string $decision, string $note, int $memberId): array {
        if (!in_array($decision, ['approve', 'decline', 'later'], true)) throw new \InvalidArgumentException('approve, decline or later');
        $p = self::find($id); $note = trim(mb_substr($note, 0, 400));
        file_put_contents(self::dir() . '/decisions.jsonl', json_encode(['id' => $id, 'title' => $p['title'], 'decision' => $decision, 'note' => $note, 'by' => GameStore::memberName($memberId), 'at' => date('c')], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
        if ($decision === 'approve') return self::start($id, $memberId, $note);
        $s = self::state($id); self::save($id, $s + ['status' => $decision === 'decline' ? 'declined' : 'later', 'note' => $note]); return self::state($id);
    }
    public static function decisions(int $n): array { $f = self::dir() . '/decisions.jsonl'; if (!is_file($f)) return []; $out = []; foreach (array_slice(file($f), -$n) as $l) { $j = json_decode($l, true); if (is_array($j)) $out[] = $j + ['note' => '']; } return $out; }

    // ---- 2. build: an improver agent on its own branch and checkout, in the jail; then deterministic checks and a commit ----
    public static function start(string $id, int $memberId, string $note = '', bool $stub = false): array {
        $p = self::find($id); $s = self::state($id); if (in_array($s['status'] ?? '', ['building', 'built', 'merged'], true)) throw new \InvalidArgumentException("this proposal is already {$s['status']}");
        $root = Paths::root(); $b = 'improve/' . self::safe($id); $wt = self::dir() . '/' . self::safe($id) . '/wt';
        if (is_dir($wt)) { GameStore::git($root, ['worktree', 'remove', '--force', $wt]); } GameStore::git($root, ['branch', '-D', $b]);
        if (!is_dir(dirname($wt))) mkdir(dirname($wt), 0775, true);
        [$rc, $out] = GameStore::git($root, ['worktree', 'add', '-q', '-b', $b, $wt, 'main']); if ($rc !== 0) throw new \RuntimeException('could not make a checkout for the improver: ' . $out);
        self::save($id, ['status' => 'building', 'branch' => $b, 'wt' => $wt, 'base' => trim(GameStore::git($root, ['rev-parse', 'main'])[1]), 'note' => $note, 'by' => $memberId, 'started' => date('c')]);
        $cmd = 'setsid nohup php ' . escapeshellarg($root . '/scripts/forge-improve.php') . ' ' . escapeshellarg($id) . ($stub ? ' --stub' : '') . ' >> ' . escapeshellarg($root . '/log/improve.log') . ' 2>&1 & echo $!';
        $pid = (int) trim((string) shell_exec($cmd)); if ($pid <= 0) { self::save($id, self::state($id) + ['status' => 'failed', 'error' => 'the improver did not start; see log/improve.log']); throw new \RuntimeException('the improver did not start'); }
        $st = self::state($id); $st['pid'] = $pid; self::save($id, $st); return self::state($id);
    }
    public static function prompt(array $p, string $note): string {
        return "You are the forge's improver. This checkout is the VoidLux forge itself (a tiknix app; its guide is AGENTS.md, imported by CLAUDE.md: read it first). A human approved this proposal from the forge's hourly retrospective; implement exactly it, as the smallest change that does it:\n"
             . "TITLE: {$p['title']}\nFILE: {$p['file']}\nCHANGE: {$p['change']}\nEVIDENCE: {$p['evidence']}\nMEASURE THAT SHOULD MOVE: {$p['measure']}\n" . ($note !== '' ? "THE HUMAN'S NOTE ON APPROVING: {$note}\n" : '')
             . "Rules: follow AGENTS.md (Bean:: not R::, no fallbacks, nothing under vendor/). Touch only what the change needs; no new dependencies; never edit conf/ or anything secret; never start a browser. Keep the code's style (dense lines, plain-English comments saying what and why). If the proposal is wrong or cannot be done safely, change nothing and say why.\n"
             . "When done, check every file you changed: `php -l` for PHP, `node --check` for JavaScript, `bash -n` for shell. Do not commit (the forge commits and a human reviews the diff). End your reply with one line: SUMMARY: <what you changed and why, one sentence>.";
    }
    /** The improver's run (scripts/forge-improve.php calls this, detached). $stub: make a trivial change instead of calling the agent (tests). */
    public static function run(string $id, bool $stub = false): array {
        $p = self::find($id); $s = self::state($id); $wt = (string) ($s['wt'] ?? ''); if (!is_dir($wt)) { self::save($id, $s + ['status' => 'failed', 'error' => 'no checkout']); return self::state($id); }
        $summary = ''; $cost = 0.0;
        if ($stub) { file_put_contents("$wt/IMPROVE-STUB.md", "stub change for {$id}\n"); $summary = 'stub change (test)'; }
        else {
            $root = Paths::root(); [$env, $bin] = GameAgent::environment($root); $env['CLAUDE_BIN'] = $bin; $env['JAIL_HOME_CLAUDE'] = (string) GameStore::cfg('agent_login', 'host') === 'host' ? '1' : '0'; $env['JAIL_CHROME'] = '0';
            $cmd = [$root . '/studio/jail.sh', $wt, $bin, '-p', self::prompt($p, (string) ($s['note'] ?? '')), '--output-format', 'json', '--max-turns', (string) GameStore::cfg('improve_turns', '40'), '--permission-mode', 'bypassPermissions'];
            $model = (string) GameStore::cfg('model', ''); if ($model !== '') array_push($cmd, '--model', $model);
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $wt, $env);
            if (!is_resource($proc)) { self::save($id, $s + ['status' => 'failed', 'error' => 'the agent did not start']); return self::state($id); }
            fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $rc = proc_close($proc);
            $j = json_decode((string) $out, true); $text = (string) ($j['result'] ?? ''); $cost = (float) ($j['total_cost_usd'] ?? 0);
            if (!is_array($j)) { self::save($id, self::state($id) + ['status' => 'failed', 'error' => "the agent answered nothing usable (exit $rc): " . mb_substr(trim($err ?: (string) $out), -600)]); return self::state($id); }
            $summary = preg_match('/^\s*SUMMARY:\s*(.+)$/mi', $text, $m) ? trim($m[1]) : mb_substr(trim($text), -300);
        }
        // deterministic from here: what changed, linted, committed
        GameStore::git($wt, ['add', '-A']); [, $names] = GameStore::git($wt, ['diff', '--cached', '--name-only']); $files = array_values(array_filter(array_map('trim', explode("\n", $names))));
        if (!$files) { self::save($id, self::state($id) + ['status' => 'failed', 'error' => 'the improver changed nothing: ' . $summary, 'cost' => $cost]); return self::state($id); }
        $bad = array_values(array_filter(array_map(fn($f) => self::lint("$wt/$f", $f), $files)));
        if ($bad) { self::save($id, self::state($id) + ['status' => 'failed', 'error' => 'checks failed: ' . implode(' | ', $bad), 'files' => $files, 'summary' => $summary, 'cost' => $cost]); return self::state($id); }
        GameStore::git($wt, ['-c', 'user.email=forge@voidlux.com', '-c', 'user.name=VoidLux Forge', 'commit', '-q', '-m', "Improve: {$p['title']}\n\n{$summary}\n\nProposal {$id} from the hourly retrospective, approved by a person; built by the improver.\n\nCo-Authored-By: Claude <noreply@anthropic.com>"]);
        [, $stat] = GameStore::git($wt, ['show', '--stat', '--format=', 'HEAD']); [, $diff] = GameStore::git($wt, ['show', '--format=', 'HEAD']);
        self::save($id, self::state($id) + ['status' => 'built', 'files' => $files, 'summary' => $summary, 'stat' => trim($stat), 'cost' => $cost, 'built' => date('c')]);
        file_put_contents(self::dir() . '/' . self::safe($id) . '.diff', $diff);
        self::notify($id, 'built: a change is ready for review', "<p>{$p['title']}</p><p>" . htmlspecialchars($summary) . '</p><pre>' . htmlspecialchars(trim($stat)) . '</pre>');
        return self::state($id);
    }
    /** One file's check, deterministic: '' when it passes, else what failed. */
    public static function lint(string $path, string $name): string {
        if (!is_file($path)) return ''; $cmd = null;
        if (str_ends_with($name, '.php')) $cmd = 'php -l ' . escapeshellarg($path); elseif (str_ends_with($name, '.js') || str_ends_with($name, '.mjs')) $cmd = 'node --check ' . escapeshellarg($path); elseif (str_ends_with($name, '.sh') || str_starts_with($name, 'studio/bin/')) $cmd = 'bash -n ' . escapeshellarg($path); elseif (str_ends_with($name, '.json')) return json_decode((string) file_get_contents($path)) === null && trim((string) file_get_contents($path)) !== 'null' ? "$name: not JSON" : '';
        if (!$cmd) return ''; exec($cmd . ' 2>&1', $o, $rc); return $rc === 0 ? '' : "$name: " . mb_substr(trim(implode(' ', $o)), 0, 300);
    }
    public static function diff(string $id): string { $f = self::dir() . '/' . self::safe($id) . '.diff'; return is_file($f) ? (string) file_get_contents($f) : ''; }

    // ---- 3. accept: merge into the live forge, checked, smoked, pushed; or reset on failure ----
    public static function merge(string $id, int $memberId, ?string $repo = null, bool $push = true, ?array $smokeUrls = null): array {
        $s = self::state($id); if (($s['status'] ?? '') !== 'built') throw new \InvalidArgumentException('only a built change can be merged');
        $repo = $repo ?? Paths::root(); $b = (string) $s['branch'];
        [, $dirty] = GameStore::git($repo, ['status', '--porcelain', '--untracked-files=no']); if (trim($dirty) !== '') throw new \RuntimeException('the live forge has uncommitted changes; commit or stash them, then merge');
        [, $cur] = GameStore::git($repo, ['rev-parse', '--abbrev-ref', 'HEAD']); if (trim($cur) !== 'main') throw new \RuntimeException('the live forge is not on main');
        $pre = trim(GameStore::git($repo, ['rev-parse', 'HEAD'])[1]);
        [$rc, $o] = GameStore::git($repo, ['merge', '--ff-only', '-q', $b]);
        if ($rc !== 0) { // main moved since the branch was made: rebase the branch on it, then try again
            [$rr, $ro] = GameStore::git((string) $s['wt'], ['rebase', '-q', 'main']); if ($rr !== 0) { GameStore::git((string) $s['wt'], ['rebase', '--abort']); throw new \RuntimeException('main moved and the change no longer applies cleanly: ' . mb_substr($ro, -300)); }
            [$rc, $o] = GameStore::git($repo, ['merge', '--ff-only', '-q', $b]); if ($rc !== 0) throw new \RuntimeException('could not merge: ' . mb_substr($o, -300)); }
        $fail = array_values(array_filter(array_map(fn($f) => self::lint("$repo/$f", $f), (array) ($s['files'] ?? []))));
        if (!$fail) { sleep(3); exec('cd ' . escapeshellarg($repo) . ' && timeout 120 php scripts/forge-retro.php --dry > /dev/null 2>&1', $x, $rr); if ($rr !== 0) $fail[] = "the retrospective's dry run exited $rr"; }
        if (!$fail) foreach ($smokeUrls ?? [GameStore::baseUrl() . '/', GameStore::baseUrl() . '/auth/login'] as $u) { $code = self::httpCode($u); if ($code !== 200) $fail[] = "$u answered $code"; }
        if ($fail) { GameStore::git($repo, ['reset', '-q', '--hard', $pre]); self::save($id, $s + ['status' => 'failed', 'error' => 'merged, then the smoke failed, so main was put back: ' . implode(' | ', $fail)]); self::notify($id, 'merge rolled back', '<p>' . htmlspecialchars(implode(' | ', $fail)) . '</p>'); return self::state($id); }
        $pushed = false; if ($push) { [$pr, $po] = GameStore::git($repo, ['push', '-q', 'origin', 'main']); $pushed = $pr === 0; }
        self::save($id, $s + ['status' => 'merged', 'merged' => date('c'), 'merged_by' => $memberId, 'sha' => trim(GameStore::git($repo, ['rev-parse', 'HEAD'])[1]), 'pushed' => $pushed]);
        self::cleanup($id, $repo); return self::state($id);
    }
    public static function reject(string $id, string $note, int $memberId): array {
        $s = self::state($id); self::cleanup($id); file_put_contents(self::dir() . '/decisions.jsonl', json_encode(['id' => $id, 'title' => (self::find($id)['title'] ?? ''), 'decision' => 'reject the change', 'note' => trim(mb_substr($note, 0, 400)), 'by' => GameStore::memberName($memberId), 'at' => date('c')], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
        self::save($id, $s + ['status' => 'rejected', 'note' => $note]); return self::state($id);
    }
    private static function cleanup(string $id, ?string $repo = null): void { $s = self::state($id); $repo = $repo ?? Paths::root(); if (!empty($s['wt']) && is_dir($s['wt'])) GameStore::git($repo, ['worktree', 'remove', '--force', $s['wt']]); if (!empty($s['branch'])) GameStore::git($repo, ['branch', '-D', $s['branch']]); }
    private static function httpCode(string $url): int { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_NOBODY => false, CURLOPT_FOLLOWLOCATION => false]); curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return $c; }

    // ---- 4. verify: the targeted finding, a day before and a day after the merge ----
    public static function verifyAll(): void {
        foreach (glob(self::dir() . '/*.json') ?: [] as $f) { $s = json_decode((string) file_get_contents($f), true); if (!is_array($s) || ($s['status'] ?? '') !== 'merged' || !empty($s['verdict']) || empty($s['merged']) || time() - strtotime($s['merged']) < 86400) continue;
            $id = (string) $s['id']; $p = self::find($id); $rule = (string) ($p['rule'] ?? 'other'); $t = strtotime($s['merged']);
            if ($rule === 'other' || $rule === '') { $s['verdict'] = 'not measured (no finding named)'; }
            else { $count = function ($from, $to) use ($rule) { $n = 0; $h = 0; foreach (glob(Retro::dir() . '/2*.json') ?: [] as $e) { $j = json_decode((string) file_get_contents($e), true); $at = strtotime((string) ($j['at'] ?? '')); if ($at <= $from || $at > $to) continue; $h++; foreach ((array) ($j['findings'] ?? []) as $fi) if (($fi['rule'] ?? '') === $rule) $n++; } return [$n, $h]; };
                [$b, $hb] = $count($t - 86400, $t); [$a, $ha] = $count($t, $t + 86400);
                $s['verdict'] = ($hb === 0 || $ha === 0) ? 'not measured (no hours recorded)' : ($a < $b ? "helped: $rule fired $b times the day before, $a the day after" : ($a === $b ? "no effect: $rule fired $b times before and after" : "worse: $rule fired $b times before, $a after")); }
            file_put_contents($f, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"); }
    }
    public static function outcomes(int $n): array { $out = []; foreach (glob(self::dir() . '/*.json') ?: [] as $f) { $s = json_decode((string) file_get_contents($f), true); if (is_array($s) && !empty($s['verdict'])) { try { $out[] = ['title' => self::find((string) $s['id'])['title'], 'verdict' => $s['verdict']]; } catch (\Throwable $e) {} } } return array_slice($out, -$n); }

    private static function notify(string $id, string $what, string $html): void {
        $root = Bean::findOne('member', 'level = ? ORDER BY id ASC', [1]); if (!$root) return;
        try { \app\services\NotifyService::create()->to((string) $root->email, (string) ($root->username ?? ''))->subject('Forge improve: ' . $what)->owner((int) $root->id)->send($html . '<p><a href="' . htmlspecialchars(GameStore::baseUrl() . '/forge/retro#' . rawurlencode($id)) . '">Review it on the Retro page</a></p>'); }
        catch (\Throwable $e) { error_log('ERROR Improve::notify: ' . $e->getMessage()); }
    }
}
