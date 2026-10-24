// The overworld: ground, regions, lakes, trees, hedges and walls, every biome's decorations (drawDeco) and the pickups.
// Extends Render (see render.js). Loads after render.js. The biomes' own drawers live in render-circus.js,
// render-farm.js, render-jungle.js and render-graveyard.js.
Object.assign(Render, {
  // ---- overworld ----
  drawWorld() {
    const c = this.ctx, cam = G.cam;
    const vx0 = cam.x - 50, vx1 = cam.x + this.viewW() + 50, vy0 = cam.y - 50, vy1 = cam.y + this.viewH() + 50;
    for (const R of World.regions) {
      if (R.x1 < vx0 || R.x0 > vx1) continue;
      const B = BIOMES[R.biome];
      const grd = c.createLinearGradient(0, 0, 0, CFG.WORLD_H);
      if (R.circus) { const h = (G.time * 14 + R.idx * 50) % 360; grd.addColorStop(0, `hsl(${h},55%,48%)`); grd.addColorStop(0.5, `hsl(${(h + 60) % 360},60%,42%)`); grd.addColorStop(1, `hsl(${(h + 120) % 360},55%,38%)`); } // the midway: the ground never settles on a colour
      else { grd.addColorStop(0, B.grass2); grd.addColorStop(1, B.grass); }
      c.fillStyle = grd; c.fillRect(R.x0, 0, CFG.REGION_W, CFG.WORLD_H);
      const dr = World.dread(R.idx); // the further right, the darker and more purple the land (grey-blue once it is haunted)
      if (dr > 0 && !R.circus) { c.fillStyle = R.haunted ? `rgba(18,18,30,${dr * 0.3})` : R.farm ? `rgba(20,60,20,${dr * 0.18})` : `rgba(28,4,48,${dr * 0.42})`; c.fillRect(R.x0, 0, CFG.REGION_W, CFG.WORLD_H); }
      if (R.circus) { // candy stripes down the whole region, breathing; and in the arena, the centre ring
        c.save(); c.globalAlpha = 0.1 + 0.04 * Math.sin(G.time * 1.3);
        const w = 90 + 10 * Math.sin(G.time * 0.7); for (let x = R.x0; x < R.x1; x += w * 2) { if (x + w < vx0 || x > vx1) continue; c.fillStyle = '#fff'; c.fillRect(x, 0, w, CFG.WORLD_H); }
        c.restore();
        if (R.boss) { const cx = R.x0 + 1900, cy = CFG.WORLD_H / 2; c.save(); c.globalAlpha = 0.5; for (let k = 0; k < 3; k++) { c.fillStyle = k % 2 ? '#ffd166' : '#ff5c8a'; c.beginPath(); c.arc(cx, cy, 420 - k * 60, 0, TAU); c.fill(); } c.fillStyle = '#c8508a'; c.beginPath(); c.arc(cx, cy, 240, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 4; c.beginPath(); c.arc(cx, cy, 420, 0, TAU); c.stroke(); c.restore(); }
      }
      if (R.farm) { // crop rows: alternating strips with a stitch of plants down each
        for (let y = CFG.HEDGE; y < CFG.WORLD_H - CFG.HEDGE; y += 48) { c.fillStyle = (y / 48 | 0) % 2 ? 'rgba(0,0,0,0.07)' : 'rgba(255,255,220,0.05)'; c.fillRect(R.x0, y, CFG.REGION_W, 48); }
        c.strokeStyle = 'rgba(40,70,20,0.35)'; c.lineWidth = 2; c.setLineDash([6, 10]);
        for (let y = CFG.HEDGE + 24; y < CFG.WORLD_H - CFG.HEDGE; y += 48) { c.beginPath(); c.moveTo(Math.max(R.x0, vx0), y); c.lineTo(Math.min(R.x1, vx1), y); c.stroke(); }
        c.setLineDash([]);
      }
    }
    c.fillStyle = this.grassTile; c.fillRect(vx0, vy0, vx1 - vx0, vy1 - vy0);
    for (const R of World.regions) { // jungle: dappled canopy shade, and the stepped pyramid in the arena
      if (!R.jungle || R.x1 < vx0 || R.x0 > vx1) continue;
      for (let k = 0; k < 14; k++) { const x = R.x0 + (k * 587 + R.idx * 131) % CFG.REGION_W, y = (k * 311 + 70) % CFG.WORLD_H; if (x < vx0 - 200 || x > vx1 + 200) continue; c.fillStyle = 'rgba(0,30,10,0.13)'; c.beginPath(); c.ellipse(x, y, 180 + (k % 3) * 50, 60 + (k % 4) * 20, (k % 5) * 0.4, 0, TAU); c.fill(); }
      if (R.boss) this.drawZiggurat(R.x0 + 1900, CFG.WORLD_H / 2);
    }
    // graveyard litter: bones, skulls, ribcages, toadstools, wisps; jungle: ferns, flowers, gold, glyph stones, skull poles
    for (const R of World.regions) { if (!R.deco.length || R.x1 < vx0 || R.x0 > vx1) continue; for (const d of R.deco) if (d.x > vx0 && d.x < vx1) this.drawDeco(d); }
    // lakes
    for (const l of World.lakes) {
      if (l.x + l.rx < vx0 || l.x - l.rx > vx1) continue;
      c.fillStyle = '#c9ad79'; c.beginPath(); c.ellipse(l.x, l.y + 6, l.rx + 10, l.ry + 10, 0, 0, TAU); c.fill();
      const g2 = c.createRadialGradient(l.x - l.rx * 0.3, l.y - l.ry * 0.3, 10, l.x, l.y, Math.max(l.rx, l.ry));
      g2.addColorStop(0, '#9fe3ff'); g2.addColorStop(1, '#4aa7e6');
      c.fillStyle = l.pit ? '#3a1a4a' : g2; c.beginPath(); c.ellipse(l.x, l.y, l.rx, l.ry, 0, 0, TAU); c.fill();
      if (l.pit) { c.save(); c.clip(); const cols = ['#ff5c8a', '#ffd166', '#7df9ff', '#9bf06a', '#f3c6ff', '#ff8a3d']; for (let k = 0; k < 60; k++) { const bx = l.x + Math.cos(k * 2.4 + l.seed) * l.rx * Math.sqrt((k % 10) / 10) * 0.95, by = l.y + Math.sin(k * 2.4 + l.seed) * l.ry * Math.sqrt((k % 10) / 10) * 0.95; this.circle(bx, by, 9, cols[k % 6], this.OUT, 1.5); } c.restore(); c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.ellipse(l.x, l.y, l.rx, l.ry, 0, 0, TAU); c.stroke(); this.text('BALL PIT', l.x, l.y - l.ry - 12, 11, '#ffd166', 'center'); continue; } // a ball pit (it slows you like water)
      const ldr = World.dread(l.region); if (ldr > 0) { c.fillStyle = `rgba(20,0,40,${ldr * 0.5})`; c.fill(); } // murky water deeper in
      c.strokeStyle = 'rgba(255,255,255,0.5)'; c.lineWidth = 3;
      for (let k = 0; k < 3; k++) { const t = G.time * 0.8 + l.seed + k * 2; const rx = l.rx * (0.3 + 0.2 * k) + Math.sin(t) * 6, ry = l.ry * (0.3 + 0.2 * k) + Math.sin(t) * 4; c.beginPath(); c.ellipse(l.x, l.y, rx, ry, 0, 0.6 + k, 1.6 + k); c.stroke(); }
    }
    // dungeon entrances
    for (const en of World.entrances) {
      if (en.x < vx0 || en.x > vx1) continue;
      if (World.regions[en.region].haunted) { this.drawCrypt(en); continue; }
      if (World.regions[en.region].jungle) { this.drawTempleDoor(en); continue; }
      if (World.regions[en.region].farm) { this.drawSaucerHatch(en); continue; }
      if (World.regions[en.region].circus) { this.drawTentDoor(en); continue; }
      c.fillStyle = '#6b5340'; c.beginPath(); c.ellipse(en.x, en.y + 8, 44, 30, 0, 0, TAU); c.fill();
      c.fillStyle = '#8c7055'; c.beginPath(); c.arc(en.x, en.y - 6, 36, Math.PI, 0); c.lineTo(en.x + 36, en.y + 12); c.lineTo(en.x - 36, en.y + 12); c.closePath(); c.fill();
      c.strokeStyle = this.OUT; c.lineWidth = 3; c.stroke();
      c.fillStyle = en.used ? '#3a2a22' : '#1a1026'; c.beginPath(); c.arc(en.x, en.y, 22, Math.PI, 0); c.lineTo(en.x + 22, en.y + 12); c.lineTo(en.x - 22, en.y + 12); c.closePath(); c.fill();
      if (!en.used) {
        const pulse = 0.5 + 0.5 * Math.sin(G.time * 4);
        c.fillStyle = `rgba(190,120,255,${0.25 + 0.35 * pulse})`; c.beginPath(); c.arc(en.x, en.y + 2, 10 + pulse * 4, 0, TAU); c.fill();
        this.text('DUNGEON', en.x, en.y - 48, 14, '#e8d6ff', 'center');
      }
    }
    // stumps, tombstones and boulders
    for (const t of World.trees) {
      if (t.alive || t.x < vx0 || t.x > vx1) continue;
      if (t.charred) { this.circle(t.x, t.y, 9, '#3a3338', this.OUT, 2); this.circle(t.x, t.y, 4, '#6a6066'); continue; }
      this.circle(t.x, t.y, 9, '#a2763f', this.OUT, 2); this.circle(t.x, t.y, 4, '#d9b06d');
    }
    for (const g of World.graves) if (g.x > vx0 && g.x < vx1) this.drawGrave(g);
    for (const cn of World.cannons) if (cn.x > vx0 && cn.x < vx1) this.drawCannon(cn);
    for (const b of World.boulders) {
      if (b.gone || b.x < vx0 || b.x > vx1) continue;
      if (World.regions[b.region].jungle) { this.drawIdol(b); continue; }
      if (World.regions[b.region].farm) { this.drawHay(b); continue; }
      if (World.regions[b.region].circus) { this.drawBeachBall(b); continue; }
      const laps = Math.abs(b.spin || 0) / TAU, k = Math.min(1, laps / (CFG.ROCK.laps * Perks.summonLaps()));
      if (k > 0.05) { // lap progress ring: it wakes when it closes (CFG.ROCK.laps, fewer with Summoner)
        c.strokeStyle = 'rgba(0,0,0,0.25)'; c.lineWidth = 5; c.beginPath(); c.arc(b.x, b.y, b.r + 14, 0, TAU); c.stroke();
        c.strokeStyle = k >= 1 ? '#fff' : '#ffd166'; c.lineWidth = 5; c.lineCap = 'round'; c.beginPath(); c.arc(b.x, b.y, b.r + 14, -Math.PI / 2, -Math.PI / 2 + k * TAU); c.stroke();
        this.text(k >= 1 ? 'SUMMONED' : `summoning ${Math.round(k * 100)}%`, b.x, b.y - b.r - 26, 12, '#ffd166', 'center');
      }
      c.save(); if (k > 0.3) c.translate(Math.sin(G.time * 40) * k * 2.5, 0);
      c.fillStyle = 'rgba(0,0,0,0.18)'; c.beginPath(); c.ellipse(b.x + 3, b.y + 6, b.r, b.r * 0.6, 0, 0, TAU); c.fill();
      this.circle(b.x, b.y, b.r, '#a9a2b3', this.OUT, 3); this.shine(b.x, b.y, b.r);
      // cracks and moss so it reads as a rock, not a critter
      c.strokeStyle = '#6f6880'; c.lineWidth = 2; c.lineCap = 'round';
      c.beginPath(); c.moveTo(b.x - b.r * 0.1, b.y - b.r * 0.2); c.lineTo(b.x + b.r * 0.25, b.y + b.r * 0.15); c.lineTo(b.x + b.r * 0.1, b.y + b.r * 0.55); c.stroke();
      c.beginPath(); c.moveTo(b.x - b.r * 0.6, b.y + b.r * 0.3); c.lineTo(b.x - b.r * 0.3, b.y + b.r * 0.45); c.stroke();
      c.fillStyle = '#7fbf6a'; c.beginPath(); c.ellipse(b.x - b.r * 0.35, b.y - b.r * 0.45, b.r * 0.3, b.r * 0.16, -0.5, 0, TAU); c.fill();
      c.restore();
    }
    // trees (3/4 view)
    for (const t of World.trees) {
      if (!t.alive || t.x < vx0 || t.x > vx1) continue;
      if (t.charred) { this.drawCharredTree(t); continue; }
      if (t.shroom) { this.drawMushroom(t); continue; }
      if (t.lolly) { // a giant lollipop: a white stick and a swirled disc that slowly turns
        const r = t.r, cy = t.y - r * 1.1, hue = (t.seed * 50) % 360; c.fillStyle = 'rgba(0,0,0,0.18)'; c.beginPath(); c.ellipse(t.x + 4, t.y + 8, r * 0.6, r * 0.3, 0, 0, TAU); c.fill();
        c.strokeStyle = this.OUT; c.lineWidth = 9; c.lineCap = 'round'; c.beginPath(); c.moveTo(t.x, t.y + 4); c.lineTo(t.x, cy); c.stroke(); c.strokeStyle = '#fff5e0'; c.lineWidth = 5; c.stroke();
        c.save(); c.translate(t.x, cy); c.rotate(G.time * 0.6 + t.seed); c.fillStyle = `hsl(${hue},85%,60%)`; c.beginPath(); c.arc(0, 0, r * 0.95, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 3; c.stroke();
        c.strokeStyle = '#fff'; c.lineWidth = r * 0.16; c.beginPath(); for (let a = 0; a < 14; a += 0.15) { const rr = a / 14 * r * 0.85; c.lineTo(Math.cos(a) * rr, Math.sin(a) * rr); } c.stroke(); c.restore(); this.shine(t.x, cy, r * 0.95);
        continue;
      }
      if (t.jungle) this.drawSongRing(t);
      if (t.palm) { this.drawPalm(t); if (t.song > 0) this.drawNotes(t); continue; }
      if (t.corn) { this.drawCorn(t); continue; }
      c.fillStyle = 'rgba(0,0,0,0.18)'; c.beginPath(); c.ellipse(t.x + 4, t.y + 8, t.r * 0.9, t.r * 0.45, 0, 0, TAU); c.fill();
      c.fillStyle = '#8a5a3a'; this.roundRect(t.x - 6, t.y - 10, 12, 22, 5); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.stroke();
      const sway = Math.sin(G.time * 1.5 + t.seed) * 2, cy = t.y - t.r * 0.75, dr = World.dread(t.region), L = 1 - dr * 0.45;
      // fluffy canopy: a cluster of lobes with one outline
      const lobes = [[0, 0, 1], [-0.6, 0.15, 0.65], [0.6, 0.15, 0.65], [0, -0.55, 0.7]];
      c.fillStyle = this.OUT;
      for (const [lx, ly, lr] of lobes) { c.beginPath(); c.arc(t.x + sway + lx * t.r, cy + ly * t.r, lr * t.r + 3, 0, TAU); c.fill(); }
      const J = t.jungle ? 1 : 0; // jungle broadleaf: deeper, bluer green, no dread tint
      c.fillStyle = J ? `hsl(${135 + t.hue},55%,30%)` : `hsl(${115 + t.hue - dr * 40},${50 - dr * 20}%,${42 * L}%)`;
      for (const [lx, ly, lr] of lobes) { c.beginPath(); c.arc(t.x + sway + lx * t.r, cy + ly * t.r, lr * t.r, 0, TAU); c.fill(); }
      c.fillStyle = J ? `hsl(${130 + t.hue},60%,40%)` : `hsl(${118 + t.hue - dr * 40},${55 - dr * 20}%,${52 * L}%)`; c.beginPath(); c.arc(t.x + sway - t.r * 0.25, cy - t.r * 0.3, t.r * 0.5, 0, TAU); c.fill();
      c.fillStyle = 'rgba(255,255,255,0.35)'; c.beginPath(); c.ellipse(t.x + sway - t.r * 0.4, cy - t.r * 0.55, t.r * 0.22, t.r * 0.13, -0.5, 0, TAU); c.fill();
      if (t.seed > 7) { c.fillStyle = J ? '#ff8a3d' : '#ff6b9d'; for (let k = 0; k < 3; k++) { c.beginPath(); c.arc(t.x + sway + Math.cos(t.seed + k * 2) * t.r * 0.6, cy + Math.sin(t.seed + k * 2) * t.r * 0.5, 3.5, 0, TAU); c.fill(); } }
      if (t.hive || t.hiveT > 0) { // a beehive hanging under the canopy, bees drifting round it (shoot it)
        const hx = t.x + sway + t.r * 0.55, hy = cy + t.r * 0.45; if (t.hiveT > 0) t.hiveT -= 1 / 60;
        c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.moveTo(hx, hy - 12); c.lineTo(hx, hy - 4); c.stroke();
        c.fillStyle = t.hive ? '#e8c06a' : '#9a8050'; c.strokeStyle = this.OUT; c.lineWidth = 2; for (const [dy, rr] of [[0, 7], [6, 9], [13, 8], [19, 5]]) { c.beginPath(); c.ellipse(hx, hy + dy, rr, 4, 0, 0, TAU); c.fill(); c.stroke(); }
        c.fillStyle = this.OUT; c.beginPath(); c.arc(hx, hy + 13, 2.2, 0, TAU); c.fill();
        if (t.hive) for (let k = 0; k < 3; k++) { const a = G.time * (3 + k) + k * 2, bx = hx + Math.cos(a) * (14 + k * 4), by = hy + 8 + Math.sin(a * 1.7) * 8; c.fillStyle = '#ffd166'; c.beginPath(); c.arc(bx, by, 2.2, 0, TAU); c.fill(); c.fillStyle = this.OUT; c.fillRect(bx - 0.5, by - 2, 1, 4); }
      }
      if (J && t.seed < 3) { c.strokeStyle = '#2a5a24'; c.lineWidth = 2.5; c.lineCap = 'round'; for (let k = 0; k < 3; k++) { const vx = t.x + sway + (k - 1) * t.r * 0.5; c.beginPath(); c.moveTo(vx, cy + t.r * 0.3); c.quadraticCurveTo(vx + 6, cy + t.r * 0.9, vx - 4, t.y + 4); c.stroke(); } } // hanging vines
      if (t.song > 0) this.drawNotes(t);
    }
    // thorn hedges (borders) and walls, in each region's palette (the graveyard's hedge is dead and grey)
    for (const R of World.regions) {
      if (R.x1 < vx0 || R.x0 > vx1) continue;
      const pal = this.hedgePal(R), a = Math.max(vx0, R.x0), b = Math.min(vx1, R.x1);
      c.fillStyle = pal.base;
      c.fillRect(a, 0, b - a, CFG.HEDGE); c.fillRect(a, CFG.WORLD_H - CFG.HEDGE, b - a, CFG.HEDGE);
      this.hedgeBlobs(a, CFG.HEDGE / 2, b, CFG.HEDGE / 2, 24, 0, pal);
      this.hedgeBlobs(a, CFG.WORLD_H - CFG.HEDGE / 2, b, CFG.WORLD_H - CFG.HEDGE / 2, 24, 0, pal);
      if (R.idx === 0 && vx0 < CFG.HEDGE) { c.fillStyle = pal.base; c.fillRect(0, 0, CFG.HEDGE, CFG.WORLD_H); this.hedgeBlobs(CFG.HEDGE / 2, 0, CFG.HEDGE / 2, CFG.WORLD_H, 24, 0, pal); }
      const w = R.wall;
      if (w.x < vx0 || w.x > vx1) continue;
      for (const s of w.sections) {
        if (!s.alive) continue;
        const dmg = 1 - s.hp / s.max;
        c.fillStyle = pal.base; c.fillRect(w.x - CFG.WALL_T / 2, s.y0, CFG.WALL_T, s.y1 - s.y0);
        this.hedgeBlobs(w.x, s.y0 + 8, w.x, s.y1 - 8, 18, dmg, pal);
        if (s.swarmMade) { c.strokeStyle = `rgba(255,209,102,${0.45 + 0.3 * Math.sin(G.time * 6 + s.y0 * 0.01)})`; c.lineWidth = 3; c.strokeRect(w.x - CFG.WALL_T / 2 - 2, s.y0 + 1, CFG.WALL_T + 4, s.y1 - s.y0 - 2); } // grown back by a swarm: it crumbles again when the swarm is gone
        if (dmg > 0.01) { c.strokeStyle = `rgba(255,220,120,${dmg})`; c.lineWidth = 2; c.beginPath(); c.moveTo(w.x - 8, s.y0 + 10); c.lineTo(w.x + 6, (s.y0 + s.y1) / 2); c.lineTo(w.x - 5, s.y1 - 10); c.stroke(); }
      }
    }
  },

  HEDGE_LIVE: { base: '#3b6b2e', a: '#4f8a3c', b: '#5f9e48', flower: '#ff7eb6' },

  HEDGE_DEAD: { base: '#2e2d35', a: '#45444d', b: '#55545e', flower: null },

  HEDGE_JUNGLE: { base: '#1f4d26', a: '#2d6b32', b: '#3a8040', flower: '#ff8a3d' },

  HEDGE_FENCE: { base: '#6a4428', a: '#8a5a38', b: '#a06a42', flower: null },

  HEDGE_CIRCUS: { base: '#8a1f3a', a: '#ff5c8a', b: '#ffd166', flower: '#7df9ff' }, // bunting

  hedgePal(R) { return R.haunted ? this.HEDGE_DEAD : R.jungle ? this.HEDGE_JUNGLE : R.farm ? this.HEDGE_FENCE : R.circus ? this.HEDGE_CIRCUS : this.HEDGE_LIVE; },

  // blobs sit on a world-space grid so they stay put while the camera scrolls (sampling from the
  // camera edge made the whole hedge crawl and shimmer)
  hedgeBlobs(x0, y0, x1, y1, step, dmg, pal) {
    const c = this.ctx, horiz = Math.abs(x1 - x0) >= Math.abs(y1 - y0); pal = pal || this.HEDGE_LIVE;
    const a0 = horiz ? x0 : y0, a1 = horiz ? x1 : y1, start = Math.ceil(Math.min(a0, a1) / step) * step, end = Math.max(a0, a1);
    for (let a = start; a <= end; a += step) {
      const x = horiz ? a : x0, y = horiz ? y0 : a;
      const k = ((x * 7 + y * 13) % 17) / 17;
      c.fillStyle = k < 0.5 ? pal.a : pal.b;
      c.beginPath(); c.arc(x + (k - 0.5) * 8, y + (k - 0.5) * 6, 9 + k * 5 - (dmg || 0) * 5, 0, TAU); c.fill();
      if (k > 0.7) { if (pal.flower) { c.fillStyle = pal.flower; c.beginPath(); c.arc(x + 4, y - 4, 3, 0, TAU); c.fill(); } else { c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.beginPath(); c.moveTo(x + 2, y - 2); c.lineTo(x + 7, y - 9); c.stroke(); } } // flowers, or a dead thorn
    }
  },

  // decorations of every biome, by kind
  drawDeco(d) {
    const c = this.ctx, x = d.x, y = d.y;
    c.save(); c.translate(x, y);
    if (d.kind === 'bone') { c.rotate(d.a); c.strokeStyle = this.OUT; c.lineWidth = 5; c.lineCap = 'round'; c.beginPath(); c.moveTo(-9, 0); c.lineTo(9, 0); c.stroke(); c.strokeStyle = '#d9d4c7'; c.lineWidth = 2.5; c.stroke(); for (const sx of [-9, 9]) for (const sy of [-2.5, 2.5]) this.circle(sx, sy, 2.4, '#d9d4c7', this.OUT, 1.2); }
    else if (d.kind === 'skull') { this.circle(0, -2, 6, '#d9d4c7', this.OUT, 1.8); c.fillStyle = this.OUT; for (const s of [-1, 1]) { c.beginPath(); c.arc(s * 2.4, -2.5, 1.5, 0, TAU); c.fill(); } c.fillRect(-3.5, 2.5, 7, 3); c.strokeStyle = this.OUT; c.lineWidth = 1; c.strokeRect(-3.5, 2.5, 7, 3); }
    else if (d.kind === 'rib') { c.rotate(d.a); c.strokeStyle = this.OUT; c.lineWidth = 4; c.lineCap = 'round'; c.beginPath(); c.moveTo(-14, 0); c.lineTo(14, 0); c.stroke(); c.strokeStyle = '#cfcabd'; c.lineWidth = 2; c.stroke(); for (let i = -2; i <= 2; i++) { c.beginPath(); c.arc(i * 6, 0, 6, 0.2, Math.PI - 0.2); c.strokeStyle = this.OUT; c.lineWidth = 3.5; c.stroke(); c.strokeStyle = '#cfcabd'; c.lineWidth = 1.8; c.stroke(); } }
    else if (d.kind === 'fern') { c.rotate(d.a * 0.2); c.strokeStyle = '#2f8a3a'; c.lineWidth = 2; c.lineCap = 'round'; for (let i = 0; i < 5; i++) { const a = -Math.PI / 2 + (i - 2) * 0.45, L = 14 + (i % 2) * 4; c.beginPath(); c.moveTo(0, 2); c.lineTo(Math.cos(a) * L, Math.sin(a) * L); c.stroke(); for (let k = 1; k <= 3; k++) { const px = Math.cos(a) * L * k / 4, py = Math.sin(a) * L * k / 4; c.beginPath(); c.moveTo(px, py); c.lineTo(px + Math.cos(a + 1.2) * 4, py + Math.sin(a + 1.2) * 4); c.moveTo(px, py); c.lineTo(px + Math.cos(a - 1.2) * 4, py + Math.sin(a - 1.2) * 4); c.stroke(); } } }
    else if (d.kind === 'flower') { c.strokeStyle = '#2f8a3a'; c.lineWidth = 2; c.beginPath(); c.moveTo(0, 6); c.lineTo(0, -4); c.stroke(); const col = ['#ff5c8a', '#ff8a3d', '#ffd166', '#f3c6ff'][Math.floor(d.seed) % 4]; c.fillStyle = col; for (let i = 0; i < 5; i++) { const a = i * TAU / 5 + d.seed; c.beginPath(); c.ellipse(Math.cos(a) * 4, -6 + Math.sin(a) * 4, 3.5, 2, a, 0, TAU); c.fill(); } c.fillStyle = '#fff5c0'; c.beginPath(); c.arc(0, -6, 2, 0, TAU); c.fill(); }
    else if (d.kind === 'gold') { const g = 0.6 + 0.4 * Math.sin(G.time * 4 + d.seed); c.fillStyle = `rgba(255,209,102,${0.15 * g})`; c.beginPath(); c.arc(0, 0, 10, 0, TAU); c.fill(); this.circle(-3, 1, 4, '#ffd166', this.OUT, 1.5); this.circle(3, -1, 4, '#ffe08a', this.OUT, 1.5); this.circle(0, 3, 4, '#ffd166', this.OUT, 1.5); }
    else if (d.kind === 'glyph') { c.rotate(d.a * 0.1); c.fillStyle = '#9a8f6a'; c.strokeStyle = this.OUT; c.lineWidth = 2; this.roundRect(-11, -9, 22, 18, 3); c.fill(); c.stroke(); c.strokeStyle = '#5a5038'; c.lineWidth = 1.5; c.beginPath(); c.arc(0, 0, 4, 0, TAU); c.stroke(); c.beginPath(); c.moveTo(-7, -4); c.lineTo(-7, 4); c.moveTo(7, -4); c.lineTo(7, 4); c.moveTo(-3, 6); c.lineTo(3, 6); c.stroke(); }
    else if (d.kind === 'skullpole') { c.strokeStyle = this.OUT; c.lineWidth = 4; c.beginPath(); c.moveTo(0, 12); c.lineTo(0, -10); c.stroke(); c.strokeStyle = '#8a5a3a'; c.lineWidth = 2; c.stroke(); this.circle(0, -13, 6, '#d9d4c7', this.OUT, 1.8); c.fillStyle = this.OUT; for (const s of [-1, 1]) { c.beginPath(); c.arc(s * 2.4, -13.5, 1.5, 0, TAU); c.fill(); } c.fillRect(-3.5, -8.5, 7, 3); c.fillStyle = '#ff5c5c'; for (const s of [-1, 1]) { c.beginPath(); c.ellipse(s * 7, -12 + Math.sin(G.time * 3 + d.seed) * 1.5, 2, 5, s * 0.4, 0, TAU); c.fill(); } } // feathers
    else if (d.kind === 'circle') { const R = d.big ? 260 : 130 + (d.seed % 3) * 25; c.rotate(d.a); c.fillStyle = 'rgba(255,240,170,0.22)'; c.beginPath(); c.arc(0, 0, R, 0, TAU); c.fill(); c.strokeStyle = 'rgba(70,90,30,0.45)'; c.lineWidth = 3; for (const k of [1, 0.72, 0.42]) { c.beginPath(); c.arc(0, 0, R * k, 0, TAU); c.stroke(); } c.fillStyle = 'rgba(255,240,170,0.22)'; for (let i = 0; i < 3; i++) { const a = i * TAU / 3; c.beginPath(); c.arc(Math.cos(a) * R * 1.25, Math.sin(a) * R * 1.25, R * 0.22, 0, TAU); c.fill(); c.stroke(); } for (let i = 0; i < 3; i++) { const a = i * TAU / 3; c.beginPath(); c.moveTo(Math.cos(a) * R, Math.sin(a) * R); c.lineTo(Math.cos(a) * R * 1.03, Math.sin(a) * R * 1.03); c.stroke(); } // flattened crop, rings and satellites
      if (d.gift) { const k = 0.5 + 0.5 * Math.sin(G.time * 3), st = Math.min(1, (d.stand || 0) / 2); c.rotate(-d.a); c.strokeStyle = `rgba(155,240,106,${0.3 + 0.4 * k})`; c.lineWidth = 2.5; c.setLineDash([6, 8]); c.lineDashOffset = -G.time * 30; c.beginPath(); c.arc(0, 0, 60, 0, TAU); c.stroke(); c.setLineDash([]); if (st > 0) { c.fillStyle = `rgba(155,240,106,${0.15 + 0.25 * st})`; c.beginPath(); c.moveTo(-8, -400); c.lineTo(8, -400); c.lineTo(60, 0); c.lineTo(-60, 0); c.closePath(); c.fill(); c.strokeStyle = '#9bf06a'; c.lineWidth = 4; c.beginPath(); c.arc(0, 0, 60, -Math.PI / 2, -Math.PI / 2 + st * TAU); c.stroke(); } } } // stand in the centre: the beam comes
    else if (d.kind === 'pumpkin') { c.fillStyle = '#ff8a3d'; c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.ellipse(0, 0, 10, 8, 0, 0, TAU); c.fill(); c.stroke(); c.strokeStyle = '#c45a1a'; c.lineWidth = 1.5; for (const dx of [-4, 0, 4]) { c.beginPath(); c.ellipse(dx, 0, 3, 8, 0, 0, TAU); c.stroke(); } c.fillStyle = '#5a8a3a'; c.fillRect(-2, -12, 4, 6); }
    else if (d.kind === 'scarecrow') { c.strokeStyle = this.OUT; c.lineWidth = 4; c.beginPath(); c.moveTo(0, 14); c.lineTo(0, -22); c.moveTo(-14, -10); c.lineTo(14, -10); c.stroke(); c.strokeStyle = '#8a5a3a'; c.lineWidth = 2; c.stroke(); c.fillStyle = '#4a6aa0'; c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.fillRect(-7, -14, 14, 14); c.strokeRect(-7, -14, 14, 14); this.circle(0, -24, 7, '#e8d28a', this.OUT, 2); c.fillStyle = '#6a4428'; c.beginPath(); c.moveTo(-10, -28); c.lineTo(10, -28); c.lineTo(5, -36); c.lineTo(-5, -36); c.closePath(); c.fill(); c.stroke(); c.fillStyle = this.OUT; c.fillRect(-3, -26, 2, 2); c.fillRect(1, -26, 2, 2); c.fillStyle = '#e8d28a'; for (const [sx, sy] of [[-16, -12], [16, -12], [-3, 12], [3, 12]]) { c.beginPath(); c.moveTo(sx, sy); c.lineTo(sx + (sx < 0 ? -4 : 4), sy + 5); c.lineTo(sx, sy + 3); c.fill(); } } // straw poking out
    else if (d.kind === 'barn') {
      const laps = Math.abs(d.spin || 0) / TAU, k = d.used ? 0 : Math.min(1, laps / (CFG.ROCK.laps * Perks.summonLaps())), open = d.used;
      if (k > 0.05) { c.strokeStyle = 'rgba(0,0,0,0.25)'; c.lineWidth = 5; c.beginPath(); c.arc(0, 0, 58, 0, TAU); c.stroke(); c.strokeStyle = k >= 1 ? '#fff' : '#ffd166'; c.lineWidth = 5; c.lineCap = 'round'; c.beginPath(); c.arc(0, 0, 58, -Math.PI / 2, -Math.PI / 2 + k * TAU); c.stroke(); this.text(k >= 1 ? 'SUMMONED' : `summoning ${Math.round(k * 100)}%`, 0, -72, 12, '#ffd166', 'center'); }
      if (k > 0.3) c.translate(Math.sin(G.time * 40) * k * 2.5, 0); // engines revving inside
      c.fillStyle = 'rgba(0,0,0,0.15)'; c.beginPath(); c.ellipse(4, 24, 44, 12, 0, 0, TAU); c.fill(); c.fillStyle = '#b53a2a'; c.strokeStyle = this.OUT; c.lineWidth = 3; c.fillRect(-36, -10, 72, 34); c.strokeRect(-36, -10, 72, 34); c.fillStyle = '#7a2a1a'; c.beginPath(); c.moveTo(-40, -10); c.lineTo(-24, -34); c.lineTo(24, -34); c.lineTo(40, -10); c.closePath(); c.fill(); c.stroke();
      if (open) { c.fillStyle = '#1a1026'; c.fillRect(-12, 0, 24, 24); c.strokeRect(-12, 0, 24, 24); const sw = d.doorT > 0 ? 1 - d.doorT / 1.2 : 1; for (const s of [-1, 1]) { c.save(); c.translate(s * 12, 0); c.rotate(s * (0.4 + sw * 1.1)); c.fillStyle = '#4a2a1a'; c.fillRect(s < 0 ? -12 : 0, 0, 12, 24); c.strokeStyle = this.OUT; c.lineWidth = 2; c.strokeRect(s < 0 ? -12 : 0, 0, 12, 24); c.restore(); } } // the doors flung wide
      else { c.fillStyle = '#4a2a1a'; c.fillRect(-12, 0, 24, 24); c.strokeRect(-12, 0, 24, 24); c.strokeStyle = '#e8d28a'; c.lineWidth = 2; c.beginPath(); c.moveTo(-12, 0); c.lineTo(12, 24); c.moveTo(12, 0); c.lineTo(-12, 24); c.stroke(); }
      c.fillStyle = '#ffd166'; c.fillRect(-4, -26, 8, 8); c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.strokeRect(-4, -26, 8, 8);
      if (!open && k <= 0.05) this.text('BARN', 0, -44, 11, 'rgba(255,233,168,0.7)', 'center');
    }
    else if (d.kind === 'fence') { c.rotate(d.a * 0.15); c.strokeStyle = this.OUT; c.lineWidth = 4; for (const px of [-18, 0, 18]) { c.beginPath(); c.moveTo(px, 8); c.lineTo(px, -10); c.stroke(); } c.beginPath(); c.moveTo(-22, -4); c.lineTo(22, -4); c.moveTo(-22, 4); c.lineTo(22, 4); c.stroke(); c.strokeStyle = '#a06a42'; c.lineWidth = 2; for (const px of [-18, 0, 18]) { c.beginPath(); c.moveTo(px, 8); c.lineTo(px, -10); c.stroke(); } c.beginPath(); c.moveTo(-22, -4); c.lineTo(22, -4); c.moveTo(-22, 4); c.lineTo(22, 4); c.stroke(); }
    else if (d.kind === 'probe') { c.fillStyle = 'rgba(0,0,0,0.15)'; c.beginPath(); c.ellipse(3, 8, 16, 6, 0, 0, TAU); c.fill(); c.fillStyle = '#9ba3b0'; c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.ellipse(0, 2, 14, 6, d.a * 0.2, 0, TAU); c.fill(); c.stroke(); c.fillStyle = '#7df9ff'; c.beginPath(); c.arc(0, -2, 5, Math.PI, 0); c.fill(); c.stroke(); for (let k = 0; k < 2; k++) { const ph = (G.time * 0.6 + k * 0.5 + d.seed) % 1; c.fillStyle = `rgba(120,120,130,${0.35 * (1 - ph)})`; c.beginPath(); c.arc(Math.sin(G.time + k) * 3, -8 - ph * 26, 3 + ph * 6, 0, TAU); c.fill(); } } // a crashed probe, still smoking
    else if (d.kind === 'shroom') { c.fillStyle = '#b8b0c8'; c.strokeStyle = this.OUT; c.lineWidth = 1.8; this.roundRect(-2.5, -4, 5, 9, 2); c.fill(); c.stroke(); c.fillStyle = '#8a6aa8'; c.beginPath(); c.arc(0, -4, 7, Math.PI, 0); c.closePath(); c.fill(); c.stroke(); c.fillStyle = '#e6d8ff'; for (const [sx, sy] of [[-3, -7], [2, -9], [4, -5]]) { c.beginPath(); c.arc(sx, sy, 1.3, 0, TAU); c.fill(); } }
    // the graveyard's coffin, the jungle's geyser, the farm's tractor
    else if (d.kind === 'coffin') { c.rotate(d.a); c.fillStyle = 'rgba(0,0,0,0.18)'; c.beginPath(); c.ellipse(3, 8, 26, 12, 0, 0, TAU); c.fill(); c.fillStyle = '#5a3a2a'; c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.beginPath(); c.moveTo(-22, -6); c.lineTo(-10, -16); c.lineTo(14, -16); c.lineTo(22, -4); c.lineTo(14, 16); c.lineTo(-10, 16); c.lineTo(-22, 8); c.closePath(); c.fill(); c.stroke(); if (d.open) { c.fillStyle = '#0c0a12'; c.beginPath(); c.moveTo(-17, -5); c.lineTo(-8, -12); c.lineTo(10, -12); c.lineTo(17, -3); c.lineTo(10, 12); c.lineTo(-8, 12); c.lineTo(-17, 6); c.closePath(); c.fill(); c.save(); c.translate(14, -22); c.rotate(-0.5); c.fillStyle = '#6a4a3a'; c.beginPath(); c.moveTo(-22, -6); c.lineTo(-10, -16); c.lineTo(14, -16); c.lineTo(22, -4); c.lineTo(14, 16); c.lineTo(-10, 16); c.lineTo(-22, 8); c.closePath(); c.fill(); c.stroke(); c.restore(); } else { c.strokeStyle = '#ffd166'; c.lineWidth = 2; c.beginPath(); c.moveTo(-4, -8); c.lineTo(-4, 8); c.moveTo(-10, -3); c.lineTo(2, -3); c.stroke(); } } // a lid with a gold cross; open: lid flung aside
    else if (d.kind === 'geyser') { const on = d.on > 0, k = on ? d.on / CFG.GEYSER.on : 0, warn = !on && d.t < 1; c.fillStyle = '#6a7a5a'; c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.beginPath(); c.ellipse(0, 4, 34, 16, 0, 0, TAU); c.fill(); c.stroke(); c.fillStyle = warn ? `rgba(159,227,255,${0.5 + 0.5 * Math.sin(G.time * 20)})` : '#3a5a6a'; c.beginPath(); c.ellipse(0, 2, 18, 8, 0, 0, TAU); c.fill(); c.stroke(); if (on) { c.fillStyle = `rgba(223,244,255,${0.35 + 0.3 * k})`; c.beginPath(); c.moveTo(-14, 2); c.quadraticCurveTo(-40 - k * 20, -120, -10, -200); c.lineTo(10, -200); c.quadraticCurveTo(40 + k * 20, -120, 14, 2); c.closePath(); c.fill(); c.strokeStyle = 'rgba(159,227,255,0.4)'; c.lineWidth = 2; c.setLineDash([4, 8]); c.beginPath(); c.arc(0, 0, CFG.GEYSER.r, 0, TAU); c.stroke(); c.setLineDash([]); } if (!on && U.chance(0.05)) Combat.particles.push(Combat.mk(d.x + U.rand(-10, 10), d.y, 0, -30, 0.8, 'rgba(220,240,255,0.6)', 3)); } // steam idling, then the column
    else if (d.kind === 'tractor') { if (d.used) { c.fillStyle = 'rgba(0,0,0,0.12)'; c.beginPath(); c.ellipse(0, 6, 36, 12, 0, 0, TAU); c.fill(); c.strokeStyle = 'rgba(60,40,20,0.5)'; c.lineWidth = 3; for (const dy of [-8, 0, 8]) { c.beginPath(); c.moveTo(-30, dy); c.lineTo(30, dy); c.stroke(); } } else { this.drawTractor(0, 0, 0, 1); const k = 0.5 + 0.5 * Math.sin(G.time * 4); c.strokeStyle = `rgba(255,209,102,${0.3 + 0.5 * k})`; c.lineWidth = 3; c.beginPath(); c.arc(0, 0, 44 + k * 4, 0, TAU); c.stroke(); this.text('TRACTOR', 0, -46, 13, '#ffd166', 'center'); } } // parked, or just tyre tracks once taken
    // the midway
    else if (d.kind === 'balloons') { const sw = Math.sin(G.time * 1.2 + d.seed) * 4; c.strokeStyle = this.OUT; c.lineWidth = 1.5; const cols = ['#ff5c8a', '#ffd166', '#7df9ff', '#9bf06a', '#f3c6ff']; for (let i = 0; i < 4; i++) { const bx = sw + (i - 1.5) * 9 + Math.sin(d.seed + i) * 3, by = -36 - (i % 2) * 10 - Math.cos(d.seed * 2 + i) * 4; c.beginPath(); c.moveTo(0, 0); c.quadraticCurveTo(bx * 0.5, by * 0.5, bx, by + 8); c.stroke(); } for (let i = 0; i < 4; i++) { const bx = sw + (i - 1.5) * 9 + Math.sin(d.seed + i) * 3, by = -36 - (i % 2) * 10 - Math.cos(d.seed * 2 + i) * 4; c.fillStyle = cols[(i + Math.floor(d.seed)) % 5]; c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.ellipse(bx, by, 8, 10, 0, 0, TAU); c.fill(); c.stroke(); this.shine(bx, by, 8); } c.fillStyle = this.OUT; c.beginPath(); c.arc(0, 2, 3, 0, TAU); c.fill(); } // a bunch on a stake
    else if (d.kind === 'popcorn') { c.fillStyle = '#ff5c8a'; c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.moveTo(-9, -4); c.lineTo(9, -4); c.lineTo(7, 12); c.lineTo(-7, 12); c.closePath(); c.fill(); c.stroke(); c.fillStyle = '#fff'; for (const dx of [-5, 0, 5]) c.fillRect(dx - 1, -4, 2, 16); c.fillStyle = '#fff5e0'; for (const [sx, sy] of [[-6, -8], [0, -12], [6, -8], [-2, -6], [4, -5]]) { c.beginPath(); c.arc(sx, sy, 3.5, 0, TAU); c.fill(); c.stroke(); } } // a box, overflowing
    else if (d.kind === 'confetti') { for (let i = 0; i < 6; i++) { const t = (G.time * 0.3 + i * 0.17 + d.seed) % 1, x = Math.sin(t * 9 + i) * 30 + (i - 3) * 12, y = -40 + t * 80; c.save(); c.translate(x, y); c.rotate(G.time * 3 + i); c.fillStyle = ['#ffd166', '#ff5c8a', '#7df9ff', '#9bf06a', '#f3c6ff', '#fff'][i]; c.globalAlpha = 0.8 * Math.sin(t * Math.PI); c.fillRect(-4, -2, 8, 4); c.restore(); } } // drifting down, forever
    else if (d.kind === 'spiral') { c.save(); c.rotate(G.time * 0.4 + d.seed); c.globalAlpha = 0.22; c.strokeStyle = '#fff'; c.lineWidth = 6; c.beginPath(); for (let a = 0; a < 18; a += 0.2) { const rr = a * 6; c.lineTo(Math.cos(a) * rr, Math.sin(a) * rr); } c.stroke(); c.restore(); } // a tie-dye spiral, turning
    else if (d.kind === 'checker') { c.save(); c.rotate(d.a * 0.3); const s = 22 + 2 * Math.sin(G.time * 1.1 + d.seed); c.globalAlpha = 0.18; for (let i = -3; i < 3; i++) for (let j = -3; j < 3; j++) { if ((i + j) % 2) continue; c.fillStyle = '#fff'; c.fillRect(i * s, j * s, s, s); } c.restore(); } // a breathing checkerboard
    else if (d.kind === 'tent') { c.fillStyle = 'rgba(0,0,0,0.15)'; c.beginPath(); c.ellipse(3, 12, 30, 10, 0, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 2.5; for (let i = 0; i < 4; i++) { c.fillStyle = i % 2 ? (d.seed > 5 ? '#7df9ff' : '#ffd166') : '#fff5e0'; c.beginPath(); c.moveTo(0, -36); c.lineTo(-28 + i * 14, 10); c.lineTo(-28 + (i + 1) * 14, 10); c.closePath(); c.fill(); } c.beginPath(); c.moveTo(-28, 10); c.lineTo(0, -36); c.lineTo(28, 10); c.closePath(); c.stroke(); c.fillStyle = '#ff5c8a'; c.beginPath(); c.moveTo(0, -36); c.lineTo(10, -32); c.lineTo(0, -28); c.closePath(); c.fill(); } // a small tent
    else if (d.kind === 'booth') { c.fillStyle = 'rgba(0,0,0,0.15)'; c.beginPath(); c.ellipse(4, 20, 36, 12, 0, 0, TAU); c.fill(); c.fillStyle = '#ff5c8a'; c.strokeStyle = this.OUT; c.lineWidth = 3; c.fillRect(-26, -14, 52, 34); c.strokeRect(-26, -14, 52, 34); c.fillStyle = '#fff5e0'; for (let i = 0; i < 4; i++) c.fillRect(-26 + i * 13 + (i % 2) * 0, -14, 6.5, 34); c.fillStyle = '#ffd166'; c.beginPath(); c.moveTo(-32, -14); c.lineTo(0, -34); c.lineTo(32, -14); c.closePath(); c.fill(); c.stroke(); c.fillStyle = '#1a1026'; c.fillRect(-12, -6, 24, 14); c.strokeRect(-12, -6, 24, 14); this.text('TICKETS', 0, -22, 8, this.OUT, 'center'); } // a ticket booth
    else if (d.kind === 'jack') { c.fillStyle = 'rgba(0,0,0,0.15)'; c.beginPath(); c.ellipse(3, 12, 18, 7, 0, 0, TAU); c.fill(); c.fillStyle = '#4a6aa0'; c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.fillRect(-13, -8, 26, 20); c.strokeRect(-13, -8, 26, 20); c.strokeStyle = '#ffd166'; c.lineWidth = 2; c.strokeRect(-9, -4, 18, 12); if (d.sprung) { const h = Math.min(1, d.t * 4) * 46, bob = Math.sin(d.t * 8) * 4; c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); for (let i = 0; i <= 8; i++) c.lineTo((i % 2 ? 6 : -6), -8 - h * i / 8); c.stroke(); c.fillStyle = '#ffd166'; c.fillRect(-13, -14, 26, 8); c.strokeRect(-13, -14, 26, 8); this.circle(0, -8 - h - 6 + bob, 9, '#fff5e0', this.OUT, 2); c.fillStyle = '#ff5c5c'; c.beginPath(); c.arc(0, -8 - h - 5 + bob, 3, 0, TAU); c.fill(); c.fillStyle = '#ff5c8a'; for (const s of [-1, 1]) { c.beginPath(); c.arc(s * 9, -8 - h - 10 + bob, 4, 0, TAU); c.fill(); } } else { c.fillStyle = '#ffd166'; c.fillRect(-13, -14, 26, 8); c.strokeRect(-13, -14, 26, 8); c.fillStyle = this.OUT; c.beginPath(); c.arc(9, -10, 2, 0, TAU); c.fill(); } } // the crank, or the clown on his spring
    else if (d.kind === 'mirror') { c.fillStyle = 'rgba(0,0,0,0.18)'; c.beginPath(); c.ellipse(4, 70, 70, 16, 0, 0, TAU); c.fill(); c.fillStyle = '#6a2a8a'; c.strokeStyle = this.OUT; c.lineWidth = 3; c.fillRect(-70, -90, 140, 150); c.strokeRect(-70, -90, 140, 150); const g = c.createLinearGradient(-60, 0, 60, 0); g.addColorStop(0, '#c8e8ff'); g.addColorStop(0.5, '#ffffff'); g.addColorStop(1, '#d8c8ff'); c.fillStyle = g; c.fillRect(-62, -82, 124, 134); c.strokeRect(-62, -82, 124, 134); c.strokeStyle = 'rgba(0,0,0,0.15)'; c.lineWidth = 2; for (const dx of [-30, 0, 30]) { c.beginPath(); c.moveTo(dx, -82); c.lineTo(dx, 52); c.stroke(); } c.fillStyle = '#ffd166'; for (let i = -3; i <= 3; i++) { const on = Math.floor(G.time * 6 + i) % 3 === 0; c.fillStyle = on ? '#fff' : '#ffd166'; c.beginPath(); c.arc(i * 20, -90, 4, 0, TAU); c.fill(); } this.text('HALL OF MIRRORS', 0, -104, 12, '#f3c6ff', 'center'); }
    else if (d.kind === 'pole') { c.strokeStyle = this.OUT; c.lineWidth = 7; c.lineCap = 'round'; c.beginPath(); c.moveTo(0, 14); c.lineTo(0, -46); c.stroke(); c.save(); c.beginPath(); c.rect(-3, -46, 6, 60); c.clip(); for (let i = 0; i < 8; i++) { c.fillStyle = i % 2 ? '#ff5c8a' : '#fff5e0'; c.save(); c.translate(0, -46 + i * 8); c.rotate(0.6); c.fillRect(-8, -3, 16, 5); c.restore(); } c.restore(); c.fillStyle = ['#ffd166', '#7df9ff', '#9bf06a'][Math.floor(d.seed) % 3]; c.strokeStyle = this.OUT; c.lineWidth = 2; const fl = Math.sin(G.time * 3 + d.seed) * 3; c.beginPath(); c.moveTo(0, -46); c.lineTo(22 + fl, -40); c.lineTo(0, -32); c.closePath(); c.fill(); c.stroke(); } // a barber-striped pole with a pennant
    else if (d.kind === 'clownface') { const R = d.big ? 70 : 44; c.rotate(d.a * 0.2); c.globalAlpha = 0.55; c.fillStyle = '#fff5e0'; c.beginPath(); c.arc(0, 0, R, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 3; c.stroke(); c.fillStyle = ['#ff5c8a', '#7df9ff', '#9bf06a'][Math.floor(d.seed) % 3]; for (const s of [-1, 1]) { c.beginPath(); c.arc(s * R * 0.95, -R * 0.2, R * 0.45, 0, TAU); c.fill(); c.stroke(); } c.fillStyle = this.OUT; for (const s of [-1, 1]) { c.beginPath(); c.ellipse(s * R * 0.38, -R * 0.25, R * 0.12, R * 0.2, 0, 0, TAU); c.fill(); } c.fillStyle = '#ff3a3a'; c.beginPath(); c.arc(0, R * 0.05, R * 0.22, 0, TAU); c.fill(); c.stroke(); c.strokeStyle = '#ff3a3a'; c.lineWidth = 5; c.beginPath(); c.arc(0, R * 0.2, R * 0.55, 0.35, Math.PI - 0.35); c.stroke(); c.strokeStyle = this.OUT; c.lineWidth = 2; c.stroke(); c.globalAlpha = 1; } // a huge clown face painted on the ground
    else if (d.kind === 'stall') { c.fillStyle = 'rgba(0,0,0,0.15)'; c.beginPath(); c.ellipse(4, 22, 40, 12, 0, 0, TAU); c.fill(); c.fillStyle = '#8a1f3a'; c.strokeStyle = this.OUT; c.lineWidth = 3; c.fillRect(-30, -6, 60, 28); c.strokeRect(-30, -6, 60, 28); c.fillStyle = '#ffd166'; c.fillRect(-26, 0, 52, 8); for (let i = 0; i < 6; i++) { c.fillStyle = i % 2 ? '#7df9ff' : '#fff5e0'; c.beginPath(); c.moveTo(-36 + i * 12, -8); c.lineTo(-30 + i * 12, -30); c.lineTo(-24 + i * 12, -8); c.closePath(); c.fill(); c.stroke(); } c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.moveTo(-36, -8); c.lineTo(36, -8); c.stroke(); const sweet = Math.floor(d.seed) % 2; for (let i = -1; i <= 1; i++) { if (sweet) { c.fillStyle = i ? '#ffb3d9' : '#7df9ff'; c.beginPath(); c.arc(i * 14, -16, 8, 0, TAU); c.fill(); c.stroke(); c.strokeStyle = '#fff'; c.lineWidth = 2; c.beginPath(); c.moveTo(i * 14, -8); c.lineTo(i * 14, 2); c.stroke(); c.strokeStyle = this.OUT; } else { c.fillStyle = '#ff5c5c'; c.beginPath(); c.arc(i * 14, -14, 7, 0, TAU); c.fill(); c.stroke(); c.strokeStyle = '#fff'; c.lineWidth = 2; c.beginPath(); c.moveTo(i * 14, -7); c.lineTo(i * 14, 2); c.stroke(); c.strokeStyle = this.OUT; } } this.text(sweet ? 'CANDY FLOSS' : 'TOFFEE APPLES', 0, -38, 8, '#fff', 'center'); } // a striped stall
    else if (d.kind === 'ferris') { const R = 78, spin = G.time * 0.35 + d.seed; c.fillStyle = 'rgba(0,0,0,0.15)'; c.beginPath(); c.ellipse(4, R + 46, 60, 14, 0, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 7; c.lineCap = 'round'; for (const s of [-1, 1]) { c.beginPath(); c.moveTo(s * 44, R + 40); c.lineTo(0, 0); c.stroke(); } c.strokeStyle = '#ff5c8a'; c.lineWidth = 3.5; for (const s of [-1, 1]) { c.beginPath(); c.moveTo(s * 44, R + 40); c.lineTo(0, 0); c.stroke(); } c.strokeStyle = this.OUT; c.lineWidth = 4; c.beginPath(); c.arc(0, 0, R, 0, TAU); c.stroke(); c.strokeStyle = '#ffd166'; c.lineWidth = 2; c.stroke(); for (let i = 0; i < 8; i++) { const a = spin + i * TAU / 8; c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.moveTo(0, 0); c.lineTo(Math.cos(a) * R, Math.sin(a) * R); c.stroke(); const gx = Math.cos(a) * R, gy = Math.sin(a) * R + 8; c.fillStyle = ['#ff5c8a', '#ffd166', '#7df9ff', '#9bf06a'][i % 4]; c.strokeStyle = this.OUT; c.lineWidth = 2; this.roundRect(gx - 9, gy - 4, 18, 13, 4); c.fill(); c.stroke(); const on = (Math.floor(G.time * 6) + i) % 2 === 0; c.fillStyle = on ? '#fff' : '#ffd166'; c.beginPath(); c.arc(Math.cos(a) * R * 0.55, Math.sin(a) * R * 0.55, 2.5, 0, TAU); c.fill(); } this.circle(0, 0, 9, '#ffd166', this.OUT, 2.5); this.text('FERRIS WHEEL', 0, -R - 16, 11, '#ffd166', 'center'); } // the big wheel, turning, gondolas swinging
    else if (d.kind === 'carousel') { const R = 46, spin = G.time * 1.1 + d.seed; c.fillStyle = 'rgba(0,0,0,0.18)'; c.beginPath(); c.ellipse(4, 14, R + 8, 18, 0, 0, TAU); c.fill(); c.fillStyle = '#ffd166'; c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.ellipse(0, 8, R, R * 0.38, 0, 0, TAU); c.fill(); c.stroke(); for (let i = 0; i < 6; i++) { const a = spin + i * TAU / 6, hx = Math.cos(a) * R * 0.75, hy = 8 + Math.sin(a) * R * 0.28, up = Math.sin(spin * 3 + i) * 5, behind = Math.sin(a) < 0; if (behind) continue; c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.moveTo(hx, hy); c.lineTo(hx, hy - 46); c.stroke(); c.strokeStyle = '#ffd166'; c.lineWidth = 1.5; c.stroke(); c.fillStyle = ['#fff5e0', '#ff5c8a', '#7df9ff', '#9bf06a', '#f3c6ff', '#ff8a3d'][i]; c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.ellipse(hx, hy - 22 + up, 10, 6, 0, 0, TAU); c.fill(); c.stroke(); c.beginPath(); c.arc(hx + 8, hy - 28 + up, 4.5, 0, TAU); c.fill(); c.stroke(); } c.strokeStyle = this.OUT; c.lineWidth = 4; c.beginPath(); c.moveTo(0, 8); c.lineTo(0, -54); c.stroke(); for (let i = 0; i < 8; i++) { c.fillStyle = i % 2 ? '#ff5c8a' : '#fff5e0'; c.beginPath(); c.moveTo(0, -80); c.lineTo(Math.cos(i * TAU / 8) * (R + 6), -46 + Math.sin(i * TAU / 8) * (R + 6) * 0.38); c.lineTo(Math.cos((i + 1) * TAU / 8) * (R + 6), -46 + Math.sin((i + 1) * TAU / 8) * (R + 6) * 0.38); c.closePath(); c.fill(); } c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.ellipse(0, -46, R + 6, (R + 6) * 0.38, 0, 0, TAU); c.stroke(); for (let i = 0; i < 6; i++) { const a = spin + i * TAU / 6, hx = Math.cos(a) * R * 0.75, hy = 8 + Math.sin(a) * R * 0.28, up = Math.sin(spin * 3 + i) * 5; if (Math.sin(a) >= 0) continue; c.globalAlpha = 0.5; c.fillStyle = ['#fff5e0', '#ff5c8a', '#7df9ff', '#9bf06a', '#f3c6ff', '#ff8a3d'][i]; c.beginPath(); c.ellipse(hx, hy - 22 + up, 9, 5, 0, 0, TAU); c.fill(); c.globalAlpha = 1; } this.circle(0, -82, 5, '#ffd166', this.OUT, 2); this.text('CAROUSEL', 0, -96, 11, '#ffd166', 'center'); } // the merry-go-round: a striped canopy, horses going round and up and down
    else if (d.kind === 'fortune') { c.fillStyle = 'rgba(0,0,0,0.18)'; c.beginPath(); c.ellipse(4, 24, 44, 14, 0, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 3; c.fillStyle = d.used ? '#3a2a5a' : '#5a2a8a'; c.beginPath(); c.moveTo(-40, 20); c.lineTo(0, -46); c.lineTo(40, 20); c.closePath(); c.fill(); c.stroke(); c.fillStyle = d.used ? '#5a4a7a' : '#ffd166'; for (let i = 0; i < 7; i++) { const a = -0.5 + i * 0.17, sx = Math.cos(a * 3 + d.seed) * 20, sy = -20 + i * 5; c.beginPath(); c.moveTo(sx, sy - 4); c.lineTo(sx + 1.5, sy - 1); c.lineTo(sx + 4, sy); c.lineTo(sx + 1.5, sy + 1); c.lineTo(sx, sy + 4); c.lineTo(sx - 1.5, sy + 1); c.lineTo(sx - 4, sy); c.lineTo(sx - 1.5, sy - 1); c.closePath(); c.fill(); } c.fillStyle = '#0c0810'; c.beginPath(); c.moveTo(-10, 20); c.lineTo(0, 0); c.lineTo(10, 20); c.closePath(); c.fill(); if (!d.used) { const k = 0.5 + 0.5 * Math.sin(G.time * 3); c.fillStyle = `rgba(243,198,255,${0.5 + 0.4 * k})`; c.beginPath(); c.arc(0, 8, 6 + k * 3, 0, TAU); c.fill(); this.text('FORTUNES', 0, -58, 12, '#f3c6ff', 'center'); } } // a starry tent with a crystal ball glowing in the door
    else { const k = 0.5 + 0.5 * Math.sin(G.time * 2.2 + d.seed), wx = Math.sin(G.time * 0.7 + d.seed) * 14, wy = Math.cos(G.time * 0.9 + d.seed) * 10 - 16; c.fillStyle = `rgba(190,230,255,${0.12 + 0.18 * k})`; c.beginPath(); c.arc(wx, wy, 9 + 4 * k, 0, TAU); c.fill(); c.fillStyle = `rgba(230,245,255,${0.5 + 0.4 * k})`; c.beginPath(); c.arc(wx, wy, 2.5, 0, TAU); c.fill(); } // a will-o'-wisp
    c.restore();
  },

  drawPickups() {
    const c = this.ctx, cam = G.cam, vw = this.viewW(), vh = this.viewH(), wanted = Player.blueprint.length ? Player.rebuildPlan().wanted : null; // parts the blueprint is waiting for get a ring
    for (const pk of World.pickups) {
      if (!pk.alive || pk.x < cam.x - 40 || pk.x > cam.x + vw + 40 || pk.y < cam.y - 50 || pk.y > cam.y + vh + 50) continue;
      const bob = Math.sin(pk.bob) * 3, y = pk.y + bob, blink = pk.fuse != null && pk.fuse < 3 && Math.floor(G.time * 8) % 2 === 0, ga = (blink ? 0.4 : 1) * (pk.lift ? Math.max(0.15, pk.lift.t / pk.lift.d) : 1); // the last three seconds: blinking
      c.fillStyle = 'rgba(0,0,0,0.15)'; c.beginPath(); c.ellipse(pk.x, pk.y + 12, 10, 4, 0, 0, TAU); c.fill();
      if (pk.fuse != null) { const k = U.clamp(pk.fuse / CFG.LOOSE.time, 0, 1); c.strokeStyle = k > 0.3 ? '#ffd166' : '#ff5c5c'; c.lineWidth = 3; c.beginPath(); c.arc(pk.x, y, 22, -Math.PI / 2, -Math.PI / 2 + TAU * k); c.stroke(); } // a loose piece on a fuse: the ring runs out
      if (wanted && pk.kind === 'weapon' && wanted.has(pk.id)) { const r = 24 + Math.sin(G.time * 5) * 3; c.strokeStyle = '#ffd166'; c.lineWidth = 2.5; c.setLineDash([6, 5]); c.beginPath(); c.arc(pk.x, y, r, G.time * 2, G.time * 2 + TAU); c.stroke(); c.setLineDash([]); this.text('wanted', pk.x, y + 32, 10, '#ffd166', 'center'); } // the blueprint needs this one
      c.globalAlpha = ga;
      if (pk.kind === 'weapon') {
        const w = BULLETS[pk.id];
        c.globalAlpha = ga * (Player.wouldTake(pk.id, pk) ? 1 : 0.4); // greyed out = no room for it (body and bag full); your own loose pieces always fit
        this.stamp(this.sprite('pkhex,' + w.color, 16, () => { const g = this.ctx; g.fillStyle = w.color; g.strokeStyle = this.OUT; g.lineWidth = 3; g.beginPath(); for (let i = 0; i < 6; i++) { const a = i * TAU / 6; g.lineTo(Math.cos(a) * 14, Math.sin(a) * 14); } g.closePath(); g.fill(); g.stroke(); }), pk.x, y, pk.bob * 0.3); // the hexagon turns as it bobs
        this.stamp(this.sprite('pktile,' + pk.id, 22, () => Icons.tile(w, 0, 0, 30, this.ctx)), pk.x, y, 0); // the same glyph and element badge as on the workbench
        c.globalAlpha = ga;
      } else if (pk.kind === 'dot') { // a crafting dot, like the ones in dungeons; bigger = worth more
        const v = pk.value || 1, r = v >= 5 ? 9 : v > 1 ? 7 : 5;
        c.fillStyle = 'rgba(255,233,168,0.25)'; c.beginPath(); c.arc(pk.x, y, r + 5 + Math.sin(pk.bob * 2) * 1.5, 0, TAU); c.fill();
        this.stamp(this.sprite('pkdot,' + v, r + 8, () => { this.circle(0, 0, r, v > 1 ? '#ffd166' : '#ffe9a8', this.OUT, 2); this.shine(0, 0, r); if (v > 1) this.text(String(v), 0, 1, 10, this.OUT, 'center'); }), pk.x, y, 0);
      } else if (pk.kind === 'cake') { // Eat Me: a slice of pink cake with a flag
        c.fillStyle = 'rgba(255,209,102,0.25)'; c.beginPath(); c.arc(pk.x, y, 20 + Math.sin(pk.bob * 2) * 2, 0, TAU); c.fill();
        c.fillStyle = '#ffb3d9'; c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.beginPath(); c.moveTo(pk.x - 12, y + 8); c.lineTo(pk.x, y - 8); c.lineTo(pk.x + 12, y + 8); c.closePath(); c.fill(); c.stroke(); c.fillStyle = '#fff5e0'; c.fillRect(pk.x - 12, y + 4, 24, 4); c.fillStyle = '#ff5c5c'; c.beginPath(); c.arc(pk.x, y - 9, 3.5, 0, TAU); c.fill(); c.stroke();
        c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.beginPath(); c.moveTo(pk.x + 4, y - 2); c.lineTo(pk.x + 4, y - 20); c.stroke(); c.fillStyle = '#fff'; c.fillRect(pk.x + 4, y - 20, 12, 7); c.strokeRect(pk.x + 4, y - 20, 12, 7);
        this.text('EAT ME', pk.x, y + 24, 10, '#ffd166', 'center');
      } else if (pk.kind === 'potion') { // Drink Me: a little blue bottle
        c.fillStyle = 'rgba(125,249,255,0.25)'; c.beginPath(); c.arc(pk.x, y, 20 + Math.sin(pk.bob * 2) * 2, 0, TAU); c.fill();
        c.fillStyle = '#7df9ff'; c.strokeStyle = this.OUT; c.lineWidth = 2.5; this.roundRect(pk.x - 9, y - 4, 18, 18, 5); c.fill(); c.stroke(); c.fillStyle = '#9bd3ff'; c.fillRect(pk.x - 4, y - 14, 8, 11); c.strokeRect(pk.x - 4, y - 14, 8, 11); c.fillStyle = '#8a5a3a'; c.fillRect(pk.x - 5, y - 18, 10, 5); c.strokeRect(pk.x - 5, y - 18, 10, 5); c.fillStyle = '#fff'; c.fillRect(pk.x - 6, y + 1, 12, 8); this.shine(pk.x, y + 4, 8);
        this.text('DRINK ME', pk.x, y + 26, 10, '#7df9ff', 'center');
      } else if (pk.kind === 'shroom') { // the mushroom: red cap, white spots, slowly cycling hue
        c.fillStyle = 'rgba(243,198,255,0.25)'; c.beginPath(); c.arc(pk.x, y, 20 + Math.sin(pk.bob * 2) * 2, 0, TAU); c.fill();
        c.fillStyle = '#f3e6d0'; c.strokeStyle = this.OUT; c.lineWidth = 2.5; this.roundRect(pk.x - 5, y - 2, 10, 14, 3); c.fill(); c.stroke(); c.fillStyle = `hsl(${(G.time * 60) % 360},85%,55%)`; c.beginPath(); c.ellipse(pk.x, y - 4, 15, 10, 0, Math.PI, 0); c.closePath(); c.fill(); c.stroke(); c.fillStyle = '#fff'; for (const [sx, sy] of [[-7, -7], [2, -11], [8, -6]]) { c.beginPath(); c.arc(pk.x + sx, y + sy, 2.5, 0, TAU); c.fill(); }
        this.text('?', pk.x, y + 24, 12, '#f3c6ff', 'center');
      } else if (pk.kind === 'mega') { // a mega for your deck, dropped by a unique: a shimmering orb with the mega star, and its name
        const M = MEGAS[pk.id], h0 = (G.time * 160) % 360, r = 15 + Math.sin(pk.bob * 2) * 1.5;
        c.fillStyle = `hsla(${h0},90%,65%,0.3)`; c.beginPath(); c.arc(pk.x, y, r + 12, 0, TAU); c.fill();
        const g = c.createLinearGradient(pk.x - r, y - r, pk.x + r, y + r); for (let k = 0; k <= 3; k++) g.addColorStop(k / 3, `hsl(${(h0 + k * 90) % 360},90%,65%)`);
        c.fillStyle = g; c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.arc(pk.x, y, r, 0, TAU); c.fill(); c.stroke(); this.shine(pk.x, y, r);
        Icons.draw('mega', pk.x, y, 8, this.OUT);
        this.text(`MEGA: ${M.name}`, pk.x, y - 30, 12, M.color, 'center');
      } else if (pk.kind === 'egg') { // a Broodmother's egg: hatches a minion
        c.fillStyle = 'rgba(155,240,106,0.25)'; c.beginPath(); c.arc(pk.x, y, 20 + Math.sin(pk.bob * 2) * 2, 0, TAU); c.fill();
        c.fillStyle = '#e6d8ff'; c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.ellipse(pk.x, y, 11, 14, 0, 0, TAU); c.fill(); c.stroke();
        c.fillStyle = '#c58aff'; for (let i = 0; i < 4; i++) c.beginPath(), c.arc(pk.x + Math.cos(i * 1.7) * 5, y + Math.sin(i * 2.3) * 7, 2.2, 0, TAU), c.fill();
        this.shine(pk.x, y - 2, 11);
        this.text('EGG', pk.x, y + 26, 10, '#9bf06a', 'center');
      } else {
        this.circle(pk.x, y, 12, '#fff', this.OUT, 3);
        c.fillStyle = '#ff6b9d'; c.fillRect(pk.x - 2.5, y - 8, 5, 16); c.fillRect(pk.x - 8, y - 2.5, 16, 5);
      }
    }
    this.ctx.globalAlpha = 1;
  },
});
