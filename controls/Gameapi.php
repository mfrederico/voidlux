<?php
/**
 * Gameapi — the editors' JSON: the SFX editor, the tracker and the config editor (kit/editor/*, plain pages served
 * from /editor/) read and write one game's files through here. Every route is /gameapi/<method>/<slug>; the editors
 * fetch /gameapi/token/<slug> first and send it back as X-CSRF-TOKEN on writes (they are static pages: no form token).
 *
 *   GET  token      GET/POST sfx      GET sounds      GET/POST tuning      POST musicsave { name, snippet }     POST musicdelete { name }
 *   GET  sprites    POST spritesave { key, png|svg|file, w, h, frames, fps, source }    POST spritedelete { key, file }
 *   POST sketch { png, note, style, pixel, size, key, palette } → { id }      GET sketch?id= → { result, base, busy }
 *   GET  sketches → { jobs }      POST sketchdelete { id }      GET spritehints?key=   POST spritehints { key, plan, joints, notes }
 *   GET  cast → the 3D cast       POST cast { do: list | make | wire | revise | edit | request | go | cancel | keepall }
 *   GET/POST bestiary (js/bestiary.js, for the game's own bestiary editor)
 *   POST thumb { name, data } (a 3D turntable strip, cached in thumbs/)
 *   GET  events → rules, vocabulary, proposals      POST events { do: save | describe | add | dismiss | wire | ask }
 */
namespace app;

use \Flight as Flight;

class Gameapi extends BaseControls\Control {
    private function game($params): ?object {
        $slug = (string) ($params['operation']->name ?? '');
        $g = preg_match(GameStore::SLUG, $slug) ? GameStore::row($slug) : null;
        if (!$g) { $this->jsonError('No such game.', 404); return null; }
        if (!GameStore::canOpen($g, (int) $this->member->id, (int) $this->member->level)) { $this->jsonError('That game is not yours to open.', 403); return null; }
        return $g;
    }
    private function post(): bool { if (Flight::request()->method !== 'POST') return false; $this->validateCSRF(); return true; }
    /** The editors are plain JSON clients: answer with the bare object they expect, not the {success, data} envelope. */
    private function raw($obj, int $code = 200): void { http_response_code($code); header('Content-Type: application/json'); header('Cache-Control: no-store'); echo json_encode($obj, JSON_UNESCAPED_SLASHES); exit; }

