<?php /* The member's games on the dashboard: what each is doing right now, and the door to the forge. */
$__games = \app\GameStore::forMember(member_id(\Flight::getMember()));
?>
<div class="ui-panel mb-3">
    <div class="ui-panel-header d-flex justify-content-between align-items-center"><h3><i class="bi bi-controller text-primary me-2"></i>Your games</h3><a href="/forge/summon" class="btn btn-sm btn-primary"><i class="bi bi-stars"></i> Summon a game</a></div>
    <div class="ui-panel-body">
        <?php if (!$__games): ?><p class="text-secondary mb-0">Nothing in the forge yet. Write a brief and summon one.</p>
        <?php else: ?><div class="vl-grid">
            <?php foreach ($__games as $__g): $__m = \app\GameStore::meta($__g); ?>
            <a class="vl-card" href="/game/view/<?= htmlspecialchars($__m['slug']) ?>">
                <div class="vl-card-title"><?= htmlspecialchars($__m['title']) ?> <?php if ($__m['agent']['busy']): ?><span class="vl-pill live">● <?= htmlspecialchars($__m['agent']['mode']) ?></span><?php endif; ?></div>
                <div class="vl-meta"><?= htmlspecialchars($__m['status']) ?> · <?= $__m['tasks']['total'] ? 'tasks ' . $__m['tasks']['done'] . '/' . $__m['tasks']['total'] : 'no plan yet' ?> · <?= (int) $__m['checkpoints'] ?> checkpoints<?php if ($__m['lastSoak']): ?> · soak <?= $__m['lastSoak']['ok'] ? '<b class="ok">ok</b>' : '<b class="bad">failing</b>' ?><?php endif; ?></div>
            </a>
            <?php endforeach; ?>
        </div><?php endif; ?>
    </div>
</div>
