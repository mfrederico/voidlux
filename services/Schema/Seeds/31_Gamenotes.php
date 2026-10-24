<?php
/**
 * 31_Gamenotes.php — `gamenote`: the producer's notes on a running game (see models/Model_Gamenote.php). Padded so text
 * and what (JSON) are born wide; game_id and member_id arrive with the first real row.
 */
use \RedBeanPHP\R;

if (!$_tableCheck('gamenote')) {
    $b = $_dispensePadding('gamenote');
    $b->scene = str_repeat('x', 40); $b->x = 1280; $b->y = 720; $b->what = str_repeat('x', 4000); $b->text = str_repeat('x', 4000); $b->shot = str_repeat('x', 200);
    $b->state = 'open'; $b->createdAt = date('c'); $b->sentAt = date('c');
    R::store($b); $_defer($b); unset($b);
    echo "  gamenote: table created\n";
}
