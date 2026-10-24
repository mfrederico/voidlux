// Overworld enemies: chasing snakes (some spit), the bug family, and parasite spiders that try to latch on.
const Enemies = {
  list: [], skitterT: 0, threat: 0,
  reset() { this.list = []; this.skitterT = 0; this.threat = 0; },
  nmHp(hp) { return G.nightmare ? Math.round(hp * CFG.NIGHTMARE.hp) : hp; }, // nightmare: every monster is tougher
  // keep a monster inside its region, m px off the walls, unless the wall it is pressing on is breached at its height: then it
  // spills through and belongs to the next region (whose spawner counts it there, while the region it left fills back up).
  // Returns true when an intact wall stopped it. Bosses keep their arenas (their own clamps)
  contain(e, m) {
    const R = World.regions[e.region] || World.regions[World.regionAt(e.x)]; e.y = U.clamp(e.y, 50, CFG.WORLD_H - 50); if (!R) return false;
    const L = World.regions[R.idx - 1], N = World.regions[R.idx + 1], open = W => !!W && !W.sections[U.clamp(Math.floor(e.y / CFG.WALL_SECTION), 0, W.sections.length - 1)].alive;
    let stopped = false;
    if (e.x < R.x0 + m && !(L && open(L.wall))) { e.x = R.x0 + m; stopped = true; }
    else if (e.x > R.x1 - m && !(N && open(R.wall))) { e.x = R.x1 - m; stopped = true; }
    if (e.x < R.x0 && L) e.region = L.idx; else if (e.x > R.x1 && N) e.region = N.idx;
    return stopped;
  },
  // the "region" that hp formulas see: linear through level 1, then only CFG.HP_CURVE of each further region, so a
  // level-3 monster is tough but not eight times a level-1 one (your damage grows by perks, fusions and the level ramp, not that fast)
  hpScale(region) { const L = CFG.BOSS_EVERY - 1; return region <= L ? region : L + (region - L) * CFG.HP_CURVE; },
  // rare roll for a fresh spawn: chance rises per region (CFG.RARE)
  rollRare(region) { return U.chance(Math.min(CFG.RARE.max, CFG.RARE.chance + CFG.RARE.chanceR * region)); },
  // apply an affix to a freshly spawned snake or bug: bigger, faster, armored or trigger-happy, with a name and gold glow
  makeRare(e, affix) {
    const A = AFFIXES[affix || U.pick(Object.keys(AFFIXES))];
    e.rare = A; e.affixName = A.name; e.maxhp = e.hp = Math.round(e.hp * A.hp); e.r = Math.round(e.r * A.r); e.speed *= A.speed; e.power += A.power;
    if (A.armor !== undefined) e.armor = A.armor;
    if (A.shots) { e.extraShots = A.shots; e.cdMult = A.cdMult; if (e.kind === 'snake') e.spit = true; }
    if (e.kind === 'snake') e.segN = Math.min(14, e.segN + (A.r > 1.2 ? 4 : 0));
    const base = e.onDeath;
    e.onDeath = function () { base.call(this); World.dropDots(this.x, this.y, this.region, U.randi(CFG.DOT_DROP.rare[0], CFG.DOT_DROP.rare[1])); G.msg(`${A.name} ${this.baseName} slain!`, A.color); };
    e.label = `${A.name} ${e.baseName}`;
    return e;
  },
  // a snake variant's speed and spit: CFG.SNAKE, with the bestiary's overrides for that variant (bone, python, balloon, snake)
  snakeStats(variant) { const o = (typeof BESTIARY !== 'undefined' && BESTIARY.stats && BESTIARY.stats['snake-' + variant]) || {}, S = { ...CFG.SNAKE, spawn: 1 }; for (const k of Object.keys(S)) if (typeof o[k] === 'number') S[k] = o[k]; if (typeof o.cd === 'number') S.spitCd = o.cd; if (typeof o.range === 'number') S.spitRange = o.range; if (typeof o.shotSpeed === 'number') S.spitSpeed = o.shotSpeed; return S; }, // (the editor's names: cd, range, shotSpeed)
  // spawn rates (the bestiary's Spawn x): which snake a region grows (by level), how common it is, and the parasites' kinds
  snakeVariantAt(region) { const L = World.levelOf(region); return L === 2 ? 'bone' : L === 3 ? 'python' : L >= 5 ? 'balloon' : 'snake'; },
  snakeSpawnAt(region) { return this.snakeStats(this.snakeVariantAt(region)).spawn; },
  paraSpawn: {}, // parasite kind: x its share (set at load from the bestiary)
  paraSpawnAvg() { const ks = Object.keys(PARASITES); return ks.reduce((a, k) => a + (this.paraSpawn[k] ?? 1), 0) / ks.length; },
  pickParasite() { const ks = Object.keys(PARASITES), w = ks.map(k => this.paraSpawn[k] ?? 1), t = w.reduce((a, b) => a + b, 0); if (t <= 0) return null; let r = Math.random() * t; for (let i = 0; i < ks.length; i++) { r -= w[i]; if (r <= 0) return ks[i]; } return ks[ks.length - 1]; },
  // nearest other enemy for a berserk one to attack
  rageTarget(e) {
    let best = null, bd = 1e12;
    for (const o of G.enemies) { if (o === e || o.dead || o.hidden || o.ally > 0) continue; const d2 = U.dist2(o.x, o.y, e.x, e.y); if (d2 < bd) { bd = d2; best = o; } }
    return best;
  },
  // a long body draws fire: monster cooldowns shrink by firePer for every fireSegs weapon segments past the starting body (capped)
  pressure() { const K = CFG.BALANCE, n = Math.max(0, Player.weaponCount() - CFG.MAX_SEGMENTS); return (G.nightmare ? CFG.NIGHTMARE.fire : 1) / Math.min(1 + K.fireCap, 1 + K.firePer * Math.floor(n / K.fireSegs)); },
  update(dt) {
    const P = Player;
    this.threat = 0; // how close the nearest hunting parasite is (0..1), set by updateParasite
    for (let i = this.list.length - 1; i >= 0; i--) {
      const e = this.list[i];
      if (e.dead) { this.list.splice(i, 1); continue; }
      if (Math.abs(e.x - P.x) > CFG.REGION_W * 1.3) continue; // far away: sleep
      e.flash -= dt; this.acting = e; // (what it fires, bites or latches with plays its attack sound: SFX.attack)
      const ox = e.x, oy = e.y;
      if (e.st && e.st.giddy && e.kind !== 'boss' && !(e.ally > 0) && !e.minion) { Combat.tickStatus(e, dt); if (!e.dead) this.giddy(e, dt); e.vx = (e.x - ox) / dt; e.vy = (e.y - oy) / dt; continue; } // laughing gas: no fighting, just wandering off
      if (e.kind === 'parasite') { this.updateParasite(e, dt); e.vx = (e.x - ox) / dt; e.vy = (e.y - oy) / dt; continue; }
      if (e.kind === 'boss') { this.updateBoss(e, dt); e.vx = (e.x - ox) / dt; e.vy = (e.y - oy) / dt; continue; }
      if (e.kind === 'stone') { this.updateStone(e, dt); e.vx = (e.x - ox) / dt; e.vy = (e.y - oy) / dt; continue; }
      if (e.kind === 'rock') continue; // a woken boulder: World.updateRock moves it
      if (e.mimeWall && (e.type !== 'mime' || e.st.hypno || e.st.berserk || e.ally > 0)) e.mimeWall = null; // the wall only holds while the mime is minding it
      if (e.ally > 0 && e.ally < 1e8) { this.updateCharmed(e, dt); e.vx = (e.x - ox) / dt; e.vy = (e.y - oy) / dt; continue; } // under a grave ghost's spell
      Combat.tickStatus(e, dt);
      if (e.dead) continue;
      if (e.st.hypno && e.hypnoTree) { this.updateHypno(e, dt); e.vx = (e.x - ox) / dt; e.vy = (e.y - oy) / dt; continue; } // listening to a singing tree
      const frozen = !!e.st.freeze;
      const R = World.regions[e.region] || World.regions[World.regionAt(e.x)];
      if (e.kind === 'snake') {
        e.stun -= dt; e.biteCd -= dt; e.spitCd -= dt;
        const T = e.st.berserk ? this.rageTarget(e) : null;
        if (!frozen) {
          if (T) { e.angle = U.angLerp(e.angle, Math.atan2(T.y - e.y, T.x - e.x), e.turn * 1.5 * dt); if (U.dist2(T.x, T.y, e.x, e.y) < (e.r + T.r + 2) ** 2) { Combat.damage(T, 12 * dt, '#ff7bff'); if (U.chance(dt * 6)) Combat.burst(T.x, T.y, 3, '#ff7bff', 2); } }
          else if (P.alive && e.stun <= 0) {
            const desired = Math.atan2(P.y - e.y, P.x - e.x);
            e.angle = U.angLerp(e.angle, desired, e.turn * dt);
          }
          const sp = (e.stun > 0 ? e.speed * 0.4 : e.speed) * (e.st.slow ? 0.5 : 1);
          e.x += Math.cos(e.angle) * sp * dt; e.y += Math.sin(e.angle) * sp * dt;
          if (e.spit && P.alive && e.spitCd <= 0 && U.dist2(e.x, e.y, P.x, P.y) < e.spitRange * e.spitRange) {
            e.spitCd = e.spitEvery * (e.cdMult || 1) * this.pressure();
            for (let k = 0; k < 1 + (e.extraShots || 0); k++) Combat.fireEnemy(e.x, e.y, Math.atan2(P.y - e.y, P.x - e.x) + (k - (e.extraShots || 0) / 2) * 0.2, { speed: e.spitSpeed, power: 1, size: 6, color: '#9ef04c' });
          }
        }
        // keep inside its region
        this.contain(e, 40);
        this.snakeTrail(e);
        // contact with player (a berserk snake ignores you)
        if (P.alive && P.invuln <= 0 && !T) {
          const HR = CFG.HEAD_R;
          if (U.dist2(e.x, e.y, P.x, P.y) < (e.r + HR) ** 2) {
            P.hit(e.power, 'a snake', e.x, e.y); e.stun = 1.2;
          } else {
            let bit = false;
            if (e.biteCd <= 0) {
              for (let j = 0; j < P.segPos.length; j++) {
                const s = P.segPos[j];
                if (U.dist2(e.x, e.y, s.x, s.y) < (e.r + CFG.SEG_R) ** 2) {
                  P.removeAt(j, 'bite'); e.biteCd = 1.6; e.grow(1); G.msg('A snake bit off a segment!', '#ff9b8b'); bit = true; break;
                }
              }
            }
            if (!bit) for (const s of e.segPos) {
              if (U.dist2(s.x, s.y, P.x, P.y) < (8 + HR) ** 2) { P.hit(1, 'a snake', s.x, s.y); break; }
            }
          }
        }
      } else this.updateBug(e, dt);
      e.vx = (e.x - ox) / dt; e.vy = (e.y - oy) / dt; // velocity for the player's aim lead
    }
    Minions.worldTick(dt);
    // the swarm hunts you: on top of its own moves every swarm bug homes in on the centipede, faster the longer it lives
    // (CFG.SWARM.chase per second, up to chaseMax x its speed), so running away only buys time
    const K = CFG.SWARM;
    for (const e of this.list) {
      if (!e.swarm || e.dead || e.hidden || e.ally > 0 || e.st.freeze || !P.alive) continue;
      e.swarmAge = (e.swarmAge || 0) + dt;
      const dx = P.x - e.x, dy = P.y - e.y, d = Math.hypot(dx, dy) || 1; if (d < 60) continue;
      const pull = e.speed * Math.min(K.chaseMax, 1 + K.chase * e.swarmAge) * (e.stun > 0 ? 0.3 : 1) * (e.st.slow ? 0.5 : 1) * (d > 500 ? 1 : 0.5); // (close in, it fights its own way)
      e.x += dx / d * pull * dt; e.y += dy / d * pull * dt; e.angle = U.angLerp(e.angle, Math.atan2(dy, dx), 4 * dt);
      this.contain(e, 40);
    }
    // skittering gets faster and louder as a parasite closes in
    this.skitterT -= dt;
    if (this.threat > 0 && this.skitterT <= 0) { this.skitterT = U.lerp(1.2, 0.16, this.threat); SFX.skitter(this.threat); }
  }
};

