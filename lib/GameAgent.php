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
    public const ALLOWED = ['Bash(node:*)', 'Bash(git:*)', 'Bash(timeout:*)', 'Bash(../../studio/bin/soak:*)', 'Bash(../../studio/bin/shot:*)', 'Bash(ls:*)', 'Bash(cat:*)', 'Bash(head:*)', 'Bash(tail:*)', 'Bash(wc:*)',
                           'Bash(grep:*)', 'Bash(find:*)', 'Bash(diff:*)', 'Bash(sed:*)', 'Bash(awk:*)', 'Bash(sort:*)', 'Bash(uniq:*)', 'Bash(cut:*)', 'Bash(tr:*)', 'Bash(echo:*)', 'Bash(printf:*)',
                           'Bash(test:*)', 'Bash([:*)', 'Bash(true)', 'Bash(false)', 'Bash(for:*)', 'Bash(while:*)', 'Bash(if:*)', 'Bash(mkdir:*)', 'Bash(cp:*)', 'Bash(mv:*)', 'Bash(rm:*)', 'Bash(touch:*)',
                           'Bash(python3:*)', 'Bash(date:*)', 'Bash(pwd)', 'Bash(sleep:*)', 'Bash(xargs:*)', 'Bash(jq:*)', 'Bash(curl -s http://127.0.0.1:*)', 'Bash(curl -s https://voidlux.com/games/:*)'];
    public const PLAN = "You are the game designer and planner. Read AGENTS.md, ../../kit/AGENTS.md and GAME.md (the Brief). Write GAME.md's Design section (the fantasy, the core loop, how a run ends, what the player collects or builds, the enemies and how each threatens, the levels or waves, the SFX keys and music theme names the game needs, and what sells it: the hook, the screenshot, the price). Then the Acceptance criteria: 6 to 12 checkable claims, each a line starting '- [ ] '. Then Tasks: 4 to 8 ordered build tasks, each a line starting '- [ ] T<n>: ' naming what to build and ending with '(check: <how the reviewer verifies it>)'. Keep the three criteria already there. Do not write game code in this turn. Keep the game buildable on the kit in plain canvas JavaScript, small enough to finish. If GAME.md opens with a Fork goal, this game is a fork of a finished game: keep its Design and update it only where the goal changes things, and plan the tasks that reach the goal (the first one makes its save and settings keys its own). Commit GAME.md when done.";
    public const BUILD = "Build task {task} from GAME.md: {text}\nFollow AGENTS.md. Work only on this task. When it works: node --check every js file; if the autopilot's run would go through what you built, run `../../studio/bin/soak 60 4` and fix anything it reports until ok is true, otherwise say what you checked instead; tick the task's box in GAME.md, add a line to GAME.md's Log, and commit with a plain-English message.";
    // Standing rules every run gets through --append-system-prompt, chat runs included (a chat prompt is the producer's own words).
    public const STANDING = "Forge rules: never delete, loosen or skip a soak check, an assertion or a test to make a run pass. A check that fails before your change is reported as failing, in your reply and your commit message, and fixed only when that is your task; \"ok\" means every check that was there passed. never start a browser (Chrome, Chromium, Playwright, Puppeteer): none is available and the server must not run one. To see the game, ../../studio/bin/shot [seconds] [query] takes a screenshot in a producer's open browser and prints the PNG's path to Read; with no browser attached it says no-browser, and then you say the visual check is still to do and carry on. The soak (../../studio/bin/soak) is the autopilot playing the game; run it only when its run would go through what you changed (gameplay, scene flow, load order). For a screen the bot never reaches, docs, tuning, copy or comments, skip it and say in one line what you checked instead. It costs the producer a minute each time.";
    public const FIX = "The headless soak reported problems. Fix them, re-run `../../studio/bin/soak 60 4` until ok is true, and commit.\nReport:\n{report}";
    public const REVIEW = "You are the reviewer, independent of whoever built this. Read GAME.md's Acceptance criteria and Tasks. For each criterion decide pass or fail by reading the code and `node --check`; run `../../studio/bin/soak 90 4` once, only if some criterion is about play the autopilot reaches (it proves nothing about screens the bot never visits). No vague claims: a pass needs evidence you saw. Change nothing except ticking criteria that pass in GAME.md and appending a Log line 'review: <n> pass, <m> fail'. Finish your reply with one line exactly: VERDICT: {\"pass\": true|false, \"failures\": [\"<criterion>: <why>\", ...]}";
    public const REWORK = "The reviewer failed these acceptance criteria:\n{failures}\nFix them following AGENTS.md: change the code, node --check, run `../../studio/bin/soak 60 4` until ok is true when the autopilot's run goes through the fix (otherwise say what you checked instead), update GAME.md's Log, commit.";

    private string $slug; private string $dir; private array $state; private int $memberId; private bool $stop = false;

    private array $pendingNotes = []; // the queued notes this run was given, to check its answer against
    private string $lane = 'build'; // 'build': the builder (its session, its inbox, its checkpoints); 'art': a sprite job beside it (fresh session, no inbox, no commits)
    private int $slot = 0; // an art runner's slot in the lane (GameStore::startArt)
    public function __construct(string $slug, int $memberId = 0, string $lane = 'build', int $slot = 0) {
        $this->slug = $slug; $this->dir = GameStore::dir($slug); $this->memberId = $memberId; $this->lane = $lane === 'art' ? 'art' : 'build'; $this->slot = $slot;
        $this->state = $this->lane === 'art' ? GameStore::artState($slug, $slot) : GameStore::agentState($slug);
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () { $this->stop = true; });
    }
    private function emit(string $kind, array $d = []): void { GameStore::emit($this->slug, $kind, $d); }
    private function save(): void { if ($this->lane === 'art') GameStore::saveArtState($this->slug, $this->state, $this->slot); else GameStore::saveAgentState($this->slug, $this->state); }
    /** A sprite job: one fresh, self-contained run, nothing committed. */
    public function art(string $prompt): array { return $this->run($prompt, false, true, 0, 'art'); }

    /** [env, binary, note] for a run, or throws naming what to set. */
    public static function environment(string $root): array {
        $mode = (string) GameStore::cfg('agent_login', 'host');
        $env = getenv();
        foreach (array_keys($env) as $k) if (str_starts_with($k, 'CLAUDE') || in_array($k, ['ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN'])) unset($env[$k]);
        $env['PATH'] = '/usr/local/bin:/usr/bin:/bin' . (!empty($env['PATH']) ? ':' . $env['PATH'] : '');
        $home = (string) ($env['HOME'] ?? ''); if ($home === '') { $env['HOME'] = (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '/tmp'); $home = $env['HOME']; }
        $env['PATH'] = $home . '/.local/bin:' . $env['PATH'];
        // [forge] node_bin: the folder of the node the agents and their plugins' MCP servers run on (mojulo wants 22.14+, the
        // system node here is 20). Set and missing is a fault to fix in conf/config.ini, never a quiet fall back to the system node.
        $nodeBin = rtrim((string) GameStore::cfg('node_bin', ''), '/');
        if ($nodeBin !== '') { if (!is_executable($nodeBin . '/node')) throw new \RuntimeException("[forge] node_bin = {$nodeBin} has no node program; fix conf/config.ini"); $env['PATH'] = $nodeBin . ':' . $env['PATH']; }
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
    public function run(string $prompt, bool $resume = true, bool $fresh = false, int $maxTurns = 0, string $label = '', bool $retried = false): array {
        $result = ['ok' => false, 'text' => '', 'cost' => 0.0, 'turns' => 0];
        try { [$env, $bin, $note] = self::environment(GameStore::root()); } catch (\Throwable $e) { $this->emit('error', ['text' => $e->getMessage()]); return $result; }
        $sid = $fresh ? null : ($this->state['session'] ?? null);
        // A long conversation makes every turn expensive (each one re-reads all of it): once the builder's session is over
        // [forge] session_max_tokens (default 150000), the next run starts a fresh session and gets a handoff instead, built
        // from the game itself (GameStore::handoff: tasks, recent log, commits, the last runs' answers, the last playtest).
        $max = (int) GameStore::cfg('session_max_tokens', 150000);
        if ($resume && $sid && $this->lane === 'build' && $max > 0 && (int) ($this->state['context'] ?? 0) > $max) {
            $this->emit('note', ['text' => 'the builder\'s conversation is ' . round(((int) $this->state['context']) / 1000) . 'k tokens (over ' . round($max / 1000) . "k): this run starts a fresh session with a handoff from the game's own record"]);
            $prompt = GameStore::handoff($this->slug) . "\n\n---\nTHE TASK:\n" . $prompt; $sid = null; $resume = false; $this->state['session'] = null; $this->state['context'] = 0; $this->save();
        }
        if ($this->lane === 'build') { // what the other lanes and the producer did since this builder's last run
            $news = GameStore::activitySince($this->slug, (int) ($this->state['seenAt'] ?? (time() - 86400)), 'builder', 25);
            if ($news) $prompt = "SINCE YOUR LAST RUN (the forge's activity log; take it into account, the files are the truth):\n" . GameStore::activityLines($news) . "\n\n---\n" . $prompt;
            $this->state['seenAt'] = time(); $this->save();
        }
        $jail = (string) GameStore::cfg('jail', '0') === '1' && is_executable('/usr/bin/bwrap');
        $cmd = [$bin, '-p', $prompt, '--output-format', 'stream-json', '--verbose', '--max-turns', (string) ($maxTurns ?: (int) GameStore::cfg('max_turns', 60))];
        // Jailed ([forge] jail = 1): bubblewrap is the boundary (studio/jail.sh: the OS read-only, only this game writable, nothing
        // else of the machine visible), so inside it the agent runs without permission prompts, as the tiknix AI Builder did.
        // Unjailed: edits inside the folder are accepted and Bash is limited to the allowlist above.
        array_push($cmd, '--append-system-prompt', self::STANDING);
        if ($jail) array_push($cmd, '--permission-mode', 'bypassPermissions');
        else { array_push($cmd, '--permission-mode', 'acceptEdits', '--allowedTools'); $cmd = array_merge($cmd, self::ALLOWED); }
        foreach (['kit', 'studio', 'centipede', 'templates'] as $extra) if (is_dir(GameStore::root() . '/' . $extra)) array_push($cmd, '--add-dir', GameStore::root() . '/' . $extra); // the shared engine, the soak tools and the reference game are readable beyond the game's folder
        $model = (string) GameStore::cfg('model', ''); if ($model !== '') array_push($cmd, '--model', $model);
        if ($resume && $sid) array_push($cmd, '--resume', $sid);
        $notes = $this->lane !== 'art' ? GameStore::takeNotes($this->slug) : [];
        if ($notes) { // numbered, and each must be answered: the run's reply ends with a NOTES line, checked after the run
            $prompt .= "\n\nNotes from the producer that arrived while you were busy. Each needs an answer: do it now if it fits this run, or say it is planned for later, or decline it and say why.\n" . implode("\n", array_map(fn($n, $i) => ($i + 1) . '. ' . $n['text'], $notes, array_keys($notes)))
                     . "\nEnd your reply with one line exactly like: NOTES: 1 built; 2 planned (when); 3 declined (why) — one entry for every number above.";
            $this->pendingNotes = $notes; $this->emit('note', ['text' => count($notes) . " producer note(s) go with this run, numbered; the reply must answer each"]); }
        $env['GAME_URL'] = GameStore::soakUrl($this->slug); $env['STUDIO'] = GameStore::root() . '/studio'; $env['VOIDLUX_GAME'] = $this->slug;
        $env['PLAYWRIGHT_ROOT'] = (string) GameStore::cfg('playwright_root', '/home/ubuntu'); $env['CHROME'] = (string) GameStore::cfg('chrome', '/usr/bin/google-chrome'); $env['CDP_URL'] = (string) GameStore::cfg('cdp_url', 'http://127.0.0.1:9222');
        if ($jail) { $env['JAIL_CHROME'] = (string) GameStore::cfg('server_chrome', '0') === '1' ? '1' : '0'; $env['CLAUDE_BIN'] = $bin; $env['JAIL_HOME_CLAUDE'] = (string) GameStore::cfg('agent_login', 'host') === 'host' ? '1' : '0'; $cmd = array_merge([GameStore::root() . '/studio/jail.sh', $this->dir], $cmd); }
        $this->emit('note', ['text' => ($label !== '' ? "{$label}: " : '') . 'agent run starts' . ($resume && $sid ? ' (resumes ' . substr($sid, 0, 8) . ')' : ' (new session)') . " on {$note}" . ($jail ? ', in the jail' : '')]);
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $cmd[array_search('-p', $cmd, true) + 1] = $prompt; // the prompt as it now is: the queued notes were added above, after $cmd was built
        $proc = proc_open($cmd, $desc, $pipes, $this->dir, $env);
        if (!is_resource($proc)) { $this->emit('error', ['text' => 'cannot start the agent process']); return $result; }
        fclose($pipes[0]); stream_set_blocking($pipes[2], false);
        $last = []; $err = ''; $tail = [];
        while (($line = fgets($pipes[1])) !== false) {
            $err .= (string) stream_get_contents($pipes[2]);
            $line = trim($line); if ($line === '') continue; $tail[] = mb_substr($line, 0, 400); if (count($tail) > 6) array_shift($tail);
            $m = json_decode($line, true);
            if (!is_array($m)) { $this->emit('note', ['text' => mb_substr($line, 0, 500)]); continue; }
            $kind = $m['type'] ?? '';
            if ($kind === 'system' && ($m['subtype'] ?? '') === 'init') {
                if (!empty($m['session_id'])) { $this->state['session'] = $m['session_id']; $this->save(); }
                $this->emit('note', ['text' => 'model ' . ($m['model'] ?? '?') . ' · ' . count($m['tools'] ?? []) . ' tools']);
            } elseif ($kind === 'assistant') {
                $u = $m['message']['usage'] ?? null; if (is_array($u)) $this->state['context'] = (int) ($u['input_tokens'] ?? 0) + (int) ($u['cache_read_input_tokens'] ?? 0) + (int) ($u['cache_creation_input_tokens'] ?? 0) + (int) ($u['output_tokens'] ?? 0); // how big the conversation is now
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
                if ($this->lane === 'build') { $this->save(); $this->emit('note', ['text' => 'conversation size now ' . round(((int) ($this->state['context'] ?? 0)) / 1000) . 'k tokens']); }
            }
            if ($this->stop) { proc_terminate($proc); break; }
        }
        $err .= (string) stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $rc = proc_close($proc);
        if ($this->stop) { $this->emit('note', ['text' => 'run stopped']); $result['stopped'] = true; }
        elseif ($rc !== 0 && !$result['ok'] && $resume && $sid && !$retried && stripos($err, 'No conversation found') !== false) {
            // the stored session lives in another login's state (the agent moved from the host login to the app's own, or
            // the jail's own HOME): forget it and run again, fresh, once
            $this->emit('note', ['text' => 'the saved session is not in this login\'s store; starting a fresh one']);
            $this->state['session'] = null; $this->save();
            return $this->run($prompt, false, true, $maxTurns, $label, true);
        }
        elseif ($rc !== 0 && !$result['ok']) { // say why: stderr's tail, else the last things the agent wrote, and what the failed run cost
            $why = trim(substr($err, -1500)); $lastOut = implode("\n", array_slice($tail, -3));
            $this->emit('error', ['text' => "the agent exited {$rc}: " . ($why !== '' ? $why : ($lastOut !== '' ? "nothing on stderr; its last output was:\n{$lastOut}" : 'no output at all (the process died before writing anything)')), 'exit' => $rc, 'wasted' => round((float) $result['cost'], 4), 'subtype' => $result['subtype'] ?? null]); }
        if ($this->pendingNotes && !$this->stop) { // every queued note answered, or the gap said out loud
            $txt = (string) $result['text']; $ans = []; if (preg_match('/^\s*NOTES:\s*(.+)$/mi', $txt, $nm)) foreach (preg_split('/;\s*/', $nm[1]) as $part) if (preg_match('/^\s*(\d+)\s+(built|planned|declined|done)\b(.*)$/i', $part, $pm)) $ans[(int) $pm[1]] = strtolower($pm[2]) . trim($pm[3]);
            $missing = []; foreach ($this->pendingNotes as $i => $n) if (!isset($ans[$i + 1])) $missing[] = ($i + 1) . '. ' . mb_substr($n['text'], 0, 120);
            $this->emit('notes', ['answered' => count($ans), 'total' => count($this->pendingNotes), 'missing' => $missing, 'answers' => $ans, 'text' => $missing ? 'not answered: ' . implode(' | ', $missing) : 'every queued note answered']);
            if ($missing) foreach ($this->pendingNotes as $i => $n) if (!isset($ans[$i + 1])) GameStore::queueNote($this->slug, $n['text'], $n['tries'] + 1); // back in the queue, one try spent
            $this->pendingNotes = []; }
        $this->state['runs'] = ($this->state['runs'] ?? 0) + 1; $this->state['cost'] = ($this->state['cost'] ?? 0) + $result['cost']; $this->save();
        if (!$this->stop) { $first = trim((string) $result['text']); $first = mb_substr(preg_replace('/\s+/', ' ', preg_replace('/[*`#]/', '', $first)), 0, 300);
            GameStore::activity($this->slug, $this->lane === 'art' ? 'artist' : 'builder', ($label !== '' && $label !== 'art' ? "{$label}: " : '') . ($result['ok'] ? '' : 'FAILED: ') . ($first !== '' ? $first : '(no answer)')); }
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
        if (($rep['status'] ?? '') === 'no-browser') { $this->emit('stage', ['stage' => 'rejected', 'text' => 'nothing to fix yet: no browser is attached to run the playtest. Open the game page and try again.']); return; }
        $this->run(str_replace('{report}', mb_substr(json_encode($this->trim($rep), JSON_PRETTY_PRINT), 0, 6000), self::FIX), true, false, 0, 'fix'); $this->checkpoint('fix');
    }
    private function trim(array $rep): array { $o = []; foreach (['status', 'errors', 'checks', 'browserErrors', 'scenes', 'fps', 'slowFrames'] as $k) if (isset($rep[$k])) $o[$k] = $rep[$k]; return $o; }
    public function reviewOnly(): void {
        $v = $this->review(); $this->checkpoint('review'); if ($v !== null) $this->recordReview($v);
        if ($v !== null) $this->emit('stage', ['stage' => !empty($v['pass']) ? 'accepted' : 'rejected', 'text' => 'review: ' . (!empty($v['pass']) ? 'pass' : 'fail'), 'failures' => $v['failures'] ?? []]);
    }
    /** Which version was reviewed and how it came out: the commit after the review's own checkpoint (its ticks and log line), so "unchanged since" means no commit since. */
    private function recordReview(array $v): void {
        [, $sha] = GameStore::git($this->dir, ['rev-parse', '--short', 'HEAD']); $s = GameStore::agentState($this->slug);
        $s['lastReview'] = ['sha' => trim($sha), 'pass' => !empty($v['pass']), 'failures' => count((array) ($v['failures'] ?? [])), 'at' => date('c')]; GameStore::saveAgentState($this->slug, $s); $this->state['lastReview'] = $s['lastReview'];
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
            if (($rep['status'] ?? '') === 'no-browser') { $this->emit('stage', ['stage' => 'soak', 'text' => 'no browser attached to playtest in: skipping the playtest gate (open the game page in the forge to run one)']); break; }
            if ($i === $rounds) { $this->emit('error', ['text' => "the soak still fails after {$rounds} fix rounds"]); break; }
            $this->emit('stage', ['stage' => 'fix', 'text' => 'soak failed: asking the agent to fix it (round ' . ($i + 1) . ')']);
            $r = $this->run(str_replace('{report}', mb_substr(json_encode($this->trim($rep), JSON_PRETTY_PRINT), 0, 6000), self::FIX), true, false, 0, 'fix');
            $this->checkpoint('fix round ' . ($i + 1)); if (!$r['ok']) return;
        }
        for ($i = 0; $i <= $rounds; $i++) {
            if ($this->stop) return;
            $this->emit('stage', ['stage' => 'review', 'text' => 'independent review against the acceptance criteria (round ' . ($i + 1) . ')']);
            $v = $this->review(); if ($v === null) return;
            $this->checkpoint('review round ' . ($i + 1)); $this->recordReview($v);
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
