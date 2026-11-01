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
        foreach ($keys as $k) { $s = $v['subjects'][$k] ?? []; $c = $cast[$k] ?? [];
            $out[] = ['key' => $k, 'about' => (string) ($s['about'] ?? $c['what'] ?? ''), 'group' => (string) (($s['groups'] ?? [])[0] ?? $c['group'] ?? 'other'), 'thumb' => self::thumb($slug, $k, $c), 'rules' => (int) ($own[$k] ?? 0)]; }
        usort($out, fn($a, $b) => [$a['group'], $a['key']] <=> [$b['group'], $b['key']]);
        return $out;
    }
    private static function thumb(string $slug, string $k, array $c): string {
        if (empty($c['v'])) return ''; $f = "thumbs/{$k}-{$c['v']}.webp"; return is_file(GameStore::dir($slug) . "/$f") ? "/games/{$slug}/$f" : '';
    }
    /** One thing's card. Throws InvalidArgumentException when the game has no such thing. */
    public static function card(string $slug, string $key): array {
        $v = Rules::vocab($slug); $cast = is_file(Cast::path($slug)) ? Cast::read($slug)['items'] : []; $sprites = (array) GameStore::readSprites($slug)['sprites'];
        $subject = $v['subjects'][$key] ?? null; $item = $cast[$key] ?? null; $sprite = isset($sprites[$key]) ? (array) $sprites[$key] : null;
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
        return ['key' => $key, 'about' => (string) ($subject['about'] ?? $item['what'] ?? ''), 'groups' => $groups, 'look' => self::look($slug, $key, $item, $sprite), 'rules' => $rules, 'built' => $built, 'builtForAll' => $forAll, 'listed' => (bool) $cat,
            'bestiary' => self::resistances($slug, (array) ($subject['bestiary'] ?? [])), 'wired' => $v !== null,
            'open' => ['rules' => "/editor/sprites/?game={$slug}&who=" . rawurlencode($key) . '#events', 'model' => $item ? "/editor/sprites/?game={$slug}&item=" . rawurlencode($key) . '#3d' : null, 'bestiary' => !empty($subject['bestiary']) ? "/game/gameeditor/{$slug}?e=bestiary&game={$slug}" : null]];
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
