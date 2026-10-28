<?php
/* The forge's menu. A member sees the forge and the kit's guide; the reference game is for everyone. */
if ($__loggedIn) {
    $__sections['The forge'] = [
        ['url' => '/forge', 'label' => 'Games', 'icon' => 'controller'],
        ['url' => '/forge/summon', 'label' => 'Summon a game', 'icon' => 'stars'],
        ['url' => '/forge/retro', 'label' => 'Retro' . (function () { try { $i = \app\Improve::inbox(); $n = $i['decide'] + $i['review']; return $n ? " ($n)" : ''; } catch (\Throwable $e) { return ''; } })(), 'icon' => 'clock-history', 'level' => LEVELS['ADMIN']],
        ['url' => '/forge/github', 'label' => 'GitHub', 'icon' => 'github', 'level' => LEVELS['ADMIN']],
        ['url' => '/teams', 'label' => 'Teams', 'icon' => 'people'],
    ];
}
