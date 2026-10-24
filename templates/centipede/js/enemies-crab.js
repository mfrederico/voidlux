// The crab: its claws, the pincer strike and the snip that takes a segment.
// Extends Enemies (see enemies.js). Loads after it.
Object.assign(Enemies, {
  // where a claw's jaws are (side -1 or 1): ahead of the shell and out to that side, as drawn
  clawAt(e, side) { const fx = Math.cos(e.angle), fy = Math.sin(e.angle), r = e.r; return { x: e.x + fx * r * 1.3 - fy * side * r, y: e.y + fy * r * 1.3 + fx * side * r }; },
  // does a claw at (x, y) catch the centipede? (any segment, or the head)
  clawCatches(x, y, rad) { const P = Player; return U.dist2(P.x, P.y, x, y) < (rad + CFG.HEAD_R) ** 2 || P.segPos.some(q => U.dist2(q.x, q.y, x, y) < (rad + CFG.SEG_R) ** 2); },
  crabStrike(e, B, dt, toP, frozen, enraged) {
    const S = e.snip, P = Player, R = World.regions[e.region]; if (frozen) return; S.t += dt;
    const end = () => { e.snip = null; e.snipCd = enraged ? U.rand(3, 5) : U.rand(5, 8); };
    const bite = () => { // it closed on you
      if (P.invuln > 0) return false;
      if (P.weaponCount() >= 2) this.snip(e, B); else P.hit(1, `the ${B.name}'s pincer`, e.x, e.y);
      return true;
    };
    if (S.phase === 'charge') {
      e.clawT = 0.2;
      if (S.t >= S.ch) {
        const q = P.predict(S.mode === 'lunge' ? 0.3 : 0.25); S.a = S.locked ? e.angle : Math.atan2(q.y - e.y, q.x - e.x); e.angle = S.a; S.phase = S.mode; S.t = 0;
        if (S.mode === 'lunge') { S.vx = Math.cos(S.a) * 980; S.vy = Math.sin(S.a) * 980; S.go = S.locked ? Math.max(30, Math.hypot(S.tx - e.x, S.ty - e.y) - e.r * 1.3) : 372; S.went = 0; SFX.play('roar'); } // a plain crab lunges only as far as the spot it locked on, not through it
        else { const c = this.clawAt(e, S.side), tx = S.aimed ? q.x : S.tx, ty = S.aimed ? q.y : S.ty; S.cx = c.x; S.cy = c.y; S.ca = Math.atan2(ty - c.y, tx - c.x); S.out = true; S.reach = 0; SFX.play('zap'); }
      }
    } else if (S.phase === 'lunge') {
      const stepK = Math.min(1, (S.go - S.went) / (980 * dt)); S.went += 980 * dt * stepK;
      e.x = U.clamp(e.x + S.vx * dt * stepK, R.x0 + 120, R.x1 - 120); e.y = U.clamp(e.y + S.vy * dt * stepK, 90, CFG.WORLD_H - 90); e.clawT = 0.2;
      if (U.chance(dt * 30)) Combat.burst(e.x, e.y + e.r * 0.5, 2, '#c9b48a', 2);
      { const c = this.clawAt(e, 0); if (this.clawCatches(c.x, c.y, e.r * 0.7) && bite()) { end(); return; } } // a lunge bites with its front, between the claws, and stops with that bite on the spot it locked
      if (S.t > 0.5 || (S.went >= S.go && S.t > 0.2)) end(); // there, it holds its jaws at the spot a beat before it walks again
    } else { // the claw flies out on its arm, then reels back in
      const sp = 950;
      if (S.out) { S.cx += Math.cos(S.ca) * sp * dt; S.cy += Math.sin(S.ca) * sp * dt; S.reach += sp * dt; if (S.reach > 520) S.out = false; }
      else { const c = this.clawAt(e, S.side), dx = c.x - S.cx, dy = c.y - S.cy, dd = Math.hypot(dx, dy); if (dd < 24) { end(); return; } const m = Math.min(dd, 1200 * dt); S.cx += dx / dd * m; S.cy += dy / dd * m; }
      if (S.out && this.clawCatches(S.cx, S.cy, 26) && bite()) S.out = false; // caught you: it reels back
    }
  },
  // SNIP: the King Crab cuts the centipede at the halfway point. The front half (and any riding minions) stays; every weapon
  // segment behind the cut is flung out whole, away from the crab, as a loose pickup on the usual fuse: go and get them back
  snip(e, B) {
    SFX.attack(e);
    const P = Player, ws = P.segments.filter(s => !P.isMinion(s)), lost = ws.slice(Math.ceil(ws.length / 2)), k = lost.length; if (!k) return;
    const ri = World.regionAt(P.x), cutAt = P.segPos[P.segments.indexOf(lost[0])] || P;
    lost.forEach((s, m) => {
      const q = P.segPos[P.segments.indexOf(s)] || P, pk = World.mkPickup(q.x, q.y, 'weapon', s.id, ri), base = Math.atan2(q.y - e.y, q.x - e.x);
      const a = base + (m - (k - 1) / 2) * 0.35 + U.rand(-0.15, 0.15), d = U.rand(280, 460) * 4; // (thrown pickups slow by e^-4t: they travel d/4)
      pk.loose = true; pk.vx = Math.cos(a) * d; pk.vy = Math.sin(a) * d; if (CFG.LOOSE.time > 0) pk.fuse = CFG.LOOSE.time; World.pickups.push(pk);
    });
    const gone = new Set(lost); P.segments = P.segments.filter(s => !gone.has(s)); P.resolveBuild(); P.computeSegPos();
    P.invuln = CFG.INVULN * G.mods.invuln; e.snipCd = 5; e.clawT = 0.5; G.shake = Math.max(G.shake, 0.5);
    Combat.burst(cutAt.x, cutAt.y, 18, '#ff7a5c', 3); SFX.play('snip'); SFX.play('lose');
    G.msg(`SNIP! The ${B.name} cut you in half: ${k} segment${k > 1 ? 's' : ''} flung out. Go and get ${k > 1 ? 'them' : 'it'} back before ${k > 1 ? 'they fade' : 'it fades'}!`, '#ff7a5c');
  },
});
