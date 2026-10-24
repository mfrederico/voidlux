<?php
/**
 * Gameapi — the editors' JSON: the SFX editor, the tracker and the config editor (kit/editor/*, plain pages served
 * from /editor/) read and write one game's files through here. Every route is /gameapi/<method>/<slug>; the editors
 * fetch /gameapi/token/<slug> first and send it back as X-CSRF-TOKEN on writes (they are static pages: no form token).
 *
 *   GET  token      GET/POST sfx      GET sounds      GET/POST tuning      POST musicsave { name, snippet }     POST musicdelete { name }
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
    public function musicdelete($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return; if (!$this->post()) { $this->raw(['error' => 'POST'], 405); }
        try { GameStore::deleteSong($g->slug, (string) (($this->getJsonInput() ?? [])['name'] ?? '')); $this->raw(['ok' => true, 'file' => 'js/music-themes.js']); }
        catch (\InvalidArgumentException $e) { $this->raw(['error' => $e->getMessage()], 400); }
    }
}
