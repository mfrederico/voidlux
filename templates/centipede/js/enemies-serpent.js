// The serpent: its coils bite, and hatchlings drop off its tail.
// Extends Enemies (see enemies.js). Loads after it.
Object.assign(Enemies, {
  // the Jungle Serpent: slithers in a wave around you at range, spits venom globs that burst into lingering clouds,
  // its coils bite on contact, and every so often hatchlings drop off its tail and come for you
  updateSerpent(e, dt, mood) {
    const P = Player, B = BOSSES[e.type], R = World.regions[e.region];
    const frozen = !!e.st.freeze, enraged = e.hp < e.maxhp * 0.3, desperate = mood === 4;
    const dx = P.x - e.x, dy = P.y - e.y, d = Math.hypot(dx, dy) || 1, toP = Math.atan2(dy, dx);
    e.strafeT -= dt; if (e.strafeT <= 0) { e.strafeT = U.rand(2.5, 5); e.strafe = -e.strafe; }
    const want = enraged ? B.keep * 0.7 : B.keep;
    let heading = d > want + 80 ? toP : d < want - 80 ? toP + Math.PI : toP + e.strafe * Math.PI / 2; // close, back off, or circle
    e.wiggle += dt * 5; heading += Math.sin(e.wiggle) * 0.55; // the slither
    e.angle = U.angLerp(e.angle, heading, 2.4 * dt);
    const sp = e.speed * (desperate ? 1.5 : enraged ? 1.3 : 1) * (e.stun > 0 ? 0.3 : 1) * (e.st.slow ? 0.5 : 1);
    if (!frozen) { e.x += Math.cos(e.angle) * sp * dt; e.y += Math.sin(e.angle) * sp * dt; }
    if (e.x < R.x0 + 120 || e.x > R.x1 - 120) e.angle = Math.PI - e.angle;
    if (e.y < 90 || e.y > CFG.WORLD_H - 90) e.angle = -e.angle;
    e.x = U.clamp(e.x, R.x0 + 120, R.x1 - 120); e.y = U.clamp(e.y, 90, CFG.WORLD_H - 90);
    this.serpentTrail(e);
    // venom: a spread of globs that fly about as far as you are and burst into clouds
    e.spitT -= dt;
    if (!frozen && e.spitT <= 0 && P.alive && d < 820) {
      e.spitT = B.spitCd * (desperate ? 0.45 : enraged ? 0.7 : 1); e.clawT = 0.35;
      const n = B.spitN + (enraged ? 1 : 0), speed = 300 + 10 * e.level, q = P.predict(d / speed), aim = Math.atan2(q.y - e.y, q.x - e.x), qd = U.dist(e.x, e.y, q.x, q.y); // the globs burst where you are heading
      for (let k = 0; k < n; k++) Combat.fireEnemy(e.x + Math.cos(aim) * e.r, e.y + Math.sin(aim) * e.r, aim + (k - (n - 1) / 2) * 0.2, { speed, power: 1, size: 7, color: '#9bf06a', life: U.clamp(qd / speed, 0.5, 2.2) * U.rand(0.9, 1.05), venom: true, cloudR: B.cloudR, cloudT: B.cloudT });
      SFX.play('hiss'); G.msg(`${B.name} spits venom!`, '#9bf06a');
    }
    // hatchlings drop off the tail
    e.broodT -= dt;
    if (!frozen && e.broodT <= 0 && P.alive) {
      e.broodT = B.broodCd * (enraged ? 0.6 : 1);
      const tail = e.segPos[e.segPos.length - 1] || e, alive = this.list.filter(o => o.kind === 'snake' && o.hatchling && !o.dead).length;
      if (alive < 6) {
        for (let k = 0; k < B.broodN; k++) { const h = this.spawnSnake(tail.x + U.rand(-20, 20), tail.y + U.rand(-20, 20), e.region, false); h.segN = 3; h.hp = h.maxhp = 10 + 4 * e.level; h.speed = 170 + 10 * e.level; h.power = 1; h.hatchling = true; h.spit = false; h.baseName = 'Hatchling'; h.python = true; h.color = '#8fe07a'; h.dark = '#2a5a24'; }
        G.msg(`${B.name} sends hatchlings after you!`, '#9bf06a'); SFX.play('parasite');
      }
    }
    if (e.clawT > 0) e.clawT -= dt;
    e.screamT = (e.screamT || 0) - dt; if (e.screamT <= 0 && Math.abs(P.x - e.x) < 1300) { e.screamT = enraged ? U.rand(2, 3.5) : U.rand(5, 8); SFX.play('hiss'); }
    // the coils bite
    if (P.alive && P.invuln <= 0) {
      if (U.dist2(e.x, e.y, P.x, P.y) < (e.r + CFG.HEAD_R) ** 2) { P.hit(e.power, `the ${B.name}`, e.x, e.y); e.stun = 0.4; }
      else for (const s of e.segPos) if (U.dist2(s.x, s.y, P.x, P.y) < (B.segR + CFG.HEAD_R) ** 2) { P.hit(1, `the ${B.name}'s coils`, s.x, s.y); break; }
    }
  },
  serpentTrail(e) {
    const B = BOSSES[e.type], SP = B.segR * 1.5, t = e.trail, last = t[t.length - 1];
    const dd = U.dist(last.x, last.y, e.x, e.y);
    if (dd > 0.4) t.push({ x: e.x, y: e.y, d: last.d + dd });
    const need = (e.segN + 2) * SP + 30;
    while (t.length > 2 && t[t.length - 1].d - t[1].d > need) t.shift();
    const res = [], headD = t[t.length - 1].d;
    let j = t.length - 1;
    for (let i = 0; i < e.segN; i++) {
      const target = headD - (i + 1) * SP;
      while (j > 0 && t[j - 1].d > target) j--;
      if (j === 0) { res.push({ x: t[0].x, y: t[0].y }); continue; }
      const a = t[j - 1], b = t[j], span = b.d - a.d, f = span > 0 ? (target - a.d) / span : 0;
      res.push({ x: U.lerp(a.x, b.x, f), y: U.lerp(a.y, b.y, f) });
    }
    e.segPos = res;
  },
});
