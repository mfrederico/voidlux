// Snakes: spawning one (its skin by level: bone, python, balloon animal) and the trail its body follows.
// Extends Enemies (see enemies.js). Loads after it.
Object.assign(Enemies, {
  spawnSnake(x, y, region, rare) {
    const segN = 3 + Math.min(9, region + U.randi(0, 1));
    const spit = region >= 2 && U.chance(0.35);
    const R = World.regions[region], bone = !!(R && R.haunted), python = !!(R && R.jungle), balloon = !!(R && R.circus);
    const S = this.snakeStats(bone ? 'bone' : python ? 'python' : balloon ? 'balloon' : 'snake'); // graveyard snakes are skeletons, jungle ones pythons, circus ones balloon animals
    const e = {
      kind: 'snake', x, y, angle: U.rand(0, TAU), speed: S.speed + region * S.speedR, spitEvery: S.spitCd, spitRange: S.spitRange, spitSpeed: S.spitSpeed, turn: 2.4 + region * 0.08, segN: python ? segN + 2 : segN, bone, python, balloon,
      hp: this.nmHp(12 + 7 * this.hpScale(region)), maxhp: this.nmHp(12 + 7 * this.hpScale(region)), power: Math.max(1, Math.ceil(segN / 3)), r: 11, armor: 0,
      st: {}, biteCd: 0, stun: 0, spit, spitCd: U.rand(1, 3), region, dead: false, flash: 0, trail: [], segPos: [],
      color: balloon ? U.pick(['#ff5c8a', '#ffd166', '#7df9ff', '#9bf06a', '#f3c6ff', '#ff8a3d']) : bone ? (spit ? '#d8e2c8' : '#e8e2d2') : python ? (spit ? '#d9c84a' : '#5ec85a') : spit ? '#7fd36a' : '#ff7b6b', dark: balloon ? '#fff' : bone ? '#8f8778' : python ? '#2a5a24' : spit ? '#3f7a34' : '#b0392c', seed: Math.random() * 10, balloon,
      baseName: balloon ? 'Balloon Animal' : bone ? 'Bone Snake' : python ? 'Python' : 'Snake',
      hitCircles() { const c = [{ x: this.x, y: this.y, r: this.r }]; for (const s of this.segPos) c.push({ x: s.x, y: s.y, r: 8 }); return c; },
      onDeath() { G.kills++; G.score += 20 * (this.region + 1); World.loot(this, { chance: spit ? 0.75 : 0.65, tier: 2, n: 1 }); },
      // STUB: enemy growth hook (eat the player's lost segments). Not enabled.
      grow(n) { /* this.segN += n; */ },
    };
    e.trail = [{ x, y, d: 0 }];
    if (rare === undefined ? this.rollRare(region) : rare) this.makeRare(e);
    this.list.push(e);
    return e;
  },
  snakeTrail(e) {
    const t = e.trail, last = t[t.length - 1];
    const dd = U.dist(last.x, last.y, e.x, e.y);
    if (dd > 0.4) { t.push({ x: e.x, y: e.y, d: last.d + dd }); }
    const need = (e.segN + 2) * 12 + 30;
    while (t.length > 2 && t[t.length - 1].d - t[1].d > need) t.shift();
    const res = [], headD = t[t.length - 1].d;
    let j = t.length - 1;
    for (let i = 0; i < e.segN; i++) {
      const target = headD - (i + 1) * 12;
      while (j > 0 && t[j - 1].d > target) j--;
      if (j === 0) { res.push({ x: t[0].x, y: t[0].y }); continue; }
      const a = t[j - 1], b = t[j], span = b.d - a.d, f = span > 0 ? (target - a.d) / span : 0;
      res.push({ x: U.lerp(a.x, b.x, f), y: U.lerp(a.y, b.y, f) });
    }
    e.segPos = res;
  },
});
