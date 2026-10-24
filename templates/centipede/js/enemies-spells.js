// Spells on monsters: the grave ghost's charm (it fights for you, then bursts) and the singing tree's hypnosis.
// Extends Enemies (see enemies.js). Loads after it.
Object.assign(Enemies, {
  // giddy (laughing gas): it wanders off away from you in a giggling zigzag, and does nothing else until the poison wears off
  giddy(e, dt) {
    const P = Player, away = Math.atan2(e.y - P.y, e.x - P.x), seed = e.seed || 0;
    if (e.st.freeze) return;
    const want = away + Math.sin(G.time * 3 + seed * 7) * 0.9; e.angle = U.angLerp(e.angle || 0, want, 3 * dt);
    const sp = (e.speed || 100) * 0.55 * (e.st.slow ? 0.5 : 1); e.x += Math.cos(e.angle) * sp * dt; e.y += Math.sin(e.angle) * sp * dt;
    if (e.legT !== undefined) e.legT += dt * 8;
    this.contain(e, 40);
    if (e.kind === 'snake') this.snakeTrail(e);
    if (U.chance(dt * 4)) Combat.particles.push(Combat.mk(e.x + U.rand(-6, 6), e.y - (e.r || 12), U.rand(-15, 15), U.rand(-50, -25), 0.7, '#ffaad8', 3)); // giggles bubbling up
  },
  // ---- the grave ghost's spell: a monster fights for you for `secs` seconds, then bursts ----
  // (snakes and bugs run updateCharmed; parasites reuse the Hive Mind path, so a loyal one can even stay as a minion)
  charm(e, secs) {
    e.ally = secs; e.charmed = secs; e.stun = 0; e.windup = 0; if (e.st) delete e.st.berserk;
    if (e.kind === 'parasite') { e.huntT = 3; e.spitT = U.rand(0.2, 0.8); e.tossT = 0; }
    Combat.burst(e.x, e.y, 10, '#dfe6ff', 2);
  },
  updateCharmed(e, dt) {
    const P = Player, R = World.regions[e.region] || World.regions[World.regionAt(e.x)];
    e.ally -= dt; e.legT = (e.legT || 0) + dt * 12;
    if (e.ally <= 0) { this.charmBoom(e); return; }
    Combat.tickStatus(e, dt); if (e.dead) return;
    const T = this.rageTarget(e), B = e.kind === 'bug' ? BUGS[e.type] : null, frozen = !!e.st.freeze;
    if (T) {
      const td = U.dist(T.x, T.y, e.x, e.y), want = Math.atan2(T.y - e.y, T.x - e.x);
      e.angle = U.angLerp(e.angle, want, (e.kind === 'snake' ? e.turn * 1.5 : 5) * dt);
      if (!frozen) { e.x += Math.cos(e.angle) * e.speed * 1.15 * dt; e.y += Math.sin(e.angle) * e.speed * 1.15 * dt; }
      if (td < e.r + T.r + 2) { Combat.damage(T, (e.kind === 'snake' ? 12 : 9) * dt, '#dfe6ff'); if (U.chance(dt * 6)) Combat.burst(T.x, T.y, 3, '#dfe6ff', 2); }
      if (B) { if (e.windup > 0) { e.windup -= dt; if (e.windup <= 0) { this.shoot(e, B, T); e.shotCd = B.cd * (e.cdMult || 1) * 0.7; } } else if (td < B.range) { e.shotCd -= dt; if (e.shotCd <= 0) e.windup = 0.3; } }
      else if (e.spit) { e.spitCd -= dt; if (e.spitCd <= 0 && td < 480) { e.spitCd = 2; Combat.fireEnemy(e.x, e.y, want, { speed: 260, power: 1, size: 6, color: '#9ef04c', hostile: true, from: e }); } }
    } else { // nothing to fight: tag along behind you
      const d = U.dist(e.x, e.y, P.x, P.y);
      if (d > 110) { e.angle = U.angLerp(e.angle, Math.atan2(P.y - e.y, P.x - e.x), 4 * dt); if (!frozen) { e.x += Math.cos(e.angle) * e.speed * 0.9 * dt; e.y += Math.sin(e.angle) * e.speed * 0.9 * dt; } }
    }
    this.contain(e, 40);
    if (e.kind === 'snake') this.snakeTrail(e);
    if (e.type === 'skull' && U.chance(dt * 25)) this.skullFlame(e);
  },
  charmBoom(e) {
    const K = CFG.GRAVE; e.dead = true;
    Combat.burst(e.x, e.y, 28, '#dfe6ff', 4); Combat.burst(e.x, e.y, 14, e.color, 3); SFX.play('wall'); G.shake = Math.max(G.shake, 0.3);
    G.ring = { x: e.x, y: e.y, r: 0, max: K.blast, t: 0.6 };
    let hits = 0;
    for (const o of G.enemies) { if (o === e || o.dead || o.hidden || o.ally > 0) continue; const d = U.dist(o.x, o.y, e.x, e.y); if (d < K.blast + o.r) { Combat.damage(o, K.blastDmg * G.powerMult * (1 - 0.4 * d / (K.blast + o.r)), '#dfe6ff'); hits++; } }
    if (hits) G.msg(`The spell breaks: the ${(e.label || e.baseName || 'monster').toLowerCase()} bursts and hits ${hits}!`, '#dfe6ff');
  },
  // hypnotized by a singing tree: wander to it and sway there, harmless, until the song ends
  updateHypno(e, dt) {
    const t = e.hypnoTree, R = World.regions[e.region] || World.regions[World.regionAt(e.x)];
    e.legT = (e.legT || 0) + dt * 6; e.windup = 0;
    const d = U.dist(e.x, e.y, t.x, t.y), want = Math.atan2(t.y - e.y, t.x - e.x);
    if (d > t.r + 70 + (e.seed || 0) * 6) { e.angle = U.angLerp(e.angle, want, 3 * dt); if (!e.st.freeze && e.speed > 0) { e.x += Math.cos(e.angle) * e.speed * 0.55 * dt; e.y += Math.sin(e.angle) * e.speed * 0.55 * dt; } }
    else e.angle = U.angLerp(e.angle, want + Math.sin(G.time * 2 + (e.seed || 0)) * 0.6, 2 * dt); // swaying to the music
    this.contain(e, 40);
    if (e.kind === 'snake') this.snakeTrail(e);
    if (U.chance(dt * 1.5)) Combat.particles.push(Combat.mk(e.x + U.rand(-8, 8), e.y - e.r, U.rand(-8, 8), -35, 0.9, '#f3c6ff', 3));
  },
});
