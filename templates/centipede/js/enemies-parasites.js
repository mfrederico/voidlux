// Parasites: the spiders that sprint at a segment and latch on, the Broodmother, and allied (Hive Mind) parasites.
// Extends Enemies (see enemies.js). Loads after it.
Object.assign(Enemies, {
  // purple spider that sprints at your nearest segment and latches on, jamming it for a while. Shootable.
  spawnParasite(id, x, y, region) {
    const Pz = PARASITES[id], hp = this.nmHp(Pz.hp + Pz.hpR * this.hpScale(region));
    const e = {
      kind: 'parasite', id, x, y, angle: U.rand(0, TAU), speed: Pz.speed + Pz.speedR * region, hp, maxhp: hp, r: 11, power: 0,
      st: {}, region, dead: false, flash: 0, color: Pz.color, dark: Pz.dark, seed: Math.random() * 10, legT: 0, wanderT: 0, stun: 0, hidden: false, vx: 0, vy: 0, huntT: 0, ti: -2, ally: 0, spitT: 0.5, // ti = segment it has picked to bite (-1 head, -2 none yet)
      hitCircles() { return [{ x: this.x, y: this.y, r: this.r }]; },
      onDeath() { G.kills++; G.score += 8 * (this.region + 1); World.loot(this, { chance: 0.3, tier: 1, n: 1 }); },
    };
    this.list.push(e);
    return e;
  },
  // Hive Mind: an allied parasite hunts the nearest enemy, spits at it, and explodes when its time is up
  updateAlly(e, dt) {
    e.ally -= dt; e.legT += dt * 14;
    if (e.ally <= 0) { if (Minions.adopt(e)) return; e.dead = true; Combat.burst(e.x, e.y, 20, e.color, 3); Combat.burst(e.x, e.y, 10, '#fff', 2); SFX.play('wall'); for (const o of G.enemies) { if (o.dead || o.ally > 0) continue; if (U.dist2(o.x, o.y, e.x, e.y) < (110 + o.r) ** 2) Combat.damage(o, 14 * G.powerMult, e.color); } return; }
    let best = null, bd = 1e12;
    for (const o of G.enemies) { if (o.dead || o.hidden || o.ally > 0 || o === e) continue; const d2 = U.dist2(o.x, o.y, e.x, e.y); if (d2 < bd) { bd = d2; best = o; } }
    const P = Player;
    if (best) {
      const d = Math.sqrt(bd), want = Math.atan2(best.y - e.y, best.x - e.x);
      e.angle = U.angLerp(e.angle, d > 140 ? want : want + Math.PI / 2, 6 * dt);
      if (d > 140) { e.x += Math.cos(e.angle) * e.speed * dt; e.y += Math.sin(e.angle) * e.speed * dt; }
      e.spitT -= dt;
      if (e.spitT <= 0 && d < 520) { e.spitT = 0.55; Combat.fire(e.x, e.y, want, BULLETS.bullet, G.powerMult * 0.7, null); Combat.burst(e.x, e.y, 3, e.color, 1.5); }
    } else { // nothing to fight: tag along behind the player
      const d = U.dist(e.x, e.y, P.x, P.y); if (d > 90) { e.angle = U.angLerp(e.angle, Math.atan2(P.y - e.y, P.x - e.x), 6 * dt); e.x += Math.cos(e.angle) * e.speed * 0.8 * dt; e.y += Math.sin(e.angle) * e.speed * 0.8 * dt; }
    }
  },
  updateParasite(e, dt) {
    const P = Player, R = World.regions[e.region] || World.regions[World.regionAt(e.x)];
    if (e.minion) { Minions.updateWorld(e, dt); return; }
    if (e.ally > 0) { this.updateAlly(e, dt); return; }
    if (e.unique === 'brood' && P.alive && !e.st.freeze) { // the Broodmother keeps her distance and births hatchlings
      e.broodT -= dt; e.legT += dt * 6;
      const d = U.dist(P.x, P.y, e.x, e.y), to = Math.atan2(P.y - e.y, P.x - e.x);
      e.angle = U.angLerp(e.angle, d < 260 ? to + Math.PI : d > 420 ? to : to + Math.PI / 2, 3 * dt);
      const sp = e.speed * (e.st.slow ? 0.5 : 1); e.x += Math.cos(e.angle) * sp * dt; e.y += Math.sin(e.angle) * sp * dt;
      this.contain(e, 40);
      Combat.tickStatus(e, dt); if (e.dead) return;
      if (e.broodT <= 0 && d < 900) { e.broodT = UNIQUES.brood.spawnCd; const h = this.spawnParasite(U.pick(Object.keys(PARASITES)), e.x, e.y + 20, e.region); h.hp = h.maxhp = 10 + 4 * this.hpScale(e.region); h.huntT = 1; Combat.burst(e.x, e.y, 10, e.color, 2); SFX.play('parasite'); }
      return;
    }
    if (e.tossT > 0) { // thrown by the crab: sails through the air, then lands and hunts
      e.tossT -= dt; e.x += e.toss.vx * dt; e.y += e.toss.vy * dt; e.toss.vx *= 0.97; e.toss.vy *= 0.97; e.legT += dt * 30;
      this.contain(e, 40);
      if (e.tossT <= 0) Combat.burst(e.x, e.y, 6, '#c9b48a', 2);
      return;
    }
    Combat.tickStatus(e, dt);
    if (e.st.freeze && P.alive && !e.unique && U.dist2(e.x, e.y, P.x, P.y) < (e.r + CFG.HEAD_R + 4) ** 2) { // head-butt a frozen parasite: it imprints on you
      if (Minions.count() < Minions.cap()) { Minions.tame(e); Combat.burst(e.x, e.y, 18, '#9bf06a', 3); Combat.burst(e.x, e.y, 8, '#fff', 2); SFX.play('minion'); G.msg(`You head-butted a frozen ${PARASITES[e.id].name}: it imprints on you and joins as a minion (${Minions.count()}/${Minions.cap()}).`, '#9bf06a'); return; }
      if (!e.tameWarned) { e.tameWarned = true; G.msg(`That frozen ${PARASITES[e.id].name} could be tamed, but your roster is full (${Minions.count()}/${Minions.cap()}).`, '#c58aff'); }
    }
    if (e.dead) return;
    if (e.st.hypno && e.hypnoTree) { this.updateHypno(e, dt); return; }
    e.legT += dt * 12;
    // each parasite picks a random clean segment and goes for that one (the head only if nothing else is left)
    const valid = e.ti >= 0 && P.segments[e.ti] && !P.segments[e.ti].para;
    if (!valid) { const clean = []; for (let j = 0; j < P.segments.length; j++) if (!P.segments[j].para) clean.push(j); e.ti = clean.length ? U.pick(clean) : -1; }
    const ti = e.ti, q = ti >= 0 && P.segPos[ti] ? P.segPos[ti] : P, tx = q.x, ty = q.y;
    const d = U.dist(tx, ty, e.x, e.y) || 1, hunting = P.alive && d < 720;
    // creep, then ramp up to a sprint the longer it has you in sight
    e.huntT = hunting ? Math.min(PARASITE_RAMP, e.huntT + dt) : Math.max(0, e.huntT - dt * 2);
    const ramp = 0.3 + 0.7 * (e.huntT / PARASITE_RAMP);
    e.legT += dt * 10 * ramp;
    if (hunting) { e.angle = U.angLerp(e.angle, Math.atan2(ty - e.y, tx - e.x), 6 * dt); const k = U.clamp(1 - (d - 40) / 600, 0, 1) * (0.4 + 0.6 * ramp); if (k > this.threat) this.threat = k; if (R && R.farm && U.chance(dt * 0.25)) SFX.play('baa'); } // (farm parasites are sheep, and sheep bleat)
    else { e.wanderT -= dt; if (e.wanderT <= 0) { e.wanderT = U.rand(0.8, 2); e.angle += U.rand(-1.5, 1.5); } }
    if (!e.st.freeze) { const sp = hunting ? e.speed * ramp * (e.stun > 0 ? 0.3 : 1) : e.speed * 0.3; e.x += Math.cos(e.angle) * sp * dt; e.y += Math.sin(e.angle) * sp * dt; }
    e.stun -= dt;
    this.contain(e, 40);
    // latch on
    if (P.alive && d < e.r + (ti < 0 ? CFG.HEAD_R : CFG.SEG_R)) {
      if (P.giant > 0) { Combat.damage(e, e.hp + 1, '#ffd166'); return; } // a giant (or a tractor) is no place to latch on: squashed
      if (G.perks.includes('brood') && !e.unique && Minions.count() < Minions.cap() && Minions.tame(e)) { // the Brood perk: with a minion slot free, a parasite that reaches you joins you instead
        Combat.burst(e.x, e.y, 16, '#9bf06a', 3); SFX.play('minion'); G.msg(`Your Brood absorbed the ${PARASITES[e.id].name}: it rides on your tail as a minion (${Minions.count()}/${Minions.cap()}).`, '#9bf06a');
      } else { e.dead = true; P.infest(ti, e.id); Combat.burst(e.x, e.y, 12, e.color, 2); }
    }
  },
});
