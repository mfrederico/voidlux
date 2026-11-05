<?php
/**
 * Upgrade: a 2D sprite becomes a jointed 3D model that replaces it in the game.
 *
 * One art job models the sprite from its picture (the sprite editor renders it and, for a 2D rig, sends the joints from the rig's
 * rest pose) as named parts the pose editor and kit-poses know (torso at the hips, head, upperArmL…), each turning at its joint.
 * Where it lands, from what the game already has:
 *   - the 3D model that draws the sprite's subject (assets/events-vocab.json "model"), or a cast item that says it replaces the sprite:
 *     a new version of that model, which waits in Work (Pose it first / Use it) like any revision;
 *   - else a new cast item that replaces the sprite: kept when it lands, and the cast's wiring (Cast::wire) puts it in the game.
 * The item remembers the sprite ("fromSprite") and the parts asked for ("parts"): the take is checked against them, the pose editor
 * brings the 2D rig's motions across, and when the model is kept the subject is linked to it so the Studio shows the 3D one.
 */
namespace app;

class Upgrade {
    /** The part names a jointed model gets, each with its parent: the names kit-poses, the pose editor and the games' posing code use. */
    public const PARTS = ['torso' => '', 'head' => 'torso', 'upperArmL' => 'torso', 'lowerArmL' => 'upperArmL', 'upperArmR' => 'torso', 'lowerArmR' => 'upperArmR',
        'thighL' => 'torso', 'shinL' => 'thighL', 'thighR' => 'torso', 'shinR' => 'thighR'];
    /** Groups whose things are characters: split into limbs unless the person says not to. */
    public const CHARACTERS = ['player', 'enemy', 'boss', 'npc'];

    /** What an upgrade of this sprite would do: where the model goes, what it is, how big, the cost. */
    public static function plan(string $slug, string $sprite): array {
        $sp = self::sprite($slug, $sprite); [$key, $existing] = self::target($slug, $sprite); $cast = Cast::read($slug)['items']; $it = $cast[$key] ?? [];
        $v = Rules::vocab($slug); $subj = $v['subjects'][$sprite] ?? null;
        $group = (string) ($it['group'] ?? (($subj['groups'] ?? [])[0] ?? 'other')); if (!in_array($group, ['player', 'enemy', 'boss', 'npc', 'prop', 'pickup', 'terrain', 'other'], true)) $group = 'other';
        return ['sprite' => $sprite, 'spriteKind' => (string) ($sp['kind'] ?? 'picture'), 'key' => $key, 'existing' => $existing,
            'what' => (string) ($it['what'] ?? ($subj['about'] ?? '')), 'group' => $group, 'size' => (float) ($it['size'] ?? self::sizeGuess($cast, $group)),
            'limbs' => in_array($group, self::CHARACTERS, true) || ($sp['kind'] ?? '') === 'rig', 'cost' => RunCosts::estimate($slug, 'art')['cost'], 'three' => Cast::threeRevision($slug) !== ''];
    }

    /** Starts the art job. $b: what, group, size, limbs (bool), png (the sprite rendered, a data: URL), joints ([{ part, at: [forward, up] }] as
     *  fractions of the figure's height, from a 2D rig's rest pose), view ('side' or 'front': how the sprite is drawn). */
    public static function start(string $slug, string $sprite, array $b, int $memberId): array {
        $p = self::plan($slug, $sprite); $key = $p['key'];
        $what = trim(mb_substr((string) ($b['what'] ?? $p['what']), 0, 200)); if ($what === '') throw new \InvalidArgumentException('say what it is');
        $group = in_array($b['group'] ?? '', ['player', 'enemy', 'boss', 'npc', 'prop', 'pickup', 'terrain', 'other'], true) ? (string) $b['group'] : $p['group'];
        $size = max(0.01, min(10000, (float) ($b['size'] ?? $p['size']))); $limbs = !empty($b['limbs']); $view = ($b['view'] ?? 'side') === 'front' ? 'front' : 'side';
        $parts = []; if ($limbs) foreach (self::PARTS as $n => $par) $parts[] = ['name' => $n, 'parent' => $par];
        $joints = []; foreach (array_slice((array) ($b['joints'] ?? []), 0, 20) as $j) { if (!is_array($j) || !isset(self::PARTS[(string) ($j['part'] ?? '')])) continue; $at = array_values((array) ($j['at'] ?? []));
            if (count($at) !== 2) continue; $joints[(string) $j['part']] = [round(max(-2.0, min(2.0, (float) $at[0])), 3), round(max(-0.5, min(1.5, (float) $at[1])), 3)]; }
        /* the item: a new one for a sprite nothing draws in 3D yet; an existing one keeps what it says and learns where it came from */
        Cast::locked($slug, function () use ($slug, $key, $sprite, $what, $group, $size, $parts, $p) {
            $c = Cast::read($slug); $it = (array) ($c['items'][$key] ?? []);
            if (!$it) $it = ['what' => $what, 'group' => $group, 'size' => $size, 'facing' => '+x', 'replaces' => "the 2D sprite {$sprite}: draw this model wherever the game draws that sprite", 'note' => '', 'moves' => ''];
            $it['fromSprite'] = $sprite; if ($parts) $it['parts'] = array_column($parts, 'name'); else unset($it['parts']);
            $c['items'][$key] = $it; Cast::write($slug, $c);
        });
        $brief = self::brief($sprite, $what, $parts, $joints, $view);
        $req = ['maker' => 'model', 'model' => ['size' => $size, 'animate' => false, 'takes' => 1, 'upgrade' => $brief] + ($p['existing'] ? ['from' => $key] : ['cast' => $key]),
            'key' => $key, 'note' => mb_substr("{$what}. Modelled from the 2D sprite {$sprite}" . ($parts ? ', as jointed parts' : ''), 0, 600), 'asset' => $sprite, 'size' => 64];
        if (!empty($b['png'])) $req['png'] = (string) $b['png'];
        $r = GameStore::startSketch($slug, $req, $memberId);
        if (!$p['existing']) Cast::locked($slug, function () use ($slug, $key, $r) { $c = Cast::read($slug); $c['items'][$key]['job'] = $r['id']; unset($c['items'][$key]['error']); Cast::write($slug, $c); });
        return $r + ['target' => $key, 'existing' => $p['existing']];
    }

