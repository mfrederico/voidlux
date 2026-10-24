// The bug family: picking one for the region, spawning it, and its update: wind-up shots, lunges, the iguana's tongue.
// Extends Enemies (see enemies.js). Loads after it.
Object.assign(Enemies, {
  // pick a bug type for this region by weight; only one queen alive per region
  pickBug(region) {
    const types = Object.keys(BUGS), weights = types.map(t => BUGS[t].weight(region));
    const total = weights.reduce((a, b) => a + b, 0);
    let roll = Math.random() * total;
    let type = types[0];
    for (let i = 0; i < types.length; i++) { roll -= weights[i]; if (roll <= 0) { type = types[i]; break; } }
    if (BUGS[type].boss && this.list.some(e => !e.dead && e.type === type && e.region === region)) type = 'stinkbug';
    return type;
  },
  spawnBug(type, x, y, region, rare) {
    const B = BUGS[type], hp = this.nmHp(B.hp + B.hpR * this.hpScale(region));
    const e = {
      kind: 'bug', type, x, y, angle: U.rand(0, TAU), speed: B.speed + B.speedR * region, hp, maxhp: hp, r: B.r, power: B.power, armor: B.armor || 0,
      st: {}, region, dead: false, flash: 0, color: B.color, dark: B.dark, seed: Math.random() * 10, legT: 0, wanderT: 0,
      shotCd: U.rand(B.cd * 0.6, B.cd), windup: 0, emerge: B.boss ? 2.0 : 1.0, hidden: true, stun: 0, strafe: U.pick([-1, 1]), mode: 'approach', modeT: 0, baseName: B.name,
      hitCircles() { return this.hidden ? [] : [{ x: this.x, y: this.y, r: this.r }]; },
      onDeath() {
        G.kills++; G.score += B.score * (this.region + 1);
        World.loot(this, B.loot);
        if (B.boss) { World.pickups.push(World.mkPickup(this.x, this.y, 'cleanse', 'cleanse', this.region)); G.msg(`${B.name} defeated!`, '#ffd166'); G.shake = 0.5; }
        if (B.split && this.r > 9) { for (const s of [-1, 1]) { const h = Enemies.spawnBug('blob', this.x + s * this.r * 0.8, this.y + U.rand(-6, 6), this.region, false); h.r = Math.round(this.r * 0.68); h.hp = h.maxhp = Math.max(3, Math.round(this.maxhp * 0.45)); h.hidden = false; h.emerge = 0; h.angle = s > 0 ? 0.6 : Math.PI - 0.6; } } // a lava blob splits in two
        if (B.ghost) { for (let k = 0; k < 14; k++) Combat.particles.push(Combat.mk(this.x, this.y, U.rand(-90, 90), U.rand(-140, -20), 0.8, U.pick(['#dfe6ff', '#b8c4ff', '#ffffff']), 4)); } // it bursts into wisps
        else if (B.bop) { for (let k = 0; k < 14; k++) Combat.particles.push(Combat.mk(this.x, this.y, U.rand(-120, 120), U.rand(-160, 20), 0.9, U.pick(['#ffd166', '#ff5c8a', '#7df9ff', '#9bf06a', '#fff']), 3)); } // confetti
      },
    };
    if (B.ai === 'flee') e.flee = true;
    if (B.boss) G.msg(`A ${B.name} is burrowing up nearby!`, '#ff6b9d');
    if (!B.boss && (rare === undefined ? this.rollRare(region) : rare)) this.makeRare(e);
    this.list.push(e);
    return e;
  },
  skullFlame(e) { Combat.particles.push(Combat.mk(e.x - Math.cos(e.angle) * 8 + U.rand(-5, 5), e.y + U.rand(-5, 5), -Math.cos(e.angle) * 30, -40 + U.rand(-20, 10), 0.45, U.chance(0.5) ? '#ff8a3d' : '#ffd166', 4)); },
  shoot(e, B, P) {
    const S = B.shot, base = Math.atan2(P.y - e.y, P.x - e.x), hostile = P !== Player, n = S.n + (e.extraShots || 0), spread = S.spread || (n > 1 ? 0.18 : 0);
    for (let i = 0; i < n; i++) {
      const off = n === 1 ? 0 : U.lerp(-spread, spread, i / (n - 1));
      Combat.fireEnemy(e.x, e.y, base + off, Object.assign({}, S, { hostile, from: e }));
    }
    if (S.ring) for (let i = 0; i < S.ring; i++) Combat.fireEnemy(e.x, e.y, i * TAU / S.ring + G.time, { speed: S.ringSpeed, power: 1, size: 5, color: S.color, life: 3.5 });
    Combat.burst(e.x, e.y, 6, S.color, 2);
    SFX.play('hit');
  },
  updateBug(e, dt) {
    const P = Player, B = BUGS[e.type], R = World.regions[e.region] || World.regions[World.regionAt(e.x)];
    if (e.emerge > 0) {
      e.emerge -= dt;
      if (e.emerge <= 0) { e.hidden = false; Combat.burst(e.x, e.y, 14, '#8a5a3a', 3); Combat.burst(e.x, e.y, 6, '#c9a26b', 2); }
      return;
    }
    Combat.tickStatus(e, dt);
    if (e.dead) return;
    e.stun -= dt; e.legT += dt * 10;
    const frozen = !!e.st.freeze;
    // berserk: forget the player, go for the nearest other enemy
    if (e.st.berserk) {
      const T = this.rageTarget(e);
      if (T) {
        const td = U.dist(T.x, T.y, e.x, e.y);
        e.angle = U.angLerp(e.angle, Math.atan2(T.y - e.y, T.x - e.x), 5 * dt);
        if (!frozen) { e.x += Math.cos(e.angle) * e.speed * 1.2 * dt; e.y += Math.sin(e.angle) * e.speed * 1.2 * dt; }
        if (td < e.r + T.r + 2) { Combat.damage(T, 9 * dt, '#ff7bff'); if (U.chance(dt * 6)) Combat.burst(T.x, T.y, 3, '#ff7bff', 2); }
        if (e.windup > 0) { e.windup -= dt; if (e.windup <= 0) { this.shoot(e, B, T); e.shotCd = B.cd * (e.cdMult || 1) * U.rand(0.85, 1.15) * (T === Player ? this.pressure() : 1); } }
        else if (td < B.range) { e.shotCd -= dt; if (e.shotCd <= 0) e.windup = 0.3; }
      }
      this.contain(e, 40);
      return;
    }
    const dx = P.x - e.x, dy = P.y - e.y, d = Math.hypot(dx, dy) || 1, toP = Math.atan2(dy, dx);
    let sp = e.speed, target = e.angle;
    if (!P.alive) { e.wanderT -= dt; if (e.wanderT <= 0) { e.wanderT = U.rand(0.8, 2); e.angle += U.rand(-1.5, 1.5); } }
    else if (e.flee) { // the Dot Hoarder runs from you, zigzagging; it dawdles when you are far
      target = d < 520 ? toP + Math.PI + Math.sin(G.time * 4 + e.seed) * 0.7 : e.angle + Math.sin(G.time * 1.5 + e.seed) * 0.05;
      e.angle = U.angLerp(e.angle, target, 4 * dt); sp = d < 520 ? e.speed : e.speed * 0.35;
    }
    else switch (B.ai) {
      case 'swarm': target = toP + Math.sin(G.time * 6 + e.seed) * 0.9; e.angle = U.angLerp(e.angle, target, 5 * dt); break;
      case 'wander':
        e.wanderT -= dt;
        if (e.wanderT <= 0) { e.wanderT = U.rand(0.8, 2.2); e.angle = d < 420 ? toP + U.rand(-0.8, 0.8) : e.angle + U.rand(-1.5, 1.5); }
        break;
      case 'keep': {
        if (d > 300) target = toP; else if (d < 200) target = toP + Math.PI; else target = toP + e.strafe * Math.PI / 2;
        if (U.chance(dt * 0.3)) e.strafe = -e.strafe;
        e.angle = U.angLerp(e.angle, target, 3 * dt); sp = d > 200 && d < 300 ? e.speed * 0.7 : e.speed; break;
      }
      case 'dive':
        e.modeT -= dt;
        if (e.mode === 'approach' && d < 110) { e.mode = 'retreat'; e.modeT = 1.1; }
        else if (e.mode === 'retreat' && e.modeT <= 0) e.mode = 'approach';
        target = e.mode === 'approach' ? toP : toP + Math.PI + Math.sin(G.time * 3) * 0.6;
        e.angle = U.angLerp(e.angle, target, 4 * dt); break;
      case 'boss': target = d > 160 ? toP : toP + Math.PI / 2 * e.strafe; e.angle = U.angLerp(e.angle, target, 1.5 * dt); break;
      case 'lunge': // earwig: creeps up, then a sudden pincer lunge, then a breather
        e.modeT -= dt;
        if (e.mode === 'lunge') { sp = e.speed * 2.8; if (e.modeT <= 0) { e.mode = 'rest'; e.modeT = 1.1; } }
        else if (e.mode === 'rest') { target = toP + Math.PI + Math.sin(G.time * 5 + e.seed) * 0.5; e.angle = U.angLerp(e.angle, target, 4 * dt); sp = e.speed * 0.5; if (e.modeT <= 0) e.mode = 'approach'; }
        else { target = toP + Math.sin(G.time * 3 + e.seed) * 0.4; e.angle = U.angLerp(e.angle, target, 5 * dt); if (d < 230 && e.modeT <= 0) { e.mode = 'lunge'; e.modeT = 0.45; e.angle = toP; Combat.burst(e.x, e.y, 4, e.color, 1.5); } }
        break;
      case 'tongue': sp = 0; e.angle = U.angLerp(e.angle, toP, 2.5 * dt); break; // iguana: rooted, just turns to face you
      case 'mime': { // rooted, faces you, and holds an invisible wall across the line between you (bullets and your body stop at it)
        sp = 0; e.angle = U.angLerp(e.angle, toP, 2 * dt);
        e.mimeWall = { x: e.x + Math.cos(e.angle) * 46, y: e.y + Math.sin(e.angle) * 46, a: e.angle + Math.PI / 2, len: B.wall };
        e.mimeT = (e.mimeT || 0) + dt; break;
      }
      case 'ball': { // the elephant on its ball: a straight line, bouncing off everything, a trumpet now and then
        target = e.angle; e.roll = (e.roll || 0) + sp * dt / 14;
        const nx = e.x + Math.cos(e.angle) * sp * dt, ny = e.y + Math.sin(e.angle) * sp * dt;
        if (World.solidAt(nx, e.y, e.r)) e.angle = Math.PI - e.angle; else if (World.solidAt(e.x, ny, e.r)) e.angle = -e.angle;
        if (U.chance(dt * 0.15) && Math.abs(P.x - e.x) < 1100) SFX.play('trumpet');
        break;
      }
      case 'kami': { // a kamikaze clown: straight at you with the mallet up
        if (e.born > 0) { e.born -= dt; sp = e.speed * 0.3; }
        e.angle = U.angLerp(e.angle, toP, 7 * dt); e.life = (e.life || 0) + dt;
        if (e.life > 12) { Combat.kill(e); return; } // it runs out of puff eventually
        break;
      }
    }
    if (e.tongue) this.updateTongue(e, dt);
    if (!frozen) {
      const k = (e.stun > 0 ? 0.3 : 1) * (e.st.slow ? 0.5 : 1);
      e.x += Math.cos(e.angle) * sp * k * dt; e.y += Math.sin(e.angle) * sp * k * dt;
    }
    if (e.y < 50 || e.y > CFG.WORLD_H - 50) e.angle = -e.angle;
    if (this.contain(e, 40)) e.angle = Math.PI - e.angle; // an intact wall turns it back; a breach lets it through
    if (e.type === 'skull' && U.chance(dt * 25)) this.skullFlame(e);
    if (e.type === 'mooncow' && U.chance(dt * 0.12) && Math.abs(P.x - e.x) < 1000) SFX.play('moo');
    // shooting with a wind-up telegraph (steam blinds them); an iguana lashes its tongue instead
    if (P.alive && !frozen && !e.st.blind && !e.tongue) {
      if (e.windup > 0) { e.windup -= dt; if (e.windup <= 0) { if (B.tongue) this.lash(e, B, P); else this.shoot(e, B, P); e.shotCd = B.cd * (e.cdMult || 1) * U.rand(0.85, 1.15) * this.pressure(); } }
      else if (d < B.range && !e.flee) { e.shotCd -= dt; if (e.shotCd <= 0) e.windup = B.boss ? 0.7 : B.tongue ? 0.55 : 0.45; }
    }
    // contact
    if (P.alive && P.invuln <= 0 && U.dist2(e.x, e.y, P.x, P.y) < (e.r + CFG.HEAD_R) ** 2) {
      if (B.ai === 'flee') { Combat.kill(e); return; } // a chicken caught is a chicken dinner
      P.hit(e.power, B.ghost ? 'a grave ghost' : B.bop ? "a clown's mallet" : `a ${B.name.toLowerCase()}`, e.x, e.y);
      if (B.clown) SFX.play('honk'); // you pressed a clown's nose. Honk-honk
      if (B.fragile) Combat.kill(e); else e.stun = 0.8;
    } else if (B.bop && P.alive && P.invuln <= 0) { // the mallet lands on a segment too
      for (let j = 0; j < P.segPos.length; j++) { const s = P.segPos[j]; if (U.dist2(e.x, e.y, s.x, s.y) < (e.r + CFG.SEG_R) ** 2) { P.removeAt(j, B.ghost ? 'a grave ghost' : 'bop'); P.invuln = 0.5; SFX.play(B.ghost ? 'scream' : 'squeak'); G.msg(B.ghost ? 'A grave ghost burst through a segment!' : 'BOP! A clown mallet took a segment.', '#ff9b8b'); Combat.kill(e); break; } }
    }
  },
  // the iguana's tongue: shoots out toward where you were at the flick, to `range` px, and whips back. The tip slurps
  // the first segment it touches (the iguana heals a little) and costs the head a segment the usual way.
  lash(e, B, P) {
    e.tongue = { a: Math.atan2(P.y - e.y, P.x - e.x), len: 0, phase: 'out', max: B.range + 20 };
    SFX.play('shed'); Combat.burst(e.x + Math.cos(e.tongue.a) * e.r, e.y + Math.sin(e.tongue.a) * e.r, 3, '#ff6b9d', 1.5);
  },
  updateTongue(e, dt) {
    const T = e.tongue, B = BUGS[e.type], P = Player;
    if (T.phase === 'out') { T.len += B.tongue * dt; if (T.len >= T.max) { T.len = T.max; T.phase = 'back'; } } else { T.len -= B.tongue * 1.3 * dt; if (T.len <= 0) { e.tongue = null; return; } }
    const tx = e.x + Math.cos(T.a) * T.len, ty = e.y + Math.sin(T.a) * T.len;
    if (T.phase !== 'out' || !P.alive || P.tiny > 0) return; // (a tiny centipede is under the tongue's reach)
    if (P.invuln <= 0 && U.dist2(tx, ty, P.x, P.y) < (CFG.HEAD_R + 7) ** 2) { P.hit(1, 'an iguana tongue', e.x, e.y); T.phase = 'back'; return; }
    for (let j = 0; j < P.segPos.length; j++) {
      const s = P.segPos[j];
      if (U.dist2(tx, ty, s.x, s.y) < (CFG.SEG_R + 7) ** 2) { const name = P.segName(P.segments[j]); P.removeAt(j, 'slurp'); e.hp = Math.min(e.maxhp, e.hp + 10); T.phase = 'back'; Combat.burst(tx, ty, 10, '#ff6b9d', 2); G.msg(`An iguana slurped your ${name}!`, '#ff9b8b'); return; }
    }
    for (const m of G.enemies) { if (!m.minion || m.dead) continue; if (U.dist2(tx, ty, m.x, m.y) < (m.r + 7) ** 2) { Minions.hurt(m, 12, e); T.phase = 'back'; return; } }
  },
});
