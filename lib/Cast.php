<?php
/**
 * Cast — a 3D game's cast of models: assets/models.json, one item per thing the game draws in 3D, each with the model kept
 * for it. Four steps, each started by a person on the sprite page's 3D tab:
 *   list   one art job reads the game's 3D code and writes the cast (what each thing is, the code that draws its stand-in,
 *          its size, colours and what the code animates on it); new items join models.json, items already there are kept
 *   make   one model job per item (one take each, through mojulo); settle() keeps each take as it lands
 *   wire   one builder run hooks every item into the game through kit/js/kit-models.js, after which a kept or revised
 *          model shows up in the game with no further run
 *   revise a model job from the item's kept mojulo recipe, with one change; its take is kept by a person
 * models.json: { version: 1, items: { <key>: { what, group, replaces, size, facing, colours, note, moves,
 *                                             file?, v?, recipe?, job?, kept? (the job whose take is in file), error? } } }
 */
namespace app;

class Cast {
    public const GROUPS = ['player', 'enemy', 'boss', 'npc', 'prop', 'pickup', 'terrain', 'other'];

    private static int $depth = 0;
    /** Runs $fn holding the cast's lock, re-entrantly: art runners ending together settle one at a time, so no change to
     *  models.json (or sprites.json, through saveSprite) is lost between a read and a write. */
    public static function locked(string $slug, callable $fn) {
        if (self::$depth > 0) { self::$depth++; try { return $fn(); } finally { self::$depth--; } }
        $lk = fopen(GameStore::studioDir($slug) . '/cast.lock', 'c'); flock($lk, LOCK_EX); self::$depth = 1;
        try { return $fn(); } finally { self::$depth = 0; flock($lk, LOCK_UN); fclose($lk); }
    }
    public static function path(string $slug): string { return GameStore::dir($slug) . '/assets/models.json'; }
    public static function read(string $slug): array {
        $p = self::path($slug); if (!is_file($p)) return ['version' => 1, 'items' => []];
        $j = json_decode((string) file_get_contents($p), true);
        if (!is_array($j)) throw new \RuntimeException("assets/models.json in {$slug} is not JSON; fix or remove it");
        $j['items'] = (array) ($j['items'] ?? []); return $j;
    }
    public static function write(string $slug, array $c): void {
        $d = GameStore::dir($slug) . '/assets'; if (!is_dir($d)) mkdir($d, 0775, true);
        ksort($c['items']); GameStore::writeAtomic(self::path($slug), json_encode(['version' => 1, 'items' => (object) $c['items']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        GameStore::git(GameStore::dir($slug), ['add', 'assets/models.json']);
    }

    /** A model was kept (from saveSprite): its item points at the file, with a version for the game's cache. */
    public static function kept(string $slug, string $key, string $file, float $size, bool $recipe, string $job = ''): void { self::locked($slug, fn() => self::keptIn($slug, $key, $file, $size, $recipe, $job)); }
    private static function keptIn(string $slug, string $key, string $file, float $size, bool $recipe, string $job = ''): void {
        $c = self::read($slug); $it = (array) ($c['items'][$key] ?? ['what' => $key, 'group' => 'other']);
        $it['file'] = $file; $it['size'] = $size; $it['v'] = substr((string) sha1_file(GameStore::dir($slug) . "/assets/$file"), 0, 10); $it['recipe'] = $recipe;
        if ($job !== '') $it['kept'] = $job; unset($it['error']);
        $c['items'][$key] = $it; self::write($slug, $c);
        try { self::strip($slug, GameStore::dir($slug) . "/assets/$file", "$key-{$it['v']}"); } catch (\Throwable $e) { error_log("ERROR strip {$slug}/{$key}: " . $e->getMessage()); }
    }

    /** The cast with each item's state, for the 3D tab. */
    public static function status(string $slug): array {
        $c = self::read($slug); $dir = GameStore::dir($slug); $hooks = self::hooks($slug); $out = [];
        foreach ($c['items'] as $k => $it) {
            $state = !empty($it['file']) && is_file("$dir/assets/{$it['file']}") ? 'kept' : 'none'; $job = (string) ($it['job'] ?? '');
            if ($job !== '' && strcmp((string) ($it['kept'] ?? ''), $job) < 0) { // a job newer than what is kept
                try { $s = GameStore::sketchStatus($slug, $job); $state = !empty($s['busy']) && empty($s['result']) ? 'making' : (!empty($s['result']['ok']) ? ($state === 'kept' ? 'kept' : 'made') : (!empty($s['result']) ? 'failed' : 'making')); if (!empty($s['result']) && empty($s['result']['ok'])) $it['error'] = (string) ($s['result']['error'] ?? 'the job failed'); }
                catch (\InvalidArgumentException $e) { $state = $state === 'kept' ? 'kept' : 'none'; } // the job was deleted
            }
            $out[$k] = $it + ['state' => $state, 'hooked' => in_array($k, $hooks, true)];
        }
        $page = is_file("$dir/index.html") ? (string) file_get_contents("$dir/index.html") : '';
        return ['items' => (object) $out, 'wired' => str_contains($page, 'kit-models.js'), 'list' => self::listJob($slug), 'three' => self::threeRevision($slug), 'slots' => GameStore::artSlots(), 'requests' => self::requests($slug), 'waiting' => array_keys(self::waitingRevisions($slug)), 'working' => (int) (GameStore::artState($slug)['working'] ?? 0)];
    }
    /** The keys the game's code asks Models.make() for. */
    public static function hooks(string $slug): array {
        $keys = []; foreach (glob(GameStore::dir($slug) . '/js/*.js') ?: [] as $f) if (preg_match_all('/Models\.make\(\s*[\'"]([a-z][a-z0-9_-]*)[\'"]/', (string) file_get_contents($f), $m)) $keys = array_merge($keys, $m[1]);
        return array_values(array_unique($keys));
    }
    /** The three.js revision the game vendors ('' when it has none). */
    public static function threeRevision(string $slug): string {
        $d = GameStore::dir($slug) . '/js/vendor'; foreach (['three.core.min.js', 'three.module.min.js', 'three.module.js'] as $f) if (is_file("$d/$f")) { $head = (string) file_get_contents("$d/$f", false, null, 0, 2000); if (preg_match('/REVISION\s*=\s*["\'](\d+)|const \w+\s*=\s*"(\d{3})"/', $head, $m)) return $m[1] !== '' ? $m[1] : $m[2]; }
        return '';
    }
    private static function listJob(string $slug): ?array {
        $f = GameStore::studioDir($slug) . '/cast-list.json'; if (!is_file($f)) return null;
        $id = (string) (json_decode((string) file_get_contents($f), true)['id'] ?? ''); if ($id === '') return null;
        try { $s = GameStore::sketchStatus($slug, $id); } catch (\InvalidArgumentException $e) { return null; }
        return ['id' => $id, 'busy' => !empty($s['busy']) && empty($s['result']), 'result' => $s['result'] ?? null];
    }

    // ---- list: the artist reads the game and writes the cast ----
    public static function startList(string $slug, int $memberId): array {
        if (($l = self::listJob($slug)) && $l['busy']) throw new \RuntimeException('the artist is listing the cast already');
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3)); $dir = GameStore::sketchDir($slug, $id); mkdir($dir, 0775, true);
        $have = array_keys(self::read($slug)['items']); $sprites = array_keys((array) GameStore::readSprites($slug)['sprites']);
        $req = ['id' => $id, 'note' => 'the 3D cast: every thing the game draws in 3D', 'key' => 'cast', 'maker' => 'cast', 'cast' => ['list' => true], 'by' => $memberId, 'at' => time()];
        GameStore::writeAtomic("$dir/request.json", json_encode($req, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        GameStore::writeAtomic(GameStore::studioDir($slug) . '/cast-list.json', json_encode(['id' => $id, 'at' => time()]));
        $groups = implode(', ', self::GROUPS);
        $p = "3D CAST. List every distinct thing this game draws in 3D, so that a 3D model can be made for each one and put in place of what draws it now. Change nothing in the game.\n"
           . "Read GAME.md, then the code that draws in 3D (the files using THREE: js/iso3d*.js or similar) and the 2D drawers it builds from (pictures inflated into pillows, stood up as cards). Follow each thing to where its stand-in is built.\n"
           . "One item per distinct look: the player's head and its body segment are two items; one monster type is one item (its colour variants go in its note); each boss; each kind of prop (tree, rock, door, grave…); each pickup kind only if it is drawn as a 3D object (flat billboards and icons stay flat: leave them out). Leave out the ground and its tiles, effects, particles, shadows, rings, bars, text and the HUD.\n"
           . "For each item: key (lowercase letters, digits and -, a plain name such as centipede-head, ant, hive-queen, tree" . ($sprites ? '; not one of the 2D sprite keys: ' . implode(', ', array_slice($sprites, 0, 80)) : '') . "), what (one line), group (one of {$groups}), replaces (file:line and the function or call that builds its stand-in now), size (world units across its largest side as the game draws it now, worked out from the code's numbers), facing (where its front points in the game's model space, e.g. +x), colours (the hex colours the code draws it with), note (what a modeller needs: its shape, parts, proportions and character, from the code and GAME.md, three or four sentences), moves (what the code animates on it: legs, eyes, a bob, a colour flash; empty if nothing).\n"
           . ($have ? 'These are in the cast already; list them again only if you have something to correct: ' . implode(', ', $have) . ".\n" : '')
           . "Write sketches/{$id}/cast.json as {\"items\": [ {\"key\": …, \"what\": …, \"group\": …, \"replaces\": …, \"size\": <number>, \"facing\": …, \"colours\": [\"#rrggbb\", …], \"note\": …, \"moves\": …}, … ]} and check it parses with `node -e`. Then write sketches/{$id}/result.json as {\"ok\": true, \"files\": [], \"note\": \"<how many items, by group, in one line>\"}. If something stops you, write result.json as {\"ok\": false, \"error\": \"<why>\"}. Do not run a soak and do not commit. Reply with one line.";
        GameStore::emit($slug, 'prompt', ['text' => 'Sprite job: the 3D cast (what the game draws in 3D)', 'mode' => 'art', 'by' => GameStore::memberName($memberId), 'job' => $id]);
        $r = GameStore::startArt($slug, $p, $memberId);
        return ['id' => $id, 'queued' => !empty($r['queued'])];
    }
    /** The list job's cast.json joins models.json: new keys are added, items already there are left as they are. Returns the keys added. */
    public static function adoptList(string $slug, string $id): array { return self::locked($slug, fn() => self::adoptListIn($slug, $id)); }
    private static function adoptListIn(string $slug, string $id): array {
        $f = GameStore::sketchDir($slug, $id) . '/cast.json'; if (!is_file($f)) return [];
        $j = json_decode((string) file_get_contents($f), true); if (!is_array($j) || !is_array($j['items'] ?? null)) throw new \RuntimeException("the cast list sketches/{$id}/cast.json is not the expected JSON");
        $c = self::read($slug); $sprites = (array) GameStore::readSprites($slug)['sprites']; $added = [];
        foreach ($j['items'] as $it) {
            if (!is_array($it)) continue; $k = strtolower(trim((string) ($it['key'] ?? ''))); if (!preg_match(GameStore::SPRITE_KEY, $k)) continue;
            if (isset($sprites[$k]) && (((array) $sprites[$k])['kind'] ?? '') !== 'model') $k .= '-3d'; // a 2D sprite has that name
            if (isset($c['items'][$k])) continue;
            $c['items'][$k] = self::clean($it); $added[] = $k;
        }
        if ($added) self::write($slug, $c);
        return $added;
    }
    private static function clean(array $it): array {
        $s = fn($v, $n) => trim(mb_substr((string) $v, 0, $n));
        return ['what' => $s($it['what'] ?? '', 200), 'group' => in_array($it['group'] ?? '', self::GROUPS, true) ? $it['group'] : 'other', 'replaces' => $s($it['replaces'] ?? '', 200),
            'size' => max(0.01, min(10000, (float) ($it['size'] ?? 1))), 'facing' => $s($it['facing'] ?? '+x', 20),
            'colours' => array_values(array_filter(array_map('strval', (array) ($it['colours'] ?? [])), fn($x) => preg_match('/^#[0-9a-f]{6}$/i', $x))),
            'note' => $s($it['note'] ?? '', 900), 'moves' => $s($it['moves'] ?? '', 300)];
    }
    /** A person's change to an item: its words and size, or dropping it from the cast (its kept model file stays in assets/). */
    public static function editItem(string $slug, string $key, array $b): array { return self::locked($slug, fn() => self::editItemIn($slug, $key, $b)); }
    private static function editItemIn(string $slug, string $key, array $b): array {
        $c = self::read($slug); if (!isset($c['items'][$key])) throw new \InvalidArgumentException("no {$key} in the cast");
        if (!empty($b['drop'])) { unset($c['items'][$key]); self::write($slug, $c); return []; }
        $it = $c['items'][$key]; $cl = self::clean(array_merge($it, array_intersect_key($b, array_flip(['what', 'note', 'size', 'group', 'replaces', 'moves']))));
        foreach (['what', 'note', 'size', 'group', 'replaces', 'moves'] as $f) if (array_key_exists($f, $b)) $it[$f] = $cl[$f];
        $c['items'][$key] = $it; self::write($slug, $c); return $it;
    }

    // ---- make: one model job per item ----
    /** Starts (or queues) a model job for each key given, or for every item with no model and no job running. Returns the keys started. */
    public static function make(string $slug, array $keys, int $memberId): array { return self::locked($slug, fn() => self::makeIn($slug, $keys, $memberId)); }
    private static function makeIn(string $slug, array $keys, int $memberId): array {
        $st = (array) self::status($slug)['items']; $c = self::read($slug); $started = [];
        if (!$keys) $keys = array_keys(array_filter($st, fn($it) => $it['state'] === 'none' || $it['state'] === 'failed'));
        foreach ($keys as $k) {
            $k = (string) $k; if (!isset($c['items'][$k])) throw new \InvalidArgumentException("no {$k} in the cast");
            if (($st[$k]['state'] ?? '') === 'making') continue;
            $it = $c['items'][$k];
            $note = ($it['what'] ?? $k) . '. ' . ($it['note'] ?? '') . (!empty($it['colours']) ? ' Its colours in the game: ' . implode(', ', $it['colours']) . '.' : '')
                  . (!empty($it['replaces']) ? " It replaces the stand-in built at {$it['replaces']}: read that code, it is the reference for its shape, proportions and colours." : '')
                  . (!empty($it['moves']) ? " The game animates on it: {$it['moves']} (model those parts as separate, clearly named meshes)." : '');
            $r = GameStore::startSketch($slug, ['maker' => 'model', 'model' => ['size' => (float) $it['size'], 'animate' => false, 'takes' => 1, 'cast' => $k], 'key' => $k, 'note' => mb_substr($note, 0, 1200), 'size' => 64], $memberId);
            $c = self::read($slug); $c['items'][$k]['job'] = $r['id']; unset($c['items'][$k]['error']); self::write($slug, $c); $started[] = $k;
        }
        return $started;
    }
    /** Finished cast jobs land: the list joins models.json, and each batch model's take is kept. Safe to call any time (the art
     *  runner's end and the sweep both do). Returns what it did. */
    public static function settle(string $slug): array { return self::locked($slug, fn() => self::settleIn($slug)); }
    private static function settleIn(string $slug): array {
        $did = [];
        if (($l = self::listJob($slug)) && !$l['busy'] && !empty($l['result']['ok'])) {
            $mark = GameStore::studioDir($slug) . '/cast-list.json'; $m = json_decode((string) file_get_contents($mark), true) ?: [];
            if (empty($m['adopted'])) { $added = self::adoptList($slug, $l['id']); $m['adopted'] = time(); GameStore::writeAtomic($mark, json_encode($m)); $did[] = 'cast: ' . count($added) . ' item(s) added'; GameStore::emit($slug, 'note', ['text' => 'the 3D cast: ' . count($added) . ' item(s) added' . ($added ? ': ' . implode(', ', $added) : '')]); }
        }
        $c = self::read($slug);
        foreach ($c['items'] as $k => $it) {
            $job = (string) ($it['job'] ?? ''); if ($job === '' || strcmp((string) ($it['kept'] ?? ''), $job) >= 0) continue; // kept from this job, or from a later one (a revision a person kept): never put an older take back
            try { $s = GameStore::sketchStatus($slug, $job); } catch (\InvalidArgumentException $e) { continue; }
            $req = json_decode((string) @file_get_contents(GameStore::sketchDir($slug, $job) . '/request.json'), true) ?: [];
            if (($req['model']['cast'] ?? '') !== $k || empty($s['result'])) continue; // a revision is kept by a person; a job still running waits
            if (empty($s['result']['ok']) || empty($s['result']['files'])) continue; // failed: status() shows its error, make() retries it
            $take = (string) $s['result']['files'][0]; if (!str_ends_with($take, '.glb')) continue;
            try { GameStore::saveSprite($slug, ['key' => $k, 'take' => ['id' => $job, 'file' => $take], 'size' => (float) $it['size'], 'source' => 'cast']); $did[] = "kept {$k}"; }
            catch (\Throwable $e) { $c = self::read($slug); $c['items'][$k]['error'] = 'not kept: ' . $e->getMessage(); self::write($slug, $c); error_log("ERROR cast {$slug}/{$k}: " . $e->getMessage()); }
        }
        return $did;
    }

    // ---- versions: a model replaced is kept as an earlier version (versions/<key>/<v>.glb with its recipe; not in git's
    //      working tree and not published), listed in its item's history, and can be put back ----
    private static function versionDir(string $slug, string $key): string { return GameStore::dir($slug) . "/versions/$key"; }
    /** The item's current model joins its history, before something replaces it. */
    public static function archive(string $slug, string $key): void { self::locked($slug, function () use ($slug, $key) {
        $c = self::read($slug); $it = $c['items'][$key] ?? null; $d = GameStore::dir($slug); if (!$it || empty($it['file']) || empty($it['v']) || !is_file("$d/assets/{$it['file']}")) return;
        $vd = self::versionDir($slug, $key); if (!is_dir($vd)) mkdir($vd, 0775, true);
        $gi = "$d/.gitignore"; $have = is_file($gi) ? (string) file_get_contents($gi) : ''; if (!preg_match('#^/?versions/?$#m', $have)) file_put_contents($gi, rtrim($have) . ($have === '' ? '' : "\n") . "versions/\n");
        copy("$d/assets/{$it['file']}", "$vd/{$it['v']}.glb"); if (is_file("$d/assets/$key.recipe.json")) copy("$d/assets/$key.recipe.json", "$vd/{$it['v']}.recipe.json");
        $note = ''; if (!empty($it['kept'])) { $rq = json_decode((string) @file_get_contents(GameStore::sketchDir($slug, (string) $it['kept']) . '/request.json'), true) ?: []; $note = !empty($rq['model']['from']) ? (string) ($rq['note'] ?? '') : (!empty($rq['model']['cast']) ? 'the first model, from the batch' : 'made on the Make tab'); }
        $h = array_values(array_filter((array) ($it['history'] ?? []), fn($e) => ($e['v'] ?? '') !== $it['v']));
        array_unshift($h, ['v' => $it['v'], 'job' => (string) ($it['kept'] ?? ''), 'note' => mb_substr($note, 0, 200), 'until' => time(), 'recipe' => is_file("$vd/{$it['v']}.recipe.json")]);
        $c['items'][$key]['history'] = array_slice($h, 0, 20); self::write($slug, $c);
    }); }
    /** An earlier version becomes the model again; the one in use joins the history. */
    public static function useVersion(string $slug, string $key, string $v): array { return self::locked($slug, function () use ($slug, $key, $v) {
        if (!preg_match('/^[a-f0-9]{6,40}$/', $v)) throw new \InvalidArgumentException('not a version');
        $it = self::read($slug)['items'][$key] ?? null; if (!$it) throw new \InvalidArgumentException("no {$key} in the cast");
        $e = null; foreach ((array) ($it['history'] ?? []) as $x) if (($x['v'] ?? '') === $v) $e = $x; $vd = self::versionDir($slug, $key);
        if (!$e || !is_file("$vd/$v.glb")) throw new \InvalidArgumentException("{$key} has no earlier version {$v}");
        self::archive($slug, $key); $d = GameStore::dir($slug);
        copy("$vd/$v.glb", "$d/assets/$key.glb"); $rec = is_file("$vd/$v.recipe.json"); if ($rec) copy("$vd/$v.recipe.json", "$d/assets/$key.recipe.json");
        GameStore::git($d, ['add', "assets/$key.glb"] + ($rec ? [2 => "assets/$key.recipe.json"] : []));
        self::kept($slug, $key, "$key.glb", (float) $it['size'], $rec, (string) ($e['job'] ?? ''));
        $c = self::read($slug); $c['items'][$key]['history'] = array_values(array_filter((array) ($c['items'][$key]['history'] ?? []), fn($x) => ($x['v'] ?? '') !== $v)); self::write($slug, $c);
        return $c['items'][$key];
    }); }

    // ---- strips: every model's turntable picture, made on the server as the model lands (studio/bin/glb-strip.mjs draws it in
    //      software; GD writes the WebP), so no page renders 3D to show a thumbnail. thumbs/<key>-<v>.webp for a kept model,
    //      thumbs/<job id>-<take>.webp for a take. Not in git, not published. ----
    public static function strip(string $slug, string $glb, string $name): bool {
        $out = GameStore::dir($slug) . "/thumbs/$name.webp"; if (is_file($out) && filemtime($out) >= filemtime($glb)) return false;
        if (!is_dir(dirname($out))) mkdir(dirname($out), 0775, true);
        $gi = GameStore::dir($slug) . '/.gitignore'; $have = is_file($gi) ? (string) file_get_contents($gi) : ''; if (!preg_match('#^/?thumbs/?$#m', $have)) file_put_contents($gi, rtrim($have) . ($have === '' ? '' : "\n") . "thumbs/\n");
        $nb = (string) GameStore::cfg('node_bin', ''); $node = $nb !== '' ? rtrim($nb, '/') . '/node' : 'node';
        $png = tempnam(sys_get_temp_dir(), 'strip') . '.png';
        exec(escapeshellarg($node) . ' ' . escapeshellarg(GameStore::root() . '/studio/bin/glb-strip.mjs') . ' ' . escapeshellarg($glb) . ' ' . escapeshellarg($png) . ' 16 250 2>&1', $o, $rc);
        try {
            if ($rc !== 0 || !is_file($png)) throw new \RuntimeException('glb-strip: ' . trim(implode(' ', $o)));
            $im = imagecreatefrompng($png); if (!$im) throw new \RuntimeException('the strip PNG could not be read');
            imagealphablending($im, false); imagesavealpha($im, true); $ok = imagewebp($im, $out . '.tmp', 82); imagedestroy($im);
            if (!$ok) throw new \RuntimeException('GD could not write the WebP'); rename($out . '.tmp', $out); return true;
        } finally { @unlink($png); @unlink(substr($png, 0, -4)); }
    }
    /** Makes every strip that is missing: kept models and the takes of model jobs. Returns how many it made. One at a time per game. */
    public static function strips(string $slug): int {
        $lk = fopen(GameStore::studioDir($slug) . '/strips.lock', 'c'); if (!flock($lk, LOCK_EX | LOCK_NB)) { fclose($lk); return 0; }
        $n = 0; $d = GameStore::dir($slug);
        try {
            foreach (self::read($slug)['items'] as $k => $it) if (!empty($it['file']) && !empty($it['v']) && is_file("$d/assets/{$it['file']}"))
                try { $n += (int) self::strip($slug, "$d/assets/{$it['file']}", "$k-{$it['v']}"); } catch (\Throwable $e) { error_log("ERROR strip {$slug}/{$k}: " . $e->getMessage()); }
            foreach (glob("$d/sketches/*/*.glb") ?: [] as $g) { $id = basename(dirname($g)); $f = basename($g, '.glb'); if (!preg_match(GameStore::SKETCH_ID, $id) || in_array($f, ['base', 'ref'], true)) continue;
                try { $n += (int) self::strip($slug, $g, "$id-$f"); } catch (\Throwable $e) { error_log("ERROR strip {$slug}/{$id}/{$f}: " . $e->getMessage()); } }
        } finally { flock($lk, LOCK_UN); fclose($lk); }
        return $n;
    }

    // ---- wire: one builder run hooks every item in ----
    public static function wire(string $slug, int $memberId): array {
        $st = self::status($slug); $items = (array) $st['items']; if (!$items) throw new \InvalidArgumentException('the cast is empty: list it first');
        $rev = $st['three']; if ($rev === '') throw new \InvalidArgumentException('this game vendors no three.js (js/vendor/three.*.js): it does not draw in 3D, or loads three some other way; say how in a note to the builder');
        $todo = array_filter($items, fn($it) => empty($it['hooked']));
        if (!$todo && $st['wired']) throw new \InvalidArgumentException('every item in the cast is hooked in already');
        $lines = []; foreach ($todo as $k => $it) $lines[] = "- {$k}: " . ($it['what'] ?? $k) . '; its stand-in: ' . (($it['replaces'] ?? '') ?: 'find it') . '; ' . (($it['moves'] ?? '') !== '' ? "the code animates: {$it['moves']}" : 'nothing animated') . (($it['state'] ?? '') === 'kept' ? ' (a model is kept)' : ' (no model yet: hook it anyway)');
        $p = "Wire the game's 3D cast in, following \"3D models\" in ../../kit/AGENTS.md exactly (three.js r{$rev}: vendor the addons from https://cdn.jsdelivr.net/npm/three@0.{$rev}.0/examples/jsm/ and check each download is JavaScript, not an error page). "
           . ($st['wired'] ? 'Models.init is in place already; hook in the items below. ' : 'Set up the import map, kit-models.js and Models.init first. ')
           . "Then, for each item below, use Models.make('<key>') where its stand-in is built, the stand-in when it returns null, and swap live stand-ins in Models.on(). Decide per item what happens to what the code animated on the stand-in (keep those parts in code beside the model, tint its materials, or play its clips) and say which in the commit message.\n"
           . implode("\n", $lines) . "\n"
           . "Check: node --check every file you touched; run ../../studio/bin/soak 60 4 and fix anything it reports; then ../../studio/bin/shot to look at the 3D world (the models that are kept should show; a missing model must leave its stand-in, never an empty spot). Commit.";
        return GameStore::askBuilder($slug, $p, $memberId, true); // a run of its own: queued behind a run that is going
    }

    // ---- revise: one change to a kept model, from its recipe ----
    // ---- requests: a change described in plain words; the artist works out which models it means and the change for each,
    //      a person sees that plan and starts it (go), and each model gets its own revision to keep or discard ----
    private static function requestsFile(string $slug): string { return GameStore::studioDir($slug) . '/cast-requests.json'; }
    private static function readRequests(string $slug): array { $f = self::requestsFile($slug); return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : []; }
    public static function request(string $slug, string $text, int $memberId): array {
        $text = trim(mb_substr($text, 0, 1200)); if ($text === '') throw new \InvalidArgumentException('describe the change');
        $c = self::read($slug); $kept = array_filter($c['items'], fn($it) => !empty($it['file'])); if (!$kept) throw new \InvalidArgumentException('no models are kept yet: make them first');
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3)); $dir = GameStore::sketchDir($slug, $id); mkdir($dir, 0775, true);
        $req = ['id' => $id, 'note' => $text, 'key' => 'cast', 'maker' => 'cast', 'cast' => ['request' => true], 'by' => $memberId, 'at' => time()];
        GameStore::writeAtomic("$dir/request.json", json_encode($req, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $r = self::readRequests($slug); array_unshift($r, ['id' => $id, 'text' => $text, 'at' => time(), 'by' => $memberId, 'state' => 'reading']); GameStore::writeAtomic(self::requestsFile($slug), json_encode(array_slice($r, 0, 20), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $p = "3D CAST CHANGE REQUEST. The producer wants to change some of this game's 3D models. Their words: \"{$text}\"\n"
           . "The models are listed in assets/models.json (each item: what it is, its group, the code its stand-in came from, its colours and note). Read it, and GAME.md if the words need the game's terms. Work out which models the request is about: a named thing is that item; a kind (\"all the trees\", \"the bosses\", \"every enemy\") is every item of that kind; \"everything\" is every item. Only models that are kept (they have a file) can be changed. Do not stretch the request to models it does not mean.\n"
           . "For each model, write the change as one instruction for that model alone, concrete enough for a modeller who sees only that model (not \"like the others\"; say the colour, the count, the size).\n"
           . "Write sketches/{$id}/changes.json as {\"changes\": [{\"key\": \"<item key>\", \"change\": \"<the instruction>\"}, …], \"unclear\": \"<what you could not place, or empty>\"} and check it parses with `node -e`. Then sketches/{$id}/result.json as {\"ok\": true, \"files\": [], \"note\": \"<how many models, in one line>\"}. Model nothing and change nothing else. Do not run a soak and do not commit. Reply with one line.";
        GameStore::emit($slug, 'prompt', ['text' => "3D models: {$text}", 'mode' => 'art', 'by' => GameStore::memberName($memberId), 'job' => $id]);
        $r2 = GameStore::startArt($slug, $p, $memberId);
        return ['id' => $id, 'queued' => !empty($r2['queued'])];
    }
    /** The recent requests with their plans: reading (the artist is working out the plan), planned (changes to start), started, failed, cancelled. */
    public static function requests(string $slug): array {
        $out = []; $keys = self::read($slug)['items'];
        foreach (self::readRequests($slug) as $q) {
            if (($q['state'] ?? '') === 'reading') {
                try { $s = GameStore::sketchStatus($slug, (string) $q['id']); } catch (\InvalidArgumentException $e) { continue; }
                if (!empty($s['result'])) {
                    if (empty($s['result']['ok'])) { $q['state'] = 'failed'; $q['error'] = (string) ($s['result']['error'] ?? 'the artist could not place it'); }
                    else { $j = json_decode((string) @file_get_contents(GameStore::sketchDir($slug, (string) $q['id']) . '/changes.json'), true);
                        if (!is_array($j) || !is_array($j['changes'] ?? null)) { $q['state'] = 'failed'; $q['error'] = 'the plan (changes.json) is missing or not JSON'; }
                        else { $q['state'] = 'planned'; $q['unclear'] = trim(mb_substr((string) ($j['unclear'] ?? ''), 0, 400)); $q['changes'] = [];
                            foreach ($j['changes'] as $ch) { $k = (string) ($ch['key'] ?? ''); $t = trim(mb_substr((string) ($ch['change'] ?? ''), 0, 600)); if ($t !== '' && !empty($keys[$k]['file'])) $q['changes'][] = ['key' => $k, 'change' => $t]; } } }
                }
            }
            $out[] = $q;
        }
        return $out;
    }
    /** A planned request starts: a revision per model (all of them, or the keys given). */
    public static function go(string $slug, string $id, array $only, int $memberId): array {
        return self::locked($slug, function () use ($slug, $id, $only, $memberId) {
            $all = self::requests($slug); $i = array_search($id, array_column($all, 'id'), true); if ($i === false) throw new \InvalidArgumentException('no such request');
            $q = $all[$i]; if (($q['state'] ?? '') !== 'planned') throw new \InvalidArgumentException('that request is ' . ($q['state'] ?? '?') . ', not waiting to start');
            $started = []; foreach ($q['changes'] as $ch) { if ($only && !in_array($ch['key'], $only, true)) continue; self::revise($slug, $ch['key'], $ch['change'], $memberId); $started[] = $ch['key']; }
            $all[$i]['state'] = 'started'; $all[$i]['started'] = $started; GameStore::writeAtomic(self::requestsFile($slug), json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $started;
        });
    }
    public static function cancelRequest(string $slug, string $id): void {
        self::locked($slug, function () use ($slug, $id) { $all = self::requests($slug); foreach ($all as &$q) if ($q['id'] === $id && in_array($q['state'], ['planned', 'failed'], true)) $q['state'] = 'cancelled'; unset($q);
            GameStore::writeAtomic(self::requestsFile($slug), json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); });
    }
    /** Every revision that is done and not yet kept or discarded: [key => [id, file]] (the newest per model). */
    public static function waitingRevisions(string $slug): array {
        $c = self::read($slug); $out = [];
        foreach (GameStore::listSketches($slug)['jobs'] as $j) { // newest first
            if (($j['kind'] ?? '') !== 'remodel' || isset($out[$j['key']]) || empty($j['result']['ok']) || empty($j['result']['files'][0])) continue;
            if (($c['items'][$j['key']]['kept'] ?? '') === $j['id']) { $out[$j['key']] = null; continue; } // the newest is kept already
            $out[$j['key']] = ['id' => $j['id'], 'file' => (string) $j['result']['files'][0]];
        }
        return array_filter($out);
    }
    /** Keeps every waiting revision (or those of the keys given). Returns the keys kept. */
    public static function keepRevisions(string $slug, array $only): array {
        $kept = []; $c = self::read($slug);
        foreach (self::waitingRevisions($slug) as $k => $w) { if ($only && !in_array($k, $only, true)) continue;
            GameStore::saveSprite($slug, ['key' => $k, 'take' => $w, 'size' => (float) ($c['items'][$k]['size'] ?? 1), 'source' => 'cast']); $kept[] = $k; }
        return $kept;
    }

    public static function revise(string $slug, string $key, string $note, int $memberId): array {
        $c = self::read($slug); $it = $c['items'][$key] ?? null; if (!$it || empty($it['file'])) throw new \InvalidArgumentException("{$key} has no kept model to revise");
        if (trim($note) === '') throw new \InvalidArgumentException('say what to change');
        return GameStore::startSketch($slug, ['maker' => 'model', 'model' => ['size' => (float) $it['size'], 'animate' => false, 'takes' => 1, 'from' => $key], 'key' => $key, 'note' => $note, 'size' => 64], $memberId);
    }
}
