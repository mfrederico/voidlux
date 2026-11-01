<?php
/**
 * Releases — what makes publishing safe to do. Before a build goes live a person sees what is live, what would replace it and
 * what is doubtful about it (check); it is refused while the builder is mid-run, or when what they reviewed has since changed.
 * The live site's files are kept before each publish (data/releases/<slug>/<id>/, the last KEEP, hard-linked so that unchanged
 * files cost nothing), and any of them can be put back in seconds (restore). Only the live site changes: not the game's source.
 */
namespace app;

class Releases {
    public const KEEP = 5;
    private const ID = '/^[0-9]{8}-[0-9]{6}-[A-Za-z0-9-]{1,40}$/D';

    private static function dir(string $slug): string { return GameStore::root() . '/data/releases/' . $slug; }
    /** The live site's folder ('' when the forge has no [forge] domain to publish to). */
    public static function liveDir(string $slug): string {
        $domain = (string) GameStore::cfg('domain', ''); if ($domain === '') return '';
        return rtrim((string) GameStore::cfg('publish_root', '/var/www/html/default'), '/') . '/' . $slug . '.' . preg_replace('/\.[a-z]+$/', '', $domain) . '/public';
    }
    private static function buildIn(string $dir): string { $v = @file_get_contents("$dir/js/version.js"); return $v !== false && preg_match('/"id":"([^"]+)"/', $v, $m) ? $m[1] : ''; }
    /** What would be published now, as a string that changes when the game does. */
    public static function nextBuild(string $slug): string {
        $d = GameStore::dir($slug); $sha = trim(GameStore::git($d, ['rev-parse', '--short', 'HEAD'])[1]) ?: 'dev'; $st = trim(GameStore::git($d, ['status', '--porcelain'])[1]);
        return $sha . ($st !== '' ? '-dirty-' . substr(sha1($st), 0, 6) : '');
    }
    /** All a person needs to decide: live, next, what stops it (blockers), what is doubtful (warnings), the earlier builds. */
    public static function check(object $g): array {
        $slug = (string) $g->slug; $d = GameStore::dir($slug); $blockers = []; $warnings = [];
        if (GameStore::agentState($slug)['busy']) $blockers[] = 'The builder is working on this game right now, so half-finished work would go live. Wait for it to finish, or stop it.';
        $next = self::nextBuild($slug); $dirty = str_contains($next, '-dirty');
        [, $subject] = GameStore::git($d, ['log', '-1', '--format=%s']); $commitAt = (int) trim(GameStore::git($d, ['log', '-1', '--format=%ct'])[1]);
        if ($dirty) $warnings[] = 'It includes changes not saved in the game\'s history yet (editor saves or uncommitted work).';
        $soaks = GameStore::soaks($slug); $last = $soaks[0] ?? null;
        if (!$last) $warnings[] = 'It has never been playtested.';
        else { if (empty($last['ok'])) $warnings[] = 'Its last playtest failed' . (!empty($last['status']) && $last['status'] !== 'ok' ? ' (' . $last['status'] . ')' : '') . '.';
            // what a player would get, not when something was committed: builders commit just after their last playtest, and a note in GAME.md changes nothing that ships
            $f = GameStore::dir($slug) . '/.studio/soaks/' . ($last['id'] ?? '') . '.json'; $seen = is_file($f) ? (string) ((json_decode((string) file_get_contents($f), true) ?: [])['shipped'] ?? '') : '';
            if ($seen !== '' ? $seen !== self::fingerprint($slug) : ($commitAt > (int) @filemtime($f))) $warnings[] = 'What would go live has changed since its last playtest (' . date('M j H:i', (int) @filemtime($f)) . ').'; }
        $live = self::liveDir($slug);
        return ['live' => $live !== '' && is_file("$live/index.html") ? ['build' => self::buildIn($live) ?: (string) $g->publishedBuild, 'at' => (string) $g->publishedAt, 'url' => (string) $g->publishedUrl] : null,
            'next' => ['build' => $next, 'subject' => trim((string) $subject), 'url' => $live !== '' ? 'https://' . $slug . '.' . GameStore::cfg('domain') . '/' : ''], 'blockers' => $blockers, 'warnings' => $warnings, 'earlier' => self::earlier($slug), 'keep' => self::KEEP];
    }
    /** A hash of the files that go live (index.html, js/, assets/, icons/, the manifest). The forge's playtest tooling is left out. */
    public static function fingerprint(string $slug): string {
        $d = GameStore::dir($slug); $parts = [];
        foreach (['index.html', 'manifest.webmanifest'] as $f) if (is_file("$d/$f")) $parts[$f] = sha1_file("$d/$f");
        foreach (['js', 'assets', 'icons'] as $sub) { if (!is_dir("$d/$sub")) continue;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$d/$sub", \FilesystemIterator::SKIP_DOTS)) as $x) { $n = substr($x->getPathname(), strlen($d) + 1); if ($x->isFile() && !in_array($n, ['js/forge-soak.js', 'js/soak-store.js'], true)) $parts[$n] = sha1_file($x->getPathname()); } }
        ksort($parts); return sha1(json_encode($parts));
    }
    /** Refuses a publish that is not what was reviewed, or that must not go now. */
    public static function guard(object $g, string $expect): void {
        $c = self::check($g); if ($c['blockers']) throw new \RuntimeException($c['blockers'][0]);
        if ($expect === '') throw new \InvalidArgumentException('Publishing needs a review first: ask for the check, then send what it showed as expect.');
        if ($expect !== $c['next']['build']) throw new \RuntimeException('The game has changed since you reviewed it. Look again before publishing.');
    }
    /** Every script of a build must parse: a typo must not take a live game down. Throws naming the file. */
    public static function checkBuild(string $dist): void {
        $nb = (string) GameStore::cfg('node_bin', ''); $node = $nb !== '' ? rtrim($nb, '/') . '/node' : 'node';
        foreach (glob("$dist/js/*.js") ?: [] as $f) { $o = []; exec(escapeshellarg($node) . ' --check ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
            if ($rc !== 0) throw new \RuntimeException('Not published: js/' . basename($f) . ' has an error, and the live game is unchanged. ' . mb_substr(trim(str_replace($dist . '/', '', implode(' ', array_slice($o, 0, 5)))), 0, 400)); }
        if (!is_file("$dist/index.html")) throw new \RuntimeException('Not published: the build has no index.html.');
    }
    /** The live site as it is, kept before it is replaced. Nothing live yet: nothing to keep. */
    public static function keep(object $g, string $by = '', string $spare = ''): ?string {
        $slug = (string) $g->slug; $live = self::liveDir($slug); if ($live === '' || !is_file("$live/index.html")) return null;
        $dir = self::dir($slug); if (!is_dir($dir)) mkdir($dir, 0775, true);
        $build = self::buildIn($live) ?: 'unknown'; $id = date('Ymd-His') . '-' . preg_replace('/[^A-Za-z0-9-]/', '', $build);
        $prev = self::ids($slug)[0] ?? null; $link = $prev ? ' --link-dest=' . escapeshellarg("$dir/$prev") : '';
        exec('rsync -a --checksum --delete' . $link . ' ' . escapeshellarg($live . '/') . ' ' . escapeshellarg("$dir/$id/") . ' 2>&1', $o, $rc);
        if ($rc !== 0) throw new \RuntimeException('Not published: the live build could not be kept first, so nothing changed. ' . mb_substr(implode(' ', $o), 0, 300));
        GameStore::writeAtomic("$dir/$id.json", json_encode(['id' => $id, 'build' => $build, 'liveAt' => (string) $g->publishedAt, 'until' => date('c'), 'by' => $by], JSON_UNESCAPED_SLASHES));
        foreach (array_slice(self::ids($slug), self::KEEP) as $old) { if ($old === $spare) continue; exec('rm -rf ' . escapeshellarg("$dir/$old")); @unlink("$dir/$old.json"); }
        return $id;
    }
    /** @return string[] kept builds, newest first */
    private static function ids(string $slug): array { $d = self::dir($slug); $out = []; foreach (is_dir($d) ? scandir($d, SCANDIR_SORT_DESCENDING) : [] as $f) if (preg_match(self::ID, $f) && is_dir("$d/$f")) $out[] = $f; return $out; }
    public static function earlier(string $slug): array { $d = self::dir($slug); return array_map(fn($id) => (json_decode((string) @file_get_contents("$d/$id.json"), true) ?: []) + ['id' => $id, 'build' => '', 'liveAt' => '', 'until' => ''], self::ids($slug)); }
    /** A kept build goes live again; what was live is kept. The game's source and history are not touched. */
    public static function restore(object $g, string $id, string $by): array {
        $slug = (string) $g->slug; if (!preg_match(self::ID, $id) || !is_dir(self::dir($slug) . "/$id")) throw new \InvalidArgumentException('No such earlier build.');
        $live = self::liveDir($slug); if ($live === '') throw new \InvalidArgumentException('This forge has no site to publish to ([forge] domain).');
        $src = self::dir($slug) . "/$id"; if (!is_file("$src/index.html")) throw new \RuntimeException("The kept build {$id} has no index.html: it cannot go live.");
        self::keep($g, $by, $id); // ($id itself is spared if it is the oldest)
        exec('rsync -a --checksum --delete ' . escapeshellarg($src . '/') . ' ' . escapeshellarg($live . '/') . ' 2>&1', $o, $rc);
        if ($rc !== 0) throw new \RuntimeException('Putting the build back failed: ' . mb_substr(implode(' ', $o), 0, 300));
        $build = self::buildIn($live); $g->publishedAt = date('c'); $g->publishedBuild = $build; Bean::store($g);
        GameStore::emit($slug, 'note', ['text' => "{$by} put build {$build} back live"]); GameStore::activity($slug, $by, "put the earlier build {$build} back live (the game's source is unchanged)");
        return ['build' => $build, 'url' => (string) $g->publishedUrl];
    }
}
