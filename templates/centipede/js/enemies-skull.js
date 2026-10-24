// The Skull King: it hunts you, spits, and its skeletal foot stomps.
// Extends Enemies (see enemies.js). Loads after it.
Object.assign(Enemies, {
  // the Skull King: hovers at range, breathes fireballs, calls flaming skulls, and STOMPS: a shadow grows where you are
  // heading, then a skeletal foot slams down. Get out of the shadow.
  updateSkull(e, dt, mood) {
    const P = Player, B = BOSSES[e.type], R = World.regions[e.region];
    const frozen = !!e.st.freeze, enraged = e.hp < e.maxhp * 0.3, desperate = mood === 4;
    const dx = P.x - e.x, dy = P.y - e.y, d = Math.hypot(dx, dy) || 1, toP = Math.atan2(dy, dx);
    e.angle = U.angLerp(e.angle, toP, 2 * dt);
    this.tickStomp(e, dt, B, R, frozen, desperate ? 0.45 : enraged ? 0.65 : 1, d);
    // movement: hover at keep range, drifting sideways; it slows while the foot is busy
    e.strafeT -= dt; if (e.strafeT <= 0) { e.strafeT = U.rand(2, 4); e.strafe = -e.strafe; }
    const want = enraged ? B.keep * 0.8 : B.keep, radial = d > want + 60 ? 1 : d < want - 60 ? -1 : 0;
    const mx = -Math.sin(toP) * e.strafe * 0.7 + Math.cos(toP) * radial * 0.8, my = Math.cos(toP) * e.strafe * 0.7 + Math.sin(toP) * radial * 0.8;
    const sp = e.speed * (enraged ? 1.3 : 1) * (e.stun > 0 ? 0.3 : 1) * (e.st.slow ? 0.5 : 1) * (e.stomp ? 0.35 : 1);
    if (!frozen) { e.x += mx * sp * dt; e.y += my * sp * dt; }
    e.x = U.clamp(e.x, R.x0 + 140, R.x1 - 140); e.y = U.clamp(e.y, 110, CFG.WORLD_H - 110);
    // fireballs: a spread from the jaws
    e.fireT -= dt;
    if (!frozen && !e.stomp && e.fireT <= 0 && P.alive && d < 760) {
      e.fireT = B.fireCd * (enraged ? 0.6 : 1); e.clawT = 0.4;
      const n = B.fireN + (enraged ? 2 : 0);
      const fs = 300 + 15 * e.level, q = P.predict(d / fs), aim = Math.atan2(q.y - e.y, q.x - e.x); // lead the shot along your arc
      for (let k = 0; k < n; k++) Combat.fireEnemy(e.x + Math.cos(aim) * e.r * 0.8, e.y + Math.sin(aim) * e.r * 0.8, aim + (k - (n - 1) / 2) * 0.22, { speed: fs, power: 1, size: 8, color: '#ff8a3d', life: 3 });
      SFX.play('scream');
    }
    // the ring of fire: a full circle of fireballs that rolls outward. Circling at a steady range walks you straight into it
    e.ringT = (e.ringT === undefined ? B.ringCd * 0.7 : e.ringT) - dt;
    if (!frozen && !e.stomp && e.ringT <= 0 && P.alive && d < 820) {
      e.ringT = B.ringCd * (desperate ? 0.5 : enraged ? 0.7 : 1); e.clawT = 0.5;
      for (let k = 0; k < B.ringN; k++) Combat.fireEnemy(e.x, e.y, k * TAU / B.ringN + G.time, { speed: 230 + 12 * e.level, power: 1, size: 7, color: '#ffd166', life: 3.6 });
      SFX.play('roar'); G.msg(`${B.name} breathes a RING OF FIRE: find a gap!`, '#ff8a3d');
    }
    // calls flaming skulls up from the bone pile behind it
    e.summonT -= dt;
    if (!frozen && e.summonT <= 0 && P.alive) {
      e.summonT = B.summonCd * (enraged ? 0.6 : 1);
      const alive = this.list.filter(o => o.kind === 'bug' && o.type === 'skull' && !o.dead && o.region === e.region).length;
      if (alive < 5) {
        for (let k = 0; k < B.summonN; k++) { const a = toP + Math.PI + (k - (B.summonN - 1) / 2) * 0.8; const s = this.spawnBug('skull', e.x + Math.cos(a) * (e.r + 40), e.y + Math.sin(a) * (e.r + 40), e.region, false); s.hp = s.maxhp = 10 + 6 * e.level; s.emerge = 0.8; }
        G.msg(`${B.name} calls flaming skulls!`, '#ff8a3d'); SFX.play('roar');
      }
    }
    // NIGHTMARE: unmarked graves around the arena (no headstone: you cannot summon from them). He raises their dead as
    // kamikaze ghosts that dive at you and burst on contact
    if (G.nightmare) {
      if (!e.unmarked) { e.unmarked = []; for (let k = 0; k < 6; k++) e.unmarked.push({ x: U.rand(R.x0 + 220, R.x1 - 220), y: U.rand(150, CFG.WORLD_H - 150), shake: 0 }); e.ghostT = 4; }
      e.ghostT -= dt; for (const g of e.unmarked) g.shake = Math.max(0, g.shake - dt);
      const ghosts = this.list.filter(o => o.kind === 'bug' && o.type === 'kamighost' && !o.dead).length;
      if (!frozen && e.ghostT <= 0 && P.alive && ghosts < 8) {
        e.ghostT = enraged ? 3 : 5; const n = enraged ? 3 : 2, picks = U.shuffle(e.unmarked.slice()).slice(0, n);
        for (const g of picks) { const gh = this.spawnBug('kamighost', g.x, g.y, e.region, false); gh.hidden = false; gh.emerge = 0.7; gh.born = 0.6; gh.hp = gh.maxhp = this.nmHp(8 + 4 * e.level); g.shake = 0.8; Combat.burst(g.x, g.y, 10, '#6a5a48', 3); }
        SFX.play('scream'); G.msg(`${B.name} raises the dead from the unmarked graves!`, '#dfe6ff');
      }
    }
    if (e.clawT > 0) e.clawT -= dt;
    e.screamT = (e.screamT || 0) - dt; if (e.screamT <= 0 && Math.abs(P.x - e.x) < 1300) { e.screamT = enraged ? U.rand(1.5, 3) : U.rand(4, 7); SFX.play('scream'); }
    if (U.chance(dt * 20)) Combat.particles.push(Combat.mk(e.x + U.rand(-e.r, e.r) * 0.8, e.y + e.r * 0.8, U.rand(-10, 10), U.rand(-70, -40), 0.5, U.chance(0.5) ? '#ff8a3d' : '#ffd166', 5)); // fire licking up from under it
    if (P.alive && P.invuln <= 0 && U.dist2(e.x, e.y, P.x, P.y) < (e.r + CFG.HEAD_R) ** 2) { P.hit(e.power, `the ${B.name}`, e.x, e.y); e.stun = 0.5; }
  },
});
