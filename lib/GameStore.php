<?php
/**
 * GameStore — the games on disk and their rows: creation from a template, checkpoints (git tags), soaks, the
 * editors' files (sfx, tuning, music), events, agent state and publishing.
 *
 * A game is games/<slug>/, its own git repository, running on the shared kit (kit/). Its row (`game`) holds who owns it
 * (member_id, optional team_id) and the store card; everything else is read from the folder, so the folder is the
 * truth and the row is the index. .studio/ inside a game (gitignored) holds the agent's session, its events and the
 * soak reports.
 *
 * Config ([forge] in conf/config.ini):
 *   domain        games publish to https://<slug>.<domain>/ (capricorn serves /var/www/html/default/<slug>.<domain minus tld>/public/)
 *   publish_root  that folder's parent, default /var/www/html/default
 *   agent_login   'host' (the claude login of the user PHP runs as) or 'app' (the token set on /agents, AgentStep::agentEnv)
 *   model, max_turns  for agent runs
 *   chrome, playwright_root  for the headless soak
 */
namespace app;

use \app\Bean;
use \Flight as Flight;

class GameStore {
    public const SLUG = '/^[a-z][a-z0-9-]{1,40}$/';
    public const MARKER = '// ---- tracker songs below';

    public static function root(): string { return \app\Paths::root(); }
    public static function gamesDir(): string { return self::root() . '/games'; }
    public static function dir(string $slug): string {
        if (!preg_match(self::SLUG, $slug)) throw new \InvalidArgumentException('bad game slug');
        $d = self::gamesDir() . '/' . $slug;
        if (!is_dir($d)) throw new \RuntimeException("no game folder for {$slug}");
        return $d;
    }
    public static function cfg(string $key, $default = null) { $v = Flight::get('forge.' . $key); return ($v === null || $v === '') ? $default : $v; }
    public static function baseUrl(): string { return rtrim((string) Flight::get('app.baseurl'), '/'); }

