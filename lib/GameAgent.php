<?php
/**
 * GameAgent — one game's Claude Code runs and the pipeline made of them. Runs INSIDE scripts/game-agent.php, a CLI
 * process the web starts detached (GameStore::startRunner): a run is `claude -p <prompt> --output-format stream-json`
 * in the game's folder, its stream parsed into events (GameStore::emit) that the game page polls.
 *
 * Credentials ([forge] agent_login): 'app' takes the runtime's chain for this app (AgentStep::agentEnv: the token set
 * on /agents, then a copied login, then a stored key); 'host' runs on the claude login of the user PHP runs as. Either
 * way nothing of the web request's environment reaches the agent, and no API key is injected by this class.
 *
 * Permissions: --permission-mode acceptEdits with a Bash allowlist (node --check, git, the soak, ls, grep...). The
 * games are static folders, not web apps, so no jail yet; studio/jail.sh is kept for the day one is wanted.
 */
namespace app;

class GameAgent {
    // What an agent may run without asking, by program: checks, git, the soak and the shell's everyday tools, with the
    // pipes and `timeout` prefixes real work is made of. Edits inside the game's folder are accepted by acceptEdits.
    public const ALLOWED = ['Bash(node:*)', 'Bash(git:*)', 'Bash(timeout:*)', 'Bash(../../studio/bin/soak:*)', 'Bash(ls:*)', 'Bash(cat:*)', 'Bash(head:*)', 'Bash(tail:*)', 'Bash(wc:*)',
                           'Bash(grep:*)', 'Bash(find:*)', 'Bash(diff:*)', 'Bash(sed:*)', 'Bash(awk:*)', 'Bash(sort:*)', 'Bash(uniq:*)', 'Bash(cut:*)', 'Bash(tr:*)', 'Bash(echo:*)', 'Bash(printf:*)',
                           'Bash(test:*)', 'Bash([:*)', 'Bash(true)', 'Bash(false)', 'Bash(for:*)', 'Bash(while:*)', 'Bash(if:*)', 'Bash(mkdir:*)', 'Bash(cp:*)', 'Bash(mv:*)', 'Bash(rm:*)', 'Bash(touch:*)',
                           'Bash(python3:*)', 'Bash(date:*)', 'Bash(pwd)', 'Bash(sleep:*)', 'Bash(xargs:*)', 'Bash(jq:*)', 'Bash(curl -s http://127.0.0.1:*)', 'Bash(curl -s https://voidlux.com/games/:*)'];
    public const PLAN = "You are the game designer and planner. Read AGENTS.md, ../../kit/AGENTS.md and GAME.md (the Brief). Write GAME.md's Design section (the fantasy, the core loop, how a run ends, what the player collects or builds, the enemies and how each threatens, the levels or waves, the SFX keys and music theme names the game needs, and what sells it: the hook, the screenshot, the price). Then the Acceptance criteria: 6 to 12 checkable claims, each a line starting '- [ ] '. Then Tasks: 4 to 8 ordered build tasks, each a line starting '- [ ] T<n>: ' naming what to build and ending with '(check: <how the reviewer verifies it>)'. Keep the three criteria already there. Do not write game code in this turn. Keep the game buildable on the kit in plain canvas JavaScript, small enough to finish. Commit GAME.md when done.";
    public const BUILD = "Build task {task} from GAME.md: {text}\nFollow AGENTS.md. Work only on this task. When it works: node --check every js file, run `../../studio/bin/soak 60 4` and fix anything it reports until ok is true, tick the task's box in GAME.md, add a line to GAME.md's Log, and commit with a plain-English message.";
    public const FIX = "The headless soak reported problems. Fix them, re-run `../../studio/bin/soak 60 4` until ok is true, and commit.\nReport:\n{report}";
    public const REVIEW = "You are the reviewer, independent of whoever built this. Read GAME.md's Acceptance criteria and Tasks. For each criterion decide pass or fail by reading the code and by running `../../studio/bin/soak 90 4` (and `node --check`). No vague claims: a pass needs evidence you saw. Change nothing except ticking criteria that pass in GAME.md and appending a Log line 'review: <n> pass, <m> fail'. Finish your reply with one line exactly: VERDICT: {\"pass\": true|false, \"failures\": [\"<criterion>: <why>\", ...]}";
    public const REWORK = "The reviewer failed these acceptance criteria:\n{failures}\nFix them following AGENTS.md: change the code, node --check, run `../../studio/bin/soak 60 4` until ok is true, update GAME.md's Log, commit.";

