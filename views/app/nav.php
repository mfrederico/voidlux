<?php
/* The forge's menu. A member sees the forge and the kit's guide; the reference game is for everyone. */
if ($__loggedIn) {
    $__sections['The forge'] = [
        ['url' => '/forge', 'label' => 'Games', 'icon' => 'controller'],
        ['url' => '/forge/summon', 'label' => 'Summon a game', 'icon' => 'stars'],
        ['url' => '/forge/retro', 'label' => 'Retro', 'icon' => 'clock-history', 'level' => LEVELS['ADMIN']],
        ['url' => '/teams', 'label' => 'Teams', 'icon' => 'people'],
    ];
}
