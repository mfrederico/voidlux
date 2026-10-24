// Drawing core: the canvas, the primitives every drawer shares (circle, eyes, text, roundRect), the camera transform and frame(), the one draw call per tick.
// The rest of the drawing lives in render-*.js; each of those files adds its methods to this same Render object with Object.assign, so `this` works across files.
// Sticker-cute style: chunky rounded shapes, thick dark outlines, big glossy eyes.
const Render = {
  ctx: null, grassTile: null, OUT: '#2b1d3a',

  init(canvas) {
    this.ctx = this.ctx0 = canvas.getContext('2d');
    const t = document.createElement('canvas'); t.width = t.height = 160;
    const g = t.getContext('2d');
    g.fillStyle = 'rgba(0,0,0,0)'; g.clearRect(0, 0, 160, 160);
    for (let i = 0; i < 90; i++) {
      const x = Math.random() * 160, y = Math.random() * 160;
      g.strokeStyle = Math.random() < 0.5 ? 'rgba(255,255,255,0.13)' : 'rgba(0,0,0,0.10)';
      g.lineWidth = 2; g.lineCap = 'round';
      g.beginPath(); g.moveTo(x, y); g.lineTo(x + U.rand(-3, 3), y - U.rand(3, 7)); g.stroke();
    }
    for (let i = 0; i < 10; i++) { g.fillStyle = 'rgba(255,255,255,0.18)'; g.beginPath(); g.arc(Math.random() * 160, Math.random() * 160, 1.6, 0, TAU); g.fill(); }
    this.grassTile = this.ctx.createPattern(t, 'repeat');
  },

  // ---- sprites: a monster's body drawn once into the sprite atlas, then stamped every frame after ----
  // A swarm is hundreds of monsters of a handful of kinds, and drawing each one's legs, shell and eyes stroke by stroke was
  // most of a phone's frame. A drawer paints a body at (0, 0) facing angle 0; sprite() keeps the result under its key (kind,
  // colour, size, flash, animation frame) at the canvas's real pixel scale, and stamp() puts it down, turned. Only what turns
  // with the body goes in: shadows, status rings, hp bars and anything that stays upright are drawn live around it
  // Sprites live in 2048 px atlas canvases packed in shelves: one big canvas stays on the GPU, where a small canvas per sprite
  // was kept in memory and sent up again at every stamp. Monsters and loot share one page, text another (damage numbers
  // come and go and would keep evicting the monsters). A full page is wiped and refills as things are drawn
  sprites: new Map(), pxScale: 1, pages: {}, ATLAS: 2048, camM: null,
  SPRITE_BUGS: { gnat: 1, beetle: 1, stinkbug: 1, hornet: 1, earwig: 1, roach: 1 }, // the swarm's bugs; the rest are rare, upright or animate more than a frame can hold
  // a w x h box (screen pixels) on a page; S.x, S.y is its corner on the page's canvas
  alloc(page, id, w, h) {
    const A = this.ATLAS, P = this.pages[page] || (this.pages[page] = { cv: null, x: 0, y: 0, h: 0, keys: [] });
    if (!P.cv) { P.cv = document.createElement('canvas'); P.cv.width = P.cv.height = A; }
    if (P.x + w > A) { P.x = 0; P.y += P.h + 2; P.h = 0; } // the next shelf
    if (P.y + h > A) { for (const k of P.keys) this.sprites.delete(k); P.keys = []; P.cv.getContext('2d').clearRect(0, 0, A, A); P.x = P.y = P.h = 0; } // full: start over
    const S = { cv: P.cv, x: P.x, y: P.y, w, h }; P.x += w + 2; P.h = Math.max(P.h, h); P.keys.push(id); this.sprites.set(id, S); return S;
  },
  // paint() draws round (0, 0) in world units; half is how far it reaches. Cached under key at the current pixel scale
  sprite(key, half, paint) {
    const k = this.pxScale, id = key + '@' + k; let S = this.sprites.get(id); if (S) return S;
    const n = Math.ceil(half * 2 * k) + 2; S = this.alloc('mon', id, n, n); S.hw = S.hh = n / 2 / k;
    this.paintInto(S, n / 2, n / 2, paint); return S;
  },
  paintInto(S, ox, oy, paint) {
    const g = S.cv.getContext('2d'), was = this.ctx, k = this.pxScale;
    g.save(); g.beginPath(); g.rect(S.x, S.y, S.w, S.h); g.clip(); g.translate(S.x + ox, S.y + oy); g.scale(k, k); g.lineCap = 'round'; // (round: what the play field's context has been left at by the time monsters draw)
    this.ctx = g; try { paint(); } finally { this.ctx = was; g.restore(); }
  },
  // Render.text as a sprite: for text that repeats (damage numbers, a dot's value), drawn from the text page
  textSprite(s, size, color, align) {
    const k = this.pxScale, id = 'T' + s + '|' + size + '|' + color + '|' + (align || 'left') + '@' + k; let S = this.sprites.get(id); if (S) return S;
    const c = this.ctx; c.font = `bold ${size}px "Trebuchet MS", "Segoe UI", sans-serif`; const tw = c.measureText(s).width, pad = Math.max(3, size * 0.18) + 2;
    const w = Math.ceil((tw + pad * 2) * k), h = Math.ceil((size * 1.3 + pad * 2) * k); S = this.alloc('txt', id, w, h);
    const ax = align === 'center' ? tw / 2 + pad : align === 'right' ? tw + pad : pad; S.hw = ax; S.hh = h / 2 / k; S.ww = w / k; S.wh = h / k; // where the anchor is, in world units
    this.paintInto(S, ax * k, h / 2, () => this.text(s, 0, 0, size, color, align)); return S;
  },
  textStamp(s, x, y, size, color, align) { const S = this.textSprite(s, size, color, align); this.ctx.drawImage(S.cv, S.x, S.y, S.w, S.h, x - S.hw, y - S.hh, S.ww, S.wh); },
  // put a sprite down at (x, y) turned by a. Turned by setting the transform outright from the camera's (camM, kept for
  // the world pass) and putting it back after: save() and restore() copy the whole context state, and reading the
  // transform back makes an object, both more than the stamp itself
  stamp(S, x, y, a) {
    const c = this.ctx, hw = S.hw, hh = S.hh; if (!a) { c.drawImage(S.cv, S.x, S.y, S.w, S.h, x - hw, y - hh, hw * 2, hh * 2); return; }
    const m = c === this.ctx0 && this.camM || c.getTransform(), co = Math.cos(a), si = Math.sin(a);
    c.setTransform(m.a * co + m.c * si, m.b * co + m.d * si, m.c * co - m.a * si, m.d * co - m.b * si, m.a * x + m.c * y + m.e, m.b * x + m.d * y + m.f);
    c.drawImage(S.cv, S.x, S.y, S.w, S.h, -hw, -hh, hw * 2, hh * 2); c.setTransform(m.a, m.b, m.c, m.d, m.e, m.f);
  },

  // ---- primitives ----
  circle(x, y, r, fill, stroke, lw) {
    const c = this.ctx;
    c.beginPath(); c.arc(x, y, r, 0, TAU);
    if (fill) { c.fillStyle = fill; c.fill(); }
    if (stroke) { c.strokeStyle = stroke; c.lineWidth = lw || 3; c.stroke(); }
  },

  shine(x, y, r) { // glossy highlight
    const c = this.ctx;
    c.fillStyle = 'rgba(255,255,255,0.45)';
    c.beginPath(); c.ellipse(x - r * 0.3, y - r * 0.35, r * 0.35, r * 0.22, -0.6, 0, TAU); c.fill();
  },

  eyes(x, y, a, r, opts) {
    // two big cartoon eyes looking along angle a
    const c = this.ctx, o = opts || {};
    const px = -Math.sin(a), py = Math.cos(a), fx = Math.cos(a), fy = Math.sin(a);
    const sp = o.spread || r * 0.45, fwd = o.fwd || r * 0.25, er = o.er || r * 0.36;
    for (const s of [-1, 1]) {
      const ex = x + px * s * sp + fx * fwd, ey = y + py * s * sp + fy * fwd;
      c.fillStyle = '#fff'; c.beginPath(); c.arc(ex, ey, er, 0, TAU); c.fill();
      c.strokeStyle = this.OUT; c.lineWidth = 2; c.stroke();
      const pr = er * 0.55;
      c.fillStyle = o.pupil || this.OUT; c.beginPath(); c.arc(ex + fx * er * 0.3, ey + fy * er * 0.3, pr, 0, TAU); c.fill();
      c.fillStyle = '#fff'; c.beginPath(); c.arc(ex + fx * er * 0.3 - pr * 0.35, ey + fy * er * 0.3 - pr * 0.35, pr * 0.35, 0, TAU); c.fill();
    }
  },

  blush(x, y, a, r) {
    const c = this.ctx, px = -Math.sin(a), py = Math.cos(a);
    c.fillStyle = 'rgba(255,120,150,0.45)';
    for (const s of [-1, 1]) { c.beginPath(); c.ellipse(x + px * s * r * 0.75, y + py * s * r * 0.75, r * 0.22, r * 0.14, a, 0, TAU); c.fill(); }
  },

  mouth(x, y, a, r, happy) {
    const c = this.ctx, fx = Math.cos(a), fy = Math.sin(a);
    c.strokeStyle = this.OUT; c.lineWidth = 2; c.lineCap = 'round';
    c.beginPath(); c.arc(x + fx * r * 0.45, y + fy * r * 0.45, r * 0.22, a - 1.1 + (happy ? 0 : Math.PI), a + 1.1 + (happy ? 0 : Math.PI)); c.stroke();
  },

  tint(hex, a) { const n = parseInt(hex.slice(1), 16); return `rgba(${n >> 16},${(n >> 8) & 255},${n & 255},${a})`; },
  // a big square button in the workbench bin's style: translucent, dashed outline (solid and filled in its colour when
  // `on`), a glyph (an Icons name in `icon`, or a character in `glyph`) and up to two label lines inside, an optional
  // `sub` line under it. o = { icon, glyph, label, label2, sub, color, on, hov, dim }. Returns the rect.
  square(cx, cy, s, o) {
    const c = this.ctx, k = s / 90, col = o.color || '#ffd166', ink = o.on ? this.OUT : '#fff', x = cx - s / 2, y = cy - s / 2;
    c.save(); if (o.dim) c.globalAlpha = 0.5;
    c.fillStyle = o.on ? this.tint(col, 0.9) : o.hov ? this.tint(col, 0.3) : 'rgba(255,255,255,0.08)'; this.roundRect(x, y, s, s, 16 * k); c.fill();
    c.strokeStyle = o.on || o.hov ? (o.on ? this.OUT : col) : 'rgba(255,255,255,0.4)'; c.lineWidth = o.on || o.hov ? 3 : 2; c.setLineDash(o.on || o.hov ? [] : [6, 6]); c.stroke(); c.setLineDash([]);
    const gy = cy - s * (o.label ? 0.16 : 0.04);
    const say = o.on ? (t, x, y, sz) => this.ink(t, x, y, sz, this.OUT, 'center') : (t, x, y, sz, cl) => this.text(t, x, y, sz, cl, 'center'); // lit: dark ink with no dark outline (it smears)
    if (o.icon) Icons.draw(o.icon, cx, gy, 22 * k, o.on ? this.OUT : col); else if (o.glyph) say(o.glyph, cx, gy + 2, s * 0.3, col);
    if (o.label) say(o.label, cx, cy + s * (o.label2 ? 0.2 : 0.3), 12.5 * k, ink);
    if (o.label2) say(o.label2, cx, cy + s * 0.2 + 15 * k, 12.5 * k, ink);
    c.restore();
    if (o.sub) this.text(o.sub, cx, cy + s / 2 + 16, 12, 'rgba(255,255,255,0.8)', 'center', 'normal');
    return { x, y, w: s, h: s };
  },
  roundRect(x, y, w, h, r) { const c = this.ctx; c.beginPath(); c.moveTo(x + r, y); c.arcTo(x + w, y, x + w, y + h, r); c.arcTo(x + w, y + h, x, y + h, r); c.arcTo(x, y + h, x, y, r); c.arcTo(x, y, x + w, y, r); c.closePath(); },

  text(s, x, y, size, color, align, weight) {
    const c = this.ctx;
    c.font = `${weight || 'bold'} ${size}px "Trebuchet MS", "Segoe UI", sans-serif`;
    c.textAlign = align || 'left'; c.textBaseline = 'middle';
    c.lineWidth = Math.max(3, size * 0.18); c.strokeStyle = 'rgba(30,18,45,0.85)'; c.lineJoin = 'round';
    c.strokeText(s, x, y); c.fillStyle = color || '#fff'; c.fillText(s, x, y);
  },

  // dark text on a bright badge or button: no outline (text()'s dark stroke would smear the letters)
  ink(s, x, y, size, color, align, maxW) {
    const c = this.ctx; c.font = `bold ${size}px "Trebuchet MS", "Segoe UI", sans-serif`; c.textAlign = align || 'left'; c.textBaseline = 'middle';
    c.fillStyle = color || this.OUT; if (maxW) c.fillText(s, x, y, maxW); else c.fillText(s, x, y);
  },

  // ---- scale on touch screens ----
  ui() { return Input.touch ? Settings.size() : 1; }, // HUD text, panels and buttons (the Size setting, touch screens only)
  // the world camera's zoom: the full mobile scale in the overworld; in a dungeon as much as the maze allows

  zoom() {
    if (!Input.touch) return 1;
    if (G.mode === 'deck') return 1; // the deck is the screen
    if (G.mode === 'dungeon' && Dungeon.cols) return Math.max(1, Math.min(Settings.size(), (CFG.W - 30) / (Dungeon.cols * Dungeon.cell), (CFG.H - 90) / (Dungeon.rows * Dungeon.cell)));
    return Settings.size();
  },

  viewW() { return CFG.W / this.zoom(); }, viewH() { return CFG.H / this.zoom(); }, // how much world the screen shows
  // is a monster (at least partly) on screen? In the overworld your guns only go after what you can see; bosses always count
  // (they hover at a distance on purpose). Elsewhere (the maze, the deck) the whole board is the screen
  aimable(e) { if (G.mode !== 'world' || e.kind === 'boss') return true; const m = e.r || 0; return e.x > G.cam.x - m && e.x < G.cam.x + this.viewW() + m && e.y > G.cam.y - m && e.y < G.cam.y + this.viewH() + m; },

  worldTransform(c) { const Z = this.zoom(), cam = G.cam; c.scale(Z, Z); c.translate(-Math.round(cam.x), -Math.round(cam.y)); },

  // ---- frame ----
  // the vignette: a CRT tube's falloff toward the screen edges, drawn once into a cached canvas (per size and mood) and stamped
  // on every frame. Normal: dark glass at the rim. Nightmare: deeper and blood-red, breathing slowly
  // crtVignette: four edge gradients (they stack in the corners, so those sink deepest) and rounded tube corners in the edge
  // colour. band: how far in the falloff reaches, in half-short-sides (the same depth on every edge). stops: [[offset, color], ...]
  // from the inner end (0) to the screen edge (1)
  crtVignette(c, band, stops) {
    const W = CFG.W, H = CFG.H, b = band * Math.min(W, H) / 2, r = Math.min(W, H) * 0.07;
    const edge = (x0, y0, x1, y1, rx, ry, rw, rh) => { const g = c.createLinearGradient(x0, y0, x1, y1); for (const [o, col] of stops) g.addColorStop(o, col); c.fillStyle = g; c.fillRect(rx, ry, rw, rh); };
    edge(b, 0, 0, 0, 0, 0, b, H); edge(W - b, 0, W, 0, W - b, 0, b, H); edge(0, b, 0, 0, 0, 0, W, b); edge(0, H - b, 0, H, 0, H - b, W, b);
    c.fillStyle = stops[stops.length - 1][1]; c.beginPath(); c.rect(0, 0, W, H); // the tube's rounded corners: everything outside them
    c.moveTo(r, 0); c.arcTo(W, 0, W, H, r); c.arcTo(W, H, 0, H, r); c.arcTo(0, H, 0, 0, r); c.arcTo(0, 0, W, 0, r); c.closePath(); c.fill('evenodd');
  },
  vignette() {
    const nm = !!G.nightmare && G.state !== 'title', key = CFG.W + 'x' + CFG.H + (nm ? 'n' : '');
    if (this.vigKey !== key) {
      const cv = document.createElement('canvas'); cv.width = CFG.W; cv.height = CFG.H;
      this.crtVignette(cv.getContext('2d'), nm ? 0.75 : 0.6, nm ? [[0, 'rgba(0,0,0,0)'], [0.45, 'rgba(70,0,8,0.12)'], [0.8, 'rgba(100,0,10,0.45)'], [1, 'rgba(60,0,6,0.95)']] : [[0, 'rgba(0,0,0,0)'], [0.5, 'rgba(0,0,0,0.07)'], [0.8, 'rgba(0,0,0,0.3)'], [0.94, 'rgba(0,0,0,0.62)'], [1, 'rgba(0,0,0,0.9)']]);
      this.vig = cv; this.vigKey = key;
    }
    const c = this.ctx; c.save(); if (nm) c.globalAlpha = 0.82 + 0.18 * Math.sin(G.time * 1.6); c.drawImage(this.vig, 0, 0); c.restore();
  },
  frame() {
    const c = this.ctx;
    c.save();
    c.clearRect(0, 0, CFG.W, CFG.H);
    if (G.state === 'title') { this.drawTitle(); c.restore(); return; }
    if (G.state === 'paused' || (G.state === 'settings' && Settings.from === 'paused')) { c.fillStyle = '#120a1e'; c.fillRect(0, 0, CFG.W, CFG.H); this.drawHUD(); c.restore(); return; } // the workbench covers the screen: the world is not drawn under it (the circus's spotlights and mirrors cost real frames)
    if (G.cinematic) { this.drawAscend(); c.restore(); return; }
    const cam = G.cam;
    this.worldTransform(c);
    { const m = c.getTransform(); this.camM = { a: m.a, b: m.b, c: m.c, d: m.d, e: m.e, f: m.f }; this.pxScale = Math.round(Math.hypot(m.a, m.b) * 4) / 4 || 1; } // the camera, for stamp(); screen pixels per world unit, so sprites are drawn sharp (quarter steps, so a pinch does not remake them all)
    if (G.mode === 'world') this.drawWorld(); else if (G.mode === 'deck') Deck.draw(); else this.drawDungeon();
    this.drawClouds();
    if (G.mode === 'world') this.drawPickups();
    if (G.mode === 'world') this.drawStompShadow();
    const vw = this.viewW(), vh = this.viewH();
    for (const e of G.enemies) {
      if (e.dead) continue;
      if (G.mode === 'world') { // off screen: not drawn. The margin is how far it can reach onto the screen: a bug's legs and label, a snake's body, an iguana's tongue; a boss can be long, so 700
        const m = e.kind === 'bug' ? (BUGS[e.type].tongue || e.r * 3 + 40) : e.kind === 'parasite' ? 70 : e.kind === 'snake' ? 50 + (e.segPos ? e.segPos.length : 12) * 14 : 700;
        if (e.x < cam.x - m || e.x > cam.x + vw + m || e.y < cam.y - m || e.y > cam.y + vh + m) continue;
      }
      if (e.hidden) { if (e.kind === 'bug') this.drawMound(e); continue; }
      if (e.rare || e.unique) this.drawHalo(e);
      if (e.charmed && e.ally > 0 && e.kind !== 'parasite') this.drawCharm(e);
      if (e.mimeWall) this.drawMimeWall(e);
      if (e.kind === 'snake') this.drawSnake(e); else if (e.kind === 'bug') { if (e.type === 'skull') this.drawSkullBug(e); else this.drawBug(e); } else if (e.kind === 'parasite') this.drawParasite(e); else if (e.kind === 'boss') { if (BOSSES[e.type].foot) this.drawSkullBoss(e); else if (BOSSES[e.type].serpent) this.drawSerpent(e); else if (BOSSES[e.type].alien) this.drawOverlord(e); else if (BOSSES[e.type].car) this.drawClownCar(e); else this.drawCrab(e); } else if (e.kind === 'stone') this.drawStone(e); else if (e.kind === 'rock') this.drawRock(e); else this.drawGhost(e);
      if (e.label) this.text(e.label, e.x, e.y - e.r - 24, 12, e.rare ? e.rare.color : e.color, 'center');
    }
    if (G.mode === 'world') this.drawMirrors();
    Bot.draw();
    if (Player.alive) { this.drawPlayer(); this.drawLichSkulls(); this.drawGiantGauge(); }
    this.drawProjectiles();
    this.drawArcs();
    this.drawParticles();
    if (G.mode === 'world') { this.drawWraiths(); this.drawFog(); this.drawStompFoot(); }
    this.drawFloaters();
    this.camM = null; c.restore();
    // the play field is done: the nightmare dusk and the vignette go on it now, and every piece of text comes after, undimmed
    if (G.nightmare && G.mode === 'world') { c.fillStyle = CFG.NIGHTMARE.tint; c.fillRect(0, 0, CFG.W, CFG.H); } // nightmare: a dusk over the field
    this.vignette(); // every mode: the board darkens toward its edges (blood-red, breathing, in nightmare)
    if (G.mode === 'deck' || G.mode === 'dungeon') { c.save(); this.worldTransform(c); if (G.mode === 'deck') Deck.drawLabels(); else this.drawDungeonLabels(); c.restore(); }
    if (G.mode === 'world') { // creeping vignette: the deeper you go, the more the dark closes in
      const RR = World.regions[World.regionAt(Player.x)], dr = World.dread(RR.idx), haunted = RR.haunted, vig = haunted ? '14,14,22' : RR.farm ? '8,40,18' : RR.circus ? '40,0,60' : '10,0,20';
      if (dr > 0) this.crtVignette(c, 0.8, [[0, `rgba(${vig},0)`], [1, `rgba(${vig},${0.15 + dr * (haunted ? 0.45 : RR.circus ? 0.35 : 0.6)})`]]);
      if (RR.circus) { for (let k = 0; k < 3; k++) { const a = G.time * (0.5 + k * 0.17) + k * 2.1, x = CFG.W / 2 + Math.cos(a) * CFG.W * 0.42, y = CFG.H / 2 + Math.sin(a * 1.3) * CFG.H * 0.4; const sp = c.createRadialGradient(x, y, 0, x, y, 220); sp.addColorStop(0, 'rgba(255,255,255,0.13)'); sp.addColorStop(1, 'rgba(255,255,255,0)'); c.fillStyle = sp; c.fillRect(0, 0, CFG.W, CFG.H); } } // sweeping spotlights
      if (Player.shroom > 0) { c.save(); c.globalCompositeOperation = 'difference'; c.fillStyle = `rgba(255,255,255,${0.85 + 0.15 * Math.sin(G.time * 3)})`; c.fillRect(0, 0, CFG.W, CFG.H); c.restore(); } // the mushroom: everything inside out
      if (Player.mirror > 0) this.text('THE GIGGLES: controls are backwards', CFG.W / 2, 100, 22, '#f3c6ff', 'center');
      for (const s of G.splats) { // cream pies on the lens
        const k = Math.min(1, s.t / 0.3), x = s.x * CFG.W, y = s.y * CFG.H + (2.6 - s.t) * 18; c.save(); c.globalAlpha = 0.92 * k; c.fillStyle = '#fff5e0'; c.strokeStyle = 'rgba(200,180,140,0.6)'; c.lineWidth = 3;
        c.beginPath(); for (let i = 0; i < 14; i++) { const a = i * TAU / 14, rr = 70 + Math.sin(s.seed * 10 + i * 2.3) * 22 + (i % 2) * 14; c.lineTo(x + Math.cos(a) * rr, y + Math.sin(a) * rr * 1.1); } c.closePath(); c.fill(); c.stroke();
        for (let i = 0; i < 4; i++) { const dx = Math.sin(s.seed + i * 1.7) * 40, dy = 50 + i * 18 + (2.6 - s.t) * 30; c.beginPath(); c.ellipse(x + dx, y + dy, 9, 16, 0, 0, TAU); c.fill(); } // drips
        c.fillStyle = '#ff5c5c'; c.beginPath(); c.arc(x + Math.sin(s.seed) * 20, y - 10, 9, 0, TAU); c.fill(); c.restore(); // a cherry
      }
      const th = Enemies.threat; // a parasite is closing in: the edges pulse purple-red
      if (th > 0.05 && G.state === 'playing') { const a = th * 0.45 * (0.55 + 0.45 * Math.sin(G.time * (6 + th * 14))); this.crtVignette(c, 0.6, [[0, 'rgba(120,0,80,0)'], [1, `rgba(120,0,80,${a})`]]); }
    }
    // mega effects: colour tint, shock ring, white flash
    if (G.tint) { c.fillStyle = `rgba(${G.tint.color},${0.18 * Math.min(1, G.tint.t)})`; c.fillRect(0, 0, CFG.W, CFG.H); }
    if (G.ring) { c.save(); this.worldTransform(c); c.globalAlpha = Math.max(0, G.ring.t / 0.6); c.strokeStyle = G.ring.color || '#fff'; c.lineWidth = 10; c.beginPath(); c.arc(G.ring.x, G.ring.y, G.ring.r, 0, TAU); c.stroke(); c.restore(); }
    if (G.freeze > 0 && G.pendingBlast) { const b = G.pendingBlast; c.save(); this.worldTransform(c); const k = 1 - G.freeze / 1.1; c.strokeStyle = `rgba(255,90,90,${0.5 + 0.5 * Math.sin(G.time * 40)})`; c.lineWidth = 4; c.beginPath(); c.arc(b.x, b.y, 20 + k * 60, 0, TAU); c.stroke(); c.restore(); this.text('SACRIFICE', CFG.W / 2, CFG.H / 2 - 40, 64, '#ff5c5c', 'center'); this.text(`your ${b.name} goes off`, CFG.W / 2, CFG.H / 2 + 8, 20, '#ffd6d6', 'center'); }
    if (G.flash > 0) { c.fillStyle = `rgba(255,255,255,${Math.min(1, G.flash)})`; c.fillRect(0, 0, CFG.W, CFG.H); }
    const tY = G.state === 'playing' && this.shownBoss() ? 128 + this.ui() * 44 + 12 : 100; // under the boss bar while one is up
    if (G.storm > 0) this.text(`BULLET STORM ${G.storm.toFixed(1)}`, CFG.W / 2, tY, 30, '#ffd166', 'center');
    else if (G.bolt) this.text(`LIGHTNING STORM ${Math.max(0, G.bolt.t).toFixed(1)}`, CFG.W / 2, tY, 30, '#7df9ff', 'center');
    else if (G.lich) this.text(`WILL OF THE LICH ${Math.max(0, G.lich.t).toFixed(1)}`, CFG.W / 2, tY, 30, '#ff8a3d', 'center');
    if (G.deepFreeze > 0) this.text(`DEEP FREEZE ${G.deepFreeze.toFixed(1)}  ·  ICE ×${CFG.DEEP_FREEZE.mult}`, CFG.W / 2, tY, 30, '#cfeeff', 'center');
    if (G.slowmo > 0) { c.fillStyle = `rgba(155,240,106,${0.12 + 0.08 * Math.sin(G.time * 40)})`; c.fillRect(0, 0, CFG.W, CFG.H); for (let k = 0; k < 6; k++) { c.fillStyle = 'rgba(155,240,106,0.08)'; c.fillRect(0, ((G.time * 400 + k * 140) % (CFG.H + 40)) - 20, CFG.W, 6); } } // the abduction: green scanlines crawl while time stands still
    this.drawHUD();
  },

  legs(x, y, a, r, i) {
    const c = this.ctx, px = -Math.sin(a), py = Math.cos(a), fx = Math.cos(a), fy = Math.sin(a);
    const wig = Math.sin(G.time * 16 + i * 1.1) * 0.6;
    c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.lineCap = 'round';
    for (const s of [-1, 1]) {
      const lx = x + px * s * r * 0.6, ly = y + py * s * r * 0.6;
      const ex = x + px * s * (r + 7) + fx * wig * 5 * s, ey = y + py * s * (r + 7) + fy * wig * 5 * s;
      c.beginPath(); c.moveTo(lx, ly); c.lineTo(ex, ey); c.stroke();
      c.fillStyle = '#ffb86b'; c.beginPath(); c.arc(ex, ey, 2.2, 0, TAU); c.fill();
    }
  },
};
