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
        $hero = Paths::root() . '/public/assets/hero/hero-loop.mp4'; $watch = Paths::root() . '/public/assets/home/watch-build.mp4'; // the builder at work, under the hero, once it is filmed // the gameplay loop behind the hero, once it is cut: until then the ember mesh
        $this->render('home/index', ['loggedIn' => Flight::isLoggedIn(), 'games' => self::publicGames(), 'costs' => self::costs(), 'heroVideo' => is_file($hero) ? (int) filemtime($hero) : 0, 'watchVideo' => is_file($watch) ? (int) filemtime($watch) : 0], false);
    }
    /** What a change and a whole game have cost here, from the same figures the Studio quotes: {change, game} each {n, usual, high} or null. */
    public static function costs(): array {
        $t = RunCosts::table();
        return ['change' => $t['kinds']['chat'] ?? null, 'game' => $t['games'] ?? null];
    }
    /** A brief cut for a card: at a word, with an ellipsis, never mid-word. */
    public static function blurb(string $brief, int $max = 140): string {
        $brief = trim(preg_replace('/\s+/', ' ', $brief));
        if (mb_strlen($brief) <= $max) return $brief;
        $cut = mb_substr($brief, 0, $max);
        $sp = mb_strrpos($cut, ' ');
        return rtrim($sp > $max * 0.6 ? mb_substr($cut, 0, $sp) : $cut, ' ,;:-') . '…';
    }
    /** Published games their owners made public, newest first: what the world may play. */
    public static function publicGames(): array {
        $games = [];
        foreach (\app\Bean::find('game', "published_url <> '' AND is_public = 1 ORDER BY published_at DESC") as $g) {
            $games[] = ['slug' => (string) $g->slug, 'title' => (string) $g->title, 'url' => (string) $g->publishedUrl, 'brief' => self::blurb((string) $g->brief), 'price' => $g->price === null ? null : (float) $g->price,
                        'tags' => json_decode((string) $g->tags, true) ?: [], 'cover' => GameStore::cover((string) $g->slug) !== '', 'gif' => GameStore::gif((string) $g->slug) !== '', 'at' => (string) $g->publishedAt,
                        'updated' => max((int) strtotime((string) $g->publishedAt ?: '@0'), GameStore::lastTouched((string) $g->slug))];
        }
        usort($games, fn($a, $b) => $b['updated'] <=> $a['updated']); // newest first: the game updated most recently leads
        return $games;
    }
    /** A public game's cover picture (its latest playtest shot) for the landing page: no sign-in, public games only. */
    public function cover($params = []): void {
        $slug = (string) ($params['operation']->name ?? '');
        $g = preg_match(GameStore::SLUG, $slug) ? \app\Bean::findOne('game', 'slug = ? AND is_public = 1 AND published_url <> ?', [$slug, '']) : null;
        $anim = (string) $this->getParam('anim', '') === '1'; // the first seconds of play, for a pointer resting on the card
        $id = $g ? ($anim ? GameStore::gif($slug) : GameStore::cover($slug)) : '';
        $base = $id !== '' ? GameStore::dir($slug) . '/.studio/soaks/' . $id : '';
        $f = $anim ? ($base !== '' && is_file($base . '.gif') ? $base . '.gif' : '') : ($base !== '' && is_file($base . '.png') ? $base . '.png' : ($base !== '' && is_file($base . '.jpg') ? $base . '.jpg' : ''));
        if ($f === '') { Flight::notFound(); return; }
        header('Content-Type: ' . (str_ends_with($f, '.png') ? 'image/png' : (str_ends_with($f, '.gif') ? 'image/gif' : 'image/jpeg'))); header('Cache-Control: public, max-age=600'); readfile($f); exit;
    }
}
