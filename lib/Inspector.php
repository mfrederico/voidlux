<?php
/**
 * Inspector — everything the forge knows about one thing in a game, in one place: the Studio's card. Read-only: it assembles
 * what the game's own files already say (the vocabulary's subjects, the 3D cast, sprites, rules, the catalogue of what is
 * built in, the bestiary). A game without some of those has a thinner card; nothing here is required, and a file that exists
 * but cannot be read is an error, never an empty section (Rules, Cast and GameStore throw naming it).
 */
namespace app;

class Inspector {
    /** The things of a game, for the list: the vocabulary's subjects, else the cast's items, else the sprites. */
    public static function things(string $slug): array {
        $v = Rules::vocab($slug); $cast = is_file(Cast::path($slug)) ? Cast::read($slug)['items'] : []; $sprites = (array) GameStore::readSprites($slug)['sprites'];
        $rules = Rules::read($slug)['rules']; $own = []; foreach ($rules as $r) $own[$r['who'] ?? ''] = ($own[$r['who'] ?? ''] ?? 0) + 1;
        $keys = $v ? array_keys($v['subjects']) : ($cast ? array_keys($cast) : array_keys($sprites));
        $out = [];
        foreach ($keys as $k) { $s = $v['subjects'][$k] ?? []; $mk = self::modelKey($k, $s, $cast); $c = $cast[$mk] ?? [];
            $out[] = ['key' => $k, 'name' => (string) ($s['name'] ?? '') ?: self::name($k, (string) ($s['about'] ?? $c['what'] ?? '')), 'ui' => in_array('ui', (array) ($s['groups'] ?? []), true), 'about' => (string) ($s['about'] ?? $c['what'] ?? ''), 'group' => (string) (($s['groups'] ?? [])[0] ?? $c['group'] ?? 'other'), 'thumb' => self::thumb($slug, $mk, $c), 'model' => $c ? $mk : '', 'rules' => (int) ($own[$k] ?? 0)]; }
        /* models made for the game that nothing in it draws yet (an upgraded sprite, a new character): listed, so their card says what is left */
        if ($v) { $drawn = []; foreach ($v['subjects'] as $k => $s) { $drawn[$k] = true; if (!empty($s['model'])) $drawn[(string) $s['model']] = true; }
            $hooks = array_flip(Cast::hooks($slug));
            foreach ($cast as $k => $c) if (!isset($drawn[$k]) && !isset($hooks[$k]) && !empty($c['fromSprite']) || (!isset($drawn[$k]) && !isset($hooks[$k]) && !empty($c['file']) && ($c['group'] ?? '') !== 'terrain' && preg_match('/\b2D sprite\b/', (string) ($c['replaces'] ?? ''))))
                $out[] = ['key' => (string) $k, 'name' => self::name((string) $k, (string) ($c['what'] ?? '')), 'ui' => false, 'about' => (string) ($c['what'] ?? ''), 'group' => 'new', 'thumb' => self::thumb($slug, (string) $k, $c), 'model' => (string) $k, 'rules' => 0]; }
        usort($out, fn($a, $b) => [$a['group'], $a['key']] <=> [$b['group'], $b['key']]);
        return $out;
    }
    /** What a person calls it, when the vocabulary gives no name: the first clause of what the game says about it when that is short, else the key in words. */
    private static function name(string $key, string $about): string {
        $first = trim((string) preg_split('/[:,]\s/', $about, 2)[0]);
        if ($first !== '' && mb_strlen($first) <= 32) return mb_strtoupper(mb_substr($first, 0, 1)) . mb_substr($first, 1);
        return ucfirst(str_replace(['-', '_'], ' ', (string) preg_replace('/^[a-z]+:/', '', $key)));
    }
    private static function thumb(string $slug, string $k, array $c): string {
        if (empty($c['v'])) return ''; $f = "thumbs/{$k}-{$c['v']}.webp"; return is_file(GameStore::dir($slug) . "/$f") ? "/games/{$slug}/$f" : '';
    }
    /** The 3D model that draws a subject: the vocabulary's "model" when it names one (the player keyed by its old 2D rig, drawn by the model ninja), else the subject's own key.
     *  A "model" the cast does not have is the vocabulary's mistake, said as one. */
    private static function modelKey(string $key, array $subject, array $cast): string {
        $m = (string) ($subject['model'] ?? ''); if ($m === '') return $key;
        if (!isset($cast[$m])) throw new \RuntimeException("assets/events-vocab.json: {$key} is drawn by the model \"{$m}\", which is not in the 3D cast (assets/models.json)");
        return $m;
    }
    /** A thing drawn by a 2D sprite in a game that draws in 3D can be upgraded to a jointed 3D model (Upgrade): the sprite's key, else null */
    private static function upgradeOpen(string $slug, ?array $item, ?array $sprite, string $spriteKey): ?string {
        if (!$sprite || ($sprite['kind'] ?? '') === 'model' || ($item && !empty($item['file']))) return null;
        return Cast::threeRevision($slug) !== '' ? $spriteKey : null;
    }
    /** Where a thing stands on the way from a drawing to a moving 3D thing in the game: four steps (draw, 3d, move, game), each
     *  done | busy | ready (a new version waits) | next | later | skip (made from words, or one that need not move) | stuck (one solid
     *  piece that should move), with what to do next. Only for games that draw in 3D, and never for parts of a screen. */
    private static function path(string $slug, string $key, ?array $subject, ?array $item, ?array $sprite, string $mk, array $sprites): ?array {
        if (Cast::threeRevision($slug) === '' || in_array('ui', (array) ($subject['groups'] ?? []), true) || str_contains($key, ':')) return null;
        $d = GameStore::dir($slug); $is2d = fn($k) => $k !== '' && isset($sprites[$k]) && (((array) $sprites[$k])['kind'] ?? '') !== 'model'; /* the 2D drawing: the one the model was made from, else the thing's own sprite */
        $from = (string) ($item['fromSprite'] ?? ''); if (!$is2d($from)) $from = $is2d($key) ? $key : ($is2d($mk) ? $mk : '');
        $has2d = $from !== '' && isset($sprites[$from]) && ((array) $sprites[$from])['kind'] !== 'model'; $kept = $item && !empty($item['file']) && is_file("$d/assets/{$item['file']}");
        $group = (string) ($item['group'] ?? (($subject['groups'] ?? [])[0] ?? 'other')); $character = in_array($group, ['player', 'enemy', 'boss', 'npc', 'friend', 'critter'], true);
        $draw = $has2d ? ['state' => 'done', 'say' => 'Drawn in 2D', 'sprite' => $from] : ($kept ? ['state' => 'skip', 'say' => 'Made from words'] : ['state' => 'next', 'say' => 'Draw it, or describe it']);
        $three = ['state' => 'later', 'say' => 'Made in 3D'];
        $waiting = Cast::waitingRevisions($slug)[$mk] ?? null; $busy = false;
        if ($item && !empty($item['job']) && strcmp((string) ($item['kept'] ?? ''), (string) $item['job']) < 0) { try { $st = GameStore::sketchStatus($slug, (string) $item['job']); $busy = empty($st['result']); } catch (\InvalidArgumentException $e) {} }
        if ($busy) $three = ['state' => 'busy', 'say' => 'The artist is making it'];
        elseif ($waiting) $three = ['state' => 'ready', 'say' => 'A new version is ready to look at', 'take' => $waiting['id'] . '/' . $waiting['file']];
        elseif ($kept) $three = ['state' => 'done', 'say' => 'Made in 3D'];
        elseif ($has2d) $three = ['state' => 'next', 'say' => 'Make it 3D'];
        $move = ['state' => 'later', 'say' => 'Make it move'];
        if ($kept) { $names = $kept ? Upgrade::partsIn("$d/assets/{$item['file']}") : []; $jointed = count(array_intersect($names, array_keys(Upgrade::PARTS))) >= 4;
            $poses = is_file("$d/assets/{$mk}.poses.json") ? (array) (GameStore::readPoses($slug, $mk)['motions'] ?? []) : []; $posed = array_keys(array_filter($poses, fn($m) => !empty($m['keys'])));
            if ($posed) $move = ['state' => 'done', 'say' => count($posed) . ' move' . (count($posed) === 1 ? '' : 's') . ': ' . implode(', ', array_map(fn($n) => str_replace(['_', '-'], ' ', $n), array_slice($posed, 0, 4))) . (count($posed) > 4 ? '…' : ''), 'jointed' => $jointed];
            elseif ($jointed) $move = ['state' => 'next', 'say' => 'Make it move', 'rig' => $has2d && ((array) $sprites[$from])['kind'] === 'rig'];
            elseif ($character) $move = ['state' => 'stuck', 'say' => 'It is one solid piece: it needs arms and legs to move'];
            else $move = ['state' => 'skip', 'say' => 'Does not need to move']; }
        $inGame = $subject !== null || in_array($mk, Cast::hooks($slug), true);
        $game = $inGame ? ['state' => 'done', 'say' => 'In the game'] : ['state' => $kept ? 'next' : 'later', 'say' => 'Put it in the game'];
        return ['draw' => $draw, '3d' => $three, 'move' => $move, 'game' => $game, 'model' => $mk, 'cost' => ['art' => RunCosts::estimate($slug, 'art')['cost'], 'build' => RunCosts::estimate($slug, 'chat')['cost']]];
    }
    /** How the pose editor opens for a 3D model: its key, and its waiting new version when there is one (posed before it is kept); null for anything not 3D */
    private static function poseOpen(string $slug, string $key, ?array $sprite): ?array {
        $w = is_file(Cast::path($slug)) ? (Cast::waitingRevisions($slug)[$key] ?? null) : null; $take = $w && str_ends_with((string) $w['file'], '.glb') ? $w['id'] . '/' . $w['file'] : '';
        if (($sprite['kind'] ?? '') !== 'model' && $take === '') return null;
        return ['key' => $key, 'take' => $take];
    }
    /** One thing's card. Throws InvalidArgumentException when the game has no such thing. */
    public static function card(string $slug, string $key): array {
        $v = Rules::vocab($slug); $cast = is_file(Cast::path($slug)) ? Cast::read($slug)['items'] : []; $sprites = (array) GameStore::readSprites($slug)['sprites'];
        $subject = $v['subjects'][$key] ?? null; $mk = self::modelKey($key, (array) $subject, $cast); $item = $cast[$mk] ?? null; $sprite = isset($sprites[$mk]) ? (array) $sprites[$mk] : (isset($sprites[$key]) ? (array) $sprites[$key] : null);
        if ($subject === null && $item === null && $sprite === null) throw new \InvalidArgumentException("this game has no thing called {$key}");
        $groups = array_values((array) ($subject['groups'] ?? []));
        // the rules that reach it: its own, then its groups', then those for everything
        $rules = ['own' => [], 'group' => [], 'all' => []];
        foreach (Rules::status($slug)['rules'] as $r) { $w = (string) ($r['who'] ?? '');
            if ($w === $key) $rules['own'][] = $r; elseif ($w === '*') $rules['all'][] = $r; elseif (str_starts_with($w, 'group:') && in_array(substr($w, 6), $groups, true)) $rules['group'][] = $r; }
        // what the game does for it in code, by system; what is for everything is only counted (it would bury the rest)
        $built = []; $forAll = 0; $cat = Rules::builtin($slug);
        foreach ((array) ($cat['systems'] ?? []) as $s) foreach ((array) ($s['items'] ?? []) as $it) { $w = (string) ($it['who'] ?? '');
            if ($w === '*') { $forAll++; continue; } if ($w === $key || (str_starts_with($w, 'group:') && in_array(substr($w, 6), $groups, true))) $built[(string) ($s['name'] ?? $s['id'] ?? '')][] = $it; }
        return ['key' => $key, 'name' => (string) ($subject['name'] ?? '') ?: self::name($key, (string) ($subject['about'] ?? $item['what'] ?? '')), 'ui' => in_array('ui', $groups, true), 'about' => (string) ($subject['about'] ?? $item['what'] ?? ''), 'groups' => array_values(array_diff($groups, ['ui'])), 'look' => self::look($slug, $mk, $item, $sprite), 'rules' => $rules, 'built' => $built, 'builtForAll' => $forAll, 'listed' => (bool) $cat,
            'bestiary' => self::resistances($slug, (array) ($subject['bestiary'] ?? [])), 'wired' => $v !== null,
            'open' => ['rules' => "/editor/sprites/?game={$slug}&who=" . rawurlencode($key) . '#events', 'model' => $item ? "/editor/sprites/?game={$slug}&item=" . rawurlencode($mk) . '#3d' : null, 'bestiary' => !empty($subject['bestiary']) ? "/game/gameeditor/{$slug}?e=bestiary&game={$slug}" : null, 'pose' => self::poseOpen($slug, $mk, $sprite), 'upgrade' => self::upgradeOpen($slug, $item, $sprite, isset($sprites[$mk]) ? $mk : $key)], 'path' => self::path($slug, $key, $subject, $item, $sprite, $mk, $sprites)];
    }
    private static function look(string $slug, string $key, ?array $item, ?array $sprite): ?array {
        if ($item) { $has = !empty($item['file']); return ['kind' => 'model', 'kept' => $has, 'strip' => self::thumb($slug, $key, $item), 'size' => $item['size'] ?? null, 'note' => (string) ($item['note'] ?? ''), 'colours' => array_values((array) ($item['colours'] ?? [])), 'moves' => (string) ($item['moves'] ?? ''), 'replaces' => (string) ($item['replaces'] ?? ''), 'recipe' => !empty($item['recipe']), 'versions' => count((array) ($item['history'] ?? []))]; }
        if ($sprite) return ['kind' => 'sprite', 'kept' => true, 'url' => "/games/{$slug}/assets/" . basename((string) ($sprite['file'] ?? '')), 'file' => (string) ($sprite['file'] ?? ''), 'spriteKind' => (string) ($sprite['kind'] ?? 'picture')];
        return null;
    }
    /** Each element's multiplier for each own row (one thing may cover several variants), with the row it came from. */
    private static function resistances(string $slug, array $rows): ?array {
        if (!$rows) return null; $B = GameStore::readBestiary($slug); $R = (array) ($B['resist'] ?? []); $S = (array) ($B['stats'] ?? []);
        $fam = array_values(array_filter($rows, fn($r) => str_starts_with($r, 'family-'))); $own = array_values(array_filter($rows, fn($r) => !str_starts_with($r, 'family-'))); $lines = [];
        foreach ($own ?: [''] as $o) { $chain = array_merge($o === '' ? [] : [$o], $fam); $vals = [];
            foreach (GameStore::BESTIARY_ELEMENTS as $el) { $from = ''; $val = 1; foreach ($chain as $c) if (isset($R[$c][$el])) { $from = $c; $val = $R[$c][$el]; break; } $vals[$el] = ['v' => $val, 'from' => $from]; }
            $lines[] = ['row' => $o, 'values' => $vals, 'note' => (string) ($R[$o]['note'] ?? ''), 'stats' => (array) ($S[$o] ?? [])]; }
        return $lines;
    }
}
