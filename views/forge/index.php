<?php /* The forge: every game the member can open, as cards, and the door to summon one. */ ?>
<?= \app\Ui::page(['eyebrow' => 'The forge', 'title' => 'Games', 'lead' => 'Yours and your teams\'. Open one to play it, build it, test it, tune its sound and ship it.',
                   'primary' => ['label' => 'Summon a game', 'url' => '/forge/summon', 'icon' => 'stars']]) ?>
<?php if (!$games): ?>
<?= \app\Ui::emptyState(['title' => 'Nothing in the forge yet', 'text' => 'Write a brief: who the player is, what they do every ten seconds, how a run ends, what sells it. The planner does the rest.', 'icon' => 'controller', 'action' => ['label' => 'Summon a game', 'url' => '/forge/summon']]) ?>
<?php else: ?>
<div class="vl-grid">
<?php foreach ($games as $g): $soak = $g['lastSoak']; $a = $g['agent']; $t = $g['tasks']; ?>
    <a class="vl-card" href="/game/view/<?= htmlspecialchars($g['slug']) ?>">
        <div class="vl-card-title"><?= htmlspecialchars($g['title']) ?> <?php if (!empty($a['busy'])): ?><span class="vl-pill live">● <?= htmlspecialchars($a['mode']) ?></span><?php endif; ?></div>
        <div class="vl-brief"><?= htmlspecialchars($g['brief']) ?></div>
        <div class="vl-meta"><?= htmlspecialchars($g['template']) ?> · <?= htmlspecialchars($g['status']) ?> · <?= $t['total'] ? 'tasks ' . $t['done'] . '/' . $t['total'] : 'no plan yet' ?> · <?= (int) $g['checkpoints'] ?> checkpoints<?php if ($soak): ?> · soak <?= $soak['ok'] ? '<b class="ok">ok</b>' : '<b class="bad">' . htmlspecialchars((string) ($soak['errors'] ?: $soak['status'])) . '</b>' ?><?= $soak['fps'] ? ' ' . $soak['fps'] . ' fps' : '' ?><?php endif; ?></div>
        <div class="vl-meta"><?= htmlspecialchars($g['lastCommit']) ?></div>
    </a>
<?php endforeach; ?>
</div>
<?php endif; ?>
<section class="vl-anatomy">
    <h2 class="vl-h2">Anatomy of the forge</h2>
    <div class="vl-grid">
        <div class="vl-card"><div class="vl-card-title">&#9670; The Planner</div>Reads the brief and writes GAME.md: design, acceptance criteria, ordered tasks. Nothing vague survives into the task list.</div>
        <div class="vl-card"><div class="vl-card-title">&#9679; The Builders</div>Claude Code sessions in the game's own folder, one task at a time, each ending in <code>node --check</code>, a headless soak and a commit.</div>
        <div class="vl-card"><div class="vl-card-title">&#9733; The Reviewer</div>A fresh session that never saw the build judges every criterion with evidence. Rejected criteria go back as rework, up to two rounds.</div>
        <div class="vl-card"><div class="vl-card-title">&#8734; The Kit</div>Centipede's engine pulled out: pooled-voice synth, chip tracker, sprite atlas, touch controls, soak harness. Every game shares it; every editor works on every game.</div>
        <div class="vl-card"><div class="vl-card-title">&#9876; Checkpoints</div>Every run is bracketed by a git tag. Roll back any time; the state before a rollback is a checkpoint too.</div>
        <div class="vl-card"><div class="vl-card-title">&#9813; Ship</div>A build with the kit inlined and a version stamp, served at its own subdomain in one click.</div>
    </div>
</section>
