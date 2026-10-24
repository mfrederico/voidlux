// Loot: the bullets monsters drop (weighted by tier), dots, the crowd bonus and a kill's loot roll.
// Extends World (see world.js). Loads after it.
Object.assign(World, {
  // random bullet of a potency tier (1..3), weighted within the tier
  pickBullet(tier) {
    let keys = Object.keys(BULLETS).filter(k => !BULLETS[k].craft && (!BULLETS[k].minLevel || (G.level || 1) >= BULLETS[k].minLevel)); // bone spears only from level 2
    if (tier) { const t = keys.filter(k => BULLETS[k].tier === tier); if (t.length) keys = t; }
    const total = keys.reduce((a, k) => a + BULLETS[k].weight, 0);
    let roll = Math.random() * total;
    for (const k of keys) { roll -= BULLETS[k].weight; if (roll <= 0) return k; }
    return keys[0];
  },
  mkPickup(x, y, kind, id, region) { return { x, y, kind, id, region, bob: Math.random() * TAU, r: 11, alive: true, vx: 0, vy: 0 }; },
  // dots on the ground: crafting currency, worth `n` each (shown as a size). Scattered a little so a pile reads as a pile.
  dropDots(x, y, region, n) {
    if (!n) return;
    const piles = Math.min(n, 6);
    for (let k = 0; k < piles; k++) { const v = Math.floor(n / piles) + (k < n % piles ? 1 : 0); const a = U.rand(0, TAU), d = piles > 1 ? U.rand(10, 34) : 0; const pk = this.mkPickup(x + Math.cos(a) * d, y + Math.sin(a) * d, 'dot', 'dot', region); pk.value = v; pk.r = 9; this.pickups.push(pk); }
  },
  // drop loot of roughly this tier; it can roll one tier up (lucky) or down
  dropAt(x, y, region, tier) {
    let t = tier || 1;
    if (U.chance(CFG.LOOT.TIER_UP)) t++; else if (U.chance(CFG.LOOT.TIER_DOWN)) t--;
    t = U.clamp(t, 1, 3);
    if (U.chance(0.03)) this.pickups.push(this.mkPickup(x, y, 'cleanse', 'cleanse', region));
    else this.pickups.push(this.mkPickup(x, y, 'weapon', this.pickBullet(t), region));
  },
  // the extra monsters your damage output draws: crowdPerDoubling for every doubling of DPS past crowdRef, capped
  crowdBonus() { const K = CFG.BALANCE, dps = Player.estimateDps(1, 1); return Math.min(K.crowdMax, Math.max(0, Math.round(K.crowdPerDoubling * Math.log2(Math.max(1, dps / K.crowdRef))))); },
  // kill loot: roll each enemy's loot table; a pity counter guarantees a drop after a dry streak
  loot(e, L) {
    if (!L) return;
    { const K = CFG.GEAR; if (U.chance(e.unique ? K.uniqueDrop : e.rare ? K.rareDrop : K.drop)) this.pickups.push(this.mkPickup(e.x, e.y, 'weapon', U.pick(Object.keys(BULLETS).filter(k => BULLETS[k].kind === 'head' && (BULLETS[k].minLevel || 1) <= (G.level || 1))), e.region)); } // now and then: head gear
    if (e.unique && G.mode === 'world' && U.chance(CFG.MEGA_DECK.uniqueDrop)) { const left = Mega.missing().filter(id => !this.pickups.some(p => p.alive && p.kind === 'mega' && p.id === id)); if (left.length) this.pickups.push(this.mkPickup(e.x, e.y, 'mega', U.pick(left), e.region)); } // a unique monster may drop a mega for your deck
    let dropped = 0;
    const ls = G.lootScale || 1, tier = e.rare ? Math.min(3, L.tier + 1) : L.tier, chance = e.rare ? 1 : L.chance * (L.chance < 1 ? CFG.LOOT.RATE : 1) * ls * (e.lootMult ?? 1); // rares always drop, one tier up; the rest thin out with the crowd
    for (let k = 0; k < L.n; k++) if (U.chance(chance * G.mods.luck)) { this.dropAt(e.x + U.rand(-18, 18), e.y + U.rand(-18, 18), e.region, tier); dropped++; }
    if (!dropped && !e.swarm && U.chance(ls) && ++G.pity >= CFG.LOOT.PITY) { this.dropAt(e.x, e.y, e.region, tier); dropped++; }
    if (dropped) G.pity = 0;
    // and now and then a dot, so fusions are not gated on dungeons alone
    if (!e.unique && U.chance(CFG.DOT_DROP.chance * G.mods.luck * ls * (e.lootMult ?? 1))) this.dropDots(e.x + U.rand(-10, 10), e.y + U.rand(-10, 10), e.region, 1);
  },
});
