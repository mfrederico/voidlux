<?php
/**
 * Studio — one game, built around the game itself: the running game in the middle, pause and click anything in it, and one
 * card says what that thing is (its look, its rules, what the game does for it in code, its resistances). It reads and writes
 * the same game folders as the Game page; it is another way in, not another kind of game.
 *   GET /studio/open/<slug>    the page      GET /studio/things/<slug>    the list      GET /studio/card/<slug>?key=    a card
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
        $this->render('studio/open', ['title' => (string) $g->title . ' · Studio', 'slug' => (string) $g->slug, 'gameTitle' => (string) $g->title], false);
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
