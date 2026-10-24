<?php
/**
 * 30_Forge.php — the forge's table and permission rows.
 *
 * `game`: one row per game folder (games/<slug>/): who owns it, which team may open it, and its store card. Padded so
 * every text column is born wide (notes, brief, tags hold real text; RedBean types a column from its first value).
 *
 * Routes: the forge and a game's pages are for members (100); the editors' JSON API too (it checks the game's access
 * itself); the landing page is public.
 */
use \RedBeanPHP\R;

foreach ([['forge', '*', 100, 'The forge: every game, summon one'], ['game', '*', 100, 'A game: play, build, test, history, ship'], ['gameapi', '*', 100, 'The editors\' API (sfx, tuning, music)'], ['home', '*', 101, 'The VoidLux landing page']] as [$c, $m, $lvl, $d]) {
    echo "  authcontrol: {$c}::{$m} => " . \app\PermissionCache::seedRule($c, $m, $lvl, $d) . "\n";
}
if (!$_tableCheck('game')) {
    $b = $_dispensePadding('game');
    $b->slug = 'schema-ghost-game'; $b->title = str_repeat('x', 60); $b->template = str_repeat('x', 40); $b->brief = str_repeat('x', 4000);
    $b->status = str_repeat('x', 20); $b->price = 12.34; $b->tags = str_repeat('x', 500); $b->notes = str_repeat('x', 4000);
    $b->createdAt = date('c'); $b->publishedAt = date('c'); $b->publishedUrl = str_repeat('x', 200); $b->publishedBuild = str_repeat('x', 40);
    R::store($b); $_defer($b); unset($b);
    echo "  game: table created\n";
}
