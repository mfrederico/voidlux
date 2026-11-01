<?php /* The Studio: the running game in the middle, a list of its things on the left, the card of the thing picked on the right
         (public/studio.js). Its own full-window page, without the site's chrome: the game needs the room. */ ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<title><?= h($title) ?></title>
<style>
:root { --bg: #100c1c; --panel: #191329; --line: #30264a; --ink: #eee9f8; --muted: #a59bb8; --accent: #ffb547; --pick: #ff3d9a; --ok: #9bf06a; --warn: #ff9d6c; }
* { box-sizing: border-box; }
html, body { height: 100%; margin: 0; background: var(--bg); color: var(--ink); font: 14px/1.45 system-ui, "Segoe UI", sans-serif; }
button { font: inherit; color: inherit; background: #2a2040; border: 1px solid var(--line); border-radius: 8px; padding: 5px 12px; cursor: pointer; } button:hover { border-color: var(--accent); }
button:focus-visible, a:focus-visible, input:focus-visible, summary:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }
button.on { background: var(--pick); border-color: var(--pick); color: #fff; } a { color: var(--accent); }
.st { height: 100%; display: grid; grid-template: 48px minmax(0, 1fr) / 230px minmax(0, 1fr) 390px; }
.st-top { grid-column: 1 / -1; display: flex; gap: 10px; align-items: center; padding: 0 12px; border-bottom: 1px solid var(--line); background: var(--panel); min-width: 0; }
.st-top b { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; } .st-top .sp { flex: 1; } .st-hint { color: var(--muted); font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.st-list { min-height: 0; display: flex; flex-direction: column; border-right: 1px solid var(--line); background: var(--panel); }
.st-list input { margin: 8px; padding: 6px 9px; background: var(--bg); color: var(--ink); border: 1px solid var(--line); border-radius: 8px; font: inherit; }
#things { flex: 1; min-height: 0; overflow-y: auto; padding: 0 6px 10px; } #things h4 { margin: 10px 4px 3px; color: var(--muted); font-size: 11px; letter-spacing: .08em; text-transform: uppercase; }
.th { width: 100%; display: flex; gap: 7px; align-items: center; text-align: left; background: none; border: 1px solid transparent; padding: 2px 5px; overflow: hidden; } .th.on { border-color: var(--pick); } .th span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.strip { flex: none; width: 28px; height: 28px; background-size: 1600% 100%; background-repeat: no-repeat; }
.strip.spin { animation: stripspin 1.8s steps(16) infinite; } @keyframes stripspin { from { background-position: 0 0; } to { background-position: 106.6667% 0; } } @media (prefers-reduced-motion: reduce) { .strip.spin { animation: none; } }
.st-stage { position: relative; min-width: 0; min-height: 0; background: #000; } #game { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; display: block; }
#over { position: absolute; inset: 0; display: none; cursor: crosshair; } #over.on { display: block; box-shadow: inset 0 0 0 3px var(--pick); }
#ring { position: absolute; display: none; border: 2px solid var(--pick); border-radius: 50%; box-shadow: 0 0 0 4px rgba(255,61,154,.25); pointer-events: none; } #tip { position: absolute; display: none; padding: 2px 8px; border-radius: 6px; background: rgba(16,12,28,.92); color: #fff; font-size: 13px; pointer-events: none; white-space: nowrap; }
.st-card { min-height: 0; overflow-y: auto; padding: 14px; border-left: 1px solid var(--line); background: var(--panel); }
.st-card h2 { margin: 0; font-size: 19px; overflow-wrap: anywhere; } .st-card h3 { margin: 18px 0 5px; font-size: 12px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); } .muted { color: var(--muted); } .small { font-size: 12.5px; }
.look { display: flex; gap: 12px; margin-top: 10px; } .look .strip { width: 120px; height: 120px; border-radius: 8px; background-color: #0c0916; } .look img { width: 120px; height: 120px; object-fit: contain; border-radius: 8px; background: #0c0916; }
.dots { display: flex; gap: 4px; margin-top: 6px; } .dots i { width: 14px; height: 14px; border-radius: 4px; border: 1px solid rgba(255,255,255,.25); }
.now { margin-top: 10px; padding: 6px 10px; border-radius: 8px; border: 1px solid var(--pick); font-size: 13px; }
.res { width: 100%; border-collapse: collapse; font-size: 13px; } .res th, .res td { padding: 3px 6px 3px 0; text-align: left; } .res th { font-weight: 500; color: var(--muted); text-transform: capitalize; } .res .from { display: block; font-size: 11px; color: var(--muted); }
.rule { padding: 5px 0; border-top: 1px solid var(--line); font-size: 13px; overflow-wrap: anywhere; } .rule.off { opacity: .5; } .rule .n { color: var(--muted); font-size: 12px; } .bad { color: var(--warn); }
details { border-top: 1px solid var(--line); padding: 5px 0; } summary { cursor: pointer; } summary .c { color: var(--muted); } .bi { padding: 5px 0 5px 12px; font-size: 13px; overflow-wrap: anywhere; }
.pill { padding: 0 6px; border: 1px solid var(--line); border-radius: 99px; font-size: 11px; color: var(--muted); white-space: nowrap; } .pill.rule-now { color: var(--ok); border-color: var(--ok); }
.links { display: flex; flex-wrap: wrap; gap: 8px 14px; margin-top: 12px; font-size: 13px; }
@media (max-width: 1100px) { .st { grid-template: 48px minmax(240px, 45vh) minmax(0, 1fr) / 190px minmax(0, 1fr); height: auto; min-height: 100%; } .st-list { grid-row: 2 / 4; max-height: 100vh; } .st-card { border-left: 0; border-top: 1px solid var(--line); } .st-hint { display: none; } }
@media (max-width: 640px) { .st { display: flex; flex-direction: column; } .st-stage { height: 56vw; flex: none; } .st-list { max-height: 34vh; order: 3; border-right: 0; } .st-top { flex: none; height: 48px; } }
</style></head><body>
<div class="st">
  <div class="st-top"><a href="/forge" title="All games">Forge</a><b><?= h($gameTitle) ?></b><button id="inspect" title="Alt+I: pause the game and click anything in it">Inspect</button><button id="restart" title="Load the game again">Restart game</button><span class="st-hint" id="hint">Press Inspect, then click anything in the game.</span><span class="sp"></span><a href="/game/view/<?= h($slug) ?>">Game page</a></div>
  <aside class="st-list" aria-label="Things in this game"><input id="find" type="search" placeholder="Find a thing…" aria-label="Find a thing"><div id="things"></div></aside>
  <main class="st-stage"><iframe id="game" title="The game" allow="autoplay; fullscreen" src="/games/<?= h($slug) ?>/"></iframe><div id="over"><div id="ring"></div><div id="tip"></div></div></main>
  <aside class="st-card" id="card" aria-live="polite"><h2>Nothing picked yet</h2><p class="muted">Press <b>Inspect</b> (Alt+I), then click anything in the game, or choose from the list. Everything about it appears here.</p></aside>
</div>
<script>const SLUG = <?= json_encode($slug) ?>;</script>
<script src="/kit/editor/ux.js"></script><script src="/forge.js"></script><script src="/kit/editor/sprites/rule-words.js"></script><script src="/studio.js"></script>
</body></html>
