<?php /* ui: hand-made: the builder at work, as wires from each run into the parts of the game it reads and changes (public/wires.js; data from Studio::wiredata). */ ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<style>
  html, body { margin: 0; height: 100%; background: #07060c; color: #e9e4f7; overflow: hidden; font: 13px/1.35 ui-monospace, "SF Mono", Menlo, Consolas, monospace; }
  #wires { position: fixed; inset: 0; width: 100%; height: 100%; display: block; }
  .bar { position: fixed; left: 0; right: 0; top: 0; display: flex; gap: 22px; align-items: center; padding: 8px 14px; background: linear-gradient(#07060cf0, #07060c00); font-size: 12px; letter-spacing: .04em; text-transform: uppercase; color: #a79fc0; z-index: 2; }
  .bar b { color: #fff; font-weight: 600; } .bar .k { color: #ffcc00; } .bar a { color: #c9b8ff; text-decoration: none; border: 1px solid #3a3352; border-radius: 8px; padding: 6px 10px; text-transform: none; letter-spacing: 0; }
  .bar .sp { flex: 1; } .bar button { font: inherit; text-transform: none; letter-spacing: 0; color: #111; background: #ffcc00; border: 0; border-radius: 8px; padding: 7px 12px; cursor: pointer; min-height: 32px; }
  .live { padding: 3px 9px; border-radius: 99px; background: #2a2440; color: #a79fc0; } .live.on { background: #ff3d9a; color: #fff; animation: pulse 1.4s ease-in-out infinite; } @keyframes pulse { 50% { opacity: .55; } }
  .title { position: fixed; left: 120px; top: 46px; z-index: 2; border-left: 4px solid #c58aff; padding: 4px 12px; background: #0c0a14d0; }
  .title h1 { margin: 0; font-size: 22px; letter-spacing: .06em; text-transform: uppercase; } .title div { color: #a79fc0; font-size: 12px; }
  .panels { position: fixed; left: 0; right: 0; bottom: 0; height: 190px; display: grid; grid-template-columns: 1.2fr 1.4fr 1fr; gap: 1px; background: #1c1830; z-index: 2; border-top: 1px solid #2a2440; }
  .panels section { background: #0b0a12f2; padding: 8px 12px; overflow: hidden; } .panels h2 { margin: 0 0 6px; font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: #a79fc0; display: flex; justify-content: space-between; }
  #log div { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; } #log .w { color: #ff6bd5; } #log .r { color: #7de0ff; } #log .p { color: #9bf06a; } #log .a { color: #ffb547; } #log .s { color: #fff; }
  #ask { white-space: pre-wrap; color: #d9d2ee; max-height: 150px; overflow: hidden; } #ask .q { color: #ffcc00; }
  #cost .big { font-size: 34px; color: #ffcc00; text-shadow: 0 0 18px #ffcc0088; } #cost .row { display: flex; gap: 6px; align-items: center; font-size: 11px; color: #a79fc0; margin: 2px 0; } #cost i { display: inline-block; height: 7px; background: #c58aff; border-radius: 2px; }
  .tip { position: fixed; z-index: 3; pointer-events: none; white-space: pre-line; background: #120f1e; border: 1px solid #3a3352; border-radius: 6px; padding: 5px 8px; font-size: 12px; display: none; max-width: 360px; }
  @media (max-width: 900px) { .panels { grid-template-columns: 1fr 1fr; height: 160px; } .panels section:nth-child(2) { display: none; } .title { left: 90px; } }
</style>
</head><body>
<canvas id="wires" aria-label="Each run of the builder, wired into the parts of the game it reads and changes"></canvas>
<div class="bar"><b><?= h($gameTitle) ?></b><span>runs <span class="k" id="nRuns">0</span></span><span>parts <span class="k" id="nNodes">0</span></span><span>wires <span class="k" id="nWires">0</span></span><span>steps <span class="k" id="nSteps">0</span></span><span>cost <span class="k" id="nCost">$0</span></span><span>t <span class="k" id="nT">0.0s</span></span><span>frame <span class="k" id="nFrame">0</span></span><span class="sp"></span><span class="live" id="live">idle</span><button type="button" id="replay" title="Play the last run back, quickly">▶ Replay the last run</button><a href="/studio/open/<?= h($slug) ?>">Back to the Studio</a></div>
<div class="title"><h1 id="runTitle">The builder</h1><div id="runSub">waiting for the game's runs…</div></div>
<div class="panels">
  <section><h2>Run log <span id="logRate">0 steps</span></h2><div id="log"></div></section>
  <section><h2>The ask <span id="askWhen"></span></h2><div id="ask"></div></section>
  <section id="cost"><h2>Cost <span id="costLive"></span></h2><div class="big" id="costBig">$0.00</div><div id="costRuns"></div></section>
</div>
<div class="tip" id="tip"></div>
<script>const SLUG = <?= json_encode($slug) ?>;</script>
<script src="/wires.js?v=<?= (int) @filemtime(\app\Paths::root() . '/public/wires.js') ?>"></script>
</body></html>
