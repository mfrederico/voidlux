// The shooting gallery on the workbench: your current body fires at a training dummy in a lane, with real-looking
// tracers, damage floaters, burns, freezes, poison and sparks ticking on the dummy, and a measured dps readout
// (damage landed over the last few seconds). It is a lightweight simulation of the body, not the live Combat system:
// every weapon segment fires on its own clock (cooldown + flight time, like Player.estimateDps), bursts, twins and
// cascades put more tracers in the lane, and elements apply the same status maths Combat uses. Change the body and the
// lane changes with it, so a swap can be judged by watching.
const Gallery = {
  shots: [], floaters: [], log: [], t: 0, kos: 0, clock: new Map(), dummy: null, lane: null, WINDOW: 4,
  reset() { this.shots = []; this.floaters = []; this.log = []; this.t = 0; this.kos = 0; this.clock = new Map(); this.dummy = this.mkDummy(); },
  volley() { for (const c of this.clock.values()) c.t = 0; }, // every segment fires at once (a fusion just landed: show the change)
  mkDummy() { return { hp: 1, max: 1, st: {}, flash: 0, r: 22, armor: 0.6, seed: Math.random() * 10 }; },
  // how many bullets this body can have in the air at once (segments, bursts, twins, cascades)
  airborne() { let n = 0; for (const s of Player.segments) { const B = BULLETS[s.id]; if (B.minion || B.kind === 'trail') continue; n += (B.maxInflight || 1) * (B.burst || 1) * (1 + (G.mods.twin || 0)); } return n; },
  // measured dps: damage landed in the trailing window
  // seg: only what that body segment landed (its hits and the burns, poison and sparks it lit), for the workbench's per-segment readout
  dps(seg) { const cut = this.t - this.WINDOW; let sum = 0; for (const e of this.log) if (e.t >= cut && (!seg || e.seg === seg)) sum += e.d; return sum / Math.min(this.WINDOW, Math.max(1, this.t)); },
  elements() { const set = {}; for (const s of Player.segments) for (const e of (BULLETS[s.id].elems || [])) set[e] = (set[e] || 0) + 1; return set; },
  // the build readout as a stat strip: measured dps, bullets in the air, element coverage, dummies knocked out
  items() {
    const els = this.elements(), items = [{ icon: 'dmg', color: '#ff7b6b', text: `${this.dps().toFixed(0)}` }, { icon: 'burst', color: '#ffd166', text: `${this.airborne()}` }] // (icons say what: damage per second, bullets in the air);
    for (const e of Object.keys(els)) items.push({ icon: e, color: ELEM_COLORS[e], text: els[e] > 1 ? `×${els[e]}` : '' });
    if (this.kos) items.push({ icon: 'skull', color: '#eae4d4', text: `${this.kos}` });
    return items;
  },
  land(d, color, text, seg) { const D = this.dummy; D.hp -= d; D.flash = 0.08; this.log.push({ t: this.t, d, seg }); this.floaters.push({ x: this.lane.dx - D.r - 80 + U.rand(-14, 14), y: this.lane.y + U.rand(-10, 10), t: 0.7, text: text || String(Math.round(d)), color }); if (D.hp <= 0) { this.kos++; D.hp = D.max; D.st = {}; this.floaters.push({ x: this.lane.dx, y: this.lane.y - D.r - 6, t: 1.0, text: 'KO!', color: '#ffd166' }); } },
  update(dt, lane) {
    const P = Player; this.lane = lane; this.t += dt;
    if (!this.dummy) this.dummy = this.mkDummy();
    const D = this.dummy; D.flash -= dt;
    D.max = Math.max(60, Math.round(P.estimateDps(D.armor, 1) * 6)); if (D.hp > D.max) D.hp = D.max; // the dummy takes about six seconds of your estimated output
    // every weapon segment on its own clock
    for (const s of P.segments) {
      const B = BULLETS[s.id]; if (B.minion || s.jam) continue;
      let c = this.clock.get(s); if (!c) { c = { t: U.rand(0, 0.5) }; this.clock.set(s, c); }
      c.t -= dt; if (c.t > 0) continue;
      const flight = B.kind === 'trail' ? 0 : B.life ? B.life * G.mods.range : (B.range * G.mods.range) / (B.speed * G.mods.bspeed);
      c.t = B.cd * G.mods.cd + (B.kind === 'orbit' || B.kind === 'pulse' ? flight : flight * 0.9);
      const dmg = B.dmg * G.powerMult * Combat.ramp(B) * (P.shroom > 0 ? CFG.SHROOM.dmg : 1), base = dmg / (G.powerMult || 1); /* statuses: Brawn stays out of them, as in Combat.fire */
      if (B.kind === 'trail') { this.land(dmg * B.life * 0.5, B.color, `${Math.round(dmg * B.life * 0.5)} trail`, s); this.applyElems(B, base, s); continue; } // a walker's patch, averaged
      if (B.kind === 'orbit' || B.kind === 'pulse') { this.shots.push({ x: lane.dx - D.r - 30, y: lane.y, vx: 300, size: B.size, color: B.color, dmg, base, B, seg: s, extra: false }); continue; }
      const n = (B.maxInflight || 1) * (B.burst || 1), twins = G.mods.twin || 0, speed = Math.min(1100, B.speed * G.mods.bspeed) * ((lane.dx - D.r - lane.x0) / 420); // the lane stands for 420px of range, whatever its pixel length
      for (let k = 0; k < n; k++) for (let w = 0; w <= twins; w++) this.shots.push({ x: lane.x0 + U.rand(-6, 6) - k * 14, y: lane.y + (w ? (w % 2 ? 1 : -1) * Math.ceil(w / 2) * 9 : 0) + U.rand(-3, 3), vx: speed, size: B.size, color: B.color, dmg: dmg * (w ? Perks.twinMult(twins) : 1), base, /* a twin copy hits softer, statuses at full */ B, extra: w > 0, seg: s, spin: U.rand(0, TAU) });
    }
    // tracers fly, hit, apply
    for (let i = this.shots.length - 1; i >= 0; i--) {
      const q = this.shots[i]; q.x += q.vx * dt; q.spin = (q.spin || 0) + 10 * dt;
      if (q.x >= lane.dx - D.r) {
        const B = q.B, plain = !(B.elems && B.elems.length) && !B.combo; let d = q.dmg;
        if (D.st.freeze) d *= 1.25; if (plain) d *= D.armor; if (B.combo === 'plasma') d *= 1.5; if (B.combo === 'shatter' && D.st.freeze) d *= 3;
        if (B.combo === 'gas' || B.combo === 'bomb') d *= 2.5;
        this.land(d, q.color, undefined, q.seg); this.applyElems(B, q.base, q.seg);
        this.shots.splice(i, 1);
      }
    }
    // statuses tick on the dummy
    for (const k in D.st) { const s = D.st[k]; s.t -= dt; if (s.t <= 0) { delete D.st[k]; continue; }
      if (k === 'burn' || k === 'poison') { D.hp -= s.mag * dt; s.acc = (s.acc || 0) + s.mag * dt; if (s.acc >= 4) { this.log.push({ t: this.t, d: s.acc, seg: s.seg }); this.floaters.push({ x: lane.dx - D.r - 80 + U.rand(-14, 14), y: lane.y + U.rand(-10, 10), t: 0.6, text: `${Math.round(s.acc)} ${k}`, color: k === 'burn' ? '#ff9a3d' : '#9bf06a' }); s.acc = 0; } }
      else if (k === 'spark') { s.tick -= dt; if (s.tick <= 0) { s.tick = 0.45; D.hp -= s.mag; this.log.push({ t: this.t, d: s.mag, seg: s.seg }); this.floaters.push({ x: lane.dx - D.r - 80 + U.rand(-14, 14), y: lane.y + U.rand(-10, 10), t: 0.5, text: `${Math.round(s.mag)} zap`, color: '#7df9ff' }); } }
      if (D.hp <= 0) { this.kos++; D.hp = D.max; D.st = {}; this.floaters.push({ x: lane.dx, y: lane.y - D.r - 6, t: 1.0, text: 'KO!', color: '#ffd166' }); break; } // (break: the statuses just went with the old dummy)
    }
    for (let i = this.floaters.length - 1; i >= 0; i--) { const f = this.floaters[i]; f.t -= dt; f.y -= 28 * dt; if (f.t <= 0) this.floaters.splice(i, 1); }
    const cut = this.t - this.WINDOW - 1; while (this.log.length && this.log[0].t < cut) this.log.shift();
    for (const [s] of this.clock) if (!P.segments.includes(s)) this.clock.delete(s); // segments that left the body stop firing
  },
  applyElems(B, base, seg) { // (seg: who lit it; a status re-lit by another segment ticks for the last one)
    const D = this.dummy, EM = G.mods.elem, add = (k, t, mag, stack) => { const s = D.st[k]; if (s) { s.t = Math.max(s.t, t); s.mag = stack ? Math.min(s.mag + mag * 0.6, mag * 4) : Math.max(s.mag, mag); s.seg = seg; } else D.st[k] = { t, mag, tick: 0, seg }; };
    for (const e of (B.elems || [])) {
      if (e === 'fire') add('burn', 3, base * 0.55 * EM * (B.combo === 'napalm' ? 3 : 1), true);
      if (e === 'poison' && B.combo !== 'gas') add('poison', 4.5, base * 0.6 * EM, false);
      if (e === 'ice') add('freeze', (1.0 + 0.35) * EM, 0);
      if (e === 'electric') add('spark', 2.2, base * 0.35 * EM, false);
    }
    if (B.combo === 'gas' || B.combo === 'bomb') add('poison', 2, base * 0.6 * EM, false);
    if (B.combo === 'berserk' || B.combo === 'madmoth') add('berserk', 4, 0);
    if (B.combo === 'steam') add('blind', 0.6, 0);
  },
  // draw the lane into a panel at (x, y, w, h)
  draw(x, y, w, h) {
    const c = Render.ctx, R = Render, P = Player, D = this.dummy || this.mkDummy();
    const hs = Math.min(9, h * 0.11), n = Math.min(P.segments.length, 6), head = x + 24 + (n + 1) * hs * 1.7; // the shooter sits inside the panel's left edge
    D.r = Math.min(22, (h - 40) * 0.36);
    const lane = { x0: head + hs + 8, dx: x + w - 60, y: y + 40 + (h - 40) * 0.5 }; this.lane = lane; // the lane sits in the middle of what is left under the header
    c.fillStyle = 'rgba(255,255,255,0.06)'; R.roundRect(x, y, w, h, 14); c.fill(); c.strokeStyle = 'rgba(255,255,255,0.18)'; c.lineWidth = 1.5; c.stroke();
    // header: measured dps, bullets in the air, element coverage, KOs (god mode also shows the boss-calc estimate)
    R.text('GALLERY', x + 18, y + 22, 28, '#ffd166', 'left');
    Icons.strip(this.items(), x + 170, y + 22, 15, 'left');
    if (G.god) R.text(`est ${P.estimateDps(D.armor, 1).toFixed(0)} (boss calc)`, x + w - 120, y + 22, 16, 'rgba(255,255,255,0.6)', 'right', 'normal');
    // the lane
    c.strokeStyle = 'rgba(255,255,255,0.12)'; c.lineWidth = 1; c.setLineDash([4, 8]); c.beginPath(); c.moveTo(lane.x0, lane.y); c.lineTo(lane.dx - D.r - 6, lane.y); c.stroke(); c.setLineDash([]);
    // the shooter: your head and the first few segments
    for (let i = n - 1; i >= 0; i--) { const s = P.segments[i]; R.circle(head - (i + 1) * hs * 1.7, lane.y, hs * 0.8, s.jam ? '#5a3b8f' : P.segColor(s), R.OUT, 1.5); }
    R.circle(head, lane.y, hs, '#ffb86b', R.OUT, 2); R.eyes(head, lane.y, 0, hs, { spread: hs * 0.5, fwd: hs * 0.2, er: hs * 0.36 });
    // tracers
    for (const q of this.shots) {
      const B = q.B;
      if (B.laser) { c.globalAlpha = q.extra ? 0.5 : 0.8; c.strokeStyle = q.color; c.lineWidth = B.beam ? 6 : 3; c.lineCap = 'round'; c.beginPath(); c.moveTo(q.x - 14, q.y); c.lineTo(q.x, q.y); c.stroke(); c.globalAlpha = 1; continue; }
      if (q.extra) c.globalAlpha = 0.6;
      if (B.elems && B.elems.length) { c.fillStyle = q.color + '55'; c.beginPath(); c.arc(q.x, q.y, q.size * 0.6 + 4, 0, TAU); c.fill(); }
      R.circle(q.x, q.y, Math.max(2, q.size * 0.6), q.color, R.OUT, 1.2);
      c.globalAlpha = 1;
    }
    // the dummy: a sandbag crab with X eyes, hp bar, statuses
    const dx = lane.dx, dy = lane.y, r = D.r;
    c.fillStyle = 'rgba(0,0,0,0.25)'; c.beginPath(); c.ellipse(dx + 3, dy + r * 0.6, r * 1.1, r * 0.4, 0, 0, TAU); c.fill();
    c.strokeStyle = R.OUT; c.lineWidth = 4; for (const s of [-1, 1]) for (let i = 0; i < 3; i++) { const a = s * (0.6 + i * 0.5); c.beginPath(); c.moveTo(dx + Math.cos(a) * r * 0.8 * -1, dy + Math.sin(a) * r * 0.8); c.lineTo(dx - Math.cos(a) * r * 1.5, dy + Math.sin(a) * r * 1.4); c.stroke(); }
    R.circle(dx, dy, r, D.flash > 0 ? '#fff' : '#c9a07a', R.OUT, 3);
    c.strokeStyle = '#8a6a4a'; c.lineWidth = 2; c.beginPath(); c.moveTo(dx - r * 0.6, dy - r * 0.2); c.lineTo(dx + r * 0.6, dy - r * 0.2); c.moveTo(dx - r * 0.5, dy + r * 0.3); c.lineTo(dx + r * 0.5, dy + r * 0.3); c.stroke(); // stitching
    c.strokeStyle = R.OUT; c.lineWidth = 2.5; for (const s of [-1, 1]) { const ex = dx + s * r * 0.38, ey = dy - r * 0.15; c.beginPath(); c.moveTo(ex - 4, ey - 4); c.lineTo(ex + 4, ey + 4); c.moveTo(ex + 4, ey - 4); c.lineTo(ex - 4, ey + 4); c.stroke(); } // X eyes
    R.statusOverlay(D, dx, dy, r);
    c.fillStyle = 'rgba(0,0,0,0.5)'; R.roundRect(dx - 30, dy + r + 4, 60, 6, 3); c.fill(); c.fillStyle = D.hp < D.max * 0.3 ? '#ff5c5c' : '#9bf06a'; R.roundRect(dx - 30, dy + r + 4, Math.max(2, 60 * U.clamp(D.hp / D.max, 0, 1)), 6, 3); c.fill();
    c.save(); c.beginPath(); c.rect(x, y + 42, w, h - 42); c.clip(); // floaters stay under the header line
    for (const f of this.floaters) { c.globalAlpha = Math.min(1, f.t / 0.3); R.text(f.text, f.x, f.y, 24, f.color, 'center'); } c.globalAlpha = 1;
    c.restore();
  },
};
