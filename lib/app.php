<?php
/**
 * VoidLux's wiring into the tiknix runtime — loaded on every request before any controller runs.
 *
 * The forge adds to the shell: its menu (views/app/nav.php), its theme over the runtime's design system
 * (views/app/theme.php: the void, ember and gold), its dashboard panel (the member's games), what a team does here
 * (its games, views/app/team.php), and the landing page as the home (controls/Home.php, a page of its own).
 */
\app\Chrome::add('prepare', 'app/nav');
\app\Chrome::add('head', 'app/theme');
\app\Chrome::add('dashboard', 'app/dashboard');
\app\Chrome::add('team', 'app/team');
\app\Chrome::add('footer', 'app/footer');
\app\Chrome::$levelNames = [1 => 'Root', 50 => 'Admin', 100 => 'Maker', 101 => 'Visitor'];
\app\Index::$home = [\app\Home::class, 'index'];

// Sign-in is forgiving about case: the runtime looks a member up by the exact username or email, and SQLite compares
// bytes, so "Mfrederico@gmail.com" found nobody. Emails and the forge's usernames are stored lower-case, so the login
// field is lowered before the runtime's Auth sees it. lib/app.php runs before plugins boot, hence the before-start hook.
\Flight::before('start', function () {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($path !== '/auth/dologin' && $path !== '/auth/forgot') return;
    foreach (['username', 'email', 'login'] as $k) {
        if (isset($_POST[$k]) && is_string($_POST[$k])) { $_POST[$k] = mb_strtolower(trim($_POST[$k])); $_REQUEST[$k] = $_POST[$k]; }
        try { $d = \Flight::request()->data; if (isset($d->$k) && is_string($d->$k)) $d->$k = mb_strtolower(trim($d->$k)); } catch (\Throwable $e) {}
    }
});
