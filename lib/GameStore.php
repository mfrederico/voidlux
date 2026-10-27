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
            'status' => (string) $g->status, 'price' => $g->price === null ? null : (float) $g->price, 'isPublic' => (int) $g->isPublic === 1, 'tags' => json_decode((string) $g->tags, true) ?: [], 'notes' => (string) $g->notes,
            'memberId' => (int) $g->memberId, 'teamId' => (int) $g->teamId, 'createdAt' => (string) $g->createdAt, 'publishedAt' => (string) $g->publishedAt, 'publishedUrl' => (string) $g->publishedUrl,
            'lastSoak' => $soaks[0] ?? null, 'checkpoints' => count(self::checkpoints($slug)), 'lastCommit' => mb_substr(trim($log), 0, 140),
            'tasks' => ['done' => count(array_filter($tasks, fn($t) => $t['done'])), 'total' => count($tasks)], 'taskList' => $tasks,
            'agent' => self::agentState($slug), 'playUrl' => '/games/' . $slug . '/', 'cover' => self::cover($slug), 'gif' => self::gif($slug),
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
        if (in_array($mode, ['chat', 'pipeline'], true) && trim($prompt) !== '') self::emit($slug, 'prompt', ['text' => mb_substr($prompt, 0, 4000), 'mode' => $mode, 'by' => self::memberName($memberId)]); // what the producer said, in the timeline where the answer follows
        $log = $sd . '/runner.log';
        $cmd = 'setsid nohup php ' . escapeshellarg(self::root() . '/scripts/game-agent.php') . ' ' . escapeshellarg($slug) . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg((string) $memberId) . ' >> ' . escapeshellarg($log) . ' 2>&1 & echo $!';
        $pid = (int) trim((string) shell_exec($cmd));
        if ($pid <= 0) throw new \RuntimeException('the agent runner did not start; see ' . $log);
        $s = array_merge($s, ['busy' => true, 'mode' => $mode, 'pid' => $pid, 'started' => date('c'), 'by' => $memberId]);
        self::saveAgentState($slug, $s);
        return $s;
    }
    /**
     * Scrap a game: its runs stopped, its folder and any published copy moved into .scrapped/ archives (nothing erased, so it can
     * be brought back by hand), its notes and its row gone from the forge. Returns where the archives went.
     */
    public static function scrap(object $g, int $memberId): array {
        $slug = (string) $g->slug; $stamp = date('Ymd-His'); $out = ['slug' => $slug, 'archived' => [], 'site' => null];
        try { self::stopRunner($slug); } catch (\Throwable $e) {}
        $a = self::artState($slug); if ($a['busy'] && self::alive((int) $a['pid'])) { @posix_kill(-(int) $a['pid'], SIGTERM); @posix_kill((int) $a['pid'], SIGTERM); }
        $d = self::dir($slug); $arch = self::root() . '/games/.scrapped'; if (!is_dir($arch)) mkdir($arch, 0775, true);
        if (is_dir($d)) { $to = "$arch/$slug-$stamp"; if (!rename($d, $to)) throw new \RuntimeException("could not move the game folder to $to"); $out['archived'][] = $to; }
        $root = rtrim((string) self::cfg('publish_root', '/var/www/html/default'), '/'); $label = explode('.', (string) self::cfg('domain', 'voidlux.com'))[0];
        $site = "$root/$slug.$label"; if (is_dir($site)) { $sa = "$root/.scrapped"; if (!is_dir($sa)) mkdir($sa, 0775, true); $to = "$sa/$slug.$label-$stamp"; if (rename($site, $to)) { $out['archived'][] = $to; $out['site'] = (string) $g->publishedUrl; } else error_log("ERROR scrap: could not move $site"); }
        foreach (Bean::find('gamenote', 'game_id = ?', [(int) $g->id]) as $n) { Bean::trashAll(Bean::find('gamenotevote', 'gamenote_id = ?', [(int) $n->id])); Bean::trashAll(Bean::find('gamenotecomment', 'gamenote_id = ?', [(int) $n->id])); Bean::trash($n); }
        Bean::trash($g);
        error_log("INFO scrap: {$slug} scrapped by member {$memberId}: " . implode(', ', $out['archived']));
        return $out;
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
    /** The newest playtest picture: a mid-game view of the game as it is now, or '' when none was taken yet. */
    public static function cover(string $slug): string { foreach (self::soaks($slug) as $k) if (!empty($k['shot'])) return (string) $k['id']; return ''; }
    /** The latest playtest that left an animation (<id>.gif: the first seconds of play at 5 fps), or ''. */
    public static function gif(string $slug): string { foreach (self::soaks($slug) as $k) if (is_file(self::dir($slug) . '/.studio/soaks/' . $k['id'] . '.gif')) return (string) $k['id']; return ''; }
    /** A report's frames (data: URLs, 5 a second) stitched into <id>.gif with ImageMagick; a report without frames leaves no gif. */
    public static function saveGif(string $sd, string $id, array $rep): void {
        $frames = $rep['gif'] ?? null; if (!is_array($frames) || count($frames) < 5) return;
        $tmp = sys_get_temp_dir() . '/vl-gif-' . bin2hex(random_bytes(4)); mkdir($tmp, 0700); $files = [];
        foreach (array_slice($frames, 0, 30) as $i => $f) { if (!preg_match('#^data:image/(jpeg|png);base64,([A-Za-z0-9+/=]+)$#', (string) $f, $m)) continue; $p = sprintf('%s/f%02d.%s', $tmp, $i, $m[1] === 'png' ? 'png' : 'jpg'); file_put_contents($p, base64_decode($m[2])); $files[] = $p; }
        if (count($files) >= 5) {
            $cmd = 'timeout 60 convert -delay 20 -loop 0 ' . implode(' ', array_map('escapeshellarg', $files)) . ' -resize 320x180 -colors 128 -layers Optimize ' . escapeshellarg("$sd/$id.gif") . ' 2>&1';
            exec($cmd, $out, $rc); if ($rc !== 0) error_log('ERROR soak gif: convert failed: ' . implode(' ', $out));
        }
        foreach ($files as $p) @unlink($p); @rmdir($tmp);
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
        self::saveGif($sd, $id, $rep);
        unset($rep['shot'], $rep['gif']); $rep['id'] = $id; $rep['secs'] = (int) $r['secs']; $rep['fast'] = (int) $r['fast']; $rep['runner'] = 'browser'; $rep['status'] = $rep['status'] ?? 'ok';
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
        $deadline = time() + (int) ($secs / max(1, $fast) * 2.5) + 90; $claimDeadline = time() + (int) self::cfg('browser_soak_wait', 60);
        while (time() < $deadline) {
            if (is_file($f)) return json_decode((string) file_get_contents($f), true) ?: null;
            $r = self::soakRequest($slug); if (!$r) break; if (empty($r['claimed']) && time() > $claimDeadline) break; // nobody took it: fall back
            sleep(1);
        }
        @unlink(self::dir($slug) . '/.studio/soak-request.json'); self::emit($slug, 'note', ['text' => 'no browser answered the playtest request']); return null;
    }
    /** A soak, blocking, in a viewer's browser. The server's own Chrome runs one only when [forge] server_chrome = 1: on
     *  this machine it brought the box down, so with nobody's page open the answer is a 'no-browser' report, not a launch. */
    public static function runSoak(string $slug, int $secs = 60, int $fast = 4): array {
        $rep = self::browserSoak($slug, $secs, $fast); if ($rep !== null) return $rep;
        if ((string) self::cfg('server_chrome', '0') === '1') return self::chromeSoak($slug, $secs, $fast);
        $rep = ['ok' => false, 'status' => 'no-browser', 'runner' => 'none', 'errors' => [], 'checks' => [], 'scenes' => [], 'note' => 'no browser is attached to this game: open its page in the forge (Play or Test tab) and the playtest runs there. Not a fault in the game.'];
        self::emit($slug, 'soak', ['id' => '', 'ok' => false, 'status' => 'no-browser', 'runner' => 'none', 'errors' => [], 'browserErrors' => [], 'checks' => [], 'scenes' => [], 'text' => $rep['note']]);
        return $rep;
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
        $rep['id'] = $id; $rep['secs'] = $secs; $rep['fast'] = $fast; unset($rep['notes']); self::saveGif($sd, $id, $rep); unset($rep['gif']); if (!empty($rep['shot'])) { if (preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', (string) $rep['shot'], $m) && !is_file($shot)) file_put_contents($sd . '/' . $id . '.jpg', base64_decode($m[1])); unset($rep['shot']); }
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
    // ---- sprites: assets/sprites.json (key → { file, w, h, frames, fps, source }) and the picture files it names ----
    public const SPRITE_KEY = '/^[a-z][a-z0-9_-]{0,40}$/D';
    public static function readSprites(string $slug): array {
        $dir = self::dir($slug) . '/assets'; $mf = $dir . '/sprites.json'; $m = ['version' => 1, 'sprites' => (object) []];
        if (is_file($mf)) { $j = json_decode((string) file_get_contents($mf), true); if (!is_array($j) || !isset($j['sprites']) || !is_array($j['sprites'])) throw new \RuntimeException("assets/sprites.json is not a sprite manifest; fix or remove it"); $m = $j; $m['sprites'] = (object) $j['sprites']; }
        $named = array_column(array_map(fn($s) => (array) $s, array_values((array) $m['sprites'])), 'file'); $loose = [];
        foreach (is_dir($dir) ? scandir($dir) : [] as $f) if ($f[0] !== '.' && is_file("$dir/$f") && preg_match('/\.(png|svg|gif|webp|jpe?g)$/i', $f) && !in_array($f, $named, true)) $loose[] = $f;
        return ['sprites' => $m['sprites'], 'files' => $loose];
    }
    /** @param array{key:string,png?:string,svg?:string,file?:string,w?:int,h?:int,frames?:int,fps?:int,source?:string,smooth?:bool} $b */
    public static function saveSprite(string $slug, array $b): array {
        $key = (string) ($b['key'] ?? ''); if (!preg_match(self::SPRITE_KEY, $key)) throw new \InvalidArgumentException('a sprite key is lowercase letters, digits, - and _, starting with a letter');
        $dir = self::dir($slug) . '/assets'; if (!is_dir($dir)) mkdir($dir, 0775, true);
        $w = (int) ($b['w'] ?? 0); $h = (int) ($b['h'] ?? 0); $frames = max(1, (int) ($b['frames'] ?? 1)); $fps = min(60, max(1, (int) ($b['fps'] ?? 8)));
        if (!empty($b['png'])) {
            if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', (string) $b['png'], $mm)) throw new \InvalidArgumentException('png must be a data: URL');
            $bin = base64_decode($mm[1], true); if ($bin === false || strlen($bin) > 4 * 1024 * 1024 || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") throw new \InvalidArgumentException('not a PNG, or over 4 MB');
            $file = "$key.png"; $sz = getimagesizefromstring($bin); if (!$sz) throw new \InvalidArgumentException('the PNG cannot be read');
            if ($w < 1 || $h < 1) { $w = (int) ($sz[0] / $frames); $h = (int) $sz[1]; }
            self::writeAtomic("$dir/$file", $bin);
        } elseif (isset($b['svg'])) {
            $svg = (string) $b['svg']; if (strlen($svg) > 1024 * 1024 || stripos($svg, '<svg') === false) throw new \InvalidArgumentException('svg must be an <svg> document under 1 MB');
            if (preg_match('/<script|\son[a-z]+\s*=|javascript:|<foreignObject/i', $svg)) throw new \InvalidArgumentException('that SVG carries script or event handlers; the game serves it from its own origin, so it is refused');
            $file = "$key.svg"; if ($w < 1 || $h < 1) { $w = $w ?: 64; $h = $h ?: 64; } $frames = 1;
            self::writeAtomic("$dir/$file", $svg);
        } elseif (isset($b['rig'])) {
            $code = (string) $b['rig']; self::checkSpriteCode($code, 'Rig.define(');
            $file = "$key.rig.js"; if (!preg_match('/Rig\.define\(\s*([\'"])' . preg_quote($key, '/') . '\1\s*,/', $code)) throw new \InvalidArgumentException("the rig must call Rig.define('{$key}', { ... }) with this sprite's key");
            $half = preg_match('/half:\s*([0-9.]+)/', $code, $hm) ? (float) $hm[1] : 24; if ($w < 1 || $h < 1) { $w = $h = (int) round($half * 2); } $frames = 1;
            self::writeAtomic("$dir/$file", rtrim($code) . "\n"); $kind = 'rig';
        } elseif (isset($b['code'])) {
            $code = (string) $b['code']; self::checkSpriteCode($code);
            $file = "$key.js"; $half = 0; if (preg_match('/Draw\.define\(\s*([\'"])' . preg_quote($key, '/') . '\1\s*,\s*([0-9.]+)/', $code, $dm)) $half = (float) $dm[2];
            if ($half <= 0) throw new \InvalidArgumentException("the code must call Draw.define('{$key}', <half>, (g, f) => { ... }) with this sprite's key");
            if ($w < 1 || $h < 1) { $w = $h = (int) round($half * 2); }
            if (preg_match('/frames:\s*([0-9]+)/', $code, $fm)) $frames = max(1, (int) $fm[1]); if (preg_match('/fps:\s*([0-9]+)/', $code, $pm)) $fps = min(60, max(1, (int) $pm[1]));
            self::writeAtomic("$dir/$file", rtrim($code) . "\n"); $kind = 'code';
        } elseif (!empty($b['copyOf'])) { // a duplicate of a kept sprite under this key: its file copied, its entry too
            $src = (string) $b['copyOf']; $all0 = self::readSprites($slug); $e0 = ((array) $all0['sprites'])[$src] ?? null; if (!$e0) throw new \InvalidArgumentException("no sprite named {$src} to copy");
            $e0 = (array) $e0; $sf = basename((string) $e0['file']); $ext = preg_replace('/^.*?(\.rig)?\.([a-z0-9]+)$/i', '$1.$2', $sf); $file = $key . $ext;
            if (!is_file("$dir/$sf")) throw new \InvalidArgumentException("assets/$sf is missing");
            $code = (string) file_get_contents("$dir/$sf");
            if (preg_match('/\.js$/', $sf)) $code = preg_replace('/((?:Draw|Rig)\.define\(\s*)([\'"])' . preg_quote($src, '/') . '\2/', '$1$2' . $key . '$2', $code, 1); // the copy defines itself under the new key
            self::writeAtomic("$dir/$file", $code); if (is_file("$dir/$src.rig-hints.json")) copy("$dir/$src.rig-hints.json", "$dir/$key.rig-hints.json");
            $w = (int) ($e0['w'] ?? 0); $h = (int) ($e0['h'] ?? 0); $frames = (int) ($e0['frames'] ?? 1); $fps = (int) ($e0['fps'] ?? 8); if (!empty($e0['kind'])) $kind = $e0['kind']; if (!empty($e0['smooth'])) $b['smooth'] = true; $b['source'] = $b['source'] ?? ($e0['source'] ?? 'editor');
        } elseif (!empty($b['file'])) {
            $file = basename((string) $b['file']); if ($file[0] === '.' || !is_file("$dir/$file")) throw new \InvalidArgumentException("assets/$file does not exist");
            if ($w < 1 || $h < 1) { $sz = @getimagesize("$dir/$file"); $w = $sz ? (int) ($sz[0] / $frames) : 64; $h = $sz ? (int) $sz[1] : 64; }
        } else throw new \InvalidArgumentException('a sprite needs png, svg or file');
        $all = self::readSprites($slug); $sprites = (array) $all['sprites'];
        $entry = ['file' => $file, 'w' => $w, 'h' => $h, 'frames' => $frames, 'fps' => $fps, 'source' => preg_replace('/[^a-z-]/', '', (string) ($b['source'] ?? 'editor')) ?: 'editor'];
        if (!empty($b['smooth'])) $entry['smooth'] = true; if (!empty($kind)) $entry['kind'] = $kind;
        $sprites[$key] = $entry; ksort($sprites);
        self::writeAtomic("$dir/sprites.json", json_encode(['version' => 1, 'sprites' => (object) $sprites], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        self::git(self::dir($slug), ['add', 'assets/sprites.json', "assets/$file"]);
        return $entry;
    }
    /** A code sprite is a script the game runs: only drawing, and it must parse. Throws with the reason. */
    public static function checkSpriteCode(string $code, string $call = 'Draw.define('): void {
        if (strlen($code) > 120000) throw new \InvalidArgumentException('a code sprite or rig is under 120 KB');
        if (!str_contains($code, $call) && !str_contains($code, 'Rig.define(')) throw new \InvalidArgumentException("a code sprite calls {$call}key, …) (a rig calls Rig.define(key, { … }))");
        if (preg_match('/\b(fetch|XMLHttpRequest|WebSocket|import|require|eval|Function|document|window|globalThis|localStorage|sessionStorage|navigator|location|cookie|setTimeout|setInterval)\b/', $code, $m) || preg_match('/<\/?script/i', $code, $m)) throw new \InvalidArgumentException("a code sprite only draws: '{$m[0]}' is not allowed in it"); // case matters: `function` helpers are fine, `Function` is not
        $node = (string) self::cfg('node_bin', ''); $node = $node !== '' ? rtrim($node, '/') . '/node' : 'node';
        $tmp = tempnam(sys_get_temp_dir(), 'sprite') . '.js'; file_put_contents($tmp, $code);
        exec('timeout 10 ' . escapeshellarg($node) . ' --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc); @unlink($tmp);
        if ($rc !== 0) throw new \InvalidArgumentException('the code does not parse: ' . mb_substr(trim(implode("\n", $out)), 0, 300));
    }
    /** The producer's bone marks for a sprite: assets/<key>.rig-hints.json ({ plan, half, joints: [{ name, x, y, parent, note }], notes }). */
    public static function readHints(string $slug, string $key): array {
        if (!preg_match(self::SPRITE_KEY, $key)) throw new \InvalidArgumentException('not a sprite key');
        $f = self::dir($slug) . "/assets/$key.rig-hints.json"; if (!is_file($f)) return ['plan' => 'biped', 'joints' => [], 'notes' => ''];
        $h = json_decode((string) file_get_contents($f), true); if (!is_array($h)) throw new \RuntimeException("assets/$key.rig-hints.json is not JSON; fix or remove it"); return $h;
    }
    public static function saveHints(string $slug, string $key, array $b): array {
        if (!preg_match(self::SPRITE_KEY, $key)) throw new \InvalidArgumentException('not a sprite key');
        $h = self::cleanHints($b); $dir = self::dir($slug) . '/assets'; if (!is_dir($dir)) mkdir($dir, 0775, true);
        self::writeAtomic("$dir/$key.rig-hints.json", json_encode($h, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"); self::git(self::dir($slug), ['add', "assets/$key.rig-hints.json"]); return $h;
    }
    public static function cleanHints(array $b): array {
        $plan = in_array($b['plan'] ?? '', ['biped', 'quadruped', 'winged', 'serpent', 'other'], true) ? (string) $b['plan'] : 'biped'; $joints = [];
        foreach (array_slice((array) ($b['joints'] ?? []), 0, 60) as $j) { if (!is_array($j)) continue; $n = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($j['name'] ?? '')); if ($n === '') continue;
            $joints[] = ['name' => mb_substr($n, 0, 24), 'x' => round((float) ($j['x'] ?? 0), 1), 'y' => round((float) ($j['y'] ?? 0), 1), 'parent' => mb_substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($j['parent'] ?? '')), 0, 24), 'note' => trim(mb_substr((string) ($j['note'] ?? ''), 0, 160))]; }
        return ['plan' => $plan, 'half' => max(4, (float) ($b['half'] ?? 24)), 'joints' => $joints, 'notes' => trim(mb_substr((string) ($b['notes'] ?? ''), 0, 400))];
    }
    public static function deleteSprite(string $slug, string $key, bool $withFile): void {
        if (!preg_match(self::SPRITE_KEY, $key)) throw new \InvalidArgumentException('not a sprite key');
        $dir = self::dir($slug) . '/assets'; $all = self::readSprites($slug); $sprites = (array) $all['sprites'];
        if (!isset($sprites[$key])) throw new \InvalidArgumentException("no sprite named $key");
        $file = basename((string) ((array) $sprites[$key])['file']); unset($sprites[$key]);
        $stillUsed = in_array($file, array_column(array_map(fn($s) => (array) $s, array_values($sprites)), 'file'), true);
        self::writeAtomic("$dir/sprites.json", json_encode(['version' => 1, 'sprites' => (object) $sprites], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        if ($withFile && !$stillUsed && is_file("$dir/$file")) { unlink("$dir/$file"); self::git(self::dir($slug), ['rm', '-q', '--cached', '--ignore-unmatch', "assets/$file"]); }
        self::git(self::dir($slug), ['add', 'assets/sprites.json']);
    }

    /**
     * A message for the builder: a run now; or, when one is going, a line in the inbox for the next run (a remark that
     * belongs with whatever runs next), or with $own a run of its own queued to start the moment this one ends (a sprite
     * job: it must not ride along as a side note inside a build task).
     */
    public static function askBuilder(string $slug, string $prompt, int $memberId, bool $own = false): array {
        if (self::agentState($slug)['busy']) {
            if ($own) {
                file_put_contents(self::studioDir($slug) . '/queue.jsonl', json_encode(['prompt' => $prompt, 'memberId' => $memberId, 'at' => time()], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
                self::emit($slug, 'note', ['text' => 'queued as a run of its own, right after this one: ' . mb_substr($prompt, 0, 160)]);
            } else {
                file_put_contents(self::studioDir($slug) . '/inbox.txt', $prompt . "\n", FILE_APPEND);
                self::emit($slug, 'prompt', ['text' => mb_substr($prompt, 0, 4000), 'mode' => 'inbox', 'by' => self::memberName($memberId)]);
                self::emit($slug, 'note', ['text' => 'the builder is mid-run: this goes to it with its next run']);
            }
            return self::agentState($slug) + ['queued' => true];
        }
        return self::startRunner($slug, 'chat', $prompt, $memberId) + ['queued' => false];
    }
    // ---- the art lane: sprite jobs run beside the builder, in their own runner with their own lock (.studio/art.json), fresh
    //      sessions and no commits; they touch only sketches/<id>/. One at a time per game; more wait in art-queue.jsonl ----
    public static function artState(string $slug): array {
        $f = self::dir($slug) . '/.studio/art.json'; $s = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        $s += ['busy' => false, 'mode' => '', 'pid' => 0, 'session' => null, 'runs' => 0, 'cost' => 0.0];
        if ($s['busy'] && !self::alive((int) $s['pid'])) { $s['busy'] = false; $s['mode'] = ''; $s['pid'] = 0; }
        return $s;
    }
    public static function saveArtState(string $slug, array $s): void { self::writeAtomic(self::studioDir($slug) . '/art.json', json_encode($s, JSON_PRETTY_PRINT)); }
    public static function startArt(string $slug, string $prompt, int $memberId): array {
        $s = self::artState($slug); $sd = self::studioDir($slug);
        if ($s['busy']) {
            file_put_contents($sd . '/art-queue.jsonl', json_encode(['prompt' => $prompt, 'memberId' => $memberId, 'at' => time()], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
            self::emit($slug, 'note', ['text' => 'art: another sprite job is running; this one is next: ' . mb_substr($prompt, 0, 120)]);
            return $s + ['queued' => true];
        }
        file_put_contents($sd . '/art-prompt.txt', $prompt);
        $log = $sd . '/runner.log';
        $cmd = 'setsid nohup php ' . escapeshellarg(self::root() . '/scripts/game-agent.php') . ' ' . escapeshellarg($slug) . ' art ' . escapeshellarg((string) $memberId) . ' >> ' . escapeshellarg($log) . ' 2>&1 & echo $!';
        $pid = (int) trim((string) shell_exec($cmd));
        if ($pid <= 0) throw new \RuntimeException('the art runner did not start; see ' . $log);
        $s = array_merge($s, ['busy' => true, 'mode' => 'art', 'pid' => $pid, 'started' => date('c'), 'by' => $memberId]);
        self::saveArtState($slug, $s);
        return $s + ['queued' => false];
    }
    public static function startQueuedArt(string $slug): bool {
        $q = self::studioDir($slug) . '/art-queue.jsonl'; if (!is_file($q)) return false;
        $lines = array_values(array_filter(array_map('trim', file($q)), fn($l) => $l !== '')); if (!$lines) { @unlink($q); return false; }
        $next = json_decode(array_shift($lines), true); if ($lines) self::writeAtomic($q, implode("\n", $lines) . "\n"); else @unlink($q);
        if (!is_array($next) || trim((string) ($next['prompt'] ?? '')) === '') return self::startQueuedArt($slug);
        try { self::startArt($slug, (string) $next['prompt'], (int) ($next['memberId'] ?? 0)); return true; }
        catch (\Throwable $e) { self::emit($slug, 'error', ['text' => 'art: the queued job could not start: ' . $e->getMessage()]); return false; }
    }
    public static function memberName(int $id): string { if ($id <= 0) return 'the forge'; $m = Bean::load('member', $id); return (string) ($m->username ?: $m->email ?: 'member ' . $id); }
    /** The runner, as it ends: the next queued run of its own, if any, starts now. */
    public static function startQueued(string $slug): bool {
        $q = self::studioDir($slug) . '/queue.jsonl'; if (!is_file($q)) return false;
        $lines = array_values(array_filter(array_map('trim', file($q)), fn($l) => $l !== '')); if (!$lines) { @unlink($q); return false; }
        $next = json_decode(array_shift($lines), true); if ($lines) self::writeAtomic($q, implode("\n", $lines) . "\n"); else @unlink($q);
        if (!is_array($next) || trim((string) ($next['prompt'] ?? '')) === '') return self::startQueued($slug);
        try { self::startRunner($slug, 'chat', (string) $next['prompt'], (int) ($next['memberId'] ?? 0)); self::emit($slug, 'note', ['text' => 'a queued run starts: ' . mb_substr((string) $next['prompt'], 0, 120)]); return true; }
        catch (\Throwable $e) { self::emit($slug, 'error', ['text' => 'the queued run could not start: ' . $e->getMessage()]); return false; }
    }

    // ---- sketches: a producer's rough drawing plus a note, which the builder turns into sprite candidates through its plugins.
    //      games/<slug>/sketches/<id>/{sketch.png, request.json, out-N.png, result.json}; not committed, not published. ----
    public const SKETCH_ID = '/^[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/D';
    public static function sketchDir(string $slug, string $id): string { return self::dir($slug) . '/sketches/' . $id; }
    /** @param array{note?:string,style?:string,pixel?:bool,size?:int,key?:string,palette?:array,png?:string} $b */
    public static function startSketch(string $slug, array $b, int $memberId): array {
        $note = trim(mb_substr((string) ($b['note'] ?? ''), 0, 600)); $png = (string) ($b['png'] ?? '');
        if ($note === '' && $png === '' && empty($b['animate']) && empty($b['rigjob']) && empty($b['vectorize'])) throw new \InvalidArgumentException('a sketch needs a drawing, a note, or both');
        $size = max(8, min(256, (int) ($b['size'] ?? 32))); $key = (string) ($b['key'] ?? 'sprite'); if (!preg_match(self::SPRITE_KEY, $key)) $key = 'sprite';
        $style = trim(mb_substr((string) ($b['style'] ?? 'pixel art'), 0, 120)); $pixel = !empty($b['pixel']);
        $pal = array_values(array_filter(array_map('strval', (array) ($b['palette'] ?? [])), fn($c) => preg_match('/^#[0-9a-f]{6}$/i', $c)));
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3)); $dir = self::sketchDir($slug, $id); mkdir($dir, 0775, true);
        try { return self::startSketchIn($slug, $b, $memberId, $id, $dir, $note, $png, $size, $key, $style, $pixel, $pal); }
        catch (\Throwable $e) { foreach (glob("$dir/*") ?: [] as $f) @unlink($f); @rmdir($dir); throw $e; }
    }
    private static function startSketchIn(string $slug, array $b, int $memberId, string $id, string $dir, string $note, string $png, int $size, string $key, string $style, bool $pixel, array $pal): array {
        $gi = self::dir($slug) . '/.gitignore'; $have = is_file($gi) ? (string) file_get_contents($gi) : '';
        if (!preg_match('#^/?sketches/?$#m', $have)) file_put_contents($gi, rtrim($have) . ($have === '' ? '' : "\n") . "sketches/\n"); // scratch, never in the game's history
        $has = false;
        if ($png !== '') {
            if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $png, $m)) throw new \InvalidArgumentException('the drawing must be a PNG data: URL');
            $bin = base64_decode($m[1], true); if ($bin === false || strlen($bin) > 4 * 1024 * 1024 || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") throw new \InvalidArgumentException('the drawing is not a PNG under 4 MB');
            self::writeAtomic($dir . '/sketch.png', $bin); $has = true;
        }
        // the pad's strokes as exact vector paths: the geometry the builder draws on when it makes the SVG itself
        $strokes = []; foreach ((array) ($b['strokes'] ?? []) as $s) { if (!is_array($s) || empty($s['pts']) || !is_array($s['pts'])) continue; $pts = []; foreach (array_slice($s['pts'], 0, 4000) as $p) if (is_array($p) && count($p) >= 2) $pts[] = [max(0, min(512, (int) $p[0])), max(0, min(512, (int) $p[1]))]; if ($pts) $strokes[] = ['pen' => preg_match('/^#[0-9a-f]{6}$/i', (string) ($s['pen'] ?? '')) ? (string) $s['pen'] : (($s['pen'] ?? '') === 'remove' ? 'remove' : 'erase'), 'w' => max(1, min(120, (int) ($s['w'] ?? 10))), 'pts' => $pts]; if (count($strokes) >= 400) break; }
        if ($strokes) {
            self::writeAtomic($dir . '/strokes.json', json_encode($strokes, JSON_UNESCAPED_SLASHES)); // the pad can take the drawing back
            $paths = ''; foreach ($strokes as $s) { $d = ''; foreach ($s['pts'] as $i => $p) $d .= ($i ? ' L' : 'M') . $p[0] . ' ' . $p[1]; if (count($s['pts']) === 1) $d .= ' L' . ($s['pts'][0][0] + 1) . ' ' . $s['pts'][0][1]; $paths .= '  <path d="' . $d . '" stroke="' . ($s['pen'] === 'erase' ? '#ffffff' : ($s['pen'] === 'remove' ? '#ff2bd6' : $s['pen'])) . '" stroke-width="' . $s['w'] . ($s['pen'] === 'remove' ? '" data-mark="remove' : '') . "\"/>\n"; }
            self::writeAtomic($dir . '/sketch.svg', "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 512 512\" width=\"512\" height=\"512\" fill=\"none\" stroke-linecap=\"round\" stroke-linejoin=\"round\">\n" . $paths . "</svg>\n");
        }
        $mk = (string) ($b['maker'] ?? 'svg'); $maker = in_array($mk, ['svg', 'code', 'spritecook'], true) ? $mk : 'svg';
        // a vector job: a kept picture, usually pixels the producer drew, redrawn as SVG in the same look
        $vector = null;
        if (!empty($b['vectorize']) && !empty($b['asset'])) {
            $all = self::readSprites($slug); $ak = (string) $b['asset']; $e = ((array) $all['sprites'])[$ak] ?? null; if (!$e) throw new \InvalidArgumentException("no sprite named {$ak} in the game");
            $af = basename((string) ((array) $e)['file']); $ext = pathinfo($af, PATHINFO_EXTENSION); if (!in_array($ext, ['png', 'svg', 'gif', 'webp', 'jpg', 'jpeg'], true)) throw new \InvalidArgumentException('only a picture sprite can be made vector');
            copy(self::dir($slug) . "/assets/$af", "$dir/base.$ext"); $vector = ['base' => "base.$ext", 'asset' => $ak, 'w' => (int) (((array) $e)['w'] ?? 0), 'h' => (int) (((array) $e)['h'] ?? 0)]; $maker = 'svg';
        }
        // a rig job: the character's body plan inferred, bones fitted, parts split, idle and locomotion as motions; from a kept sprite or a take
        $rigjob = null;
        if (!empty($b['rigjob']) && is_array($b['rigjob'])) {
            $rigjob = ['notes' => trim(mb_substr((string) ($b['rigjob']['notes'] ?? ''), 0, 400))];
            if (!empty($b['rigjob']['hints']) && is_array($b['rigjob']['hints'])) $rigjob['hints'] = self::cleanHints($b['rigjob']['hints']);
            elseif (!empty($b['asset']) && is_file(self::dir($slug) . '/assets/' . preg_replace('/[^a-z0-9_-]/', '', (string) $b['asset']) . '.rig-hints.json')) { $hh = self::readHints($slug, (string) $b['asset']); if (!empty($hh['joints'])) $rigjob['hints'] = self::cleanHints($hh); }
            if (!empty($b['asset'])) {
                $all = self::readSprites($slug); $ak = (string) $b['asset']; $e = ((array) $all['sprites'])[$ak] ?? null; if (!$e) throw new \InvalidArgumentException("no sprite named {$ak} in the game");
                $af = basename((string) ((array) $e)['file']); $ext = pathinfo($af, PATHINFO_EXTENSION); if (!in_array($ext, ['svg', 'js', 'png'], true)) throw new \InvalidArgumentException('only an svg, code or png sprite can be rigged');
                copy(self::dir($slug) . "/assets/$af", "$dir/base.$ext"); $rigjob['base'] = "base.$ext"; $rigjob['asset'] = $ak;
            }
            $maker = 'code';
        }
        // an animation: a cycle (run, idle, jump…) rigged in code from a take or a kept sprite, always a code sprite with frames
        $anim = null;
        if (!empty($b['animate']) && is_array($b['animate'])) {
            $an = (string) ($b['animate']['name'] ?? 'run'); if (!preg_match('/^[a-z][a-z0-9-]{0,20}$/D', $an)) throw new \InvalidArgumentException('an animation name is lowercase letters, digits and dashes');
            $anim = ['name' => $an, 'frames' => max(2, min(24, (int) ($b['animate']['frames'] ?? 8))), 'fps' => max(2, min(30, (int) ($b['animate']['fps'] ?? 12))), 'notes' => trim(mb_substr((string) ($b['animate']['notes'] ?? ''), 0, 400))];
            if (!empty($b['asset'])) { // a kept sprite as the base
                $all = self::readSprites($slug); $ak = (string) $b['asset']; $e = ((array) $all['sprites'])[$ak] ?? null; if (!$e) throw new \InvalidArgumentException("no sprite named {$ak} in the game");
                $af = basename((string) ((array) $e)['file']); $ext = pathinfo($af, PATHINFO_EXTENSION); if (!in_array($ext, ['svg', 'js', 'png'], true)) throw new \InvalidArgumentException('only an svg, code or png sprite can be animated');
                copy(self::dir($slug) . "/assets/$af", "$dir/base.$ext"); $anim['base'] = "base.$ext"; $anim['asset'] = $ak;
            }
            $maker = 'code';
        }
        // a refinement: the producer points at one earlier take and says what to change; the take comes along as base.<ext>
        $refine = null;
        if (!empty($b['refine']) && is_array($b['refine'])) {
            $pid = (string) ($b['refine']['id'] ?? ''); $pf = basename((string) ($b['refine']['file'] ?? '')); $pdir = self::sketchDir($slug, $pid);
            if (!preg_match(self::SKETCH_ID, $pid) || !preg_match('/^out-[0-9]+\.(svg|js|png)$/', $pf) || !is_file("$pdir/$pf")) throw new \InvalidArgumentException('the take to refine does not exist');
            if ($note === '' && !$anim) throw new \InvalidArgumentException('say what to change'); // an animation needs no instruction beyond its cycle
            $ext = pathinfo($pf, PATHINFO_EXTENSION); $maker = $ext === 'svg' ? 'svg' : ($ext === 'js' ? 'code' : 'spritecook');
            copy("$pdir/$pf", "$dir/base.$ext"); foreach (['sketch.png', 'sketch.svg', 'base.svg', 'base.js', 'base.png'] as $f) if ($f !== "base.$ext" && is_file("$pdir/$f") && !is_file("$dir/$f")) copy("$pdir/$f", "$dir/" . (str_starts_with($f, 'base.') ? 'earlier-' . $f : $f));
            if (!$has && is_file("$dir/sketch.png")) $has = true;
            $refine = ['id' => $pid, 'file' => $pf, 'ext' => $ext];
            if ($anim) { $anim['base'] = "base.$ext"; $refine = null; $maker = 'code'; } // for an animation the take is the base, not a thing to refine
            if ($rigjob) { $rigjob['base'] = "base.$ext"; $refine = null; $maker = 'code'; }
        }
        if ($anim && empty($anim['base'])) throw new \InvalidArgumentException('an animation starts from a take or a kept sprite');
        if ($rigjob && empty($rigjob['base'])) throw new \InvalidArgumentException('a rig starts from a take or a kept sprite');
        // iteration: the drawing came back from an earlier job; that job's takes come along as earlier-N.<ext>, the liked one first
        $from = null;
        if (!empty($b['from']) && is_array($b['from']) && preg_match(self::SKETCH_ID, (string) ($b['from']['id'] ?? ''))) {
            $fid = (string) $b['from']['id']; $fdir = self::sketchDir($slug, $fid); $liked = basename((string) ($b['from']['liked'] ?? ''));
            if (is_file("$fdir/result.json")) {
                $fres = json_decode((string) file_get_contents("$fdir/result.json"), true) ?: []; $files = array_values(array_filter((array) ($fres['files'] ?? []), fn($f) => preg_match('/^out-[0-9]+\.(svg|js|png)$/', (string) $f) && is_file("$fdir/$f")));
                if ($liked !== '' && in_array($liked, $files, true)) { $files = array_merge([$liked], array_values(array_diff($files, [$liked]))); }
                $copied = []; foreach ($files as $i => $f) { $ext = pathinfo($f, PATHINFO_EXTENSION); copy("$fdir/$f", "$dir/earlier-" . ($i + 1) . ".$ext"); $copied[] = 'earlier-' . ($i + 1) . ".$ext"; }
                if ($copied) $from = ['id' => $fid, 'liked' => $liked !== '' && in_array($liked, $files, true) ? 'earlier-1.' . pathinfo($liked, PATHINFO_EXTENSION) : '', 'files' => $copied, 'note' => (string) ($fres['note'] ?? ''), 'over' => !empty($b['from']['over']) && $liked !== '' && in_array($liked, $files, true)]; // over: the producer drew on top of the liked take
            }
        }
        $saveOnly = !empty($b['save_only']);
        $req = ['id' => $id, 'note' => $note, 'style' => $style, 'pixel' => $pixel, 'size' => $size, 'key' => $key, 'palette' => $pal, 'sketch' => $has, 'strokes' => count($strokes), 'maker' => $maker, 'refine' => $refine, 'from' => $from, 'animate' => $anim, 'rig' => $rigjob, 'vector' => $vector, 'saved' => $saveOnly, 'by' => $memberId, 'at' => time()];
        self::writeAtomic($dir . '/request.json', json_encode($req, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        if ($saveOnly) return ['id' => $id, 'key' => $key, 'at' => $req['at'], 'saved' => true]; // kept in the gallery, not sent
        $what = $note !== '' ? "\"{$note}\"" : 'nothing written: go by the drawing';
        $target = "{$size}×{$size} px, {$style}, transparent background" . ($pal ? ', using only these colours: ' . implode(', ', $pal) : '');
        $look = ($has ? "Their drawing: sketches/{$id}/sketch.png (512×512, coloured lines on white; look at it with Read)" . ($strokes ? ", and sketches/{$id}/sketch.svg: the very same strokes as vector paths in a 512×512 viewBox, the geometry to build on" : '') . ". " : 'They drew nothing: go by the note alone. ');
        if ($maker === 'code') {
            $half = $size / 2;
            $p = "The producer wants a game sprite drawn from a rough sketch, and you are the artist: you draw it as canvas code, the way Centipede draws its creatures (see games/centipede/js/render-enemies.js for the manner). First read GAME.md's Design so the sprite belongs in this game's world, and look at assets/sprites.json and the files it names (if any) so it matches the look and palette of the sprites the game already has. "
               . $look . "Their note: {$what}. Target: {$target}.\n"
               . "Write two takes, each one file that is exactly one call plus a header comment:\n"
               . "  // {$key}: <what it is>, drawn from the producer's sketch\n  Draw.define('{$key}', {$half}, (g, f) => { ... });\n"
               . "g is a 2D canvas context already translated and scaled so that (0, 0) is the sprite's centre and {$half} is the radius it may fill; draw it facing right (angle 0) with plain canvas calls (beginPath, arc, ellipse, moveTo, lineTo, quadraticCurveTo, bezierCurveTo, closePath, fill, stroke, fillStyle, strokeStyle, lineWidth, lineCap, save/restore, translate/rotate/scale) and Math and TAU; Draw.circle, Draw.ring, Draw.roundRect and Draw.glow may be called too (they draw on g). f is the frame index, 0 for a still; for a little life (a bob, a blink, legs) you may pass { frames: 4, fps: 8 } as a fourth argument and draw by f. Nothing else: no images, no text, no document, no globals, no timers.\n"
               . "- sketches/{$id}/out-1.js, faithful: keep the drawing's shapes, proportions and placement (its 512 grid maps to the diameter {$size}: a point at (256 + d, 256 + e) is (d, e) × " . round($size / 512, 4) . "), smooth the wobbly strokes into clean filled shapes with a readable palette, add the details the note implies.\n"
               . "- sketches/{$id}/out-2.js, bolder: the same pose and layout with more character, shading and a stronger outline.\n"
               . "Both must read at {$size} px in a game (bold shapes, outlines about " . max(1, round($size / 32, 1)) . " wide, few tiny details) and pass `node --check`. Run it on each before you finish.\n"
               . "Then write sketches/{$id}/result.json as {\"ok\": true, \"files\": [\"out-1.js\", \"out-2.js\"], \"note\": \"<one line on what you drew>\"}.\n"
               . "Do not add anything to assets/ or assets/sprites.json and do not commit: the producer chooses what to keep on the sprite page. If something stops you, write sketches/{$id}/result.json as {\"ok\": false, \"error\": \"<why, in one line>\"} and stop. Do not run a soak. Reply with one line.";
        } elseif ($maker === 'svg') {
            $p = "The producer wants a game sprite drawn from a rough sketch, and you are the artist: you draw it as SVG. First read GAME.md's Design so the sprite belongs in this game's world, and look at assets/sprites.json and the files it names (if any) so it matches the look and palette of the sprites the game already has. "
               . ($has ? "Their drawing: sketches/{$id}/sketch.png (512×512, coloured lines on white; look at it with Read)" . ($strokes ? ", and sketches/{$id}/sketch.svg: the very same strokes as vector paths in a 512×512 viewBox, the geometry to build on" : '') . ". " : 'They drew nothing: go by the note alone. ')
               . "Their note: {$what}. Target: {$target}.\n"
               . "Draw two takes, each a complete SVG file:\n"
               . "- sketches/{$id}/out-1.svg, faithful: keep the drawing's shapes, proportions and placement (scale the 512 grid to the viewBox), smooth the wobbly strokes into clean closed shapes, fill them with a readable palette, add the details the note implies (eyes, wings, stripes, a tool) where the drawing hints at them.\n"
               . "- sketches/{$id}/out-2.svg, bolder: the same pose and layout with more character, shading and a stronger outline.\n"
               . "Rules for both: viewBox=\"0 0 {$size} {$size}\" with width and height {$size}, the xmlns attribute, a transparent background, only shapes, paths, groups and gradients (no <script>, no on* attributes, no <image>, no <text>, no external references, no <foreignObject>), under 30 KB, and a silhouette that reads at {$size} px in a game: bold shapes, outlines about " . max(1, round($size / 32, 1)) . " wide, few tiny details" . ($pixel ? ', flat fills in the manner of pixel art rather than soft gradients' : '') . ". Check each with `node -e` or `python3 -c` that it parses as XML before you finish.\n"
               . "Then write sketches/{$id}/result.json as {\"ok\": true, \"files\": [\"out-1.svg\", \"out-2.svg\"], \"note\": \"<one line on what you drew>\"}.\n"
               . "Do not add anything to assets/ or assets/sprites.json and do not commit: the producer chooses what to keep on the sprite page. If something stops you, write sketches/{$id}/result.json as {\"ok\": false, \"error\": \"<why, in one line>\"} and stop. Do not run a soak. Reply with one line.";
        } else
        $p = "The producer wants a sprite made" . ($has ? " from a rough freehand drawing they made: sketches/{$id}/sketch.png (512×512, black lines on white, a rough shape and pose, not finished art)" : '') . ". Their note: {$what}. Target: a game sprite, {$target}.\n"
           . "Using the spritecook skills (read spritecook-workflow-essentials first): " . ($has ? "upload the drawing with spritecook-upload-assets and call generate_game_art with reference_asset_id = that asset, so the drawing sets the subject's pose, shape and layout; " : 'call generate_game_art; ')
           . "a prompt built from the note" . ($has ? ' and what the drawing shows' : '') . ", width and height {$size}, pixel=" . ($pixel ? 'true' : 'false') . ", bg_mode transparent, variations=2. "
           . "Download each result's sprite_url to sketches/{$id}/out-1.png and sketches/{$id}/out-2.png. Then write sketches/{$id}/result.json as {\"ok\": true, \"files\": [\"out-1.png\", \"out-2.png\"], \"note\": \"<one line on what you made>\"}.\n"
           . "Do not add anything to assets/ or assets/sprites.json and do not commit: the producer chooses what to keep on the sprite page. If anything stops you (SpriteCook needs authentication, no credits, an error), write sketches/{$id}/result.json as {\"ok\": false, \"error\": \"<why, in one line>\"} and stop. Do not run a soak. Reply with one line.";
        if ($vector) { // pixels to vector: the same picture as clean shapes
            $p = "VECTOR. The producer drew sketches/{$id}/{$vector['base']} themselves, pixel by pixel ({$vector['w']}×{$vector['h']}); look at it with Read. They want the same picture as SVG: the same subject, proportions, placement, colours and details, as clean vector shapes, so it scales without pixels. " . ($note !== '' ? "Their note: \"{$note}\". " : '') . "Read GAME.md's Design so it stays in this game's world.\n"
               . "Draw two takes, each a complete SVG: sketches/{$id}/out-1.svg, faithful (every shape where the pixels have it, edges smoothed into curves and straight lines, the palette kept exactly: sample the colours from the picture), and sketches/{$id}/out-2.svg, cleaned up (the same drawing with a tidier silhouette, a consistent outline and one or two details resolved that the pixels could only hint at).\n"
               . "Rules for both: viewBox=\"0 0 {$size} {$size}\" with width and height {$size}, the xmlns attribute, a transparent background, only shapes, paths, groups and gradients (no <script>, no on* attributes, no <image>, no <text>, no external references, no <foreignObject>), under 30 KB, readable at {$size} px. Check each parses as XML before you finish.\n"
               . "Then write sketches/{$id}/result.json as {\"ok\": true, \"files\": [\"out-1.svg\", \"out-2.svg\"], \"note\": \"<one line>\"}. Do not add anything to assets/ or assets/sprites.json and do not commit. If something stops you, write result.json as {\"ok\": false, \"error\": \"<why>\"} and stop. Do not run a soak. Reply with one line.";
        } elseif ($rigjob) { // the skeleton: body plan inferred from the form, bones fitted, parts split, two motions
            $half = $size / 2; $base = $rigjob['base']; $bext = pathinfo($base, PATHINFO_EXTENSION);
            $p = "RIG. The producer wants the character in sketches/{$id}/{$base} turned into a skeleton rig: bones fitted to its body, its drawing split into parts bound to the bones, and two motions, so that any motion made for this body plan can play on it. " . ($bext === 'png' ? 'Look at the picture with Read. ' : 'Read the file: it is the character, its shapes and its palette. ') . ($rigjob['notes'] !== '' ? "Their notes: \"{$rigjob['notes']}\". " : '')
               . "Read ../../kit/js/kit-rig.js first: its header is the exact format (Rig.define, bones with rest angles, two-bone IK chains with bend, parts drawn in bone space, motions as keyframes) and ../../kit/AGENTS.md's Sprites section the rules. Read GAME.md's Design so the motion suits the game.\n"
               . (!empty($rigjob['hints']['joints']) ? "THE PRODUCER MARKED THE SKELETON on the drawing. Body plan: {$rigjob['hints']['plan']}. Joints, in the rig's own space (centre (0, 0), y down, the figure fitting radius " . round((float) $rigjob['hints']['half'], 1) . "; scale them by " . round($half / max(1, (float) $rigjob['hints']['half']), 3) . " to this rig's half {$half}), each named for the bone that ENDS at it and attached to its parent joint:\n" . implode("\n", array_map(fn($j) => "  - {$j['name']} at ({$j['x']}, {$j['y']})" . ($j['parent'] !== '' ? " from {$j['parent']}" : ' (root)') . ($j['note'] !== '' ? " — \"{$j['note']}\"" : ''), $rigjob['hints']['joints'])) . "\nFit the bones to THESE positions (lengths and rest angles follow from them, bone names from the marks, parent links as marked; a joint named like a knee or elbow with a foot or hand beyond it is a two-bone IK chain) and follow each joint's note. Marks win over your own reading of the drawing.\n" : '')
               . "1. Decide the BODY PLAN from the form" . (!empty($rigjob['hints']['joints']) ? ' (the producer said: ' . $rigjob['hints']['plan'] . ')' : '') . ", and say which in the header comment: biped (spine, head, two arms, two legs; IK chains for arms and legs; feet are the ground contacts), quadruped (spine with hips and shoulders, neck and head, four legs with IK, a tail as a chain of two or three bones), winged (a biped or quadruped plus wing chains), serpent (one spine chain, no IK), or none (a vehicle, a blob, a projectile: then write result.json as {\"ok\": false, \"error\": \"no skeleton suits this form: animate it as code frames instead\"} and stop).\n"
               . "2. Fit the bones to the drawing: the root at the hips (or the body's centre of mass), lengths and rest angles so the skeleton's rest pose lies on the drawn figure, facing RIGHT, the whole figure within radius {$half} with the feet near the bottom. Chains: bend 1 for a knee (forward), -1 for an elbow (back); a quadruped's rear legs bend the other way from its front legs; give each chain a fold limit in degrees from straight, e.g. fold: [0, 150] for knees and elbows, [0, 120] for a stiff hock, so the editor's posing stops where the joint would.\n"
               . "3. Split the drawing into parts bound to bones, drawn in bone space ((0, 0) the joint, the bone along +x to (b.len, 0)) with plain canvas calls in the character's own palette and outline weight; z orders back to front (far limbs behind the torso, near limbs and the head in front). Keep the character's look exactly: colours, proportions, details (eyes, mask, scarf, weapon). A part ONLY draws on g: it is given the bone's world transform (w) and the pose (P) and must not call Rig.solve, Rig.pose, Rig.rigs or Draw.rig itself (the editor renders takes under other names, and such a call finds nothing).\n"
               . "4. Motions: an idle (slow breath, a small bob) and a locomotion matching the plan: run for a biped, trot for a quadruped, fly for winged, slither for a serpent; legs by IK foot targets placed within reach (the chain's two lengths), loops seamless, arms counter-swinging, root bobbing. Keys at t in 0..1 with pose, root and ik.\n"
               . "Write ONE file, sketches/{$id}/out-1.js: a header comment naming the plan, then exactly one Rig.define('{$key}', { half: {$half}, bones, chains, parts, motions }). Nothing but drawing in it: no images, no text, no document, no globals, no timers. Then check it: `node --check sketches/{$id}/out-1.js`, and run this harness from the game folder, fixing anything it reports until it prints ok:\n"
               . "  node -e \"const TAU=Math.PI*2;global.TAU=TAU;const fs=require('fs');const Rig=new Function(fs.readFileSync('../../kit/js/kit-rig.js','utf8')+'\\nreturn Rig;')();const g=new Proxy({},{get:()=>()=>0,set:()=>true});new Function('Rig','TAU',fs.readFileSync('sketches/{$id}/out-1.js','utf8'))(Rig,TAU);const R=Rig.rigs.get('{$key}');let bad=0;for(const m of Object.keys(R.motions)){const F=Rig.frames(R.motions[m]);for(let f=0;f<F;f++){const P=Rig.pose(R.motions[m],f/F,R),W=Rig.solve(R,P);for(const [cn,ch] of Object.entries(R.chains)){const T=P.ik[cn];if(!T)continue;const e=W[ch.bones[1]];const miss=Math.hypot(e.tx-(T.x+P.root.x),e.ty-(T.y+P.root.y));if(miss>0.5){bad++;console.log(m,'frame',f,cn,'target out of reach by',miss.toFixed(1));}}for(const w of Object.values(W))for(const v of [w.x,w.y,w.angle,w.tx,w.ty])if(!Number.isFinite(v)){bad++;console.log(m,f,'non-finite');}Rig.paint(R,m,f/F,g);}}console.log(bad?'problems: '+bad:'ok',Object.keys(R.bones).length,'bones',Object.keys(R.motions).join(','));\"\n"
               . "Then write sketches/{$id}/result.json as {\"ok\": true, \"files\": [\"out-1.js\"], \"note\": \"<the plan, the bones, the motions, in one line>\"}. Do not add anything to assets/ or assets/sprites.json and do not commit. If something stops you, write result.json as {\"ok\": false, \"error\": \"<why>\"} and stop. Do not run a soak. Reply with one line.";
        } elseif ($anim && str_contains((string) @file_get_contents("$dir/{$anim['base']}"), 'Rig.define(')) { // a motion for an existing rig: keyframes only
            $base = $anim['base']; $n = $anim['frames']; $fps = $anim['fps']; $secs = round($n / max(1, $fps), 2);
            $p = "A MOTION FOR A RIG. sketches/{$id}/{$base} is a skeleton rig (read ../../kit/js/kit-rig.js for the format). The producer wants a \"{$anim['name']}\" motion added to it: about {$n} frames at {$fps} fps ({$secs} s). " . ($anim['notes'] !== '' ? "Their notes: \"{$anim['notes']}\". " : '') . "Read GAME.md's Design so the motion suits the game.\n"
               . "Change nothing but motions: keep every bone, chain and part exactly as they are. Write sketches/{$id}/out-1.js as the SAME Rig.define with motions.{$anim['name']} added (replacing one of that name): { secs: {$secs}, fps: {$fps}, loop: " . (in_array($anim['name'], ['run', 'walk', 'idle', 'fly', 'swim'], true) ? 'true' : 'false') . ", keys: [ … ] }, four to eight keys at t in 0..1 with pose (angles added to the rest angles), root and ik targets for hands and feet (within each chain's reach; feet on the ground when they should be). "
               . ($anim['name'] === 'attack' ? 'An attack: anticipation (weight back, weapon arm drawn), the strike (arm extended, torso twisted into it, a lunge of the root), then recovery back toward the rest pose. ' : '') . ($anim['name'] === 'jump' ? 'A jump: crouch, launch with arms up, hang with legs tucked, land with a squash. ' : '') . ($anim['name'] === 'hurt' ? 'A hurt: a jolt back, head and arms thrown, a stagger. ' : '') . ($anim['name'] === 'death' ? 'A death: a collapse to the ground, no loop. ' : '')
               . "Run `node --check sketches/{$id}/out-1.js`, then the harness from the game folder, fixing anything it reports until it prints ok:\n"
               . "  node -e \"const TAU=Math.PI*2;global.TAU=TAU;const fs=require('fs');const Rig=new Function(fs.readFileSync('../../kit/js/kit-rig.js','utf8')+'\\nreturn Rig;')();const g=new Proxy({},{get:()=>()=>0,set:()=>true});new Function('Rig','TAU',fs.readFileSync('sketches/{$id}/out-1.js','utf8'))(Rig,TAU);const R=[...Rig.rigs.values()][0];let bad=0;for(const m of Object.keys(R.motions)){const F=Rig.frames(R.motions[m]);for(let f=0;f<F;f++){const P=Rig.pose(R.motions[m],f/F,R),W=Rig.solve(R,P);for(const [cn,ch] of Object.entries(R.chains)){const T=P.ik[cn];if(!T)continue;const e=W[ch.bones[1]];const miss=Math.hypot(e.tx-(T.x+P.root.x),e.ty-(T.y+P.root.y));if(miss>0.5){bad++;console.log(m,'frame',f,cn,'out of reach by',miss.toFixed(1));}}Rig.paint(R,m,f/F,g);}}console.log(bad?'problems: '+bad:'ok',Object.keys(R.motions).join(','));\"\n"
               . "Then write sketches/{$id}/result.json as {\"ok\": true, \"files\": [\"out-1.js\"], \"note\": \"<the motion in one line>\"}. Do not add anything to assets/ or assets/sprites.json and do not commit. If something stops you, write result.json as {\"ok\": false, \"error\": \"<why>\"} and stop. Do not run a soak. Reply with one line.";
        } elseif ($anim) { // the rig: the character as parts, posed per frame
            $half = $size / 2; $n = $anim['frames']; $fps = $anim['fps']; $base = $anim['base']; $bext = pathinfo($base, PATHINFO_EXTENSION);
            $p = "ANIMATION. The producer wants the character in sketches/{$id}/{$base} animated: a \"{$anim['name']}\" cycle, {$n} frames at {$fps} fps, as a code sprite. " . ($bext === 'png' ? 'Look at the picture with Read. ' : 'Read the file: it is the character, its shapes and its palette. ') . ($anim['notes'] !== '' ? "Their notes: \"{$anim['notes']}\". " : '') . ($note !== '' && $note !== $anim['notes'] ? "Their description: \"{$note}\". " : '')
               . "First read GAME.md's Design so the motion suits the game.\n"
               . "Write two takes, each one file that is exactly one call plus a header comment and any helper functions declared INSIDE the paint function:\n"
               . "  // {$key}: <the character>, {$anim['name']} cycle, drawn from the producer's sprite\n  Draw.define('{$key}', {$half}, (g, f) => { ... }, { frames: {$n}, fps: {$fps} });\n"
               . "g is a 2D canvas context translated and scaled so that (0, 0) is the sprite's centre and {$half} the radius it may fill; draw facing RIGHT (the game mirrors it to face left). Re-express the character as canvas drawing code with its body split into parts (head, torso, each arm, each leg, hair, scarf, weapon or prop as the design has them), each drawn by a small helper at its own origin, then placed per frame with g.save/translate/rotate/restore. f runs 0..{$n}; the phase is f / {$n} and the cycle must loop seamlessly (frame {$n} would equal frame 0). "
               . ($anim['name'] === 'run' || $anim['name'] === 'walk' ? "A {$anim['name']} cycle: legs alternate through contact, down, passing and up poses (the second half mirrors the first with the legs swapped), arms swing opposite to the legs, the torso leans forward and bobs up and down twice per cycle, hair or scarf trail and whip; keep the feet near the bottom of the radius. " : '')
               . ($anim['name'] === 'idle' ? 'An idle: a slow breath (torso scale and head bob), a blink or a shift of weight, subtle, looping. ' : '') . ($anim['name'] === 'jump' ? 'A jump: crouch, launch with arms up, hang with legs tucked, land with a squash, loop or hold the last frame. ' : '') . ($anim['name'] === 'attack' ? 'An attack: anticipation, the strike with the weapon arm fully extended and a motion smear, recovery. ' : '')
               . "Keep the character's palette, silhouette and proportions from the base; plain canvas calls (beginPath, arc, ellipse, moveTo, lineTo, quadraticCurveTo, bezierCurveTo, closePath, fill, stroke, fillStyle, strokeStyle, lineWidth, lineCap, lineJoin, save/restore, translate/rotate/scale) with Math and TAU; Draw.circle, Draw.ring, Draw.roundRect and Draw.glow may be called too. Nothing else: no images, no text, no document, no globals, no timers. Use Math.sin/cos of the phase for smooth motion, not if-chains per frame.\n"
               . "- sketches/{$id}/out-1.js: the clean cycle, readable at {$size} px (bold shapes, outlines about " . max(1, round($size / 32, 1)) . " wide).\n- sketches/{$id}/out-2.js: the same cycle, more exaggerated: deeper lean, longer stride, more follow-through.\n"
               . "Both must pass `node --check`; run it on each before you finish. Then write sketches/{$id}/result.json as {\"ok\": true, \"files\": [\"out-1.js\", \"out-2.js\"], \"note\": \"<one line on the motion>\"}.\n"
               . "Do not add anything to assets/ or assets/sprites.json and do not commit: the producer keeps what they like on the sprite page. If something stops you, write result.json as {\"ok\": false, \"error\": \"<why>\"} and stop. Do not run a soak. Reply with one line.";
        } elseif ($refine) { // the same craft, but starting from the earlier take rather than from the sketch
            $ext = $refine['ext'];
            $p = "REFINEMENT. The producer looked at a take you made earlier and wants one change. The take: sketches/{$id}/base.{$ext}" . ($ext === 'png' ? '' : ' (read it: it is the starting point, not the sketch)') . ". Their instruction: \"{$note}\". The original drawing and note are there for context only" . ($has ? " (sketches/{$id}/sketch.png)" : '') . ".\n"
               . "Make sketches/{$id}/out-1." . ($ext === 'png' ? 'png' : $ext) . " the earlier take with exactly that change and nothing else changed, and out-2 the same change taken a step further. "
               . ($ext === 'png' ? "Use the spritecook skills: upload base.png with spritecook-upload-assets and generate with edit_asset_id = that asset and the instruction as the prompt, variations=2, then download the results. " : "Keep the file's format, size and rules as before (" . ($ext === 'svg' ? "a complete SVG, viewBox 0 0 {$size} {$size}, only shapes, no script, no images, no text" : "exactly one Draw.define('{$key}', " . ($size / 2) . ", (g, f) => { ... }) that only draws, passing node --check") . "). ")
               . "Then write sketches/{$id}/result.json as {\"ok\": true, \"files\": [\"out-1.{$ext}\", \"out-2.{$ext}\"], \"note\": \"<one line on what changed>\"}. Do not add anything to assets/ or assets/sprites.json and do not commit. If something stops you, write result.json as {\"ok\": false, \"error\": \"<why>\"} and stop. Do not run a soak. Reply with one line.";
        }
        $removes = count(array_filter($strokes, fn($s) => $s['pen'] === 'remove'));
        if ($removes) $p .= "\nREMOVE MARKS. The drawing has {$removes} magenta (#ff2bd6) stroke" . ($removes > 1 ? 's' : '') . " (data-mark=\"remove\" in sketch.svg). Magenta is never a colour to paint: it marks what to DELETE. Whatever lies under a magenta scribble is to be gone from the result, the spot left empty or closed over by the shapes around it, with nothing added in its place unless another mark or the note says so.";
        if ($from && !$refine) {
            $p .= "\nEARLIER ROUNDS. This drawing is the producer's revision of one they sent before. The takes you made then are here: " . implode(', ', array_map(fn($f) => "sketches/{$id}/{$f}", $from['files'])) . ($from['note'] !== '' ? " (your note then: \"{$from['note']}\")" : '') . '. '
                . ($from['liked'] !== '' ? "They liked {$from['liked']} best: start from its craft, palette and character, " : 'Start from the craft, palette and character of those, ')
                . (!empty($from['over']) ? "and this time they drew DIRECTLY ON TOP of {$from['liked']}: sketch.png shows your take, faded, with their marks over it, and sketch.svg holds only their marks (in the take's 512-grid frame; the take fills the middle of it). Read each mark as an edit of that spot: lines drawn over a part of the take are the NEW shape of that part and REPLACE it (new arms drawn over the old arms means: these arms instead of those, not both); lines where the take had nothing add a part there; a scribble over something means remove or rework it; the note says the rest. Everything they did not mark stays exactly as it was in the take, down to the colours."
                   : "and apply what the new drawing and note change (compare the drawing with what you drew before: what moved, grew, shrank, appeared or vanished is the point of this round). Keep what they did not change.");
        }
        $facts = self::jobFacts($req); $what = $facts['kind'] === 'rig' ? 'rig the character ' . ($facts['asset'] ?: $key) : ($facts['kind'] === 'animate' ? "a {$facts['cycle']} motion for " . ($facts['asset'] ?: $key) : ($facts['kind'] === 'vector' ? "{$facts['asset']} as vector, kept as {$key}" : ($facts['kind'] === 'refine' ? "refine {$key}" : ($facts['kind'] === 'revision' ? "a revision of {$key}" : "a sprite: {$key}"))));
        self::emit($slug, 'prompt', ['text' => "Sprite job: {$what}" . ($note !== '' ? "\n{$note}" : '') . ($has ? "\n(with a drawing)" : ''), 'mode' => 'art', 'by' => self::memberName($memberId), 'job' => $id]);
        $r = self::startArt($slug, $p, $memberId); // the art lane: beside the builder, never inside its run
        return ['id' => $id, 'key' => $key, 'at' => $req['at'], 'queued' => !empty($r['queued']), 'rig' => (bool) $rigjob, 'animate' => (bool) $anim];
    }
    /** Every sketch job of a game, newest first, with its takes. */
    public static function listSketches(string $slug): array {
        $root = self::dir($slug) . '/sketches'; $out = [];
        foreach (is_dir($root) ? scandir($root, SCANDIR_SORT_DESCENDING) : [] as $id) {
            if (!preg_match(self::SKETCH_ID, $id) || !is_file("$root/$id/request.json")) continue;
            $s = self::sketchStatus($slug, $id); $req = json_decode((string) file_get_contents("$root/$id/request.json"), true) ?: [];
            $out[] = self::jobFacts($req) + ['id' => $id, 'at' => (int) ($req['at'] ?? 0), 'note' => (string) ($req['note'] ?? ''), 'key' => $s['key'], 'maker' => (string) ($req['maker'] ?? 'spritecook'), 'size' => (int) ($req['size'] ?? 0), 'sketch' => is_file("$root/$id/sketch.png"), 'strokes' => is_file("$root/$id/strokes.json"), 'refine' => $req['refine'] ?? null, 'from' => $req['from'] ?? null, 'animate' => $req['animate'] ?? null, 'rig' => $req['rig'] ?? null, 'saved' => $s['saved'], 'style' => (string) ($req['style'] ?? ''), 'pixel' => !empty($req['pixel']), 'base' => $s['base'], 'result' => $s['result']];
        }
        return ['jobs' => $out, 'busy' => self::artState($slug)['busy']];
    }
    public static function deleteSketch(string $slug, string $id): void {
        if (!preg_match(self::SKETCH_ID, $id)) throw new \InvalidArgumentException('not a sketch id');
        $dir = self::sketchDir($slug, $id); if (!is_dir($dir)) throw new \InvalidArgumentException('no such sketch');
        foreach (scandir($dir) as $f) if ($f[0] !== '.' && is_file("$dir/$f")) unlink("$dir/$f");
        rmdir($dir);
    }
    /** A take the page may show: an svg without script, a js that only draws (checked like a saved code sprite), a png. */
    private static function takeIsSafe(string $path): bool {
        if (str_ends_with($path, '.svg')) return !preg_match('/<script|\son[a-z]+\s*=|javascript:|<foreignObject|<image/i', (string) file_get_contents($path));
        if (str_ends_with($path, '.js')) { try { self::checkSpriteCode((string) file_get_contents($path)); return true; } catch (\InvalidArgumentException $e) { error_log("ERROR sprite take {$path} refused: " . $e->getMessage()); return false; } }
        return true;
    }
    /** A job's kind, its base file (for a rig, an animation or a refinement) and the sprite it started from, from its request. */
    public static function jobFacts(array $req): array {
        $kind = !empty($req['rig']) ? 'rig' : (!empty($req['animate']) ? 'animate' : (!empty($req['vector']) ? 'vector' : (!empty($req['refine']) ? 'refine' : (!empty($req['from']) ? 'revision' : 'sketch'))));
        $base = $req['rig']['base'] ?? $req['animate']['base'] ?? $req['vector']['base'] ?? (!empty($req['refine']['ext']) ? 'base.' . $req['refine']['ext'] : '');
        return ['kind' => $kind, 'basefile' => (string) $base, 'asset' => (string) ($req['rig']['asset'] ?? $req['animate']['asset'] ?? $req['vector']['asset'] ?? ''), 'cycle' => (string) ($req['animate']['name'] ?? '')];
    }
    public static function sketchStatus(string $slug, string $id): array {
        if (!preg_match(self::SKETCH_ID, $id)) throw new \InvalidArgumentException('not a sketch id');
        $dir = self::sketchDir($slug, $id); if (!is_file($dir . '/request.json')) throw new \InvalidArgumentException('no such sketch');
        $req = json_decode((string) file_get_contents($dir . '/request.json'), true) ?: [];
        if (!is_file($dir . '/result.json') && empty($req['saved']) && !self::artState($slug)['busy']) { // the artist finished without its result file: its takes on disk are the result
            $found = array_values(array_filter(scandir($dir), fn($f) => preg_match('/^out-[0-9]+\.(png|svg|js)$/', $f))); sort($found);
            if ($found && filemtime($dir . '/' . $found[0]) < time() - 20) self::writeAtomic($dir . '/result.json', json_encode(['ok' => true, 'files' => $found, 'note' => 'the artist finished without leaving a note; these are its takes'], JSON_UNESCAPED_SLASHES));
        }
        $res = is_file($dir . '/result.json') ? json_decode((string) file_get_contents($dir . '/result.json'), true) : null;
        if (is_file($dir . '/result.json') && !is_array($res)) $res = ['ok' => false, 'error' => 'the builder wrote a result.json that is not JSON'];
        if (is_array($res) && !empty($res['ok'])) $res['files'] = array_values(array_filter((array) ($res['files'] ?? []), fn($f) => preg_match('/^out-[0-9]+\.(png|svg|js)$/', (string) $f) && is_file($dir . '/' . $f) && self::takeIsSafe($dir . '/' . $f)));
        $busy = self::artState($slug)['busy'];
        $saved = !empty($req['saved']) && $res === null;
        $qf = self::studioDir($slug) . '/art-queue.jsonl'; $queued = is_file($qf) && str_contains((string) file_get_contents($qf), "sketches/{$id}/");
        return self::jobFacts($req) + ['id' => $id, 'key' => (string) ($req['key'] ?? 'sprite'), 'note' => (string) ($req['note'] ?? ''), 'at' => (int) ($req['at'] ?? 0), 'base' => "/games/{$slug}/sketches/{$id}/", 'busy' => $busy || $queued, 'saved' => $saved, 'status' => $saved ? 'a saved drawing, not sent to the builder' : ($queued ? 'waiting its turn behind another sprite job' : ($busy ? 'the artist is working…' : 'waiting for the artist to start')), 'result' => $res];
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
        foreach (glob(self::root() . '/kit/js/*.js') as $f) if (basename($f) !== 'kit-notes.js') copy($f, $dist . '/js/' . basename($f));
        if (is_dir($d . '/icons')) self::copyTree($d . '/icons', $dist . '/icons');
        if (is_dir($d . '/assets')) self::copyTree($d . '/assets', $dist . '/assets'); // sprites and pictures the game draws
        $cv = self::cover($slug); foreach (['jpg', 'png'] as $x) if ($cv !== '' && is_file("$d/.studio/soaks/$cv.$x")) copy("$d/.studio/soaks/$cv.$x", "$dist/cover.$x"); // the store card's pictures ship with the game
        $gf = self::gif($slug); if ($gf !== '') copy("$d/.studio/soaks/$gf.gif", "$dist/cover.gif");
        if (is_file($d . '/manifest.webmanifest')) copy($d . '/manifest.webmanifest', $dist . '/manifest.webmanifest');
        self::writeAtomic($dist . '/js/version.js', 'const BUILD = ' . json_encode(['id' => $bid, 'date' => date('Y-m-d'), 'channel' => 'stable']) . ";\n");
        $html = str_replace('../../kit/js/', 'js/', (string) file_get_contents($d . '/index.html'));
        $html = preg_replace('/^.*<script src="[^"]*kit-notes\.js"><\/script>.*\n?/m', '', $html); // note mode is the forge\'s tool, not the player\'s
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
