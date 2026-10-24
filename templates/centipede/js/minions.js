// Minions: tamed parasites that ride your body as segments. A minion segment takes NO body slot (the cap counts
// weapons only), spits bullets at the nearest enemy from wherever it sits, and counts as a hit point like any
// segment (minions join at the tail, so they are the shield that wears down first). Sources: a Broodmother's egg,
// the Brood perk, a head-butted frozen parasite, and Hive Mind recruits that outlive their timer. Cap = CFG.MINIONS.max + Brood perks.
// The one time a minion leaves the body: a boss, a named unique or a beefy rare within reach makes the tail minion
// LAUNCH itself as a diver (a parasite entity with minion: true, ally forever) that detonates on contact.
// Minion segments are pseudo-bullets in BULLETS (ids minion_<parasite>), so the workbench, HUD and build code read them as any segment.
const Minions = {
  launchT: 0,
  reset() { this.launchT = 0; },
  cap() { return CFG.MINIONS.max + (G.mods && G.mods.minions || 0); },
  count() { return Player.segments.filter(s => BULLETS[s.id].minion).length; },
  all() { return Enemies.list.filter(e => e.minion && !e.dead); }, // divers in flight (never many)
  name(e) { return `${PARASITES[e.id || e.minion].name} minion`; },
  segName(id) { return `${PARASITES[id].name} minion`; },
  // add a minion segment at the tail. Returns false when the roster is full.
  add(id, quiet) {
    if (this.count() >= this.cap()) return false;
    id = id || U.pick(Object.keys(PARASITES));
    Player.segments.push({ kind: 'weapon', id: 'minion_' + id, cd: U.rand(0.2, 0.6), inflight: 0, jam: false, para: null, seed: Math.random() * 10 });
    Player.maxLen = Math.max(Player.maxLen, Player.segments.length); G.maxLen = Math.max(G.maxLen, Player.segments.length);
    Player.resolveBuild();
    if (!quiet) { const p = Player.segPos[Player.segments.length - 1] || Player; Combat.burst(p.x, p.y, 18, '#9bf06a', 3); Combat.burst(p.x, p.y, 8, '#fff', 2); SFX.play('minion'); }
    return true;
  },
  hatch(id, x, y) {
    if (!this.add(id)) return false;
    Combat.burst(x, y, 14, '#9bf06a', 3);
    G.msg(`A ${this.segName(id || Player.segments[Player.segments.length - 1].id.slice(7))} hatched onto your tail! It takes no slot, spits at enemies, and dives into bosses. Minions ${this.count()}/${this.cap()}`, '#9bf06a');
    return true;
  },
  // a Hive Mind recruit whose time is up joins the body instead of exploding, if there is room
  adopt(e) {
    if (this.count() >= this.cap() || !this.add(e.id)) return false;
    e.dead = true; Combat.burst(e.x, e.y, 14, '#9bf06a', 3); SFX.play('pickup');
    G.msg(`A ${this.segName(e.id)} stayed loyal: it climbs onto your tail (${this.count()}/${this.cap()}).`, '#9bf06a');
    return true;
  },
  // head-butting a frozen parasite: it climbs on
  tame(e) { if (!this.add(e.id)) return false; e.dead = true; return true; },
  // a minion segment spits at the nearest enemy (called from Player.fireFrom)
  spit(i, s, B) {
    const P = Player, p = P.segPos[i] || P;
    let best = null, bd = 520 * 520;
    for (const e of G.enemies) { if (e.dead || e.hidden || e.ally > 0 || e.kind === 'stone') continue; const d2 = U.dist2(e.x, e.y, p.x, p.y); if (d2 < bd) { bd = d2; best = e; } }
    if (!best) return false;
    Combat.fire(p.x, p.y, Math.atan2(best.y - p.y, best.x - p.x), BULLETS.bullet, G.powerMult * CFG.MINIONS.spitMult, null);
    Combat.burst(p.x, p.y, 2, B.color, 1.5); s.cd = CFG.MINIONS.spitCd;
    return true;
  },
  // every frame in the overworld: launch the tail minion at a big target in reach
  update(dt) {
    this.launchT -= dt;
    if (this.launchT > 0 || G.mode !== 'world' || !Player.alive) return;
    const P = Player, M = CFG.MINIONS;
    let i = -1; for (let k = P.segments.length - 1; k >= 0; k--) if (BULLETS[P.segments[k].id].minion) { i = k; break; }
    if (i < 0) return;
    let T = null, td = M.launchRange * M.launchRange;
    for (const o of G.enemies) {
      if (o.dead || o.hidden || o.ally > 0 || o.kind === 'stone' || o.kind === 'rock') continue;
      if (!(o.kind === 'boss' || o.unique || (o.rare && o.hp >= 50))) continue;
      const d2 = U.dist2(o.x, o.y, P.x, P.y); if (d2 < td) { td = d2; T = o; }
    }
    if (!T) return;
    this.launch(i, T);
  },
  launch(i, T) {
    const P = Player, s = P.segments[i], id = s.id.slice(7), p = P.segPos[i] || P;
    P.segments.splice(i, 1); P.resolveBuild();
    const e = Enemies.spawnParasite(id, p.x, p.y, World.regionAt(P.x));
    e.minion = true; e.ally = 1e9; e.ti = -2; e.kami = true; e.kamiT = 3.5; e.kamiTarget = T; e.hp = e.maxhp = CFG.MINIONS.hp; e.speed = CFG.MINIONS.speed; e.huntT = 0; e.tossT = 0; e.unique = null; e.label = null;
    this.launchT = CFG.MINIONS.launchCd;
    G.msg(`Your ${this.segName(id)} leaps off your tail at the ${(T.label || T.baseName || BOSSES[T.type]?.name || 'enemy').toLowerCase()}!`, '#ffd166'); SFX.play('parasite');
  },
  hurt(e, amount, by) {
    if (e.dead) return;
    e.hp -= amount; e.flash = 0.08;
    if (e.hp <= 0) { e.dead = true; Combat.burst(e.x, e.y, 16, e.color, 3); SFX.play('kill'); G.msg(`Your diving ${this.name(e)} was swatted${by ? ' by ' + (by.label || by.baseName || 'an enemy').toLowerCase() : ''}.`, '#ff9b8b'); }
  },
  explode(e, target) {
    const M = CFG.MINIONS; e.dead = true;
    Combat.burst(e.x, e.y, 30, e.color, 4); Combat.burst(e.x, e.y, 16, '#fff', 3); SFX.play('wall'); G.shake = Math.max(G.shake, 0.35);
    G.ring = { x: e.x, y: e.y, r: 0, max: M.blast, t: 0.6 };
    for (const o of G.enemies) { if (o.dead || o.hidden || o.ally > 0) continue; const d = U.dist(o.x, o.y, e.x, e.y); if (d < M.blast + o.r) Combat.damage(o, M.blastDmg * G.powerMult * (1 - 0.4 * d / (M.blast + o.r)), e.color); }
    G.msg(`Your ${this.name(e)} blew itself up${target ? ' on the ' + (target.label || target.baseName || BOSSES[target.type]?.name || 'enemy') : ''}! Minions ${this.count()}/${this.cap()}`, '#ffd166');
  },
  // a diver in flight (called from Enemies.updateParasite for e.minion)
  updateWorld(e, dt) {
    const T = e.kamiTarget && !e.kamiTarget.dead ? e.kamiTarget : null;
    e.kamiT -= dt; e.legT += dt * 20;
    if (!T || e.kamiT <= 0) { this.explode(e, null); return; }
    const want = Math.atan2(T.y - e.y, T.x - e.x); e.angle = U.angLerp(e.angle, want, 12 * dt);
    e.x += Math.cos(e.angle) * e.speed * 1.8 * dt; e.y += Math.sin(e.angle) * e.speed * 1.8 * dt;
    if (U.chance(dt * 30)) Combat.burst(e.x, e.y, 2, '#ffd166', 1.5);
    if (U.dist(T.x, T.y, e.x, e.y) < T.r + e.r + 4) this.explode(e, T);
  },
  // enemies bite divers they touch
  worldTick(dt) {
    for (const m of this.all()) for (const o of G.enemies) {
      if (o.dead || o.hidden || o.ally > 0 || o === m || o.kind === 'stone' || (o.kind === 'parasite' && !o.unique)) continue;
      if (U.dist2(o.x, o.y, m.x, m.y) < (o.r + m.r) ** 2) { this.hurt(m, (o.kind === 'boss' ? 20 : 8) * dt, o); if (U.chance(dt * 6)) Combat.burst(m.x, m.y, 2, '#ff9b8b', 1.5); }
    }
  },
  // divers are abandoned on a warp or a dungeon; segments come along by themselves
  recall() { for (const m of this.all()) m.dead = true; },
  enterDungeon() { this.recall(); },
};
