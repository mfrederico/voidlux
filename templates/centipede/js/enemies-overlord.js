// The Brain Overlord: its laser, the tractor beam that abducts segments, the saucer stomp and the brainlings.
// Extends Enemies (see enemies.js). Loads after it.
Object.assign(Enemies, {
  // the Brain Overlord: hovers at range on its disc; sweeps a laser; abducts segments with a tractor beam and fires
  // them back as its own guns; lands the saucer on you; beams brainlings down
  updateOverlord(e, dt, mood) {
    const P = Player, B = BOSSES[e.type], R = World.regions[e.region];
    const frozen = !!e.st.freeze, enraged = e.hp < e.maxhp * 0.3, desperate = mood === 4, rate = desperate ? 0.5 : enraged ? 0.7 : 1;
    const dx = P.x - e.x, dy = P.y - e.y, d = Math.hypot(dx, dy) || 1, toP = Math.atan2(dy, dx);
    e.angle = U.angLerp(e.angle, toP, 2.5 * dt);
    if (!e.captives) e.captives = [];
    // movement: hover, drift sideways, keep its distance; it holds still for the beam and the abduction
    e.strafeT -= dt; if (e.strafeT <= 0) { e.strafeT = U.rand(2, 4); e.strafe = -e.strafe; }
    const want = enraged ? B.keep * 0.85 : B.keep, radial = d > want + 60 ? 1 : d < want - 60 ? -1 : 0, busy = e.beam || e.abd;
    const mx = -Math.sin(toP) * e.strafe * 0.7 + Math.cos(toP) * radial * 0.8, my = Math.cos(toP) * e.strafe * 0.7 + Math.sin(toP) * radial * 0.8;
    const sp = e.speed * (enraged ? 1.25 : 1) * (e.stun > 0 ? 0.3 : 1) * (e.st.slow ? 0.5 : 1) * (busy ? 0.2 : e.stomp ? 0.4 : 1);
    if (!frozen) { e.x += mx * sp * dt; e.y += my * sp * dt; }
    e.x = U.clamp(e.x, R.x0 + 140, R.x1 - 140); e.y = U.clamp(e.y, 110, CFG.WORLD_H - 110);
    // the laser: a line that warns, then fires and sweeps after you
    e.beamT = (e.beamT === undefined ? 4 : e.beamT) - dt;
    if (e.beam) {
      const L = e.beam; L.t -= dt;
      L.a = U.angLerp(L.a, toP, (L.phase === 'warn' ? B.beamSweep * 0.6 : B.beamSweep) * dt);
      if (L.phase === 'warn' && L.t <= 0) { L.phase = 'fire'; L.t = B.beamT; SFX.play('ray'); G.shake = Math.max(G.shake, 0.2); }
      else if (L.phase === 'fire') {
        if (P.alive && P.invuln <= 0) {
          const hit = (x, y, r) => { const rx = x - e.x, ry = y - e.y, along = rx * Math.cos(L.a) + ry * Math.sin(L.a); if (along < e.r || along > B.beamLen) return false; const perp = Math.abs(-rx * Math.sin(L.a) + ry * Math.cos(L.a)); return perp < r + 9; };
          if (hit(P.x, P.y, CFG.HEAD_R)) P.hit(1, "the Overlord's laser", e.x, e.y);
          else for (let j = 0; j < P.segPos.length; j++) { const s = P.segPos[j]; if (hit(s.x, s.y, CFG.SEG_R)) { P.removeAt(j, 'laser'); P.invuln = 0.7; G.msg('The laser cut off a segment!', '#ff5cf0'); break; } }
        }
        if (U.chance(dt * 30)) { const k = U.rand(e.r, B.beamLen); Combat.particles.push(Combat.mk(e.x + Math.cos(L.a) * k, e.y + Math.sin(L.a) * k, U.rand(-20, 20), U.rand(-20, 20), 0.3, '#ff5cf0', 2)); }
        if (L.t <= 0) { e.beam = null; e.beamT = B.beamCd * rate; }
      }
    } else if (!frozen && !e.abd && e.beamT <= 0 && P.alive && d < 900) { e.beam = { a: toP, phase: 'warn', t: B.beamWarn }; SFX.play('beam'); G.msg(`${B.name} charges its laser: step off the line!`, '#ff5cf0'); }
    // the tractor beam: a circle marks your tail, then whatever is inside gets pulled up and joins the alien's gun
    e.abdT = (e.abdT === undefined ? 3.5 : e.abdT) - dt;
    if (e.abd) {
      const A = e.abd; A.t -= dt;
      if (A.phase === 'warn' && A.t <= 0) {
        A.phase = 'pull'; A.t = 0.6;
        const taken = [];
        for (let j = P.segments.length - 1; j >= 0 && taken.length < B.abductN; j--) { const s = P.segPos[j]; if (!s || P.isMinion(P.segments[j])) continue; if (U.dist2(s.x, s.y, A.x, A.y) < (B.abductR + CFG.SEG_R) ** 2) { taken.push(P.segments[j].id); Combat.burst(s.x, s.y, 10, '#9bf06a', 2); P.segments.splice(j, 1); } }
        const lifted = []; // anything lying in the circle goes up too: dots, power-ups, ghosts, your own loose pieces
        for (const pk of World.pickups) { if (!pk.alive || pk.lift || U.dist2(pk.x, pk.y, A.x, A.y) > (B.abductR + (pk.r || 10)) ** 2) continue; pk.lift = { t: 0.6, d: 0.6, tx: e.x, ty: e.y }; lifted.push(pk); }
        if (lifted.length) { e.stash = (e.stash || []).concat(lifted); G.msg(`The tractor beam sucked up ${lifted.length} pickup${lifted.length > 1 ? 's' : ''} too. Down the alien and they fall back.`, '#9bf06a'); if (!taken.length) SFX.play('moo'); }
        if (taken.length) {
          for (const id of taken) e.captives.push({ id, cd: U.rand(0.5, B.captiveCd), orb: U.rand(0, TAU) });
          P.resolveBuild(); P.computeSegPos(); P.invuln = Math.max(P.invuln, 1.5); A.took = taken.length;
          G.slowmo = CFG.SLOWMO.time; G.flash = 0.35; G.tint = { color: '155,240,106', t: 1.6 }; G.shake = Math.max(G.shake, 0.5); SFX.play('abduct'); SFX.play('moo'); SFX.play('lose');
          G.banner = { text: 'ABDUCTED!', sub: taken.map(id => BULLETS[id].name).join(' + ') + ' now fight for the alien', t: 2.2 };
          G.msg(`${B.name} abducted ${taken.map(id => BULLETS[id].name).join(' and ')}! They fire at you from its gun until it dies.`, '#9bf06a');
        } else if (!lifted.length) G.msg('The tractor beam closed on nothing.', '#9bf06a');
      } else if (A.phase === 'pull' && A.t <= 0) { e.abd = null; e.abdT = B.abductCd * rate; }
    } else if (!frozen && !e.beam && e.abdT <= 0 && P.alive && d < 760 && P.segments.length) {
      const tail = P.segPos[P.segPos.length - 1] || P, q = P.predict(B.abductWarn * 0.5); // the tail follows the head's arc half a second later
      e.abd = { x: U.clamp(tail.x + (q.x - P.x), R.x0 + 60, R.x1 - 60), y: U.clamp(tail.y + (q.y - P.y), 60, CFG.WORLD_H - 60), t: B.abductWarn, warn: B.abductWarn, phase: 'warn' };
      SFX.play('abduct'); SFX.play('moo'); G.msg('TRACTOR BEAM on your tail: get it out of the circle!', '#9bf06a');
    }
    // the captives fire their own bullets at you (annoying, not deadly: enemy shots, no elements)
    for (const c of e.captives) {
      c.orb += dt * 1.2; c.cd -= dt;
      if (c.cd <= 0 && P.alive && d < 900 && !frozen) { // each captive is a gun: it fires its own bullet, at full speed, from where it orbits, leading your arc; a burst bullet fires its burst
        c.cd = B.captiveCd * U.rand(0.8, 1.3) * rate; const W = BULLETS[c.id], speed = Math.max(220, W.speed || 240), gx = e.x + Math.cos(c.orb) * (e.r + 40), gy = e.y + Math.sin(c.orb) * (e.r + 40);
        const q = P.predict(Math.min(1.2, U.dist(gx, gy, P.x, P.y) / speed)), aim = Math.atan2(q.y - gy, q.x - gx), n = Math.min(3, W.burst || 1);
        for (let k = 0; k < n; k++) Combat.fireEnemy(gx, gy, aim + (n > 1 ? (k - (n - 1) / 2) * 0.16 : 0) + U.rand(-0.05, 0.05), { speed, power: 1, size: (W.size || 4) + 1, color: W.color, life: U.clamp((W.range || 320) / speed + 0.3, 0.8, 2.4) });
        SFX.play('ray');
      }
    }
    this.tickStomp(e, dt, B, R, frozen, rate, d);
    // brainlings beam down beside it
    e.summonT = (e.summonT === undefined ? 5 : e.summonT) - dt;
    if (!frozen && e.summonT <= 0 && P.alive) {
      e.summonT = B.summonCd * rate;
      const alive = this.list.filter(o => o.kind === 'bug' && o.type === 'brainling' && !o.dead && o.region === e.region).length;
      if (alive < 5) { for (let k = 0; k < B.summonN; k++) { const a = toP + Math.PI / 2 * (k ? 1 : -1); const s = this.spawnBug('brainling', e.x + Math.cos(a) * (e.r + 60), e.y + Math.sin(a) * (e.r + 60), e.region, false); s.hp = s.maxhp = 12 + 6 * e.level; s.emerge = 1.2; } G.msg(`${B.name} beams down brainlings!`, '#ff5cf0'); SFX.play('beam'); }
    }
    e.clawT = Math.max(0, (e.clawT || 0) - dt);
    e.screamT = (e.screamT || 0) - dt; if (e.screamT <= 0 && Math.abs(P.x - e.x) < 1300) { e.screamT = U.rand(4, 7); SFX.play('ray'); }
    if (P.alive && P.invuln <= 0 && U.dist2(e.x, e.y, P.x, P.y) < (e.r + CFG.HEAD_R) ** 2) { P.hit(e.power, `the ${B.name}`, e.x, e.y); e.stun = 0.5; }
  },
});
