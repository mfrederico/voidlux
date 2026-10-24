<?php
/**
 * Game — one game's page and the actions on it. The page (/game/view/<slug>) has the tabs: play, build, test, the
 * editors, design, history, ship. Everything else here is JSON for that page (fetch with the CSRF token as
 * X-CSRF-TOKEN); the editors' own JSON lives in Gameapi.
 *
 *   GET  /game/view/<slug>                 the page
 *   GET  /game/status/<slug>               meta + agent state (polled)
 *   GET  /game/events/<slug>?since=N       the agent's events after N (polled)
 *   GET  /game/doc/<slug>                  GAME.md;  POST saves it
 *   GET  /game/soakreport/<slug>?id=       one soak's full report;  /game/soakshot/<slug>?id=  its screenshot
 *   POST /game/agent/<slug>  { mode, prompt }    start a run (chat, pipeline, review, fix, soak "secs fast")
 *   POST /game/stop/<slug>  /game/reset/<slug>   stop the runner / forget the session
 *   POST /game/checkpoint/<slug> { label }   /game/rollback/<slug> { ref }
 *   POST /game/publish/<slug>                /game/card/<slug> { title, status, price, tags, notes, team_id }
 */
namespace app;

use \Flight as Flight;
use \app\Bean;

class Game extends BaseControls\Control {
    /** The game named in the route, after the access check, or a 404/403 answered. */
    private function game($params, bool $json = true): ?object {
        $slug = (string) ($params['operation']->name ?? '');
        $g = preg_match(GameStore::SLUG, $slug) ? GameStore::row($slug) : null;
        if (!$g) { $json ? $this->jsonError('No such game.', 404) : Flight::notFound(); return null; }
        if (!GameStore::canOpen($g, (int) $this->member->id, (int) $this->member->level)) { $json ? $this->jsonError('That game is not yours to open.', 403) : Flight::redirect('/forge'); return null; }
        return $g;
    }
    private function body(): array { return $this->getJsonInput() ?? []; }

