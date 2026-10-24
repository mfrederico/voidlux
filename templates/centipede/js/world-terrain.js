// Terrain: what stops bullets and the centipede (trees, boulders, tombstones), the thorn walls between regions
// (finding them, breaking them) and the lakes.
// Extends World (see world.js). Loads after it.
Object.assign(World, {
  // is a circle at (x,y,r) inside a tree canopy or a boulder? (bullets stop here). Remembers what was hit.
  lastSolid: null,
  solidAt(x, y, r) {
    const trees = this.nearTrees && Math.abs(x - Player.x) < 1400 ? this.nearTrees : this.trees; // (the near list: built once a frame in update)
    for (const t of trees) { if (!t.alive || Math.abs(t.x - x) > 60) continue; if (U.dist2(t.x, t.y - t.r * 0.4, x, y) < (t.r * 0.8 + r) ** 2) { this.lastSolid = { tree: t }; return true; } }
    for (const b of this.boulders) { if (b.gone || Math.abs(b.x - x) > 60) continue; if (U.dist2(b.x, b.y, x, y) < (b.r + r) ** 2) { this.lastSolid = { boulder: b }; return true; } }
    for (const g of this.graves) { if (Math.abs(g.x - x) > 60) continue; if (U.dist2(g.x, g.y - 6, x, y) < (g.r * 0.7 + r) ** 2) { this.lastSolid = { grave: g }; return true; } }
    for (const cn of this.cannons) { if (Math.abs(cn.x - x) > 60) continue; if (U.dist2(cn.x, cn.y, x, y) < (cn.r * 0.8 + r) ** 2) { this.lastSolid = { cannon: cn }; return true; } }
    if (G.mode === 'world') for (const e of (Combat.near || G.enemies)) { // a mime's invisible wall: a line in front of it that bullets cannot cross (the combat step's nearby list, not the whole world)
      if (e.dead || e.hidden || !e.mimeWall) continue; if (Math.abs(e.x - x) > 200) continue;
      const W = e.mimeWall, rx = x - W.x, ry = y - W.y, along = rx * Math.cos(W.a) + ry * Math.sin(W.a), perp = Math.abs(-rx * Math.sin(W.a) + ry * Math.cos(W.a));
      if (Math.abs(along) < W.len / 2 + r && perp < 6 + r) { this.lastSolid = { mime: e }; return true; }
    }
    this.lastSolid = null;
    return false;
  },
  meltTree(t) { if (!t.alive) return; t.alive = false; Combat.burst(t.x, t.y - 20, 22, '#b6ff5c', 3); Combat.burst(t.x, t.y, 10, '#5fae4c', 3); G.msg('Melted a tree!', '#b6ff5c'); },
  fryBoulder(b) { if (b.gone) return; b.gone = true; Combat.burst(b.x, b.y, 26, '#9bf7ff', 3); Combat.burst(b.x, b.y, 14, '#a9a2b3', 3); SFX.play('wall'); G.msg('Fried a boulder!', '#9bf7ff'); },

  // an intact wall section strictly between x0 and x1 at height y (a move that jumped clean across one: a geyser, a shove, a hitch)
  wallBetween(x0, x1, y) {
    if (x0 == null || Math.abs(x1 - x0) < 1) return null;
    const lo = Math.min(x0, x1), hi = Math.max(x0, x1);
    for (let i = Math.max(0, Math.floor(lo / CFG.REGION_W) - 1); i <= Math.min(this.regions.length - 1, Math.floor(hi / CFG.REGION_W)); i++) {
      const w = this.regions[i].wall; if (!w || !((x0 - w.x) * (x1 - w.x) < 0)) continue;
      const s = w.sections[U.clamp(Math.floor(y / CFG.WALL_SECTION), 0, w.sections.length - 1)];
      if (s.alive) return { region: this.regions[i], section: s, x: w.x };
    }
    return null;
  },
  // wall section hit by a circle at (x,y,r); returns {region, section} or null
  wallAt(x, y, r) {
    const idx = Math.floor(x / CFG.REGION_W);
    for (const i of [idx - 1, idx]) {
      if (i < 0 || i >= this.regions.length) continue;
      const w = this.regions[i].wall;
      if (Math.abs(x - w.x) < CFG.WALL_T / 2 + r) {
        const s = w.sections[U.clamp(Math.floor(y / CFG.WALL_SECTION), 0, w.sections.length - 1)];
        if (s.alive) return { region: this.regions[i], section: s, x: w.x };
      }
    }
    return null;
  },
  damageWall(hit, dmg) {
    const s = hit.section;
    if (hit.region.locked) { if (U.chance(0.1)) G.msg('The wall is sealed until the boss falls.', '#ff9b8b'); return; }
    if (G.swarm && (hit.region.idx === G.swarm.region || hit.region.idx === G.swarm.region - 1)) { if (U.chance(0.1)) G.msg('The swarm has sealed the walls: wipe it out first.', '#ffd166'); return; } // (the walls on both sides of a swarm's region)
    s.hp -= dmg;
    if (s.hp <= 0) {
      s.alive = false;
      Combat.burst(hit.x, (s.y0 + s.y1) / 2, 30, '#3f6b2a', 3);
      Combat.burst(hit.x, (s.y0 + s.y1) / 2, 14, '#9bd27a', 2);
      SFX.play('wall');
      G.msg('Wall breached!', '#9bf06a');
    }
  },
  inLake(x, y) {
    for (const l of this.lakes) if (((x - l.x) / l.rx) ** 2 + ((y - l.y) / l.ry) ** 2 < 1) return true;
    return false;
  },
});
