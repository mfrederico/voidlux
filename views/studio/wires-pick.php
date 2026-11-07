<?php /* ui: hand-made: the games to watch build, in the dark look of the wires page they open (controls/Studio.php wirePicker). */
$when = fn(int $t) => $t ? date('M j', $t) : ''; ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<style>
  html, body { margin: 0; min-height: 100%; background: #07060c; color: #e9e4f7; font: 14px/1.4 ui-monospace, "SF Mono", Menlo, Consolas, monospace; }
  body { background-image: linear-gradient(rgba(155,135,255,.06) 1px, transparent 1px), linear-gradient(90deg, rgba(155,135,255,.06) 1px, transparent 1px); background-size: 40px 40px; }
  header { display: flex; align-items: center; gap: 18px; padding: 22px 28px 6px; }
  header h1 { margin: 0; font-size: 26px; letter-spacing: .08em; text-transform: uppercase; border-left: 4px solid #c58aff; padding-left: 12px; }
  header p { margin: 0; color: #a79fc0; } header .sp { flex: 1; }
  a.back { color: #c9b8ff; text-decoration: none; border: 1px solid #3a3352; border-radius: 8px; padding: 8px 12px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); gap: 18px; padding: 18px 28px 40px; }
  .game { background: #0e0c16; border: 1px solid #2a2440; border-radius: 10px; overflow: hidden; display: flex; flex-direction: column; text-decoration: none; color: inherit; transition: transform .15s, border-color .15s; }
  .game:hover, .game:focus-visible { transform: translateY(-3px); border-color: #c58aff; outline: none; }
  .pic { aspect-ratio: 16 / 9; background: #14111f; display: grid; place-items: center; color: #4a4568; font-size: 18px; letter-spacing: .06em; text-transform: uppercase; overflow: hidden; }
  .pic img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .body { padding: 12px 14px 14px; display: flex; flex-direction: column; gap: 6px; flex: 1; }
  .body h2 { margin: 0; font-size: 16px; } .stats { color: #a79fc0; font-size: 12px; } .stats b { color: #ffcc00; font-weight: 600; }
  .go { margin-top: auto; align-self: flex-start; background: #ffcc00; color: #111; border-radius: 8px; padding: 8px 12px; font-weight: 600; }
  .game.none .go { background: transparent; color: #a79fc0; border: 1px solid #3a3352; }
  .empty { padding: 40px 28px; color: #a79fc0; }
</style>
</head><body>
<header><h1>Watch them build</h1><p>Pick a game to watch it come together, run by run, from the first.</p><span class="sp"></span><a class="back" href="/forge">Back to the forge</a></header>
<?php if (!$games): ?>
  <div class="empty">No games yet. <a class="back" href="/forge/summon">Summon a game</a></div>
<?php else: ?>
<div class="grid">
<?php foreach ($games as $g): $built = $g['runs'] > 0; ?>
  <a class="game<?= $built ? '' : ' none' ?>" href="/studio/wires/<?= h($g['slug']) ?><?= $built ? '?build=1' : '' ?>">
    <div class="pic"><?php if ($g['cover'] !== ''): ?><img src="/game/soakshot/<?= h($g['slug']) ?>?id=<?= h($g['cover']) ?>" alt="" loading="lazy"><?php else: ?><?= h($g['title']) ?><?php endif; ?></div>
    <div class="body"><h2><?= h($g['title']) ?></h2>
      <?php if ($built): ?><div class="stats"><b><?= (int) $g['runs'] ?></b> runs · <b><?= (int) $g['steps'] ?></b> steps · <b>$<?= number_format($g['cost'], 2) ?></b></div>
        <div class="stats"><?= h($when($g['first'])) ?><?= $g['last'] && $when($g['last']) !== $when($g['first']) ? ' – ' . h($when($g['last'])) : '' ?></div>
        <span class="go">▶ Watch it build</span>
      <?php else: ?><div class="stats">The builder has not worked on it yet.</div><span class="go">Open it</span><?php endif; ?>
    </div>
  </a>
<?php endforeach; ?>
</div>
<?php endif; ?>
</body></html>
