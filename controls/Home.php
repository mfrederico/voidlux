<?php
/**
 * Home — the VoidLux landing page at /, a standalone page (views/home/index.php: the swarm's own design, no layout).
 * Signed-in members get a Forge link in its nav; everyone else sees the page as the world does.
 */
namespace app;

use \Flight as Flight;

class Home extends BaseControls\Control {
    // nav: none — it IS the front door
    public function index($params = []): void {
        $games = [];
        foreach (\app\Bean::find('game', "published_url <> '' ORDER BY published_at DESC") as $g) $games[] = ['title' => (string) $g->title, 'url' => (string) $g->publishedUrl, 'brief' => mb_substr((string) $g->brief, 0, 160), 'price' => $g->price === null || $g->price === '' ? null : (float) $g->price];
        $this->render('home/index', ['loggedIn' => Flight::isLoggedIn(), 'games' => $games], false);
    }
}
