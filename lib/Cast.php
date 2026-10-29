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
    }

    /** The cast with each item's state, for the 3D tab. */
    public static function status(string $slug): array {
        $c = self::read($slug); $dir = GameStore::dir($slug); $hooks = self::hooks($slug); $out = [];
        foreach ($c['items'] as $k => $it) {
            $state = !empty($it['file']) && is_file("$dir/assets/{$it['file']}") ? 'kept' : 'none'; $job = (string) ($it['job'] ?? '');
            if ($job !== '' && ($it['kept'] ?? '') !== $job) {
                try { $s = GameStore::sketchStatus($slug, $job); $state = !empty($s['busy']) && empty($s['result']) ? 'making' : (!empty($s['result']['ok']) ? ($state === 'kept' ? 'kept' : 'made') : (!empty($s['result']) ? 'failed' : 'making')); if (!empty($s['result']) && empty($s['result']['ok'])) $it['error'] = (string) ($s['result']['error'] ?? 'the job failed'); }
                catch (\InvalidArgumentException $e) { $state = $state === 'kept' ? 'kept' : 'none'; } // the job was deleted
            }
            $out[$k] = $it + ['state' => $state, 'hooked' => in_array($k, $hooks, true)];
        }
        $page = is_file("$dir/index.html") ? (string) file_get_contents("$dir/index.html") : '';
        return ['items' => (object) $out, 'wired' => str_contains($page, 'kit-models.js'), 'list' => self::listJob($slug), 'three' => self::threeRevision($slug), 'slots' => GameStore::artSlots(), 'working' => (int) (GameStore::artState($slug)['working'] ?? 0)];
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
            $job = (string) ($it['job'] ?? ''); if ($job === '' || ($it['kept'] ?? '') === $job) continue;
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
    public static function revise(string $slug, string $key, string $note, int $memberId): array {
        $c = self::read($slug); $it = $c['items'][$key] ?? null; if (!$it || empty($it['file'])) throw new \InvalidArgumentException("{$key} has no kept model to revise");
        if (trim($note) === '') throw new \InvalidArgumentException('say what to change');
        return GameStore::startSketch($slug, ['maker' => 'model', 'model' => ['size' => (float) $it['size'], 'animate' => false, 'takes' => 1, 'from' => $key], 'key' => $key, 'note' => $note, 'size' => 64], $memberId);
    }
}
