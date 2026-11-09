<?php /* ui: hand-made: an admin's tool that films the game playing itself (controls/Studio.php clip); a stage, a button and a status line. */ ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<style>
  html, body { margin: 0; background: #07060c; color: #e9e4f7; font: 13px/1.4 ui-monospace, Menlo, Consolas, monospace; }
  .bar { display: flex; gap: 14px; align-items: center; padding: 10px 14px; flex-wrap: wrap; }
  .bar b { color: #fff; } .bar button { font: inherit; background: #ff3d9a; color: #fff; border: 0; border-radius: 8px; padding: 8px 14px; cursor: pointer; min-height: 36px; } .bar button:disabled { opacity: .5; }
  .bar a { color: #c9b8ff; } #st { color: #ffcc00; } #res { color: #9bf06a; }
  .stage { width: min(100vw - 28px, (100vh - 120px) * 16 / 9); aspect-ratio: 16 / 9; margin: 0 14px; position: relative; background: #000; border: 1px solid #2a2440; }
  .stage iframe { position: absolute; left: 0; top: 0; width: 1280px; height: 720px; border: 0; transform-origin: 0 0; }
  video { display: block; margin: 10px 14px; max-width: 480px; }
</style>
</head><body>
<div class="bar"><b><?= h($gameTitle) ?></b><span>its bot plays · <span id="secs"></span> s filmed after <span id="wait"></span> s</span>
  <button type="button" id="go">● Film it</button><span id="st">loading the game…</span><span id="res"></span><span id="fps" style="color:#7de0ff"></span><a href="/studio/open/<?= h($slug) ?>">Back to the Studio</a></div>
<div class="stage" id="stage"><iframe id="game" title="<?= h($gameTitle) ?>, playing itself"></iframe></div>
<video id="out" controls muted playsinline hidden></video>
<script>
(() => {
  const SLUG = <?= json_encode($slug) ?>, TOKEN = <?= json_encode(csrf_token()) ?>, q = new URLSearchParams(location.search);
  const SECS = Math.max(3, Math.min(30, +q.get('s') || 12)), WAIT = Math.max(0, Math.min(60, +(q.get('wait') ?? 6))), $ = id => document.getElementById(id);
  const MIMES = ['video/mp4;codecs=avc1.640028', 'video/mp4;codecs=avc1', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm'];
  $('secs').textContent = SECS; $('wait').textContent = WAIT;
  const frame = $('game'), stage = $('stage'), fit = () => { frame.style.transform = `scale(${stage.clientWidth / 1280})`; }; fit(); addEventListener('resize', fit);
  // ?game=brain%3Dscripted passes the game settings of its own; ?quiet=1 hides a bot's own panels (its thinking, its target marks) while filming
  const extra = (q.get('game') || '').replace(/[^a-zA-Z0-9=&%._-]/g, '');
  frame.src = `/games/${encodeURIComponent(SLUG)}/?bot=1${extra ? '&' + extra : ''}&r=${Date.now()}`;
  // the game's systems are top-level consts of its scripts, reached by name in its own window
  const ask = code => { try { return new frame.contentWindow.Function('try { return ' + code + '; } catch (e) { return undefined; }')(); } catch (e) { return undefined; } };
  const state = () => { const st = ask("typeof Game !== 'undefined' ? Game.state : typeof G !== 'undefined' ? G.state : '?'"), t = ask("typeof Game !== 'undefined' ? Game.time : typeof G !== 'undefined' ? (G.time || G.t) : undefined"); return st + (typeof t === 'number' ? ' · game clock ' + t.toFixed(1) + ' s' : ''); };
  // into play: the game's own "start of the level" place for the Studio, else its forgePlay (which carries on a saved run)
  const startPlay = () => { const w = frame.contentWindow; try { const p = (w.forgeScenes || []).find(x => x.id === 'play'); if (p) { p.go(); return 'started at the start of the level'; } if (typeof w.forgePlay === 'function') return w.forgePlay() || 'already playing'; } catch (e) { console.error(e); return 'could not start: ' + e.message; } return 'it has no way to start from here'; };
  const say = t => { $('st').textContent = t; };
  async function film() {
    $('go').disabled = true; $('res').textContent = '';
    let canvas = null; try { canvas = frame.contentDocument.getElementById('game'); } catch (e) {}
    if (!canvas || !canvas.captureStream) { say('The game has no canvas to film yet.'); $('go').disabled = false; return; }
    const mime = MIMES.find(m => window.MediaRecorder && MediaRecorder.isTypeSupported(m)); if (!mime) { say('This browser cannot record video.'); return; }
    // a hidden tab draws nothing and boots nothing: wait for this tab to be in front and the game to have started up
    while (document.hidden || !ask("typeof Game !== 'undefined' ? Game.state : typeof G !== 'undefined' ? G.state : ''")) { say(document.hidden ? 'waiting: bring this tab to the front' : 'waiting for the game to start up'); await new Promise(r => setTimeout(r, 500)); }
    say(startPlay()); await new Promise(r => setTimeout(r, 800));
    // ?prep=1: a fixed setup per game for a clip that shows it at its best (never code from the address: this page runs as an admin).
    // Centipede 3D: level 4 with that level's loadout (its powers, a long body); its doors lead nowhere and its bot does not see them,
    // so the clip stays in the open world instead of the flat dungeon maze.
    const PREP = { 'centipede-3d': "(function () { if (typeof G === 'undefined') return 0; G.warpLevel(4); G.enterDungeon = function () {}; if (typeof Bot !== 'undefined') { const s = Bot.sense; Bot.sense = function () { const r = s.apply(this, arguments); if (r) r.entrance = null; return r; }; } return 1; })()" };
    if (q.get('prep') === '1' && PREP[SLUG]) ask(PREP[SLUG]);
    if (q.get('quiet') === '1') ask("(typeof Bot !== 'undefined' && Bot) ? (Bot.drawHUD = Bot.draw = function () {}, 1) : 0");
    // filmed from a canvas of this page, copied from the game's every frame here: the game's own canvas sends frames only while it paints
    const copy = document.createElement('canvas'); copy.width = 1280; copy.height = 720; const cg = copy.getContext('2d'); let filming = true;
    const pump = () => { if (!filming) return; try { cg.drawImage(canvas, 0, 0, 1280, 720); } catch (e) {} requestAnimationFrame(pump); }; pump();
    for (let k = WAIT; k > 0; ) { await new Promise(r => setTimeout(r, 1000)); if (document.hidden) { say('waiting: bring this tab to the front'); continue; } k--; say(`the bot is playing (${state()}): filming in ${k} s`); }
    const chunks = [], rec = new MediaRecorder(copy.captureStream(30), { mimeType: mime, videoBitsPerSecond: 3e6 });
    rec.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
    const done = new Promise(r => { rec.onstop = r; }); rec.start(1000);
    // the tab hidden part way: the recording waits for it, so the clip has no frozen stretch
    const vis = () => { if (document.hidden && rec.state === 'recording') rec.pause(); else if (!document.hidden && rec.state === 'paused') rec.resume(); }; document.addEventListener('visibilitychange', vis);
    for (let k = SECS; k > 0; ) { await new Promise(r => setTimeout(r, 1000)); if (document.hidden) { say('paused: bring this tab to the front'); continue; } k--; say(`● filming (${state()}): ${k} s left`); }
    document.removeEventListener('visibilitychange', vis);
    rec.stop(); await done; filming = false;
    const blob = new Blob(chunks, { type: mime.split(';')[0] }), ext = mime.includes('mp4') ? 'mp4' : 'webm';
    $('out').src = URL.createObjectURL(blob); $('out').hidden = false;
    say(`filmed ${(blob.size / 1048576).toFixed(1)} MB: sending it…`);
    const fd = new FormData(); fd.append('clip', blob, `${SLUG}.${ext}`);
    try {
      const r = await fetch(`/studio/clipsave/${encodeURIComponent(SLUG)}`, { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': TOKEN, 'X-Requested-With': 'XMLHttpRequest' } });
      const j = await r.json(); if (!r.ok || j.success === false) throw new Error(j.message || j.error || r.status);
      const d = j.data || j; say('kept.'); $('res').textContent = `${d.file} · ${(d.bytes / 1048576).toFixed(1)} MB`;
    } catch (e) { say('not kept: ' + e.message); }
    $('go').disabled = false;
  }
  // how many frames this page and the game draw each second: a page that is not drawing films nothing
  let pf = 0; (function tick() { pf++; requestAnimationFrame(tick); })();
  let gf = 0, hooked = false; const hookGame = () => { if (hooked) return; try { const w = frame.contentWindow; const t = () => { gf++; w.requestAnimationFrame(t); }; w.requestAnimationFrame(t); hooked = true; } catch (e) {} };
  setInterval(() => { hookGame(); $('fps').textContent = `page ${pf} fps · game ${gf} fps · ${innerWidth}×${innerHeight}${document.hidden ? ' · hidden' : ''}`; pf = 0; gf = 0; }, 1000);
  $('go').onclick = film;
  frame.addEventListener('load', () => { say(`loaded (${state()})`); if (q.get('go') === '1') film(); });
})();
</script>
</body></html>
