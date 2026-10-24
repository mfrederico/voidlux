// Named uniques: which can appear where, spawning one (and nightmare's 1 in 10).
// Extends Enemies (see enemies.js). Loads after it.
Object.assign(Enemies, {
  // ---- named uniques: one of each alive at a time, built on a base enemy with a signature drop ----
  uniqueAlive(id) { return this.list.some(e => !e.dead && e.unique === id); },
  // which uniques can appear in region i (its level, its minRegion); in nightmare any number of each, else one of each at a time
  uniquePool(i) { const L = World.levelOf(i); return Object.keys(UNIQUES).filter(id => { const Q = UNIQUES[id]; return i >= Q.minRegion && (!Q.levels || Q.levels.includes(L)) && (G.nightmare || !this.uniqueAlive(id)); }); },
  spawnUnique(id, x, y, region) {
    const Q = UNIQUES[id]; if (this.uniqueAlive(id) && !G.nightmare) return null;
    let e;
    if (Q.base === 'snake') { e = this.spawnSnake(x, y, region, false); e.segN = Q.segN; e.spit = false; }
    else if (Q.base === 'bug') { e = this.spawnBug(Q.type, x, y, region, false); e.flee = !!Q.flee; e.emerge = 1.5; }
    else { e = this.spawnParasite(Q.id, x, y, region); e.broodT = Q.spawnCd * 0.5; }
    e.unique = id; e.label = Q.name; e.color = Q.color; e.dark = Q.dark; e.r = Q.r;
    e.maxhp = e.hp = Math.round((Q.hp + Q.hpR * this.hpScale(region)) * (G.nightmare ? CFG.NIGHTMARE.uniqueHp : 1)); e.speed = Q.speed; if (Q.armor) e.armor = Q.armor; if (Q.power) e.power = Q.power;
    if (Q.shots !== undefined) { e.extraShots = Q.shots; if (Q.base === 'snake') e.spit = true; } if (Q.cdMult) e.cdMult = Q.cdMult;
    e.onDeath = function () {
      G.kills++; G.score += 400 * (this.region + 1);
      World.loot(this, Q.loot); World.dropDots(this.x, this.y, this.region, Q.dots);
      if (Q.egg) World.pickups.push(World.mkPickup(this.x, this.y - 30, 'egg', 'egg', this.region));
      G.msg(`${Q.name} is slain!`, Q.color); G.shake = Math.max(G.shake, 0.4);
    };
    if (!G.uniqueSeen) G.uniqueSeen = new Set(); if (!G.uniqueSeen.has(id)) { G.uniqueSeen.add(id); G.msg(`${Q.name} is here: ${Q.desc}.`, Q.color); } // (introduced once a run: in nightmare they are everywhere)
    return e;
  },
  // nightmare: CFG.NIGHTMARE.uniques of the snakes and bugs that spawn are named uniques instead. True when it made one
  maybeUnique(x, y, region) { if (!G.nightmare || !U.chance(CFG.NIGHTMARE.uniques)) return false; const pool = this.uniquePool(region); if (!pool.length) return false; return !!this.spawnUnique(U.pick(pool), x, y, region); },
});
