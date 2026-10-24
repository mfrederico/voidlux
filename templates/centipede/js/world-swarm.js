// The SWARM (CFG.SWARM): stay too long in one region and a swarm of weak bugs pours in; the region seals until it is popped.
// Extends World (see world.js). Loads after it.
Object.assign(World, {
  // ---- the SWARM (CFG.SWARM): stay too long in one region and a swarm of weak bugs pours in, for the joy of popping them ----
  updateSwarm(dt, ri, R) {
    const K = CFG.SWARM, S = G.swarm;
    if (G.swarmCd > 0) G.swarmCd -= dt;
    if (!S) {
      if (G.swarmRegion !== ri) { G.swarmRegion = ri; G.swarmT = 0; } // the clock runs per region: moving on resets it
      if (R.boss || ri < K.minRegion || G.state !== 'playing' || !Player.alive || !Player.ready) return;
      G.swarmT = (G.swarmT || 0) + dt;
      if (G.swarmT < K.after || G.swarmCd > 0) return;
      // the race: a centipede half through a breach when the swarm comes. It waits until no part of you is in either wall's
      // gap (the clock keeps running, so it comes the moment you are clear), then the broken sections grow back around you
      const walls = this.swarmWalls(ri), P = Player, pts = [P, ...P.segPos], gap = CFG.WALL_T / 2 + CFG.SEG_R + 30;
      if (walls.some(w => pts.some(p => p && Math.abs(p.x - w.x) < gap))) return;
      const total = Math.round(K.count + K.perLevel * (G.level - 1));
      G.swarm = { phase: 'warn', t: K.warn, region: ri, total, spawned: 0, wave: 0, popped: 0, left: total, rebuilt: [] };
      for (const w of walls) for (const sec of w.sections) if (!sec.alive) { // the walls grow back (gold) until the swarm is gone
        sec.alive = true; sec.hp = sec.max; sec.swarmMade = true; G.swarm.rebuilt.push(sec);
        Combat.burst(w.x, (sec.y0 + sec.y1) / 2, 10, '#ffd166', 2.5);
      }
      if (G.swarm.rebuilt.length) SFX.play('wall');
      G.banner = { text: 'SWARM INCOMING', sub: `you lingered too long: ${total} of them are coming`, t: K.warn };
      G.msg(`A SWARM is gathering in ${this.regionName(R)}: ${total} weak bugs, a few nasty ones among them. ${G.swarm.rebuilt.length ? 'The broken walls grow back: they' : 'The walls'} stay sealed until you pop them all!`, '#ffd166');
      SFX.play('roar'); G.shake = Math.max(G.shake, 0.4);
      return;
    }
    S.t -= dt;
    if (S.phase === 'warn') { if (S.t <= 0) { S.phase = 'pour'; S.t = 0; } return; }
    if (S.t <= 0 && S.wave < K.waves) { // a wave from off screen, all round you
      const n = S.wave === K.waves - 1 ? S.total - S.spawned : Math.round(S.total / K.waves), P = Player, rad = Math.hypot(Render.viewW(), Render.viewH()) / 2 + 60, Rg = this.regions[S.region];
      for (let k = 0; k < n; k++) {
        const a = U.rand(0, TAU), d = rad + U.rand(0, 160);
        this.swarmBug(U.clamp(P.x + Math.cos(a) * d, Rg.x0 + 60, Rg.x1 - 60), U.clamp(P.y + Math.sin(a) * d, 70, CFG.WORLD_H - 70), S.region);
      }
      S.spawned += n; S.wave++; S.t = K.gap;
      if (S.wave === 1) { G.banner = { text: 'SWARM!', sub: 'pop them all', t: 1.4 }; SFX.play('scream'); }
    }
    const alive = Enemies.list.filter(e => e.swarm && !e.dead).length; S.left = alive + (S.total - S.spawned);
    if (S.wave >= K.waves && !alive) { // cleared: a fanfare and a score bonus for the lot
      const bonus = S.total * 10 * G.level; G.score += bonus;
      G.banner = { text: 'SWARM CLEARED', sub: `${S.popped} popped  ·  +${bonus} score`, t: 2.6 }; SFX.play('cleared');
      for (const w of this.swarmWalls(S.region)) for (const sec of w.sections) if (sec.swarmMade) { sec.alive = false; sec.swarmMade = false; Combat.burst(w.x, (sec.y0 + sec.y1) / 2, 16, '#a9a2b3', 3); } // the grown-back sections crumble: your breaches are open again
      if (S.rebuilt.length) SFX.play('wall');
      G.swarm = null; G.swarmT = 0; G.swarmCd = K.cooldown;
    }
  },
  swarmWalls(ri) { const R = this.regions[ri], L = this.regions[ri - 1]; return [L && L.wall, R && R.wall].filter(Boolean); }, // the two walls of region ri
  swarmBug(x, y, region) {
    const K = CFG.SWARM; let type = Enemies.pickBug(region);
    for (let k = 0; k < 6 && (BUGS[type].boss || !BUGS[type].speed); k++) type = Enemies.pickBug(region); // (no hornet queens, and nothing that cannot move: mimes, iguanas)
    if (BUGS[type].boss || !BUGS[type].speed) type = 'gnat';
    const e = Enemies.spawnBug(type, x, y, region, false);
    e.swarm = true; e.hidden = false; e.emerge = 0.35; e.lootMult = K.loot;
    if (U.chance(K.elite)) { Enemies.makeRare(e); e.swarmElite = true; } // the odd one at full strength, glowing gold: it drops its loot
    else { e.maxhp = e.hp = Math.max(1, Math.round(e.hp * K.hp)); e.cdMult = (e.cdMult || 1) / K.fire; }
    const base = e.onDeath; e.onDeath = function () { if (base) base.call(this); if (G.swarm) G.swarm.popped++; };
    return e;
  },
});
