// The circus: giant mushrooms, beach balls, the tent door, cannons, the hall of mirrors. Its midway decorations are drawn by
// drawDeco in render-world.js.
// Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  drawMushroom(t) { // a giant spotted mushroom with a sleepy face, breathing
    const c = this.ctx, r = t.r, br = 1 + 0.04 * Math.sin(G.time * 1.6 + t.seed), cy = t.y - r * 0.9;
    c.fillStyle = 'rgba(0,0,0,0.18)'; c.beginPath(); c.ellipse(t.x + 4, t.y + 8, r * 0.9, r * 0.4, 0, 0, TAU); c.fill();
    c.fillStyle = '#f3e6d0'; c.strokeStyle = this.OUT; c.lineWidth = 3; this.roundRect(t.x - r * 0.38, t.y - r * 0.9, r * 0.76, r * 1.0, r * 0.2); c.fill(); c.stroke(); // the stalk
    const hue = (t.seed * 70 + G.time * 20) % 360;
    c.fillStyle = `hsl(${hue},80%,55%)`; c.beginPath(); c.ellipse(t.x, cy, r * 1.15 * br, r * 0.75 * br, 0, Math.PI, 0); c.lineTo(t.x + r * 1.15 * br, cy); c.closePath(); c.fill(); c.stroke(); // the cap
    c.fillStyle = `hsl(${(hue + 180) % 360},80%,85%)`; for (const [dx, dy, rr] of [[-0.6, -0.3, 0.16], [0.1, -0.5, 0.2], [0.65, -0.2, 0.13], [-0.2, -0.1, 0.1]]) { c.beginPath(); c.ellipse(t.x + dx * r * br, cy + dy * r * br, rr * r, rr * r * 0.7, 0, 0, TAU); c.fill(); } // spots
    c.strokeStyle = this.OUT; c.lineWidth = 2; for (const s of [-1, 1]) { c.beginPath(); c.arc(t.x + s * r * 0.16, t.y - r * 0.45, r * 0.08, 0.2, Math.PI - 0.2); c.stroke(); } // closed, sleepy eyes
    c.beginPath(); c.arc(t.x, t.y - r * 0.2, r * 0.07, 0, Math.PI); c.stroke(); // a little smile
    if (Math.floor(G.time * 0.5 + t.seed) % 4 === 0) this.text('z', t.x + r * 0.5, t.y - r * 0.7 - ((G.time * 10) % 20), 11, '#fff', 'center');
  },

  drawBeachBall(b) { // the circus's boulder: same collision, same waking
    const c = this.ctx, r = b.r, laps = Math.abs(b.spin || 0) / TAU, k = Math.min(1, laps / CFG.ROCK.laps);
    if (k > 0.05) { c.strokeStyle = 'rgba(0,0,0,0.25)'; c.lineWidth = 5; c.beginPath(); c.arc(b.x, b.y, r + 14, 0, TAU); c.stroke(); c.strokeStyle = k >= 1 ? '#fff' : '#ffd166'; c.lineWidth = 5; c.lineCap = 'round'; c.beginPath(); c.arc(b.x, b.y, r + 14, -Math.PI / 2, -Math.PI / 2 + k * TAU); c.stroke(); this.text(k >= 1 ? 'SUMMONED' : `summoning ${Math.round(k * 100)}%`, b.x, b.y - r - 26, 12, '#ffd166', 'center'); }
    c.save(); if (k > 0.3) c.translate(Math.sin(G.time * 40) * k * 2.5, 0);
    c.fillStyle = 'rgba(0,0,0,0.18)'; c.beginPath(); c.ellipse(b.x + 3, b.y + 6, r, r * 0.6, 0, 0, TAU); c.fill();
    const cols = ['#ff5c8a', '#ffd166', '#7df9ff', '#fff', '#9bf06a', '#ff8a3d'];
    for (let i = 0; i < 6; i++) { c.fillStyle = cols[i]; c.beginPath(); c.moveTo(b.x, b.y); c.arc(b.x, b.y, r, i * TAU / 6 + b.seed, (i + 1) * TAU / 6 + b.seed); c.closePath(); c.fill(); }
    c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.arc(b.x, b.y, r, 0, TAU); c.stroke(); this.shine(b.x, b.y, r);
    c.restore();
  },

  drawTentDoor(en) { // a little striped tent with the flap open: the circus's dungeon entrance
    const c = this.ctx, x = en.x, y = en.y, pulse = 0.5 + 0.5 * Math.sin(G.time * 4);
    c.fillStyle = 'rgba(0,0,0,0.2)'; c.beginPath(); c.ellipse(x + 4, y + 18, 56, 20, 0, 0, TAU); c.fill();
    c.strokeStyle = this.OUT; c.lineWidth = 3;
    for (let i = 0; i < 6; i++) { c.fillStyle = i % 2 ? '#ff5c8a' : '#fff5e0'; c.beginPath(); c.moveTo(x, y - 70); c.lineTo(x - 54 + i * 18, y + 14); c.lineTo(x - 54 + (i + 1) * 18, y + 14); c.closePath(); c.fill(); }
    c.beginPath(); c.moveTo(x - 54, y + 14); c.lineTo(x, y - 70); c.lineTo(x + 54, y + 14); c.closePath(); c.stroke();
    c.fillStyle = en.used ? '#2a1a2a' : '#0c0810'; c.beginPath(); c.moveTo(x - 14, y + 14); c.lineTo(x, y - 20); c.lineTo(x + 14, y + 14); c.closePath(); c.fill(); c.stroke(); // the flap
    c.fillStyle = '#ffd166'; c.beginPath(); c.moveTo(x, y - 70); c.lineTo(x + 18, y - 64); c.lineTo(x, y - 58); c.closePath(); c.fill(); c.stroke(); // the pennant
    if (!en.used) { c.fillStyle = `rgba(255,209,102,${0.25 + 0.35 * pulse})`; c.beginPath(); c.arc(x, y + 2, 8 + pulse * 4, 0, TAU); c.fill(); for (let i = -2; i <= 2; i++) { const on = Math.floor(G.time * 5 + i) % 3 === 0; c.fillStyle = on ? '#ffd166' : '#ff5c8a'; c.beginPath(); c.arc(x + i * 20, y - 70 + Math.abs(i) * 30, 3.5, 0, TAU); c.fill(); } this.text('BIG TOP', x, y - 88, 14, '#ffd166', 'center'); }
  },

  drawCannon(cn) { // a circus cannon on a wheeled carriage; the barrel swings toward the crowd; a ring counts the laps
    const c = this.ctx, K = CFG.CANNON, laps = Math.abs(cn.spin || 0) / TAU, k = cn.cd > 0 ? 0 : Math.min(1, laps / (K.laps * Perks.summonLaps())), r = cn.r;
    if (k > 0.05) { c.strokeStyle = 'rgba(0,0,0,0.25)'; c.lineWidth = 5; c.beginPath(); c.arc(cn.x, cn.y, r + 18, 0, TAU); c.stroke(); c.strokeStyle = k >= 1 ? '#fff' : '#ff5c8a'; c.lineWidth = 5; c.lineCap = 'round'; c.beginPath(); c.arc(cn.x, cn.y, r + 18, -Math.PI / 2, -Math.PI / 2 + k * TAU); c.stroke(); this.text(k >= 1 ? 'SUMMONED' : `summoning ${Math.round(k * 100)}%`, cn.x, cn.y - r - 30, 12, '#ff5c8a', 'center'); }
    if (cn.recoil > 0) cn.recoil -= 1 / 60;
    c.fillStyle = 'rgba(0,0,0,0.2)'; c.beginPath(); c.ellipse(cn.x + 4, cn.y + r * 0.6, r * 1.3, r * 0.5, 0, 0, TAU); c.fill();
    for (const s of [-1, 1]) { this.circle(cn.x + s * r * 0.7, cn.y + r * 0.35, r * 0.42, '#8a1f3a', this.OUT, 3); c.strokeStyle = '#ffd166'; c.lineWidth = 2; for (let i = 0; i < 4; i++) { const a = i * Math.PI / 4 + cn.seed; c.beginPath(); c.moveTo(cn.x + s * r * 0.7 - Math.cos(a) * r * 0.35, cn.y + r * 0.35 - Math.sin(a) * r * 0.35); c.lineTo(cn.x + s * r * 0.7 + Math.cos(a) * r * 0.35, cn.y + r * 0.35 + Math.sin(a) * r * 0.35); c.stroke(); } } // wheels
    c.save(); c.translate(cn.x, cn.y); c.rotate(cn.a); const back = cn.recoil > 0 ? -cn.recoil * 30 : 0;
    c.fillStyle = '#4a4a58'; c.strokeStyle = this.OUT; c.lineWidth = 3.5; this.roundRect(-r * 0.9 + back, -r * 0.5, r * 2.2, r, r * 0.3); c.fill(); c.stroke();
    c.fillStyle = '#ffd166'; this.roundRect(-r * 0.6 + back, -r * 0.55, r * 0.3, r * 1.1, 3); c.fill(); c.stroke(); this.roundRect(r * 0.6 + back, -r * 0.55, r * 0.3, r * 1.1, 3); c.fill(); c.stroke(); // bands
    c.fillStyle = this.OUT; c.beginPath(); c.ellipse(r * 1.3 + back, 0, r * 0.18, r * 0.42, 0, 0, TAU); c.fill(); // the muzzle
    c.restore();
    if (k > 0.3 || cn.recoil > 0) { for (let i = 0; i < 3; i++) { const ph = (G.time * 0.8 + i / 3) % 1; c.fillStyle = `rgba(255,255,255,${0.35 * (1 - ph)})`; c.beginPath(); c.arc(cn.x + Math.cos(cn.a) * (r * 1.4 + ph * 30), cn.y + Math.sin(cn.a) * (r * 1.4 + ph * 30), 4 + ph * 10, 0, TAU); c.fill(); } } // a fuse smoking
    this.text(cn.cd > 0 ? 'reloading' : 'CANNON', cn.x, cn.y + r + 16, 12, cn.cd > 0 ? '#aaa' : '#ff5c8a', 'center');
  },

  drawMirrors() { // the hall of mirrors: your reflection crawls on the far side when you are near
    const c = this.ctx, P = Player;
    for (const R of World.regions) { if (!R.circus) continue; for (const d of R.deco) {
      if (d.kind !== 'mirror' || Math.abs(d.x - P.x) > 320 || Math.abs(d.y - P.y) > 260 || !P.alive) continue;
      const mx = d.x; // mirror axis: a vertical line through the hall
      c.save(); c.globalAlpha = 0.45 * Math.max(0, 1 - Math.abs(mx - P.x) / 320);
      c.beginPath(); c.rect(mx - 70, d.y - 90, 140, 150); c.clip();
      const rx = x => 2 * mx - x;
      for (let i = P.segments.length - 1; i >= 0; i--) { const p = P.segPos[i]; if (!p) continue; this.circle(rx(p.x), p.y, CFG.SEG_R * P.scale(), P.segColor(P.segments[i]), this.OUT, 2); }
      this.circle(rx(P.x), P.y, CFG.HEAD_R * P.scale(), '#ffb86b', this.OUT, 3); this.eyes(rx(P.x), P.y, Math.PI - P.angle, CFG.HEAD_R * P.scale(), { spread: CFG.HEAD_R * 0.5, fwd: CFG.HEAD_R * 0.2, er: CFG.HEAD_R * 0.36 });
      c.restore();
    } }
  },
});