    /** The part list and joints, as the artist reads them. */
    private static function brief(string $sprite, string $what, array $parts, array $joints, string $view): string {
        $s = "UPGRADE FROM 2D. Model {$what} in 3D from the game's own 2D sprite {$sprite} (the drawing and the sprite are your references): keep its silhouette, proportions and colours as a low-poly model in the game's look. ";
        if (!$parts) return $s . 'It is one solid piece: no limbs to split.';
        $s .= "Build it as separate, named, nested parts (never one merged mesh), each with its origin at the joint it turns about: ";
        $rows = []; foreach ($parts as $pt) { $at = $joints[$pt['name']] ?? null; $rows[] = $pt['name'] . ($pt['parent'] !== '' ? " (under {$pt['parent']})" : ' (the root, origin at the hips)') . ($at ? " turning at {$at[0]} forward, {$at[1]} up" : ''); }
        $s .= implode('; ', $rows) . '. ';
        if ($joints) $s .= 'Those joint places are measured from the 2D rig\'s rest pose' . ($view === 'side' ? ' (a side view facing right, so forward is +x)' : ' (a front view)') . ', as fractions of the figure\'s height, from its feet; the depth of each side is yours: L parts on the figure\'s left (-z), R on its right (+z). ';
        $s .= 'Hats, scarves, weapons and other props go under the part that carries them (a spear in a hand under that lowerArm, a hat under head), named for what they are. Rest pose: standing, arms down, facing +x, y up. Drop empty marker nodes. '
            . 'CHECK before you finish: read the exported glTF JSON with node and confirm every part above is a node with that exact name, nested as listed, each with a shape of its own, and that the parts meet with no gaps.';
        return $s;
    }

    /** Problems with a take against the parts asked for: missing, unnamed, nested wrongly, with no shape. Empty when it is right. */
    public static function check(string $glb, array $parts): array {
        if (!$parts) return []; if (!is_file($glb)) return ['the model file is missing'];
        $b = (string) file_get_contents($glb); if (strlen($b) < 20 || substr($b, 0, 4) !== 'glTF') return ['it is not a binary glTF file'];
        $n = unpack('V', substr($b, 12, 4))[1]; $j = json_decode(substr($b, 20, $n), true); if (!is_array($j)) return ['its glTF JSON does not parse'];
        $nodes = (array) ($j['nodes'] ?? []); $by = []; $parent = [];
        foreach ($nodes as $i => $nd) { if (isset($nd['name'])) $by[(string) $nd['name']] = $i; foreach ((array) ($nd['children'] ?? []) as $ch) $parent[$ch] = $i; }
        $shaped = function (int $i) use ($nodes, &$shaped): bool { if (isset($nodes[$i]['mesh'])) return true; foreach ((array) ($nodes[$i]['children'] ?? []) as $ch) if (!isset($nodes[$ch]['name']) || !isset(self::PARTS[(string) $nodes[$ch]['name']])) { if ($shaped($ch)) return true; } return false; };
        $out = [];
        foreach ($parts as $name) {
            if (!isset($by[$name])) { $out[] = "no part named {$name}"; continue; }
            $want = self::PARTS[$name] ?? ''; if ($want !== '') { $up = $parent[$by[$name]] ?? null; while ($up !== null && !isset(self::PARTS[(string) ($nodes[$up]['name'] ?? '')])) $up = $parent[$up] ?? null;
                $got = $up === null ? '' : (string) $nodes[$up]['name']; if ($got !== $want) $out[] = "{$name} is under " . ($got ?: 'nothing') . ", not {$want}"; }
            if (!$shaped($by[$name])) $out[] = "{$name} has no shape of its own";
        }
        return $out;
    }

