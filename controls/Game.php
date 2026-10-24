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
 *   GET  /game/notes/<slug>                 the producer's notes;  POST /game/note/<slug> { note } adds one (from the game's note mode)
 *   POST /game/notestate/<slug> { id, state }   open | sent | done, or state 'delete'
 *   POST /game/sendnotes/<slug> { ids, text }   hand a batch to the builder (a chat run, or the inbox while one runs)
 *   GET  /game/noteshot/<slug>?id=          a note's screenshot crop
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
        GameStore::browserSeen($g->slug); // this viewer's page is open: it can run playtests
        $req = GameStore::soakRequest($g->slug);
        $this->jsonSuccess(GameStore::events($g->slug, (int) $this->getParam('since', 0)) + ['agent' => GameStore::agentState($g->slug), 'soakRequest' => ($req && empty($req['claimed'])) ? $req : null]);
    }
    public function doc($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        $p = GameStore::dir($g->slug) . '/GAME.md';
        if (Flight::request()->method === 'POST') { $this->validateCSRF(); GameStore::writeAtomic($p, (string) ($this->body()['text'] ?? '')); $this->jsonSuccess(['ok' => true], 'Saved GAME.md.'); return; }
        $text = is_file($p) ? (string) file_get_contents($p) : '';
        $this->jsonSuccess(['text' => $text, 'html' => self::md($text)]);
    }
    /** GAME.md as a page: the runtime's markdown, with task-list lines as boxes. */
    public static function md(string $text): string {
        // the planner hard-wraps paragraphs at ~120 columns; joined back so a paragraph reads as one, lists and headings left alone
        $text = preg_replace('/(?<=\S)\n(?=[A-Za-z(`*_"\'][^\n]*)(?![-*#>|\d]|\s*- )/', ' ', $text);
        $html = \app\MarkdownParser::parseSafe($text);
        $html = preg_replace('/<(h[1-6]) id="[^"]*">/', '<$1>', $html);  // heading ids would steal the page's tab hashes (#design, #build)
        $html = preg_replace('/<li>\[ \] /', '<li class="todo"><span class="box"></span> ', $html);
        $html = preg_replace('/<li>\[x\] /i', '<li class="done"><span class="box on">✓</span> ', $html);
        return $html;
    }
    /** The page takes a pending playtest request (first viewer wins). */
    public function soakclaim($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return;
        $ok = GameStore::claimSoak($g->slug, (string) ($this->body()['id'] ?? '')); $this->jsonSuccess(['claimed' => $ok], $ok ? 'Yours.' : 'Someone else took it.');
    }
    /** The page's report for a playtest it ran. */
    public function soakdone($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return;
        $b = $this->body(); $rep = $b['report'] ?? null; if (!is_array($rep)) { $this->jsonError('a report is needed'); return; }
        try { $rep['by'] = (string) ($this->member->username ?: $this->member->email); $r = GameStore::finishBrowserSoak($g->slug, (string) ($b['id'] ?? ''), $rep); $this->jsonSuccess(['ok' => $r['ok']], 'Report stored.'); }
        catch (\Throwable $e) { $this->jsonError($e->getMessage()); }
    }
    public function soakreport($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        $id = (string) $this->getParam('id'); if (!preg_match('/^[0-9a-f-]+$/', $id)) { $this->jsonError('bad id'); return; }
        $f = GameStore::dir($g->slug) . '/.studio/soaks/' . $id . '.json'; if (!is_file($f)) { $this->jsonError('no such soak', 404); return; }
        $this->jsonSuccess(json_decode((string) file_get_contents($f), true) ?: []);
    }
    public function soakshot($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        $id = (string) $this->getParam('id'); $base = preg_match('/^[0-9a-f-]+$/', $id) ? GameStore::dir($g->slug) . '/.studio/soaks/' . $id : '';
        $f = $base !== '' && is_file($base . '.png') ? $base . '.png' : ($base !== '' && is_file($base . '.jpg') ? $base . '.jpg' : '');
        if ($f === '') { Flight::notFound(); return; }
        header('Content-Type: ' . (str_ends_with($f, '.png') ? 'image/png' : 'image/jpeg')); header('Cache-Control: no-store'); readfile($f); exit;
    }

    public function agent($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return;
        $b = $this->body(); $mode = (string) ($b['mode'] ?? 'chat'); $prompt = trim((string) ($b['prompt'] ?? ''));
        if (!in_array($mode, ['chat', 'pipeline', 'review', 'fix', 'soak'], true)) { $this->jsonError('mode is chat, pipeline, review, fix or soak'); return; }
        if ($mode === 'chat' && $prompt === '') { $this->jsonError('Say what to do.'); return; }
        if ($mode === 'chat' && GameStore::agentState($g->slug)['busy']) { // a message during a run waits in the inbox for the next run
            file_put_contents(GameStore::studioDir($g->slug) . '/inbox.txt', $prompt . "\n", FILE_APPEND);
            GameStore::emit($g->slug, 'note', ['text' => 'queued for the next run: ' . mb_substr($prompt, 0, 200)]);
            $this->jsonSuccess(GameStore::agentState($g->slug) + ['queued' => true], 'Queued for the next run.'); return;
        }
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
    // ---- the producer's notes: made in the game's note mode (kit-notes.js), batched here, sent to the builder ----
    private function noteRows(object $g): array {
        $out = [];
        $names = [];
        $me = (int) $this->member->id;
        $nameOf = function (int $mid) use (&$names): string { if (!isset($names[$mid])) { $m = Bean::load('member', $mid); $names[$mid] = (string) ($m->username ?: $m->email ?: 'someone'); } return $names[$mid]; };
        foreach (Bean::find('gamenote', 'game_id = ? ORDER BY id DESC', [(int) $g->id]) as $n) { $mid = (int) $n->memberId;
            $votes = array_values(Bean::find('gamenotevote', 'gamenote_id = ?', [(int) $n->id])); $comments = [];
            foreach (Bean::find('gamenotecomment', 'gamenote_id = ? ORDER BY id ASC', [(int) $n->id]) as $c) $comments[] = ['id' => (int) $c->id, 'by' => $nameOf((int) $c->memberId), 'mine' => (int) $c->memberId === $me, 'text' => (string) $c->text, 'at' => (string) $c->createdAt];
            $out[] = ['votes' => count($votes), 'voted' => (bool) array_filter($votes, fn($v) => (int) $v->memberId === $me), 'voters' => array_map(fn($v) => $nameOf((int) $v->memberId), $votes), 'comments' => $comments, 'byName' => $nameOf($mid), 'id' => (int) $n->id, 'scene' => (string) $n->scene, 'x' => (int) $n->x, 'y' => (int) $n->y, 'what' => json_decode((string) $n->what, true), 'text' => (string) $n->text, 'state' => (string) $n->state, 'shot' => (string) $n->shot !== '', 'createdAt' => (string) $n->createdAt, 'by' => (int) $n->memberId]; }
        return $out;
    }
    public function notes($params = []): void { if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return; $this->jsonSuccess(['notes' => $this->noteRows($g)]); }
    public function note($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return;
        $b = ($this->body()['note'] ?? []); if (!is_array($b) || trim((string) ($b['text'] ?? '')) === '') { $this->jsonError('a note needs words'); return; }
        $n = Bean::dispense('gamenote');
        $n->gameId = (int) $g->id; $n->memberId = (int) $this->member->id; $n->scene = mb_substr((string) ($b['scene'] ?? ''), 0, 40); $n->x = (int) ($b['x'] ?? 0); $n->y = (int) ($b['y'] ?? 0);
        $n->what = mb_substr(json_encode($b['what'] ?? null, JSON_UNESCAPED_SLASHES), 0, 4000); $n->text = mb_substr(trim((string) $b['text']), 0, 4000); $n->state = 'open'; $n->createdAt = date('c'); $n->shot = '';
        Bean::store($n);
        $shot = (string) ($b['shot'] ?? '');
        if (preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $shot, $m) && strlen($shot) < 400000) {
            $dir = GameStore::studioDir($g->slug) . '/notes'; if (!is_dir($dir)) mkdir($dir, 0775, true);
            file_put_contents($dir . '/' . (int) $n->id . '.jpg', base64_decode($m[1])); $n->shot = '.studio/notes/' . (int) $n->id . '.jpg'; Bean::store($n);
        }
        GameStore::emit($g->slug, 'note', ['text' => 'producer\'s note #' . (int) $n->id . ' (' . $n->scene . ' at ' . $n->x . ',' . $n->y . '): ' . mb_substr($n->text, 0, 160)]);
        if (!empty($b['build'])) { $this->sendBatch($g, [(int) $n->id], ''); }
        $this->jsonSuccess(['id' => (int) $n->id, 'notes' => $this->noteRows($g)], !empty($b['build']) ? 'Sent to the build.' : 'Note saved.');
    }
    private function noteOf(object $g, int $id): ?object { $n = Bean::load('gamenote', $id); return ($n->id && (int) $n->gameId === (int) $g->id) ? $n : null; }
    /** Toggle my upvote on a note. */
    public function notevote($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return;
        $n = $this->noteOf($g, (int) ($this->body()['id'] ?? 0)); if (!$n) { $this->jsonError('no such note', 404); return; }
        $v = Bean::findOne('gamenotevote', 'gamenote_id = ? AND member_id = ?', [(int) $n->id, (int) $this->member->id]);
        if ($v && $v->id) Bean::trash($v); else { $v = Bean::dispense('gamenotevote'); $v->gamenoteId = (int) $n->id; $v->memberId = (int) $this->member->id; $v->createdAt = date('c'); Bean::store($v); }
        $this->jsonSuccess(['notes' => $this->noteRows($g)], 'Saved.');
    }
    /** A comment under a note. */
    public function notecomment($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return;
        $b = $this->body(); $n = $this->noteOf($g, (int) ($b['id'] ?? 0)); if (!$n) { $this->jsonError('no such note', 404); return; }
        if (!empty($b['delete'])) { $c = Bean::load('gamenotecomment', (int) $b['delete']); if ($c->id && (int) $c->gamenoteId === (int) $n->id && ((int) $c->memberId === (int) $this->member->id || Flight::hasLevel(LEVELS['ADMIN']))) Bean::trash($c); }
        else { $text = mb_substr(trim((string) ($b['text'] ?? '')), 0, 4000); if ($text === '') { $this->jsonError('a comment needs words'); return; } $c = Bean::dispense('gamenotecomment'); $c->gamenoteId = (int) $n->id; $c->memberId = (int) $this->member->id; $c->text = $text; $c->createdAt = date('c'); Bean::store($c); }
        $this->jsonSuccess(['notes' => $this->noteRows($g)], 'Saved.');
    }
    /** The message a batch would send, for the producer to refine before sending. */
    public function sendpreview($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        $ids = array_filter(array_map('intval', explode(',', (string) $this->getParam('ids', ''))));
        try { $this->jsonSuccess(['message' => $this->composeBatch($g, $ids, (string) $this->getParam('intro', ''))]); } catch (\Throwable $e) { $this->jsonError($e->getMessage()); }
    }
    public function notestate($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return;
        $b = $this->body(); $n = Bean::load('gamenote', (int) ($b['id'] ?? 0));
        if (!$n->id || (int) $n->gameId !== (int) $g->id) { $this->jsonError('no such note', 404); return; }
        if (($b['state'] ?? '') === 'delete') { $f = GameStore::dir($g->slug) . '/' . $n->shot; if ((string) $n->shot !== '' && is_file($f)) @unlink($f); Bean::trash($n); }
        else { $n->state = (string) ($b['state'] ?? 'open'); Bean::store($n); }
        $this->jsonSuccess(['notes' => $this->noteRows($g)], 'Saved.');
    }
    public function sendnotes($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF(); if (!$g = $this->game($params)) return;
        $b = $this->body(); $ids = array_map('intval', (array) ($b['ids'] ?? []));
        if (!$ids) { $this->jsonError('pick at least one note'); return; }
        try { $r = $this->sendBatch($g, $ids, trim((string) ($b['text'] ?? '')), trim((string) ($b['message'] ?? ''))); $this->jsonSuccess(['notes' => $this->noteRows($g)] + $r, $r['queued'] ? 'Queued for the next run.' : 'Sent to the builder.'); }
        catch (\Throwable $e) { $this->jsonError($e->getMessage()); }
    }
    /** The batch's message as the forge composes it: every note with its votes and comments, most voted first. */
    private function composeBatch(object $g, array $ids, string $intro): string {
        $rows = [];
        foreach ($ids as $id) { $row = $this->noteOf($g, (int) $id); if ($row) $rows[] = $row; }
        if (!$rows) throw new \RuntimeException('none of those notes belong to this game');
        usort($rows, fn($a, $b) => Bean::count('gamenotevote', 'gamenote_id = ?', [(int) $b->id]) <=> Bean::count('gamenotevote', 'gamenote_id = ?', [(int) $a->id]));
        $lines = []; $n = 0; $names = [];
        $nameOf = function (int $mid) use (&$names): string { if (!isset($names[$mid])) { $m = Bean::load('member', $mid); $names[$mid] = (string) ($m->username ?: $m->email ?: 'someone'); } return $names[$mid]; };
        foreach ($rows as $row) {
            $votes = Bean::count('gamenotevote', 'gamenote_id = ?', [(int) $row->id]);
            $line = $row->box()->line(++$n) . ($votes ? " [{$votes} vote" . ($votes === 1 ? '' : 's') . ']' : '');
            foreach (Bean::find('gamenotecomment', 'gamenote_id = ? ORDER BY id ASC', [(int) $row->id]) as $c) $line .= "\n   - " . $nameOf((int) $c->memberId) . ': ' . trim((string) $c->text);
            $lines[] = $line;
        }
        return "The producers paused the game and pointed at things on the screen. Each note names the scene, the position (logical canvas coordinates) and what the kit found there; a screenshot crop path is relative to this folder and you can look at it with Read. Votes say what matters most; comments under a note refine or argue with it, and the last word there wins. Do what the notes ask (ask nothing; make the best call), node --check, soak, update GAME.md's Log, commit.\n"
             . ($intro !== '' ? "The producer adds: {$intro}\n" : '') . "\n" . implode("\n", $lines);
    }
    /** The batch as one message to the builder: a chat run now, or the inbox while a run is going. Marks the notes sent. */
    private function sendBatch(object $g, array $ids, string $intro, string $message = ''): array {
        $rows = []; foreach ($ids as $id) { $row = $this->noteOf($g, (int) $id); if ($row) $rows[] = $row; }
        $prompt = $message !== '' ? $message : $this->composeBatch($g, $ids, $intro);
        if (!$rows) throw new \RuntimeException('none of those notes belong to this game');
        $lines = $rows;
        $queued = GameStore::agentState($g->slug)['busy'];
        if ($queued) { file_put_contents(GameStore::studioDir($g->slug) . '/inbox.txt', $prompt . "\n", FILE_APPEND); GameStore::emit($g->slug, 'note', ['text' => count($lines) . ' producer note(s) queued for the next run']); }
        else GameStore::startRunner($g->slug, 'chat', $prompt, (int) $this->member->id);
        foreach ($rows as $row) { $row->state = 'sent'; $row->sentAt = date('c'); Bean::store($row); }
        return ['sent' => count($rows), 'queued' => $queued];
    }
    public function noteshot($params = []): void {
        if (!$this->requireLogin()) return; if (!$g = $this->game($params)) return;
        $n = Bean::load('gamenote', (int) $this->getParam('id', 0)); $f = ($n->id && (int) $n->gameId === (int) $g->id && (string) $n->shot !== '') ? GameStore::dir($g->slug) . '/' . $n->shot : '';
        if ($f === '' || !is_file($f)) { Flight::notFound(); return; }
        header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=3600'); readfile($f); exit;
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
