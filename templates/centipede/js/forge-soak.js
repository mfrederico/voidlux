// The forge's soak shim for games sprung from Centipede. ?soak=N (&fast=K) turns the scripted autopilot on, mutes, runs N
// game-seconds (K updates per frame), collects every exception and console error, and leaves the report in window.__SOAK
// for the headless runner (studio/soak.mjs). Without ?soak this file does nothing. Loads last, after main.js.
(function () {
  const q = new URLSearchParams(location.search), secs = +q.get('soak'); if (!secs) return;
  const fast = Math.max(1, Math.min(30, +q.get('fast') || 1));
  Bot.on = true; Bot.allowAuto = true; Bot.scriptedOnly = true; Bot.offline = true; Bot.retryT = 1e12; SFX.muted = true;
  const errors = [], scenes = {}; let t = 0, done = false;
  const note = s => { s = String(s); if (!errors.some(e => e.text === s)) errors.push({ text: s, at: +t.toFixed(1), state: G.state, mode: G.mode }); };
  window.addEventListener('error', e => note(e.error && e.error.stack || e.message));
  window.addEventListener('unhandledrejection', e => note(e.reason && e.reason.stack || e.reason));
  const ce = console.error.bind(console); console.error = (...a) => { note(a.map(x => x && x.stack || String(x)).join(' ')); ce(...a); };
  const upd = G.update.bind(G);
  const step = dt => { if (done) return; upd(dt); t += dt; const k = G.state + (G.state === 'playing' ? ':' + G.mode : ''); scenes[k] = (scenes[k] || 0) + dt; if (t >= secs) finish(); };
  G.update = function (dt) { for (let i = 1; i < fast; i++) { step(1 / 60); Input.endFrame(); } step(dt); };
  /* a hidden or covered window gets few animation frames or none, so the game would stand still: a message-channel pump
     (not throttled like timers) steps it in ~12 ms slices whenever frames lag, as the trials pump in main.js does */
  { const ch = new MessageChannel(); let due = performance.now();
    ch.port1.onmessage = () => { if (done) return; const now = performance.now();
      if (now - (G.rafAt || 0) < 40) { due = now; setTimeout(() => ch.port2.postMessage(0), 100); return; }
      let n = 0; try { while (due <= now && n < 200 && performance.now() - now < 12) { G.update(1 / 60); Input.endFrame(); due += 1000 / 60; n++; } } catch (err) { note(err && err.stack || err); }
      if (due < now - 200) due = now - 200; ch.port2.postMessage(0); };
    ch.port2.postMessage(0); }
  function finish() {
    done = true; const p = G.prof;
    let shot = ''; try { const c = document.createElement('canvas'); c.width = 320; c.height = 180; c.getContext('2d').drawImage(document.getElementById('game'), 0, 0, 320, 180); shot = c.toDataURL('image/jpeg', 0.6); } catch (e) {}
    window.__SOAK = { game: 'centipede', shot, seconds: +t.toFixed(1), fast, errors, checks: [], fps: +(1000 / p.frame).toFixed(1), updateMs: +p.upd.toFixed(2), drawMs: +p.draw.toFixed(2), slowFrames: p.slow, scenes: Object.fromEntries(Object.entries(scenes).map(([k, v]) => [k, +v.toFixed(1)])), score: G.score, level: G.level, kills: G.kills, alive: typeof Player !== 'undefined' && Player.alive, ok: !errors.length };
    console.log('SOAK ' + JSON.stringify(window.__SOAK));
  }
})();
