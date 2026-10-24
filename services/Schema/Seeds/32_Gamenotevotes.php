<?php
/**
 * 32_Gamenotevotes.php — `gamenotevote` (one member's upvote on a note) and `gamenotecomment` (words under a note).
 * The FK columns (gamenote_id, member_id) arrive with the first real row; the comment's text is padded wide.
 */
use \RedBeanPHP\R;

if (!$_tableCheck('gamenotecomment')) {
    $b = $_dispensePadding('gamenotecomment');
    $b->text = str_repeat('x', 4000); $b->createdAt = date('c');
    R::store($b); $_defer($b); unset($b);
    echo "  gamenotecomment: table created\n";
}
if (!$_tableCheck('gamenotevote')) {
    $b = $_dispensePadding('gamenotevote');
    $b->createdAt = date('c');
    R::store($b); $_defer($b); unset($b);
    echo "  gamenotevote: table created\n";
}
