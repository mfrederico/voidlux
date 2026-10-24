// The farm: corn, hay bales, the saucer hatch, and the tractor (also driven by the player and by monsters). Its decorations
// (pumpkins, scarecrows, the barn, fences, probes) are drawn by drawDeco in render-world.js.
// Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  drawCorn(t) { // a clump of corn stalks
    const c = this.ctx, r = t.r, sway = Math.sin(G.time * 1.4 + t.seed) * 2;
    c.fillStyle = 'rgba(0,0,0,0.15)'; c.beginPath(); c.ellipse(t.x + 3, t.y + 7, r * 0.8, r * 0.35, 0, 0, TAU); c.fill();
    for (let i = -2; i <= 2; i++) {
      const x = t.x + i * r * 0.3, top = t.y - r * (1.6 + Math.abs(i) * -0.15 + ((t.seed * 3 + i) % 1) * 0.3);
      c.strokeStyle = this.OUT; c.lineWidth = 5; c.lineCap = 'round'; c.beginPath(); c.moveTo(x, t.y + 4); c.lineTo(x + sway, top); c.stroke(); c.strokeStyle = '#8fbf3a'; c.lineWidth = 2.5; c.stroke();
      for (const sg of [-1, 1]) { const ly = t.y - r * 0.6 + sg * r * 0.25; c.strokeStyle = this.OUT; c.lineWidth = 4; c.beginPath(); c.moveTo(x, ly); c.quadraticCurveTo(x + sg * r * 0.35, ly - r * 0.15, x + sg * r * 0.5 + sway, ly - r * 0.45); c.stroke(); c.strokeStyle = '#a3d048'; c.lineWidth = 2; c.stroke(); } // leaves
      if (i % 2 === 0) { c.fillStyle = '#ffd166'; c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.beginPath(); c.ellipse(x + 4, t.y - r * 0.9, 3.5, 7, 0.3, 0, TAU); c.fill(); c.stroke(); } // an ear
      c.fillStyle = '#e8d28a'; c.beginPath(); c.moveTo(x + sway, top); c.lineTo(x + sway - 4, top - 8); c.lineTo(x + sway + 4, top - 8); c.closePath(); c.fill(); // the tassel
    }
  },

  drawHay(b) { // a round hay bale: same collision and waking as a boulder
    const c = this.ctx, r = b.r, laps = Math.abs(b.spin || 0) / TAU, k = Math.min(1, laps / CFG.ROCK.laps);
    if (k > 0.05) { c.strokeStyle = 'rgba(0,0,0,0.25)'; c.lineWidth = 5; c.beginPath(); c.arc(b.x, b.y, r + 14, 0, TAU); c.stroke(); c.strokeStyle = k >= 1 ? '#fff' : '#ffd166'; c.lineWidth = 5; c.lineCap = 'round'; c.beginPath(); c.arc(b.x, b.y, r + 14, -Math.PI / 2, -Math.PI / 2 + k * TAU); c.stroke(); this.text(k >= 1 ? 'SUMMONED' : `summoning ${Math.round(k * 100)}%`, b.x, b.y - r - 26, 12, '#ffd166', 'center'); }
    c.save(); if (k > 0.3) c.translate(Math.sin(G.time * 40) * k * 2.5, 0);
    c.fillStyle = 'rgba(0,0,0,0.18)'; c.beginPath(); c.ellipse(b.x + 3, b.y + 6, r, r * 0.6, 0, 0, TAU); c.fill();
    this.circle(b.x, b.y, r, '#d9b85a', this.OUT, 3);
    c.strokeStyle = '#a88a3a'; c.lineWidth = 2; for (let i = 1; i <= 3; i++) { c.beginPath(); c.arc(b.x, b.y, r * i / 4, 0, TAU); c.stroke(); } // the spiral of the roll
    c.strokeStyle = '#b8963f'; c.lineWidth = 2.5; c.beginPath(); c.moveTo(b.x - r, b.y - 3); c.lineTo(b.x + r, b.y - 3); c.moveTo(b.x - r, b.y + 4); c.lineTo(b.x + r, b.y + 4); c.stroke(); // twine
    c.restore();
  },

  drawSaucerHatch(en) { // a landed saucer with its ramp down: the farm's dungeon entrance
    const c = this.ctx, x = en.x, y = en.y, pulse = 0.5 + 0.5 * Math.sin(G.time * 4);
    c.fillStyle = 'rgba(0,0,0,0.2)'; c.beginPath(); c.ellipse(x + 4, y + 18, 56, 20, 0, 0, TAU); c.fill();
    { // the boarding ramp: a long striped gangway down from the hatch, with chevrons walking up into the saucer, so the saucer reads as a door
      c.fillStyle = '#5a6070'; c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.moveTo(x - 34, y + 40); c.lineTo(x + 34, y + 40); c.lineTo(x + 12, y - 4); c.lineTo(x - 12, y - 4); c.closePath(); c.fill(); c.stroke();
      c.save(); c.beginPath(); c.moveTo(x - 34, y + 40); c.lineTo(x + 34, y + 40); c.lineTo(x + 12, y - 4); c.lineTo(x - 12, y - 4); c.closePath(); c.clip();
      c.fillStyle = 'rgba(255,209,102,0.55)'; for (let k = 0; k < 4; k++) { const yy = y + 2 + k * 11; c.fillRect(x - 40, yy, 80, 4); } // hazard stripes
      if (!en.used) { c.strokeStyle = `rgba(155,240,106,${0.5 + 0.4 * pulse})`; c.lineWidth = 3; c.lineCap = 'round'; for (let k = 0; k < 3; k++) { const ph = (G.time * 0.8 + k / 3) % 1, yy = y + 36 - ph * 36, w = 6 + (1 - ph) * 10; c.beginPath(); c.moveTo(x - w, yy + 6); c.lineTo(x, yy); c.lineTo(x + w, yy + 6); c.stroke(); } } // chevrons walking up the ramp
      c.restore();
      for (const sx of [-1, 1]) for (let k = 0; k < 3; k++) { const t = k / 2, lx = x + sx * (12 + 22 * t), ly = y - 4 + 44 * t; c.fillStyle = Math.floor(G.time * 6 + k) % 2 ? '#ff5cf0' : '#fff'; c.beginPath(); c.arc(lx, ly, 2.5, 0, TAU); c.fill(); } // runway lights down both edges
    }
    c.fillStyle = '#c9ced6'; c.beginPath(); c.ellipse(x, y - 10, 54, 18, 0, 0, TAU); c.fill(); c.stroke();
    c.fillStyle = '#9ba3b0'; c.beginPath(); c.ellipse(x, y - 24, 26, 16, 0, Math.PI, 0); c.fill(); c.stroke(); // the dome
    for (let i = -2; i <= 2; i++) { const on = Math.floor(G.time * 4 + i) % 3 === 0; c.fillStyle = on ? '#ff5cf0' : '#7df9ff'; c.beginPath(); c.arc(x + i * 22, y - 6, 4, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.stroke(); }
    c.fillStyle = en.used ? '#2a2a30' : `rgba(155,240,106,${0.5 + 0.4 * pulse})`; c.fillRect(x - 10, y - 4, 20, 8); // the open hatch
    if (!en.used) { c.fillStyle = `rgba(155,240,106,${0.12 + 0.1 * pulse})`; c.beginPath(); c.moveTo(x - 12, y - 4); c.lineTo(x + 12, y - 4); c.lineTo(x + 24, y + 22); c.lineTo(x - 24, y + 22); c.closePath(); c.fill(); this.text('SAUCER DUNGEON', x, y - 50, 14, '#9bf06a', 'center'); }
    if (en.beam > 0) { // the tractor beam: a green cone from the dome to the centipede, rings sliding up it
      const P = Player, k = Math.min(1, en.beam / 0.3), a = Math.atan2(P.y - (y - 24), P.x - x), d = U.dist(x, y - 24, P.x, P.y), px = -Math.sin(a), py = Math.cos(a);
      c.save(); c.globalAlpha = k;
      c.fillStyle = `rgba(155,240,106,${0.18 + 0.1 * Math.sin(G.time * 16)})`; c.beginPath(); c.moveTo(x + px * 8, y - 24 + py * 8); c.lineTo(x - px * 8, y - 24 - py * 8); c.lineTo(P.x - px * 34, P.y - py * 34); c.lineTo(P.x + px * 34, P.y + py * 34); c.closePath(); c.fill();
      c.strokeStyle = 'rgba(200,255,170,0.8)'; c.lineWidth = 3; for (let i = 0; i < 5; i++) { const ph = (1 - ((G.time * 1.2 + i / 5) % 1)), bx = x + Math.cos(a) * d * ph, by = y - 24 + Math.sin(a) * d * ph; c.beginPath(); c.ellipse(bx, by, 6 + 28 * ph, 3 + 10 * ph, a, 0, TAU); c.stroke(); }
      c.restore(); this.text('TRACTOR BEAM', x, y - 68, 14, '#fff', 'center');
    }
  },

  drawTractor(x, y, a, s) { // a red farm tractor seen from above, facing angle a
    const c = this.ctx; c.save(); c.translate(x, y); c.rotate(a); c.scale(s, s);
    c.fillStyle = '#2a2a2a'; c.strokeStyle = this.OUT; c.lineWidth = 2.5; for (const [wx, wy, ww, wh] of [[-16, -22, 14, 10], [-16, 12, 14, 10], [10, -17, 9, 7], [10, 10, 9, 7]]) { this.roundRect(wx, wy, ww, wh, 3); c.fill(); c.stroke(); } // wheels: big at the back
    c.fillStyle = '#c93a2a'; this.roundRect(-20, -11, 42, 22, 5); c.fill(); c.stroke(); c.fillStyle = '#ffd166'; this.roundRect(-14, -8, 14, 16, 3); c.fill(); c.stroke(); // body and seat
    c.fillStyle = '#8a2a1a'; this.roundRect(4, -7, 16, 14, 3); c.fill(); c.stroke(); c.fillStyle = '#555'; c.fillRect(8, -12, 4, 8); c.strokeRect(8, -12, 4, 8); // the bonnet and the exhaust stack
    c.fillStyle = '#ffb86b'; c.beginPath(); c.arc(-7, 0, 6, 0, TAU); c.fill(); c.stroke(); // the driver (you)
    c.restore();
  },
});
