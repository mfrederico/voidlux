<?php /* The forge's retrospective ledger: each hour (and week), its numbers, the rule findings, the agent's note and its proposals. Nothing here changes anything: a proposal is acted on by a person. */ ?>
<div class="vl-wrap">
  <div class="vl-head"><h1 class="vl-h1">The forge, hour by hour</h1><p class="vl-lede">Every hour the forge measures itself (runs, cost, art jobs, keeps), applies its rules, and, when something happened, has a read-only agent write a note and at most three proposals. The measures and findings are deterministic; the note and proposals are the agent's. Nothing changes until someone says so.</p></div>
  <?php if (!$entries): ?><p class="vl-muted">No hours recorded yet: <code>php scripts/forge-retro.php</code> records the last full hour; cron runs it at five past every hour.</p><?php endif; ?>
  <?php foreach ($entries as $e): $m = $e['measures']; $fs = array_filter((array) $e['findings'], fn($f) => $f['rule'] !== 'quiet'); $quiet = !$fs && empty($e['proposals']) && !$m['runs']['build'] && !$m['runs']['art'] && !$m['art']['jobs']; ?>
  <details class="vl-card vl-retro<?= $quiet ? ' quiet' : '' ?>" <?= $e === $entries[0] || $e['kind'] === 'week' ? 'open' : '' ?>>
    <summary><b><?= $e['kind'] === 'week' ? 'The week to ' : '' ?><?= h(date('D j M H:00', strtotime($e['at']))) ?></b>
      <span class="vl-muted"> · <?= (int) $m['runs']['build'] ?> build + <?= (int) $m['runs']['art'] ?> art runs · $<?= number_format((float) $m['cost']['total'], 2) ?> · <?= (int) $m['art']['jobs'] ?> art jobs · <?= (int) $m['keeps'] ?> kept<?= $m['cost_per_keep'] !== null ? ' · $' . h($m['cost_per_keep']) . ' per keep' : '' ?><?= $fs ? ' · ' . count($fs) . ' finding' . (count($fs) === 1 ? '' : 's') : '' ?><?= !empty($e['proposals']) ? ' · ' . count($e['proposals']) . ' proposal' . (count($e['proposals']) === 1 ? '' : 's') : '' ?><?= $quiet ? ' · quiet' : '' ?></span></summary>
    <div class="vl-retro-body">
      <?php if (!empty($e['note'])): ?><p><?= nl2br(h($e['note'])) ?></p><?php endif; ?>
      <?php if ($fs): ?><h3 class="vl-h2">Findings</h3><ul><?php foreach ($fs as $f): ?><li><span class="vl-pill <?= h($f['severity']) ?>"><?= h($f['severity']) ?></span> <?= h($f['text']) ?><?php if (!empty($f['evidence'])): ?><div class="vl-muted small"><?= h(implode(' · ', array_slice((array) $f['evidence'], 0, 6))) ?></div><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?>
      <?php if (!empty($e['proposals'])): ?><h3 class="vl-h2">Proposals</h3><ol><?php foreach ($e['proposals'] as $p): ?><li><b><?= h($p['title']) ?></b> <span class="vl-muted">· <?= h($p['kind']) ?> · <code><?= h($p['file']) ?></code><?= empty($p['file_exists']) && !empty($p['file']) ? ' <span class="vl-pill high">file not found</span>' : '' ?><?= !empty($p['from']) ? ' · from ' . h(date('D H:00', strtotime($p['from']))) : '' ?></span><div><?= nl2br(h($p['change'])) ?></div><div class="vl-muted small">evidence: <?= h($p['evidence']) ?><?= !empty($p['measure']) ? ' · measure: ' . h($p['measure']) : '' ?></div></li><?php endforeach; ?></ol><?php endif; ?>
      <details class="vl-muted small"><summary>the numbers</summary><pre class="vl-json"><?= h(json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre><?php if (!empty($e['agent'])): ?><div>agent: <?= $e['agent']['ok'] ? 'ok' : 'failed' ?> · $<?= h(number_format((float) ($e['agent']['cost'] ?? 0), 3)) ?><?= !empty($e['agent']['error']) ? ' · ' . h($e['agent']['error']) : '' ?></div><?php endif; ?></details>
    </div>
  </details>
  <?php endforeach; ?>
</div>
<style>
.vl-retro { margin-bottom: 10px; } .vl-retro summary { cursor: pointer; } .vl-retro.quiet { opacity: .6; } .vl-retro-body { padding-top: 8px; display: grid; gap: 8px; } .vl-retro-body ul, .vl-retro-body ol { margin: 0; padding-left: 20px; } .vl-retro-body li { margin: 4px 0; }
.vl-pill.high { background: #4a1a1a; color: #ff9b9b; } .vl-pill.medium { background: #4a3a10; color: #ffd166; } .vl-pill.low { background: #1a2a3a; color: #9ad0ff; } .small { font-size: .82rem; }
</style>
