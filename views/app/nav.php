<?php
/* The forge's menu. A member sees the forge and the kit's guide; the reference game is for everyone. */
if ($__loggedIn) {
    $__sections['The forge'] = [
        ['url' => '/forge', 'label' => 'Games', 'icon' => 'controller'],
        ['url' => '/forge/summon', 'label' => 'Summon a game', 'icon' => 'stars'],
    ];
    $__sections['Springboards'] = [
        ['url' => '/centipede/', 'label' => 'Play Centipede', 'icon' => 'bug'],
        ['url' => '/centipede/README.md', 'label' => 'Centipede features', 'icon' => 'list-check'],
        ['url' => '/kit/AGENTS.md', 'label' => 'Kit guide', 'icon' => 'book'],
    ];
}
