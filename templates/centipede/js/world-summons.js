// Circle-to-summon: waking boulders (and their blast), the farm's barns and tractors, tombstones and their ghosts,
// the singing trees, and the circus cannons that fire you across the map.
// Extends World (see world.js). Loads after it.
Object.assign(World, {
  // ---- waking boulders ----
  // laps are counted as signed angle swept around the rock while you stay within CFG.ROCK.radius of it;
  // leave for more than 1.5s and the count resets
  updateRocks(dt) {
    const P = Player, K = CFG.ROCK;
    for (const b of this.boulders) {
      if (b.gone) continue;
      const d2 = U.dist2(b.x, b.y, P.x, P.y);
      if (d2 > (K.radius + b.r) ** 2 || Math.abs(b.x - P.x) > 400) { if (b.spin) { b.spinT = (b.spinT || 0) + dt; if (b.spinT > 1.5) { b.spin = 0; b.lastAng = null; } } continue; }
      const ang = Math.atan2(P.y - b.y, P.x - b.x);
      if (b.lastAng != null) { let d = ang - b.lastAng; while (d > Math.PI) d -= TAU; while (d < -Math.PI) d += TAU; if (b.spin && Math.sign(d) !== Math.sign(b.spin)) b.spin *= 0.985; b.spin = (b.spin || 0) + d; }
      b.lastAng = ang; b.spinT = 0;
      const laps = Math.abs(b.spin) / TAU;
      if (laps >= 0.4 && !b.cheered) { b.cheered = true; G.msg('The boulder stirs... keep circling to summon it!', '#d8d0e8'); }
      if (laps >= K.laps * Perks.summonLaps()) this.wakeRock(b);
    }
    for (const e of G.enemies) if (e.kind === 'rock' && !e.dead) this.updateRock(e, dt);
  },
  // a roller: the woken boulder entity (also the barn's tractors). It drives at a monster and goes off like a bomb (updateRock)
  roller(x, y, r, region, seed) {
    const K = CFG.ROCK, R = this.regions[region];
    const e = {
      kind: 'rock', x, y, r, angle: Player.angle, ally: 1e9, hp: 9999, maxhp: 9999, armor: 1, power: 0, speed: K.speed, life: K.life, roll: 0, seed: seed || Math.random() * 10,
      color: '#a9a2b3', dark: '#6f6880', st: {}, dead: false, flash: 0, hidden: false, region, target: null, wake: 0.7, vx: 0, vy: 0, circus: !!(R && R.circus), rank: 0,
      hitCircles() { return []; }, onDeath() {},
    };
    G.enemies.push(e);
    return e;
  },
  wakeRock(b) {
    b.gone = true; b.spin = 0;
    this.roller(b.x, b.y, b.r, b.region, b.seed);
    Combat.burst(b.x, b.y, 16, '#a9a2b3', 3); Combat.burst(b.x, b.y, 8, '#7fbf6a', 2); SFX.play('power'); G.shake = Math.max(G.shake, 0.3);
    G.msg('THE BOULDER WAKES! It rolls at the nearest monster and goes off like a bomb.', '#ffd166');
  },
  // ---- the farm's barns: circle one (summon it) and the doors fly open: three tractors drive out at the nearest monsters ----
  updateBarns(dt) {
    const P = Player, K = CFG.ROCK, ri = this.regionAt(P.x), R = this.regions[ri];
    if (!R.farm) return;
    for (const d of R.deco) {
      if (d.kind !== 'barn') continue;
      if (d.doorT > 0) d.doorT -= dt;
      if (d.used) continue;
      const d2 = U.dist2(d.x, d.y, P.x, P.y);
      if (d2 > (K.radius + 40) ** 2) { if (d.spin) { d.spinT = (d.spinT || 0) + dt; if (d.spinT > 1.5) { d.spin = 0; d.lastAng = null; } } continue; }
      const ang = Math.atan2(P.y - d.y, P.x - d.x);
      if (d.lastAng != null) { let dd = ang - d.lastAng; while (dd > Math.PI) dd -= TAU; while (dd < -Math.PI) dd += TAU; if (d.spin && Math.sign(dd) !== Math.sign(d.spin)) d.spin *= 0.985; d.spin = (d.spin || 0) + dd; }
      d.lastAng = ang; d.spinT = 0;
      const laps = Math.abs(d.spin) / TAU;
      if (laps >= 0.4 && !d.cheered) { d.cheered = true; G.msg('Engines turn over inside the barn... keep circling to summon them!', '#ffd166'); }
      if (laps >= K.laps * Perks.summonLaps()) this.openBarn(d, ri);
    }
  },
  openBarn(d, ri) {
    d.used = true; d.spin = 0; d.lastAng = null; d.doorT = 1.2;
    for (let k = 0; k < 3; k++) { const e = this.roller(d.x + (k - 1) * 14, d.y + 30 + k * 6, 20, ri, Math.random() * 10); e.farm = true; e.rank = k; e.wake = 0.5 + k * 0.4; e.angle = Math.PI / 2 + (k - 1) * 0.5; e.life = CFG.ROCK.life + 2; }
    Combat.burst(d.x, d.y + 20, 20, '#d9b85a', 3); Combat.burst(d.x, d.y + 20, 10, '#8a5a3a', 2); SFX.play('wall'); SFX.play('vroom'); setTimeout(() => SFX.play('vroom'), 400); G.shake = Math.max(G.shake, 0.4);
    G.msg('THE BARN DOORS FLY OPEN: three tractors roar out at the nearest monsters and go off like bombs!', '#ffd166');
  },
  updateRock(e, dt) {
    const K = CFG.ROCK;
    if (e.wake > 0) { e.wake -= dt; return; } // a moment of blinking before it charges
    e.life -= dt;
    let best = null, bd = K.range * K.range;
    if (e.rank) { // the barn's second and third tractors each take a different monster, so three do not pile onto one
      const cands = []; for (const o of G.enemies) { if (o === e || o.dead || o.hidden || o.ally > 0) continue; const d2 = U.dist2(o.x, o.y, e.x, e.y); if (d2 < bd) cands.push({ o, d2 }); }
      cands.sort((a, b) => a.d2 - b.d2); const pick = cands[Math.min(e.rank, cands.length - 1)]; if (pick) { best = pick.o; bd = pick.d2; }
    } else for (const o of G.enemies) { if (o === e || o.dead || o.hidden || o.ally > 0) continue; const d2 = U.dist2(o.x, o.y, e.x, e.y); if (d2 < bd) { bd = d2; best = o; } }
    e.target = best;
    if (best) e.angle = U.angLerp(e.angle, Math.atan2(best.y - e.y, best.x - e.x), 4 * dt);
    e.x += Math.cos(e.angle) * e.speed * dt; e.y += Math.sin(e.angle) * e.speed * dt;
    e.roll += e.speed * dt / e.r;
    if (U.chance(dt * 12)) Combat.burst(e.x - Math.cos(e.angle) * e.r, e.y - Math.sin(e.angle) * e.r, 1, '#c9c2a8', 2);
    for (const t of this.trees) if (t.alive && Math.abs(t.x - e.x) < 60 && U.dist2(t.x, t.y, e.x, e.y) < (e.r + t.r * 0.55) ** 2) { t.alive = false; Combat.burst(t.x, t.y, 14, '#5fae4c', 3); } // it flattens trees on the way
    const w = this.wallAt(e.x, e.y, e.r); if (w) { this.damageWall(w, K.wallDmg); this.boom(e); return; }
    if (e.y < CFG.HEDGE + e.r) { e.y = CFG.HEDGE + e.r; e.angle = -e.angle; } else if (e.y > CFG.WORLD_H - CFG.HEDGE - e.r) { e.y = CFG.WORLD_H - CFG.HEDGE - e.r; e.angle = -e.angle; } // it bounces off the hedge
    if (e.x < CFG.HEDGE + e.r) { e.x = CFG.HEDGE + e.r; e.angle = Math.PI - e.angle; }
    if (best && bd < (e.r + best.r + 4) ** 2) { this.boom(e, best); return; }
    if (e.life <= 0) { this.boom(e); }
  },
  // ---- tombstones: circle one and a ghost rises ----
  updateGraves(dt) {
    const P = Player, K = CFG.GRAVE;
    for (const g of this.graves) {
      if (g.used) continue;
      const d2 = U.dist2(g.x, g.y, P.x, P.y);
      if (d2 > (K.radius + g.r) ** 2 || Math.abs(g.x - P.x) > 400) { if (g.spin) { g.spinT = (g.spinT || 0) + dt; if (g.spinT > 1.5) { g.spin = 0; g.lastAng = null; } } continue; }
      const ang = Math.atan2(P.y - g.y, P.x - g.x);
      if (g.lastAng != null) { let d = ang - g.lastAng; while (d > Math.PI) d -= TAU; while (d < -Math.PI) d += TAU; if (g.spin && Math.sign(d) !== Math.sign(g.spin)) g.spin *= 0.985; g.spin = (g.spin || 0) + d; }
      g.lastAng = ang; g.spinT = 0;
      const laps = Math.abs(g.spin) / TAU;
      if (laps >= 0.4 && !g.cheered) { g.cheered = true; G.msg('Something stirs under the stone... keep circling to summon it!', '#dfe6ff'); }
      if (laps >= K.laps * Perks.summonLaps()) this.raiseGhost(g);
    }
    for (let i = this.wraiths.length - 1; i >= 0; i--) { const w = this.wraiths[i]; w.t += dt; if (w.t >= w.life) this.wraiths.splice(i, 1); }
  },
  // ---- singing trees (jungle): circle one and it plays; monsters come and stand hypnotized ----
  updateTrees(dt) {
    const P = Player, K = CFG.SONG;
    for (const t of this.trees) {
      if (!t.jungle || !t.alive) continue;
      if (t.song > 0) { // while the song plays, anything that wanders into range falls under it too (and forgets you)
        t.song -= dt; if (t.song <= 0) { t.song = 0; t.sung = true; continue; }
        if (t.song > 0.5) for (const e of G.enemies) { if (e.dead || e.hidden || e.ally > 0 || e.minion || e.kind === 'boss' || e.kind === 'stone' || e.kind === 'rock' || e.st.hypno) continue; if (U.dist2(e.x, e.y, t.x, t.y) < K.range * K.range) { Combat.addStatus(e, 'hypno', t.song, 0); e.hypnoTree = t; e.windup = 0; if (e.tongue) e.tongue = null; Combat.burst(e.x, e.y, 6, '#f3c6ff', 2); } }
        continue;
      }
      if (t.sung) continue;
      const d2 = U.dist2(t.x, t.y, P.x, P.y);
      if (d2 > (K.radius + t.r) ** 2 || Math.abs(t.x - P.x) > 400) { if (t.spin) { t.spinT = (t.spinT || 0) + dt; if (t.spinT > 1.5) { t.spin = 0; t.lastAng = null; } } continue; }
      const ang = Math.atan2(P.y - t.y, P.x - t.x);
      if (t.lastAng != null) { let d = ang - t.lastAng; while (d > Math.PI) d -= TAU; while (d < -Math.PI) d += TAU; if (t.spin && Math.sign(d) !== Math.sign(t.spin)) t.spin *= 0.985; t.spin = (t.spin || 0) + d; }
      t.lastAng = ang; t.spinT = 0;
      const laps = Math.abs(t.spin) / TAU;
      if (laps >= 0.4 && !t.cheered) { t.cheered = true; G.msg('The leaves hum... keep circling to summon the song!', '#9bf06a'); }
      if (laps >= K.laps * Perks.summonLaps()) this.singTree(t);
    }
  },
  singTree(t) {
    const K = CFG.SONG; t.spin = 0; t.lastAng = null;
    const crowd = [];
    for (const e of G.enemies) { if (e.dead || e.hidden || e.ally > 0 || e.minion || e.kind === 'boss' || e.kind === 'stone' || e.kind === 'rock') continue; if (U.dist2(e.x, e.y, t.x, t.y) < K.range * K.range) crowd.push(e); }
    if (!crowd.length) { Combat.burst(t.x, t.y - t.r, 8, '#9bf06a', 2); SFX.play('pickup'); G.msg('The tree hums a few notes to nobody. Bring an audience.', '#9bf06a'); return; }
    t.song = K.time * Perks.summonPower(); t.sung = false;
    for (const e of crowd) { Combat.addStatus(e, 'hypno', t.song, 0); e.hypnoTree = t; e.windup = 0; if (e.tongue) e.tongue = null; Combat.burst(e.x, e.y, 6, '#f3c6ff', 2); }
    G.ring = { x: t.x, y: t.y, r: 0, max: K.range, t: 0.6 }; G.tint = { color: '243,198,255', t: 1.2 }; G.shake = Math.max(G.shake, 0.2);
    Music.song(t.song);
    G.msg(`THE TREE SINGS! ${crowd.length} monster${crowd.length > 1 ? 's' : ''} wander to it, hypnotized for ${K.time}s: they cannot fight back and take ${Math.round((K.dmgMult - 1) * 100)}% more.`, '#f3c6ff');
  },
  // the ghost: every monster within range falls under its spell (Enemies.charm) for a while, then bursts
  raiseGhost(g) {
    const K = CFG.GRAVE; g.spin = 0; g.lastAng = null; g.cheered = false;
    const victims = [];
    for (const e of G.enemies) { if (e.dead || e.hidden || e.ally > 0 || e.minion || e.kind === 'boss' || e.kind === 'stone' || e.kind === 'rock') continue; if (U.dist2(e.x, e.y, g.x, g.y) < K.range * K.range) victims.push(e); }
    if (!victims.length) { Combat.burst(g.x, g.y - 20, 10, '#dfe6ff', 2); SFX.play('shed'); G.msg('A ghost peeks out of the grave, finds nobody to haunt, and sinks back. Bring company.', '#dfe6ff'); return; }
    g.used = true;
    this.wraiths.push({ x: g.x, y: g.y, t: 0, life: 3.4, seed: Math.random() * 10, targets: victims });
    for (const e of victims) Enemies.charm(e, K.charm * Perks.summonPower());
    G.ring = { x: g.x, y: g.y, r: 0, max: K.range, t: 0.6 }; G.flash = 0.35; G.tint = { color: '200,220,255', t: 1.5 }; G.shake = Math.max(G.shake, 0.4); SFX.play('enter'); SFX.play('power');
    G.msg(`A GHOST RISES! ${victims.length} monster${victims.length > 1 ? 's' : ''} fall under its spell: they fight for you for ${K.charm}s, then burst.`, '#dfe6ff');
  },
  // ---- circus cannons: circle one and it fires you ----
  updateCannons(dt) {
    const P = Player, K = CFG.CANNON;
    for (const cn of this.cannons) {
      if (cn.cd > 0) cn.cd -= dt;
      if (cn.cd > 0 || P.flight) continue;
      const d2 = U.dist2(cn.x, cn.y, P.x, P.y);
      if (d2 > (K.radius + cn.r) ** 2 || Math.abs(cn.x - P.x) > 400) { if (cn.spin) { cn.spinT = (cn.spinT || 0) + dt; if (cn.spinT > 1.5) { cn.spin = 0; cn.lastAng = null; } } continue; }
      const ang = Math.atan2(P.y - cn.y, P.x - cn.x);
      if (cn.lastAng != null) { let d = ang - cn.lastAng; while (d > Math.PI) d -= TAU; while (d < -Math.PI) d += TAU; if (cn.spin && Math.sign(d) !== Math.sign(cn.spin)) cn.spin *= 0.985; cn.spin = (cn.spin || 0) + d; }
      cn.lastAng = ang; cn.spinT = 0;
      // the barrel swings toward the thickest crowd while you wind it up (east if nobody is about)
      let sx = 0, sy = 0, n = 0; for (const e of G.enemies) { if (e.dead || e.hidden || e.ally > 0 || e.kind === 'stone') continue; if (U.dist2(e.x, e.y, cn.x, cn.y) < 900 * 900) { sx += e.x; sy += e.y; n++; } }
      cn.a = U.angLerp(cn.a, n ? Math.atan2(sy / n - cn.y, sx / n - cn.x) : 0, 2 * dt);
      const laps = Math.abs(cn.spin) / TAU;
      if (laps >= 0.4 && !cn.cheered) { cn.cheered = true; G.msg('The cannon swings round to the crowd... keep circling and it fires YOU.', '#ff5c8a'); }
      if (laps >= K.laps * Perks.summonLaps()) this.fireCannon(cn);
    }
  },
  fireCannon(cn) {
    const K = CFG.CANNON, P = Player; cn.spin = 0; cn.lastAng = null; cn.cheered = false; cn.cd = 6; cn.recoil = 0.4;
    P.x = cn.x + Math.cos(cn.a) * (cn.r + 10); P.y = cn.y + Math.sin(cn.a) * (cn.r + 10); P.angle = cn.a; P.initTrail(P.x, P.y, cn.a); P.computeSegPos();
    P.flight = { t: K.time, a: cn.a, hits: 0 }; P.invuln = Math.max(P.invuln, K.time + 0.5);
    Combat.burst(P.x, P.y, 30, '#fff', 4); Combat.burst(P.x, P.y, 20, '#ff5c8a', 3); SFX.play('cannon'); G.shake = Math.max(G.shake, 0.6); G.flash = 0.25;
    G.banner = { text: 'HUMAN CANNONBALL', sub: 'well, centipede', t: 1.4 };
  },
  // in flight: a straight line at cannon speed, through everything, until the time is up or a wall stops you
  updateFlight(dt) {
    const P = Player, F = P.flight, K = CFG.CANNON;
    F.t -= dt;
    const sp = K.speed * (F.t < 0.25 ? Math.max(0.2, F.t / 0.25) : 1);
    P.x += Math.cos(F.a) * sp * dt; P.y += Math.sin(F.a) * sp * dt;
    if (U.chance(dt * 40)) Combat.particles.push(Combat.mk(P.x, P.y, U.rand(-60, 60), U.rand(-60, 60), 0.5, U.pick(['#ffd166', '#ff5c8a', '#7df9ff', '#9bf06a']), 3));
    for (const e of G.enemies) { if (e.dead || e.hidden || e.ally > 0 || e.flown === F) continue; if (U.dist2(e.x, e.y, P.x, P.y) < (K.hitR + e.r) ** 2) { e.flown = F; F.hits++; Combat.damage(e, K.dmg * G.powerMult * Perks.summonPower(), '#ff5c8a'); e.stun = Math.max(e.stun || 0, 1); Combat.burst(e.x, e.y, 8, '#fff', 2); } }
    const w = this.wallAt(P.x, P.y, CFG.HEAD_R + 20); if (w) { this.damageWall(w, K.wallDmg); P.x = w.x - Math.sign(Math.cos(F.a) || 1) * (CFG.WALL_T / 2 + CFG.HEAD_R + 30); F.t = 0; }
    P.x = U.clamp(P.x, CFG.HEDGE + 30, this.regions.length * CFG.REGION_W - 30); P.y = U.clamp(P.y, CFG.HEDGE + 30, CFG.WORLD_H - CFG.HEDGE - 30); P.wallX = P.x; // (the flight does its own wall stops)
    if (F.t <= 0) { P.flight = null; P.invuln = Math.max(P.invuln, 1); Combat.burst(P.x, P.y, 26, '#ffd166', 3); Combat.burst(P.x, P.y, 14, '#fff', 2); SFX.play('wall'); G.ring = { x: P.x, y: P.y, r: 0, max: 160, t: 0.6 }; G.msg(F.hits ? `Landed! You flattened ${F.hits} monster${F.hits > 1 ? 's' : ''} on the way.` : 'Landed. Nothing in the lane that time.', F.hits ? '#ffd166' : '#aaa'); }
  },
  boom(e, target) {
    const K = CFG.ROCK; e.dead = true;
    Combat.burst(e.x, e.y, 34, '#a9a2b3', 4); Combat.burst(e.x, e.y, 18, '#fff', 3); SFX.play('wall'); G.shake = Math.max(G.shake, 0.45);
    G.ring = { x: e.x, y: e.y, r: 0, max: K.blast, t: 0.6 };
    let hits = 0;
    for (const o of G.enemies) { if (o === e || o.dead || o.hidden || o.ally > 0) continue; const d = U.dist(o.x, o.y, e.x, e.y); if (d < K.blast + o.r) { Combat.damage(o, K.blastDmg * G.powerMult * Perks.summonPower() * (1 - 0.4 * d / (K.blast + o.r)), '#a9a2b3'); hits++; } }
    const what = e.farm ? 'tractor' : e.circus ? 'beach ball' : 'boulder';
    G.msg(target ? `The ${what} smashed into ${target.label || target.baseName || (target.kind === 'boss' ? BOSSES[target.type].name : 'a monster')}${hits > 1 ? ` and ${hits - 1} more` : ''}!` : hits ? `The ${what} went off: ${hits} hit!` : e.farm ? 'The tractor ran out of diesel.' : `The ${what} crumbled.`, hits ? '#ffd166' : '#aaa');
  },
});
