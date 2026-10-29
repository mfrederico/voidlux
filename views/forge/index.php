<?php /* The forge: every game the member can open, as cards, and the door to summon one. */ ?>
<?= \app\Ui::page(['eyebrow' => 'The forge', 'title' => 'Games', 'lead' => 'Yours and your teams\'. Open one to play it, build it, test it, tune its sound and ship it.',
                   'primary' => ['label' => 'Summon a game', 'url' => '/forge/summon', 'icon' => 'stars']]) ?>
<?php if (!$games): ?>
<?= \app\Ui::emptyState(['title' => 'Nothing in the forge yet', 'text' => 'Write a brief: who the player is, what they do every ten seconds, how a run ends, what sells it. The planner does the rest.', 'icon' => 'controller', 'action' => ['label' => 'Summon a game', 'url' => '/forge/summon']]) ?>
<?php else: ?>
<div class="vl-grid">
<?php foreach ($games as $g): $soak = $g['lastSoak']; $a = $g['agent']; $t = $g['tasks']; ?>
    <div class="vl-card vl-game">
        <a class="vl-cover" href="/game/view/<?= h($g['slug']) ?>#play" title="play <?= h($g['title']) ?>"><?php if (!empty($g['cover'])): ?><img src="/game/soakshot/<?= h($g['slug']) ?>?id=<?= h($g['cover']) ?>" alt="" loading="lazy"<?php if (!empty($g['gif'])): ?> data-anim="/game/soakshot/<?= h($g['slug']) ?>?id=<?= h($g['gif']) ?>&gif=1"<?php endif; ?>><?php else: ?><span><?= h($g['title']) ?></span><?php endif; ?><b class="vl-play-badge">▶ PLAY</b></a>
        <a class="vl-card-title" href="/game/view/<?= h($g['slug']) ?>#play"><?= h($g['title']) ?> <?php if (!empty($a['busy'])): ?><span class="vl-pill live">● <?= h($a['mode']) ?></span><?php endif; ?></a>
        <div class="vl-brief"><?= h($g['brief']) ?></div>
        <div class="vl-meta"><?= h($g['template']) ?> · <?= h($g['status']) ?> · <?= $t['total'] ? 'tasks ' . $t['done'] . '/' . $t['total'] : 'no plan yet' ?> · <?= (int) $g['checkpoints'] ?> checkpoints<?php if ($soak): ?> · playtest <?= $soak['ok'] ? '<b class="ok">ok</b>' : '<b class="bad">' . h(($soak['errors'] ?: $soak['status'])) . '</b>' ?><?= $soak['fps'] ? ' ' . $soak['fps'] . ' fps' : '' ?><?php endif; ?></div>
        <div class="vl-meta"><?= h($g['lastCommit']) ?></div>
        <div class="vl-game-actions"><a class="btn btn-sm btn-primary" href="/game/view/<?= h($g['slug']) ?>#play">▶ Play</a><a class="btn btn-sm btn-outline-primary" href="/game/view/<?= h($g['slug']) ?>#build">⚒ Build</a><a class="btn btn-sm btn-outline-secondary" href="/game/view/<?= h($g['slug']) ?>#notes">✎ Notes</a><?php if (!empty($g['publishedUrl'])): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h($g['publishedUrl']) ?>" target="_blank">Live ↗</a><?php endif; ?><button type="button" class="btn btn-sm btn-outline-warning vl-fork" data-fork="<?= h($g['slug']) ?>" data-title="<?= h($g['title']) ?>" title="a private copy of this game with all its history, for a big experiment that must not touch the original">⑂ FORK!</button></div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<details class="vl-anatomy">
    <summary class="vl-h2">Anatomy of the forge <span class="vl-meta">(what the parts are, and where they live)</span></summary>
    <div class="vl-grid">
        <a class="vl-card" href="/forge/summon"><div class="vl-card-title">&#9670; The Planner</div>Reads the brief and writes GAME.md: design, acceptance criteria, ordered tasks. Nothing vague survives into the task list.<div class="vl-meta">Summon a game → a brief → Concept tab</div></a>
        <a class="vl-card" href="<?= $games ? '/game/view/' . h($games[0]['slug']) . '#build' : '/forge/summon' ?>"><div class="vl-card-title">&#9679; The Builders</div>Claude Code sessions in the game's own folder, one task at a time, each ending in a check, a playtest and a commit.<div class="vl-meta">a game → Build tab</div></a>
        <a class="vl-card" href="<?= $games ? '/game/view/' . h($games[0]['slug']) . '#build' : '/forge/summon' ?>"><div class="vl-card-title">&#9733; The Reviewer</div>A fresh session that never saw the build judges every criterion with evidence. Rejected criteria go back as rework, up to two rounds.<div class="vl-meta">Build tab → Review</div></a>
        <a class="vl-card" href="<?= $games ? '/game/view/' . h($games[0]['slug']) . '#notes' : '/forge/summon' ?>"><div class="vl-card-title">&#9998; Note Mode</div>Pause the game, point at anything, say what should change. Notes carry a screenshot and the thing you pointed at; vote, comment, refine, send.<div class="vl-meta">Play tab → Note mode · Notes tab</div></a>
        <a class="vl-card" href="<?= $games ? '/game/view/' . h($games[0]['slug']) . '#media' : '/forge/summon' ?>"><div class="vl-card-title">&#9835; The Kit &amp; Media</div>Centipede's engine pulled out: pooled-voice synth, chip tracker, sprite atlas, touch controls, playtest harness. Every game shares it; every editor works on every game.<div class="vl-meta">Media tab → SFX, Tracker, Media · <span style="color:#ffcc00">kit guide</span></div></a>
        <a class="vl-card" href="<?= $games ? '/game/view/' . h($games[0]['slug']) . '#ship' : '/forge/summon' ?>"><div class="vl-card-title">&#9813; Checkpoints &amp; Ship</div>Every run is bracketed by a git tag; roll back any time. Publish builds the game and serves it at its own subdomain.<div class="vl-meta">Advanced → History · Ship tab</div></a>
    </div>
</details>
<script>for (const im of document.querySelectorAll('.vl-cover img[data-anim]')) { const still = im.src, a = im.closest('.vl-cover'); a.addEventListener('mouseenter', () => { im.src = im.dataset.anim; }); a.addEventListener('mouseleave', () => { im.src = still; }); }</script>
<script>
// FORK!: a private copy of the game, with its history, for an experiment; then straight to it
document.addEventListener('click', async e => { const b = e.target.closest('[data-fork]'); if (!b) return; const src = b.dataset.fork, name = b.dataset.title;
  const s = prompt(`Fork ${name}: slug for the copy (lowercase, dashes)`, `${src}-fork`); if (!s) return; const t = prompt('Its title:', `${name} (fork)`); if (t === null) return;
  const goal = prompt('What is the fork for? This leads its GAME.md and steers the planner.\ne.g. "Convert to three.js as an isometric game; above-ground play becomes 3D"', ''); if (!goal) return;
  b.disabled = true; b.textContent = 'forking…';
  try { const r = await fetch(`/game/fork/${encodeURIComponent(src)}`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': <?= json_encode(csrf_token()) ?>, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ slug: s.trim(), title: t.trim(), goal: goal.trim() }) }); const j = await r.json(); if (!r.ok || j.success === false) throw new Error(j.message || r.status); location.href = j.data.url + '#status'; }
  catch (err) { alert('Not forked: ' + err.message); b.disabled = false; b.textContent = '⑂ FORK!'; } });
</script>

