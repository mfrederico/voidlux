<?php
/**
 * 33_TeaminvitationAccepted.php — teaminvitation.accepted_at exists from the start.
 *
 * The runtime's join route (and its invite lists) query `accepted_at IS NULL`, but on a fresh install the column is
 * only born when the first invitation is accepted, and the first acceptance never happens because the query that would
 * find the invitation fails on the missing column. A padded ghost row brings the column in as a date-typed TEXT.
 */
use \RedBeanPHP\R;

if ($_tableCheck('teaminvitation')) {
    $_cols = array_column(R::getAll('PRAGMA table_info(teaminvitation)'), 'name');
    if (!in_array('accepted_at', $_cols, true)) {
        $b = $_dispensePadding('teaminvitation');
        $b->teamId = null; $b->email = 'schema-ghost@invalid'; $b->token = str_repeat('x', 64); $b->role = 'member'; $b->acceptedAt = date('Y-m-d H:i:s'); $b->createdAt = date('Y-m-d H:i:s');
        R::store($b); $_defer($b); unset($b);
        echo "  teaminvitation.accepted_at: added\n";
    }
}