    /** The node names in a binary glTF file ([] when it cannot be read). */
    public static function partsIn(string $glb): array {
        if (!is_file($glb)) return []; $h = (string) file_get_contents($glb, false, null, 0, 20); if (strlen($h) < 20 || substr($h, 0, 4) !== 'glTF') return [];
        $n = unpack('V', substr($h, 12, 4))[1]; $j = json_decode((string) file_get_contents($glb, false, null, 20, $n), true);
        return is_array($j) ? array_values(array_filter(array_map(fn($x) => (string) ($x['name'] ?? ''), (array) ($j['nodes'] ?? [])))) : [];
    }
    /** Links the subject drawn by the sprite to the model, so the Studio shows the 3D one (the subject's "model" in assets/events-vocab.json).
     *  Written in place, one subject per line as the builder writes the file. A subject that already names a model keeps it. */
    public static function linkSubject(string $slug, string $sprite, string $key): bool {
        $f = GameStore::dir($slug) . '/assets/events-vocab.json'; if (!is_file($f) || $sprite === $key) return false;
        $v = Rules::vocab($slug); $s = $v['subjects'][$sprite] ?? null; if ($s === null || !empty($s['model'])) return false;
        $src = (string) file_get_contents($f); $pat = '/("' . preg_quote($sprite, '/') . '"\s*:\s*\{)/'; if (preg_match_all($pat, $src) !== 1) throw new \RuntimeException("assets/events-vocab.json: the subject {$sprite} is not written as \"{$sprite}\": { … } once; link it to {$key} by hand (\"model\": \"{$key}\")");
        $new = preg_replace($pat, '$1 "model": "' . $key . '",', $src, 1); if (!is_array(json_decode($new, true))) throw new \RuntimeException("assets/events-vocab.json would not parse after linking {$sprite} to {$key}; link it by hand");
        GameStore::writeAtomic($f, $new); GameStore::git(GameStore::dir($slug), ['add', 'assets/events-vocab.json']); return true;
    }

    private static function sprite(string $slug, string $sprite): array {
        if (!preg_match(GameStore::SPRITE_KEY, $sprite)) throw new \InvalidArgumentException('not a sprite key');
        $sp = ((array) GameStore::readSprites($slug)['sprites'])[$sprite] ?? null; if (!$sp) throw new \InvalidArgumentException("no sprite named {$sprite}");
        $sp = (array) $sp; if (($sp['kind'] ?? '') === 'model') throw new \InvalidArgumentException("{$sprite} is a 3D model already");
        return $sp;
    }
    /** Where the model goes: [key, whether a kept model is there already]. */
    private static function target(string $slug, string $sprite): array {
        $cast = is_file(Cast::path($slug)) ? Cast::read($slug)['items'] : []; $v = Rules::vocab($slug); $m = (string) (($v['subjects'][$sprite] ?? [])['model'] ?? '');
        if ($m !== '' && isset($cast[$m])) return [$m, !empty($cast[$m]['file'])];
        foreach ($cast as $k => $it) if (($it['fromSprite'] ?? '') === $sprite || preg_match('/\b' . preg_quote($sprite, '/') . '\b/', (string) ($it['replaces'] ?? ''))) return [(string) $k, !empty($it['file'])];
        $base = preg_replace('/-(rig|drawing|vector|ready|sprite|2d)$/', '', $sprite); $sprites = (array) GameStore::readSprites($slug)['sprites'];
        foreach ([$base, $base . '-3d'] as $k) if (!isset($cast[$k]) && (!isset($sprites[$k]) || ((array) $sprites[$k])['kind'] === 'model')) return [$k, false];
        throw new \InvalidArgumentException("there is no free key for {$sprite}'s model ({$base} and {$base}-3d are taken)");
    }
    private static function sizeGuess(array $cast, string $group): float { foreach ($cast as $it) if (($it['group'] ?? '') === $group && !empty($it['size'])) return (float) $it['size']; return 1.0; }
}
