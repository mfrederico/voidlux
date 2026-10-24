// Getting hurt: a lost segment bursts into its parts, shedding, parasites biting and shaken off, hits (and the hard head), dying.
// Extends Player (see player.js). Loads after it.
Object.assign(Player, {
  // ---- de-segmenting: a lost segment bursts into what it was made of ----
  // the base bullets a bullet is built from (itself, if it is not a fusion) and every dot the chain cost
  pieces(id, out, depth) {
    out = out || { parts: [], dots: 0 }; depth = depth || 0; if (depth > 6) return out;
    const parts = this.partsOf(id);
    if (!parts) { out.parts.push(id); return out; }
    out.dots += this.craftCost(id);
    for (const q of parts) this.pieces(q, out, depth + 1);
    return out;
  },
  // throw the pieces of a lost segment onto the ground, away from whatever hit you, on a fuse: grab them and the blueprint rebuilds it
  scatter(id, p, spray) {
    const pc = this.pieces(id), K = CFG.LOOSE, ri = World.regionAt(p.x), hit = !spray && this.lastHit && G.time - this.lastHit.t < 0.5 ? this.lastHit : null;
    // the stronger the build and the deeper the run, the further the pieces fly (log of the damage output, times the level): a monster build has to go and fetch
    const pot = Math.log2(1 + Math.max(0, this.estimateDps(1, 0.6)) / K.dpsRef), mult = Math.min(K.maxMult, (1 + K.dpsK * pot) * (1 + K.levelK * (G.level - 1)));
    const base = hit ? Math.atan2(p.y - hit.y, p.x - hit.x) : U.rand(0, TAU), n0 = World.pickups.length;
    pc.parts.forEach(pid => { const pk = World.mkPickup(p.x, p.y, 'weapon', pid, ri); pk.loose = true; World.pickups.push(pk); }); // loose: yours, always collectable
    if (pc.dots) World.dropDots(p.x, p.y, ri, pc.dots);
    const R = Perks.magnetRadius() * 1.5; // thrown at least half again past the magnet's reach, so you really do turn round for them
    const n = World.pickups.length - n0;
    for (let k = n0; k < World.pickups.length; k++) { const pk = World.pickups[k], a = spray ? (k - n0) * TAU / Math.max(1, n) + U.rand(-0.25, 0.25) : base + U.rand(-0.9, 0.9), d = U.rand(Math.max(K.toss * 0.6 * mult, R), Math.max(K.toss * 1.3 * mult, R * 1.3)) * 4 * (spray ? 0.85 : 1); pk.vx = Math.cos(a) * d; pk.vy = Math.sin(a) * d; if (K.time > 0) pk.fuse = K.time; } // a spray fans the pieces evenly all round; a crash throws them away from what you hit (speed decays by e^-4t: it travels d/4)
    const names = pc.parts.map(q => BULLETS[q].name).join(', ');
    const when = K.time > 0 ? ` for ${K.time}s` : '';
    G.msg(`${BULLETS[id].name} ${spray ? 'knocked out of you' : 'knocked off'}! ${pc.parts.length > 1 || pc.dots ? `Its pieces (${names}${pc.dots ? ` and ${pc.dots} dots` : ''}) ${spray ? 'sprang out' : mult > 2 ? 'flew far' : 'lie there'}${when}: grab them and it rebuilds itself.` : `It ${spray ? 'sprang out' : mult > 2 ? 'flew far' : 'lies there'}${when}: go and get it.`}`, '#ff9b8b');
  },
  loseTail(n, cause) {
    for (let k = 0; k < n && this.segments.length; k++) this.removeAt(this.segments.length - 1, cause);
  },
  // what you crashed INTO throws the segment's pieces away from it; what ATTACKED you sprays them out all round. Both are recoverable (a ghost's bite in a dungeon is not)
  CRASH: /tree|boulder|tombstone|cannon|coffin|barn|booth|hedge|wall|edge|stone|lake|water/i,
  isCrash(cause) { return this.CRASH.test(cause || ''); },
  cleanse() {
    let n = 0;
    for (let i = 0; i < this.segments.length; i++) if (this.segments[i].para) { this.shakeOff(i, true); n++; }
    this.headSlow = 0;
    this.resolveBuild();
    if (n) G.msg('Parasites cleansed!', '#fff');
  },
  segName(s) { return BULLETS[s.id].name; },
  segColor(s) { return BULLETS[s.id].color; },
  // a parasite bites segment i (-1 = the head, which just slows you)
  infest(i, id) {
    if (Enemies.acting) SFX.attack(Enemies.acting); // the parasite latching on
    const Pz = PARASITES[id];
    if (i < 0 || !this.segments[i]) { this.headSlow = Math.max(this.headSlow, 3); G.msg(`A ${Pz.name} bit your head! Slowed.`, '#c58aff'); }
    else { const hold = Pz.hold * G.mods.paraHold; this.segments[i].para = { id, t: hold, hold, seed: Math.random() * 10 }; G.msg(`${Pz.name} bit your ${this.segName(this.segments[i])}: ${Pz.desc}`, '#c58aff'); }
    this.resolveBuild();
    SFX.play('parasite'); G.shake = Math.max(G.shake, 0.25);
  },
  shakeOff(i, quiet) {
    const s = this.segments[i]; if (!s || !s.para) return;
    const p = this.segPos[i]; if (p) Combat.burst(p.x, p.y, 10, PARASITES[s.para.id].color, 2.5);
    if (!quiet) G.msg(`The ${PARASITES[s.para.id].name} died. ${this.segName(s)} is firing again.`, '#ddd');
    s.para = null;
  },

  hardHeadChance(n) { return n > 0 ? 1 - Math.pow(0.6, n) : 0; }, // Fast and Hard: the chance a crash just bounces you off (40%, 64%, 78%...)
  hit(power, cause, fromX, fromY) {
    if (this.invuln > 0 || !this.alive || this.flight || this.giant > 0) return false; // a cannonball, a giant and a tractor shrug everything off
    if (this.isCrash(cause) && (this.gear || Math.random() < this.hardHeadChance(G.mods.hardHead))) { // head gear is armour: a head-first crash always bounces
      if (this.gear && BULLETS[this.gear].gear.type === 'hammer' && /wall/.test(cause || '') && G.mode === 'world') { const w = World.wallAt(this.x, this.y, CFG.HEAD_R + 12); if (w) { World.damageWall(w, BULLETS[this.gear].gear.wallDmg * G.powerMult); Combat.burst(this.x, this.y, 12, '#a9b0bc', 3); } } // the Front Hammer chips the wall it hits // a hard head: BONK, bounced off, nothing lost
      if (fromX !== undefined) this.angle = Math.atan2(this.y - fromY, this.x - fromX);
      this.invuln = 0.35; this.bonkAt = G.time; G.shake = Math.max(G.shake, 0.12); SFX.play('stomp'); SFX.tone(1900, 1400, 0.12, 'triangle', 0.3); // a clang on the helmet
      for (let k = 0; k < 10; k++) { const a = this.angle + U.rand(-1.3, 1.3), v = U.rand(120, 260); Combat.particles.push(Combat.mk(this.x + Math.cos(this.angle) * 12, this.y + Math.sin(this.angle) * 12, Math.cos(a) * v, Math.sin(a) * v, 0.35, U.pick(['#fff', '#ffe08a', '#ffd166']), 2.5)); } /* sparks off the front */ Combat.floaters.push({ x: this.x, y: this.y - 30, t: 0.8, text: 'BONK', color: '#7df58f' });
      return false;
    }
    if (Enemies.acting) SFX.attack(Enemies.acting); // a monster's bite or blow landing: its attack sound
    if (!this.segments.length) { this.die(cause); return true; } // a bare head: the last stand is over
    this.lastHit = fromX !== undefined ? { x: fromX, y: fromY, t: G.time } : null; // the pieces fly away from whatever hit you
    this.loseTail(power, cause); // (a hit takes what you have, at most: a big bite that empties you starts the last stand)
    const K = CFG.BALANCE, over = Math.max(0, this.weaponCount() - K.longBody); // a very long body recovers faster from a hit: it has hits to spare
    this.invuln = CFG.INVULN * G.mods.invuln * Math.max(K.longInvulnMin, 1 - K.longInvuln * over);
    if (!this.segments.length) { // the LAST STAND: a bare head, blinking; the next hit kills, any segment ends it
      this.invuln *= 1.5; G.flash = 0.3; G.tint = { color: '255,60,60', t: 1.2 };
      G.banner = { text: 'LAST STAND', sub: 'one more hit and it is over: grab a segment!', t: 2.4 };
      G.msg(`Last stand: ${cause || 'that hit'} took everything. The next hit kills you; pick up any segment to live.`, '#ff7b6b');
    }
    G.shake = Math.max(G.shake, 0.35);
    if (fromX !== undefined) this.angle = Math.atan2(this.y - fromY, this.x - fromX);
    return true;
  },
  die(cause) {
    this.alive = false;
    G.deathCause = cause;
    Combat.burst(this.x, this.y, 40, '#ffd6a5', 4);
    for (const p of this.segPos) Combat.burst(p.x, p.y, 8, '#f9c', 3);
    SFX.play('die');
    G.shake = 0.8;
    G.gameOver();
  },
});
