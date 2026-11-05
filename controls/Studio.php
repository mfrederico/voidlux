<?php
/**
 * Studio — one game, built around the game itself: the running game in the middle, pause and click anything in it, and one
 * card says what that thing is (its look, its rules, what the game does for it in code, its resistances). It reads and writes
 * the same game folders as the Game page; it is another way in, not another kind of game.
 *   GET /studio/open/<slug>    the page      GET /studio/things/<slug>    the list      GET /studio/card/<slug>?key=    a card
 *   POST /studio/ways/<slug> { text, key }    where a change could go and what each way costs; nothing starts (lib/ChangeBox.php)
 *   POST /studio/change/<slug> { way, text, key, values? }    starts it
 *   POST /studio/words/<slug> { texts }    which of these words can be changed directly      POST /studio/wording/<slug> { old, new, site, key }    changes them (lib/GameWords.php)
 *   GET /studio/work/<slug>    what is running and what waits on a person, and what this game still needs to work here
 *   GET /studio/timeline/<slug>    everything that changed      POST /studio/undo/<slug> { id }    undoes one entry (lib/Timeline.php)
 */
namespace app;

use \Flight as Flight;

class Studio extends BaseControls\Control {
    private function game($params, bool $json = true): ?object {
        $slug = (string) ($params['operation']->name ?? ''); $g = preg_match(GameStore::SLUG, $slug) ? GameStore::row($slug) : null;
        if (!$g) { $json ? Flight::jsonError('No such game.', 404) : Flight::notFound(); return null; }
        if (!GameStore::canOpen($g, (int) $this->member->id, (int) $this->member->level)) { $json ? Flight::jsonError('That game is not yours to open.', 403) : Flight::redirect('/forge'); return null; }
        return $g;
    }
    public function open($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params, false)) return;
        $v = []; foreach (['sfx', 'tracker', 'sprites', 'config', 'pose'] as $e) $v[$e] = (int) @filemtime(GameStore::root() . "/kit/editor/{$e}/index.html");
        $own = array_values(array_filter(Game::GAME_EDITORS, fn($e) => is_file(GameStore::dir((string) $g->slug) . "/editor/{$e}/index.html")));
        $this->render('studio/open', ['title' => (string) $g->title . ' · Studio', 'slug' => (string) $g->slug, 'gameTitle' => (string) $g->title, 'editorV' => $v, 'ownEditors' => $own], false);
    }
    public function ways($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return; $b = $this->getJsonInput() ?? [];
        try { Flight::jsonSuccess(ChangeBox::ways((string) $g->slug, (string) ($b['text'] ?? ''), (string) ($b['key'] ?? ''), (string) ($b['label'] ?? ''), (string) ($b['context'] ?? ''))); } catch (\InvalidArgumentException $e) { Flight::jsonError($e->getMessage(), 400); }
    }
    public function change($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return; $b = $this->getJsonInput() ?? [];
        try { Flight::jsonSuccess(ChangeBox::start((string) $g->slug, (string) ($b['way'] ?? ''), (string) ($b['text'] ?? ''), (string) ($b['key'] ?? ''), (array) ($b['values'] ?? []), (int) $this->member->id, (string) ($this->member->username ?: $this->member->email), (string) ($b['label'] ?? ''), (string) ($b['context'] ?? ''))); }
        catch (\InvalidArgumentException $e) { Flight::jsonError($e->getMessage(), 400); } catch (\RuntimeException $e) { Flight::jsonError($e->getMessage(), 409); }
    }
    public function words($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return; $b = $this->getJsonInput() ?? [];
        Flight::jsonSuccess(['status' => GameWords::status((string) $g->slug, array_slice((array) ($b['texts'] ?? []), 0, 60))]);
    }
    public function wording($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return; $b = $this->getJsonInput() ?? [];
        try { Flight::jsonSuccess(GameWords::change((string) $g->slug, (string) ($b['old'] ?? ''), (string) ($b['new'] ?? ''), (string) ($b['site'] ?? ''), (string) ($this->member->username ?: $this->member->email), (string) ($b['key'] ?? ''))); }
        catch (\InvalidArgumentException $e) { Flight::jsonError($e->getMessage(), 400); } catch (\RuntimeException $e) { Flight::jsonError($e->getMessage(), 409); }
    }
    public function work($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return; $slug = (string) $g->slug;
        $d = GameStore::dir($slug); $art = GameStore::artState($slug); $q = GameStore::studioDir($slug) . '/art-queue.jsonl'; $queued = is_file($q) ? count(array_filter(array_map('trim', file($q)))) : 0;
        $props = array_values(array_filter(Rules::proposals($slug), fn($p) => in_array($p['state'], ['reading', 'proposed', 'building', 'failed'], true)));
        $revisions = []; if (is_file(Cast::path($slug))) foreach (Cast::waitingRevisions($slug) as $k => $w) $revisions[] = ['key' => $k, 'file' => $w['file'], 'id' => $w['id']];
        $brief = (string) $g->brief; $v = Rules::vocab($slug); $tasks = GameStore::tasks($slug);
        Flight::jsonSuccess(['artists' => (int) ($art['working'] ?? ($art['busy'] ? 1 : 0)), 'queued' => $queued, 'proposals' => $props, 'revisions' => $revisions,
            'setup' => ['brief' => trim($brief) !== '' && $brief !== 'A small game on the VoidLux kit.', 'planned' => count($tasks) > 0 || (bool) GameStore::soaks($slug), /* a game that has been played has been built, task list or not */ 'subjects' => $v ? count((array) $v['subjects']) : 0]]);
    }
    public function setup($params = []): void { // the first run's one paid step that is not building: teach the game to answer the Studio
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return;
        try { $r = Rules::wire((string) $g->slug, (int) $this->member->id, "Give every subject a short \"name\" a person would use, and begin its \"about\" with it (\"Subject names\" in ../../kit/AGENTS.md). So the Studio can work with this game (\"Opening in the Studio\", \"Picking in the Studio\", \"Words in the Studio\" and \"Rules about the interface\" in ../../kit/AGENTS.md; games/lonelycat/js/forge-pick.js is an example): add js/forge-pick.js, after main.js in index.html, with window.forgePick answering { key: <subject key>, x, y, r } at a point of the canvas through this game's own drawing transform, window.forgeScenes with a few useful places to jump to, and ForgeWords.wrap for any text function of its own. Declare the title, pause and game over scenes as subjects screen:<scene>, group ui, with screen-open and screen-close in the vocabulary (Game.go sends them)."); Flight::jsonSuccess(['queued' => !empty($r['queued'])]); }
        catch (\InvalidArgumentException $e) { Flight::jsonError($e->getMessage(), 400); } catch (\RuntimeException $e) { Flight::jsonError($e->getMessage(), 409); }
    }
    public function timeline($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        Flight::jsonSuccess(['entries' => Timeline::entries((string) $g->slug)]);
    }
    public function undo($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return; $b = $this->getJsonInput() ?? [];
        try { Flight::jsonSuccess(Timeline::undo((string) $g->slug, (string) ($b['id'] ?? ''), (string) ($this->member->username ?: $this->member->email))); }
        catch (\InvalidArgumentException $e) { Flight::jsonError($e->getMessage(), 400); } catch (\RuntimeException $e) { Flight::jsonError($e->getMessage(), 409); }
    }
    public function things($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        try { Flight::jsonSuccess(['things' => Inspector::things((string) $g->slug)]); } catch (\RuntimeException $e) { Flight::jsonError($e->getMessage(), 500); }
    }
    public function card($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        try { Flight::jsonSuccess(['card' => Inspector::card((string) $g->slug, (string) $this->getParam('key', ''))]); }
        catch (\InvalidArgumentException $e) { Flight::jsonError($e->getMessage(), 404); } catch (\RuntimeException $e) { Flight::jsonError($e->getMessage(), 500); }
    }
}
