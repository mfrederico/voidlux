// Auto-generated side-scrolling overworld: regions separated by breakable thorn walls.
const World = {
  regions: [], trees: [], lakes: [], boulders: [], pickups: [], entrances: [], graves: [], wraiths: [], cannons: [],
  reset() {
    this.regions = []; this.trees = []; this.lakes = []; this.boulders = []; this.pickups = []; this.entrances = []; this.graves = []; this.wraiths = []; this.cannons = [];
    this.genRegion(0); this.genRegion(1);
  },
  levelOf(i) { return Math.floor(i / CFG.BOSS_EVERY) + 1; }, // which level a region index belongs to (the arena closes its level)
  bossFor(level) { return level >= 5 ? 'clowncar' : level === 4 ? 'overlord' : level === 3 ? 'serpent' : level === 2 ? 'skull' : 'crab'; },  // the boss that ends each level
  regionAt(x) { return U.clamp(Math.floor(x / CFG.REGION_W), 0, this.regions.length - 1); },
  // 0 = sunny meadow, 1 = full nightmare. The further right, the more sinister.
  dread(i) { return U.clamp(i / CFG.DREAD_REGIONS, 0, 1); },
  regionName(R) { const n = BIOMES[R.biome].name; if (R.boss) return `${n} ${Math.floor(R.idx / CFG.BOSS_EVERY) + 1}`; return R.idx >= 9 ? `Deep ${n}` : R.idx >= 6 ? `Cursed ${n}` : R.idx >= 3 ? `Dark ${n}` : n; },
  ensure(idx) { while (this.regions.length <= idx) this.genRegion(this.regions.length); },

  genRegion(i) {
    const x0 = i * CFG.REGION_W, x1 = x0 + CFG.REGION_W;
    const boss = i > 0 && i % CFG.BOSS_EVERY === CFG.BOSS_EVERY - 1; // after 6 wall-breaks: the boss arena
    const level = this.levelOf(i), haunted = level === 2, jungle = level === 3, farm = level === 4, circus = level >= 5; // graveyard past the crab, jungle past the Skull King, farmland past the Serpent, the circus past the Overlord
    const biome = boss ? (circus ? 'bigtop' : farm ? 'landing' : jungle ? 'ziggurat' : haunted ? 'ossuary' : 'arena') : circus ? 'midway' : farm ? 'farm' : jungle ? 'jungle' : haunted ? 'graveyard' : i === 0 ? 'meadow' : U.pick(['meadow', 'forest', 'wetland', 'rocky', 'forest']);
    const B = BIOMES[biome];
    const R = { idx: i, x0, x1, biome, level, haunted, jungle, farm, circus, spawnT: 4, wall: null, boss, locked: boss, bossDone: false, deco: [] };
    // wall at the right edge, in breakable sections
    const sections = [];
    for (let y = 0; y < CFG.WORLD_H; y += CFG.WALL_SECTION) {
      const hp = 22 + 16 * i;
      sections.push({ y0: y, y1: Math.min(CFG.WORLD_H, y + CFG.WALL_SECTION), hp, max: hp, alive: true });
    }
    R.wall = { x: x1, sections };
    this.regions.push(R);

    const safeX = i === 0 ? 300 : x0 + 200, safeY = CFG.WORLD_H / 2; // spawn / entry pocket
    const margin = 70;
    const okSpot = (x, y, r) => {
      if (i === 0 && U.dist(x, y, safeX, safeY) < 260) return false;
      if (U.dist(x, y, x0 + 60, safeY) < 150) return false; // entry lane after breaching a wall
      for (const t of this.trees) if (U.dist2(t.x, t.y, x, y) < (t.r + r + 10) ** 2) return false;
      for (const b of this.boulders) if (U.dist2(b.x, b.y, x, y) < (b.r + r + 10) ** 2) return false;
      for (const l of this.lakes) if (((x - l.x) / (l.rx + r)) ** 2 + ((y - l.y) / (l.ry + r)) ** 2 < 1) return false;
      for (const e of this.entrances) if (U.dist2(e.x, e.y, x, y) < (60 + r) ** 2) return false;
      for (const g of this.graves) if (U.dist2(g.x, g.y, x, y) < (g.r + r + 14) ** 2) return false;
      for (const cn of this.cannons) if (U.dist2(cn.x, cn.y, x, y) < (cn.r + r + 20) ** 2) return false;
      for (const d of R.deco) if (d.solid && U.dist2(d.x, d.y, x, y) < (d.solid + r + 10) ** 2) return false;
      return true;
    };
    const place = (r, tries) => {
      for (let k = 0; k < (tries || 30); k++) {
        const x = U.rand(x0 + margin, x1 - margin - 40), y = U.rand(CFG.HEDGE + margin, CFG.WORLD_H - CFG.HEDGE - margin);
        if (okSpot(x, y, r)) return { x, y };
      }
      return null;
    };
    for (let k = 0; k < B.lakes; k++) {
      const rx = U.rand(90, 220), ry = U.rand(60, 150);
      const p = place(Math.max(rx, ry), 20);
      if (p) this.lakes.push({ x: p.x, y: p.y, rx, ry, seed: Math.random() * 10, region: i, pit: circus }); // (circus lakes are ball pits)
    }
    // dungeon entrance (every region after the first; 60% chance in the first; never in the arena)
    if (!boss && (i > 0 || U.chance(0.6))) {
      const p = place(50, 40);
      if (p) this.entrances.push({ x: p.x, y: p.y, region: i, used: false });
    }
    for (let k = 0; k < B.trees; k++) {
      const r = U.rand(22, 34);
      const p = place(r);
      if (p) this.trees.push({ x: p.x, y: p.y, r: jungle ? r * 1.15 : r, hue: U.rand(-12, 12), seed: Math.random() * 10, region: i, alive: true, charred: haunted, jungle, palm: jungle && U.chance(0.45), corn: farm && U.chance(0.7), farm, shroom: circus && U.chance(0.65), lolly: circus && U.chance(0.35), hive: level === 1 && !boss && !farm && U.chance(0.22) }); // some meadow trees carry a beehive: shoot it and the bees take your side
    }
    // circus cannons: solid, and circling one fires you out of it (see updateCannons)
    for (let k = 0; k < (B.cannons || 0); k++) {
      const p = place(30, 40);
      if (p) this.cannons.push({ x: p.x, y: p.y, r: 24, a: U.rand(-0.6, 0.6), seed: Math.random() * 10, region: i, spin: 0, lastAng: null, spinT: 0, cd: 0 });
    }
    for (let k = 0; k < B.boulders; k++) {
      const r = U.rand(16, 24);
      const p = place(r);
      if (p) this.boulders.push({ x: p.x, y: p.y, r, seed: Math.random() * 10, region: i });
    }
    // tombstones: solid, and circling one raises a ghost (see updateGraves)
    for (let k = 0; k < (B.graves || 0); k++) {
      const p = place(22);
      if (p) this.graves.push({ x: p.x, y: p.y, r: 18, kind: U.pick(['stone', 'cross', 'slab', 'stone']), seed: Math.random() * 10, region: i, used: false, spin: 0, lastAng: null, spinT: 0 });
    }
    if (haunted) for (let k = 0; k < (boss ? 40 : 26); k++) { // bones, skulls, toadstools and wisps scattered on the ground
      const x = U.rand(x0 + 60, x1 - 60), y = U.rand(CFG.HEDGE + 40, CFG.WORLD_H - CFG.HEDGE - 40);
      if (!this.inLake(x, y)) R.deco.push({ x, y, kind: U.pick(boss ? ['bone', 'bone', 'skull', 'skull', 'rib'] : ['bone', 'bone', 'skull', 'shroom', 'wisp', 'rib']), seed: Math.random() * 10, a: U.rand(0, TAU) });
    }
    if (haunted && !boss) for (let k = 0; k < 2; k++) { const p = place(26, 30); if (p) R.deco.push({ x: p.x, y: p.y, kind: 'coffin', seed: Math.random() * 10, a: U.rand(-0.3, 0.3), open: false, solid: 22 }); } // a coffin: crawl up to it and it opens. Treasure, or something awful
    if (farm) { // crop circles (big, flattened), barns and scarecrows, pumpkins, a crashed probe or two, and one tractor
      for (let k = 0; k < (boss ? 5 : U.randi(1, 3)); k++) { const x = U.rand(x0 + 300, x1 - 300), y = U.rand(250, CFG.WORLD_H - 250); R.deco.push({ x, y, kind: 'circle', seed: Math.random() * 10, a: U.rand(0, TAU), big: boss && k === 0, gift: !boss, stand: 0 }); } // stand in the centre of one and something beams down
      for (let k = 0; k < (boss ? 10 : 24); k++) {
        const x = U.rand(x0 + 60, x1 - 60), y = U.rand(CFG.HEDGE + 40, CFG.WORLD_H - CFG.HEDGE - 40);
        if (!this.inLake(x, y)) R.deco.push({ x, y, kind: U.pick(boss ? ['probe', 'pumpkin', 'probe'] : ['pumpkin', 'pumpkin', 'scarecrow', 'probe', 'pumpkin', 'fence']), seed: Math.random() * 10, a: U.rand(0, TAU) });
      }
      if (!boss) for (let k = 0; k < 2; k++) { const p = place(44, 30); if (p) R.deco.push({ x: p.x, y: p.y, kind: 'barn', seed: Math.random() * 10, a: 0, used: false, spin: 0, lastAng: null, spinT: 0, doorT: 0, solid: 40 }); } // barns are solid, and circling one three times opens the doors (updateBarns)
      if (!boss) { const p = place(30, 30); if (p) R.deco.push({ x: p.x, y: p.y, kind: 'tractor', seed: Math.random() * 10, a: 0, used: false, solid: 0 }); } // climb aboard: eight seconds of plowing through everything
    }
    if (jungle) for (let k = 0; k < (boss ? 30 : 34); k++) { // ferns, flowers, fallen gold, carved glyph stones, skull poles
      const x = U.rand(x0 + 60, x1 - 60), y = U.rand(CFG.HEDGE + 40, CFG.WORLD_H - CFG.HEDGE - 40);
      if (!this.inLake(x, y)) R.deco.push({ x, y, kind: U.pick(boss ? ['glyph', 'gold', 'skullpole', 'glyph'] : ['fern', 'fern', 'flower', 'flower', 'gold', 'glyph', 'skullpole', 'fern']), seed: Math.random() * 10, a: U.rand(0, TAU) });
    }
    if (jungle && !boss) for (let k = 0; k < 2; k++) { const p = place(40, 30); if (p) R.deco.push({ x: p.x, y: p.y, kind: 'geyser', seed: Math.random() * 10, a: 0, t: U.rand(0, CFG.GEYSER.every), on: 0 }); } // geysers: lure monsters over one
    if (circus) { // the midway: balloon bunches, ticket booths, little tents, popcorn, jack-in-the-boxes, a hall of mirrors, a fortune teller, drifting confetti
      for (let k = 0; k < (boss ? 14 : 30); k++) {
        const x = U.rand(x0 + 60, x1 - 60), y = U.rand(CFG.HEDGE + 40, CFG.WORLD_H - CFG.HEDGE - 40);
        if (!this.inLake(x, y)) R.deco.push({ x, y, kind: U.pick(boss ? ['balloons', 'popcorn', 'confetti', 'confetti', 'spiral', 'pole', 'clownface'] : ['balloons', 'balloons', 'popcorn', 'popcorn', 'confetti', 'confetti', 'spiral', 'checker', 'tent', 'pole', 'pole', 'clownface', 'stall']), seed: Math.random() * 10, a: U.rand(0, TAU), big: boss });
      }
      { const p = place(110, 40); if (p) R.deco.push({ x: p.x, y: p.y - 40, kind: 'ferris', seed: Math.random() * 10, a: 0, solid: 36 }); } // a Ferris wheel turning over the midway
      { const p = place(70, 40); if (p) R.deco.push({ x: p.x, y: p.y, kind: 'carousel', seed: Math.random() * 10, a: 0, solid: 52 }); } // a merry-go-round
      for (let k = 0; k < 2; k++) { const p = place(36, 30); if (p) R.deco.push({ x: p.x, y: p.y, kind: 'stall', seed: Math.random() * 10, a: 0, solid: 30 }); }
      if (!boss) {
        for (let k = 0; k < 3; k++) { const p = place(16, 30); if (p) R.deco.push({ x: p.x, y: p.y, kind: 'jack', seed: Math.random() * 10, a: 0, sprung: false, t: 0 }); } // a jack-in-the-box: crawl past and a clown pops out
        { const p = place(60, 40); if (p) R.deco.push({ x: p.x, y: p.y, kind: 'mirror', seed: Math.random() * 10, a: 0, solid: 0 }); } // a hall of mirrors: your reflection crawls on the other side
        { const p = place(40, 40); if (p) R.deco.push({ x: p.x, y: p.y, kind: 'fortune', seed: Math.random() * 10, a: 0, used: false, solid: 0 }); } // the fortune teller: touch the tent and a card flips
        { const p = place(30, 40); if (p) R.deco.push({ x: p.x, y: p.y, kind: 'booth', seed: Math.random() * 10, a: 0, solid: 26 }); }
        if (U.chance(0.7)) { const p = place(12); if (p) this.pickups.push(this.mkPickup(p.x, p.y, U.pick(['cake', 'potion', 'shroom']), 'oddity', i)); } // Eat Me, Drink Me, or the mushroom
      }
    }
    if (boss) { // the arena: just the boss, sleeping under the ground until you arrive
      Enemies.spawnBoss(this.bossFor(level), x0 + 1900, CFG.WORLD_H / 2, i, level);
      return;
    }
    // loot only comes from kills; the only things lying around are the odd Cleanse and the parasites hunting you
    const nP = Math.round(Math.min(CFG.PARA_SHARE.seedMax, 2 + Math.floor(i / 2)) * Enemies.paraSpawnAvg()); // (x the bestiary's spawn rates)
    for (let k = 0; k < nP; k++) { const p = place(12), id = Enemies.pickParasite(); if (p && id && !(i === 0 && p.x < x0 + 900)) Enemies.spawnParasite(id, p.x, p.y, i); }
    if (U.chance(0.25)) { const p = place(12); if (p) this.pickups.push(this.mkPickup(p.x, p.y, 'cleanse', 'cleanse', i)); }
    // enemies (more per level cleared)
    const extra = CFG.DENSITY_LEVEL * (G.level - 1);
    const nS = Math.round((Math.min(7, 1 + i) + Math.ceil(extra / 2)) * Enemies.snakeSpawnAt(i)), nB = 4 + Math.min(6, i) + extra;
    for (let k = 0; k < nS; k++) { const p = place(14); if (p && !(i === 0 && p.x < x0 + 1400) && !Enemies.maybeUnique(p.x, p.y, i)) Enemies.spawnSnake(p.x, p.y, i); }
    for (let k = 0; k < nB; k++) { const p = place(14); if (p && !(i === 0 && p.x < x0 + 700) && !Enemies.maybeUnique(p.x, p.y, i)) Enemies.spawnBug(Enemies.pickBug(i), p.x, p.y, i); }
    // a named unique, maybe (one roll per region, only one of each alive in the world)
    const pool = Enemies.uniquePool(i).filter(id => !Enemies.uniqueAlive(id));
    if (pool.length) { const id = U.pick(pool); if (U.chance(UNIQUES[id].chance)) { const p = place(24, 40); if (p && p.x > x0 + 900) Enemies.spawnUnique(id, p.x, p.y, i); } }
  },
  update(dt) {
    { const px = Player.x, out = []; for (const t of this.trees) if (t.alive && Math.abs(t.x - px) < 1500) out.push(t); this.nearTrees = out; } // trees within reach of anything that moves this frame
    const P = Player;
    const ri = this.regionAt(P.x);
    this.ensure(ri + 1);
    this.updateRocks(dt); this.updateGraves(dt); this.updateTrees(dt); this.updateCannons(dt); this.updateDeco(dt); this.updateBarns(dt);
    if (G.rubber > 0) G.rubber -= dt;
    const R = this.regions[ri];
    if (ri > G.regionMax) {
      G.regionMax = ri; Save.write(); // checkpoint
      if (R.boss) { G.ascend(R); return; } // level up: climb to the boss arena
      G.msg(`Region ${ri + 1}: ${this.regionName(R)}`, ri >= 3 ? '#c58aff' : '#fff');
      { const th = Music.levelTheme(R.level); if (th) { if (Music.name !== th || !Music.on) Music.start(th); } else if (Music.on && (Music.isLevelTheme(Music.name) || !G.enemies.some(e => e.kind === 'boss' && !e.dead && e.awake))) Music.stop(); } // and never a boss theme with no boss // level music (a tracker theme named level2 or graveyard, level3 or jungle...), if there is one
      if (R.haunted && !G.hauntedIntro) { G.hauntedIntro = true; G.msg('THE GRAVEYARD. Circle a tombstone to summon its ghost: it rises and turns every monster nearby to your side. Flaming skulls hunt here, and bone spears drop.', '#dfe6ff'); }
      if (R.farm && !G.farmIntro) { G.farmIntro = true; G.msg('THE FARM. They have landed. Brainlings with ray guns, scout saucers, and the cows are not themselves. Circle a hay bale to roll it at them, or a barn to let the tractors out.', '#ff5cf0'); }
      if (R.jungle && !G.jungleIntro) { G.jungleIntro = true; G.msg('THE JUNGLE. Earwigs lunge, cockroaches swarm in armour, and the iguanas sit still: their tongues reach 300px and slurp a segment clean off. Circle a tree to summon its song: every monster nearby, spiders included, comes to listen, hypnotized, and forgets you.', '#9bf06a'); }
      if (R.circus && !G.circusIntro) { G.circusIntro = true; G.msg('THE CIRCUS. Clowns on unicycles, jugglers, mimes with invisible walls, pie throwers, pink elephants. Circle a CANNON to summon a shot: it fires YOU through the crowd. Juggling pins drop here: they ricochet.', '#ff5c8a'); }
    }
    this.updateSwarm(dt, ri, R);
    // ongoing spawns in the current region (none in the boss arena)
    R.spawnT -= dt;
    if (R.spawnT <= 0 && !R.boss) {
      const base = CFG.DENSITY + ri + CFG.DENSITY_LEVEL * (G.level - 1) + (G.nightmare ? CFG.NIGHTMARE.density : 0), extra = this.crowdBonus();
      G.crowd = extra; G.lootScale = base / (base + extra); // (kills come that much faster, so each one's loot is that much rarer)
      R.spawnT = Math.max(extra ? 0.8 : 1.6, (8 - ri * 0.7 - (G.level - 1)) * G.lootScale);
      let count = 0;
      for (const e of Enemies.list) if (!e.dead && e.region === ri && !(e.ally > 0)) count++;
      if (count < base + extra) { // a stronger build draws a bigger crowd
        for (let k = 0; k < 20; k++) {
          const x = U.rand(R.x0 + 80, R.x1 - 80), y = U.rand(80, CFG.WORLD_H - 80);
          if (U.dist(x, y, P.x, P.y) > 380 && !this.inLake(x, y)) {
            const roll = Math.random();
            const ps = Math.min(CFG.PARA_SHARE.max, CFG.PARA_SHARE.base + CFG.PARA_SHARE.perRegion * ri) * Enemies.paraSpawnAvg(), ss = Math.min(0.85, (0.45 + 0.02 * ri) * Enemies.snakeSpawnAt(ri)); // (x the bestiary's spawn rates)
            if (roll >= ps && Enemies.maybeUnique(x, y, ri)) { /* nightmare: a named unique instead */ }
            else if (roll < ps) { const id = Enemies.pickParasite(); if (id) Enemies.spawnParasite(id, x, y, ri); } // more spiders the deeper you go, up to a cap
            else if (roll < ps + ss) Enemies.spawnSnake(x, y, ri);
            else Enemies.spawnBug(Enemies.pickBug(ri), x, y, ri);
            break;
          }
        }
      }
    }
    for (const en of this.entrances) {
      if (en.cd > 0) en.cd -= dt; // just-left entrances wait a moment before they take you again
      if (!this.regions[en.region].farm || en.used || Math.abs(en.x - P.x) > 700) continue;
      // the farm's landed saucers want you aboard: every so often one locks a TRACTOR BEAM on you and pulls for two seconds (steer against it, or let it reel you into the dungeon)
      en.beamT = (en.beamT === undefined ? U.rand(3, 6) : en.beamT) - dt;
      if (en.beam > 0) {
        en.beam -= dt;
        if (P.alive && !P.flight && G.mode === 'world') { const d = U.dist(en.x, en.y, P.x, P.y) || 1; const k = 150 * dt; P.x += (en.x - P.x) / d * k; P.y += (en.y - P.y) / d * k; if (U.chance(dt * 20)) Combat.particles.push(Combat.mk(P.x + U.rand(-10, 10), P.y + U.rand(-10, 10), 0, -60, 0.5, '#9bf06a', 2)); }
        if (en.beam <= 0) { en.beam = 0; en.beamT = U.rand(7, 11); }
      } else if (en.beamT <= 0 && en.cd <= 0 && P.alive && P.ready && U.dist(en.x, en.y, P.x, P.y) < 460) { en.beam = 2; SFX.play('abduct'); SFX.play('moo'); G.msg('TRACTOR BEAM! The saucer is pulling you in. Steer against it, or let it take you.', '#9bf06a'); }
    }
    // pickups bob; dead ones are swept
    const fizzled = [];
    for (let i = this.pickups.length - 1; i >= 0; i--) {
      const pk = this.pickups[i];
      if (!pk.alive) { this.pickups.splice(i, 1); continue; }
      if (pk.lift) { const L = pk.lift; L.t -= dt; const k = Math.max(0, L.t / L.d); pk.x += (L.tx - pk.x) * Math.min(1, dt * 6); pk.y += (L.ty - pk.y) * Math.min(1, dt * 6); if (L.t <= 0) { pk.alive = false; this.pickups.splice(i, 1); } continue; } // up the tractor beam
      if (Math.abs(pk.x - P.x) > 1600) continue;
      pk.bob += dt * 3;
      if (pk.vx || pk.vy) { // thrown pieces skid to a stop, and stay where you can reach them: inside their region (never over its wall), off the hedge, out of the water
        pk.x += pk.vx * dt; pk.y += pk.vy * dt; const f = Math.exp(-4 * dt); pk.vx *= f; pk.vy *= f; if (Math.abs(pk.vx) + Math.abs(pk.vy) < 3) pk.vx = pk.vy = 0;
        const R = this.regions[pk.region] || this.regions[this.regionAt(pk.x)], x0 = (R ? R.x0 : 0) + CFG.HEDGE + 40, x1 = R ? R.wall.x - 50 : this.regions.length * CFG.REGION_W - 20;
        if (pk.x < x0) { pk.x = x0; pk.vx = Math.abs(pk.vx) * 0.5; } if (pk.x > x1) { pk.x = x1; pk.vx = -Math.abs(pk.vx) * 0.5; }
        if (pk.y < CFG.HEDGE + 40) { pk.y = CFG.HEDGE + 40; pk.vy = Math.abs(pk.vy) * 0.5; } if (pk.y > CFG.WORLD_H - CFG.HEDGE - 40) { pk.y = CFG.WORLD_H - CFG.HEDGE - 40; pk.vy = -Math.abs(pk.vy) * 0.5; }
        for (const l of this.lakes) { const nx = (pk.x - l.x) / (l.rx + 30), ny = (pk.y - l.y) / (l.ry + 30), q = nx * nx + ny * ny; if (q < 1 && q > 0) { const s = 1 / Math.sqrt(q); pk.x = l.x + nx * s * (l.rx + 30); pk.y = l.y + ny * s * (l.ry + 30); pk.vx *= 0.3; pk.vy *= 0.3; } } // bounced off the water's edge
      }
      if (pk.fuse != null) { pk.fuse -= dt; if (pk.fuse <= 0) { pk.alive = false; Combat.burst(pk.x, pk.y, 8, '#8a8a99', 2); if (pk.kind === 'weapon') fizzled.push(BULLETS[pk.id].name); continue; } } // a loose piece fizzles away
      if (pk.kind === 'weapon' && !P.wouldTake(pk.id, pk)) continue; // no room for it: it stays where it is instead of trailing you
      { // base magnet: anything within 10% of the screen drifts to you, so you never orbit a dot; Loot Magnet stacks reach further
        const R = Perks.magnetRadius(), d = U.dist(pk.x, pk.y, P.x, P.y);
        if (d < R && d > 1) { const sp = (220 + 60 * G.mods.magnet) * (1 - d / R) + 80; pk.x += (P.x - pk.x) / d * sp * dt; pk.y += (P.y - pk.y) / d * sp * dt; }
      }
    }
    if (fizzled.length) { G.msg(`${fizzled.join(', ')} fizzled away.`, '#aaa'); SFX.play('shed'); }
  }
};
