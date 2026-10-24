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
