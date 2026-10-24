<?php /* What a team does in the forge: the games shared with it. $team is in scope (the runtime's team page). */
$__tg = array_values(\app\Bean::find('game', 'team_id = ? ORDER BY title ASC', [(int) $team->id]));
?>
<div class="ui-panel mb-3">
    <div class="ui-panel-header"><h3><i class="bi bi-controller text-primary me-2"></i>Games this team builds</h3></div>
    <div class="ui-panel-body">
        <?php if (!$__tg): ?><p class="text-secondary mb-0">No game is shared with this team yet. On a game's Ship tab, set its team.</p>
        <?php else: ?><div class="vl-grid">
            <?php foreach ($__tg as $__g): $__m = \app\GameStore::meta($__g); ?>
            <a class="vl-card" href="/game/view/<?= htmlspecialchars($__m['slug']) ?>"><div class="vl-card-title"><?= htmlspecialchars($__m['title']) ?></div><div class="vl-meta"><?= htmlspecialchars($__m['status']) ?> · <?= htmlspecialchars($__m['lastCommit']) ?></div></a>
            <?php endforeach; ?>
        </div><?php endif; ?>
    </div>
</div>
