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
\app\Chrome::add('scripts', 'app/browserdrive-top');
\app\Chrome::add('scripts', 'app/form-guard'); // sign-up and password reset: FormGuard's hidden field and page stamp (lib/FormGuard.php)
// FormGuard: a sign-up or reset posted by a form-filling bot is refused before the runtime's Auth sees it (a bot made member 79 on 2026-11-04)
\Flight::before('start', function () {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH); $page = array_search($path, \app\FormGuard::FORMS, true); if ($page === false) return;
    $why = \app\FormGuard::problem($_POST); if ($why === '') return;
    \Flight::get('log')->warning('FormGuard refused a post', ['path' => $path, 'why' => $why, 'ip' => \Flight::request()->ip, 'agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160)]);
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['flash'][] = ['type' => 'error', 'message' => 'That did not go through. Please wait a moment on the page, then try again.'];
    \Flight::redirect($page); exit; // ends the request here: a redirect alone let the runtime's register run on (a probe made an account)
}); // the browserdrive plugin's page: an API key panel and its settings up top (views/app/browserdrive-top.php)
\app\Chrome::$levelNames = [1 => 'Root', 50 => 'Admin', 100 => 'Maker', 101 => 'Visitor'];
\app\Index::$home = [\app\Home::class, 'index'];

// Sign-in is forgiving about case: the runtime looks a member up by the exact username or email, and SQLite compares
// bytes, so "Mfrederico@gmail.com" found nobody. Emails and the forge's usernames are stored lower-case, so the login
// field is lowered before the runtime's Auth sees it. lib/app.php runs before plugins boot, hence the before-start hook.
\Flight::before('start', function () {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($path !== '/auth/dologin' && $path !== '/auth/doforgot') return; // the reset form posts to doforgot (it named /auth/forgot, the page, and so never ran)
    foreach (['username', 'email', 'login'] as $k) {
        if (isset($_POST[$k]) && is_string($_POST[$k])) { $_POST[$k] = mb_strtolower(trim($_POST[$k])); $_REQUEST[$k] = $_POST[$k]; }
        try { $d = \Flight::request()->data; if (isset($d->$k) && is_string($d->$k)) $d->$k = mb_strtolower(trim($d->$k)); } catch (\Throwable $e) {}
    }
});
