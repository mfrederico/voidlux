// The title screen's attract mode: a centipede with a random loadout races across the top of the screen, firing at
// bugs that drift in along the top and bottom edges. Pure show: it borrows the bullet table for colours, sizes and
// rates and the bug table for looks, and touches nothing in the live game. The loadout re-rolls every so often.
// main.js ticks update() while the title is up; Render.drawTitle calls draw().
const Title = {
  segs: [], pos: [], enemies: [], shots: [], parts: [], t: 0, spawnT: 0, rollT: 0, Y: 150, SPEED: 230,
  reset() {
    const ids = Object.keys(BULLETS).filter(id => { const B = BULLETS[id]; return !B.minion && B.kind !== 'trail' && B.kind !== 'mine' && B.kind !== 'orbit'; });
    const n = U.randi(7, 11); this.segs = []; for (let i = 0; i < n; i++) this.segs.push({ id: U.pick(ids), cd: U.rand(0, 0.6) });
    this.enemies = []; this.shots = []; this.parts = []; this.spawnT = 0; this.rollT = 0;
  },
  spawn() {
    const types = ['gnat', 'beetle', 'hornet', 'roach', 'earwig', 'stinkbug', 'chicken', 'skull'].filter(k => BUGS[k]);
    const type = U.pick(types), B = BUGS[type], top = Math.random() < 0.5, fromRight = Math.random() < 0.5;
    this.enemies.push({ kind: 'bug', type, x: fromRight ? CFG.W + 40 : -40, y: top ? U.rand(36, 100) : U.rand(CFG.H - 100, CFG.H - 36), vx: (fromRight ? -1 : 1) * U.rand(50, 130), vy: 0, angle: fromRight ? Math.PI : 0, r: B.r, hp: B.hp * 2, maxhp: B.hp * 2, color: B.color, dark: B.dark, seed: Math.random() * 10, legT: 0, windup: 0, flash: 0, st: {}, region: 0, mode: 'approach', dead: false });
  },
  update(dt) {
    if (!this.segs.length) this.reset();
    this.t += dt; this.rollT += dt; if (this.rollT > 16) this.reset();
    // the centipede: head first, right to left, wrapping round
    const n = this.segs.length, base = CFG.W + 60 + n * 32 - ((this.t * this.SPEED) % (CFG.W + 160 + n * 32));
    this.pos = [{ x: base - 32, y: this.Y + Math.sin(this.t * 3 - 0.5) * 18, a: Math.PI }];
    for (let i = 0; i < n; i++) this.pos.push({ x: base + i * 32, y: this.Y + Math.sin(this.t * 3 + i * 0.5) * 18, a: Math.atan2(Math.cos(this.t * 3 + i * 0.5) * 36, -32) });
    // bugs drift in along the edges
    this.spawnT -= dt; if (this.spawnT <= 0 && this.enemies.length < 14) { this.spawn(); this.spawnT = U.rand(0.3, 0.6); }
    for (const e of this.enemies) { e.x += e.vx * dt; e.y += Math.sin(this.t * 2 + e.seed) * 12 * dt; e.legT += dt * 12; e.flash -= dt; }
    // every segment fires at the nearest bug on screen, on its bullet's clock
    const onScreen = this.enemies.filter(e => e.x > 20 && e.x < CFG.W - 20);
    this.segs.forEach((s, i) => {
      s.cd -= dt; if (s.cd > 0) return;
      const p = this.pos[i + 1]; if (p.x < -20 || p.x > CFG.W + 20 || !onScreen.length) { s.cd = 0.15; return; }
      let best = null, bd = 1e12; for (const e of onScreen) { const d = U.dist2(e.x, e.y, p.x, p.y); if (d < bd) { bd = d; best = e; } }
      const B = BULLETS[s.id], burst = B.burst || 1, a0 = Math.atan2(best.y - p.y, best.x - p.x);
      for (let k = 0; k < burst; k++) {
        const a = a0 + (burst > 1 ? (k - (burst - 1) / 2) * 0.18 : 0), sp = B.laser ? 0 : U.clamp((B.speed || 500) * 1.3, 520, 1100);
        this.shots.push({ x: p.x, y: p.y, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp, color: B.color, size: Math.max(3, B.size || 4), laser: !!B.laser, beam: !!B.beam, tx: best.x, ty: best.y, t: B.laser ? 0.12 : 2.5, dmg: B.dmg || 2, glow: !!(B.elems && B.elems.length), hit: false });
      }
      if (B.laser) { best.hp -= (B.dmg || 2) * burst; best.flash = 0.08; this.burst(best.x, best.y, 4, B.color); }
      s.cd = Math.max(0.22, (B.cd || 0.6) * 0.8);
    });
    for (let i = this.shots.length - 1; i >= 0; i--) {
      const q = this.shots[i]; q.t -= dt;
      if (!q.laser) { q.x += q.vx * dt; q.y += q.vy * dt; for (const e of this.enemies) { if (U.dist2(e.x, e.y, q.x, q.y) < (e.r + q.size) ** 2) { e.hp -= q.dmg; e.flash = 0.08; this.burst(q.x, q.y, 3, q.color); q.t = 0; break; } } }
      if (q.t <= 0 || q.x < -40 || q.x > CFG.W + 40 || q.y < -40 || q.y > CFG.H + 40) this.shots.splice(i, 1);
    }
    for (let i = this.enemies.length - 1; i >= 0; i--) { const e = this.enemies[i]; if (e.hp <= 0) { this.burst(e.x, e.y, 14, e.color); this.burst(e.x, e.y, 6, '#fff'); this.enemies.splice(i, 1); } else if (e.x < -80 || e.x > CFG.W + 80) this.enemies.splice(i, 1); }
    for (const p of this.parts) { p.x += p.vx * dt; p.y += p.vy * dt; p.vy += 200 * dt; p.t -= dt; } this.parts = this.parts.filter(p => p.t > 0);
  },
  burst(x, y, n, color) { for (let i = 0; i < n; i++) { const a = Math.random() * TAU, sp = U.rand(60, 260); this.parts.push({ x, y, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp, t: U.rand(0.3, 0.7), r: U.rand(2, 5), color }); } },
  draw() {
    const R = Render, c = R.ctx;
    for (const e of this.enemies) R.drawBug(e);
    for (const q of this.shots) {
      if (q.laser) { c.save(); c.globalAlpha = Math.min(1, q.t / 0.12); c.strokeStyle = q.color; c.lineWidth = q.beam ? 7 : 3; c.lineCap = 'round'; c.beginPath(); c.moveTo(q.x, q.y); c.lineTo(q.tx, q.ty); c.stroke(); c.restore(); continue; }
      if (q.glow) { c.fillStyle = q.color + '55'; c.beginPath(); c.arc(q.x, q.y, q.size + 4, 0, TAU); c.fill(); }
      R.circle(q.x, q.y, q.size, q.color, R.OUT, 1.5);
    }
    for (const p of this.parts) { c.save(); c.globalAlpha = Math.min(1, p.t / 0.3); c.fillStyle = p.color; c.beginPath(); c.arc(p.x, p.y, p.r, 0, TAU); c.fill(); c.restore(); }
    // the centipede, tail first so the head is on top
    for (let i = this.segs.length; i >= 1; i--) { const p = this.pos[i], B = BULLETS[this.segs[i - 1].id]; R.legs(p.x, p.y, p.a, 11, i); R.circle(p.x, p.y, 11, B.color, B.craft ? '#fff' : R.OUT, 3); R.shine(p.x, p.y, 11); }
    const h = this.pos[0]; if (h) { R.legs(h.x, h.y, Math.PI, 14, -1); R.circle(h.x, h.y, 14, '#ffb86b', R.OUT, 3.5); R.shine(h.x, h.y, 14); R.blush(h.x, h.y, Math.PI, 14); R.eyes(h.x, h.y, Math.PI, 14); R.mouth(h.x, h.y, Math.PI, 14, true); }
  },
};
