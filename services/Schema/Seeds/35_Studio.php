<?php
/** The Studio: a game's pages built around the running game, for members (each route checks the game's access itself). */
echo "  authcontrol: studio::* => " . \app\PermissionCache::seedRule('studio', '*', 100, 'The Studio: the running game, and a card for anything in it') . "\n";
