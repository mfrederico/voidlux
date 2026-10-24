// VoidLux kit: the test harness. URL parameters, read at boot:
//   ?soak=N      play for N game-seconds with the scenes' bots (scene.bot(dt)) driving, muted, catching every exception and
//                console.error; then post a report to the studio (/api/soak/report) and set window.__SOAK for the headless
//                runner (studio/soak.mjs). The report: errors, fps, slow frames, scenes visited, score, memory.
//   ?fast=K      K updates per frame (soak only): a long run in a short time. In a hidden tab a MessageChannel pump keeps
//                stepping (browsers throttle animation frames there).
//   ?seed=N      seed Rng for a repeatable run
//   ?fps=1       the profiler overlay
//   ?bot=1       the bots play without a soak (watch the autopilot)
// Test.assert(cond, what) records a failed check without throwing (the game goes on; the report lists it).
const Test = {
  soak: 0, fast: 0, errors: [], checks: [], t: 0, scenes: {}, done: false, game: null,
  init(game) {
    this.game = game; const p = game.params;
    if (p.seed) Rng.seed(+p.seed);
    if (p.soak) { this.soak = +p.soak || 60; this.fast = Math.max(1, Math.min(60, +p.fast || 1)); SFX.muted = true; Input.bot = { axis: { x: 0, y: 0 } }; document.title += ' · soak'; }
    else if (p.bot) Input.bot = { axis: { x: 0, y: 0 } };
    window.addEventListener('error', e => this.error(e.error || e.message));
    window.addEventListener('unhandledrejection', e => this.error(e.reason));
    const ce = console.error.bind(console); console.error = (...a) => { this.note(a.map(x => x && x.stack || String(x)).join(' ')); ce(...a); };
  },
  error(err) { const s = String(err && err.stack || err && err.message || err); if (!this.errors.some(e => e.text === s)) this.errors.push({ text: s, at: +this.t.toFixed(1), scene: Game.state, score: Game.score }); },
  note(s) { if (!this.errors.some(e => e.text === s)) this.errors.push({ text: s, at: +this.t.toFixed(1), scene: Game.state, console: true }); },
  assert(ok, what) { if (!ok && !this.checks.some(c => c.what === what)) this.checks.push({ what, at: +this.t.toFixed(1), scene: Game.state }); return !!ok; },
  tick(dt) { if (!this.soak || this.done) return; this.t += dt; this.scenes[Game.state] = (this.scenes[Game.state] || 0) + dt; if (this.t >= this.soak) this.finish(); },
  pump(step) {
    const orig = step; this.step = step;
    if (!this.soak) return;
    const ch = new MessageChannel(); let due = performance.now();
    ch.port1.onmessage = () => {
      if (this.done) return; const now = performance.now();
      if (now - (this.game.rafAt || 0) < 40) { due = now; setTimeout(() => ch.port2.postMessage(0), 100); return; }
      const stepMs = 1000 / (60 * this.fast); let n = 0;
      try { while (due <= now && n < 400 && performance.now() - now < 12) { orig(1 / 60); Input.endFrame(); due += stepMs; n++; } } catch (err) { console.error(err); this.error(err); }
      if (due < now - 200) due = now - 200;
      ch.port2.postMessage(0);
    };
    ch.port2.postMessage(0);
  },
  report() {
    const p = Game.prof, mem = performance.memory ? Math.round(performance.memory.usedJSHeapSize / 1048576) : null;
    return { game: Game.id, seconds: +this.t.toFixed(1), fast: this.fast, errors: this.errors, checks: this.checks, fps: +(1000 / p.frame).toFixed(1), updateMs: +p.upd.toFixed(2), drawMs: +p.draw.toFixed(2), slowFrames: p.slow, frames: p.frames, scenes: Object.fromEntries(Object.entries(this.scenes).map(([k, v]) => [k, +v.toFixed(1)])), score: Game.score, best: Game.best, heapMB: mem, ok: !this.errors.length && !this.checks.length };
  },
  finish() {
    if (this.done) return; this.done = true; const r = this.report(); window.__SOAK = r; console.log('SOAK ' + JSON.stringify(r));
    try { fetch('/api/soak/report?game=' + encodeURIComponent(Game.id), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(r) }).catch(() => {}); } catch (e) {}
  },
};
// the soak clock rides on Game's step: wrap scene updates through Test.tick
(function hookTick() { const go = Game.go.bind(Game); Game.go = function (name, arg) { go(name, arg); const sc = Game.scene; if (sc && !sc.__hooked) { sc.__hooked = true; const up = sc.update.bind(sc); sc.update = dt => { Test.tick(dt); up(dt); }; } }; })();