    public function token($params = []): void { if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return; $this->raw(['token' => csrf_token(), 'slug' => (string) $g->slug, 'title' => (string) $g->title]); }
    public function sfx($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        try {
            if ($this->post()) { $this->raw(['ok' => true, 'sounds' => GameStore::saveSfx($g->slug, $this->getJsonInput() ?? [])]); }
            $this->raw(GameStore::readSfx($g->slug));
        } catch (\InvalidArgumentException $e) { $this->raw(['error' => $e->getMessage()], 400); }
    }
    public function sounds($params = []): void { if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return; $this->raw(GameStore::sounds($g->slug)); }
    public function tuning($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        try {
            if ($this->post()) { $this->raw(['ok' => true, 'count' => GameStore::saveTuning($g->slug, $this->getJsonInput() ?? [])]); }
            $this->raw(GameStore::readTuning($g->slug));
        } catch (\InvalidArgumentException $e) { $this->raw(['error' => $e->getMessage()], 400); }
    }
    public function musicsave($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return; if (!$this->post()) { $this->raw(['error' => 'POST'], 405); }
        $b = $this->getJsonInput() ?? [];
        try { $this->raw(['ok' => true, 'action' => GameStore::saveSong($g->slug, (string) ($b['name'] ?? ''), (string) ($b['snippet'] ?? '')), 'file' => 'js/music-themes.js']); }
        catch (\InvalidArgumentException $e) { $this->raw(['error' => $e->getMessage()], 400); }
    }
    public function sprites($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        try { $this->raw(GameStore::readSprites($g->slug)); } catch (\RuntimeException $e) { $this->raw(['error' => $e->getMessage()], 500); }
    }
    public function spritesave($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return; if (!$this->post()) { $this->raw(['error' => 'POST'], 405); }
        try { $in = $this->getJsonInput() ?? []; $e = GameStore::saveSprite($g->slug, $in); GameStore::activity($g->slug, (string) ($this->member->username ?: $this->member->email), (($e['kind'] ?? '') === 'model' ? 'used a new 3D model for ' : 'saved the sprite ') . ($in['key'] ?? '?') . (!empty($in['take']['id']) ? ' (from job ' . $in['take']['id'] . ')' : '') . ': assets/' . $e['file']); GameStore::emit($g->slug, 'note', ['text' => 'sprite saved by ' . ($this->member->username ?: $this->member->email) . ': assets/' . $e['file']]); $this->raw(['ok' => true, 'sprite' => $e] + GameStore::readSprites($g->slug)); }
        catch (\InvalidArgumentException $e) { $this->raw(['error' => $e->getMessage()], 400); }
    }
    public function spritedelete($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return; if (!$this->post()) { $this->raw(['error' => 'POST'], 405); }
        $b = $this->getJsonInput() ?? [];
        try { GameStore::deleteSprite($g->slug, (string) ($b['key'] ?? ''), !empty($b['file'])); $this->raw(['ok' => true] + GameStore::readSprites($g->slug)); }
        catch (\InvalidArgumentException $e) { $this->raw(['error' => $e->getMessage()], 400); }
    }
    public function sketch($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        try {
            if ($this->post()) { $this->raw(GameStore::startSketch($g->slug, $this->getJsonInput() ?? [], (int) $this->member->id)); }
            $this->raw(GameStore::sketchStatus($g->slug, (string) $this->getParam('id', '')));
        } catch (\InvalidArgumentException $e) { $this->raw(['error' => $e->getMessage()], 400); }
        catch (\RuntimeException $e) { $this->raw(['error' => $e->getMessage()], 409); }
    }
    public function spritehints($params = []): void { // the rigger's marks for one sprite: GET ?key=, POST { key, plan, half, joints, notes }
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        try {
            if ($this->post()) { $b = $this->getJsonInput() ?? []; $this->raw(['ok' => true] + GameStore::saveHints($g->slug, (string) ($b['key'] ?? ''), $b)); }
            $this->raw(GameStore::readHints($g->slug, (string) $this->getParam('key', '')));
        } catch (\InvalidArgumentException $e) { $this->raw(['error' => $e->getMessage()], 400); } catch (\RuntimeException $e) { $this->raw(['error' => $e->getMessage()], 500); }
    }
    /** POST /gameapi/ux/<slug>: the editors' ergonomics events (kit/editor/ux.js), appended to .studio/ux.jsonl for the retrospective.
     *  No CSRF token (sent by sendBeacon as a page closes); sign-in and access to the game are required, and only known fields are kept. */
    public function ux($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return; $b = $this->getJsonInput() ?? []; $who = (int) $this->member->id; $lines = '';
        foreach (array_slice((array) ($b['items'] ?? []), 0, 50) as $it) { if (!is_array($it)) continue; $ev = (string) ($it['ev'] ?? ''); if (!in_array($ev, ['error', 'fail', 'act', 'undo', 'tab', 'session'], true)) continue;
            $keep = ['ev' => $ev, 'page' => preg_replace('/[^a-z]/', '', (string) ($it['page'] ?? '')), 'at' => time(), 'by' => $who];
            foreach (['msg' => 200, 'where' => 60, 'name' => 40, 'detail' => 60, 'id' => 30, 'tab' => 30, 'path' => 80, 'method' => 8, 'via' => 10] as $k => $n) if (isset($it[$k]) && is_scalar($it[$k])) $keep[$k] = mb_substr((string) $it[$k], 0, $n);
            foreach (['status', 'secs', 'acts', 'errors', 'fails', 'undos', 't'] as $k) if (isset($it[$k]) && is_numeric($it[$k])) $keep[$k] = (int) $it[$k];
            $lines .= json_encode($keep, JSON_UNESCAPED_SLASHES) . "\n"; }
        $f = GameStore::studioDir($g->slug) . '/ux.jsonl'; if (is_file($f) && filesize($f) > 2 * 1024 * 1024) @rename($f, $f . '.1'); // keep it small: one old file at most
        if ($lines !== '') file_put_contents($f, $lines, FILE_APPEND | LOCK_EX);
        http_response_code(204); exit;
    }
    /** A 3D thumbnail strip the sprite page rendered (lib: none, it is a cache): POST { name, data: a data:image/webp URL } →
     *  games/<slug>/thumbs/<name>.webp, kept out of git and out of published builds. */
    public function thumb($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return; if (!$this->post()) { $this->raw(['error' => 'POST'], 405); }
        $b = $this->getJsonInput() ?? []; $name = (string) ($b['name'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,90}$/', $name)) $this->raw(['error' => 'a thumbnail name is lowercase letters, digits, - and _'], 400);
        if (!preg_match('#^data:image/webp;base64,([A-Za-z0-9+/=]+)$#', (string) ($b['data'] ?? ''), $m)) $this->raw(['error' => 'data must be a data:image/webp URL'], 400);
        $bin = base64_decode($m[1], true); if ($bin === false || strlen($bin) > 1024 * 1024 || substr($bin, 0, 4) !== 'RIFF' || substr($bin, 8, 4) !== 'WEBP') $this->raw(['error' => 'not a WebP under 1 MB'], 400);
        $d = GameStore::dir((string) $g->slug); if (!is_dir("$d/thumbs")) mkdir("$d/thumbs", 0775, true);
        $gi = "$d/.gitignore"; $have = is_file($gi) ? (string) file_get_contents($gi) : ''; if (!preg_match('#^/?thumbs/?$#m', $have)) file_put_contents($gi, rtrim($have) . ($have === '' ? '' : "\n") . "thumbs/\n");
        GameStore::writeAtomic("$d/thumbs/$name.webp", $bin);
        http_response_code(204); exit;
    }
    /** The bestiary (js/bestiary.js): GET it as JSON; POST { resist, stats } rewrites it (GameStore::saveBestiary). Used by the
     *  game's own bestiary editor through Game::gameeditor, which points its /api/bestiary here. */
    public function bestiary($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return; $slug = (string) $g->slug;
        try {
            if ($this->post()) { $n = GameStore::saveBestiary($slug, $this->getJsonInput() ?? []); GameStore::activity($slug, (string) ($this->member->username ?: $this->member->email), "saved the bestiary ({$n} rows of resistances and stats)"); GameStore::emit($slug, 'note', ['text' => 'bestiary saved: js/bestiary.js']); $this->raw(['ok' => true, 'rows' => $n, 'file' => 'js/bestiary.js']); }
            $this->raw(GameStore::readBestiary($slug) ?: (object) []);
        } catch (\InvalidArgumentException $e) { $this->raw(['error' => $e->getMessage()], 400); }
        catch (\RuntimeException $e) { $this->raw(['error' => $e->getMessage()], 500); }
    }
    /** The events (lib/Rules.php): GET the vocabulary, rules and proposals; POST { do: save (rules) | describe (text) | add (id) |
     *  dismiss (id) | wire (extra?) | ask (what) }. */
    public function events($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return; $slug = (string) $g->slug; $who = (int) $this->member->id;
        try {
            if ($this->post()) { $b = $this->getJsonInput() ?? []; $out = [];
                switch ((string) ($b['do'] ?? '')) {
                    case 'save': $out = ['saved' => count(Rules::save($slug, (array) ($b['rules'] ?? [])))]; break;
                    case 'describe': $out = Rules::describe($slug, (string) ($b['text'] ?? ''), $who, '', '', (string) ($b['about'] ?? '')); break;
                    case 'add': $out = ['added' => Rules::settleProposal($slug, (string) ($b['id'] ?? ''), true)]; break;
                    case 'dismiss': Rules::settleProposal($slug, (string) ($b['id'] ?? ''), false); break;
                    case 'build': $out = Rules::buildMissing($slug, (string) ($b['id'] ?? ''), $who); break;
                    case 'catalogue': $r = Rules::catalogue($slug, $who); $out = ['queued' => !empty($r['queued'])]; break;
                    case 'convert': $r = Rules::convert($slug, isset($b['items']) ? (array) $b['items'] : [(string) ($b['item'] ?? '')], $who, trim(mb_substr((string) ($b['also'] ?? ''), 0, 600))); $out = ['queued' => !empty($r['queued'])]; break;
                    case 'wire': $r = Rules::wire($slug, $who, trim(mb_substr((string) ($b['extra'] ?? ''), 0, 600))); $out = ['queued' => !empty($r['queued'])]; break;
                    case 'ask': $r = Rules::askFor($slug, (string) ($b['what'] ?? ''), $who); $out = ['queued' => !empty($r['queued'])]; break;
                    default: $this->raw(['error' => 'do: save, describe, add, dismiss, build, catalogue, convert, wire or ask'], 400);
                }
                $what = ['save' => 'saved the event rules (' . ($out['saved'] ?? 0) . ' rules)', 'describe' => 'asked for event rules' . (!empty($b['about']) && $b['about'] !== '*' ? ' (about ' . $b['about'] . ')' : '') . ': ' . ($b['text'] ?? ''), 'add' => 'added event rules ' . implode(', ', (array) ($out['added'] ?? [])), 'wire' => 'asked the builder to wire events in' . (!empty($b['extra']) ? ': ' . $b['extra'] : ''), 'ask' => 'asked the builder for a new event action: ' . ($b['what'] ?? ''), 'build' => 'asked the builder to add what an event concept needs, then write its rules', 'catalogue' => 'asked the builder to list what the game does built in', 'convert' => 'asked the builder to make built-in behaviour rules: ' . implode(', ', isset($b['items']) ? (array) $b['items'] : [(string) ($b['item'] ?? '')])][(string) ($b['do'] ?? '')] ?? '';
                if ($what !== '') GameStore::activity($slug, (string) ($this->member->username ?: $this->member->email), $what);
                $this->raw($out + Rules::status($slug));
            }
            $this->raw(Rules::status($slug));
        } catch (\InvalidArgumentException $e) { $this->raw(['error' => $e->getMessage()], 400); }
        catch (\RuntimeException $e) { $this->raw(['error' => $e->getMessage()], 409); }
    }
    /** The 3D cast (lib/Cast.php): GET its items and states; POST { do: list | make (keys?) | wire | revise (key, note) | edit (key, fields | drop) | request (text) | go (id, keys?) | cancel (id) | keepall (keys?) | use (key, v) }. */
    public function cast($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return; $slug = (string) $g->slug; $who = (int) $this->member->id;
        try {
            if ($this->post()) { $b = $this->getJsonInput() ?? []; $out = [];
                switch ((string) ($b['do'] ?? '')) {
                    case 'list': $out = Cast::startList($slug, $who); break;
                    case 'make': $out = ['started' => Cast::make($slug, array_values(array_map('strval', (array) ($b['keys'] ?? []))), $who)]; break;
                    case 'wire': $r = Cast::wire($slug, $who); $out = ['queued' => !empty($r['queued'])]; break;
                    case 'revise': $out = Cast::revise($slug, (string) ($b['key'] ?? ''), trim(mb_substr((string) ($b['note'] ?? ''), 0, 600)), $who); break;
                    case 'request': $out = Cast::request($slug, (string) ($b['text'] ?? ''), $who); break;
                    case 'go': $out = ['started' => Cast::go($slug, (string) ($b['id'] ?? ''), array_values(array_map('strval', (array) ($b['keys'] ?? []))), $who)]; break;
                    case 'cancel': Cast::cancelRequest($slug, (string) ($b['id'] ?? '')); break;
                    case 'use': $out = ['item' => Cast::useVersion($slug, (string) ($b['key'] ?? ''), (string) ($b['v'] ?? ''))]; break;
                    case 'keepall': $out = ['kept' => Cast::keepRevisions($slug, array_values(array_map('strval', (array) ($b['keys'] ?? []))))]; break;
                    case 'edit': $out = ['item' => Cast::editItem($slug, (string) ($b['key'] ?? ''), (array) ($b['fields'] ?? []) + (!empty($b['drop']) ? ['drop' => true] : []))]; break;
                    default: $this->raw(['error' => 'do: list, make, wire, revise, edit, request, go, cancel, keepall or use'], 400);
                }
                $what = ['list' => 'asked the artist to list the 3D cast', 'make' => 'started model jobs for: ' . implode(', ', (array) ($out['started'] ?? [])), 'wire' => 'asked the builder to wire the 3D cast in', 'revise' => 'asked for a revision of ' . ($b['key'] ?? '') . ': ' . ($b['note'] ?? ''), 'edit' => (!empty($b['drop']) ? 'dropped ' : 'edited ') . ($b['key'] ?? '') . ' in the 3D cast', 'request' => 'asked for a change to the 3D models: ' . ($b['text'] ?? ''), 'go' => 'started revisions of: ' . implode(', ', (array) ($out['started'] ?? [])), 'keepall' => 'used the revisions of: ' . implode(', ', (array) ($out['kept'] ?? [])), 'use' => 'put an earlier version of ' . ($b['key'] ?? '') . ' back in use'][(string) ($b['do'] ?? '')] ?? '';
                if ($what !== '') GameStore::activity($slug, (string) ($this->member->username ?: $this->member->email), $what);
                $this->raw($out + Cast::status($slug));
            }
            $this->raw(Cast::status($slug));
        } catch (\InvalidArgumentException $e) { $this->raw(['error' => $e->getMessage()], 400); }
        catch (\RuntimeException $e) { $this->raw(['error' => $e->getMessage()], 409); }
    }
    public function sketches($params = []): void { if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return; $this->raw(GameStore::listSketches($g->slug)); }
    public function sketchdelete($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return; if (!$this->post()) { $this->raw(['error' => 'POST'], 405); }
        try { GameStore::deleteSketch($g->slug, (string) (($this->getJsonInput() ?? [])['id'] ?? '')); $this->raw(['ok' => true] + GameStore::listSketches($g->slug)); }
        catch (\InvalidArgumentException $e) { $this->raw(['error' => $e->getMessage()], 400); }
    }
    public function musicdelete($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return; if (!$this->post()) { $this->raw(['error' => 'POST'], 405); }
        try { GameStore::deleteSong($g->slug, (string) (($this->getJsonInput() ?? [])['name'] ?? '')); $this->raw(['ok' => true, 'file' => 'js/music-themes.js']); }
        catch (\InvalidArgumentException $e) { $this->raw(['error' => $e->getMessage()], 400); }
    }
}
