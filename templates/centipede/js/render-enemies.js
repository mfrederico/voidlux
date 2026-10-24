// Ordinary enemies: snakes, the bug family, parasites (sheep on the farm), ghosts, stones and rocks, plus the shared overlays (status, hp bar, rare halo, charm).
// frame() in render.js picks the drawer by e.kind / e.type. Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  drawMimeWall(e) { // only ever a hint: a faint shimmer, stronger where things just hit it
    const c = this.ctx, W = e.mimeWall, k = 0.12 + 0.08 * Math.sin(G.time * 6 + (e.seed || 0));
    c.save(); c.translate(W.x, W.y); c.rotate(W.a);
    c.fillStyle = `rgba(255,255,255,${k})`; c.fillRect(-W.len / 2, -5, W.len, 10);
    c.strokeStyle = `rgba(255,255,255,${k + 0.2})`; c.lineWidth = 1.5; c.setLineDash([3, 9]); c.lineDashOffset = -G.time * 20; c.beginPath(); c.moveTo(-W.len / 2, 0); c.lineTo(W.len / 2, 0); c.stroke(); c.setLineDash([]);
    c.restore();
  },

  drawCharm(e) {
    const c = this.ctx, r = e.r + 8, k = U.clamp(e.ally / (e.charmed || CFG.GRAVE.charm), 0, 1);
    c.fillStyle = `rgba(223,230,255,${0.18 + 0.1 * Math.sin(G.time * 8)})`; c.beginPath(); c.arc(e.x, e.y, r + 6, 0, TAU); c.fill();
    c.strokeStyle = '#dfe6ff'; c.lineWidth = 2.5; c.beginPath(); c.arc(e.x, e.y, r, -Math.PI / 2, -Math.PI / 2 + TAU * k); c.stroke();
    if (e.ally < 1.5 && Math.floor(G.time * 10) % 2 === 0) { c.strokeStyle = '#fff'; c.beginPath(); c.arc(e.x, e.y, r + 4, 0, TAU); c.stroke(); } // about to burst
  },

  drawRock(e) {
    const c = this.ctx, r = e.r;
    c.fillStyle = 'rgba(0,0,0,0.18)'; c.beginPath(); c.ellipse(e.x + 3, e.y + 6, r, r * 0.6, 0, 0, TAU); c.fill();
    if (e.farm) { // the hay bale was hiding a little tractor: it drives at its target, bouncing on its wheels, smoking
      const bounce = Math.sin(e.roll * 3) * 1.5, blink = e.wake > 0 && Math.floor(G.time * 10) % 2 === 0;
      c.save(); if (blink) c.globalAlpha = 0.6; this.drawTractor(e.x, e.y + bounce, e.angle, r / 24); c.restore();
      if (U.chance(0.4)) Combat.particles.push(Combat.mk(e.x - Math.cos(e.angle) * r * 0.6, e.y - Math.sin(e.angle) * r * 0.6 - 10, U.rand(-10, 10), U.rand(-50, -25), 0.6, '#555', 3));
      if (e.target && !e.target.dead) { c.save(); c.setLineDash([4, 8]); c.strokeStyle = 'rgba(255,209,102,0.5)'; c.lineWidth = 2; c.beginPath(); c.moveTo(e.x, e.y); c.lineTo(e.target.x, e.target.y); c.stroke(); c.restore(); }
      return;
    }
    if (e.circus) { // a woken beach ball: the same ball, rolling, with the angry face
      c.save(); c.translate(e.x, e.y); c.rotate(e.roll); const cols = ['#ff5c8a', '#ffd166', '#7df9ff', '#fff', '#9bf06a', '#ff8a3d']; for (let i = 0; i < 6; i++) { c.fillStyle = e.flash > 0 ? '#fff' : cols[i]; c.beginPath(); c.moveTo(0, 0); c.arc(0, 0, r, i * TAU / 6, (i + 1) * TAU / 6); c.closePath(); c.fill(); } c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.arc(0, 0, r, 0, TAU); c.stroke(); c.restore(); this.shine(e.x, e.y, r);
      this.eyes(e.x, e.y, e.angle, r, { spread: r * 0.45, fwd: r * 0.15, er: r * 0.3 }); this.mouth(e.x, e.y, e.angle, r, false);
      if (e.target && !e.target.dead) { c.save(); c.setLineDash([4, 8]); c.strokeStyle = 'rgba(255,209,102,0.5)'; c.lineWidth = 2; c.beginPath(); c.moveTo(e.x, e.y); c.lineTo(e.target.x, e.target.y); c.stroke(); c.restore(); }
      return;
    }
    c.save(); c.translate(e.x, e.y); c.rotate(e.roll);
    this.circle(0, 0, r, e.flash > 0 ? '#fff' : '#a9a2b3', this.OUT, 3);
    c.strokeStyle = '#6f6880'; c.lineWidth = 2; c.lineCap = 'round';
    c.beginPath(); c.moveTo(-r * 0.1, -r * 0.2); c.lineTo(r * 0.25, r * 0.15); c.lineTo(r * 0.1, r * 0.55); c.stroke();
    c.fillStyle = '#7fbf6a'; c.beginPath(); c.ellipse(-r * 0.35, -r * 0.45, r * 0.3, r * 0.16, -0.5, 0, TAU); c.fill();
    c.restore();
    this.shine(e.x, e.y, r);
    const a = e.angle, blink = e.wake > 0 && Math.floor(G.time * 10) % 2 === 0;
    if (!blink) { this.eyes(e.x, e.y, a, r, { spread: r * 0.45, fwd: r * 0.15, er: r * 0.3 }); // angry brows
      c.strokeStyle = this.OUT; c.lineWidth = 3; for (const s of [-1, 1]) { const bx = e.x + Math.cos(a) * r * 0.15 - Math.sin(a) * s * r * 0.45, by = e.y + Math.sin(a) * r * 0.15 + Math.cos(a) * s * r * 0.45; c.beginPath(); c.moveTo(bx - Math.sin(a) * s * r * 0.25 - Math.cos(a) * r * 0.05, by + Math.cos(a) * s * r * 0.25 - Math.sin(a) * r * 0.05 - r * 0.32); c.lineTo(bx + Math.sin(a) * s * r * 0.05 + Math.cos(a) * r * 0.1, by - Math.cos(a) * s * r * 0.05 + Math.sin(a) * r * 0.1 - r * 0.22); c.stroke(); } }
    this.mouth(e.x, e.y, a, r, false);
    if (e.target && !e.target.dead) { c.save(); c.setLineDash([4, 8]); c.strokeStyle = 'rgba(255,209,102,0.5)'; c.lineWidth = 2; c.beginPath(); c.moveTo(e.x, e.y); c.lineTo(e.target.x, e.target.y); c.stroke(); c.restore(); }
  },

  statusOverlay(e, x, y, r) {
    const c = this.ctx;
    if (e.st.freeze) { // frozen solid: a faceted block of ice grows over it, glints, hangs icicles, and cracks and shakes as it thaws
      const F = e.st.freeze, grow = Math.min(1, ((F.t0 || F.t) - F.t) / 0.15 + 0.35), thaw = F.t < 0.5, sx = thaw ? Math.sin(G.time * 70 + (e.seed || 0)) * 1.5 : 0, cx = x + sx, R = (r + 7) * grow, sd = (e.seed || 1) * 7;
      const pts = []; for (let i = 0; i < 8; i++) { const a = i * TAU / 8 + 0.25, rr = R * (0.9 + 0.16 * Math.sin(sd + i * 2.3)); pts.push([cx + Math.cos(a) * rr, y + Math.sin(a) * rr * 0.95]); }
      c.save(); const g = c.createLinearGradient(cx - R, y - R, cx + R, y + R); g.addColorStop(0, 'rgba(225,246,255,0.7)'); g.addColorStop(0.5, 'rgba(150,215,255,0.5)'); g.addColorStop(1, 'rgba(110,180,240,0.6)');
      c.fillStyle = g; c.beginPath(); pts.forEach(([px, py], i) => i ? c.lineTo(px, py) : c.moveTo(px, py)); c.closePath(); c.fill(); c.strokeStyle = '#f4fbff'; c.lineWidth = 2; c.stroke();
      c.strokeStyle = 'rgba(255,255,255,0.45)'; c.lineWidth = 1; c.beginPath(); for (const k of [0, 3, 5]) { c.moveTo(cx - R * 0.15, y - R * 0.1); c.lineTo(pts[k][0], pts[k][1]); } c.stroke(); // facets
      c.strokeStyle = 'rgba(255,255,255,0.85)'; c.lineWidth = 2.5; c.lineCap = 'round'; c.beginPath(); c.moveTo(cx - R * 0.55, y - R * 0.2); c.lineTo(cx - R * 0.25, y - R * 0.55); c.stroke(); // a shine
      c.fillStyle = 'rgba(220,244,255,0.85)'; for (const dx of [-0.45, 0, 0.4]) { const ix = cx + dx * R, iy = y + R * 0.82, len = R * (0.35 + 0.15 * Math.sin(sd + dx * 9)); c.beginPath(); c.moveTo(ix - 3, iy); c.lineTo(ix + 3, iy); c.lineTo(ix, iy + len); c.closePath(); c.fill(); } // icicles
      for (let q = 0; q < 2; q++) { const tw = 0.5 + 0.5 * Math.sin(G.time * 6 + q * 2.5 + sd), gx = cx + R * (q ? 0.45 : -0.2), gy = y + R * (q ? -0.4 : 0.35), z = 2 + 3 * tw; c.strokeStyle = `rgba(255,255,255,${tw})`; c.lineWidth = 1.5; c.beginPath(); c.moveTo(gx - z, gy); c.lineTo(gx + z, gy); c.moveTo(gx, gy - z); c.lineTo(gx, gy + z); c.stroke(); } // glints
      if (thaw) { c.strokeStyle = 'rgba(255,255,255,0.9)'; c.lineWidth = 1.5; c.beginPath(); for (let q = 0; q < 3; q++) { const a = sd + q * 2.1; let px = cx, py = y; c.moveTo(px, py); for (let s2 = 1; s2 <= 3; s2++) { px = cx + Math.cos(a + Math.sin(s2 * 3 + q) * 0.4) * R * s2 / 3; py = y + Math.sin(a + Math.sin(s2 * 3 + q) * 0.4) * R * s2 / 3; c.lineTo(px, py); } } c.stroke(); } // cracking
      c.restore();
    }
    if (e.st.dry) { // dried out: a pale, sandy, cracked crust
      c.save(); c.fillStyle = 'rgba(224,200,144,0.4)'; c.beginPath(); c.arc(x, y, r + 1, 0, TAU); c.fill(); c.strokeStyle = 'rgba(120,90,50,0.7)'; c.lineWidth = 1.2; c.beginPath(); const sd = (e.seed || 1) * 5; for (let q = 0; q < 4; q++) { const a = sd + q * 1.6; c.moveTo(x + Math.cos(a) * r * 0.2, y + Math.sin(a) * r * 0.2); c.lineTo(x + Math.cos(a + 0.3) * r * 0.6, y + Math.sin(a + 0.3) * r * 0.6); c.lineTo(x + Math.cos(a - 0.1) * r * 0.95, y + Math.sin(a - 0.1) * r * 0.95); } c.stroke(); c.restore();
    }
    if (e.st.poison) { c.fillStyle = 'rgba(120,220,80,0.35)'; c.beginPath(); c.arc(x, y, r + 2, 0, TAU); c.fill(); }
    if (e.st.berserk) { c.strokeStyle = '#ff7bff'; c.lineWidth = 2.5; c.beginPath(); c.arc(x, y, r + 7, G.time * 8, G.time * 8 + 4); c.stroke(); c.fillStyle = '#ff3a3a'; c.beginPath(); c.arc(x + Math.cos(G.time * 8) * (r + 7), y + Math.sin(G.time * 8) * (r + 7), 3, 0, TAU); c.fill(); }
    if (e.st.slow) { c.strokeStyle = 'rgba(207,238,255,0.8)'; c.lineWidth = 2; c.setLineDash([3, 4]); c.beginPath(); c.arc(x, y, r + 5, 0, TAU); c.stroke(); c.setLineDash([]); }
    if (e.st.blind) { c.fillStyle = 'rgba(255,255,255,0.35)'; c.beginPath(); c.arc(x, y, r + 4, 0, TAU); c.fill(); }
    if (e.st.giddy) { const k = 0.5 + 0.5 * Math.sin(G.time * 9 + (e.seed || 0) * 5); c.save(); c.fillStyle = `rgba(255,170,216,${0.25 + 0.2 * k})`; c.beginPath(); c.arc(x, y, r + 3 + 2 * k, 0, TAU); c.fill(); c.restore(); if (Math.floor(G.time * 2 + (e.seed || 0)) % 3 === 0) this.text('ha', x + Math.sin(G.time * 4 + (e.seed || 0)) * r, y - r - 10 - ((G.time * 25 + (e.seed || 0) * 13) % 16), 11, '#ffaad8', 'center'); } // giddy: a pink glow and a 'ha'
    if (e.st.hypno) { // spiral eyes: a rotating dashed ring and a drifting note
      c.strokeStyle = '#f3c6ff'; c.lineWidth = 2.5; c.setLineDash([5, 5]); c.lineDashOffset = -G.time * 40; c.beginPath(); c.arc(x, y, r + 8, 0, TAU); c.stroke(); c.setLineDash([]);
      const ph = (G.time * 0.8 + (e.seed || 0)) % 1; c.fillStyle = `rgba(243,198,255,${1 - ph})`; c.strokeStyle = this.OUT; c.lineWidth = 1.5; const nx = x + r + 6 + Math.sin(ph * 9) * 3, ny = y - r - ph * 24; c.beginPath(); c.ellipse(nx, ny + 4, 3.5, 2.5, -0.5, 0, TAU); c.fill(); c.stroke(); c.beginPath(); c.moveTo(nx + 3, ny + 3); c.lineTo(nx + 3, ny - 8); c.stroke();
    }
    if (e.st.spark) { c.strokeStyle = '#7df9ff'; c.lineWidth = 2; for (let i = 0; i < 3; i++) { const a = G.time * 20 + i * 2.1; c.beginPath(); c.moveTo(x + Math.cos(a) * (r + 2), y + Math.sin(a) * (r + 2)); c.lineTo(x + Math.cos(a + 0.5) * (r + 9), y + Math.sin(a + 0.5) * (r + 9)); c.stroke(); } }
  },

  hpBar(e, x, y, w) {
    if (e.hp >= e.maxhp) return;
    const c = this.ctx;
    c.fillStyle = 'rgba(0,0,0,0.5)'; this.roundRect(x - w / 2, y, w, 6, 3); c.fill();
    c.fillStyle = e.minion || e.ally > 0 ? '#9bf06a' : '#ff5c5c'; this.roundRect(x - w / 2, y, Math.max(2, w * U.clamp(e.hp / e.maxhp, 0, 1)), 6, 3); c.fill(); // red for an enemy, green once it fights for you (a minion, or charmed)
  },

  drawSnake(e) {
    const c = this.ctx;
    const col = e.flash > 0 ? '#fff' : e.color;
    if (!e.bone && !e.balloon) { this.drawSnakeSprites(e, col); return; }
    c.fillStyle = 'rgba(0,0,0,0.15)';
    for (const s of e.segPos) { c.beginPath(); c.ellipse(s.x + 2, s.y + 6, 8, 4, 0, 0, TAU); c.fill(); }
    for (let i = e.segPos.length - 1; i >= 0; i--) {
      const s = e.segPos[i];
      if (e.bone) { // a spine: vertebrae with little ribs sticking out sideways
        const q = i > 0 ? e.segPos[i - 1] : e, a = Math.atan2(q.y - s.y, q.x - s.x), px = -Math.sin(a), py = Math.cos(a);
        c.strokeStyle = this.OUT; c.lineWidth = 4; c.lineCap = 'round'; c.beginPath(); c.moveTo(s.x + px * 10, s.y + py * 10); c.lineTo(s.x - px * 10, s.y - py * 10); c.stroke();
        c.strokeStyle = '#cfcabd'; c.lineWidth = 2; c.stroke();
        this.circle(s.x, s.y, 6.5 - i * 0.15, i % 2 ? '#cfcabd' : col, this.OUT, 2.5);
        continue;
      }
      if (e.balloon) { const rr = 8.5 - i * 0.15; this.circle(s.x, s.y, rr, col, this.OUT, 2.5); this.shine(s.x, s.y, rr); if (i % 3 === 2) { c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.arc(s.x, s.y, rr * 0.55, 0, TAU); c.stroke(); } continue; } // a balloon animal: glossy, knotted every third segment
      this.circle(s.x, s.y, 8 - i * 0.2, i % 2 ? e.dark : col, this.OUT, 2.5);
      if (i % 2 === 0) this.shine(s.x, s.y, 8);
    }
    this.circle(e.x, e.y, e.r, col, this.OUT, 3); this.shine(e.x, e.y, e.r);
    if (e.bone) { c.strokeStyle = e.dark; c.lineWidth = 1.5; c.beginPath(); c.moveTo(e.x - 3, e.y - e.r * 0.8); c.lineTo(e.x + 1, e.y - e.r * 0.3); c.lineTo(e.x - 2, e.y + 1); c.stroke(); } // a crack in the skull
    if (e.balloon) { c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.moveTo(e.x - Math.cos(e.angle) * e.r, e.y - Math.sin(e.angle) * e.r); c.lineTo(e.x - Math.cos(e.angle) * (e.r + 6), e.y - Math.sin(e.angle) * (e.r + 6)); c.stroke(); } // the knot
    // angry brows + eyes + fangs
    this.eyes(e.x, e.y, e.angle, e.r, { spread: e.r * 0.5, fwd: e.r * 0.2, er: e.r * 0.3, pupil: e.bone ? '#ff3a3a' : World.dread(e.region) >= 0.4 ? '#ff2a2a' : undefined });
    const fx = Math.cos(e.angle), fy = Math.sin(e.angle), px = -Math.sin(e.angle), py = Math.cos(e.angle);
    c.strokeStyle = this.OUT; c.lineWidth = 2.5;
    for (const s of [-1, 1]) { c.beginPath(); c.moveTo(e.x + px * s * e.r * 0.8 + fx * e.r * 0.1, e.y + py * s * e.r * 0.8 + fy * e.r * 0.1); c.lineTo(e.x + px * s * e.r * 0.25 + fx * e.r * 0.5, e.y + py * s * e.r * 0.25 + fy * e.r * 0.5); c.stroke(); }
    c.fillStyle = '#fff';
    for (const s of [-1, 1]) { const bx = e.x + fx * e.r * 0.75 + px * s * 4, by = e.y + fy * e.r * 0.75 + py * s * 4; c.beginPath(); c.moveTo(bx - px * 2, by - py * 2); c.lineTo(bx + px * 2, by + py * 2); c.lineTo(bx + fx * 6, by + fy * 6); c.closePath(); c.fill(); }
    // tongue for spitters
    if (e.spit) { c.strokeStyle = '#ff5c8a'; c.lineWidth = 2; c.beginPath(); c.moveTo(e.x + fx * e.r, e.y + fy * e.r); c.lineTo(e.x + fx * (e.r + 8 + Math.sin(G.time * 12) * 3), e.y + fy * (e.r + 8)); c.stroke(); }
    this.statusOverlay(e, e.x, e.y, e.r);
    this.hpBar(e, e.x, e.y - e.r - 12, 34);
  },
  // the common snake from sprites: a shadow and a ball per segment (balls never turn, so their shine stays up-left), the head
  // ball, and the face (brows, eyes, fangs) turned to where it looks. Bone and balloon snakes are drawn live by drawSnake
  drawSnakeSprites(e, col) {
    const c = this.ctx, n = e.segPos.length, red = World.dread(e.region) >= 0.4;
    c.fillStyle = 'rgba(0,0,0,0.15)'; c.beginPath(); for (const s of e.segPos) { c.moveTo(s.x + 10, s.y + 6); c.ellipse(s.x + 2, s.y + 6, 8, 4, 0, 0, TAU); } c.fill(); // the shadows as one shape (where two overlap it is no darker, which nobody sees)
    for (let i = n - 1; i >= 0; i--) { const s = e.segPos[i], f = i % 2 ? e.dark : col; this.stamp(this.sprite('snakeseg,' + f + ',' + i, 10, () => { this.circle(0, 0, 8 - i * 0.2, f, this.OUT, 2.5); if (i % 2 === 0) this.shine(0, 0, 8); }), s.x, s.y, 0); }
    this.stamp(this.sprite('snakehead,' + col + ',' + e.r, e.r + 3, () => { this.circle(0, 0, e.r, col, this.OUT, 3); this.shine(0, 0, e.r); }), e.x, e.y, 0);
    this.stamp(this.sprite('snakeface,' + e.r + ',' + red, e.r + 8, () => this.snakeFace(e, 0, 0, 0, red ? '#ff2a2a' : undefined)), e.x, e.y, e.angle);
    if (e.spit) { const fx = Math.cos(e.angle), fy = Math.sin(e.angle); c.strokeStyle = '#ff5c8a'; c.lineWidth = 2; c.beginPath(); c.moveTo(e.x + fx * e.r, e.y + fy * e.r); c.lineTo(e.x + fx * (e.r + 8 + Math.sin(G.time * 12) * 3), e.y + fy * (e.r + 8)); c.stroke(); } // the tongue flickers: live
    this.statusOverlay(e, e.x, e.y, e.r);
    this.hpBar(e, e.x, e.y - e.r - 12, 34);
  },
  snakeFace(e, x, y, a, pupil) { // eyes, angry brows and two fangs, at (x, y) looking along a
    const c = this.ctx, r = e.r, fx = Math.cos(a), fy = Math.sin(a), px = -Math.sin(a), py = Math.cos(a);
    this.eyes(x, y, a, r, { spread: r * 0.5, fwd: r * 0.2, er: r * 0.3, pupil });
    c.strokeStyle = this.OUT; c.lineWidth = 2.5;
    for (const s of [-1, 1]) { c.beginPath(); c.moveTo(x + px * s * r * 0.8 + fx * r * 0.1, y + py * s * r * 0.8 + fy * r * 0.1); c.lineTo(x + px * s * r * 0.25 + fx * r * 0.5, y + py * s * r * 0.25 + fy * r * 0.5); c.stroke(); }
    c.fillStyle = '#fff';
    for (const s of [-1, 1]) { const bx = x + fx * r * 0.75 + px * s * 4, by = y + fy * r * 0.75 + py * s * 4; c.beginPath(); c.moveTo(bx - px * 2, by - py * 2); c.lineTo(bx + px * 2, by + py * 2); c.lineTo(bx + fx * 6, by + fy * 6); c.closePath(); c.fill(); }
  },
  // gold pulse under a rare or unique monster so it reads as special from across the screen

  drawHalo(e) {
    const c = this.ctx, col = e.rare ? e.rare.color : e.color, k = 0.5 + 0.5 * Math.sin(G.time * 5 + e.seed);
    c.save(); c.globalAlpha = 0.18 + 0.12 * k; c.fillStyle = col; c.beginPath(); c.arc(e.x, e.y, e.r + 14 + k * 4, 0, TAU); c.fill();
    c.globalAlpha = 0.6; c.strokeStyle = col; c.lineWidth = 2; c.setLineDash([6, 6]); c.lineDashOffset = -G.time * 30; c.beginPath(); c.arc(e.x, e.y, e.r + 12, 0, TAU); c.stroke(); c.setLineDash([]);
    c.restore();
  },

  drawParasite(e) { // purple spider: round body, red eyes, eight scuttling legs
    const c = this.ctx, col = e.flash > 0 ? '#fff' : e.color, wob = 1 + Math.sin(G.time * 7 + e.seed) * 0.1, x = e.x, y = e.y;
    if (e.minion) { // yours: a steady green glow and a collar; red and frantic while it dives
      c.fillStyle = e.kami ? `rgba(255,209,102,${0.3 + 0.2 * Math.sin(G.time * 25)})` : 'rgba(155,240,106,0.22)'; c.beginPath(); c.arc(x, y, e.kami ? 26 : 22, 0, TAU); c.fill();
      c.strokeStyle = e.kami ? '#ff5c5c' : '#9bf06a'; c.lineWidth = 2; c.beginPath(); c.arc(x, y, 17, 0, TAU); c.stroke();
    } else if (e.ally > 0) { c.fillStyle = `rgba(125,245,143,${0.25 + 0.15 * Math.sin(G.time * 10)})`; c.beginPath(); c.arc(x, y, 22, 0, TAU); c.fill(); if (e.ally < 1.5) { c.strokeStyle = '#fff'; c.lineWidth = 2; c.beginPath(); c.arc(x, y, 24 + Math.sin(G.time * 30) * 3, 0, TAU); c.stroke(); } }
    c.fillStyle = 'rgba(0,0,0,0.15)'; c.beginPath(); c.ellipse(x + 2, y + 10, 12, 5, 0, 0, TAU); c.fill();
    c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.lineCap = 'round';
    const fx = Math.cos(e.angle), fy = Math.sin(e.angle), px = -Math.sin(e.angle), py = Math.cos(e.angle);
    const Rg = World.regions[e.region];
    if (Rg && Rg.farm && !e.minion && !e.unique) { this.drawSheep(e, x, y, col, fx, fy, px, py); return; } // on the farm the parasites are sheep. Of course they are
    const bs = e.r > 14 ? e.r / 12 : 1; // the Broodmother is a big one
    // three sprites: the legs and the face turn with it, the body and its shine stay upright between them (a live drawing
    // put the dark spot under the shine; here it sits over it, which nobody can see on a spider this size)
    const lf = Math.floor(((e.legT || 0) % TAU) / TAU * 12), wq = Math.round((wob - 1) * 20) / 2, z = Math.round(bs * 4) / 4;
    this.stamp(this.sprite('pzlegs,' + lf, 18, () => this.parasiteLegs(0, 0, 0, (lf + 0.5) / 12 * TAU)), x, y, e.angle);
    this.stamp(this.sprite('pzbody,' + col + ',' + wq + ',' + z, 16 * bs + 3, () => this.parasiteBody(col, 0, 0, 1 + wq * 0.1, bs)), x, y, 0);
    this.stamp(this.sprite('pzface,' + e.dark + ',' + !!e.minion + ',' + Math.round(e.r), 13 * bs + 3, () => this.parasiteFace(e, 0, 0, 0, bs)), x, y, e.angle);
    this.statusOverlay(e, x, y, 12);
    this.hpBar(e, x, y - 24, 28);
  },
  parasiteLegs(x, y, a, leg) { // eight scuttling legs, round (x, y) facing a, at step leg of the walk
    const c = this.ctx, fx = Math.cos(a), fy = Math.sin(a), px = -Math.sin(a), py = Math.cos(a);
    c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.lineCap = 'round';
    for (let i = -1.5; i <= 1.5; i++) for (const s of [-1, 1]) {
      const k = Math.sin(leg + i * 1.3 + (s > 0 ? 0 : Math.PI)) * 4;
      const bx = x + fx * i * 5, by = y + fy * i * 5;
      c.beginPath(); c.moveTo(bx + px * s * 7, by + py * s * 7); c.lineTo(bx + px * s * 17 + fx * k, by + py * s * 17 + fy * k); c.stroke();
    }
  },
  parasiteBody(col, x, y, wob, bs) { const c = this.ctx; c.fillStyle = col; c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.ellipse(x, y, 13 * wob * bs, 11 / wob * bs, 0, 0, TAU); c.fill(); c.stroke(); this.shine(x, y, 11 * bs); }, // upright, squashing as it breathes
  parasiteFace(e, x, y, a, bs) { // the dark spot at its back, the eyes and (a minion's) smile
    const c = this.ctx; c.fillStyle = e.dark; c.beginPath(); c.arc(x - Math.cos(a) * 5 * bs, y - Math.sin(a) * 5 * bs, 4 * bs, 0, TAU); c.fill();
    this.eyes(x, y, a, e.r > 14 ? e.r : 12, { pupil: e.minion ? '#2a6f3a' : '#ff3a3a', spread: e.r > 14 ? e.r * 0.4 : 5, fwd: 3, er: e.r > 14 ? e.r * 0.3 : 4 });
    if (e.minion) this.mouth(x, y, a, 12, true);
  },
  // a farm parasite: a woolly sheep with a black face and little trotting legs, wearing its parasite colour as a collar.
  // Same hitbox, same sprint, same bite; it just looks like it wandered out of a field with bad intentions

  drawSheep(e, x, y, col, fx, fy, px, py) {
    const c = this.ctx, bs = e.r > 14 ? e.r / 12 : 1, run = e.huntT > 1.5;
    c.strokeStyle = this.OUT; c.lineWidth = 3; c.lineCap = 'round';
    for (let i = -1; i <= 1; i += 2) for (const s of [-1, 1]) { const k = Math.sin(e.legT * 0.8 + i * 1.6 + (s > 0 ? 0 : Math.PI)) * 5; c.beginPath(); c.moveTo(x + fx * i * 6 * bs + px * s * 5 * bs, y + fy * i * 6 * bs + py * s * 5 * bs); c.lineTo(x + fx * (i * 6 + k) * bs + px * s * 9 * bs, y + fy * (i * 6 + k) * bs + py * s * 9 * bs + 6); c.stroke(); } // four trotting legs
    c.fillStyle = e.flash > 0 ? '#fff' : '#f3efe6'; c.strokeStyle = this.OUT; c.lineWidth = 3;
    for (let i = 0; i < 7; i++) { const a = i * TAU / 7 + e.seed; c.beginPath(); c.arc(x + Math.cos(a) * 7 * bs, y + Math.sin(a) * 5.5 * bs, 7 * bs, 0, TAU); c.fill(); } // the wool, as a ring of puffs
    c.beginPath(); c.ellipse(x, y, 13 * bs, 11 * bs, 0, 0, TAU); c.fill(); c.strokeStyle = 'rgba(0,0,0,0)'; c.stroke();
    c.strokeStyle = this.OUT; c.lineWidth = 2.5; for (let i = 0; i < 7; i++) { const a = i * TAU / 7 + e.seed; c.beginPath(); c.arc(x + Math.cos(a) * 7 * bs, y + Math.sin(a) * 5.5 * bs, 7 * bs, a - 1.2, a + 1.2); c.stroke(); } // the outline only round the outside
    c.strokeStyle = col; c.lineWidth = 3; c.beginPath(); c.ellipse(x - fx * 4 * bs, y - fy * 4 * bs, 9 * bs, 7 * bs, e.angle, 0, TAU); c.stroke(); // the collar in the parasite's colour (Leech purple, Slug violet, Jammer indigo)
    const hx = x + fx * 11 * bs, hy = y + fy * 11 * bs - 2;
    this.circle(hx, hy, 6.5 * bs, '#2b1d3a', this.OUT, 2); // the black face
    for (const s of [-1, 1]) { c.fillStyle = '#2b1d3a'; c.beginPath(); c.ellipse(hx - fx * 3 * bs + px * s * 7 * bs, hy - fy * 3 * bs + py * s * 7 * bs, 4 * bs, 2.2 * bs, e.angle + s * 0.5, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.stroke(); } // ears
    this.eyes(hx, hy, e.angle, 6.5 * bs, { pupil: run ? '#ff3a3a' : '#ffd166', spread: 3 * bs, fwd: 1.5, er: 2.4 * bs });
    if (run && Math.floor(G.time * 4 + e.seed) % 3 === 0) this.text('baa', hx + 10, hy - 12, 10, '#fff', 'center');
    this.statusOverlay(e, x, y, 12 * bs);
    this.hpBar(e, x, y - 24, 28);
  },

  drawStone(e) {
    const c = this.ctx, r = e.r;
    c.fillStyle = 'rgba(0,0,0,0.2)'; c.beginPath(); c.ellipse(e.x + 3, e.y + 7, r, r * 0.55, 0, 0, TAU); c.fill();
    c.save(); c.translate(e.x, e.y); c.rotate(e.spin);
    c.fillStyle = e.flash > 0 ? '#fff' : e.color; c.strokeStyle = this.OUT; c.lineWidth = 3;
    c.beginPath(); for (let i = 0; i < 7; i++) { const a = i * TAU / 7, rr = r * (0.85 + 0.15 * Math.sin(e.seed + i * 2)); c.lineTo(Math.cos(a) * rr, Math.sin(a) * rr); } c.closePath(); c.fill(); c.stroke();
    c.strokeStyle = e.dark; c.lineWidth = 2; c.beginPath(); c.moveTo(-r * 0.3, -r * 0.2); c.lineTo(r * 0.1, r * 0.1); c.lineTo(-r * 0.1, r * 0.5); c.stroke();
    c.restore();
    this.shine(e.x, e.y, r);
    this.statusOverlay(e, e.x, e.y, r);
    this.hpBar(e, e.x, e.y - r - 10, 30);
  },

  drawMound(e) {
    const c = this.ctx, B = BUGS[e.type], k = 1 - U.clamp(e.emerge / (B.boss ? 2 : 1), 0, 1), r = e.r + 6, bone = e.type === 'skull';
    if (e.type === 'brainling' || e.type === 'saucer' || e.type === 'mooncow') { // the invaders beam down instead of burrowing up
      c.fillStyle = `rgba(155,240,106,${0.18 + 0.1 * Math.sin(G.time * 12)})`; c.beginPath(); c.moveTo(e.x - 6, e.y - 400); c.lineTo(e.x + 6, e.y - 400); c.lineTo(e.x + r * 1.4, e.y + 6); c.lineTo(e.x - r * 1.4, e.y + 6); c.closePath(); c.fill();
      c.fillStyle = 'rgba(155,240,106,0.3)'; c.beginPath(); c.ellipse(e.x, e.y + 6, r * 1.4, r * 0.5, 0, 0, TAU); c.fill();
      if (k > 0.3) { c.globalAlpha = k; c.fillStyle = e.color; c.beginPath(); c.arc(e.x, e.y - (1 - k) * 60, r * 0.6, 0, TAU); c.fill(); c.globalAlpha = 1; }
      return;
    }
    c.fillStyle = bone ? '#8a867c' : '#8a5a3a'; c.strokeStyle = this.OUT; c.lineWidth = 2.5;
    c.beginPath(); c.ellipse(e.x, e.y + 4, r * (0.6 + 0.5 * k), r * (0.35 + 0.3 * k), 0, 0, TAU); c.fill(); c.stroke();
    c.fillStyle = bone ? '#b5b0a5' : '#a9754a'; c.beginPath(); c.ellipse(e.x - r * 0.2, e.y, r * 0.3 * (0.5 + k), r * 0.18 * (0.5 + k), 0, 0, TAU); c.fill();
    if (k > 0.5) { const sh = (G.time * 30 % 2) - 1; c.fillStyle = bone ? '#ff8a3d' : e.color; c.beginPath(); c.arc(e.x + sh, e.y - 2, r * 0.25 * k, 0, TAU); c.fill(); }
    if (U.chance(0.3)) Combat.particles.push(Combat.mk(e.x + U.rand(-r, r) * 0.6, e.y + 2, U.rand(-20, 20), U.rand(-50, -20), 0.4, bone ? (U.chance(0.5) ? '#ff8a3d' : '#8a867c') : '#8a5a3a', 2));
  },
  // a flaming skull: a cranium with fire in the sockets and a tail of flame streaming behind it

  drawSkullBug(e) {
    const c = this.ctx, B = BUGS[e.type], r = e.r, col = e.flash > 0 ? '#fff' : e.color, bob = Math.sin(G.time * 9 + e.seed) * 3, y = e.y + bob;
    const fx = Math.cos(e.angle), fy = Math.sin(e.angle);
    c.fillStyle = 'rgba(0,0,0,0.15)'; c.beginPath(); c.ellipse(e.x + 3, e.y + 14, r, r * 0.5, 0, 0, TAU); c.fill();
    if (e.windup > 0) { const k = 1 - e.windup / 0.45; c.strokeStyle = `rgba(255,80,80,${0.4 + 0.5 * k})`; c.lineWidth = 3; c.beginPath(); c.arc(e.x, y, r + 6 + (1 - k) * 14, 0, TAU); c.stroke(); }
    for (let i = 0; i < 5; i++) { const ph = (G.time * 3 + i * 0.2 + e.seed) % 1, fr = r * (1.0 - ph * 0.75); c.fillStyle = `rgba(${i % 2 ? '255,209,102' : '255,120,40'},${0.65 * (1 - ph)})`; c.beginPath(); c.arc(e.x - fx * (r * 0.5 + ph * r * 2) + Math.sin(G.time * 20 + i) * 3, y - fy * (r * 0.5 + ph * r * 2) - ph * 12, fr, 0, TAU); c.fill(); }
    c.fillStyle = col; c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.arc(e.x, y - r * 0.15, r, 0, TAU); c.fill(); c.stroke(); // cranium
    this.roundRect(e.x - r * 0.55, y + r * 0.45, r * 1.1, r * 0.5, 3); c.fill(); c.stroke(); // jaw
    c.strokeStyle = this.OUT; c.lineWidth = 1.5; for (let i = -1; i <= 1; i++) { c.beginPath(); c.moveTo(e.x + i * r * 0.3, y + r * 0.45); c.lineTo(e.x + i * r * 0.3, y + r * 0.9); c.stroke(); }
    for (const s of [-1, 1]) { c.fillStyle = this.OUT; c.beginPath(); c.ellipse(e.x + s * r * 0.4, y - r * 0.2, r * 0.3, r * 0.34, 0, 0, TAU); c.fill(); c.fillStyle = `rgb(255,${140 + 60 * Math.sin(G.time * 15 + s)},40)`; c.beginPath(); c.arc(e.x + s * r * 0.4 + fx * 2, y - r * 0.2 + fy * 2, r * 0.15, 0, TAU); c.fill(); }
    c.fillStyle = this.OUT; c.beginPath(); c.moveTo(e.x, y + r * 0.05); c.lineTo(e.x - r * 0.12, y + r * 0.32); c.lineTo(e.x + r * 0.12, y + r * 0.32); c.closePath(); c.fill(); // nose
    this.statusOverlay(e, e.x, y, r);
    this.hpBar(e, e.x, y - r - 12, 30);
  },

  drawBug(e) {
    if (e.type === 'kamighost') { // a grave ghost: pale, a wispy trail behind it, an angry red glow in the eyes
      const c = this.ctx, k = 1 - U.clamp((e.emerge || 0) / 0.7, 0, 1), y = e.y + Math.sin(G.time * 9 + e.seed) * 3 - (1 - k) * 16;
      c.save(); c.globalAlpha = 0.35 * k; for (let i = 1; i <= 3; i++) { this.ctx.fillStyle = '#dfe6ff'; c.beginPath(); c.arc(e.x - Math.cos(e.angle) * i * 9, y - Math.sin(e.angle) * i * 9, e.r * (1 - i * 0.22), 0, TAU); c.fill(); } c.restore();
      c.save(); c.globalAlpha = 0.25 + 0.75 * k; this.ghostIcon(e.x, y, e.r, e.flash > 0 ? '#fff' : '#dfe6ff'); c.restore();
      c.fillStyle = 'rgba(255,60,60,0.55)'; c.beginPath(); c.arc(e.x, y - 2, e.r * 0.55, 0, TAU); c.fill();
      return;
    }
    const c = this.ctx, B = BUGS[e.type], r = e.r, col = e.flash > 0 ? '#fff' : e.color;
    const bob = B.flying ? Math.sin(G.time * 9 + e.seed) * 3 : 0, y = e.y + bob;
    // shadow
    c.fillStyle = 'rgba(0,0,0,0.15)'; c.beginPath(); c.ellipse(e.x + 3, e.y + (B.flying ? 14 : 6), r, r * 0.5, 0, 0, TAU); c.fill();
    // wind-up telegraph
    if (e.windup > 0) { const k = 1 - e.windup / (B.boss ? 0.7 : B.tongue ? 0.55 : 0.45); c.strokeStyle = `rgba(255,80,80,${0.4 + 0.5 * k})`; c.lineWidth = 3; c.beginPath(); c.arc(e.x, y, r + 6 + (1 - k) * 14, 0, TAU); c.stroke(); if (B.tongue) { c.setLineDash([6, 8]); c.strokeStyle = `rgba(255,107,157,${0.3 + 0.4 * k})`; c.beginPath(); c.moveTo(e.x, y); c.lineTo(e.x + Math.cos(Math.atan2(Player.y - y, Player.x - e.x)) * B.range, y + Math.sin(Math.atan2(Player.y - y, Player.x - e.x)) * B.range); c.stroke(); c.setLineDash([]); } } // an iguana shows where the tongue will go
    if (B.tongue && !e.tongue && e.windup <= 0) { c.strokeStyle = 'rgba(255,107,157,0.18)'; c.lineWidth = 1.5; c.setLineDash([4, 10]); c.beginPath(); c.arc(e.x, y, B.range, 0, TAU); c.stroke(); c.setLineDash([]); } // and its reach
    const t = G.time, flap = B.flying ? 0.5 + Math.abs(Math.sin(t * 40 + e.seed)) * 0.6 : 1;
    if (this.SPRITE_BUGS[e.type] && !e.tongue) { // the common bugs come from the sprite cache: a frame of the walk (or the wingbeat, and the roach's antennae)
      const lf = B.flying ? 0 : Math.floor(((e.legT || 0) % TAU) / TAU * 12), ff = B.flying ? Math.round((flap - 0.5) / 0.12) : 0, wf = e.type === 'roach' ? Math.floor((t * 9 % TAU) / TAU * 8) : 0;
      const S = this.sprite('bug,' + e.type + ',' + (e.flash > 0 ? '#fff' : e.color) + ',' + e.dark + ',' + Math.round(r) + ',' + lf + ',' + ff + ',' + wf + ',' + (World.dread(e.region) >= 0.4) + ',' + (e.mode === 'lunge'), r * 2.6 + 6,
        () => this.bugBody(e, 0, 0, 0, (lf + 0.5) / 12 * TAU, 0.5 + ff * 0.12, (wf + 0.5) / 8 * TAU));
      this.stamp(S, e.x, y, e.angle);
    } else this.bugBody(e, e.x, y, e.angle, e.legT, flap, t * 9);
    this.statusOverlay(e, e.x, y, r);
    this.hpBar(e, e.x, y - r - 12, Math.max(26, r * 2.4));
  },
  // a bug's body at (x, y) facing a: legs or wings, the tongue, the shell, the crown, eyes and blush. leg is the walk cycle,
  // flap the wing spread, wig the antennae's wiggle; drawBug passes the live ones, or a sprite's frozen frame (Render.sprite)
  bugBody(e, x, y, a, leg, flap, wig) {
    const c = this.ctx, B = BUGS[e.type], r = e.r, col = e.flash > 0 ? '#fff' : e.color;
    const fx = Math.cos(a), fy = Math.sin(a), px = -Math.sin(a), py = Math.cos(a);
    // legs
    if (!B.flying) {
      c.strokeStyle = this.OUT; c.lineWidth = Math.max(2, r * 0.18); c.lineCap = 'round';
      for (let i = -1; i <= 1; i++) for (const sgn of [-1, 1]) { const bx = x + fx * i * r * 0.55, by = y + fy * i * r * 0.55, k = Math.sin(leg + i) * r * 0.25; c.beginPath(); c.moveTo(bx + px * sgn * r * 0.6, by + py * sgn * r * 0.6); c.lineTo(bx + px * sgn * r * 1.4 + fx * k, by + py * sgn * r * 1.4 + fy * k); c.stroke(); }
    }
    // wings
    if (B.flying) {
      c.fillStyle = 'rgba(255,255,255,0.55)'; c.strokeStyle = 'rgba(255,255,255,0.8)'; c.lineWidth = 1.5;
      for (const sgn of [-1, 1]) { c.beginPath(); c.ellipse(x + px * sgn * r * 0.9 * flap - fx * r * 0.2, y + py * sgn * r * 0.9 * flap - fy * r * 0.2, r * 0.9, r * 0.45, a + sgn * 0.5, 0, TAU); c.fill(); c.stroke(); }
    }
    if (e.tongue) { // the iguana's tongue, out to its tip
      const T = e.tongue, tx = x + Math.cos(T.a) * T.len, ty = y + Math.sin(T.a) * T.len;
      c.strokeStyle = this.OUT; c.lineWidth = 8; c.lineCap = 'round'; c.beginPath(); c.moveTo(x + fx * r * 0.8, y + fy * r * 0.3); c.lineTo(tx, ty); c.stroke();
      c.strokeStyle = '#ff6b9d'; c.lineWidth = 4.5; c.stroke();
      this.circle(tx, ty, 6, '#ff6b9d', this.OUT, 2.5);
    }
    c.save(); c.translate(x, y); c.rotate(a);
    c.fillStyle = col; c.strokeStyle = this.OUT; c.lineWidth = r > 14 ? 3.5 : 3;
    if (e.type === 'brainling') { // a big wrinkled brain on a tiny body, black almond eyes, a ray gun
      c.restore(); c.save(); c.translate(x, y); // (drawn upright, not rotated: it is a little person)
      c.fillStyle = '#9fbf8a'; c.strokeStyle = this.OUT; c.lineWidth = 2.5; this.roundRect(-r * 0.45, r * 0.1, r * 0.9, r * 0.9, 4); c.fill(); c.stroke(); // the suit
      c.strokeStyle = this.OUT; c.lineWidth = 3; for (const sg of [-1, 1]) { c.beginPath(); c.moveTo(sg * r * 0.3, r); c.lineTo(sg * r * 0.45 + Math.sin(leg + sg) * 3, r * 1.5); c.stroke(); } // legs
      c.fillStyle = '#c9c9c9'; c.beginPath(); c.moveTo(r * 0.45, r * 0.4); c.lineTo(r * 1.1, r * 0.25); c.lineTo(r * 1.1, r * 0.5); c.lineTo(r * 0.6, r * 0.6); c.closePath(); c.fill(); c.stroke(); // the ray gun
      if (e.windup > 0) { c.fillStyle = '#ff5cf0'; c.beginPath(); c.arc(r * 1.15, r * 0.37, 3 + (1 - e.windup / 0.45) * 3, 0, TAU); c.fill(); }
      c.fillStyle = col; c.beginPath(); c.ellipse(0, -r * 0.35, r * 1.05, r * 0.9, 0, 0, TAU); c.fill(); c.stroke(); // the brain
      c.strokeStyle = e.dark; c.lineWidth = 1.5; for (let i = 0; i < 4; i++) { c.beginPath(); c.moveTo(-r * 0.8 + i * r * 0.45, -r * 0.6); c.quadraticCurveTo(-r * 0.6 + i * r * 0.45, -r * 1.05, -r * 0.4 + i * r * 0.45, -r * 0.5); c.stroke(); } c.beginPath(); c.moveTo(0, -r * 1.2); c.lineTo(0, -r * 0.1); c.stroke(); // wrinkles and the fissure
      c.fillStyle = this.OUT; for (const sg of [-1, 1]) { c.beginPath(); c.ellipse(sg * r * 0.4, r * 0.05, r * 0.26, r * 0.14, sg * 0.5, 0, TAU); c.fill(); } // the eyes
      c.restore(); c.save(); c.translate(x, y); c.rotate(a); // (back to the rotated frame the shared code expects)
    } else if (e.type === 'saucer') { // a classic disc with a dome and running lights
      c.fillStyle = '#9ba3b0'; c.beginPath(); c.ellipse(0, -r * 0.35, r * 0.6, r * 0.45, 0, Math.PI, 0); c.fill(); c.stroke();
      c.fillStyle = col; c.beginPath(); c.ellipse(0, 0, r * 1.4, r * 0.5, 0, 0, TAU); c.fill(); c.stroke();
      for (let i = -2; i <= 2; i++) { const on = (Math.floor(G.time * 6 + e.seed) + i) % 3 === 0; c.fillStyle = on ? '#ff5cf0' : '#7df9ff'; c.beginPath(); c.arc(i * r * 0.55, r * 0.15, r * 0.12, 0, TAU); c.fill(); }
      c.fillStyle = this.OUT; c.beginPath(); c.ellipse(r * 0.15, -r * 0.4, r * 0.2, r * 0.12, 0, 0, TAU); c.fill(); // a pilot's eye in the dome
    } else if (e.type === 'mooncow') { // a cow hanging in a tractor beam, legs dangling
      c.fillStyle = 'rgba(155,240,106,0.16)'; c.beginPath(); c.moveTo(-r * 0.3, -r * 6); c.lineTo(r * 0.3, -r * 6); c.lineTo(r * 1.6, r * 0.8); c.lineTo(-r * 1.6, r * 0.8); c.closePath(); c.fill();
      c.strokeStyle = this.OUT; c.lineWidth = 3; for (let i = -1; i <= 1; i += 2) for (const sg of [-1, 1]) { c.beginPath(); c.moveTo(i * r * 0.5, sg * r * 0.5); c.lineTo(i * r * 0.5 + Math.sin(G.time * 5 + i + sg) * 3, sg * r * 1.0); c.stroke(); } // legs
      c.fillStyle = col; c.beginPath(); c.ellipse(0, 0, r * 1.2, r * 0.75, 0, 0, TAU); c.fill(); c.stroke();
      c.fillStyle = this.OUT; for (const [px, py, pr] of [[-0.5, -0.2, 0.35], [0.3, 0.25, 0.3], [0.1, -0.4, 0.2]]) { c.beginPath(); c.ellipse(px * r, py * r, pr * r, pr * r * 0.7, 0.4, 0, TAU); c.fill(); } // patches
      c.fillStyle = '#ffb3c6'; c.beginPath(); c.ellipse(r * 0.95, r * 0.1, r * 0.45, r * 0.35, 0, 0, TAU); c.fill(); c.stroke(); c.fillStyle = this.OUT; c.beginPath(); c.arc(r * 1.05, r * 0.05, 2, 0, TAU); c.arc(r * 1.05, r * 0.2, 2, 0, TAU); c.fill(); // the muzzle
      c.strokeStyle = this.OUT; c.lineWidth = 2.5; for (const sg of [-1, 1]) { c.beginPath(); c.moveTo(r * 0.7, sg * r * 0.45); c.lineTo(r * 0.95, sg * r * 0.8); c.stroke(); } // horns
    } else if (e.type === 'unicycle') { // a clown wobbling on one wheel: drawn upright
      c.restore(); c.save(); c.translate(x, y); const wob = Math.sin(G.time * 9 + e.seed) * 0.15; c.rotate(wob);
      this.circle(0, r * 0.9, r * 0.55, '#2a2a2a', this.OUT, 2.5); c.strokeStyle = '#aaa'; c.lineWidth = 1.5; for (let i = 0; i < 3; i++) { const sa = leg * 0.4 + i * Math.PI / 3; c.beginPath(); c.moveTo(-Math.cos(sa) * r * 0.5, r * 0.9 - Math.sin(sa) * r * 0.5); c.lineTo(Math.cos(sa) * r * 0.5, r * 0.9 + Math.sin(sa) * r * 0.5); c.stroke(); } // the wheel
      c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.moveTo(0, r * 0.9); c.lineTo(0, -r * 0.1); c.stroke(); // the post
      c.fillStyle = col; c.strokeStyle = this.OUT; c.lineWidth = 2.5; this.roundRect(-r * 0.5, -r * 0.4, r, r * 0.7, 4); c.fill(); c.stroke(); c.fillStyle = '#ffd166'; for (const dy of [-r * 0.25, 0, r * 0.2]) { c.beginPath(); c.arc(0, dy, 2.2, 0, TAU); c.fill(); } // a spotty suit with pom-poms
      this.circle(0, -r * 0.8, r * 0.5, '#fff5e0', this.OUT, 2.5); c.fillStyle = '#ff5c8a'; for (const sg of [-1, 1]) { c.beginPath(); c.arc(sg * r * 0.5, -r * 0.95, r * 0.25, 0, TAU); c.fill(); c.stroke(); } this.circle(0, -r * 0.7, 2.8, '#ff3a3a', this.OUT, 1.5); this.eyes(0, -r * 0.9, -Math.PI / 2, r * 0.5, { spread: r * 0.2, fwd: 0, er: 2.2, pupil: '#ff2a2a' }); // the head: wig, nose, mad eyes
      c.restore(); c.save(); c.translate(x, y); c.rotate(a);
    } else if (e.type === 'juggler') { // a clown in stripes with three pins going round over its head
      c.restore(); c.save(); c.translate(x, y);
      c.fillStyle = col; c.strokeStyle = this.OUT; c.lineWidth = 2.5; this.roundRect(-r * 0.6, -r * 0.3, r * 1.2, r * 1.1, 5); c.fill(); c.stroke(); c.fillStyle = e.dark; for (let i = 0; i < 3; i++) c.fillRect(-r * 0.6, -r * 0.3 + i * r * 0.4, r * 1.2, r * 0.12);
      this.circle(0, -r * 0.7, r * 0.5, '#fff5e0', this.OUT, 2.5); c.fillStyle = '#9bf06a'; c.beginPath(); c.arc(0, -r * 1.05, r * 0.35, Math.PI, 0); c.fill(); c.stroke(); this.circle(0, -r * 0.6, 2.8, '#ff3a3a', this.OUT, 1.5); this.eyes(0, -r * 0.8, -Math.PI / 2, r * 0.5, { spread: r * 0.2, fwd: 0, er: 2.2 });
      for (let i = 0; i < 3; i++) { const ph = (G.time * 1.4 + i / 3) % 1, px = Math.sin(ph * TAU) * r * 0.9, py = -r * 1.5 - Math.sin(ph * Math.PI) * r * 1.2; c.save(); c.translate(px, py); c.rotate(ph * TAU * 2); c.fillStyle = '#ff8a3d'; c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.beginPath(); c.ellipse(0, 2, 3.5, 6, 0, 0, TAU); c.fill(); c.stroke(); c.fillRect(-1.2, -8, 2.4, 6); c.restore(); } // the pins
      c.restore(); c.save(); c.translate(x, y); c.rotate(a);
    } else if (e.type === 'mime') { // white face, black stripes, white gloves pressed flat against nothing
      c.restore(); c.save(); c.translate(x, y);
      c.fillStyle = '#2b1d3a'; c.strokeStyle = this.OUT; c.lineWidth = 2.5; this.roundRect(-r * 0.55, -r * 0.3, r * 1.1, r * 1.1, 5); c.fill(); c.stroke(); c.fillStyle = '#fff'; for (let i = 0; i < 3; i++) c.fillRect(-r * 0.55, -r * 0.2 + i * r * 0.35, r * 1.1, r * 0.14);
      const fx2 = Math.cos(a), fy2 = Math.sin(a), push = Math.sin((e.mimeT || 0) * 2) * 3; for (const sg of [-1, 1]) { const hx = fx2 * (r * 1.3 + push) - fy2 * sg * r * 0.7, hy = fy2 * (r * 1.3 + push) + fx2 * sg * r * 0.7; c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.moveTo(sg * -fy2 * r * 0.5, sg * fx2 * r * 0.5); c.lineTo(hx, hy); c.stroke(); this.circle(hx, hy, r * 0.28, '#fff', this.OUT, 2); } // the gloves on the wall
      this.circle(0, -r * 0.7, r * 0.5, '#fff', this.OUT, 2.5); c.fillStyle = '#2b1d3a'; c.beginPath(); c.arc(0, -r * 1.0, r * 0.45, Math.PI + 0.3, -0.3); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.beginPath(); c.moveTo(-r * 0.2, -r * 0.5); c.lineTo(r * 0.2, -r * 0.5); c.stroke(); for (const sg of [-1, 1]) { c.beginPath(); c.moveTo(sg * r * 0.2, -r * 0.95); c.lineTo(sg * r * 0.2, -r * 0.55); c.stroke(); } c.fillStyle = this.OUT; for (const sg of [-1, 1]) { c.beginPath(); c.arc(sg * r * 0.2, -r * 0.75, 1.8, 0, TAU); c.fill(); } // beret, painted tears, a thin line of a mouth
      c.restore(); c.save(); c.translate(x, y); c.rotate(a);
    } else if (e.type === 'pieclown') { // a chef-hatted clown with a pie in each hand
      c.restore(); c.save(); c.translate(x, y);
      c.fillStyle = col; c.strokeStyle = this.OUT; c.lineWidth = 2.5; this.roundRect(-r * 0.55, -r * 0.3, r * 1.1, r * 1.1, 5); c.fill(); c.stroke(); c.fillStyle = '#fff'; for (const [dx, dy] of [[-r * 0.25, 0], [r * 0.25, r * 0.4], [0, r * 0.2]]) { c.beginPath(); c.arc(dx, dy, 2.5, 0, TAU); c.fill(); }
      for (const sg of [-1, 1]) { const hx = sg * r * 0.95, hy = r * 0.1 + (e.windup > 0 ? -r * 0.5 * (1 - e.windup / 0.45) : 0); c.fillStyle = '#d9b06d'; c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.ellipse(hx, hy, r * 0.4, r * 0.16, 0, 0, TAU); c.fill(); c.stroke(); c.fillStyle = '#fff5e0'; c.beginPath(); c.ellipse(hx, hy - 3, r * 0.34, r * 0.16, 0, 0, TAU); c.fill(); c.stroke(); c.fillStyle = '#ff5c5c'; c.beginPath(); c.arc(hx, hy - 5, 2, 0, TAU); c.fill(); } // the pies
      this.circle(0, -r * 0.7, r * 0.5, '#fff5e0', this.OUT, 2.5); c.fillStyle = '#fff'; this.roundRect(-r * 0.4, -r * 1.5, r * 0.8, r * 0.5, 4); c.fill(); c.stroke(); this.circle(0, -r * 0.6, 2.8, '#ff3a3a', this.OUT, 1.5); this.eyes(0, -r * 0.8, -Math.PI / 2, r * 0.5, { spread: r * 0.2, fwd: 0, er: 2.2 }); // the chef's hat
      c.restore(); c.save(); c.translate(x, y); c.rotate(a);
    } else if (e.type === 'elephant') { // a pink elephant balancing on a circus ball
      c.restore(); c.save(); c.translate(x, y);
      const roll = e.roll || 0; c.save(); c.translate(0, r * 0.9); c.rotate(roll); for (let i = 0; i < 6; i++) { c.fillStyle = i % 2 ? '#ffd166' : '#7df9ff'; c.beginPath(); c.moveTo(0, 0); c.arc(0, 0, r * 0.7, i * TAU / 6, (i + 1) * TAU / 6); c.closePath(); c.fill(); } c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.beginPath(); c.arc(0, 0, r * 0.7, 0, TAU); c.stroke(); c.restore(); // the ball
      c.save(); c.rotate(Math.sin(G.time * 4 + e.seed) * 0.08); // the wobble
      c.strokeStyle = this.OUT; c.lineWidth = 3; for (const dx of [-r * 0.45, r * 0.45]) { c.beginPath(); c.moveTo(dx, 0); c.lineTo(dx, r * 0.35); c.stroke(); }
      c.fillStyle = col; c.strokeStyle = this.OUT; c.lineWidth = 3.5; c.beginPath(); c.ellipse(0, -r * 0.3, r * 1.0, r * 0.7, 0, 0, TAU); c.fill(); c.stroke(); // the body
      c.beginPath(); c.arc(r * 0.6, -r * 0.8, r * 0.5, 0, TAU); c.fill(); c.stroke(); // the head
      for (const sg of [-1, 1]) { c.fillStyle = '#ffb3d9'; c.beginPath(); c.ellipse(r * 0.45, -r * 0.8 + sg * r * 0.5, r * 0.3, r * 0.4, sg * 0.6, 0, TAU); c.fill(); c.stroke(); c.fillStyle = '#ff8ab8'; c.beginPath(); c.ellipse(r * 0.45, -r * 0.8 + sg * r * 0.5, r * 0.15, r * 0.22, sg * 0.6, 0, TAU); c.fill(); } // ears
      c.strokeStyle = this.OUT; c.lineWidth = 7; c.lineCap = 'round'; c.beginPath(); c.moveTo(r * 1.0, -r * 0.7); c.quadraticCurveTo(r * 1.5, -r * 0.3 + Math.sin(G.time * 3) * 4, r * 1.3 + Math.sin(G.time * 3) * 4, r * 0.3); c.stroke(); c.strokeStyle = col; c.lineWidth = 4; c.stroke(); // the trunk
      this.eyes(r * 0.65, -r * 0.9, 0, r * 0.5, { spread: r * 0.22, fwd: r * 0.1, er: r * 0.12, pupil: '#ff2a2a' });
      c.fillStyle = '#ffd166'; c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.moveTo(r * 0.3, -r * 1.25); c.lineTo(r * 0.6, -r * 1.7); c.lineTo(r * 0.9, -r * 1.25); c.closePath(); c.fill(); c.stroke(); // a party hat
      c.restore(); c.restore(); c.save(); c.translate(x, y); c.rotate(a);
    } else if (e.type === 'blob') { // a lava-lamp blob: a wobbling shape, translucent, slowly changing colour
      const hue = (e.seed * 40 + G.time * 40) % 360; c.fillStyle = `hsla(${hue},90%,65%,0.8)`; c.strokeStyle = this.OUT; c.lineWidth = 2.5;
      c.beginPath(); for (let i = 0; i < 12; i++) { const a = i * TAU / 12, rr = r * (0.85 + 0.2 * Math.sin(G.time * 3 + i * 1.7 + e.seed)); c.lineTo(Math.cos(a) * rr, Math.sin(a) * rr); } c.closePath(); c.fill(); c.stroke();
      c.fillStyle = `hsla(${(hue + 40) % 360},90%,80%,0.7)`; c.beginPath(); c.arc(-r * 0.2, -r * 0.2, r * 0.35, 0, TAU); c.fill();
    } else if (e.type === 'kamiclown') { // a little clown sprinting with a mallet over its head
      c.restore(); c.save(); c.translate(x, y);
      const bounce = Math.abs(Math.sin(leg * 0.8)) * 3; c.translate(0, -bounce);
      c.strokeStyle = this.OUT; c.lineWidth = 3; for (const sg of [-1, 1]) { c.beginPath(); c.moveTo(sg * r * 0.2, r * 0.5); c.lineTo(sg * r * 0.4 + Math.sin(leg + sg * 2) * 5, r * 1.1); c.stroke(); } // big shoes going
      c.fillStyle = col; c.strokeStyle = this.OUT; c.lineWidth = 2.5; this.roundRect(-r * 0.5, -r * 0.3, r, r * 0.9, 4); c.fill(); c.stroke(); c.fillStyle = '#ff5c8a'; for (const dy of [-r * 0.1, r * 0.3]) { c.beginPath(); c.arc(0, dy, 2.2, 0, TAU); c.fill(); }
      c.save(); c.rotate(a); c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.moveTo(r * 0.3, 0); c.lineTo(r * 1.5, 0); c.stroke(); c.fillStyle = '#ff5c5c'; c.strokeStyle = this.OUT; c.lineWidth = 2; this.roundRect(r * 1.3, -r * 0.45, r * 0.6, r * 0.9, 3); c.fill(); c.stroke(); c.restore(); // the mallet, held out toward you
      this.circle(0, -r * 0.7, r * 0.5, '#fff5e0', this.OUT, 2.5); c.fillStyle = '#7df9ff'; for (const sg of [-1, 1]) { c.beginPath(); c.arc(sg * r * 0.45, -r * 0.85, r * 0.25, 0, TAU); c.fill(); c.stroke(); } this.circle(0, -r * 0.6, 2.6, '#ff3a3a', this.OUT, 1.5); this.eyes(0, -r * 0.8, -Math.PI / 2, r * 0.5, { spread: r * 0.2, fwd: 0, er: 2, pupil: '#ff2a2a' }); c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.beginPath(); c.arc(0, -r * 0.55, r * 0.22, 0.2, Math.PI - 0.2); c.stroke(); // a huge grin
      c.restore(); c.save(); c.translate(x, y); c.rotate(a);
    } else if (e.type === 'chicken') { // a panicking chicken
      c.fillStyle = col; c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.beginPath(); c.ellipse(0, 0, r * 1.1, r * 0.8, 0, 0, TAU); c.fill(); c.stroke();
      c.strokeStyle = this.OUT; c.lineWidth = 2; for (const sg of [-1, 1]) { c.beginPath(); c.moveTo(-r * 0.2, sg * r * 0.4); c.lineTo(-r * 0.6, sg * r * (0.9 + Math.abs(Math.sin(leg)) * 0.4)); c.stroke(); } // wings flapping
      c.fillStyle = '#ffd166'; c.beginPath(); c.moveTo(r * 1.0, 0); c.lineTo(r * 1.5, -r * 0.1); c.lineTo(r * 1.0, r * 0.25); c.closePath(); c.fill(); c.stroke(); c.fillStyle = '#ff3a3a'; c.beginPath(); c.arc(r * 0.6, -r * 0.75, r * 0.2, 0, TAU); c.fill(); c.beginPath(); c.arc(r * 0.9, -r * 0.6, r * 0.18, 0, TAU); c.fill(); // beak and comb
    } else if (e.type === 'iguana') { // a fat lizard: long body, crest of spines down the back, a tail curling behind
      c.beginPath(); c.moveTo(-r * 1.3, 0); c.quadraticCurveTo(-r * 2.2, -r * 0.4 + Math.sin(G.time * 3 + e.seed) * r * 0.3, -r * 2.6, r * 0.3); c.strokeStyle = this.OUT; c.lineWidth = 7; c.stroke(); c.strokeStyle = col; c.lineWidth = 4; c.stroke(); c.strokeStyle = this.OUT; c.lineWidth = 3; // tail
      for (const sg of [-1, 1]) for (const lx of [-r * 0.5, r * 0.5]) { c.beginPath(); c.moveTo(lx, sg * r * 0.5); c.lineTo(lx - r * 0.2, sg * r * 1.0); c.lineTo(lx + r * 0.15, sg * r * 1.2); c.stroke(); } // legs
      c.fillStyle = col; c.beginPath(); c.ellipse(0, 0, r * 1.35, r * 0.8, 0, 0, TAU); c.fill(); c.stroke();
      c.fillStyle = '#c9e26b'; c.beginPath(); c.ellipse(r * 0.1, r * 0.2, r * 0.9, r * 0.35, 0, 0, TAU); c.fill(); // pale belly
      c.fillStyle = e.dark; for (let i = -3; i <= 2; i++) { c.beginPath(); c.moveTo(i * r * 0.32, -r * 0.5); c.lineTo(i * r * 0.32 + r * 0.14, -r * 1.0); c.lineTo(i * r * 0.32 + r * 0.28, -r * 0.5); c.closePath(); c.fill(); c.stroke(); } // crest
      c.fillStyle = '#ffd166'; c.beginPath(); c.arc(r * 0.9, r * 0.55, r * 0.22, 0, TAU); c.fill(); c.stroke(); // dewlap
    } else if (e.type === 'earwig') { // long segmented body with pincers at the back
      c.beginPath(); c.ellipse(0, 0, r * 1.5, r * 0.6, 0, 0, TAU); c.fill(); c.stroke();
      c.strokeStyle = e.dark; c.lineWidth = 1.5; for (let i = -2; i <= 2; i++) { c.beginPath(); c.moveTo(i * r * 0.45, -r * 0.55); c.lineTo(i * r * 0.45, r * 0.55); c.stroke(); }
      c.strokeStyle = this.OUT; c.lineWidth = 3; const snap = e.mode === 'lunge' ? 0.15 : 0.45; for (const sg of [-1, 1]) { c.beginPath(); c.moveTo(-r * 1.4, sg * r * 0.2); c.quadraticCurveTo(-r * 2.1, sg * r * 0.9, -r * 2.5, sg * r * snap); c.stroke(); } // pincers (closed on the lunge)
      c.fillStyle = e.dark; c.beginPath(); c.arc(r * 1.1, 0, r * 0.45, 0, TAU); c.fill(); c.stroke();
    } else if (e.type === 'roach') { // a glossy brown oval with a shell seam and long antennae
      c.beginPath(); c.ellipse(0, 0, r * 1.25, r * 0.85, 0, 0, TAU); c.fill(); c.stroke();
      c.strokeStyle = e.dark; c.lineWidth = 2; c.beginPath(); c.moveTo(-r * 1.1, 0); c.lineTo(r * 0.5, 0); c.stroke();
      c.strokeStyle = this.OUT; c.lineWidth = 2; for (const sg of [-1, 1]) { c.beginPath(); c.moveTo(r * 1.1, sg * r * 0.2); c.quadraticCurveTo(r * 1.9, sg * r * 0.3 + Math.sin(wig + sg) * 3, r * 2.3, sg * r * 0.9); c.stroke(); }
      c.fillStyle = 'rgba(255,255,255,0.45)'; c.beginPath(); c.ellipse(-r * 0.3, -r * 0.4, r * 0.5, r * 0.18, 0, 0, TAU); c.fill();
    } else if (e.type === 'stinkbug') { // shield body with spikes
      c.beginPath(); c.moveTo(r * 1.1, 0); c.lineTo(r * 0.3, -r); c.lineTo(-r * 0.8, -r * 0.8); c.lineTo(-r * 1.1, 0); c.lineTo(-r * 0.8, r * 0.8); c.lineTo(r * 0.3, r); c.closePath(); c.fill(); c.stroke();
      c.fillStyle = e.dark; for (const sgn of [-1, 1]) { c.beginPath(); c.moveTo(-r * 0.2, sgn * r * 0.9); c.lineTo(-r * 0.5, sgn * r * 1.45); c.lineTo(-r * 0.7, sgn * r * 0.8); c.closePath(); c.fill(); c.stroke(); }
    } else {
      c.beginPath(); c.ellipse(0, 0, r * 1.15, r, 0, 0, TAU); c.fill(); c.stroke();
    }
    if (e.type === 'hornet' || e.type === 'queen') { // stripes
      c.fillStyle = e.dark; for (let i = 0; i < 3; i++) { const x0 = -r * 0.9 + i * r * 0.5; c.beginPath(); c.rect(x0, -r * 0.95, r * 0.22, r * 1.9); c.fill(); }
      c.save(); c.beginPath(); c.ellipse(0, 0, r * 1.15, r, 0, 0, TAU); c.clip(); c.fillStyle = e.dark; for (let i = 0; i < 3; i++) { const x0 = -r * 0.9 + i * r * 0.5; c.fillRect(x0, -r, r * 0.22, r * 2); } c.restore();
      c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.beginPath(); c.moveTo(-r * 1.1, 0); c.lineTo(-r * 1.6, 0); c.stroke(); // stinger
    }
    if (e.type === 'beetle' || e.type === 'gnat') { c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.moveTo(-r, 0); c.lineTo(r * 0.4, 0); c.stroke(); }
    if (e.type === 'beetle') { c.fillStyle = e.dark; for (const [sx, sy] of [[-0.4, -0.45], [-0.4, 0.45], [0.1, -0.5], [0.1, 0.5]]) { c.beginPath(); c.arc(sx * r, sy * r, r * 0.16, 0, TAU); c.fill(); } }
    c.fillStyle = 'rgba(255,255,255,0.35)'; c.beginPath(); c.ellipse(-r * 0.3, -r * 0.4, r * 0.4, r * 0.22, 0, 0, TAU); c.fill();
    c.restore();
    if (e.type === 'queen') { // crown
      c.fillStyle = '#ffd166'; c.strokeStyle = this.OUT; c.lineWidth = 2.5;
      const cx = x + fx * r * 0.5, cy = y + fy * r * 0.5 - r * 0.9;
      c.beginPath(); c.moveTo(cx - 12, cy + 8); c.lineTo(cx - 12, cy - 6); c.lineTo(cx - 6, cy); c.lineTo(cx, cy - 10); c.lineTo(cx + 6, cy); c.lineTo(cx + 12, cy - 6); c.lineTo(cx + 12, cy + 8); c.closePath(); c.fill(); c.stroke();
    }
    const upright = e.type === 'unicycle' || e.type === 'juggler' || e.type === 'mime' || e.type === 'pieclown' || e.type === 'elephant' || e.type === 'kamiclown' || e.type === 'brainling'; // (these draw their own faces)
    if (!upright) this.eyes(x, y, a, r, { spread: r * 0.38, fwd: r * 0.55, er: Math.max(2.5, r * 0.26), pupil: World.dread(e.region) >= 0.4 ? '#ff2a2a' : undefined });
    if (r >= 12 && !upright) this.blush(x, y, a, r * 0.9);
  },

  drawGhost(e) {
    // scared ghosts are blue; in the last two seconds of the scare they blink blue / yellow five times, so you know when to stop chasing
    const ending = e.fright && Dungeon.fright < 2, blinkYellow = ending && Math.floor(Dungeon.fright * 5) % 2 === 1;
    const c = this.ctx, col = e.fright ? (blinkYellow ? '#ffe66d' : '#5b7cff') : (e.flash > 0 ? '#fff' : e.color), r = e.r;
    const bob = Math.sin(G.time * 5 + e.seed) * 2, y = e.y + bob;
    c.fillStyle = col; c.strokeStyle = this.OUT; c.lineWidth = 3;
    c.beginPath(); c.arc(e.x, y - 2, r, Math.PI, 0);
    c.lineTo(e.x + r, y + r * 0.7);
    for (let i = 3; i >= -3; i--) { const px = e.x + i * r / 3; const wy = y + r * 0.7 + ((i + 3) % 2 ? 5 : 0) + Math.sin(G.time * 10 + i) * 1.5; c.lineTo(px, wy); }
    c.closePath(); c.fill(); c.stroke();
    this.shine(e.x, y - 2, r);
    if (e.fright) { this.eyes(e.x, y, -Math.PI / 2, r, { spread: 5, fwd: -1, er: 3.5 }); c.strokeStyle = blinkYellow ? this.OUT : '#fff'; c.lineWidth = 2; c.beginPath(); c.moveTo(e.x - 6, y + 6); c.lineTo(e.x - 3, y + 3); c.lineTo(e.x, y + 6); c.lineTo(e.x + 3, y + 3); c.lineTo(e.x + 6, y + 6); c.stroke(); }
    else { const a = Math.atan2(Player.y - e.y, Player.x - e.x); this.eyes(e.x, y - 1, a, r, { spread: 5.5, fwd: 0, er: 4.5 }); this.blush(e.x, y, -Math.PI / 2, r * 0.9); }
    if (G.mode === 'dungeon' && Dungeon.theme() === 'bigtop') { this.circle(e.x, y + 3, 3.5, '#ff3a3a', this.OUT, 1.5); c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.beginPath(); c.moveTo(e.x, y + r * 0.9); c.lineTo(e.x + 2, y + r * 0.9 + 18); c.stroke(); for (const s of [-1, 1]) { c.fillStyle = '#ff5c8a'; c.beginPath(); c.arc(e.x + s * (r + 2), y - 4, 4, 0, TAU); c.fill(); } } // big-top ghosts are balloon clowns: a red nose, a string, a wig
    this.statusOverlay(e, e.x, y, r);
    this.hpBar(e, e.x, y - r - 12, 30);
  },

  // a ghost: the dungeon's hunters, the ghost pickup and its icon
  ghostIcon(x, y, r, col) {
    const c = this.ctx;
    c.fillStyle = col; c.strokeStyle = this.OUT; c.lineWidth = 2.5;
    c.beginPath(); c.arc(x, y - 2, r, Math.PI, 0); c.lineTo(x + r, y + r * 0.7);
    for (let i = 3; i >= -3; i--) c.lineTo(x + i * r / 3, y + r * 0.7 + ((i + 3) % 2 ? 4 : 0));
    c.closePath(); c.fill(); c.stroke();
    this.eyes(x, y - 1, -Math.PI / 2, r, { spread: r * 0.42, fwd: 0, er: r * 0.3 });
  },
});