// the bestiary's speed and attack overrides (js/bestiary.js stats, from the editor), written onto the creature tables once at
// load: move speed (+ per region), attack cooldown, range, bullet speed, a parasite's hold, a boss's tempo (every ability
// cooldown scaled), and how often each kind spawns. Snakes read theirs at spawn (Enemies.snakeStats)
(function applyBestiaryStats() {
  const S = (typeof BESTIARY !== 'undefined' && BESTIARY.stats) || {};
  for (const [id, o] of Object.entries(S)) {
    const [fam, key] = id.split('-'), T = fam === 'bug' || fam === 'summon' ? BUGS[key] : fam === 'parasite' ? PARASITES[key] : fam === 'unique' ? UNIQUES[key] : fam === 'boss' ? BOSSES[key] : null;
    if (!T) continue;
    for (const f of ['speed', 'speedR', 'cd', 'range', 'hold']) if (typeof o[f] === 'number') T[f] = o[f];
    if (fam === 'unique' && typeof o.cd === 'number' && T.spawnCd) T.spawnCd = o.cd; // the Broodmother's 'attack': a hatchling every spawnCd s
    if (typeof o.spawn === 'number') { // Spawn x: a bug's weight, a parasite kind's share, a unique's chance (snakes: Enemies.snakeStats)
      if (fam === 'bug') { const w = T.weight; T.weight = r => w(r) * o.spawn; }
      else if (fam === 'parasite') Enemies.paraSpawn[key] = o.spawn;
      else if (fam === 'unique') T.chance = Math.min(1, T.chance * o.spawn);
    }
    if (typeof o.shotSpeed === 'number' && T.shot) T.shot.speed = o.shotSpeed;
    if (typeof o.tempo === 'number') for (const k of Object.keys(T)) if (/Cd$/.test(k) && typeof T[k] === 'number') T[k] *= o.tempo;
  }
})();