    private string $slug; private string $dir; private array $state; private int $memberId; private bool $stop = false;

    public function __construct(string $slug, int $memberId = 0) {
        $this->slug = $slug; $this->dir = GameStore::dir($slug); $this->memberId = $memberId; $this->state = GameStore::agentState($slug);
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () { $this->stop = true; });
    }
    private function emit(string $kind, array $d = []): void { GameStore::emit($this->slug, $kind, $d); }
    private function save(): void { GameStore::saveAgentState($this->slug, $this->state); }

    /** [env, binary, note] for a run, or throws naming what to set. */
    public static function environment(string $root): array {
        $mode = (string) GameStore::cfg('agent_login', 'host');
        $env = getenv();
        foreach (array_keys($env) as $k) if (str_starts_with($k, 'CLAUDE') || in_array($k, ['ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN'])) unset($env[$k]);
        $env['PATH'] = '/usr/local/bin:/usr/bin:/bin' . (!empty($env['PATH']) ? ':' . $env['PATH'] : '');
        $home = (string) ($env['HOME'] ?? ''); if ($home === '') { $env['HOME'] = (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '/tmp'); $home = $env['HOME']; }
        $env['PATH'] = $home . '/.local/bin:' . $env['PATH'];
        $bin = is_executable($root . '/bin/claude') ? $root . '/bin/claude' : (\app\ClaudeBinary::hostBinary() ?? ($home . '/.local/bin/claude')); // the app's own agent program first (/agents installs it), else the host's
        if (!is_executable($bin)) throw new \RuntimeException("no claude CLI for the agent (looked for {$bin}); install Claude Code for the user PHP runs as");
        if ($mode === 'app') {
            [$aenv, $err, $note] = \app\Pipeline\Steps\AgentStep::agentEnv('claude', $root);
            if ($err !== '') throw new \RuntimeException($err . ' — sign the app in on /agents, or set [forge] agent_login = host');
            foreach (['CLAUDE_CODE_OAUTH_TOKEN', 'CLAUDE_CONFIG_DIR', 'ANTHROPIC_API_KEY', 'ANTHROPIC_BASE_URL'] as $k) if (isset($aenv[$k])) $env[$k] = $aenv[$k];
            return [$env, $bin, $note];
        }
        return [$env, $bin, 'the host login of ' . (posix_getpwuid(posix_geteuid())['name'] ?? 'php')];
    }

    /** One claude run, blocking. */
    public function run(string $prompt, bool $resume = true, bool $fresh = false, int $maxTurns = 0, string $label = ''): array {
        $result = ['ok' => false, 'text' => '', 'cost' => 0.0, 'turns' => 0];
        try { [$env, $bin, $note] = self::environment(GameStore::root()); } catch (\Throwable $e) { $this->emit('error', ['text' => $e->getMessage()]); return $result; }
        $sid = $fresh ? null : ($this->state['session'] ?? null);
        $jail = (string) GameStore::cfg('jail', '0') === '1' && is_executable('/usr/bin/bwrap');
        $cmd = [$bin, '-p', $prompt, '--output-format', 'stream-json', '--verbose', '--max-turns', (string) ($maxTurns ?: (int) GameStore::cfg('max_turns', 60))];
        // Jailed ([forge] jail = 1): bubblewrap is the boundary (studio/jail.sh: the OS read-only, only this game writable, nothing
        // else of the machine visible), so inside it the agent runs without permission prompts, as the tiknix AI Builder did.
        // Unjailed: edits inside the folder are accepted and Bash is limited to the allowlist above.
        if ($jail) array_push($cmd, '--permission-mode', 'bypassPermissions');
        else { array_push($cmd, '--permission-mode', 'acceptEdits', '--allowedTools'); $cmd = array_merge($cmd, self::ALLOWED); }
        foreach (['kit', 'studio', 'centipede', 'templates'] as $extra) if (is_dir(GameStore::root() . '/' . $extra)) array_push($cmd, '--add-dir', GameStore::root() . '/' . $extra); // the shared engine, the soak tools and the reference game are readable beyond the game's folder
        $model = (string) GameStore::cfg('model', ''); if ($model !== '') array_push($cmd, '--model', $model);
        if ($resume && $sid) array_push($cmd, '--resume', $sid);
        $inbox = $this->dir . '/.studio/inbox.txt';
        if (is_file($inbox) && trim((string) file_get_contents($inbox)) !== '') { $prompt .= "\n\nNotes from the producer since the last run (follow them where they fit this task; keep them in mind otherwise):\n" . trim((string) file_get_contents($inbox)); unlink($inbox); $this->emit('note', ['text' => "the producer's notes go with this run"]); }
        $env['GAME_URL'] = GameStore::soakUrl($this->slug); $env['STUDIO'] = GameStore::root() . '/studio'; $env['VOIDLUX_GAME'] = $this->slug;
        $env['PLAYWRIGHT_ROOT'] = (string) GameStore::cfg('playwright_root', '/home/ubuntu'); $env['CHROME'] = (string) GameStore::cfg('chrome', '/usr/bin/google-chrome'); $env['CDP_URL'] = (string) GameStore::cfg('cdp_url', 'http://127.0.0.1:9222');
        if ($jail) { $env['CLAUDE_BIN'] = $bin; $env['JAIL_HOME_CLAUDE'] = (string) GameStore::cfg('agent_login', 'host') === 'host' ? '1' : '0'; $cmd = array_merge([GameStore::root() . '/studio/jail.sh', $this->dir], $cmd); }
        $this->emit('note', ['text' => ($label !== '' ? "{$label}: " : '') . 'agent run starts' . ($resume && $sid ? ' (resumes ' . substr($sid, 0, 8) . ')' : ' (new session)') . " on {$note}" . ($jail ? ', in the jail' : '')]);
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, $this->dir, $env);
        if (!is_resource($proc)) { $this->emit('error', ['text' => 'cannot start the agent process']); return $result; }
        fclose($pipes[0]); stream_set_blocking($pipes[2], false);
        $last = []; $err = '';
        while (($line = fgets($pipes[1])) !== false) {
            $err .= (string) stream_get_contents($pipes[2]);
            $line = trim($line); if ($line === '') continue;
            $m = json_decode($line, true);
            if (!is_array($m)) { $this->emit('note', ['text' => mb_substr($line, 0, 500)]); continue; }
            $kind = $m['type'] ?? '';
            if ($kind === 'system' && ($m['subtype'] ?? '') === 'init') {
                if (!empty($m['session_id'])) { $this->state['session'] = $m['session_id']; $this->save(); }
                $this->emit('note', ['text' => 'model ' . ($m['model'] ?? '?') . ' · ' . count($m['tools'] ?? []) . ' tools']);
            } elseif ($kind === 'assistant') {
                foreach ($m['message']['content'] ?? [] as $c) {
                    if (($c['type'] ?? '') === 'text' && trim($c['text'] ?? '') !== '') { $last[] = $c['text']; $this->emit('text', ['text' => $c['text']]); }
                    elseif (($c['type'] ?? '') === 'tool_use') $this->emit('tool', ['name' => $c['name'] ?? '', 'input' => $this->brief($c['name'] ?? '', $c['input'] ?? [])]);
                }
            } elseif ($kind === 'user') {
                foreach ($m['message']['content'] ?? [] as $c) if (($c['type'] ?? '') === 'tool_result') {
                    $body = $c['content'] ?? ''; $txt = is_string($body) ? $body : implode(' ', array_map(fn($x) => is_array($x) ? ($x['text'] ?? '') : '', (array) $body));
                    $this->emit('result', ['text' => mb_substr($txt, 0, 1200), 'error' => !empty($c['is_error'])]);
                }
            } elseif ($kind === 'result') {
                $result = array_merge($result, ['ok' => empty($m['is_error']), 'text' => (string) ($m['result'] ?? end($last) ?: ''), 'cost' => (float) ($m['total_cost_usd'] ?? 0), 'turns' => (int) ($m['num_turns'] ?? 0), 'subtype' => $m['subtype'] ?? '']);
                if (!empty($m['session_id'])) $this->state['session'] = $m['session_id'];
            }
            if ($this->stop) { proc_terminate($proc); break; }
        }
        $err .= (string) stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $rc = proc_close($proc);
        if ($this->stop) { $this->emit('note', ['text' => 'run stopped']); $result['stopped'] = true; }
        elseif ($rc !== 0 && !$result['ok']) $this->emit('error', ['text' => "the agent exited {$rc}: " . (trim(substr($err, -800)) ?: 'no message')]);
        $this->state['runs'] = ($this->state['runs'] ?? 0) + 1; $this->state['cost'] = ($this->state['cost'] ?? 0) + $result['cost']; $this->save();
        $this->emit('done', ['ok' => $result['ok'], 'cost' => round($result['cost'], 4), 'turns' => $result['turns'], 'text' => mb_substr((string) $result['text'], 0, 2000), 'label' => $label]);
        return $result;
    }
    private function brief(string $name, array $inp): array {
        if (in_array($name, ['Edit', 'Write', 'Read', 'MultiEdit'])) return ['file' => isset($inp['file_path']) ? str_replace($this->dir . '/', '', (string) $inp['file_path']) : ''];
        if ($name === 'Bash') return ['command' => mb_substr((string) ($inp['command'] ?? ''), 0, 300)];
        if (in_array($name, ['Grep', 'Glob'])) return ['pattern' => $inp['pattern'] ?? '', 'path' => $inp['path'] ?? ''];
        $o = []; foreach (array_slice($inp, 0, 4, true) as $k => $v) $o[$k] = mb_substr(is_scalar($v) ? (string) $v : json_encode($v), 0, 120); return $o;
    }
    private function checkpoint(string $label): void { GameStore::checkpoint($this->slug, $label); }

    // ---- the modes ----
    public function chat(string $prompt): void { $this->run($prompt, true, false, 0, 'chat'); $this->checkpoint('after chat: ' . mb_substr($prompt, 0, 60)); }
    public function soakOnly(int $secs, int $fast): void { GameStore::runSoak($this->slug, $secs, $fast); }
    public function fix(): void {
        $rep = GameStore::runSoak($this->slug, 90, 4);
        if (!empty($rep['ok'])) { $this->emit('stage', ['stage' => 'accepted', 'text' => 'the soak already passes']); return; }
        $this->run(str_replace('{report}', mb_substr(json_encode($this->trim($rep), JSON_PRETTY_PRINT), 0, 6000), self::FIX), true, false, 0, 'fix'); $this->checkpoint('fix');
    }
    private function trim(array $rep): array { $o = []; foreach (['status', 'errors', 'checks', 'browserErrors', 'scenes', 'fps', 'slowFrames'] as $k) if (isset($rep[$k])) $o[$k] = $rep[$k]; return $o; }
    public function reviewOnly(): void {
        $v = $this->review(); $this->checkpoint('review');
        if ($v !== null) $this->emit('stage', ['stage' => !empty($v['pass']) ? 'accepted' : 'rejected', 'text' => 'review: ' . (!empty($v['pass']) ? 'pass' : 'fail'), 'failures' => $v['failures'] ?? []]);
    }
    private function review(): ?array {
        $r = $this->run(self::REVIEW, false, true, 40, 'review');
        if (!$r['ok'] && $r['text'] === '') return null;
        if (!preg_match('/VERDICT:\s*(\{.*\})/s', (string) $r['text'], $m)) { $this->emit('note', ['text' => 'the reviewer gave no VERDICT line; treating it as a fail']); return ['pass' => false, 'failures' => ['the reviewer ended without a verdict']]; }
        $v = json_decode($m[1], true); return is_array($v) ? $v : ['pass' => false, 'failures' => ['unreadable verdict: ' . mb_substr($m[1], 0, 200)]];
    }
    public function pipeline(string $prompt, int $rounds = 2): void {
        $this->emit('stage', ['stage' => 'plan', 'text' => 'planning the game from its brief']);
        if (!GameStore::tasks($this->slug) || $prompt !== '') {
            $r = $this->run(self::PLAN . ($prompt !== '' ? "\nThe producer adds: " . $prompt : ''), true, false, 0, 'plan');
            if ($this->stop || !$r['ok']) return;
            $this->checkpoint('plan');
        }
        $tasks = GameStore::tasks($this->slug);
        if (!$tasks) { $this->emit('error', ['text' => 'the plan has no tasks: GAME.md needs "- [ ] T1: ..." lines under ## Tasks']); return; }
        $this->emit('stage', ['stage' => 'build', 'text' => count($tasks) . ' tasks, ' . count(array_filter($tasks, fn($t) => $t['done'])) . ' done', 'tasks' => $tasks]);
        foreach ($tasks as $t) {
            if ($t['done']) continue; if ($this->stop) return;
            $this->emit('stage', ['stage' => 'task', 'text' => $t['id'] . ': ' . mb_substr($t['text'], 0, 120), 'task' => $t['id']]);
            $r = $this->run(str_replace(['{task}', '{text}'], [$t['id'], $t['text']], self::BUILD), true, false, 0, $t['id']);
            $this->checkpoint($t['id'] . ' built');
            if ($this->stop || !$r['ok']) return;
            $this->emit('stage', ['stage' => 'tasks', 'tasks' => GameStore::tasks($this->slug)]);
        }
        for ($i = 0; $i <= $rounds; $i++) {
            if ($this->stop) return;
            $this->emit('stage', ['stage' => 'soak', 'text' => 'headless soak, 90 game-seconds at 4x']);
            $rep = GameStore::runSoak($this->slug, 90, 4);
            if (!empty($rep['ok'])) break;
            if ($i === $rounds) { $this->emit('error', ['text' => "the soak still fails after {$rounds} fix rounds"]); break; }
            $this->emit('stage', ['stage' => 'fix', 'text' => 'soak failed: asking the agent to fix it (round ' . ($i + 1) . ')']);
            $r = $this->run(str_replace('{report}', mb_substr(json_encode($this->trim($rep), JSON_PRETTY_PRINT), 0, 6000), self::FIX), true, false, 0, 'fix');
            $this->checkpoint('fix round ' . ($i + 1)); if (!$r['ok']) return;
        }
        for ($i = 0; $i <= $rounds; $i++) {
            if ($this->stop) return;
            $this->emit('stage', ['stage' => 'review', 'text' => 'independent review against the acceptance criteria (round ' . ($i + 1) . ')']);
            $v = $this->review(); if ($v === null) return;
            $this->checkpoint('review round ' . ($i + 1));
            if (!empty($v['pass'])) { $this->emit('stage', ['stage' => 'accepted', 'text' => 'the reviewer accepted every criterion']); break; }
            $fails = $v['failures'] ?? []; if (!$fails) $fails = ['the reviewer gave no detail'];
            if ($i === $rounds) { $this->emit('stage', ['stage' => 'rejected', 'text' => "still failing after {$rounds} rework rounds", 'failures' => $fails]); break; }
            $this->emit('stage', ['stage' => 'rework', 'text' => count($fails) . ' criteria failed: reworking', 'failures' => $fails]);
            $r = $this->run(str_replace('{failures}', implode("\n", array_map(fn($f) => '- ' . $f, $fails)), self::REWORK), true, false, 0, 'rework');
            $this->checkpoint('rework round ' . ($i + 1)); if (!$r['ok']) return;
        }
        $this->checkpoint('pipeline done');
        $this->emit('stage', ['stage' => 'done', 'text' => 'pipeline finished']);
    }
}
