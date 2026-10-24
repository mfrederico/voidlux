// The graveyard: charred trees, graves, the crypt door, the wraiths, the fog. Its litter (bones, skulls, ribs, coffins) is
// drawn by drawDeco in render-world.js.
// Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  // charred tree: a black trunk with bare forked branches, ash at the base, the odd ember still glowing
  drawCharredTree(t) {
    const c = this.ctx, r = t.r, sway = Math.sin(G.time * 1.2 + t.seed) * 1.5;
    c.fillStyle = 'rgba(0,0,0,0.2)'; c.beginPath(); c.ellipse(t.x + 4, t.y + 8, r * 0.7, r * 0.3, 0, 0, TAU); c.fill();
    c.fillStyle = 'rgba(130,130,138,0.45)'; c.beginPath(); c.ellipse(t.x, t.y + 6, r * 0.85, r * 0.3, 0, 0, TAU); c.fill(); // ash
    const top = t.y - r * 1.0;
    const line = (x0, y0, x1, y1, w1, w2) => { c.lineCap = 'round'; c.strokeStyle = this.OUT; c.lineWidth = w1; c.beginPath(); c.moveTo(x0, y0); c.lineTo(x1, y1); c.stroke(); c.strokeStyle = '#2a2328'; c.lineWidth = w2; c.beginPath(); c.moveTo(x0, y0); c.lineTo(x1, y1); c.stroke(); };
    line(t.x, t.y + 6, t.x + sway * 0.4, top, 11, 6);
    const branches = [[-1, -0.55, 0.8], [0.9, -0.7, 0.75], [-0.45, -1.05, 0.55], [0.5, -1.1, 0.5], [1.05, -0.2, 0.45], [-0.8, 0.05, 0.4]]; // direction and length (in r) from points up the trunk
    branches.forEach(([bx, by, bl], i) => {
      const sy = top + r * (0.15 + (i % 3) * 0.28), sx = t.x + sway * 0.4;
      const ex = sx + bx * r * bl + sway, ey = sy + by * r * bl;
      line(sx, sy, ex, ey, 6, 3);
      line(ex, ey, ex + bx * r * 0.3 - by * r * 0.15, ey + by * r * 0.3 + bx * r * 0.15, 4, 2); // a fork at the tip
      if (((t.seed * 7 + i) | 0) % 3 === 0) { const g = 0.5 + 0.5 * Math.sin(G.time * 3 + t.seed + i); c.fillStyle = `rgba(255,120,40,${0.35 + 0.65 * g})`; c.beginPath(); c.arc(ex, ey, 2.2 + g, 0, TAU); c.fill(); if (U.chance(0.02)) Combat.particles.push(Combat.mk(ex, ey, U.rand(-6, 6), U.rand(-30, -15), 0.8, '#ff8a3d', 2)); } // an ember
    });
  },

  drawGrave(g) {
    const c = this.ctx, x = g.x, y = g.y, r = g.r, K = CFG.GRAVE;
    const laps = Math.abs(g.spin || 0) / TAU, k = g.used ? 0 : Math.min(1, laps / (K.laps * Perks.summonLaps()));
    if (k > 0.05) { // lap progress ring: the ghost rises when it closes (CFG laps, fewer with Summoner)
      c.strokeStyle = 'rgba(0,0,0,0.25)'; c.lineWidth = 5; c.beginPath(); c.arc(x, y, r + 16, 0, TAU); c.stroke();
      c.strokeStyle = k >= 1 ? '#fff' : '#dfe6ff'; c.lineWidth = 5; c.lineCap = 'round'; c.beginPath(); c.arc(x, y, r + 16, -Math.PI / 2, -Math.PI / 2 + k * TAU); c.stroke();
      this.text(k >= 1 ? 'SUMMONED' : `summoning ${Math.round(k * 100)}%`, x, y - r * 1.7 - 16, 12, '#dfe6ff', 'center');
    }
    c.fillStyle = 'rgba(0,0,0,0.2)'; c.beginPath(); c.ellipse(x + 3, y + 12, r * 1.1, r * 0.4, 0, 0, TAU); c.fill();
    c.fillStyle = g.used ? '#1e1a22' : '#4b4a50'; c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.beginPath(); c.ellipse(x, y + 8, r * 1.15, r * 0.45, 0, 0, TAU); c.fill(); c.stroke(); // the mound, or the open grave
    if (g.used) { c.fillStyle = '#6a6456'; for (const [dx, dy] of [[-r * 1.1, 2], [r * 1.0, 5], [-r * 0.3, 12]]) { c.beginPath(); c.arc(x + dx, y + 8 + dy, 4, 0, TAU); c.fill(); } }
    c.save(); if (k > 0.3) c.translate(Math.sin(G.time * 40) * k * 2, 0);
    const st = g.used ? '#7a7680' : '#a9a6ad', dk = '#6a6670';
    c.fillStyle = st; c.strokeStyle = this.OUT; c.lineWidth = 2.5;
    if (g.kind === 'cross') { this.roundRect(x - 4, y - r * 1.7, 8, r * 1.8, 3); c.fill(); c.stroke(); this.roundRect(x - r * 0.6, y - r * 1.25, r * 1.2, 8, 3); c.fill(); c.stroke(); }
    else if (g.kind === 'slab') { c.save(); c.translate(x, y); c.rotate(-0.08); this.roundRect(-r * 0.85, -r * 1.1, r * 1.7, r * 1.25, 4); c.fill(); c.stroke(); c.restore(); }
    else { c.beginPath(); c.moveTo(x - r * 0.7, y + 4); c.lineTo(x - r * 0.7, y - r * 0.9); c.arc(x, y - r * 0.9, r * 0.7, Math.PI, 0); c.lineTo(x + r * 0.7, y + 4); c.closePath(); c.fill(); c.stroke(); }
    c.strokeStyle = dk; c.lineWidth = 1.5; c.beginPath(); c.moveTo(x - 5, y - r * 0.7); c.lineTo(x + 5, y - r * 0.7); c.moveTo(x - 5, y - r * 0.45); c.lineTo(x + 5, y - r * 0.45); c.stroke(); // the inscription
    if (g.seed > 5) { c.beginPath(); c.moveTo(x + r * 0.3, y - r * 1.3); c.lineTo(x + r * 0.1, y - r * 0.9); c.lineTo(x + r * 0.35, y - r * 0.6); c.stroke(); } // a crack
    if (g.seed > 7 && g.kind !== 'cross') { c.fillStyle = '#5a7a4a'; c.beginPath(); c.ellipse(x - r * 0.4, y - r * 0.2, r * 0.3, r * 0.14, 0.4, 0, TAU); c.fill(); } // moss
    c.restore();
  },

  // a mausoleum door: the graveyard's dungeon entrance
  drawCrypt(en) {
    const c = this.ctx, x = en.x, y = en.y;
    c.fillStyle = 'rgba(0,0,0,0.2)'; c.beginPath(); c.ellipse(x + 4, y + 16, 48, 18, 0, 0, TAU); c.fill();
    c.fillStyle = '#6e6a74'; c.strokeStyle = this.OUT; c.lineWidth = 3; this.roundRect(x - 42, y - 30, 84, 44, 4); c.fill(); c.stroke();
    c.fillStyle = '#5a5660'; c.beginPath(); c.moveTo(x - 48, y - 30); c.lineTo(x, y - 58); c.lineTo(x + 48, y - 30); c.closePath(); c.fill(); c.stroke(); // the roof
    c.strokeStyle = '#8a8690'; c.lineWidth = 1.5; for (const yy of [y - 18, y - 6, y + 6]) { c.beginPath(); c.moveTo(x - 40, yy); c.lineTo(x + 40, yy); c.stroke(); } // stone courses
    c.fillStyle = '#d9d4c7'; c.strokeStyle = this.OUT; c.lineWidth = 2; this.roundRect(x - 3, y - 76, 6, 20, 2); c.fill(); c.stroke(); this.roundRect(x - 8, y - 70, 16, 5, 2); c.fill(); c.stroke(); // a cross on top
    c.fillStyle = en.used ? '#2a2630' : '#0c0a12'; c.beginPath(); c.moveTo(x - 16, y + 14); c.lineTo(x - 16, y - 12); c.arc(x, y - 12, 16, Math.PI, 0); c.lineTo(x + 16, y + 14); c.closePath(); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 3; c.stroke(); // the doorway
    if (!en.used) {
      const pulse = 0.5 + 0.5 * Math.sin(G.time * 4);
      c.fillStyle = `rgba(190,230,255,${0.25 + 0.35 * pulse})`; c.beginPath(); c.arc(x, y, 8 + pulse * 4, 0, TAU); c.fill();
      for (const s of [-1, 1]) { const fl = 0.7 + 0.3 * Math.sin(G.time * 14 + s); c.fillStyle = `rgba(255,200,90,${0.8 * fl})`; c.beginPath(); c.ellipse(x + s * 28, y - 8 - fl * 2, 3, 5 * fl, 0, 0, TAU); c.fill(); } // torches
      this.text('CRYPT', x, y - 90, 14, '#dfe6ff', 'center');
    }
  },

  // the ghost that rose from a grave: it hangs over the stone, reaches out to each monster it charmed, then fades
  drawWraiths() {
    const c = this.ctx;
    for (const w of World.wraiths) {
      const k = w.t / w.life, rise = Math.min(1, w.t / 0.6), fade = k > 0.75 ? (1 - k) / 0.25 : 1;
      const y = w.y - 10 - rise * 55 + Math.sin(G.time * 3 + w.seed) * 6, r = 22 * rise;
      c.save(); c.globalAlpha = fade;
      if (w.t < 1.4) for (const t of w.targets) { if (t.dead) continue; c.strokeStyle = `rgba(223,230,255,${0.6 * (1 - w.t / 1.4)})`; c.lineWidth = 2; c.setLineDash([6, 8]); c.lineDashOffset = -G.time * 60; c.beginPath(); c.moveTo(w.x, y); c.lineTo(t.x, t.y); c.stroke(); c.setLineDash([]); }
      c.fillStyle = 'rgba(223,230,255,0.25)'; c.beginPath(); c.arc(w.x, y, r + 14, 0, TAU); c.fill();
      c.fillStyle = 'rgba(235,240,255,0.85)'; c.strokeStyle = this.OUT; c.lineWidth = 2.5;
      c.beginPath(); c.arc(w.x, y - 2, r, Math.PI, 0); c.lineTo(w.x + r, y + r * 0.7);
      for (let i = 3; i >= -3; i--) c.lineTo(w.x + i * r / 3, y + r * 0.7 + ((i + 3) % 2 ? 6 : 0) + Math.sin(G.time * 8 + i) * 2);
      c.closePath(); c.fill(); c.stroke();
      if (rise > 0.5) { this.eyes(w.x, y - 1, -Math.PI / 2, r, { spread: r * 0.42, fwd: 0, er: r * 0.28, pupil: '#2a6f9a' }); c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.arc(w.x, y + r * 0.25, r * 0.22, 0, TAU); c.stroke(); } // a round 'ooo' mouth
      c.restore();
    }
  },

  // drifting mist over haunted ground
  drawFog() {
    const c = this.ctx, cam = G.cam, vx0 = cam.x - 300, vx1 = cam.x + CFG.W + 300;
    for (const R of World.regions) {
      if (!R.haunted || R.x1 < vx0 || R.x0 > vx1) continue;
      for (let k = 0; k < 9; k++) {
        const x = R.x0 + ((k * 431 + R.idx * 97 + G.time * (12 + (k % 3) * 6)) % (CFG.REGION_W + 600)) - 300, y = 120 + (k * 277) % (CFG.WORLD_H - 240) + Math.sin(G.time * 0.5 + k) * 20;
        if (x < vx0 || x > vx1) continue;
        c.fillStyle = `rgba(200,205,220,${0.07 + 0.04 * (k % 2)})`; c.beginPath(); c.ellipse(x, y, 280 + (k % 4) * 40, 70 + (k % 3) * 20, 0, 0, TAU); c.fill();
      }
    }
  },
});
