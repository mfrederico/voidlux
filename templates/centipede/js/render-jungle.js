// The jungle: singing trees (song rings and notes), palms, idols, the temple door, the ziggurat. Its decorations (ferns,
// flowers, gold, glyph stones, skull poles, geysers) are drawn by drawDeco in render-world.js.
// Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  // lap progress around a jungle tree (it sings when the ring closes); a sung-out tree shows a faded ring
  drawSongRing(t) {
    const c = this.ctx, K = CFG.SONG, laps = Math.abs(t.spin || 0) / TAU, k = t.song > 0 || t.sung ? 0 : Math.min(1, laps / (K.laps * Perks.summonLaps()));
    if (t.sung) { c.strokeStyle = 'rgba(243,198,255,0.25)'; c.lineWidth = 2; c.setLineDash([3, 6]); c.beginPath(); c.arc(t.x, t.y, t.r + 16, 0, TAU); c.stroke(); c.setLineDash([]); return; }
    if (t.song > 0) { for (let i = 0; i < 3; i++) { const ph = (G.time * 0.7 + i / 3) % 1; c.strokeStyle = `rgba(243,198,255,${0.45 * (1 - ph)})`; c.lineWidth = 3; c.beginPath(); c.arc(t.x, t.y - t.r * 0.5, t.r + ph * K.range * 0.6, 0, TAU); c.stroke(); } return; }
    if (k > 0.05) {
      c.strokeStyle = 'rgba(0,0,0,0.25)'; c.lineWidth = 5; c.beginPath(); c.arc(t.x, t.y, t.r + 16, 0, TAU); c.stroke();
      c.strokeStyle = k >= 1 ? '#fff' : '#f3c6ff'; c.lineWidth = 5; c.lineCap = 'round'; c.beginPath(); c.arc(t.x, t.y, t.r + 16, -Math.PI / 2, -Math.PI / 2 + k * TAU); c.stroke();
      this.text(k >= 1 ? 'SUMMONED' : `summoning ${Math.round(k * 100)}%`, t.x, t.y - t.r * 2 - 18, 12, '#f3c6ff', 'center');
    }
  },

  drawNotes(t) { // music floating up out of a singing tree
    const c = this.ctx;
    for (let i = 0; i < 5; i++) {
      const ph = (G.time * 0.6 + i * 0.2 + t.seed) % 1, x = t.x + Math.sin(ph * 7 + i * 2) * 26 + (i - 2) * 10, y = t.y - t.r * 1.2 - ph * 90, a = 1 - ph;
      c.fillStyle = `rgba(${i % 2 ? '255,209,102' : '243,198,255'},${a})`; c.strokeStyle = `rgba(43,29,58,${a})`; c.lineWidth = 2;
      c.beginPath(); c.ellipse(x, y + 5, 5, 3.5, -0.5, 0, TAU); c.fill(); c.stroke(); c.beginPath(); c.moveTo(x + 4, y + 4); c.lineTo(x + 4, y - 12); c.stroke();
      if (i % 2) { c.beginPath(); c.moveTo(x + 4, y - 12); c.quadraticCurveTo(x + 12, y - 10, x + 10, y - 2); c.stroke(); }
    }
  },

  drawPalm(t) {
    const c = this.ctx, r = t.r, sway = Math.sin(G.time * 1.3 + t.seed) * 3, top = t.y - r * 1.6;
    c.fillStyle = 'rgba(0,0,0,0.18)'; c.beginPath(); c.ellipse(t.x + 4, t.y + 8, r * 0.8, r * 0.4, 0, 0, TAU); c.fill();
    c.strokeStyle = this.OUT; c.lineWidth = 9; c.lineCap = 'round'; c.beginPath(); c.moveTo(t.x, t.y + 4); c.quadraticCurveTo(t.x + sway * 2, t.y - r * 0.8, t.x + sway * 3, top); c.stroke();
    c.strokeStyle = '#8a5a3a'; c.lineWidth = 5; c.stroke();
    c.strokeStyle = '#6a4228'; c.lineWidth = 1.5; for (let k = 1; k < 5; k++) { const y = t.y + 4 - k * r * 0.32, x = t.x + sway * 3 * (k / 5); c.beginPath(); c.moveTo(x - 3, y); c.lineTo(x + 3, y); c.stroke(); } // rings on the trunk
    const hx = t.x + sway * 3, hy = top;
    for (let i = 0; i < 7; i++) { // fronds
      const a = -Math.PI / 2 + (i - 3) * 0.5 + Math.sin(G.time * 2 + i + t.seed) * 0.05, L = r * (i === 3 ? 1.5 : 1.3), ex = hx + Math.cos(a) * L, ey = hy + Math.sin(a) * L + L * 0.35;
      c.strokeStyle = this.OUT; c.lineWidth = 7; c.beginPath(); c.moveTo(hx, hy); c.quadraticCurveTo(hx + Math.cos(a) * L * 0.6, hy + Math.sin(a) * L * 0.6 - 6, ex, ey); c.stroke();
      c.strokeStyle = `hsl(${125 + t.hue},50%,${34 + (i % 2) * 8}%)`; c.lineWidth = 4; c.stroke();
    }
    c.fillStyle = '#c98a3a'; for (const [dx, dy] of [[-5, 4], [4, 6], [0, 10]]) { c.beginPath(); c.arc(hx + dx, hy + dy, 3.5, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.stroke(); } // coconuts
  },

  // a carved stone head (the jungle's boulders): same collision, same waking
  drawIdol(b) {
    const c = this.ctx, r = b.r, laps = Math.abs(b.spin || 0) / TAU, k = Math.min(1, laps / CFG.ROCK.laps);
    if (k > 0.05) { c.strokeStyle = 'rgba(0,0,0,0.25)'; c.lineWidth = 5; c.beginPath(); c.arc(b.x, b.y, r + 14, 0, TAU); c.stroke(); c.strokeStyle = k >= 1 ? '#fff' : '#ffd166'; c.lineWidth = 5; c.lineCap = 'round'; c.beginPath(); c.arc(b.x, b.y, r + 14, -Math.PI / 2, -Math.PI / 2 + k * TAU); c.stroke(); this.text(k >= 1 ? 'SUMMONED' : `summoning ${Math.round(k * 100)}%`, b.x, b.y - r - 26, 12, '#ffd166', 'center'); }
    c.save(); if (k > 0.3) c.translate(Math.sin(G.time * 40) * k * 2.5, 0);
    c.fillStyle = 'rgba(0,0,0,0.18)'; c.beginPath(); c.ellipse(b.x + 3, b.y + 6, r, r * 0.6, 0, 0, TAU); c.fill();
    c.fillStyle = '#9a8f6a'; c.strokeStyle = this.OUT; c.lineWidth = 3; this.roundRect(b.x - r, b.y - r * 1.1, r * 2, r * 2.1, r * 0.3); c.fill(); c.stroke();
    c.fillStyle = '#7a7050'; this.roundRect(b.x - r * 0.9, b.y - r * 1.3, r * 1.8, r * 0.35, 3); c.fill(); c.stroke(); // headdress band
    c.fillStyle = '#5fd9a8'; for (let i = -1; i <= 1; i++) { c.beginPath(); c.arc(b.x + i * r * 0.55, b.y - r * 1.12, r * 0.12, 0, TAU); c.fill(); } // jade studs
    c.fillStyle = this.OUT; for (const s of [-1, 1]) { c.beginPath(); c.ellipse(b.x + s * r * 0.42, b.y - r * 0.3, r * 0.22, r * 0.14, 0, 0, TAU); c.fill(); } // eyes
    c.beginPath(); c.moveTo(b.x, b.y - r * 0.1); c.lineTo(b.x - r * 0.15, b.y + r * 0.25); c.lineTo(b.x + r * 0.15, b.y + r * 0.25); c.closePath(); c.fill(); // nose
    c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.beginPath(); c.moveTo(b.x - r * 0.45, b.y + r * 0.55); c.lineTo(b.x + r * 0.45, b.y + r * 0.55); c.stroke(); for (let i = -2; i <= 2; i++) { c.beginPath(); c.moveTo(b.x + i * r * 0.2, b.y + r * 0.55); c.lineTo(b.x + i * r * 0.2, b.y + r * 0.75); c.stroke(); } // teeth
    c.fillStyle = '#5a8a3a'; c.beginPath(); c.ellipse(b.x + r * 0.6, b.y + r * 0.8, r * 0.4, r * 0.2, -0.4, 0, TAU); c.fill(); // moss
    c.restore();
  },

  // a temple doorway: the jungle's dungeon entrance
  drawTempleDoor(en) {
    const c = this.ctx, x = en.x, y = en.y;
    c.fillStyle = 'rgba(0,0,0,0.2)'; c.beginPath(); c.ellipse(x + 4, y + 16, 52, 20, 0, 0, TAU); c.fill();
    c.fillStyle = '#9a8f6a'; c.strokeStyle = this.OUT; c.lineWidth = 3;
    for (let k = 0; k < 3; k++) { const w = 52 - k * 10, h = 14; this.roundRect(x - w, y - 18 - k * h, w * 2, h, 3); c.fill(); c.stroke(); } // stepped
    c.fillStyle = '#b5a97a'; this.roundRect(x - 40, y - 18, 80, 34, 3); c.fill(); c.stroke();
    c.strokeStyle = '#6a6040'; c.lineWidth = 2; for (const [gx, gy] of [[-30, -8], [-30, 6], [28, -8], [28, 6]]) { c.beginPath(); c.rect(gx + x - 5, gy + y - 4, 10, 8); c.stroke(); c.beginPath(); c.moveTo(x + gx - 5, y + gy); c.lineTo(x + gx + 5, y + gy); c.stroke(); } // glyphs
    c.fillStyle = en.used ? '#2a2618' : '#0c0a08'; c.beginPath(); c.rect(x - 15, y - 12, 30, 28); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 3; c.stroke(); // the doorway
    if (!en.used) {
      const pulse = 0.5 + 0.5 * Math.sin(G.time * 4);
      c.fillStyle = `rgba(95,217,168,${0.25 + 0.35 * pulse})`; c.beginPath(); c.arc(x, y + 2, 8 + pulse * 4, 0, TAU); c.fill();
      for (const s of [-1, 1]) { const fl = 0.7 + 0.3 * Math.sin(G.time * 14 + s); c.fillStyle = `rgba(255,200,90,${0.8 * fl})`; c.beginPath(); c.ellipse(x + s * 30, y - 10 - fl * 2, 3, 5 * fl, 0, 0, TAU); c.fill(); } // torches
      this.text('TEMPLE', x, y - 72, 14, '#5fd9a8', 'center');
    }
  },

  // the stepped pyramid the level-3 boss sits on
  drawZiggurat(x, y) {
    const c = this.ctx;
    for (let k = 0; k < 4; k++) { const w = 520 - k * 110, h = 340 - k * 72; c.fillStyle = ['#8f8358', '#a39664', '#b5a872', '#c6b980'][k]; c.strokeStyle = this.OUT; c.lineWidth = 3; this.roundRect(x - w / 2, y - h / 2, w, h, 6); c.fill(); c.stroke(); }
    c.strokeStyle = '#6a6040'; c.lineWidth = 2; for (let k = -2; k <= 2; k++) { c.beginPath(); c.moveTo(x - 260, y + k * 40); c.lineTo(x - 150, y + k * 40); c.moveTo(x + 150, y + k * 40); c.lineTo(x + 260, y + k * 40); c.stroke(); } // stairs
    c.fillStyle = '#5fd9a8'; for (const [dx, dy] of [[-200, -140], [200, -140], [-200, 140], [200, 140]]) { c.beginPath(); c.arc(x + dx, y + dy, 9, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 2; c.stroke(); } // jade corner stones
  },
});
