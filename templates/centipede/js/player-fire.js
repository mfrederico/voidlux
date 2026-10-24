// Firing: one bullet in flight per segment, the saucer deck's pellets, the wall as a target, aiming and leading shots.
// Extends Player (see player.js). Loads after it.
Object.assign(Player, {
  fireUpdate(dt) {
    // One bullet in flight per segment. A segment can't fire again until its bullet is gone.
    // If nothing on the body can fire (no segments, or all jammed), the head spits a weak bullet instead.
    const segs = this.segments, enemies = G.enemies;
    if (!this.firing || G.lich) return; // SPACE toggled weapons off (or the segments are off as the Lich's skulls)
    const storm = G.storm > 0; // Bullet Storm: ignore the one-bullet rule and most of the cooldown
    const canFire = segs.some(s => s.kind === 'weapon' && !s.jam && !this.isMinion(s)); // minion spit does not count: the head still backs you up
    if (!canFire !== this.spitting) { this.spitting = !canFire; if (this.spitting) G.msg('No bullets left! Spitting pebbles.', '#ffd166'); }
    const n = segs.length + 1;
    const loaded = (s) => s.cd <= 0 && (s.inflight < (s.maxInflight || 1) || storm) && !s.jam;
    for (let i = 0; i < n; i++) {
      const s = i < segs.length ? segs[i] : this.head;
      if (s.kind !== 'weapon') continue;
      s.cd -= dt;
      if (s === this.head && canFire) continue;
      if (!loaded(s)) continue;
      this.fireFrom(i, storm);
    }
  },
  // the saucer deck's pellet: a plain round shot straight up, no area, pierce, bounce, homing or orbit, but as strong as the
  // segment it comes from (its base DPS, delivered once per cooldown), in that segment's colour
  pellet(B) {
    if (!B.__pellet) B.__pellet = { name: B.name, kind: 'shot', pellet: true, tier: B.tier, craft: false, color: B.color, dark: B.dark, size: 5.5, speed: 860, range: 900, cd: B.cd,
      dmg: Math.max(1, Math.round(this.rawDps(B) * (B.cd + 0.55) * 10) / 10), pierce: 0, spread: 0 }; // (rawDps already counts the fusion's area and combos; a pellet's cycle is its cooldown plus ~0.55 s climbing the deck, one in the air per segment, so this keeps the segment's DPS)
    return B.__pellet;
  },
  firePellet(i, s, storm) {
    const segs = this.segments, B = BULLETS[s.id], p = i < segs.length ? (this.segPos[i] || this) : this;
    if (!B || !B.cd) return false;
    Combat.fire(p.x, p.y, -Math.PI / 2, this.pellet(B), G.powerMult * (this.shroom > 0 ? CFG.SHROOM.dmg : 1) * (this.tiny > 0 ? CFG.TINY.dmg : 1), s);
    s.cd = (B.cd + 0.55) * G.mods.cd * (storm ? 0.5 : 1) * U.rand(0.9, 1.1); SFX.play('shoot'); // a fixed rhythm matched to the pellet's damage: the segment's DPS, exactly
    return true;
  },
  // the wall as a target: a breakable wall within 300px with no gap level with your head. It is a secondary target (shot
  // when there is nothing else to hit) and, for 2.5s after you bump it, the main one (bump: true). Returns the point to
  // shoot (the nearest standing section) or null. Once per frame
  wallTarget() {
    if (G.mode !== 'world') return null;
    if (this._wtT === G.time) return this._wt; this._wtT = G.time; this._wt = null;
    const idx = Math.floor(this.x / CFG.REGION_W);
    for (const i of [idx - 1, idx]) {
      const R = World.regions[i]; if (!R || !R.wall || R.locked || (G.swarm && (i === G.swarm.region || i === G.swarm.region - 1))) continue; // sealed walls do not break: no point
      const w = R.wall; if (Math.abs(w.x - this.x) > 300) continue;
      const own = w.sections[U.clamp(Math.floor(this.y / CFG.WALL_SECTION), 0, w.sections.length - 1)]; if (!own || !own.alive) continue; // a gap right there: just go through
      let best = null, bd = 1e9; for (const s of w.sections) { if (!s.alive) continue; const cy = (s.y0 + s.y1) / 2, d = Math.abs(cy - this.y); if (d < bd) { bd = d; best = cy; } }
      if (best !== null) { this._wt = { x: w.x, y: best, bump: G.time - (this.wallBumpT || -9) < 2.5 }; break; }
    }
    return this._wt;
  },
  // fire segment i (or the head when i === segments.length). Returns true if it fired.
  fireFrom(i, storm, forcedAim) {
    const segs = this.segments, enemies = G.enemies, s = i < segs.length ? segs[i] : this.head;
    if (BULLETS[s.id].kind === 'minion') return Minions.spit(i, s, BULLETS[s.id]); // a riding minion spits its own bullets
    if (G.mode === 'deck' && forcedAim === undefined) return this.firePellet(i, s, storm); // the saucer deck: every segment fires a plain pellet carrying its power
    if (BULLETS[s.id].kind === 'trail') { // walkers: lay a patch of the element where this segment is, instead of shooting
      const B = BULLETS[s.id], p = i < segs.length ? (this.segPos[i] || this) : this;
      Combat.clouds.push({ x: p.x, y: p.y, r: B.r, t: B.life * G.mods.range, mag: B.dmg * G.powerMult * G.mods.elem * Combat.ramp(B), kind: B.trail, seed: Math.random() * 10, tick: 0 });
      s.cd = B.cd * G.mods.cd; if (U.chance(0.3)) Combat.burst(p.x, p.y, 2, B.color, 2);
      return true;
    }
    if (storm && Combat.projectiles.length > CFG.STORM_PROJ) return false; // a storm fills the air, up to a point (each bullet costs collision checks every frame)
    {
      const B = BULLETS[s.id], p = i < segs.length ? (this.segPos[i] || this) : this;
      const straight = G.mode === 'deck' && forcedAim === undefined; // the saucer deck: no auto-aim, every segment fires straight up the deck, so you aim by sliding
      let best = null, bd = (B.range || 420) ** 2;
      for (const e of enemies) {
        if (e.dead || e.hidden || e.ally > 0 || e.kind === 'stone' || !Render.aimable(e)) continue; // stones are obstacles, not targets: shoot the crab and let them intercept; off screen: not yet
        if (straight && (e.y > p.y || Math.abs(e.x - p.x) > e.r + 14)) continue; // (on the deck only what is right above this segment counts: a chain's first hop, a pulse's trigger)
        const d2 = U.dist2(e.x, e.y, p.x, p.y);
        if (d2 < bd) { bd = d2; best = e; }
      }
      const WT = !straight && forcedAim === undefined ? this.wallTarget() : null, mult = G.powerMult * (this.shroom > 0 ? CFG.SHROOM.dmg : 1) * (this.tiny > 0 ? CFG.TINY.dmg : 1) * (G.rubber > 0 ? 1.5 : 1);
      const idle = !best || (B.kind === 'pulse' && bd >= (150 * G.mods.range * G.mods.range) ** 2); // nothing for this segment to hit
      if (WT && (WT.bump || idle) && ['chain', 'pulse', 'mine', 'boomerang'].includes(B.kind)) { // the wall: what cannot break one fires a plain shot at it instead, at the segment's own power (its pellet, as on the saucer deck)
        Combat.fire(p.x, p.y, Math.atan2(WT.y - p.y, WT.x - p.x), this.pellet(B), mult, s);
        s.cd = (B.cd + 0.55) * G.mods.cd * (storm ? 0.5 : 1) * U.rand(0.9, 1.1); SFX.play('shoot'); return true;
      }
      if (WT && (WT.bump || idle)) best = null; // just bumped it: every gun turns on the wall; otherwise only what has nothing else to hit
      if (B.kind === 'mine' && !best) return false;           // mines only drop when something is around
      if (B.kind === 'chain' && !best) return false;          // a ricochet needs a first target
      if (B.kind === 'pulse' && !(best && bd < (150 * G.mods.range * G.mods.range) ** 2)) return false; // pulse only when enemies are close (as far as the ring reaches: Long Reach widens it)
      let ang = straight ? -Math.PI / 2 : forcedAim !== undefined ? forcedAim : this.angle;
      if (best && forcedAim === undefined && !straight) { // lead the shot: aim where the target will be when the bullet arrives
        const t = B.speed > 50 ? Math.sqrt(bd) / B.speed : 0, tx = best.x + (best.vx || 0) * t * 0.8, ty = best.y + (best.vy || 0) * t * 0.8;
        ang = Math.atan2(ty - p.y, tx - p.x);
      } else if (WT && !best) ang = Math.atan2(WT.y - p.y, WT.x - p.x);
      this._lastAim = ang;
      const shot = Combat.fire(p.x, p.y, ang, B, mult, s); // the mushroom, the potion and the rubber-chicken card weigh in
      if (shot && B.kind === 'chain') shot.target = best;
      if (shot && WT && !best && B.kind === 'homing') shot.wallAim = true; // a moth sent at the wall flies straight at it
      if (shot && s.pipe === 'burst') { const K = CFG.PIPE_BURST, q = Combat.fire(p.x, p.y, ang + (U.chance(0.5) ? K.spread : -K.spread), B, mult * K.mult, null); if (q) { q.extra = true; q.pipe = null; } } // +burst piping: a second shot beside it (no segment owns it, so the reload clock ignores it)
      if (G.rubber > 0 && U.chance(0.3)) SFX.play('squeak');
      s.cd = B.cd * G.mods.cd * (storm ? 0.5 : 1) * U.rand(0.85, 1.2); // a little jitter so segments don't fire in lockstep waves
      SFX.play('shoot');
      return true;
    }
  }
});
