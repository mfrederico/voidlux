<?php
/**
 * 34_GamePublic.php — game.is_public exists from the start: 1 = listed on the landing page for the world to play
 * (once published), 0 = only its team sees it. A padded ghost row brings the column in as an integer.
 */
use \RedBeanPHP\R;

if ($_tableCheck('game')) {
    $_cols = array_column(R::getAll('PRAGMA table_info(game)'), 'name');
    if (!in_array('is_public', $_cols, true)) {
        $b = $_dispensePadding('game');
        $b->slug = 'schema-ghost-public'; $b->title = 'ghost'; $b->isPublic = 1; $b->createdAt = date('c');
        R::store($b); $_defer($b); unset($b);
        echo "  game.is_public: added\n";
    }
}
