// VoidLux kit: the game shell. One canvas, one requestAnimationFrame loop, scenes, a camera, messages, settings and saves.
//
//   Game.boot({ id, title, W, H, WMIN, WMAX, scenes, start })   from the game's js/main.js, after every script has loaded
//     id        the game's slug: namespaces localStorage (settings, best score, saves)
//     W, H      logical size (default 1280x720). H is fixed; W follows the screen's aspect between WMIN and WMAX so a phone in
//               fullscreen shows more width instead of black bars. Everything lays out from Game.W at draw time.
//     scenes    { name: { enter(arg), leave(), update(dt), draw(ctx), bot(dt) } }; Game.go('name', arg) switches.
//               update runs with dt in seconds (capped at 1/30). draw gets the context already cleared and scaled.
//               bot(dt) is the scene's autopilot for soak tests: set Input.bot.axis and call Input.tapKey.
//     start     the first scene (default 'title')
//   Game.W, Game.H, Game.cam {x, y}, Game.time, Game.score, Game.best, Game.state (the current scene's name)
//   Game.msg(text, color)      a line in the message log (Game.msgs, drawn by Game.drawMsgs)
//   Game.shake = n             screen shake (pixels, decays)
//   Game.world(fn)             draw fn under the camera transform (world space); the HUD draws after in screen space
//   Game.prof                  smoothed ms for update and draw, the frame interval, slow frame count (`?fps=1` or the ` key shows it)
//   Game.lastError             the last exception caught in a frame (shown on screen; the loop never dies)
//   Settings.data / Settings.save()    sound, volume, plus whatever the game adds to Settings.DEFAULTS
//   Save.write(obj) / Save.load() / Save.clear()   one saved run per game in localStorage
const Game = {
  W: 1280, H: 720, WMIN: 960, WMAX: 1600, id: 'game', title: 'Game', state: '', scene: null, scenes: {}, time: 0, score: 0, best: 0, cam: { x: 0, y: 0 }, msgs: [], shake: 0, slowmo: 0,
  prof: { upd: 0, draw: 0, frame: 16.7, last: 0, slow: 0, frames: 0 }, showProf: false, lastError: null, canvas: null, ctx: null, scale: 1, uiScale: 1, params: {},
  go(name, arg) { const next = this.scenes[name]; if (!next) throw new Error('no scene ' + name); if (this.scene && this.scene.leave) this.scene.leave(); this.state = name; this.scene = next; if (next.enter) next.enter(arg); },
  msg(text, color) { const same = this.msgs.find(m => m.text === text && m.t > 0); if (same) { same.n = (same.n || 1) + 1; same.t = 4; return; } this.msgs.push({ text, color: color || '#fff', t: 4 }); if (this.msgs.length > 6) this.msgs.shift(); },
  drawMsgs(x, y, size) { let yy = y; for (const m of this.msgs) { Draw.ctx.globalAlpha = Math.min(1, m.t); Draw.text(m.text + (m.n > 1 ? ' ×' + m.n : ''), x, yy, size || 18, m.color); yy += (size || 18) * 1.3; } Draw.ctx.globalAlpha = 1; },
  world(fn) { const c = this.ctx; c.save(); c.translate(-Math.round(this.cam.x), -Math.round(this.cam.y)); Draw.camM = c.getTransform(); fn(c); Draw.camM = null; c.restore(); },
  setBest() { if (this.score > this.best) { this.best = this.score; try { localStorage.setItem(this.id + '.best', String(this.best)); } catch (e) {} } },
  boot(o) {
    Object.assign(this, { id: o.id || 'game', title: o.title || 'Game', W: o.W || 1280, H: o.H || 720, WMIN: o.WMIN || 960, WMAX: o.WMAX || 1600, scenes: o.scenes || {} });
    this.params = Object.fromEntries(new URLSearchParams(location.search));
    const canvas = this.canvas = document.getElementById('game'); canvas.width = this.W; canvas.height = this.H;
    Draw.init(canvas); this.ctx = Draw.ctx;
    Settings.load(); try { this.best = parseInt(localStorage.getItem(this.id + '.best') || '0', 10) || 0; } catch (e) {}
    SFX.muted = !Settings.data.sound; SFX.volume(Settings.data.volume);
    const toLogical = (cx, cy) => { const r = canvas.getBoundingClientRect(); return { x: (cx - r.left) / r.width * this.W, y: (cy - r.top) / r.height * this.H }; };
    Input.init(canvas, toLogical);
    if (window.desktop) document.body.classList.add('desktop');
    const fit = () => {
      const vw = window.innerWidth, vh = window.innerHeight;
      const W = Math.round(U.clamp(this.H * vw / vh, this.WMIN, this.WMAX) / 2) * 2;
      if (W !== this.W) { this.W = W; canvas.width = W; if (this.scene && this.scene.resize) this.scene.resize(); }
      this.uiScale = Input.touch ? (Settings.data.size || 1) : 1; TOUCH.place(this.W, this.H, this.uiScale);
      const s = Math.min(vw / this.W, vh / this.H) * (Input.mobile ? 1 : 0.98); this.scale = s;
      canvas.style.width = Math.floor(this.W * s) + 'px'; canvas.style.height = Math.floor(this.H * s) + 'px';
      Draw.setPx(Math.min(3, Math.max(1, Math.round(s * (window.devicePixelRatio || 1) * 2) / 2)));
    };
    for (const ev of ['resize', 'orientationchange', 'fullscreenchange', 'webkitfullscreenchange']) window.addEventListener(ev, () => { fit(); setTimeout(fit, 300); });
    fit();
    if (this.params.fps) this.showProf = true;
    Test.init(this);
    this.go(o.start || 'title');
    let last = performance.now();
    const step = dt => { this.time += dt; if (this.shake > 0) this.shake = Math.max(0, this.shake - dt * 30); for (const m of this.msgs) m.t -= dt; if (this.msgs.length && this.msgs[0].t <= -1) this.msgs.shift(); if (Input.bot && this.scene.bot) this.scene.bot(dt); this.scene.update(dt); };
    const loop = now => {
      this.rafAt = now;
      let dt = Math.min(1 / 30, (now - last) / 1000); last = now;
      if (this.slowmo > 0) { this.slowmo -= dt; dt *= 0.35; }
      try {
        const t0 = performance.now();
        if (Test.fast) { for (let k = 0; k < Test.fast; k++) { step(1 / 60); Input.endFrame(); } } else step(dt);
        const t1 = performance.now();
        if (Input.once('Backquote')) this.showProf = !this.showProf;
        const c = this.ctx; c.setTransform(1, 0, 0, 1, 0, 0); c.clearRect(0, 0, this.W, this.H);
        if (this.shake > 0) c.translate(U.rand(-this.shake, this.shake), U.rand(-this.shake, this.shake));
        this.scene.draw(c);
        c.setTransform(1, 0, 0, 1, 0, 0);
        Input.drawTouch(c);
        if (this.showProf) this.drawProf(c);
        if (this.lastError && this.lastError.t > 0) { this.lastError.t -= dt; Draw.text('Error: ' + this.lastError.text, 20, this.H - 40, 14, '#ff7eb6'); }
        const t2 = performance.now(), p = this.prof;
        p.upd += (t1 - t0 - p.upd) * 0.1; p.draw += (t2 - t1 - p.draw) * 0.1; p.frame += (Math.min(100, now - p.last) - p.frame) * 0.1; p.last = now; p.frames++;
        if (t2 - t0 > 16.7) p.slow++;
      } catch (err) {
        console.error(err); this.lastError = { text: String(err && err.message || err), t: 6 }; Test.error(err);
      }
      Input.endFrame();
      requestAnimationFrame(loop);
    };
    requestAnimationFrame(loop);
    Test.pump(step); // fast soaks in a hidden tab: a MessageChannel pump keeps stepping when frames stop coming
  },
  drawProf(c) { const p = this.prof; Draw.roundRect(10, 10, 230, 64, 8, 'rgba(0,0,0,0.6)'); Draw.ink(`${(1000 / p.frame).toFixed(0)} fps · ${p.frame.toFixed(1)} ms`, 20, 26, 14, '#9bf06a'); Draw.ink(`update ${p.upd.toFixed(2)} ms · draw ${p.draw.toFixed(2)} ms`, 20, 44, 13, '#fff'); Draw.ink(`slow frames ${p.slow} / ${p.frames}`, 20, 62, 13, '#ffd166'); },
};
// Settings: a small persisted object. A game adds its own keys to Settings.DEFAULTS before Game.boot.
const Settings = {
  DEFAULTS: { sound: true, volume: 1, size: 1 }, data: null,
  load() { let d = null; try { d = JSON.parse(localStorage.getItem(Game.id + '.settings') || 'null'); } catch (e) {} this.data = Object.assign({}, this.DEFAULTS, d || {}); return this.data; },
  save() { try { localStorage.setItem(Game.id + '.settings', JSON.stringify(this.data)); } catch (e) {} SFX.muted = !this.data.sound; SFX.volume(this.data.volume); },
  toggleSound() { this.data.sound = !this.data.sound; this.save(); if (!this.data.sound) Music.stop(); },
};
// Save: one saved run per game. write(obj) stores it with a version and time; load() gives it back or null.
const Save = {
  V: 1, data: null,
  load() { try { this.data = JSON.parse(localStorage.getItem(Game.id + '.run') || 'null'); } catch (e) { this.data = null; } if (this.data && this.data.v !== this.V) this.data = null; return this.data; },
  has() { return !!this.data; },
  write(obj) { const d = Object.assign({ v: this.V, t: Date.now() }, obj); try { localStorage.setItem(Game.id + '.run', JSON.stringify(d)); this.data = d; return true; } catch (e) { return false; } },
  clear() { this.data = null; try { localStorage.removeItem(Game.id + '.run'); } catch (e) {} },
  when() { const d = this.data; if (!d) return ''; const m = Math.round((Date.now() - d.t) / 60000); return m < 1 ? 'just now' : m < 60 ? `${m} min ago` : m < 1440 ? `${Math.round(m / 60)} h ago` : `${Math.round(m / 1440)} d ago`; },
};