    // ---- git ----
    public static function git(string $dir, array $args, bool $check = false): array {
        $cmd = 'git -C ' . escapeshellarg($dir) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $out, $rc);
        $text = implode("\n", $out);
        if ($check && $rc !== 0) throw new \RuntimeException('git ' . ($args[0] ?? '') . ': ' . substr($text, -400));
        return [$rc, $text];
    }

    public static function writeAtomic(string $path, string $text): void {
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $text) === false) throw new \RuntimeException("cannot write {$path}");
        rename($tmp, $path);
    }

    // ---- rows ----
    public static function row(string $slug): ?object {
        $g = Bean::findOne('game', 'slug = ?', [$slug]);
        return ($g && $g->id) ? $g : null;
    }
    /** The games a member may open: their own, and their teams'. */
    public static function forMember(int $memberId, bool $all = false): array {
        if ($all) return array_values(Bean::findAll('game', ' ORDER BY title ASC'));
        $teamIds = \app\Teammates::teamIds($memberId);
        $where = 'member_id = ?'; $args = [$memberId];
        if ($teamIds) { $where .= ' OR team_id IN (' . implode(',', array_fill(0, count($teamIds), '?')) . ')'; $args = array_merge($args, array_values($teamIds)); }
        return array_values(Bean::find('game', $where . ' ORDER BY title ASC', $args));
    }
    public static function canOpen(object $g, int $memberId, int $level): bool {
        if ($level <= LEVELS['ADMIN']) return true;
        if ((int) $g->memberId === $memberId) return true;
        if ((int) $g->teamId > 0) { $t = Bean::load('team', (int) $g->teamId); if ($t->id && $t->box()->hasMember($memberId)) return true; }
        return false;
    }

    // ---- create ----
    public static function create(string $slug, string $title, string $brief, string $template, int $memberId, int $teamId = 0): object {
        if (!preg_match(self::SLUG, $slug)) throw new \InvalidArgumentException('a slug is lowercase letters, digits and dashes, 2 to 41 characters');
        if (!preg_match('/^[a-z][a-z0-9-]{1,40}$/', $template)) throw new \InvalidArgumentException('bad template');
        $src = self::root() . '/templates/' . $template; $dst = self::gamesDir() . '/' . $slug;
        if (!is_dir($src)) throw new \InvalidArgumentException("no template {$template}");
        if (file_exists($dst) || self::row($slug)) throw new \InvalidArgumentException("a game named {$slug} exists");
        if (!is_dir(self::gamesDir())) mkdir(self::gamesDir(), 0775, true);
        $title = trim($title) !== '' ? mb_substr(trim($title), 0, 60) : $slug;
        $brief = trim($brief) !== '' ? mb_substr(trim($brief), 0, 4000) : 'A small game on the VoidLux kit.';
        self::copyTree($src, $dst);
        $subs = ['__SLUG__' => $slug, '__TITLE__' => $title, '__DATE__' => date('Y-m-d'), '__BRIEF__' => $brief];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dst, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $p = $f->getPathname();
            if (!preg_match('/\.(html|js|md|json|webmanifest)$/', $p)) continue;
            $s = file_get_contents($p); $t = $s;
            foreach ($subs as $k => $v) $t = str_replace($k, str_ends_with($p, '.json') ? substr(json_encode($v), 1, -1) : $v, $t);
            if ($t !== $s) self::writeAtomic($p, $t);
        }
        self::writeAtomic($dst . '/CLAUDE.md', "# Guidance for agents\n\nThis game's guidance is **AGENTS.md** (and the kit's, ../../kit/AGENTS.md). Claude Code reads it through the import below.\n\n@AGENTS.md\n");
        $m = json_decode((string) file_get_contents($dst . '/game.json'), true) ?: [];
        $m = array_merge($m, ['slug' => $slug, 'title' => $title, 'template' => $template, 'created' => $subs['__DATE__'], 'status' => 'new']);
        self::writeAtomic($dst . '/game.json', json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        self::git($dst, ['init', '-q', '-b', 'main'], true);
        self::git($dst, ['config', 'user.email', 'forge@voidlux.com']); self::git($dst, ['config', 'user.name', 'VoidLux Forge']);
        self::git($dst, ['add', '-A'], true);
        self::git($dst, ['commit', '-q', '-m', "Birth: {$title} from the {$template} template"], true);
        self::git($dst, ['tag', '-a', 'cp-0', '-m', 'birth']);
        $g = Bean::dispense('game');
        $g->slug = $slug; $g->title = $title; $g->template = $template; $g->brief = $brief; $g->status = 'new'; $g->price = null; $g->tags = '[]'; $g->notes = '';
        $g->memberId = $memberId; $g->teamId = $teamId > 0 ? $teamId : null; $g->createdAt = date('c'); $g->publishedAt = null; $g->publishedUrl = '';
        Bean::store($g);
        self::emit($slug, 'note', ['text' => "born from the {$template} template"]);
        return $g;
    }
    private static function copyTree(string $src, string $dst): void {
        mkdir($dst, 0775, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $f) {
            $rel = substr($f->getPathname(), strlen($src) + 1);
            if ($f->isDir()) mkdir($dst . '/' . $rel, 0775, true); else copy($f->getPathname(), $dst . '/' . $rel);
        }
    }

    // ---- the game as the pages see it ----
    public static function meta(object $g): array {
        $slug = (string) $g->slug; $d = self::dir($slug);
        $soaks = self::soaks($slug);
        [, $log] = self::git($d, ['log', '-1', '--format=%cI %s']);
        $tasks = self::tasks($slug);
        return [
            'id' => (int) $g->id, 'slug' => $slug, 'title' => (string) $g->title, 'template' => (string) $g->template, 'brief' => (string) $g->brief,
            'status' => (string) $g->status, 'price' => $g->price === null ? null : (float) $g->price, 'tags' => json_decode((string) $g->tags, true) ?: [], 'notes' => (string) $g->notes,
            'memberId' => (int) $g->memberId, 'teamId' => (int) $g->teamId, 'createdAt' => (string) $g->createdAt, 'publishedAt' => (string) $g->publishedAt, 'publishedUrl' => (string) $g->publishedUrl,
            'lastSoak' => $soaks[0] ?? null, 'checkpoints' => count(self::checkpoints($slug)), 'lastCommit' => mb_substr(trim($log), 0, 140),
            'tasks' => ['done' => count(array_filter($tasks, fn($t) => $t['done'])), 'total' => count($tasks)], 'taskList' => $tasks,
            'agent' => self::agentState($slug), 'playUrl' => '/games/' . $slug . '/',
        ];
    }
    public static function tasks(string $slug): array {
        $p = self::dir($slug) . '/GAME.md'; if (!is_file($p)) return [];
        $tasks = [];
        foreach (preg_split('/^## /m', (string) file_get_contents($p)) as $sec) {
            if (!str_starts_with($sec, 'Tasks')) continue;
            if (preg_match_all('/^- \[( |x)\] (T\d+)?:?\s*(.+)$/m', $sec, $mm, PREG_SET_ORDER)) foreach ($mm as $m) $tasks[] = ['done' => $m[1] === 'x', 'id' => $m[2] !== '' ? $m[2] : 'T' . (count($tasks) + 1), 'text' => trim($m[3])];
        }
        return $tasks;
    }

    // ---- checkpoints ----
    public static function checkpoint(string $slug, string $label): string {
        $d = self::dir($slug);
        self::git($d, ['add', '-A']);
        [$rc] = self::git($d, ['diff', '--cached', '--quiet']);
        if ($rc !== 0) self::git($d, ['commit', '-q', '-m', mb_substr($label !== '' ? $label : 'checkpoint', 0, 200)]);
        $n = count(self::checkpoints($slug));
        while (self::git($d, ['rev-parse', '-q', '--verify', 'refs/tags/cp-' . $n])[0] === 0) $n++;
        $tag = 'cp-' . $n;
        self::git($d, ['tag', '-a', $tag, '-m', mb_substr($label !== '' ? $label : 'checkpoint', 0, 200)]);
        self::emit($slug, 'checkpoint', ['tag' => $tag, 'label' => $label]);
        return $tag;
    }
    public static function checkpoints(string $slug): array {
        [, $txt] = self::git(self::dir($slug), ['tag', '-l', 'cp-*', '--sort=-creatordate', '--format=%(refname:short)%09%(creatordate:iso8601)%09%(subject)']);
        $out = [];
        foreach (array_filter(explode("\n", $txt)) as $line) { $p = explode("\t", $line); $out[] = ['tag' => $p[0], 'at' => $p[1] ?? '', 'label' => $p[2] ?? '']; }
        return $out;
    }
    public static function rollback(string $slug, string $ref): array {
        if (!preg_match('/^[A-Za-z0-9._\/-]{1,80}$/', $ref)) throw new \InvalidArgumentException('bad ref');
        if (self::agentState($slug)['busy']) throw new \RuntimeException('stop the agent first');
        self::checkpoint($slug, 'before rolling back to ' . $ref);
        $d = self::dir($slug);
        self::git($d, ['reset', '-q', '--hard', $ref], true); self::git($d, ['clean', '-qfd']);
        self::emit($slug, 'note', ['text' => 'rolled back to ' . $ref]);
        return self::checkpoints($slug);
    }

    // ---- events and agent state (.studio/) ----
    public static function studioDir(string $slug): string { $d = self::dir($slug) . '/.studio'; if (!is_dir($d)) mkdir($d, 0775, true); return $d; }
    public static function emit(string $slug, string $kind, array $data = []): array {
        $ev = array_merge(['kind' => $kind, 't' => round(microtime(true), 2)], $data);
        $f = self::studioDir($slug) . '/events.jsonl';
        $fh = fopen($f, 'a'); if ($fh) { flock($fh, LOCK_EX); fwrite($fh, json_encode($ev, JSON_UNESCAPED_SLASHES) . "\n"); flock($fh, LOCK_UN); fclose($fh); }
        return $ev;
    }
    /** Events after line number $since (1-based ids are line numbers), at most $max of the newest. */
    public static function events(string $slug, int $since = 0, int $max = 400): array {
        $f = self::dir($slug) . '/.studio/events.jsonl'; if (!is_file($f)) return ['events' => [], 'last' => 0];
        $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $n = count($lines); $out = [];
        for ($i = max($since, $n - $max); $i < $n; $i++) { $e = json_decode($lines[$i], true); if (is_array($e)) { $e['id'] = $i + 1; $out[] = $e; } }
        return ['events' => $out, 'last' => $n];
    }
    public static function agentState(string $slug): array {
        $f = self::dir($slug) . '/.studio/agent.json';
        $s = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        $s += ['busy' => false, 'mode' => '', 'pid' => 0, 'session' => null, 'runs' => 0, 'cost' => 0.0];
        if ($s['busy'] && $s['pid'] > 0 && !self::alive((int) $s['pid'])) { $s['busy'] = false; $s['mode'] = ''; self::saveAgentState($slug, $s); } // a runner that died takes its lock with it
        return $s;
    }
    public static function saveAgentState(string $slug, array $s): void { self::writeAtomic(self::studioDir($slug) . '/agent.json', json_encode($s, JSON_PRETTY_PRINT)); }
    public static function alive(int $pid): bool { return $pid > 0 && file_exists('/proc/' . $pid); }

    /** Start the agent runner (scripts/game-agent.php) detached, in its own process group, so a Stop can take it and its claude down. */
    public static function startRunner(string $slug, string $mode, string $prompt, int $memberId): array {
        $s = self::agentState($slug);
        if ($s['busy']) throw new \RuntimeException('an agent run is already going for this game');
        $sd = self::studioDir($slug);
        file_put_contents($sd . '/prompt.txt', $prompt);
        $log = $sd . '/runner.log';
        $cmd = 'setsid nohup php ' . escapeshellarg(self::root() . '/scripts/game-agent.php') . ' ' . escapeshellarg($slug) . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg((string) $memberId) . ' >> ' . escapeshellarg($log) . ' 2>&1 & echo $!';
        $pid = (int) trim((string) shell_exec($cmd));
        if ($pid <= 0) throw new \RuntimeException('the agent runner did not start; see ' . $log);
        $s = array_merge($s, ['busy' => true, 'mode' => $mode, 'pid' => $pid, 'started' => date('c'), 'by' => $memberId]);
        self::saveAgentState($slug, $s);
        return $s;
    }
    public static function stopRunner(string $slug): array {
        $s = self::agentState($slug);
        $pid = (int) $s['pid'];
        if ($pid > 0 && self::alive($pid)) { // the runner and its claude share a process group (setsid): TERM the group, wait, then KILL what is left
            exec('kill -TERM -- -' . $pid . ' 2>/dev/null; kill -TERM ' . $pid . ' 2>/dev/null');
            for ($i = 0; $i < 40 && self::alive($pid); $i++) usleep(250000);
            if (self::alive($pid)) { exec('kill -KILL -- -' . $pid . ' 2>/dev/null; kill -KILL ' . $pid . ' 2>/dev/null'); usleep(300000); }
        }
        $s['busy'] = false; $s['mode'] = ''; self::saveAgentState($slug, $s);
        self::emit($slug, 'note', ['text' => 'stopped by the producer']);
        return $s;
    }

    // ---- soaks ----
    public static function soaks(string $slug): array {
        $d = self::dir($slug) . '/.studio/soaks'; if (!is_dir($d)) return [];
        $files = array_filter(scandir($d, SCANDIR_SORT_DESCENDING), fn($f) => str_ends_with($f, '.json'));
        $out = [];
        foreach (array_slice($files, 0, 30) as $fn) {
            $j = json_decode((string) file_get_contents($d . '/' . $fn), true); if (!is_array($j)) continue;
            $be = array_filter($j['browserErrors'] ?? [], fn($e) => ($e['kind'] ?? '') !== 'network');
            $out[] = ['runner' => $j['runner'] ?? 'server', 'id' => substr($fn, 0, -5), 'ok' => (bool) ($j['ok'] ?? false), 'status' => $j['status'] ?? '', 'seconds' => $j['seconds'] ?? null, 'fps' => $j['fps'] ?? null, 'errors' => count($j['errors'] ?? []) + count($be), 'score' => $j['score'] ?? null, 'scenes' => $j['scenes'] ?? [], 'shot' => is_file($d . '/' . substr($fn, 0, -5) . '.png') || is_file($d . '/' . substr($fn, 0, -5) . '.jpg')];
        }
        return $out;
    }
    public static function soak(string $slug): array { return json_decode((string) file_get_contents(self::dir($slug) . '/.studio/soaks/' . basename($slug) . '.json'), true) ?: []; }
    public static function soakUrl(string $slug): string { return (string) self::cfg('soak_url', self::baseUrl()) . '/games/' . $slug . '/'; }
    // ---- a soak in a viewer's own browser: the game page, while open, is a test machine. A request is a file in .studio/
    // (the jailed agent can write it); the page claims it on its next poll, plays the game in a hidden frame, posts the report ----
    public static function browserSeen(string $slug): void { @touch(self::studioDir($slug) . '/browser-seen'); }
    public static function browserAttached(string $slug): bool { $f = self::dir($slug) . '/.studio/browser-seen'; return is_file($f) && time() - filemtime($f) < (int) self::cfg('browser_soak_window', 20); }
    public static function soakRequest(string $slug): ?array { $f = self::dir($slug) . '/.studio/soak-request.json'; if (!is_file($f)) return null; $r = json_decode((string) file_get_contents($f), true); return is_array($r) ? $r : null; }
    public static function requestSoak(string $slug, int $secs, int $fast): string {
        $id = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 3);
        self::writeAtomic(self::studioDir($slug) . '/soak-request.json', json_encode(['id' => $id, 'secs' => $secs, 'fast' => $fast, 't' => time(), 'claimed' => 0]));
        return $id;
    }
    /** The page says it is running this request (the first viewer to claim it runs it). */
    public static function claimSoak(string $slug, string $id): bool {
        $r = self::soakRequest($slug); if (!$r || $r['id'] !== $id || !empty($r['claimed'])) return false;
        $r['claimed'] = time(); self::writeAtomic(self::dir($slug) . '/.studio/soak-request.json', json_encode($r)); return true;
    }
    /** The page's report for a request: stored like a Chrome soak, the request cleared, the event emitted. */
    public static function finishBrowserSoak(string $slug, string $id, array $rep): array {
        $r = self::soakRequest($slug); if (!$r || $r['id'] !== $id) throw new \RuntimeException('no such soak request');
        $sd = self::studioDir($slug) . '/soaks'; if (!is_dir($sd)) mkdir($sd, 0775, true);
        if (preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', (string) ($rep['shot'] ?? ''), $m)) file_put_contents($sd . '/' . $id . '.jpg', base64_decode($m[1]));
        unset($rep['shot']); $rep['id'] = $id; $rep['secs'] = (int) $r['secs']; $rep['fast'] = (int) $r['fast']; $rep['runner'] = 'browser'; $rep['status'] = $rep['status'] ?? 'ok';
        $rep['ok'] = empty($rep['errors']) && empty($rep['checks']) && ($rep['status'] === 'ok');
        self::writeAtomic($sd . '/' . $id . '.json', json_encode($rep, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @unlink(self::dir($slug) . '/.studio/soak-request.json');
        self::emit($slug, 'soak', ['id' => $id, 'ok' => $rep['ok'], 'status' => $rep['status'], 'runner' => 'browser', 'errors' => array_slice($rep['errors'] ?? [], 0, 10), 'browserErrors' => [], 'checks' => $rep['checks'] ?? [], 'fps' => $rep['fps'] ?? null, 'slowFrames' => $rep['slowFrames'] ?? null, 'scenes' => $rep['scenes'] ?? [], 'score' => $rep['score'] ?? null, 'seconds' => $rep['seconds'] ?? null]);
        return $rep;
    }
    /** Ask an attached browser and wait for its report; null when nobody picked it up in time. */
    public static function browserSoak(string $slug, int $secs, int $fast): ?array {
        $id = self::requestSoak($slug, $secs, $fast); $f = self::dir($slug) . '/.studio/soaks/' . $id . '.json';
        self::emit($slug, 'note', ['text' => "soak: {$secs} game-seconds at {$fast}x, in a viewer's browser"]);
        $deadline = time() + (int) ($secs / max(1, $fast) * 2.5) + 60; $claimDeadline = time() + 25;
        while (time() < $deadline) {
            if (is_file($f)) return json_decode((string) file_get_contents($f), true) ?: null;
            $r = self::soakRequest($slug); if (!$r) break; if (empty($r['claimed']) && time() > $claimDeadline) break; // nobody took it: fall back
            sleep(1);
        }
        @unlink(self::dir($slug) . '/.studio/soak-request.json'); self::emit($slug, 'note', ['text' => 'no browser answered the soak request; running it on the server']); return null;
    }
    /** A soak, blocking: in an attached viewer's browser when one is there, else headless on the server. */
    public static function runSoak(string $slug, int $secs = 60, int $fast = 4): array {
        if (self::browserAttached($slug)) { $rep = self::browserSoak($slug, $secs, $fast); if ($rep !== null) return $rep; }
        return self::chromeSoak($slug, $secs, $fast);
    }
    /** The server's own headless Chrome through Playwright. */
    public static function chromeSoak(string $slug, int $secs = 60, int $fast = 4): array {
        $d = self::dir($slug); $sd = self::studioDir($slug) . '/soaks'; if (!is_dir($sd)) mkdir($sd, 0775, true);
        $id = date('Ymd-His'); $shot = $sd . '/' . $id . '.png';
        self::emit($slug, 'note', ['text' => "soak: {$secs} game-seconds at {$fast}x"]);
        $env = ['PLAYWRIGHT_ROOT=' . escapeshellarg((string) self::cfg('playwright_root', '/home/ubuntu')), 'CHROME=' . escapeshellarg((string) self::cfg('chrome', '/usr/bin/google-chrome')), 'CDP_URL=' . escapeshellarg((string) self::cfg('cdp_url', 'http://127.0.0.1:9222')), 'HOME=' . escapeshellarg((string) (getenv('HOME') ?: '/home/ubuntu'))];
        $cmd = implode(' ', $env) . ' timeout 700 node ' . escapeshellarg(self::root() . '/studio/soak.mjs') . ' ' . escapeshellarg(self::soakUrl($slug)) . ' ' . (int) $secs . ' ' . (int) $fast . ' ' . escapeshellarg($shot) . ' 2>&1';
        exec($cmd, $out, $rc);
        $json = null; foreach (array_reverse($out) as $line) if (str_starts_with($line, '{')) { $json = json_decode($line, true); break; }
        $rep = is_array($json) ? $json : ['ok' => false, 'status' => $rc === 124 ? 'timeout' : 'crashed', 'browserErrors' => [['kind' => 'runner', 'text' => substr(implode("\n", $out), -1500)]]];
        $rep['id'] = $id; $rep['secs'] = $secs; $rep['fast'] = $fast; unset($rep['notes']); if (!empty($rep['shot'])) { if (preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', (string) $rep['shot'], $m) && !is_file($shot)) file_put_contents($sd . '/' . $id . '.jpg', base64_decode($m[1])); unset($rep['shot']); }
        self::writeAtomic($sd . '/' . $id . '.json', json_encode($rep, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $be = array_values(array_filter($rep['browserErrors'] ?? [], fn($e) => ($e['kind'] ?? '') !== 'network'));
        self::emit($slug, 'soak', ['id' => $id, 'ok' => (bool) ($rep['ok'] ?? false), 'status' => $rep['status'] ?? '', 'errors' => array_slice($rep['errors'] ?? [], 0, 10), 'browserErrors' => array_slice($be, 0, 10), 'checks' => $rep['checks'] ?? [], 'fps' => $rep['fps'] ?? null, 'slowFrames' => $rep['slowFrames'] ?? null, 'scenes' => $rep['scenes'] ?? [], 'score' => $rep['score'] ?? null, 'seconds' => $rep['seconds'] ?? null]);
        return $rep;
    }

    // ---- the editors' files (the format centipede's tools/devserver.py wrote; the editors read and write exactly this) ----
    public const WAVES = ['square', 'sawtooth', 'triangle', 'sine', 'noise'];
    public const LAYER = ['f0' => [1, 20000], 'f1' => [1, 20000], 'd' => [0.01, 5], 'v' => [0, 1], 't' => [0, 5000], 'lfo' => [0, 200], 'depth' => [0, 5000]];
    public static function readSfx(string $slug): array {
        $p = self::dir($slug) . '/js/sfx.js'; if (!is_file($p)) return [];
        $src = (string) file_get_contents($p); $i = strpos($src, 'const SFX_DATA = '); if ($i === false) return [];
        return json_decode(rtrim(rtrim(substr($src, $i + 17)), ';'), true) ?: [];
    }
    public static function saveSfx(string $slug, array $data): int {
        $clean = [];
        foreach ($data as $key => $s) {
            if (!preg_match('/^[a-z][A-Za-z0-9_]*(:[A-Za-z0-9_-]+)?$/', (string) $key) || !is_array($s)) throw new \InvalidArgumentException("bad sound key: {$key}");
            $layers = $s['layers'] ?? null;
            if (!is_array($layers) || count($layers) < 1 || count($layers) > 64) throw new \InvalidArgumentException("{$key}: 1 to 64 layers");
            $out = [];
            foreach ($layers as $L) {
                if (!is_array($L) || !in_array($L['w'] ?? '', self::WAVES, true)) throw new \InvalidArgumentException("{$key}: a layer needs a wave");
                $q = ['w' => $L['w']];
                foreach (self::LAYER as $f => [$lo, $hi]) {
                    $v = $L[$f] ?? (in_array($f, ['t', 'lfo', 'depth']) ? 0 : null);
                    if (!is_int($v) && !is_float($v)) throw new \InvalidArgumentException("{$key}: {$f} is not a number");
                    $q[$f] = round(min($hi, max($lo, $v)), 4);
                }
                if (!$q['lfo'] || !$q['depth']) { unset($q['lfo'], $q['depth']); }
                if (!$q['t']) unset($q['t']);
                if (!empty($L['crush'])) $q['crush'] = 1;
                if (!empty($L['m'])) $q['m'] = 1;
                if (isset($L['c']) && is_string($L['c']) && preg_match('/^#[0-9a-fA-F]{6}$/', $L['c'])) $q['c'] = strtolower($L['c']);
                $out[] = $q;
            }
            $entry = ['layers' => $out];
            $j = $s['jitter'] ?? 0; if ((is_int($j) || is_float($j)) && $j > 0 && $j <= 0.5) $entry['jitter'] = round($j, 3);
            $clean[$key] = $entry;
        }
        ksort($clean);
        $p = self::dir($slug) . '/js/sfx.js';
        $head = is_file($p) ? explode('const SFX_DATA = ', (string) file_get_contents($p))[0] : "// Sound effects designed in the SFX editor.\n";
        $rows = []; foreach ($clean as $k => $v) $rows[] = '  ' . json_encode($k) . ': ' . json_encode($v, JSON_UNESCAPED_SLASHES);
        self::writeAtomic($p, $head . 'const SFX_DATA = ' . ($rows ? "{\n" . implode(",\n", $rows) . "\n}" : '{}') . ";\n");
        return count($clean);
    }
    public static function sounds(string $slug): array {
        $js = self::dir($slug) . '/js'; $out = []; $themes = [];
        foreach (is_dir($js) ? scandir($js) : [] as $fn) {
            if (!str_ends_with($fn, '.js') || in_array($fn, ['sfx.js', 'music-themes.js'])) continue;
            foreach (file($js . '/' . $fn) ?: [] as $n => $line) {
                if (preg_match_all("/SFX\\.play\\(\\s*'([A-Za-z0-9_:-]+)'/", $line, $mm)) foreach ($mm[1] as $k) { $c = strpos($line, '//'); $out[] = ['key' => $k, 'file' => 'js/' . $fn, 'line' => $n + 1, 'what' => $c !== false ? trim(substr($line, $c + 2)) : '']; }
                if (preg_match_all("/Music\\.(?:start|playOnce|fanfare|has)\\(\\s*'([A-Za-z0-9_-]+)'/", $line, $mm)) foreach ($mm[1] as $k) $themes[] = ['name' => $k, 'file' => 'js/' . $fn, 'line' => $n + 1];
            }
        }
        $designed = array_keys(self::readSfx($slug)); sort($designed);
        return ['sounds' => $out, 'designed' => $designed, 'themes' => $themes];
    }
    public static function readTuning(string $slug): array {
        $p = self::dir($slug) . '/js/tuning.js'; if (!is_file($p)) return [];
        $src = (string) file_get_contents($p); $i = strpos($src, 'const TUNING = '); if ($i === false) return [];
        $j = strpos($src, ';', $i); return json_decode(substr($src, $i + 15, $j - $i - 15), true) ?: [];
    }
    public static function saveTuning(string $slug, array $data): int {
        $clean = [];
        foreach ($data as $k => $v) {
            if (!preg_match('/^[A-Z][A-Z0-9_]*(\.[A-Za-z0-9_]+){0,4}$/', (string) $k)) throw new \InvalidArgumentException("bad path: {$k}");
            if (is_bool($v)) $clean[$k] = $v; elseif (is_int($v) || is_float($v)) $clean[$k] = round($v, 4); else throw new \InvalidArgumentException("{$k}: a number or true/false");
        }
        ksort($clean);
        $rows = []; foreach ($clean as $k => $v) $rows[] = '  ' . json_encode($k) . ': ' . json_encode($v);
        $head = "// Overrides for js/config.js, written by the config editor. Each entry is a CFG path and its value; only what differs from\n// js/config.js is here. The editor rewrites this file.\nconst TUNING = ";
        $tail = "\n// applied once, right after js/config.js loads: each path is written onto CFG (a path that no longer exists, or a value\n// of the wrong kind, is skipped)\n(function applyTuning() { for (const [path, v] of Object.entries(TUNING)) { const keys = path.split('.'), k = keys.pop(); let o = CFG; for (const q of keys) o = o && o[q]; if (o && typeof o === 'object' && typeof o[k] === typeof v) o[k] = v; } })();\n";
        self::writeAtomic(self::dir($slug) . '/js/tuning.js', $head . ($rows ? "{\n" . implode(",\n", $rows) . "\n}" : '{}') . ';' . $tail);
        return count($clean);
    }
    private static function songPattern(string $name): string { return '/    \/\/ made in the tracker[^\n]*\n    ' . preg_quote($name, '/') . ': \{ tracker: true,[\s\S]*?\n    \] \},\n/'; }
    public static function saveSong(string $slug, string $name, string $snippet): string {
        if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name)) throw new \InvalidArgumentException('a song name is lowercase letters, digits and underscores');
        if (!str_contains($snippet, "{$name}: { tracker: true,") || strlen($snippet) > 400000) throw new \InvalidArgumentException("that is not a tracker snippet for {$name}");
        $p = self::dir($slug) . '/js/music-themes.js'; $src = (string) file_get_contents($p);
        $snippet = rtrim($snippet, "\n") . "\n";
        if (!str_starts_with($snippet, '    // made in the tracker')) $snippet = "    // made in the tracker\n" . $snippet;
        $pat = self::songPattern($name);
        if (preg_match($pat, $src)) { $out = preg_replace($pat, fn() => $snippet, $src, 1); $action = 'replaced'; }
        else {
            if (!preg_match('/^[ \t]*' . preg_quote(self::MARKER, '/') . '/m', $src, $m, PREG_OFFSET_CAPTURE)) throw new \InvalidArgumentException("js/music-themes.js has no '" . self::MARKER . "' marker inside Music.THEMES");
            $j = strpos($src, "\n", $m[0][1]) + 1; $out = substr($src, 0, $j) . $snippet . substr($src, $j); $action = 'added';
        }
        self::writeAtomic($p . '.bak', $src); self::writeAtomic($p, $out);
        exec('node --check ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
        if ($rc !== 0) { self::writeAtomic($p, $src); throw new \InvalidArgumentException('the snippet does not parse, nothing saved: ' . substr(implode("\n", $o), -300)); }
        return $action;
    }
    public static function deleteSong(string $slug, string $name): void {
        $p = self::dir($slug) . '/js/music-themes.js'; $src = (string) file_get_contents($p); $pat = self::songPattern($name);
        if (!preg_match($pat, $src)) throw new \InvalidArgumentException("no tracker song named {$name}");
        self::writeAtomic($p . '.bak', $src); self::writeAtomic($p, preg_replace($pat, '', $src, 1));
    }

    // ---- publish: a deployable build (kit inlined, version stamped) into the folder capricorn serves for <slug>.<domain> ----
    public static function publish(object $g): array {
        $slug = (string) $g->slug; $d = self::dir($slug); $dist = $d . '/dist';
        [, $sha] = self::git($d, ['rev-parse', '--short', 'HEAD']); $sha = trim($sha) ?: 'dev';
        [, $st] = self::git($d, ['status', '--porcelain']); $bid = $sha . (trim($st) !== '' ? '-dirty' : '');
        if (is_dir($dist)) exec('rm -rf ' . escapeshellarg($dist));
        mkdir($dist . '/js', 0775, true);
        foreach (glob($d . '/js/*.js') as $f) copy($f, $dist . '/js/' . basename($f));
        foreach (glob(self::root() . '/kit/js/*.js') as $f) copy($f, $dist . '/js/' . basename($f));
        if (is_dir($d . '/icons')) self::copyTree($d . '/icons', $dist . '/icons');
        if (is_file($d . '/manifest.webmanifest')) copy($d . '/manifest.webmanifest', $dist . '/manifest.webmanifest');
        self::writeAtomic($dist . '/js/version.js', 'const BUILD = ' . json_encode(['id' => $bid, 'date' => date('Y-m-d'), 'channel' => 'stable']) . ";\n");
        $html = str_replace('../../kit/js/', 'js/', (string) file_get_contents($d . '/index.html'));
        $html = preg_replace_callback('/<script src="js\/([a-z0-9-]+)\.js"><\/script>/', fn($m) => "<script src=\"js/{$m[1]}.js?v={$bid}\"></script>", $html);
        $html = preg_replace('/<script src="js\/kit-util\.js/', "<script src=\"js/version.js?v={$bid}\"></script>\n<script src=\"js/kit-util.js", $html, 1);
        self::writeAtomic($dist . '/index.html', $html);
        $out = ['build' => $bid, 'dist' => 'games/' . $slug . '/dist'];
        $domain = (string) self::cfg('domain', ''); $root = (string) self::cfg('publish_root', '/var/www/html/default');
        if ($domain !== '') {
            $sname = $slug . '.' . preg_replace('/\.[a-z]+$/', '', $domain); // void-drift.voidlux: capricorn's folder name for void-drift.voidlux.com
            $site = rtrim($root, '/') . '/' . $sname; $pub = $site . '/public';
            if (!is_dir($pub)) mkdir($pub, 0775, true);
            exec('rsync -a --delete ' . escapeshellarg($dist . '/') . ' ' . escapeshellarg($pub . '/') . ' 2>&1', $o, $rc);
            if ($rc !== 0) throw new \RuntimeException('copying the build to ' . $pub . ' failed: ' . implode(' ', $o));
            $proxy = rtrim($root, '/') . '/../.proxy.' . $sname; if (is_file($proxy)) @unlink($proxy); // an older route through a studio process
            $out['url'] = "https://{$slug}.{$domain}/"; $out['folder'] = $pub;
        }
        $g->publishedAt = date('c'); $g->publishedUrl = $out['url'] ?? ''; $g->publishedBuild = $bid; if (($out['url'] ?? '') !== '') $g->status = 'published';
        Bean::store($g);
        self::emit($slug, 'note', ['text' => "published build {$bid}" . (isset($out['url']) ? ' to ' . $out['url'] : ' to dist/ (no [forge] domain in config.ini)')]);
        return $out;
    }
}
