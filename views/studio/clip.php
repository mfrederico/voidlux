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
  <button type="button" id="go">● Film it</button><span id="st">loading the game…</span><span id="res"></span><a href="/studio/open/<?= h($slug) ?>">Back to the Studio</a></div>
<div class="stage" id="stage"><iframe id="game" title="<?= h($gameTitle) ?>, playing itself"></iframe></div>
<video id="out" controls muted playsinline hidden></video>
<script>
(() => {
  const SLUG = <?= json_encode($slug) ?>, TOKEN = <?= json_encode(csrf_token()) ?>, q = new URLSearchParams(location.search);
  const SECS = Math.max(3, Math.min(30, +q.get('s') || 12)), WAIT = Math.max(0, Math.min(60, +(q.get('wait') ?? 6))), $ = id => document.getElementById(id);
  const MIMES = ['video/mp4;codecs=avc1.640028', 'video/mp4;codecs=avc1', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm'];
  $('secs').textContent = SECS; $('wait').textContent = WAIT;
  const frame = $('game'), stage = $('stage'), fit = () => { frame.style.transform = `scale(${stage.clientWidth / 1280})`; }; fit(); addEventListener('resize', fit);
  frame.src = `/games/${encodeURIComponent(SLUG)}/?bot=1&r=${Date.now()}`;
  const state = () => { try { const w = frame.contentWindow; return w.Game && w.Game.state ? w.Game.state : w.G && w.G.state ? w.G.state : '?'; } catch (e) { return '?'; } };
  const say = t => { $('st').textContent = t; };
  async function film() {
    $('go').disabled = true; $('res').textContent = '';
    let canvas = null; try { canvas = frame.contentDocument.getElementById('game'); } catch (e) {}
    if (!canvas || !canvas.captureStream) { say('The game has no canvas to film yet.'); $('go').disabled = false; return; }
    const mime = MIMES.find(m => window.MediaRecorder && MediaRecorder.isTypeSupported(m)); if (!mime) { say('This browser cannot record video.'); return; }
    for (let k = WAIT; k > 0; k--) { say(`the bot is playing (${state()}): filming in ${k} s`); await new Promise(r => setTimeout(r, 1000)); }
    const chunks = [], rec = new MediaRecorder(canvas.captureStream(30), { mimeType: mime, videoBitsPerSecond: 3e6 });
    rec.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
    const done = new Promise(r => { rec.onstop = r; }); rec.start(1000);
    for (let k = SECS; k > 0; k--) { say(`● filming (${state()}): ${k} s left`); await new Promise(r => setTimeout(r, 1000)); }
    rec.stop(); await done;
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
  $('go').onclick = film;
  frame.addEventListener('load', () => { say(`loaded (${state()})`); if (q.get('go') === '1') film(); });
})();
</script>
</body></html>
