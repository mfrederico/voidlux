// Bosses: their hp (sized to your build), spawning, the crab's stones, the shared boss update and the stomp.
// Extends Enemies (see enemies.js). Loads after it.
Object.assign(Enemies, {
  // ---- bosses ----
  bossHp(B, level) { return Math.round(Math.min(B.hpCap || 1e9, Math.max(B.hpMin, Player.estimateDps(B.armor, 0.6) * B.fightSecs))) + B.hpL * (level - 1); }, // capped: a huge build gets a short fight
  spawnBoss(type, x, y, region, level) {
    const B = BOSSES[type], hp = B.hpMin + B.hpL * (level - 1); // (a stand-in: bossHp sizes it to your build when it wakes)
    const e = {
      kind: 'boss', type, x, y, angle: Math.PI, speed: B.speed + 10 * (level - 1), hp, maxhp: hp, r: B.r, power: B.power, armor: B.armor, level,
      st: {}, region, dead: false, flash: 0, color: B.color, dark: B.dark, seed: Math.random() * 10, legT: 0, hidden: true, emerge: 0, awake: false,
      stun: 0, vx: 0, vy: 0, strafe: 1, strafeT: 0, throwT: (B.throwCd || 8) * 0.6, stoneT: 2.5, evadeT: 0, dodge: 0, dodgeDir: 1, shieldT: 0, clawT: 0,
      stompT: 3.5, stomp: null, fireT: 2, summonT: 5, // the Skull King's timers
      spitT: 2.5, broodT: 5, trail: [{ x, y, d: 0 }], segPos: [], segN: B.segN || 0, wiggle: 0, // the Serpent's
      hitCircles() {
        if (this.hidden) return [];
        if (B.serpent) { const c = [{ x: this.x, y: this.y, r: this.r * 0.9 }]; for (const s of this.segPos) c.push({ x: s.x, y: s.y, r: B.segR }); return c; }
        return [{ x: this.x, y: this.y, r: this.r * 0.9 }, { x: this.x + Math.cos(this.angle) * this.r * 0.9, y: this.y + Math.sin(this.angle) * this.r * 0.9, r: this.r * 0.45 }];
      },
      onDeath() {
        G.kills++; G.score += B.score * this.level;
        const R = World.regions[this.region]; R.locked = false; R.bossDone = true;
        for (const s of R.wall.sections) { s.hp = Math.min(s.hp, 10); s.max = 10; }
        for (let k = 0; k < 3; k++) World.dropAt(this.x + U.rand(-40, 40), this.y + U.rand(-40, 40), this.region, 3);
        if (B.foot) for (let k = 0; k < 2; k++) World.pickups.push(World.mkPickup(this.x + U.rand(-60, 60), this.y + U.rand(30, 70), 'weapon', 'spear', this.region)); // the Skull King is made of spears
        if (this.stash && this.stash.length) { for (const q of this.stash) { const a = U.rand(0, TAU), sp = U.rand(500, 1100); Object.assign(q, { alive: true, lift: null, x: this.x, y: this.y, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp, region: this.region }); World.pickups.push(q); } Combat.burst(this.x, this.y, 30, '#9bf06a', 3); G.msg(`Everything the beam took (${this.stash.length}) rains back down!`, '#9bf06a'); this.stash = null; } // the beam's haul comes back
        if (this.captives && this.captives.length) { for (const c of this.captives) World.pickups.push(World.mkPickup(this.x + U.rand(-70, 70), this.y + U.rand(-70, 70), 'weapon', c.id, this.region)); G.msg(`Your abducted ${this.captives.map(c => BULLETS[c.id].name).join(', ')} fall back to earth. Pick them up.`, '#9bf06a'); } // the Overlord gives back what it took
        if (B.car) { for (let k = 0; k < 2; k++) World.pickups.push(World.mkPickup(this.x + U.rand(-60, 60), this.y + U.rand(30, 70), 'weapon', 'pin', this.region)); for (let k = 0; k < 80; k++) Combat.particles.push(Combat.mk(this.x + U.rand(-40, 40), this.y + U.rand(-20, 20), U.rand(-260, 260), U.rand(-320, 40), U.rand(0.8, 1.6), U.pick(['#ffd166', '#ff5c8a', '#7df9ff', '#9bf06a', '#fff', '#f3c6ff']), 4)); SFX.play('trombone'); } // the car bursts into confetti and a sad trombone
        World.pickups.push(World.mkPickup(this.x, this.y - 50, 'cleanse', 'cleanse', this.region));
        for (const o of G.enemies) if (!o.dead && o !== this && (o.kind === 'stone' || (o.kind === 'bug' && (o.type === 'skull' || o.type === 'kamiclown') && o.region === this.region))) { o.dead = true; Combat.burst(o.x, o.y, 10, o.color, 3); } // its stones, skulls and clowns die with it
        G.shake = 1.0; G.flash = 0.7; SFX.play('die');
        G.winLevel(this.level);
      },
    };
    this.list.push(e);
    return e;
  },
  // rolling stone: an enemy with hp that soaks the player's bullets. Orbiting ones circle the crab; thrown ones roll at you.
  spawnStone(crab, mode, angle) {
    const B = BOSSES[crab.type];
    const e = {
      kind: 'stone', x: crab.x, y: crab.y, r: 20, hp: B.stoneHp, maxhp: B.stoneHp, armor: 0, power: B.stonePower, region: crab.region, st: {}, dead: false, flash: 0, hidden: false,
      color: '#a9a2b3', dark: '#5a5366', seed: Math.random() * 10, spin: 0, mode, owner: crab, orb: angle || 0, vx: 0, vy: 0, life: 6, stun: 0,
      hitCircles() { return [{ x: this.x, y: this.y, r: this.r }]; },
      onDeath() { Combat.burst(this.x, this.y, 12, '#a9a2b3', 3); SFX.play('wall'); },
    };
    if (mode === 'roll') { const sp = 280 + 20 * crab.level; e.vx = Math.cos(angle) * sp; e.vy = Math.sin(angle) * sp; }
    this.list.push(e);
    return e;
  },
  updateStone(e, dt) {
    const P = Player, c = e.owner;
    Combat.tickStatus(e, dt); if (e.dead) return;
    e.spin += dt * 6;
    if (e.mode === 'orbit') {
      if (c.dead) { e.dead = true; return; }
      e.orb += dt * 2.2;
      const R = c.r + 58;
      e.x = c.x + Math.cos(e.orb) * R; e.y = c.y + Math.sin(e.orb) * R;
    } else {
      e.x += e.vx * dt; e.y += e.vy * dt; e.life -= dt;
      const R = World.regions[e.region];
      if (e.life <= 0 || e.x < R.x0 + 30 || e.x > R.x1 - 30 || e.y < 40 || e.y > CFG.WORLD_H - 40) { e.dead = true; Combat.burst(e.x, e.y, 8, '#a9a2b3', 2); return; }
    }
    if (P.alive && P.invuln <= 0 && U.dist2(e.x, e.y, P.x, P.y) < (e.r + CFG.HEAD_R) ** 2) { P.hit(e.power, 'a rolling stone', e.x, e.y); if (e.mode === 'roll') { e.dead = true; Combat.burst(e.x, e.y, 10, '#a9a2b3', 3); } }
  },
  updateBoss(e, dt) {
    const P = Player, B = BOSSES[e.type], R = World.regions[e.region];
    const dust = B.foot ? '#d9d4c7' : B.serpent ? '#8fbf5a' : '#c9b48a';
    if (!e.awake) { // sleeps under the ground until you come close
      if (P.alive && Math.abs(P.x - e.x) < 1100) {
        e.awake = true; e.emerge = 2.2;
        e.maxhp = e.hp = Enemies.bossHp(B, e.level); // grows to match your legend
        G.msg(`${B.name} ${B.rise}! ${B.desc}.`, '#ff7a5c'); G.shake = 0.6; SFX.play('roar'); Music.start(Music.bossTheme(e.type, B));
      }
      return;
    }
    if (e.emerge > 0) { e.emerge -= dt; if (U.chance(dt * 20)) Combat.burst(e.x + U.rand(-60, 60), e.y + U.rand(-30, 40), 4, dust, 3); if (e.emerge <= 0) { e.hidden = false; Combat.burst(e.x, e.y, 40, dust, 4); SFX.play('scream'); for (let k = 0; k < (B.shieldN || 0); k++) this.spawnStone(e, 'orbit', k * TAU / B.shieldN); } return; }
    Combat.tickStatus(e, dt); if (e.dead) { Music.stop(); return; }
    e.legT += dt * 12; e.stun -= dt; e.flash -= dt; if (e.freezeCd > 0) e.freezeCd -= dt;
    const frozen = !!e.st.freeze, enraged = e.hp < e.maxhp * 0.3, frac = e.hp / e.maxhp;
    // the music climbs as the boss weakens: a semitone at 50%, another at 30%, fever at 20%; below 10% it cuts out and the crab runs (the skull goes frantic)
    const mood = frac < 0.1 ? 4 : frac < 0.2 ? 3 : frac < 0.3 ? 2 : frac < 0.5 ? 1 : 0;
    if (mood !== e.mood) {
      e.mood = mood;
      if (mood === 4) { Music.stop(); G.msg(B.foot ? `${B.name} has nothing left but the foot: it stomps in a frenzy!` : B.serpent ? `${B.name} is dying: it spits everything it has!` : B.alien ? `${B.name} is panicking: the mothership is leaving without it!` : B.car ? `${B.name} is falling apart: the clowns are abandoning ship!` : `${B.name} is terrified: it runs for it!`, '#ffd166'); SFX.play(B.serpent ? 'hiss' : B.car ? 'honk' : 'scream'); }
      else if (Music.on) Music.setMood(Math.min(3, mood), mood === 3);
    }
    if (B.foot) { this.updateSkull(e, dt, mood); return; }
    if (B.serpent) { this.updateSerpent(e, dt, mood); return; }
    if (B.alien) { this.updateOverlord(e, dt, mood); return; }
    if (B.car) { this.updateClownCar(e, dt, mood); return; }
    const fleeing = mood === 4;
    const dx = P.x - e.x, dy = P.y - e.y, d = Math.hypot(dx, dy) || 1, toP = Math.atan2(dy, dx);
    if (!(e.snip && (e.snip.phase !== 'charge' || e.snip.locked))) e.angle = U.angLerp(e.angle, toP, (e.snip ? 6 : 2.5) * dt); // always faces you (and holds its aim through a strike)
    // sidestep incoming bullets: a short burst perpendicular to the shot
    e.evadeT -= dt;
    if (e.dodge > 0) e.dodge -= dt;
    else if (e.evadeT <= 0 && !frozen) {
      for (const p of Combat.projectiles) {
        if (p.owner !== 'player' || p.kind === 'orbit' || p.kind === 'pulse') continue;
        const px = e.x - p.x, py = e.y - p.y, dd = Math.hypot(px, py);
        if (dd > 220 || dd < 10) continue;
        const sp = Math.hypot(p.vx, p.vy) || 1, dot = (p.vx * px + p.vy * py) / (sp * dd);
        if (dot > 0.92) { if (!U.chance(B.evadeChance)) { e.evadeT = 0.4; break; } e.dodge = 0.28; e.dodgeDir = ((p.vx * py - p.vy * px) > 0 ? -1 : 1); e.evadeT = B.evadeCd * (enraged ? 0.6 : 1); Combat.burst(e.x, e.y + e.r * 0.6, 6, '#c9b48a', 2); SFX.play('scream'); break; }
      }
    }
    // movement: crabs walk sideways. Keep ~380px from you, strafing, with a dodge burst on top
    let mx = 0, my = 0;
    e.strafeT -= dt; if (e.strafeT <= 0) { e.strafeT = U.rand(1.5, 3.5); e.strafe = -e.strafe; }
    const sideX = -Math.sin(toP) * e.strafe, sideY = Math.cos(toP) * e.strafe;
    const want = enraged ? B.keep * 0.75 : B.keep;
    const radial = fleeing ? -1 : d > want + 60 ? 1 : d < want - 60 ? -1 : 0; // fleeing: always away from you
    mx = sideX * 0.8 + Math.cos(toP) * radial * 0.7; my = sideY * 0.8 + Math.sin(toP) * radial * 0.7;
    let sp = e.speed * (fleeing ? 1.7 : enraged ? 1.4 : 1) * (e.stun > 0 ? 0.3 : 1) * (e.st.slow ? 0.5 : 1);
    if (e.dodge > 0) { mx = -Math.sin(toP) * e.dodgeDir; my = Math.cos(toP) * e.dodgeDir; sp = 620; }
    if (!frozen && !e.snip) { e.x += mx * sp * dt; e.y += my * sp * dt; }
    e.x = U.clamp(e.x, R.x0 + 120, R.x1 - 120); e.y = U.clamp(e.y, 90, CFG.WORLD_H - 90);
    // the crab's voice: a stomp on every step, and a digital scream now and then while it is on the move (constantly when enraged)
    e.stepT = (e.stepT || 0) - dt; e.screamT = (e.screamT || 0) - dt;
    if (!frozen && (mx || my) && Math.abs(P.x - e.x) < 1300) {
      if (e.stepT <= 0) { e.stepT = e.dodge > 0 ? 0.12 : 0.42 / (enraged ? 1.4 : 1); SFX.play('stomp'); }
      if (e.screamT <= 0) { e.screamT = enraged ? U.rand(1.2, 2.2) : U.rand(3.5, 6); SFX.play('scream'); }
    }
    // throw parasites
    e.throwT -= dt;
    if (!frozen && !fleeing && e.throwT <= 0 && P.alive) {
      e.throwT = B.throwCd * (enraged ? 0.6 : 1);
      const n = B.throwN + (enraged ? 2 : 0);
      for (let k = 0; k < n; k++) {
        const id = U.pick(Object.keys(PARASITES)), a = toP + (k - (n - 1) / 2) * 0.25;
        const pz = this.spawnParasite(id, e.x + Math.cos(a) * e.r, e.y + Math.sin(a) * e.r, e.region);
        pz.hp = pz.maxhp = B.throwHp + B.throwHpL * (e.level - 1); // thrown ones are fresh hatchlings, not region-scaled brutes
        pz.huntT = 1.5; pz.tossT = 0.6; pz.vx = Math.cos(a) * 520; pz.vy = Math.sin(a) * 520; pz.toss = { vx: pz.vx, vy: pz.vy };
      }
      e.clawT = 0.5; G.msg(`${B.name} hurls parasites!`, '#c58aff'); SFX.play('roar');
    }
    // stones: refill the orbiting shield, and roll a stone at you
    e.stoneT -= dt; e.shieldT -= dt;
    const shields = this.list.filter(o => o.kind === 'stone' && !o.dead && o.owner === e && o.mode === 'orbit').length;
    if (shields < B.shieldN && e.shieldT <= 0) { e.shieldT = B.shieldRefill; this.spawnStone(e, 'orbit', U.rand(0, TAU)); Combat.burst(e.x, e.y, 8, '#a9a2b3', 2); }
    if (!frozen && e.stoneT <= 0 && P.alive) { e.stoneT = B.stoneCd * (enraged ? 0.5 : 1); const q = P.predict(d / (280 + 20 * e.level)); this.spawnStone(e, 'roll', Math.atan2(q.y - e.y, q.x - e.x)); e.clawT = 0.4; SFX.play('scream'); SFX.play('wall'); G.msg('A stone rolls your way!', '#ddd'); }
    // the pincer STRIKE: every few seconds it stops and one claw CHARGES (a glow shrinking onto it, a rising whine), then it
    // LUNGES at you if you are close or SHOOTS the claw out on its arm if you are not. A claw that closes on you snips you in half
    if (e.snipCd === undefined) e.snipCd = 4;
    e.snipCd -= dt;
    if (!e.snip && !frozen && !fleeing && P.alive && e.snipCd <= 0 && d < 600) {
      const mode = d < 300 ? 'lunge' : 'shoot', aimed = G.nightmare || e.level >= (B.aimLevel || 99);
      e.snip = { phase: 'charge', t: 0, side: U.chance(0.5) ? 1 : -1, mode, aimed, locked: !aimed, tx: P.x, ty: P.y, ch: enraged ? 0.7 : 0.95 }; // a plain crab locks on where you are NOW (lunge or shot): move during the wind-up and it misses
      if (!aimed) e.angle = toP; // it squares up to you as the claw lights, then holds that line
      SFX.play('charge'); G.msg(`${B.name} raises a claw...`, '#ff7a5c');
    }
    if (e.snip) this.crabStrike(e, B, dt, toP, frozen, enraged);
    if (e.clawT > 0) e.clawT -= dt;
    // contact
    if (P.alive && P.invuln <= 0 && U.dist2(e.x, e.y, P.x, P.y) < (e.r + CFG.HEAD_R) ** 2) { P.hit(e.power, `the ${B.name}`, e.x, e.y); e.stun = 0.5; }
  },
  // the stomp (the Skull King's foot, the Overlord's saucer): a shadow grows where you are heading, then it comes down
  tickStomp(e, dt, B, R, frozen, rate, d) {
    const P = Player;
    e.stompT -= dt;
    if (e.stomp) {
      const S = e.stomp; S.t -= dt;
      if (S.phase === 'warn' && S.t <= 0) { S.phase = 'down'; S.t = S.ship ? 0.35 : 0.22; }
      else if (S.phase === 'down' && S.t <= 0) { S.phase = 'hold'; S.t = S.ship ? 0.9 : 0.5; this.stompLand(e, S); }
      else if (S.phase === 'hold' && S.t <= 0) { S.phase = 'up'; S.t = S.ship ? 0.8 : 0.5; }
      else if (S.phase === 'up' && S.t <= 0) e.stomp = null;
    } else if (!frozen && e.stompT <= 0 && P.alive && d < 900) {
      e.stompT = B.stompCd * rate;
      const q = P.predict(B.stompWarn * 0.85); // aim where you will be when it lands: straight on, or round the circle you are running
      e.stomp = { x: U.clamp(q.x, R.x0 + 80, R.x1 - 80), y: U.clamp(q.y, 80, CFG.WORLD_H - 80), t: B.stompWarn, warn: B.stompWarn, phase: 'warn', r: B.stompR, ship: !!B.alien };
      SFX.play(B.alien ? 'beam' : 'roar'); G.msg(B.alien ? 'The SAUCER is coming down on you: get out of the shadow!' : 'A SKELETAL FOOT looms: get out of the shadow!', '#ffd166');
    }
  },
  stompLand(e, S) {
    const P = Player, B = BOSSES[e.type];
    G.shake = 1.0; SFX.play('wall'); SFX.play('stomp'); SFX.play('die'); Combat.burst(S.x, S.y, 40, '#d9d4c7', 4); Combat.burst(S.x, S.y, 20, '#6a6456', 3);
    G.ring = { x: S.x, y: S.y, r: 0, max: S.r, t: 0.6 };
    if (P.alive && P.invuln <= 0) {
      let hit = U.dist2(P.x, P.y, S.x, S.y) < (S.r + CFG.HEAD_R) ** 2;
      if (!hit) for (const s of P.segPos) if (U.dist2(s.x, s.y, S.x, S.y) < (S.r + CFG.SEG_R) ** 2) { hit = true; break; }
      if (hit) P.hit(B.stompDmg, 'the skeletal foot', S.x, S.y);
    }
    for (const o of G.enemies) { if (o === e || o.dead || o.hidden || o.kind === 'boss') continue; if (U.dist2(o.x, o.y, S.x, S.y) < (S.r + o.r) ** 2) { if (o.minion) Minions.hurt(o, 40, e); else Combat.damage(o, 40, '#d9d4c7'); } } // it squashes anything under it: its own skulls, your minions, charmed monsters
    for (let k = 0; k < (B.stompShards || 0); k++) Combat.fireEnemy(S.x, S.y, k * TAU / B.stompShards + G.time, { speed: 260, power: 1, size: 5, color: '#eae4d4', life: 1.6 }); // bone shards fly out
  },
});
