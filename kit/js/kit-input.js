// VoidLux kit: input. Keyboard, mouse and touch on the one canvas, read by the game as plain state:
//   Input.down('KeyA')     the key (or touch button with that code) is held      Input.once('Space')  it was pressed this frame
//   Input.axis()           { x, y } in -1..1 from WASD / arrows / the touch joystick / a gamepad
//   Input.mouse            { x, y, down, clicked, inside } in logical canvas units      Input.taps  taps this frame [{ x, y }]
//   Input.touch            the player has touched the screen (the touch controls draw); Input.mobile  a phone or tablet
//   Input.tapKey(code)     press a key for one frame (bots and touch buttons use it)
// Touch controls are data: TOUCH.buttons is a list of { id, code, rx, y, r, label } placed from the right edge (rx) at a
// logical y; the kit draws them (Input.drawTouch) and routes touches on them to their key code. A game edits TOUCH.buttons
// at boot to fit its controls. The left half of the screen is a floating joystick unless the game sets TOUCH.joystick = false.
// Every touch and key also wakes the audio (SFX.init): browsers need a gesture for that.
const TOUCH = {
  joystick: true, joyR: 70, knobR: 32, dead: 0.22, joyZone: 0.55, // the joystick appears where the thumb lands, in the left joyZone of the screen
  buttons: [
    { id: 'fire', code: 'Space', rx: 100, y: 640, r: 48, label: 'FIRE' },
    { id: 'pause', code: 'KeyP', rx: 62, y: 48, r: 30, label: 'II' },
    { id: 'full', code: null, rx: 140, y: 48, r: 30, label: '⛶' },
  ],
  place(W, H, k) { for (const b of this.buttons) { if (b.y0 === undefined) { b.y0 = b.y; b.r0 = b.r; } b.r = Math.round(b.r0 * (k || 1)); b.x = Math.round(W - b.rx * (k || 1)); b.y = Math.round(b.y0 < 360 ? b.y0 * (k || 1) : H - (720 - b.y0) * (k || 1)); } },
};
const Input = {
  keys: {}, pressed: {}, mouse: { x: 0, y: 0, down: false, clicked: false, inside: false }, taps: [], mobile: false, touch: false, joy: null, held: {}, canvas: null, fsAsked: false, bot: null, wake: null, pad: null,
  isIOS() { return /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.maxTouchPoints > 1 && /Mac/.test(navigator.platform)); },
  standalone() { return !!(navigator.standalone || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)); },
  detectMobile() { const coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches; const ua = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent) || (navigator.maxTouchPoints > 1 && /Mac/.test(navigator.platform)); return !!(coarse && (ua || window.innerWidth < 1100)); },
  toggleFullscreen() {
    if (window.desktop) { window.desktop.fullscreen(); return; }
    const d = document, el = d.documentElement;
    try {
      if (d.fullscreenElement || d.webkitFullscreenElement) { (d.exitFullscreen || d.webkitExitFullscreen).call(d); return; }
      const req = el.requestFullscreen || el.webkitRequestFullscreen; if (!req) return;
      const p = req.call(el); if (p && p.catch) p.catch(() => {});
      if (screen.orientation && screen.orientation.lock) { const q = screen.orientation.lock('landscape'); if (q && q.catch) q.catch(() => {}); }
    } catch (err) {}
  },
  inFullscreen() { return !!(document.fullscreenElement || document.webkitFullscreenElement); },
  keepAwake() { try { if (!('wakeLock' in navigator) || this.wake || document.hidden) return; navigator.wakeLock.request('screen').then(w => { this.wake = w; w.addEventListener('release', () => { this.wake = null; }); }).catch(() => {}); } catch (e) {} },
  down(code) { return !!this.keys[code]; },
  once(code) { return !!this.pressed[code]; },
  tapKey(code) { this.pressed[code] = true; },
  axis() {
    let x = 0, y = 0;
    if (this.down('KeyA') || this.down('ArrowLeft')) x -= 1; if (this.down('KeyD') || this.down('ArrowRight')) x += 1;
    if (this.down('KeyW') || this.down('ArrowUp')) y -= 1; if (this.down('KeyS') || this.down('ArrowDown')) y += 1;
    if (this.joy) { const dx = this.joy.x - this.joy.x0, dy = this.joy.y - this.joy.y0, d = Math.hypot(dx, dy), R = TOUCH.joyR; if (d > R * TOUCH.dead) { const m = Math.min(1, d / R); x = dx / d * m; y = dy / d * m; } }
    if (this.pad) { const g = navigator.getGamepads && navigator.getGamepads()[this.pad]; if (g) { const ax = g.axes[0] || 0, ay = g.axes[1] || 0; if (Math.hypot(ax, ay) > 0.2) { x = ax; y = ay; } if (g.buttons[0] && g.buttons[0].pressed) this.keys.Space = true; } }
    if (this.bot && this.bot.axis && !x && !y) { x = this.bot.axis.x || 0; y = this.bot.axis.y || 0; }
    const m = Math.hypot(x, y); if (m > 1) { x /= m; y /= m; } return { x, y };
  },
  init(canvas, toLogical) {
    this.canvas = canvas; this.toLogical = toLogical; this.mobile = this.detectMobile();
    if (this.mobile) { this.touch = true; document.body.classList.add('mobile'); TOUCH.joyR = Math.round(TOUCH.joyR * 1.3); TOUCH.knobR = Math.round(TOUCH.knobR * 1.3); }
    const toCanvas = t => toLogical(t.clientX, t.clientY);
    const btnAt = p => TOUCH.buttons.find(b => !b.hidden && (p.x - b.x) ** 2 + (p.y - b.y) ** 2 < (b.r + 14) ** 2);
    const press = code => { if (!this.keys[code]) this.pressed[code] = true; this.keys[code] = true; };
    const onStart = e => {
      e.preventDefault(); SFX.init(); this.keepAwake();
      if (!this.touch) { this.touch = true; document.body.classList.add('mobile'); }
      for (const t of e.changedTouches) {
        const p = toCanvas(t), b = btnAt(p);
        if (b && b.id === 'full') { this.toggleFullscreen(); continue; }
        if (b && b.code) { press(b.code); this.held[t.identifier] = b.code; continue; }
        if (this.mobile && !this.standalone() && !this.inFullscreen() && !this.fsAsked) { this.fsAsked = true; this.toggleFullscreen(); }
        this.taps.push({ x: p.x, y: p.y });
        if (TOUCH.joystick && !this.joy && p.x < (typeof Game !== 'undefined' ? Game.W : 1280) * TOUCH.joyZone) this.joy = { id: t.identifier, x0: p.x, y0: p.y, x: p.x, y: p.y };
      }
    };
    const onMove = e => { e.preventDefault(); for (const t of e.changedTouches) { if (this.joy && t.identifier === this.joy.id) { const p = toCanvas(t); this.joy.x = p.x; this.joy.y = p.y; const dx = p.x - this.joy.x0, dy = p.y - this.joy.y0, d = Math.hypot(dx, dy), R = TOUCH.joyR * 1.4; if (d > R) { this.joy.x0 = p.x - dx / d * R; this.joy.y0 = p.y - dy / d * R; } } } };
    const onEnd = e => { e.preventDefault(); for (const t of e.changedTouches) { if (this.joy && t.identifier === this.joy.id) this.joy = null; const code = this.held[t.identifier]; if (code) { this.keys[code] = false; delete this.held[t.identifier]; } } };
    canvas.addEventListener('touchstart', onStart, { passive: false }); canvas.addEventListener('touchmove', onMove, { passive: false }); canvas.addEventListener('touchend', onEnd, { passive: false }); canvas.addEventListener('touchcancel', onEnd, { passive: false });
    window.addEventListener('keydown', e => { if (e.repeat) return; SFX.init(); if (!this.keys[e.code]) this.pressed[e.code] = true; this.keys[e.code] = true; if (['Space', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.code)) e.preventDefault(); });
    window.addEventListener('keyup', e => { this.keys[e.code] = false; });
    window.addEventListener('blur', () => { this.keys = {}; this.held = {}; this.joy = null; });
    canvas.addEventListener('mousemove', e => { const p = toLogical(e.clientX, e.clientY); this.mouse.x = p.x; this.mouse.y = p.y; this.mouse.inside = true; });
    canvas.addEventListener('mouseleave', () => { this.mouse.inside = false; });
    canvas.addEventListener('mousedown', e => { SFX.init(); const p = toLogical(e.clientX, e.clientY); this.mouse.x = p.x; this.mouse.y = p.y; this.mouse.down = true; this.mouse.clicked = true; this.taps.push({ x: p.x, y: p.y }); });
    window.addEventListener('mouseup', () => { this.mouse.down = false; });
    canvas.addEventListener('contextmenu', e => e.preventDefault());
    window.addEventListener('gamepadconnected', e => { this.pad = e.gamepad.index; });
  },
  // a tap or click inside a rect this frame (menus and buttons)
  tapped(r) { return this.taps.some(t => U.inRect(t.x, t.y, r.x, r.y, r.w, r.h)) || (this.mouse.clicked && U.inRect(this.mouse.x, this.mouse.y, r.x, r.y, r.w, r.h)); },
  endFrame() { this.pressed = {}; this.taps.length = 0; this.mouse.clicked = false; },
  // the touch controls, drawn by the game's HUD when Input.touch (after everything else)
  drawTouch(ctx) {
    if (!this.touch) return;
    ctx.save(); ctx.globalAlpha = 0.55;
    if (this.joy) { Draw.circle(this.joy.x0, this.joy.y0, TOUCH.joyR, 'rgba(255,255,255,0.12)', 'rgba(255,255,255,0.5)', 2); const dx = this.joy.x - this.joy.x0, dy = this.joy.y - this.joy.y0, d = Math.hypot(dx, dy), m = Math.min(d, TOUCH.joyR); Draw.circle(this.joy.x0 + (d ? dx / d * m : 0), this.joy.y0 + (d ? dy / d * m : 0), TOUCH.knobR, 'rgba(255,255,255,0.6)'); }
    for (const b of TOUCH.buttons) { if (b.hidden) continue; const on = b.code && this.keys[b.code]; Draw.circle(b.x, b.y, b.r, on ? 'rgba(255,160,40,0.7)' : 'rgba(255,255,255,0.14)', 'rgba(255,255,255,0.6)', 2); Draw.ink(b.label, b.x, b.y + 1, Math.round(b.r * 0.5), '#fff', 'center'); }
    ctx.restore();
  },
};
