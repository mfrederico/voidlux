// Perks: the shop that opens when you clear a dungeon. The clear gives one free perk; every ghost you ate in
// any dungeon is a coin that buys one more. A perk costs 1 ghost, plus 1 for every copy you already have, for the whole run;
// Bigger Body climbs faster (CFG.BALANCE.bodySq), and the free pick only pays up to CFG.BALANCE.freeCap of a price. Unspent ghosts carry over.
// Your body has a hard segment cap (CFG.MAX_SEGMENTS); only the Bigger Body perk raises it, and it is always on offer.
// desc: the whole story (the autopilot reads it too); card: the same in a line or two, for the shop's cards
const PERKS = {
  swift:    { name: 'Fast and Hard', key: '>', color: '#7df58f', desc: '+15% movement speed, overworld and dungeon. Hard head: crashing into trees, rocks and walls often just bounces you off unhurt (40%, then 64%, 78%... per copy); with head gear on (always bonk-proof) it makes the gear hit 20% harder per copy instead', card: '+15% speed; crashes often bounce off. With head gear: +20% gear damage.', apply() { G.mods.speed *= 1.15; G.mods.hardHead = (G.mods.hardHead || 0) + 1; } },
  trigger:  { name: 'Hair Trigger', key: '!', color: '#ffd166', desc: '-7.5% bullet cooldown and bullets fly 10% faster, per copy', card: 'Reloads 7.5% faster, and bullets fly 10% faster.', apply() { G.mods.cd *= 0.925; G.mods.bspeed *= 1.1; } },
  velocity: { name: 'Muzzle Velocity', key: '»', color: '#9bd3ff', desc: '+15% bullet speed per copy: shots arrive before the target moves', card: 'Bullets fly 15% faster: they land before targets can move.', apply() { G.mods.bspeed *= 1.15; } },
  brawn:    { name: 'Brawn',        key: '+', color: '#ff7b6b', desc: '+50% damage on every hit (direct hits, blasts, shards and bounces); burns, poison and other statuses are Potent Elements\'s job', card: '+50% damage on every bullet.',                                 apply() { G.powerMult = Math.round(G.powerMult * 150) / 100; } },
  reach:    { name: 'Long Reach',   key: '-', color: '#9bd3ff', desc: '+10% bullet range and lifetime per copy (a Pulse ring and a Sacrifice widen with it, squared)', card: 'Bullets fly 10% further and last 10% longer.', apply() { G.mods.range *= 1.1; } },
  skin:     { name: 'Thick Skin',   key: '#', color: '#b8a48e', desc: 'Invulnerable 60% longer after every hit', card: 'After a hit you stay untouchable 60% longer.',           apply() { G.mods.invuln *= 1.6; } },
  magnet:   { name: 'Loot Magnet',  key: 'U', color: '#f3c6ff', desc: 'Loot drifts to you from 10% further per copy (from 10% of the screen: 11%, 12.1%, 13.3%...)', card: 'Loot drifts to you from 10% further.', apply() { G.mods.magnet += 1; } },
  bitter:   { name: 'Bitter Blood', key: 'X', color: '#c58aff', desc: 'Parasites die twice as fast once they bite you', card: 'A parasite that bites you dies twice as fast.',    apply() { G.mods.paraHold *= 0.5; } },
  body:     { name: 'Bigger Body',  key: '+1', color: '#9bf06a', desc: '+1 max segment: an empty slot for your next pickup or a bullet from your bag', card: '+1 body slot: room for one more bullet.', always: true,
              apply() { Player.maxSegs++; } },
  lucky:    { name: 'Lucky Shell',  key: '$', color: '#ffb3ff', desc: '+30% loot drop chance from every kill', card: '+30% loot from every kill.',             apply() { G.mods.luck *= 1.3; } },
  elements: { name: 'Potent Elements', key: '*', color: '#7df9ff', desc: 'Burn, poison, spark and freeze 50% stronger', card: 'Burn, poison, freeze and spark are 50% stronger.',        apply() { G.mods.elem *= 1.5; } },
  brood:    { name: 'Brood',        key: '&', color: '#9bf06a', desc: '+1 minion slot, and a parasite minion hatches onto your tail right now: an extra segment that takes no slot, spits at enemies, and leaps off to blow up on bosses. While a minion slot is free, a parasite that reaches you is absorbed as a minion instead of biting', card: '+1 minion slot. While a slot is free, parasites that reach you join you instead of biting.', apply() { G.mods.minions += 1; Minions.hatch(null, Player.x + 40, Player.y); } },
  summon:   { name: 'Summoner',     key: '◎', color: '#f3c6ff', desc: 'circling summons in half a lap, and what you summon is 50% stronger: boulders and cannon flights hit harder, ghosts charm longer, trees sing longer. Stacks', card: 'Summon with half a lap, and summons are 50% stronger. Stacks.', apply() { G.mods.summon += 1; } },
  twin:     { name: 'Twin Shot',    key: '=', color: '#ff9ec8', desc: 'every bullet you fire brings a free copy beside it (no slot, no reload). Each take adds another copy, and the copies share out the damage: one copy at 40%, two at 30% each, three at 25% each. Copies carry the full status effects', card: 'Free extra bullets beside each shot: 1 at 40%, 2 at 30%, 3 at 25%.', apply() { G.mods.twin += 1; } },
};
// icon language for the shop: [glyph for the badge, stat strip of [icon, text] pairs]. Megas use the star.
const PERK_ICONS = {
  swift:    ['speed',    () => [['speed', '+15%'], ['helmet', `${Math.round(100 * Player.hardHeadChance((G.mods.hardHead || 0) + 1))}%`]]],
  trigger:  ['rate',     [['rate', '-7.5%'], ['speed', '+10%']]],
  velocity: ['speed',    [['speed', '+15%']]],
  brawn:    ['dmg',      [['dmg', '+50%']]],
  reach:    ['range',    [['range', '+10%']]],
  skin:     ['shield',   [['shield', '+60%']]],
  magnet:   ['magnet',   () => [['magnet', `${Math.round(Perks.magnetFrac(G.mods.magnet + 1) * 100)}%`]]],
  bitter:   ['spider-x', [['spider-x', '×0.5']]],
  body:     ['segment',  [['segment', '+1']]],
  lucky:    ['luck',     [['luck', '+30%']]],
  elements: ['fire',     [['fire', ''], ['poison', ''], ['electric', ''], ['ice', '×1.5']]],
  brood:    ['minion',   [['minion', '+1']]],
  summon:   ['orbit',    () => [['orbit', `×${+(1 / (G.mods.summon + 2)).toFixed(2)}`], ['dmg', `+${50 * (G.mods.summon + 1)}%`]]],
  twin:     ['burst',    () => [['burst', `×${G.mods.twin + 2}`], ['dmg', Math.round(Perks.twinMult(G.mods.twin + 1) * 100) + '%']]],
};
// Mega powerups: one equipped at a time, fired with E when the power bar is full. Kills charge the bar.
const MEGAS = {
  nuke:    { name: 'Sacrifice',  key: '*', color: '#ff5c5c', desc: 'Time stops and your tail weapon detonates in its own style: its element, its colour, Long Reach widens it like a Pulse. Flattens most things near the middle and breaks walls', card: 'Your tail weapon goes off in its element. Long Reach widens it.' },
  recruit: { name: 'Hive Mind',  key: 'H', color: '#c58aff', desc: 'Every parasite nearby (riders too) switches sides and spits at your enemies for 8s, then explodes. Too few around? More burrow up to fight for you: 2 per Hive Mind level (each Berserk or Mad Moth on your body is a level), and any that die are replaced until the 8s are up', card: 'Parasites fight for you for 8s; the fallen are replaced until it ends.' },
  frost:   { name: 'Deep Freeze', key: 'I', color: '#cfeeff', desc: 'Everything on screen freezes solid for 5s. For those 5s every bullet you fire is ice too, and ice cuts through any cold resistance: every hit lands at 2x or better', card: 'Freezes everything for 5s; your bullets turn to ice and hit for 2x or more.' },
  storm:   { name: 'Bullet Storm', key: 'S', color: '#ffd166', desc: 'For 3s every segment fires nonstop, ignoring the one-bullet rule, at double fire rate. What it kills does not charge your next mega', card: 'For 3s every segment fires nonstop, twice as fast. Its kills give no mega charge.' },
  lightning: { name: 'Lightning Storm', key: 'L', color: '#7df9ff', desc: 'You curl into a ball and rise up, untouchable, for 5s: every segment is a spike that arcs lightning at the nearest enemy, chaining on, and everything on screen is shocked', card: 'A rising ball of spikes for 5s: every segment arcs lightning. Untouchable.' },
  lich:    { name: 'Will of the Lich', key: 'W', color: '#ff8a3d', desc: 'Every segment breaks off as an untouchable flaming skull for 3s, hunting monsters to burn them and dry them out, then flies home. Your bare head cannot be hurt meanwhile', card: 'Your segments fly off as flaming skulls for 3s: burn, dry out, come home.' },
};
const Mega = {
  // the MEGA DECK (CFG.MEGA_DECK): G.mega.owned is every mega you have collected (Sacrifice from the start), G.mega.id the
  // active one. Megas come from the perk shop or from unique monsters; switching (LOADOUT) empties the bar
  owned() { const o = G.mega.owned; return o && o.length ? o : ['nuke']; },
  has(id) { return this.owned().includes(id); },
  current() { return G.mega.id && MEGAS[G.mega.id] && this.has(G.mega.id) ? G.mega.id : 'nuke'; },
  missing() { return Object.keys(MEGAS).filter(id => !this.has(id)); },
  price() { const K = CFG.MEGA_DECK; return K.base + K.step * (this.owned().length - 1); }, // the more you own, the dearer the next
  // a new mega for the deck (the shop, or a unique's drop). It goes live only if the bar is empty (switching would cost nothing)
  add(id, how) {
    if (!MEGAS[id] || this.has(id)) return false;
    G.mega.owned = this.owned().concat(id); const M = MEGAS[id], n = G.mega.owned.length, live = G.mega.charge <= 0;
    if (live) G.mega.id = id;
    G.msg(`MEGA DECK (${n} found): ${M.name} ${how || 'joins your deck'}. ${live ? 'It is your active mega.' : 'Switch to it on the LOADOUT page (the bar empties when you switch).'}`, M.color);
    return true;
  },
  // make `id` the active mega: the bar empties (Build asks first when there is charge to lose)
  activate(id) {
    if (!this.has(id) || this.current() === id) return false;
    G.mega.id = id; G.mega.charge = 0; SFX.play('loadout'); G.msg(`${MEGAS[id].name} is your active mega. Kill things to charge it.`, MEGAS[id].color);
    return true;
  },
  charge(e) {
    if (e.kind === 'stone') return;
    if (G.storm > 0 || (G.stormEnd && G.time >= G.stormEnd && G.time < G.stormEnd + 1)) return; // Bullet Storm kills (and its stragglers, a second after) do not charge the next mega
    if (G.bolt || G.lich) return; // nor Lightning Storm's or the Lich's
    if (G.freeze > 0 || G.pendingBlast || (G.sacEnd && G.time >= G.sacEnd - 0.01 && G.time < G.sacEnd + 1.5)) return; // nor what a Sacrifice kills (the blast, and its burns for a moment after)
    if (e.kind === 'boss') { G.mega.charge = CFG.MEGA_MAX; return; }
    const tier = e.kind === 'snake' ? 2 : e.kind === 'bug' ? BUGS[e.type].loot.tier : 1;
    const was = G.mega.charge;
    G.mega.charge = Math.min(CFG.MEGA_MAX, G.mega.charge + 8 + 4 * tier);
    if (was < CFG.MEGA_MAX && G.mega.charge >= CFG.MEGA_MAX) { const id = this.current(); G.msg(`${MEGAS[id].name} READY: ${Input.touch ? 'tap MEGA' : 'press E'}`, MEGAS[id].color); SFX.play('megaReady'); }
  },
  ready() { return G.mega.charge >= CFG.MEGA_MAX; },
  level(id) { return id === 'recruit' ? Math.max(1, Player.segments.filter(s => ['berserk', 'madmoth'].includes(BULLETS[s.id].combo)).length) : 0; }, // Hive Mind's level: each Berserk or Mad Moth on the body
  label(id) { const L = this.level(id); return MEGAS[id].name + (L ? ` Lv${L}` : ''); },
  use() {
    if (!this.ready() || !Player.alive) return;
    const id = this.current(); G.mega.charge = 0; G.mega.used++;
    if (id !== 'nuke') G.msg(`${MEGAS[id].name}!`, MEGAS[id].color); // (Sacrifice says its own name across the screen)
    this[id]();
  },
  // --- Sacrifice: freeze time, flash, and the tail weapon (never a riding minion) goes off in its own style ---
  nuke() {
    const P = Player, K = CFG.SACRIFICE; let i = P.segments.length - 1; while (i >= 0 && P.isMinion(P.segments[i])) i--;
    const B = i >= 0 ? BULLETS[P.segments[i].id] : null, at = i >= 0 && P.segPos[i] ? { x: P.segPos[i].x, y: P.segPos[i].y } : { x: P.x, y: P.y };
    const reg = G.mode === 'world' ? World.regionAt(P.x) : G.mode === 'deck' ? Deck.region : Dungeon.region, tier = B ? B.tier : 1;
    const dmg = (K.base + K.perRegion * Enemies.hpScale(reg)) * (1 + K.tierMult * (tier - 1)) * (B ? 1 : 0.5) * G.powerMult; // a bare head only has itself to give: half
    const r = CFG.SACRIFICE.r * G.mods.range * G.mods.range; // Long Reach widens it the way it widens a Pulse: range x range
    if (i >= 0) P.removeAt(i, 'sacrifice');
    const color = B ? B.color : '#ff5c5c', elems = B ? [...new Set(B.elems || [])] : [];
    G.freeze = 1.1; G.flash = 1.0; G.shake = 1.0; SFX.play('die');
    G.pendingBlast = { x: at.x, y: at.y, r, dmg, color, elems, name: B ? B.name : 'head' };
  },
  blast(b) { // runs when the freeze ends
    G.sacEnd = G.time;
    const K = CFG.SACRIFICE, E = b.elems || [], ec = E.map(el => ELEMENTS[el].color);
    Combat.burst(b.x, b.y, 60, '#fff', 5); Combat.burst(b.x, b.y, 50, b.color || '#ff8a3d', 4.5); for (const c of ec.length ? ec : ['#ff8a3d', '#ff5c5c']) Combat.burst(b.x, b.y, 36, c, 3.5);
    let arcs = 0;
    for (const e of G.enemies) {
      if (e.dead || e.ally > 0 || e.minion) continue; const d = U.dist(e.x, e.y, b.x, b.y); if (d >= b.r + e.r) continue;
      let hit = b.dmg * (1 - 0.5 * Math.min(1, d / b.r)); // full at the middle, half at the rim; armour does not stop it
      if (E.length) hit *= 0.5 + 0.5 * E.reduce((s, el) => s + Combat.resist(e, el), 0) / E.length; // half concussion, half its element: what is immune still takes the blow, what is weak takes more
      if (e.kind === 'boss') hit = Math.min(hit, e.maxhp * K.bossCap);
      Combat.damage(e, hit, b.color || '#fff'); if (e.dead) continue;
      const dot = hit * K.dot * G.mods.elem;
      if (E.includes('fire')) Combat.addStatus(e, 'burn', 5, dot, true);
      if (E.includes('poison')) Combat.addStatus(e, 'poison', 5, dot, false);
      if (E.includes('ice')) Combat.addStatus(e, 'freeze', 3, 0);
      if (E.includes('electric')) { Combat.addStatus(e, 'spark', 3, dot * 0.45, false); if (arcs++ < 16) Combat.arcs.push({ x1: b.x, y1: b.y, x2: e.x, y2: e.y, t: 0.35, seed: Math.random() }); }
    }
    if (E.includes('poison')) Combat.clouds.push({ x: b.x, y: b.y, r: b.r * 0.4, t: 6, mag: b.dmg * K.dot * 0.5 * G.mods.elem, seed: Math.random() * 10, kind: 'poison' }); // a lingering cloud where it went off
    if (E.includes('ice')) SFX.play('freeze'); if (E.includes('electric')) SFX.play('zap');
    if (b.color) { const h = parseInt(b.color.slice(1), 16); G.tint = { color: `${h >> 16 & 255},${h >> 8 & 255},${h & 255}`, t: 1.2 }; } // the screen washes in its colour
    if (G.mode === 'world') for (const R of World.regions) { if (R.locked || (G.swarm && (R.idx === G.swarm.region || R.idx === G.swarm.region - 1))) continue; for (const s of R.wall.sections) { if (s.alive && Math.abs(R.wall.x - b.x) < b.r && (s.y0 + s.y1) / 2 > b.y - b.r && (s.y0 + s.y1) / 2 < b.y + b.r) { s.hp = 0; s.alive = false; } } } // (a sealed boss arena's wall holds: the blast once opened it, letting you walk out past a living boss; so do a swarm's)
    G.shake = 0.9; SFX.play('wall'); G.ring = { x: b.x, y: b.y, r: 0, max: b.r, t: 0.6, color: b.color };
  },
  // --- Hive Mind: parasites fight for you ---
  recruit() {
    const P = Player; let n = 0;
    for (let i = 0; i < P.segments.length; i++) { const s = P.segments[i]; if (s.para) { const p = P.segPos[i] || P; const e = Enemies.spawnParasite(s.para.id, p.x, p.y, World.regionAt(P.x)); e.ally = CFG.HIVE_MIND.time; e.huntT = 3; n++; } }
    P.cleanse();
    for (const e of G.enemies) if (e.kind === 'parasite' && !e.dead && !e.minion && !(e.ally > 0) && U.dist(e.x, e.y, P.x, P.y) < 1000) { e.ally = CFG.HIVE_MIND.time; e.huntT = 3; e.spitT = U.rand(0.2, 0.8); e.tossT = 0; n++; }
    const want = 2 * this.level('recruit'); let dug = 0; // too few to turn: the hive sends its own, burrowing up around you
    if (G.mode === 'world') for (; n + dug < want; dug++) this.burrow();
    n += dug;
    G.hive = { t: CFG.HIVE_MIND.time, want, cd: 0 }; // for its whole run, the fallen are replaced
    G.flash = 0.5; G.tint = { color: '197,138,255', t: 1.2 }; G.shake = 0.4; SFX.play('parasite');
    const room = Minions.cap() - Minions.count();
    G.msg(n ? `${n} parasite${n > 1 ? 's' : ''} fight for you${dug ? ` (${dug} burrowed up from the hive)` : ''}!${room > 0 ? ` Up to ${room} that survive will stay as minions.` : ''}` : 'No parasites around to recruit.', '#c58aff');
  },
  // a hive parasite burrows up beside you, on your side until the Hive Mind ends (no kill credit or loot for your own)
  burrow() {
    const P = Player, a = U.rand(0, TAU), d = U.rand(90, 170), x = P.x + Math.cos(a) * d, y = U.clamp(P.y + Math.sin(a) * d, 40, CFG.WORLD_H - 40);
    const e = Enemies.spawnParasite(U.pick(Object.keys(PARASITES)), x, y, World.regionAt(P.x));
    e.ally = G.hive ? G.hive.t : CFG.HIVE_MIND.time; e.hive = true; e.huntT = 3; e.spitT = U.rand(0.2, 0.8); e.tossT = 0; e.onDeath = null;
    Combat.burst(x, y, 14, '#8a5a3a', 3); Combat.burst(x, y, 8, '#c58aff', 2);
  },
  // Hive Mind's run: while it lasts, any of your parasites that die are replaced (one every `respawn`s), up to its 2 per level
  update(dt) {
    this.updateBolt(dt); this.updateLich(dt);
    const H = G.hive; if (!H) return;
    H.t -= dt; if (H.t <= 0 || G.mode !== 'world' || !Player.alive) { G.hive = null; return; }
    const mine = G.enemies.filter(e => !e.dead && e.kind === 'parasite' && e.ally > 0 && !e.minion).length;
    H.cd -= dt; if (mine < H.want && H.cd <= 0 && H.t > 0.8) { this.burrow(); H.cd = CFG.HIVE_MIND.respawn; SFX.tone(260, 140, 0.18, 'sawtooth', 0.25); }
  },
  // --- Deep Freeze ---
  frost() {
    G.deepFreeze = CFG.DEEP_FREEZE.time; // first, so the freeze itself cuts through cold resistance too
    for (const e of G.enemies) { if (e.dead || e.ally > 0) continue; Combat.addStatus(e, 'freeze', 5, 0); }
    G.flash = 0.6; G.tint = { color: '160,220,255', t: 5 }; G.shake = 0.3; SFX.play('freeze');
  },
  // --- Bullet Storm ---
  storm() { G.storm = 3; G.stormEnd = G.time + 3; G.flash = 0.4; G.tint = { color: '255,209,102', t: 4 }; SFX.play('storm'); },
  region() { return G.mode === 'world' ? World.regionAt(Player.x) : G.mode === 'deck' ? Deck.region : Dungeon.region; },
  foe(e) { return !e.dead && !e.hidden && !(e.ally > 0) && !e.minion && e.kind !== 'stone'; },
  // --- Lightning Storm (CFG.LIGHTNING): the body curls into a ball round the head (Player.ballPos), grows as it rises
  // (Player.scale) and cannot be hurt; every segment arcs a chaining bolt in turn; everything near is shocked at the start ---
  lightning() {
    const K = CFG.LIGHTNING, P = Player;
    G.bolt = { t: K.time, t0: K.time, acc: 0, k: 0 }; P.invuln = Math.max(P.invuln, K.time + 0.3);
    G.flash = 0.5; G.tint = { color: '125,249,255', t: K.time }; G.shake = 0.5; SFX.play('storm'); SFX.play('zap');
    let arcs = 0; const d0 = this.boltDmg();
    for (const e of G.enemies) { if (!this.foe(e) || U.dist2(e.x, e.y, P.x, P.y) > 900 * 900) continue; Combat.addStatus(e, 'spark', 3, d0 * 0.3, false); if (e.kind !== 'boss') e.stun = Math.max(e.stun || 0, K.stun); if (arcs++ < 20) Combat.arcs.push({ x1: P.x, y1: P.y, x2: e.x, y2: e.y, t: 0.3, seed: Math.random() }); }
  },
  boltDmg() { const K = CFG.LIGHTNING; return (K.base + K.perRegion * Enemies.hpScale(this.region())) * G.powerMult; },
  updateBolt(dt) {
    const B = G.bolt; if (!B) return; const K = CFG.LIGHTNING, P = Player;
    B.t -= dt; if (B.t <= 0 || !P.alive) { G.bolt = null; P.computeSegPos(); return; }
    const from = P.segPos.length ? P.segPos.map((p, i) => ({ x: p.x, y: p.y, tier: (BULLETS[(P.segments[i] || {}).id] || {}).tier || 1 })) : [{ x: P.x, y: P.y, tier: 1 }];
    const step = K.every / from.length; B.acc += dt; // the spikes take turns, round the ball: each one every `every`s
    while (B.acc >= step) { B.acc -= step; B.k = (B.k + 1) % from.length; this.boltFrom(from[B.k]); }
  },
  boltFrom(s) {
    const K = CFG.LIGHTNING; let best = null, bd = K.range * K.range;
    for (const e of G.enemies) { if (!this.foe(e)) continue; const d2 = U.dist2(e.x, e.y, s.x, s.y); if (d2 < bd) { bd = d2; best = e; } }
    if (!best) { if (U.chance(0.3)) Combat.arcs.push({ x1: s.x, y1: s.y, x2: s.x + U.rand(-50, 50), y2: s.y + U.rand(-50, 50), t: 0.08, seed: Math.random() }); return; } // nothing near: a spark off the spike
    SFX.at(best.x, () => SFX.play('zap')); // every tendril crackles, from where it strikes (the zap gate keeps a big ball to a pulse, not a roar)
    let dmg = this.boltDmg() * (1 + 0.25 * (s.tier - 1)), at = s; const hit = new Set();
    for (let h = 0; h <= K.hops && best; h++) {
      Combat.arcs.push({ x1: at.x, y1: at.y, x2: best.x, y2: best.y, t: 0.12, seed: Math.random() });
      Combat.damage(best, best.kind === 'boss' ? dmg * (BOSSES[best.type].megaResist || 1) : dmg, '#7df9ff'); hit.add(best);
      if (!best.dead) Combat.addStatus(best, 'spark', 1.5, dmg * 0.1, false);
      at = best; dmg *= K.hopMult; let nx = null, nd = K.hopR * K.hopR;
      for (const e of G.enemies) { if (!this.foe(e) || hit.has(e)) continue; const d2 = U.dist2(e.x, e.y, at.x, at.y); if (d2 < nd) { nd = d2; nx = e; } }
      best = nx;
    }
    if (U.chance(0.2)) SFX.play('zap');
  },
  // --- Will of the Lich (CFG.LICH): every segment flies off as a flaming skull that hunts near the head, burning and drying
  // what it touches; the last `back`s they fly home to their places. The body neither shows nor fires meanwhile; the head cannot be hurt ---
  lich() {
    const K = CFG.LICH, P = Player;
    if (!P.segments.length) G.msg('No segments to raise: the Lich has only your head.', '#ff8a3d');
    G.lich = { t: K.time, t0: K.time, skulls: P.segments.map((s, i) => { const p = P.segPos[i] || P, a = U.rand(0, TAU); return { i, id: s.id, x: p.x, y: p.y, vx: Math.cos(a) * 220, vy: Math.sin(a) * 220, hits: new Map(), seed: Math.random() * 10 }; }) };
    P.invuln = Math.max(P.invuln, K.time + 0.3);
    G.flash = 0.4; G.tint = { color: '255,138,61', t: K.time }; G.shake = 0.4; SFX.play('ghost'); SFX.play('fuse');
    for (const s of G.lich.skulls) Combat.burst(s.x, s.y, 10, '#ff8a3d', 2.5);
  },
  updateLich(dt) {
    const L = G.lich; if (!L) return; const K = CFG.LICH, P = Player;
    if (!P.alive) { G.lich = null; return; }
    L.t -= dt; const home = L.t <= K.back, dmg = (K.base + K.perRegion * Enemies.hpScale(this.region())) * G.powerMult, chasing = new Map(); // (how many skulls are on each monster: they spread out)
    for (const s of L.skulls) {
      if (home) { const p = P.segPos[s.i] || P, k = Math.min(1, dt / Math.max(dt, L.t)); s.x += (p.x - s.x) * k; s.y += (p.y - s.y) * k; continue; } // home: closing on its place, arriving as the time runs out
      let best = null, bd = 1e12;
      for (const e of G.enemies) { if (!this.foe(e) || U.dist2(e.x, e.y, P.x, P.y) > K.reach * K.reach) continue; const d2 = U.dist2(e.x, e.y, s.x, s.y) * (1 + 3 * (chasing.get(e) || 0)); if (d2 < bd) { bd = d2; best = e; } }
      if (best) chasing.set(best, (chasing.get(best) || 0) + 1);
      const tx = best ? best.x : P.x + Math.cos(G.time * 3 + s.i) * 80, ty = best ? best.y : P.y + Math.sin(G.time * 3 + s.i) * 80; // nothing near: circle the head
      const na = U.angLerp(Math.atan2(s.vy, s.vx), Math.atan2(ty - s.y, tx - s.x), K.turn * dt); s.vx = Math.cos(na) * K.speed; s.vy = Math.sin(na) * K.speed; s.x += s.vx * dt; s.y += s.vy * dt;
      for (const e of G.enemies) {
        if (e.dead || e.hidden || e.ally > 0 || e.minion || U.dist2(e.x, e.y, s.x, s.y) > (e.r + 14) ** 2 || (s.hits.get(e) || 0) > G.time) continue;
        s.hits.set(e, G.time + K.hitCd);
        Combat.damage(e, e.kind === 'boss' ? dmg * (BOSSES[e.type].megaResist || 1) : dmg, '#ff8a3d');
        if (!e.dead) { Combat.addStatus(e, 'burn', K.burn, dmg * 0.15 * G.mods.elem, true); Combat.dry(e, K.dry); }
        Combat.burst(s.x, s.y, 6, '#ff8a3d', 2);
      }
      if (U.chance(dt * 30)) Combat.particles.push(Combat.mk(s.x, s.y, U.rand(-20, 20), U.rand(-60, -20), 0.4, U.chance(0.5) ? '#ff8a3d' : '#ffd166', 3));
    }
    if (L.t <= 0) { G.lich = null; for (const p of P.segPos) Combat.burst(p.x, p.y, 8, '#ff8a3d', 2); SFX.play('land'); }
  },
};
// mega cards for the shop: buying one adds it to your MEGA DECK (only megas you do not own are offered)
for (const id of Object.keys(MEGAS)) PERKS['mega_' + id] = { name: MEGAS[id].name, key: MEGAS[id].key, color: MEGAS[id].color, desc: 'MEGA (joins your deck): ' + MEGAS[id].desc, card: MEGAS[id].card /* (the card's MEGA tab says what it is) */, mega: id, apply() { Mega.add(id); } };

