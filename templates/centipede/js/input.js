// Touch layout, in canvas coordinates (CFG.W x 720). The joystick appears wherever the left thumb lands.
// Buttons sit `rx` px from the RIGHT edge (x is filled in by fit() whenever the logical width changes).
const TOUCH = {
  joyR: 70, knobR: 32, dead: 0.22,
  buttons: [ // right-thumb cluster
    { id: 'fire',  code: 'Space', rx: 100, x: 1180, y: 640, r: 48, label: 'FIRE' },
    { id: 'mega',  code: 'KeyE',  rx: 200, x: 1080, y: 560, r: 40, label: 'MEGA' },
    { id: 'pause', code: 'KeyP',  rx: 62,  x: 1218, y: 48,  r: 34, label: 'CRAFT' }, // pause = the workbench
    { id: 'full',  code: null,    rx: 140, x: 1140, y: 48,  r: 30, label: '⛶' }, // fullscreen toggle
    { id: 'guide', code: null,    rx: 210, x: 1070, y: 48,  r: 30, label: '?' }, // guide mode: points at what to do next
  ],
  // the big "FULL SCREEN" pill on the title screen (touch devices only)
  pill: { w: 380, h: 54, y: 672 },
  // lay the buttons out for the current width and Size setting: the radii AND the gaps to the screen edges grow
  // together, so a bigger Size keeps the cluster's spacing instead of piling the circles into each other
  place() {
    const k = Render.ui();
    for (const b of this.buttons) {
      if (b.y0 === undefined) b.y0 = b.y;
      if (b.r0) b.r = Math.round(b.r0 * k);
      if (b.bar) { b.hw = Math.round(b.bar.hw * k); b.hh = Math.round(b.bar.hh * k); }
      b.x = Math.round(CFG.W - b.rx * k); b.y = Math.round(b.y0 < CFG.H / 2 ? b.y0 * k : CFG.H - (CFG.H - b.y0) * k);
    }
  },
  pillRect() { return { x: CFG.W / 2 - this.pill.w / 2, y: this.pill.y - this.pill.h / 2, w: this.pill.w, h: this.pill.h }; },
};
const Input = {
  keys: {}, pressed: {},
  // mobile = coarse pointer (phone/tablet). Laptops with touch screens stay keyboard-first; the first touch
  // still switches the touch controls on. Keyboard is always accepted.
  mobile: false, touch: false, joy: null, held: {}, canvas: null, fsAsked: false,
  isIOS() { return /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.maxTouchPoints > 1 && /Mac/.test(navigator.platform)); },
  standalone() { return !!(navigator.standalone || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)); },
  // fullscreen where the browser allows it (Android, desktop). iOS Safari has no API: Add to Home Screen instead.
  toggleFullscreen() {
    if (window.desktop) { window.desktop.fullscreen(); return; } // the desktop build: the window goes full screen, not the page
    const d = document, el = d.documentElement;
    try {
      if (d.fullscreenElement || d.webkitFullscreenElement) { (d.exitFullscreen || d.webkitExitFullscreen).call(d); return; }
      const req = el.requestFullscreen || el.webkitRequestFullscreen;
      if (!req) { G.msg(this.isIOS() ? 'iPhone: tap Share, then Add to Home Screen, and open it from there for fullscreen.' : 'Fullscreen is not available in this browser.', '#ffd166'); return; }
      const p = req.call(el); if (p && p.catch) p.catch(() => {});
      if (screen.orientation && screen.orientation.lock) { const q = screen.orientation.lock('landscape'); if (q && q.catch) q.catch(() => {}); }
    } catch (err) {}
  },
  // keep the screen awake while the game is open on a phone (the Screen Wake Lock API; re-taken when the tab comes back)
  wake: null,
  keepAwake() {
    try { if (!('wakeLock' in navigator) || this.wake || document.hidden) return; navigator.wakeLock.request('screen').then(w => { this.wake = w; w.addEventListener('release', () => { this.wake = null; }); }).catch(() => {}); } catch (e) {}
  },
  inFullscreen() {
    if (window.desktop) return window.innerWidth >= screen.width - 2 && window.innerHeight >= screen.height - 2; return !!(document.fullscreenElement || document.webkitFullscreenElement); },
  // the title-screen fullscreen pill: touch devices that are not already fullscreen or installed to the home screen
  showPill() { return this.touch && !this.inFullscreen() && !this.standalone(); },
  detectMobile() {
    const coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
    const ua = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent) || (navigator.maxTouchPoints > 1 && /Mac/.test(navigator.platform));
    return !!(coarse && (ua || window.innerWidth < 1100));
  },
  init() {
    this.mobile = this.detectMobile();
    for (const b of TOUCH.buttons) b.r0 = b.r; // base radii: scaled by the Size setting on touch screens (main.js fit())
    if (this.mobile) { this.touch = true; document.body.classList.add('mobile'); TOUCH.joyR = Math.round(TOUCH.joyR * 1.3); TOUCH.knobR = Math.round(TOUCH.knobR * 1.3); Object.assign(TOUCH.buttons.find(b => b.id === 'fire'), { y: 625, rx: 72 }); for (const [id, rx] of [['pause', 72], ['full', 150], ['guide', 220]]) TOUCH.buttons.find(b => b.id === id).rx = rx; /* CRAFT heads the same column as MEGA and FIRE */ Object.assign(TOUCH.buttons.find(b => b.id === 'mega'), { r: 30, r0: 30, rx: 72, y: 258, bar: { hw: 30, hh: 115 } }); } // MEGA: a tall charge bar on the right edge, above FIRE // thumbs are big: so are the buttons
    this.canvas = document.getElementById('game');
    const toCanvas = t => { const r = this.canvas.getBoundingClientRect(); return { x: (t.clientX - r.left) / r.width * CFG.W, y: (t.clientY - r.top) / r.height * CFG.H }; };
    const btnAt = p => TOUCH.buttons.find(b => b.bar ? Math.abs(p.x - b.x) < b.hw + 14 && Math.abs(p.y - b.y) < b.hh + 14 : (p.x - b.x) ** 2 + (p.y - b.y) ** 2 < (b.r + 14) ** 2);
    const press = code => { if (!this.keys[code]) this.pressed[code] = true; this.keys[code] = true; }; // held buttons (released on touchend)
    this.tapKey = code => { this.pressed[code] = true; }; // one-shot taps: never latch the key down
    const release = code => { this.keys[code] = false; };
    const onStart = e => {
      e.preventDefault(); SFX.init(); this.keepAwake();
      if (!this.touch) { this.touch = true; document.body.classList.add('mobile'); }
      for (const t of e.changedTouches) {
        const p = toCanvas(t); let b = btnAt(p);
        if (G.state === 'paused') b = null; // the workbench draws no cluster: its own buttons are Build's
        if (b && b.id === 'fire' && G.mode === 'deck') b = null; // the deck has no FIRE button: that touch steers
        if (b && b.id === 'full') { this.toggleFullscreen(); continue; }
        if (b && b.id === 'guide') { if (G.state !== 'title') Bot.toggleGuide(); continue; }
        if (b && b.id === 'pause' && G.state === 'paused') b = null; // hidden on the workbench: the green play button resumes
        if (b && (b.id === 'mega' || b.id === 'fire') && G.mode === 'dungeon' && G.state === 'playing') { this.swipes.set(t.identifier, { x: p.x, y: p.y, t: performance.now(), tap: b.code }); continue; } // the maze: MEGA and FIRE sit in the swipe zone, so a flick that starts on them still turns; a plain tap presses them as it lifts
        if (b) { press(b.code); this.held[t.identifier] = b.code; continue; }
        if (this.mobile && !this.standalone() && !this.inFullscreen() && (!this.fsAsked || G.state === 'title' || G.state === 'gameover')) { this.fsAsked = true; this.toggleFullscreen(); } // a phone: fullscreen on the first touch, and again on any tap that starts a run
        if (G.state === 'title') { this.titleTap(p); continue; }
        if (G.state === 'gameover') { this.tapKey('KeyR'); continue; }
        if (G.state === 'won') { this.tapKey('Enter'); continue; }
        if (G.state === 'perk') { this.tapShop(p); continue; }
        if (G.state === 'settings') { this.setTouch = { id: t.identifier, p, sc0: Settings.scroll || 0, moved: false }; continue; } // a tap on lift; a drag scrolls the list
        if (G.state === 'discover') { this.tapKey(this.discoverKey(p)); continue; }
        if (G.state === 'paused') { // workbench: tap or drag; a second finger starts the pinch magnifier (the screen springs back when it lifts)
          this.pts.set(t.identifier, p);
          if (this.pts.size >= 2) { const [a, b] = [...this.pts.values()]; this.pinch = { cx: (a.x + b.x) / 2, cy: (a.y + b.y) / 2, mx0: (a.x + b.x) / 2, my0: (a.y + b.y) / 2, d0: Math.max(20, Math.hypot(a.x - b.x, a.y - b.y)), scale: 1, dx: 0, dy: 0, release: false }; Build.drag = null; Build.press = null; this.benchTouch = null; continue; }
          Build.pointerDown(p); this.benchTouch = t.identifier; continue;
        }
        if (G.mode === 'dungeon' && p.x >= CFG.W * 0.55 && G.state === 'playing') { this.swipes.set(t.identifier, { x: p.x, y: p.y, t: performance.now() }); continue; } // the maze's right side: swipes
        if (G.mode === 'deck' && G.state === 'playing') { this.deckTouches.set(t.identifier, p); this.joy = { id: t.identifier, x0: p.x, y0: p.y, x: p.x, y: p.y }; continue; } // the deck: the newest thumb steers (a second thumb takes over without lifting the first)
        if (!this.joy && (p.x < CFG.W * 0.55)) this.joy = { /* on the saucer deck a finger anywhere drags the cannon */ id: t.identifier, x0: p.x, y0: p.y, x: p.x, y: p.y };
      }
    };
    this.swipes = new Map(); this.deckTouches = new Map();
    const onMove = e => { e.preventDefault(); for (const t of e.changedTouches) { const ST = this.setTouch; if (ST && t.identifier === ST.id) { const q = toCanvas(t), dy = q.y - ST.p.y; if (Math.abs(dy) > 12) ST.moved = true; if (ST.moved) Settings.scroll = U.clamp(ST.sc0 - dy, 0, (Settings.view || { maxS: 0 }).maxS); continue; } if (this.deckTouches.has(t.identifier)) this.deckTouches.set(t.identifier, toCanvas(t)); if (this.joy && t.identifier === this.joy.id) { const p = toCanvas(t); this.joy.x = p.x; this.joy.y = p.y;
        if (G.mode === 'dungeon') { const J = this.joy, dx = J.x - J.x0, dy = J.y - J.y0, d = Math.hypot(dx, dy), R = this.stickR() * CFG.DUNGEON.LEASH; if (d > R) { J.x0 = J.x - dx / d * R; J.y0 = J.y - dy / d * R; } } } // the maze: the stick's centre trails the thumb, so a turn is a short move from where it is now
      if (this.pts.has(t.identifier)) { this.pts.set(t.identifier, toCanvas(t)); const Z = this.pinch; if (Z && !Z.release && this.pts.size >= 2) { const [a, b] = [...this.pts.values()]; Z.scale = Math.max(1, Math.min(3, Math.hypot(a.x - b.x, a.y - b.y) / Z.d0)); Z.dx = (a.x + b.x) / 2 - Z.mx0; Z.dy = (a.y + b.y) / 2 - Z.my0; } }
      if (G.state === 'paused' && t.identifier === this.benchTouch && !this.pinch) Build.pointerMove(toCanvas(t)); } };
    const onEnd = e => { e.preventDefault(); for (const t of e.changedTouches) { const ST = this.setTouch; if (ST && t.identifier === ST.id) { this.setTouch = null; if (!ST.moved && G.state === 'settings') Settings.tap(ST.p); continue; } this.endSwipe(t.identifier, toCanvas(t)); if (this.deckTouches.delete(t.identifier) && this.joy && t.identifier === this.joy.id && this.deckTouches.size) { const [id, q] = [...this.deckTouches].pop(); this.joy = { id, x0: q.x, y0: q.y, x: q.x, y: q.y }; } /* the deck: lift the steering thumb and the one still down takes over */ else if (this.joy && t.identifier === this.joy.id) { this.joy = null; this.mazeLast = null; } const code = this.held[t.identifier]; if (code) { release(code); delete this.held[t.identifier]; }
      this.pts.delete(t.identifier); if (this.pinch && !this.pinch.release && this.pts.size < 2) this.pinch.release = true; // one finger left: the magnifier springs back
      if (t.identifier === this.benchTouch) { this.benchTouch = null; if (G.state === 'paused' && !this.pinch) Build.pointerUp(toCanvas(t)); else Build.drag = null; } } };
    document.addEventListener('visibilitychange', () => { if (!document.hidden && this.touch) this.keepAwake(); });
    this.canvas.addEventListener('touchstart', onStart, { passive: false });
    this.canvas.addEventListener('touchmove', onMove, { passive: false });
    this.canvas.addEventListener('touchend', onEnd, { passive: false });
    this.canvas.addEventListener('touchcancel', onEnd, { passive: false });
    // mouse/pen fallback: a click starts, restarts or continues (touch is handled above)
    this.canvas.addEventListener('pointerdown', e => {
      if (e.pointerType === 'touch') return;
      SFX.init();
      if (G.state === 'title') this.titleTap(toCanvas(e)); else if (G.state === 'gameover') this.tapKey('KeyR'); else if (G.state === 'won') this.tapKey('Enter');
      else if (G.state === 'settings') Settings.tap(toCanvas(e));
      else if (G.state === 'perk') this.tapShop(toCanvas(e)); // a mouse clicks the cards and the CONTINUE button too
      else if (G.state === 'playing') { const p = toCanvas(e), r = G.craftBtn, m = Render.deskMega(); if (r && p.x >= r.x && p.x <= r.x + r.w && p.y >= r.y && p.y <= r.y + r.h) this.tapKey('KeyP'); else if (Math.abs(p.x - m.x) <= m.hw + 6 && Math.abs(p.y - m.y) <= m.hh + 6) this.tapKey('KeyE'); } // the CRAFT pill at the top right, the MEGA bar on the right edge
      else if (G.state === 'discover') this.tapKey(this.discoverKey(toCanvas(e)));
      else if (G.state === 'paused') { this.mouseBench = true; Build.pointerDown(toCanvas(e)); }
    });
    window.addEventListener('pointermove', e => { if (e.pointerType === 'touch') return; this.mouse = toCanvas(e); if (this.mouseBench) Build.pointerMove(this.mouse); }); // (mouse: the workbench highlights the card under it)
    window.addEventListener('pointerup', e => { if (e.pointerType === 'touch' || !this.mouseBench) return; this.mouseBench = false; if (G.state === 'paused') Build.pointerUp(toCanvas(e)); else Build.drag = null; });
    window.addEventListener('keydown', e => { if (e.code === 'KeyF' && !e.repeat) Input.toggleFullscreen(); });
    this.press = press;
    const block = new Set(['Space', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'KeyW', 'KeyA', 'KeyS', 'KeyD', 'Tab', 'Slash']);
    window.addEventListener('keydown', e => {
      if (!Input.keys[e.code]) Input.pressed[e.code] = true;
      Input.keys[e.code] = true;
      if (block.has(e.code)) e.preventDefault();
      SFX.init();
    });
    window.addEventListener('keyup', e => { Input.keys[e.code] = false; });
    window.addEventListener('blur', () => { Input.keys = {}; });
  },
  // perk shop taps: a card buys it, the bottom hint row leaves
  tapShop(p) {
    if (Perks.spinning()) { Perks.finishReel(); return; } // a tap lands the reels
    const n = G.perkChoices.length, w = 260, h = this.touch ? 350 : 250, gap = 24, x0 = CFG.W / 2 - (n * w + (n - 1) * gap) / 2, y0 = 250; // (cards are taller on a phone: Render.drawPerks)
    for (let i = 0; i < n; i++) { const x = x0 + i * (w + gap); if (p.x >= x && p.x <= x + w && p.y >= y0 && p.y <= y0 + h) { G.perkSel = i; this.tapKey('Digit' + (i + 1)); return; } }
    const L = G.perkLeave; if (L && p.x >= L.x - 10 && p.x <= L.x + L.w + 10 && p.y >= L.y - 10 && p.y <= L.y + L.h + 10) this.tapKey('Space'); // the CONTINUE button
  },
  // a quick flick on the maze's right side queues a turn (its dominant axis), read by Dungeon.movePlayer
  endSwipe(id, p) {
    const s = this.swipes.get(id); if (!s) return; this.swipes.delete(id);
    const dx = p.x - s.x, dy = p.y - s.y; if (performance.now() - s.t > 450 || Math.hypot(dx, dy) < CFG.DUNGEON.SWIPE) { if (s.tap && Math.hypot(dx, dy) < CFG.DUNGEON.SWIPE) this.tapKey(s.tap); return; }
    this.swipeDir = Math.abs(dx) > Math.abs(dy) ? { x: Math.sign(dx), y: 0 } : { x: 0, y: Math.sign(dy) };
  },
  // the maze stick: four directions, biased toward turning. Moving along x, a thumb leaning past TURN_BIAS up or down is a
  // turn, a clear pull back is a reverse, anything else just keeps going (no request). null when the thumb is resting
  mazeDir(gdir) {
    const j = this.joyVec(); if (!j || j.d <= 0) return null;
    const n = Math.hypot(j.x, j.y) || 1, ux = j.x / n, uy = j.y / n, B = CFG.DUNGEON.TURN_BIAS, L = this.mazeLast;
    if (L && Math.abs(Math.abs(ux) - Math.abs(uy)) < 0.2 && Math.sign(L.x) * ux + Math.sign(L.y) * uy > 0.5) return L; // on the diagonal: hold the last answer (no flicker between two turns)
    const d = this.mazePick(gdir, ux, uy, B); this.mazeLast = d; return d;
  },
  mazeLast: null,
  mazePick(gdir, ux, uy, B) {
    if (gdir.x) { if (Math.abs(uy) >= B) return { x: 0, y: Math.sign(uy) }; return Math.sign(ux) === -gdir.x && Math.abs(ux) > 0.75 ? { x: -gdir.x, y: 0 } : null; }
    if (gdir.y) { if (Math.abs(ux) >= B) return { x: Math.sign(ux), y: 0 }; return Math.sign(uy) === -gdir.y && Math.abs(uy) > 0.75 ? { x: 0, y: -gdir.y } : null; }
    return Math.abs(ux) > Math.abs(uy) ? { x: Math.sign(ux), y: 0 } : { x: 0, y: Math.sign(uy) };
  },
  // the stick's reach: in the maze it is one maze cell as it shows on screen (move your thumb a square, the centipede turns
  // a square); elsewhere the usual stick
  stickR() { return G.mode === 'dungeon' && Dungeon.cell ? Math.max(CFG.DUNGEON.STICK_MIN, Dungeon.cell * Render.zoom()) : TOUCH.joyR; },
  // joystick vector (-1..1 each), null when idle
  joyVec() {
    if (!this.joy) return null;
    let dx = this.joy.x - this.joy.x0, dy = this.joy.y - this.joy.y0;
    const d = Math.hypot(dx, dy), R = this.stickR();
    if (d < R * (G.mode === 'dungeon' ? CFG.DUNGEON.STICK_DEAD : TOUCH.dead)) return { x: 0, y: 0, d: 0 };
    const k = Math.min(1, d / R); dx /= d; dy /= d;
    return { x: dx * k, y: dy * k, d: k };
  },
  // a tap on the discovery card: the checkbox (C), the workbench button (P), or anywhere else carries on (Enter)
  discoverKey(p) { const inr = r => r && p.x >= r.x && p.x <= r.x + r.w && p.y >= r.y && p.y <= r.y + r.h; return inr(G.discoverBox) ? 'KeyC' : inr(G.discoverBench) ? 'KeyP' : 'Enter'; },
  down(c) { return !!this.keys[c]; },
  pts: new Map(), pinch: null, // touches on the workbench, and the pinch magnifier { cx, cy, scale, dx, dy, release } (drawn by Render.drawPaused)
  // a tap on the title: the NEW RUN box, the ENABLE SOUND and FULL SCREEN squares (Render.drawTitle records them), or play
  titleTap(p) {
    const inr = r => r && p.x >= r.x && p.x <= r.x + r.w && p.y >= r.y && p.y <= r.y + r.h, T = G.titleBtns || {};
    if (inr(G.newRunBox)) { this.tapKey('KeyN'); return; }
    if (inr(T.load)) { Save.resume(); return; }
    if (inr(T.sound)) { G.titleSound(); return; }
    if (inr(G.titleWord)) { // the title word: five quick taps arm god mode (on a phone too); a lone tap just starts, a beat later, so a burst never starts the game
      const now = performance.now(); if (now - G.godTapT > 3000) G.godTaps = 0; G.godTapT = now; clearTimeout(this.wordT);
      if (++G.godTaps >= 5) { G.godTaps = 0; G.godArmed = !G.godArmed; SFX.play(G.godArmed ? 'power' : 'shed'); return; }
      this.wordT = setTimeout(() => { if (G.state === 'title' && performance.now() - G.godTapT >= 400) { G.godTaps = 0; this.tapKey('Enter'); } }, 420);
      return;
    }
    if (inr(T.full)) { this.fsAsked = true; this.toggleFullscreen(); return; }
    if (inr(T.quit) && window.desktop) { window.desktop.quit(); return; }
    this.tapKey('Enter');
  },
  once(c) { if (this.pressed[c]) { this.pressed[c] = false; return true; } return false; },
  endFrame() { this.pressed = {}; },
  axis() {
    let x = 0, y = 0;
    if (this.down('KeyA') || this.down('ArrowLeft')) x -= 1;
    if (this.down('KeyD') || this.down('ArrowRight')) x += 1;
    if (this.down('KeyW') || this.down('ArrowUp')) y -= 1;
    if (this.down('KeyS') || this.down('ArrowDown')) y += 1;
    if (!x && !y) { // joystick, quantised to 8 directions so the dungeon grid logic keeps working
      const j = this.joyVec();
      if (j && j.d > 0) { const a = Math.atan2(j.y, j.x), s = Math.round(a / (Math.PI / 4)); x = Math.round(Math.cos(s * Math.PI / 4)); y = Math.round(Math.sin(s * Math.PI / 4)); }
    }
    if (!x && !y && Bot.on) { x = Bot.axis.x; y = Bot.axis.y; } // the autopilot steers only while you are not
    return { x, y };
  }
};