    public function view($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$g = $this->game($params, false)) return;
        $meta = GameStore::meta($g);
        $teams = Bean::getAll('SELECT t.id, t.name FROM team t JOIN teammember tm ON t.id = tm.team_id WHERE tm.member_id = ? AND t.is_active = 1 ORDER BY t.name', [(int) $this->member->id]);
        $this->render('game/view', ['title' => $meta['title'], 'game' => $meta, 'teams' => $teams, 'checkpoints' => GameStore::checkpoints($meta['slug']), 'soaks' => GameStore::soaks($meta['slug']), 'gameMd' => is_file(GameStore::dir($meta['slug']) . '/GAME.md') ? file_get_contents(GameStore::dir($meta['slug']) . '/GAME.md') : '', 'agentNote' => $this->agentNote()]);
    }
    private function agentNote(): string {
        try { [, , $note] = GameAgent::environment(GameStore::root()); return 'agents run on ' . $note; } catch (\Throwable $e) { return $e->getMessage(); }
    }

    public function status($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        $this->jsonSuccess(['game' => GameStore::meta($g), 'checkpoints' => GameStore::checkpoints($g->slug), 'soaks' => GameStore::soaks($g->slug)]);
    }
    public function events($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        $this->jsonSuccess(GameStore::events($g->slug, (int) $this->getParam('since', 0)) + ['agent' => GameStore::agentState($g->slug)]);
    }
    public function doc($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        $p = GameStore::dir($g->slug) . '/GAME.md';
        if (Flight::request()->method === 'POST') { $this->validateCSRF(); GameStore::writeAtomic($p, (string) ($this->body()['text'] ?? '')); $this->jsonSuccess(['ok' => true], 'Saved GAME.md.'); return; }
        $this->jsonSuccess(['text' => is_file($p) ? file_get_contents($p) : '']);
    }
    public function soakreport($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        $id = (string) $this->getParam('id'); if (!preg_match('/^[0-9-]+$/', $id)) { $this->jsonError('bad id'); return; }
        $f = GameStore::dir($g->slug) . '/.studio/soaks/' . $id . '.json'; if (!is_file($f)) { $this->jsonError('no such soak', 404); return; }
        $this->jsonSuccess(json_decode((string) file_get_contents($f), true) ?: []);
    }
    public function soakshot($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        $id = (string) $this->getParam('id'); $f = preg_match('/^[0-9-]+$/', $id) ? GameStore::dir($g->slug) . '/.studio/soaks/' . $id . '.png' : '';
        if ($f === '' || !is_file($f)) { Flight::notFound(); return; }
        header('Content-Type: image/png'); header('Cache-Control: no-store'); readfile($f); exit;
    }

    public function agent($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return;
        $b = $this->body(); $mode = (string) ($b['mode'] ?? 'chat'); $prompt = trim((string) ($b['prompt'] ?? ''));
        if (!in_array($mode, ['chat', 'pipeline', 'review', 'fix', 'soak'], true)) { $this->jsonError('mode is chat, pipeline, review, fix or soak'); return; }
        if ($mode === 'chat' && $prompt === '') { $this->jsonError('Say what to do.'); return; }
        try { $this->jsonSuccess(GameStore::startRunner($g->slug, $mode, $prompt, (int) $this->member->id), 'Started.'); }
        catch (\RuntimeException $e) { $this->jsonError($e->getMessage(), 409); }
    }
    public function stop($params = []): void { if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return; $this->jsonSuccess(GameStore::stopRunner($g->slug), 'Stopped.'); }
    public function reset($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return;
        $s = GameStore::agentState($g->slug); $s['session'] = null; GameStore::saveAgentState($g->slug, $s); GameStore::emit($g->slug, 'note', ['text' => 'next run starts a fresh session']);
        $this->jsonSuccess($s, 'The next run starts fresh.');
    }
    public function checkpoint($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return;
        $tag = GameStore::checkpoint($g->slug, (string) ($this->body()['label'] ?? 'checkpoint'));
        $this->jsonSuccess(['tag' => $tag, 'checkpoints' => GameStore::checkpoints($g->slug)], "Checkpoint {$tag}.");
    }
    public function rollback($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return;
        try { $this->jsonSuccess(['checkpoints' => GameStore::rollback($g->slug, (string) ($this->body()['ref'] ?? ''))], 'Rolled back.'); }
        catch (\Throwable $e) { $this->jsonError($e->getMessage()); }
    }
    public function publish($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return;
        try { $this->jsonSuccess(GameStore::publish($g), 'Published.'); } catch (\Throwable $e) { $this->jsonError($e->getMessage(), 500); }
    }
    public function card($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return;
        $b = $this->body();
        if (isset($b['title'])) $g->title = mb_substr(trim((string) $b['title']), 0, 60) ?: $g->title;
        if (isset($b['status']) && in_array($b['status'], ['new', 'designed', 'building', 'playable', 'polished', 'published'], true)) $g->status = $b['status'];
        if (array_key_exists('price', $b)) $g->price = ($b['price'] === null || $b['price'] === '') ? null : round((float) $b['price'], 2);
        if (isset($b['tags'])) $g->tags = json_encode(array_values(array_filter(array_map(fn($t) => mb_substr(trim((string) $t), 0, 30), (array) $b['tags']))));
        if (isset($b['notes'])) $g->notes = mb_substr((string) $b['notes'], 0, 4000);
        if (array_key_exists('team_id', $b)) {
            $tid = (int) $b['team_id'];
            if ($tid > 0) { $t = Bean::load('team', $tid); if (!$t->id || !$t->box()->hasMember((int) $this->member->id)) { $this->jsonError('Not one of your teams.'); return; } }
            $g->teamId = $tid > 0 ? $tid : null;
        }
        Bean::store($g);
        $this->jsonSuccess(GameStore::meta($g), 'Saved.');
    }
}