// God-mode loadout presets (keys 1-9 while god mode is on): plausible bodies at the first boss, for tuning.
const GOD_PRESETS = [
  { name: 'Starter',           cap: 3, segs: ['bullet', 'pebble', 'pebble'], perks: [] },
  { name: 'Region-7 honest',   cap: 4, segs: ['bullet', 'laser', 'ember', 'rock'], perks: ['body'] },
  { name: 'One fusion',        cap: 4, segs: ['plasma', 'laser', 'rock', 'frost'], perks: ['body', 'brawn'] },
  { name: 'Two dungeons',      cap: 5, segs: ['rapid', 'plasma', 'acid', 'rock', 'burst'], perks: ['body', 'body', 'brawn', 'trigger'] },
  { name: 'Beam build',        cap: 5, segs: ['beam', 'sparks', 'bomb', 'frost', 'rock'], perks: ['body', 'body', 'brawn', 'brawn', 'trigger'] },
  { name: 'Hive build',        cap: 5, segs: ['berserk', 'madmoth', 'gas', 'boulderShot', 'steam'], perks: ['body', 'body', 'trigger', 'reach'] },
  { name: 'Walker build',      cap: 5, segs: ['firewalker', 'icewalker', 'stormwalker', 'plaguewalker', 'laser'], perks: ['body', 'body'] },
  { name: 'Bone spears',       cap: 4, segs: ['fireSpear', 'iceSpear', 'venomSpear', 'stormSpear'], perks: ['body', 'brawn'] },
  { name: 'Juggling act',      cap: 5, segs: ['cascade', 'firePin', 'stormPin', 'icePin', 'beam'], perks: ['body', 'body', 'brawn', 'brawn', 'twin', 'trigger'] },
];
const Perks = {
  defaults() { return { speed: 1, cd: 1, bspeed: 1, range: 1, invuln: 1, magnet: 0, paraHold: 1, luck: 1, elem: 1, minions: 0, twin: 0, summon: 0, hardHead: 0 }; },
  summonLaps() { return 1 / (1 + (G.mods.summon || 0)); },      // the fraction of a lap a summon needs (half with one Summoner)
  summonPower() { return 1 + 0.5 * (G.mods.summon || 0); },    // how much stronger summoned things are
  // Loot Magnet reach as a fraction of the screen width: CFG.MAGNET_BASE (10%) for one stack, accelerating with each extra stack
  magnetFrac(n) { return Math.min(1.5, CFG.MAGNET_BASE * Math.pow(1.1, Math.max(0, n))); }, // 10% of the screen, then 10% further per copy
  twinMult(n) { const T = CFG.TWIN.mult; return n <= 0 ? 0 : T[Math.min(n, T.length) - 1]; }, // each Twin Shot copy's share of the damage, with n copies
  magnetRadius() { return CFG.W * this.magnetFrac(G.mods.magnet); },
  SLOTS: 4,
  // pause the game and open the shop: Bigger Body plus random others
  offer() {
    const always = Object.keys(PERKS).filter(k => PERKS[k].always), rest = U.shuffle(Object.keys(PERKS).filter(k => !PERKS[k].always && !PERKS[k].mega));
    const megas = U.shuffle(Object.keys(PERKS).filter(k => PERKS[k].mega && !Mega.has(PERKS[k].mega))); // one mega you don't own yet (none when the deck is complete)
    G.perkChoices = U.shuffle(always.concat(megas.slice(0, 1), rest).slice(0, this.SLOTS));
    G.perkSel = 0; G.perkLeaveSel = false; G.perkFree = 1; G.bought = []; G.state = 'perk';
    G.perkReel = this.mkReel(G.perkChoices); SFX.play('perkShop');
    { const th = Music.THEMES.perk_shop ? 'perk_shop' : Music.levelTheme(G.level); if (th && (!Music.on || Music.name !== th)) Music.start(th); } /* the shop's own tune (a tracker song named perk_shop), else the level's: never silence after the victory song */ this.fx = { glow: null, parts: [], rings: [], pops: [] }; G.perkClosing = 0;
  },
  // the slot reel: each card spins through random perks and settles on its real one, left to right, slowing like a
  // reel (ticks per step, a pop per card, a chord when the last lands). Any key or tap lands them all at once.
  mkReel(ids) {
    const all = Object.keys(PERKS);
    return { t: 0, cards: ids.map((id, i) => { const steps = 12 + i * 6, seq = []; for (let k = 0; k < steps; k++) { let r; do r = U.pick(all); while (r === id); seq.push(r); } seq.push(id); return { dur: 1.0 + i * 0.5, steps, seq, last: -1, done: false }; }), landed: 0 };
  },
  reelEase(p) { return 1 - Math.pow(1 - p, 3); },
  spinning() { return !!(G.perkReel && G.perkReel.cards.some(c => !c.done)); },
  finishReel() { const R = G.perkReel; if (!R) return; R.t = Math.max(R.t, Math.max(...R.cards.map(c => c.dur))); for (const c of R.cards) { c.last = c.steps; c.done = true; } SFX.play('fuse'); },
  tickReel(dt) {
    const R = G.perkReel; if (!R) return; R.t += dt;
    R.cards.forEach((c, i) => {
      if (c.done) return;
      const p = Math.min(1, R.t / c.dur), idx = Math.floor(this.reelEase(p) * c.steps);
      if (idx !== c.last) { c.last = idx; SFX.tone(700 + idx * 18, 500, 0.03, 'square', 0.09); }
      if (p >= 1) { c.done = true; c.landT = R.t; SFX.play('land'); if (R.cards.every(q => q.done)) SFX.play('fuse'); }
    });
    if (R.cards.every(c => c.done) && R.t > Math.max(...R.cards.map(c => c.dur)) + 0.6) G.perkReel = null; // the settle bounce is over
  },
  // a card's rect in the shop (Render.drawPerks and Input.tapShop lay the cards out the same way)
  cardRect(i) { const n = G.perkChoices.length, w = 260, h = Input.touch ? 350 : 250, gap = 24; return { x: CFG.W / 2 - (n * w + (n - 1) * gap) / 2 + i * (w + gap), y: 250, w, h }; },
  // buying a perk plays like a fusion: a bloom of light from the card, rings and sparks in the perk's colour, the fuse
  // chord, and the badge pops off the card and flies down to the "Taken" line. Purely cosmetic (the perk is already yours)
  fx: { glow: null, parts: [], rings: [], pops: [] },
  burst(i, id) {
    const F = this.fx, r = this.cardRect(i), x = r.x + r.w / 2, y = r.y + 68, col = PERKS[id].color;
    F.glow = { x, y, color: col, t: 0.5, dur: 0.5 };
    for (let k = 0; k < 30; k++) { const a = Math.random() * TAU, sp = U.rand(140, 420); F.parts.push({ x, y, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp - 80, t: U.rand(0.45, 0.9), r: U.rand(2.5, 6.5), color: k % 3 ? col : '#fff' }); }
    F.rings.push({ x, y, t: 0.55, dur: 0.55, color: col, r0: 30, r1: 180, lw: 9 }, { x, y, t: 0.4, dur: 0.4, color: '#fff', r0: 12, r1: 110, lw: 4 });
    F.pops.push({ id, x, y, tx: CFG.W / 2, ty: Input.touch ? 694 : 600, t: 0, dur: 0.85 });
    SFX.play(PERKS[id].mega ? 'fuse4' : 'fuse');
  },
  tickFx(dt) {
    const F = this.fx;
    if (F.glow) { F.glow.t -= dt; if (F.glow.t <= 0) F.glow = null; }
    for (const p of F.parts) { p.x += p.vx * dt; p.y += p.vy * dt; p.vy += 300 * dt; p.vx *= 0.96; p.t -= dt; } F.parts = F.parts.filter(p => p.t > 0);
    for (const r of F.rings) r.t -= dt; F.rings = F.rings.filter(r => r.t > 0);
    for (const q of F.pops) q.t += dt; F.pops = F.pops.filter(q => q.t < q.dur);
  },
  // how many times this perk has been taken in the whole run (free picks included; megas are one-offs)
  level(id) { return G.perks.filter(x => x === id).length; },
  // what n copies of a perk add up to, in a line or two (the PERKS tab)
  total(id, n) {
    const pc = x => Math.round((x - 1) * 100) + '%', p = x => Math.round(x * 100) + '%';
    switch (id) {
      case 'swift': return [`+${pc(Math.pow(1.15, n))} speed`, Player.gear ? `head gear hits +${Math.round(CFG.GEAR.hardHead * n * 100)}% harder` : `${p(Player.hardHeadChance(n))} of crashes bounce off`];
      case 'trigger': return [`reloads ${p(1 - Math.pow(0.925, n))} faster`, `bullets +${pc(Math.pow(1.1, n))} speed`];
      case 'velocity': return [`bullets +${pc(Math.pow(1.15, n))} speed`];
      case 'brawn': return [`+${pc(Math.pow(1.5, n))} hit damage`, 'statuses: Potent Elements'];
      case 'reach': return [`+${pc(Math.pow(1.1, n))} range`, `pulse rings ×${(Math.pow(1.1, 2 * n)).toFixed(2)} wide`];
      case 'skin': return [`${(CFG.INVULN * Math.pow(1.6, n)).toFixed(1)}s untouchable after a hit`];
      case 'magnet': return [`+${pc(Math.pow(1.1, n))} loot reach`, `${(this.magnetFrac(n) * 100).toFixed(1)}% of the screen`];
      case 'bitter': return [`parasites die ${Math.pow(2, n)}× faster on you`];
      case 'body': return [`+${n} body slot${n > 1 ? 's' : ''}`, `${Player.maxSegs} in all`];
      case 'lucky': return [`+${pc(Math.pow(1.3, n))} loot from kills`];
      case 'elements': return [`burn, poison, freeze, spark ×${Math.pow(1.5, n).toFixed(2)}`];
      case 'brood': return [`+${n} minion slot${n > 1 ? 's' : ''}`, 'absorbs parasites into free slots'];
      case 'summon': return [`summons in 1/${n + 1} of a lap`, `+${50 * n}% stronger`];
      case 'twin': return [`${n} free extra bullet${n > 1 ? 's' : ''} per shot`, `each at ${Math.round(this.twinMult(n) * 100)}% damage, full statuses`];
    }
    return [PERKS[id] ? PERKS[id].card || PERKS[id].desc : ''];
  },
  priceAt(id, n) { return 1 + n + (id === 'body' ? Math.floor(n * n / CFG.BALANCE.bodySq) : 0); },
  price(id) { return PERKS[id] && PERKS[id].mega ? Mega.price() : this.priceAt(id, this.level(id)); }, /* a mega: dearer the bigger your deck */ // 1 ghost at level 0, then 2, 3, 4... for the rest of the run; Bigger Body faster
  cost(id) { return Math.max(0, this.price(id) - (G.perkFree > 0 ? CFG.BALANCE.freeCap : 0)); }, // what you pay in ghosts: the free pick covers up to freeCap
  canBuy(id) { return G.ghosts >= this.cost(id); },
  canAfford() { return G.perkChoices.some(id => this.canBuy(id)); },
  priceLabel(id) { const p = this.cost(id); return G.perkFree > 0 && !p ? 'FREE' : G.perkFree > 0 ? `FREE+${p}` : `${p} ghost${p > 1 ? 's' : ''}`; }, // FREE+3: the free pick plus 3 ghosts
  take(id) {
    if (!this.canBuy(id)) { SFX.play('lose'); G.msg(`Not enough ghosts: ${PERKS[id].name} costs ${this.cost(id)}${G.perkFree > 0 ? ' on top of the free pick' : ''}`, '#ff9b8b'); return; }
    G.ghosts -= this.cost(id); if (G.perkFree > 0) G.perkFree--;
    PERKS[id].apply(); if (!PERKS[id].mega) G.perks.push(id); G.bought.push(id);
    if (!PERKS[id].mega) G.msg(`Perk: ${PERKS[id].name}`, PERKS[id].color); // (a mega says its own: Mega.add)
    this.burst(G.perkChoices.indexOf(id), id);
    // restock the slot (Bigger Body is always on the shelf; a mega slot restocks with another mega)
    if (!PERKS[id].always) {
      const pool = PERKS[id].mega ? Object.keys(PERKS).filter(k => PERKS[k].mega && !Mega.has(PERKS[k].mega) && !G.perkChoices.includes(k)) // another mega you do not own
                                 : Object.keys(PERKS).filter(k => !PERKS[k].always && !PERKS[k].mega && !G.perkChoices.includes(k));
      const i = G.perkChoices.indexOf(id);
      const plain = () => Object.keys(PERKS).filter(k => !PERKS[k].always && !PERKS[k].mega && !G.perkChoices.includes(k));
      if (pool.length) G.perkChoices[i] = U.pick(pool); else if (PERKS[id].mega && plain().length) G.perkChoices[i] = U.pick(plain()); // the deck is complete: a plain perk takes the mega's slot
    }
    if (!this.canAfford()) G.perkClosing = 0.9; // nothing left to buy: the shop closes once the burst has played
    Save.write(); // the purchase and the restocked shelf
  },
  close() {
    G.state = 'playing'; Save.write(); // checkpoint with the new perks
    if (G.mode === 'world' && (!Music.on || Music.name === 'perk_shop')) { const th = Music.levelTheme(G.level); if (th) Music.start(th); else Music.stop(); } // out of the shop: the level's tune
    const names = G.bought.map(id => PERKS[id].name);
    G.banner = { text: names.length ? names.length === 1 ? names[0].toUpperCase() : `${names.length} PERKS` : 'NO PERK TAKEN', sub: names.join(' · ') || 'Ghosts saved for next time', t: 3 };
    if (G.bought.length) Settings.perkTip(G.bought.slice()); // the first time ever: where to read them again
  },
  update(dt) {
    const n = G.perkChoices.length;
    this.tickReel(dt || 1 / 60); this.tickFx(dt || 1 / 60);
    if (G.perkClosing > 0) { G.perkClosing -= dt || 1 / 60; if (G.perkClosing <= 0) { G.perkClosing = 0; Input.pressed = {}; this.close(); } return; } // the last burst plays out before the shop shuts
    if (this.spinning()) { if (Object.keys(Input.pressed).some(k => Input.pressed[k])) { Input.pressed = {}; this.finishReel(); } return; } // the reel is the show: a key lands it, nothing buys yet
    if (Input.once('KeyS') || Input.once('ArrowDown')) { if (!G.perkLeaveSel) SFX.play('tab'); G.perkLeaveSel = true; } // down to the CONTINUE button, up back to the cards
    if (Input.once('KeyW') || Input.once('ArrowUp')) { if (G.perkLeaveSel) SFX.play('tab'); G.perkLeaveSel = false; }
    if (Input.once('KeyA') || Input.once('ArrowLeft')) { G.perkSel = (G.perkSel + n - 1) % n; G.perkLeaveSel = false; }
    if (Input.once('KeyD') || Input.once('ArrowRight')) { G.perkSel = (G.perkSel + 1) % n; G.perkLeaveSel = false; }
    for (let i = 0; i < n; i++) if (Input.once('Digit' + (i + 1))) { this.take(G.perkChoices[i]); return; }
    if (Input.once('Enter')) { if (G.perkLeaveSel) this.close(); else this.take(G.perkChoices[G.perkSel]); return; }
    if (Input.once('Space') || Input.once('Escape')) this.close();
  },
};
