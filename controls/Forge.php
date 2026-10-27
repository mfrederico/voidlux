<?php
/**
 * Forge — every game a member can open, and the way to summon a new one.
 *   /forge            the games (mine and my teams')
 *   /forge/summon     the brief form; POST creates the game from a template and, when asked, starts the pipeline
 */
namespace app;

use \Flight as Flight;
use \app\Bean;

class Forge extends BaseControls\Control {
    public function index($params = []): void {
        if (!$this->requireLogin()) return;
        $games = [];
        foreach (GameStore::forMember((int) $this->member->id, Flight::hasLevel(LEVELS['ADMIN'])) as $g) {
            try { $games[] = GameStore::meta($g); } catch (\Throwable $e) { $games[] = ['slug' => (string) $g->slug, 'title' => (string) $g->title, 'status' => 'broken: ' . $e->getMessage(), 'brief' => '', 'template' => '', 'checkpoints' => 0, 'tasks' => ['done' => 0, 'total' => 0], 'lastSoak' => null, 'agent' => ['busy' => false], 'lastCommit' => '']; }
        }
        $this->render('forge/index', ['title' => 'The forge', 'games' => $games]);
    }

    /** The retrospective's ledger: every hour's measures, findings, note and proposals, newest first (lib/Retro.php). */
    public function retro($params = []): void {
        if (!$this->requireLogin()) return; if (!Flight::hasLevel(LEVELS['ADMIN'])) { Flight::redirect('/forge'); return; }
        $this->render('forge/retro', ['title' => 'The forge, hour by hour', 'entries' => Retro::entries(72)]);
    }

    /** POST /forge/revokeinvite { id }: withdraw a pending team invitation (the team's owner or an admin of it). JSON. */
    public function revokeinvite($params = []): void {
        if (!$this->requireLogin()) return; $this->validateCSRF();
        $b = $this->getJsonInput() ?? []; $inv = Bean::load('teaminvitation', (int) ($b['id'] ?? 0));
        if (!$inv->id || !empty($inv->acceptedAt)) { $this->jsonError('No such pending invitation.', 404); return; }
        $team = Bean::load('team', (int) $inv->teamId);
        if (!$team->id || !$team->box()->canManage((int) $this->member->id)) { $this->jsonError('Only the team\'s owner or an admin can revoke an invitation.', 403); return; }
        $email = (string) $inv->email; $role = (string) $inv->role; Bean::trash($inv);
        $this->logger->info('Team invitation revoked', ['team_id' => (int) $team->id, 'email' => $email, 'by' => (int) $this->member->id]);
        $this->jsonSuccess(['email' => $email, 'role' => $role, 'team_id' => (int) $team->id], 'Invitation revoked.');
    }

    public function summon($params = []): void {
        if (!$this->requireLogin()) return;
        $templates = array_values(array_filter(scandir(GameStore::root() . '/templates'), fn($t) => $t[0] !== '.' && is_dir(GameStore::root() . '/templates/' . $t)));
        $teams = Bean::getAll('SELECT t.id, t.name FROM team t JOIN teammember tm ON t.id = tm.team_id WHERE tm.member_id = ? AND t.is_active = 1 ORDER BY t.name', [(int) $this->member->id]);
        $form = ['title' => '', 'slug' => '', 'brief' => '', 'template' => 'blank', 'team_id' => 0, 'pipeline' => 1]; $problems = [];
        if (Flight::request()->method === 'POST') {
            $this->validateCSRF();
            $form = ['title' => trim((string) $this->getParam('title')), 'slug' => trim((string) $this->getParam('slug')), 'brief' => trim((string) $this->getParam('brief')), 'template' => (string) $this->getParam('template', 'blank'), 'team_id' => (int) $this->getParam('team_id', 0), 'pipeline' => (int) $this->getParam('pipeline', 0)];
            if ($form['slug'] === '') $form['slug'] = substr(preg_replace('/^[^a-z]+|-+$/', '', preg_replace('/[^a-z0-9]+/', '-', strtolower($form['title']))), 0, 40);
            if ($form['title'] === '') $problems['title'] = 'Give the game a name.';
            if (!preg_match(GameStore::SLUG, $form['slug'])) $problems['slug'] = 'Lowercase letters, digits and dashes, 2 to 41 characters.';
            if ($form['team_id'] > 0 && !in_array($form['team_id'], array_map(fn($t) => (int) $t['id'], $teams), true)) $problems['team_id'] = 'Not one of your teams.';
            if (!$problems) {
                try {
                    $g = GameStore::create($form['slug'], $form['title'], $form['brief'], $form['template'], (int) $this->member->id, $form['team_id']);
                    if ($form['pipeline']) GameStore::startRunner($g->slug, 'pipeline', '', (int) $this->member->id);
                    $this->flash('success', $form['pipeline'] ? 'Summoned. The planner is reading the brief.' : 'Summoned.');
                    Flight::redirect('/game/view/' . $g->slug . '#build'); return;
                } catch (\Throwable $e) { $problems['slug'] = $e->getMessage(); }
            }
        }
        $this->render('forge/summon', ['title' => 'Summon a game', 'form' => $form, 'problems' => $problems, 'templates' => $templates, 'teams' => $teams]);
    }
}
