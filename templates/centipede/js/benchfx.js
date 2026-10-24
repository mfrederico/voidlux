// The workbench's animation layer: purely cosmetic. The model (Player.craft, the bag, the body) changes instantly as
// before; this plays the choreography on top and hides the landed tile until its token arrives, so nothing in play
// waits on an animation. Any key or tap while a token is in flight finishes it on the spot (Build calls finish()).
//
// A fusion: the two ingredient ghosts fly to the card and collide (flash, ring, particles, the fuse chord); the result
// grows out of the collision, holds a beat, then arcs to its slot. On the forge panel the body strip grows to receive
// it and the bag steps back. On landing the slot pulses, the tile squashes in, the dps counts up and the gallery fires.
const BenchFx = {
  tokens: [], parts: [], rings: [], floats: [],
  pending: new Set(), // 'body:3' / 'bag:laser' keys: slots drawn as an incoming outline until the token lands
  land: {},           // key -> seconds left of the landing squash
  pulse: {},          // key -> seconds left of the slot pulse
  badge: {},          // element -> seconds left of its badge pulse (a new element on the body)
  focus: 0, focusTo: 0, // 0..1: on the forge the body strip grows and the bag steps back while a result flies to the body
  dps: null,          // { from, to, t, dur }: the readout counting from the old number to the new
  GATHER: 0.26, BURST: 0.22, HOLD: 0.1, FLY: 0.34, BEAM_GATHER: 0.5, // (the LAB's beams take longer: you watch the pieces get pulled in)
  reset() { this.tokens = []; this.parts = []; this.rings = []; this.floats = []; this.pending.clear(); this.land = {}; this.pulse = {}; this.badge = {}; this.focus = this.focusTo = 0; this.dps = null; },
  busy() { return this.tokens.length > 0; },
  key(L) { return L.where + ':' + (L.where === 'body' ? L.i : L.id); },
  isPending(where, k) { return this.pending.has(where + ':' + k); },
  // the landing squash of a tile: { sx, sy } scale factors (1,1 when idle)
  landScale(where, k) { const t = this.land[where + ':' + k]; if (!t) return null; const p = 1 - t / 0.3, w = Math.sin(p * Math.PI) * (1 - p); return { sx: 1 + w * 0.35, sy: 1 - w * 0.3 }; },
  slotPulse(where, k) { const t = this.pulse[where + ':' + k]; return t ? t / 0.7 : 0; },
  badgePulse(e) { const t = this.badge[e]; return t ? t / 0.8 : 0; },
  // the screen rect of a body slot or a bag stack, from the hit rects the last draw recorded (null when not on screen)
  rectOf(L) {
    const H = Build.hit; if (!L) return null;
    if (L.where === 'body') { const r = H.body.find(t => t.i === L.i); return r ? { x: r.x, y: r.y, w: r.w, h: r.h, cx: r.x + r.w / 2, cy: r.y + r.h / 2 - (r.name ? 15 : 0), size: r.tile || 60 } : null; }
    const id = L.id != null ? L.id : Player.bag[L.i], gi = Player.bagGroups().findIndex(g => g.id === id);
    const r = H.bag.find(t => t.i === gi); if (r) return { x: r.x, y: r.y, w: r.w, h: r.h, cx: r.x + r.w / 2, cy: r.y + r.h / 2 - 12, size: r.tile || 60 };
    const k = H.bagPocket; return k ? { x: k.x, y: k.y, w: k.w, h: k.h, cx: k.x + k.w / 2, cy: k.y + k.h / 2, size: Math.min(k.w, k.h) * 0.8 } : null; // a page without the bag (the LAB): its pocket
  },
  // a fusion. o = { a, b, r, srcA, srcB (rects or null), at {x,y} (the collision point), dest {where,i|id}, dpsBefore, dpsAfter }
  fuse(o) {
    const tk = { kind: 'fuse', ...o, stage: 'gather', t: 0, trail: [], pos: { x: o.at.x, y: o.at.y }, scale: 0, key: this.key(o.dest) };
    if (!o.srcA && !o.srcB) tk.stage = 'burst'; // nothing to gather from on this panel: straight to the flash
    this.pending.add(tk.key); this.tokens.push(tk);
    if (tk.stage === 'burst') this.burst(tk);
  },
  // a plain move: a tile flying from a rect to a slot (placing from the bag, stashing to the bag)
  move(o) { const tk = { kind: 'move', id: o.id, src: o.src, dest: o.dest, stage: 'fly', t: 0, trail: [], pos: { x: o.src.cx, y: o.src.cy }, at: { x: o.src.cx, y: o.src.cy }, key: this.key(o.dest), dur: 0.26 }; this.pending.add(tk.key); this.tokens.push(tk); },
  burst(tk) {
    const col = BULLETS[tk.r].color;
    SFX.play(BULLETS[tk.r].tier >= 4 ? 'fuse4' : 'fuse');
    for (let i = 0; i < 26; i++) { const a = Math.random() * TAU, sp = U.rand(120, 380); this.parts.push({ x: tk.at.x, y: tk.at.y, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp - 60, t: U.rand(0.4, 0.8), r: U.rand(2.5, 6), color: i % 3 ? col : '#fff' }); }
    this.rings.push({ x: tk.at.x, y: tk.at.y, t: 0.5, dur: 0.5, color: col, r0: 24, r1: 150, lw: 8 });
    this.rings.push({ x: tk.at.x, y: tk.at.y, t: 0.35, dur: 0.35, color: '#fff', r0: 10, r1: 90, lw: 4 });
    tk.flash = 0.25;
  },
  finish() { for (const tk of this.tokens) this.landed(tk); this.tokens = []; this.focusTo = 0; },
  landed(tk) {
    this.pending.delete(tk.key); this.land[tk.key] = 0.3; this.pulse[tk.key] = 0.7;
    const r = this.rectOf(tk.dest); if (r) { for (let i = 0; i < 10; i++) { const a = Math.random() * TAU, sp = U.rand(60, 180); this.parts.push({ x: r.cx, y: r.cy, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp, t: U.rand(0.25, 0.5), r: U.rand(2, 4), color: BULLETS[tk.r || tk.id].color }); } }
    SFX.play('land');
    if (tk.kind === 'fuse') {
      const d = tk.dpsAfter - tk.dpsBefore;
      this.dps = { from: tk.dpsBefore, to: tk.dpsAfter, t: 0, dur: 0.7 };
      if (Math.abs(d) >= 0.05) this.floats.push({ x: r ? r.cx : CFG.W / 2, y: r ? r.y - 10 : CFG.H / 2, text: `${d > 0 ? '+' : ''}${d.toFixed(1)} dps`, color: d > 0 ? '#9bf06a' : '#ff9b8b', t: 1.3, dur: 1.3, size: 22 });
      for (const e of (BULLETS[tk.r].elems || [])) this.badge[e] = 0.8;
      if (typeof Gallery !== 'undefined') Gallery.volley();
    }
    this.focusTo = 0;
  },
  dpsShown(live) { const d = this.dps; if (!d) return live; const k = 1 - Math.pow(1 - Math.min(1, d.t / d.dur), 3); return d.from + (d.to - d.from) * k; },
  // CRAFT: a quick fade-burst of light from where the fusion happens (a white bloom over the bench that fades in a blink)
  light(x, y, color) { this.glow = { x, y, color, t: 0.45, dur: 0.45 }; },
  update(dt) {
    if (this.glow) { this.glow.t -= dt; if (this.glow.t <= 0) this.glow = null; }
    for (const tk of this.tokens.slice()) {
      tk.t += dt;
      if (tk.stage === 'gather') { if (tk.t >= (tk.beams ? this.BEAM_GATHER : this.GATHER)) { tk.stage = 'burst'; tk.t = 0; this.burst(tk); } continue; }
      if (tk.stage === 'burst') { if (tk.flash > 0) tk.flash -= dt; if (tk.t >= this.BURST) { tk.stage = 'hold'; tk.t = 0; } continue; }
      if (tk.stage === 'hold') { if (tk.t >= this.HOLD) { tk.stage = 'fly'; tk.t = 0; if (tk.dest.where === 'body' && Build.panel === 'forge') this.focusTo = 1; } continue; }
      if (tk.stage === 'fly') {
        const dur = tk.dur || this.FLY, k = Math.min(1, tk.t / dur), e = k < 0.5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2, to = this.rectOf(tk.dest) || { cx: tk.at.x, cy: tk.at.y, size: 60 };
        const mx = (tk.at.x + to.cx) / 2, my = Math.min(tk.at.y, to.cy) - 90; // the arc's control point: above both ends
        tk.pos.x = (1 - e) * (1 - e) * tk.at.x + 2 * (1 - e) * e * mx + e * e * to.cx; tk.pos.y = (1 - e) * (1 - e) * tk.at.y + 2 * (1 - e) * e * my + e * e * to.cy;
        tk.scale = 64 + (to.size - 64) * e;
        for (const q of tk.trail) q.t -= dt; tk.trail.push({ x: tk.pos.x, y: tk.pos.y, t: 0.25 }); if (tk.trail.length > 14) tk.trail.shift();
        if (k >= 1) { this.landed(tk); this.tokens.splice(this.tokens.indexOf(tk), 1); }
      }
    }
    this.focus += (this.focusTo - this.focus) * Math.min(1, dt * 9);
    for (const p of this.parts) { p.x += p.vx * dt; p.y += p.vy * dt; p.vy += 300 * dt; p.vx *= 0.96; p.t -= dt; } this.parts = this.parts.filter(p => p.t > 0);
    for (const r of this.rings) r.t -= dt; this.rings = this.rings.filter(r => r.t > 0);
    for (const f of this.floats) { f.t -= dt; f.y -= 34 * dt; } this.floats = this.floats.filter(f => f.t > 0);
    for (const k in this.land) { this.land[k] -= dt; if (this.land[k] <= 0) delete this.land[k]; }
    for (const k in this.pulse) { this.pulse[k] -= dt; if (this.pulse[k] <= 0) delete this.pulse[k]; }
    for (const k in this.badge) { this.badge[k] -= dt; if (this.badge[k] <= 0) delete this.badge[k]; }
    if (this.dps) { this.dps.t += dt; if (this.dps.t >= this.dps.dur + 0.4) this.dps = null; }
  },
  // drawn last, over the whole workbench
  draw() {
    const c = Render.ctx, R = Render;
    if (this.glow) { const g = this.glow, k = g.t / g.dur, rad = 80 + (1 - k) * Math.max(CFG.W, CFG.H) * 0.9, gr = c.createRadialGradient(g.x, g.y, 0, g.x, g.y, rad); gr.addColorStop(0, `rgba(255,255,255,${0.95 * k})`); gr.addColorStop(0.25, R.tint(g.color, 0.55 * k)); gr.addColorStop(1, 'rgba(255,255,255,0)'); c.save(); c.globalCompositeOperation = 'lighter'; c.fillStyle = gr; c.fillRect(0, 0, CFG.W, CFG.H); c.restore(); }
    const tile = (id, x, y, size, alpha, rot) => { const W = BULLETS[id]; c.save(); c.globalAlpha = alpha; c.translate(x, y); if (rot) c.rotate(rot); c.fillStyle = W.color; R.roundRect(-size / 2, -size / 2, size, size, size * 0.18); c.fill(); c.strokeStyle = W.craft ? '#fff' : R.OUT; c.lineWidth = Math.max(2, size * 0.045); c.stroke(); Icons.tile(W, 0, 0, size, c); c.restore(); };
    for (const r of this.rings) { const k = 1 - r.t / r.dur; c.save(); c.globalAlpha = 1 - k; c.strokeStyle = r.color; c.lineWidth = r.lw * (1 - k) + 1; c.beginPath(); c.arc(r.x, r.y, r.r0 + (r.r1 - r.r0) * (1 - Math.pow(1 - k, 2)), 0, TAU); c.stroke(); c.restore(); }
    for (const tk of this.tokens) {
      if (tk.stage === 'gather') {
        const k = Math.min(1, tk.t / (tk.beams ? this.BEAM_GATHER : this.GATHER)), e = k * k;
        if (tk.beams) for (const [src, id] of [[tk.srcA, tk.a], [tk.srcB, tk.b]]) if (src) R.crackle({ cx: src.cx, cy: src.cy, size: src.size }, { cx: tk.at.x, cy: tk.at.y, size: 0 }, BULLETS[id].color, '#ffffff'); // the LAB: each ingredient rides a lightning beam into the core
        for (const [src, id] of [[tk.srcA, tk.a], [tk.srcB, tk.b]]) if (src) tile(id, src.cx + (tk.at.x - src.cx) * e, src.cy + (tk.at.y - src.cy) * e, src.size + (34 - src.size) * e, 0.95, (1 - k) * 0.3 * (id === tk.a ? 1 : -1));
      } else if (tk.stage === 'burst' || tk.stage === 'hold') {
        if (tk.flash > 0) { c.save(); c.globalAlpha = tk.flash / 0.25 * 0.9; c.fillStyle = '#fff'; c.beginPath(); c.arc(tk.at.x, tk.at.y, 40 + (1 - tk.flash / 0.25) * 60, 0, TAU); c.fill(); c.restore(); }
        const k = tk.stage === 'hold' ? 1 : Math.min(1, tk.t / this.BURST), s = k < 0.7 ? 1.25 * (k / 0.7) : 1.25 - 0.25 * ((k - 0.7) / 0.3); // grows past full size, then settles
        tile(tk.r, tk.at.x, tk.at.y, 64 * s, 1, Math.sin(G.time * 18) * 0.06 * (1 - k));
      } else if (tk.stage === 'fly') {
        for (const q of tk.trail) { if (q.t <= 0) continue; c.save(); c.globalAlpha = q.t / 0.25 * 0.5; c.fillStyle = BULLETS[tk.r || tk.id].color; c.beginPath(); c.arc(q.x, q.y, 6 * q.t / 0.25 + 2, 0, TAU); c.fill(); c.restore(); }
        tile(tk.r || tk.id, tk.pos.x, tk.pos.y, tk.scale || 64, 1, 0);
      }
    }
    for (const p of this.parts) { c.save(); c.globalAlpha = Math.min(1, p.t / 0.3); c.fillStyle = p.color; c.beginPath(); c.arc(p.x, p.y, p.r, 0, TAU); c.fill(); c.restore(); }
    for (const f of this.floats) { c.save(); c.globalAlpha = Math.min(1, f.t / 0.35); R.text(f.text, f.x, f.y, f.size || 20, f.color, 'center'); c.restore(); }
  },
};
