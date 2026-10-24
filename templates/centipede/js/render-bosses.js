// Bosses: the crab, the skull (and its stomping foot), the serpent, the Overlord (and its saucer), the clown car.
// Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  drawCrab(e) {
    const c = this.ctx, B = BOSSES[e.type], r = e.r, col = e.flash > 0 ? '#fff' : e.color;
    const fx = Math.cos(e.angle), fy = Math.sin(e.angle), px = -Math.sin(e.angle), py = Math.cos(e.angle);
    const enraged = e.hp < e.maxhp * 0.3;
    c.fillStyle = 'rgba(0,0,0,0.2)'; c.beginPath(); c.ellipse(e.x + 6, e.y + r * 0.5, r * 1.3, r * 0.5, 0, 0, TAU); c.fill();
    // legs: four each side, scuttling
    c.strokeStyle = this.OUT; c.lineWidth = 6; c.lineCap = 'round';
    for (const s of [-1, 1]) for (let i = 0; i < 4; i++) {
      const t = (i - 1.5) * 0.5, k = Math.sin(e.legT + i * 1.4 + (s > 0 ? 0 : Math.PI)) * 10;
      const bx = e.x + fx * t * r * 0.7 + px * s * r * 0.8, by = e.y + fy * t * r * 0.7 + py * s * r * 0.8;
      const kx = bx + px * s * r * 0.5 + fx * k, ky = by + py * s * r * 0.5 + fy * k - 18;
      const ex = kx + px * s * r * 0.35 - fx * k * 0.5, ey = ky + py * s * r * 0.35 + 26;
      c.strokeStyle = this.OUT; c.lineWidth = 7; c.beginPath(); c.moveTo(bx, by); c.lineTo(kx, ky); c.lineTo(ex, ey); c.stroke();
      c.strokeStyle = e.dark; c.lineWidth = 3.5; c.beginPath(); c.moveTo(bx, by); c.lineTo(kx, ky); c.lineTo(ex, ey); c.stroke();
    }
    // claws (big, in front), snapping when throwing
    const snap = e.clawT > 0 ? Math.sin(e.clawT * 25) * 0.5 : 0.2;
    const S = e.snip;
    for (const s of [-1, 1]) {
      let cx = e.x + fx * r * 0.9 + px * s * r * 0.95, cy = e.y + fy * r * 0.9 + py * s * r * 0.95, rot = e.angle + s * 0.35;
      if (S && S.side === s && S.phase === 'charge') { // the strike winding up: a red ring shrinking onto this claw, the claw glowing hotter
        const k = Math.min(1, S.t / S.ch);
        c.save(); c.globalAlpha = 0.25 + 0.55 * k; c.fillStyle = '#ff5c3a'; c.beginPath(); c.arc(cx, cy, r * (0.45 + 0.25 * k) + Math.sin(G.time * 40) * 3 * k, 0, TAU); c.fill();
        c.globalAlpha = 1 - k * 0.6; c.strokeStyle = '#ffd166'; c.lineWidth = 4; c.beginPath(); c.arc(cx, cy, r * (2.2 - 1.6 * k), 0, TAU); c.stroke(); c.restore();
        this.text('!', e.x, e.y - r * 1.5 - 6 * Math.sin(G.time * 20), 34, '#ff5c3a', 'center');
      }
      if (S && S.side === s && S.phase === 'shoot') { // the claw out on its arm: a jointed arm from the shoulder to where the claw is
        c.save(); c.strokeStyle = this.OUT; c.lineWidth = 11; c.lineCap = 'round'; c.beginPath(); c.moveTo(cx, cy); c.lineTo(S.cx, S.cy); c.stroke();
        c.strokeStyle = e.dark; c.lineWidth = 6; c.stroke();
        const n = Math.max(1, Math.floor(Math.hypot(S.cx - cx, S.cy - cy) / 40)); c.fillStyle = col;
        for (let i = 1; i <= n; i++) { const f = i / (n + 1); c.beginPath(); c.arc(cx + (S.cx - cx) * f, cy + (S.cy - cy) * f, 7, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.stroke(); } // its joints
        c.restore(); cx = S.cx; cy = S.cy; rot = S.ca;
      }
      c.save(); c.translate(cx, cy); c.rotate(rot);
      c.fillStyle = col; c.strokeStyle = this.OUT; c.lineWidth = 4;
      c.beginPath(); c.ellipse(0, 0, r * 0.42, r * 0.3, 0, 0, TAU); c.fill(); c.stroke();
      c.beginPath(); c.moveTo(r * 0.25, -r * 0.2); c.lineTo(r * 0.75, -r * 0.1 - snap * r * 0.3); c.lineTo(r * 0.4, 0.05 * r); c.closePath(); c.fill(); c.stroke();
      c.beginPath(); c.moveTo(r * 0.25, r * 0.2); c.lineTo(r * 0.75, r * 0.1 + snap * r * 0.3); c.lineTo(r * 0.4, -0.05 * r); c.closePath(); c.fill(); c.stroke();
      c.restore();
    }
    // shell
    c.save(); c.translate(e.x, e.y); c.rotate(e.angle);
    c.fillStyle = col; c.strokeStyle = this.OUT; c.lineWidth = 5;
    c.beginPath(); c.ellipse(0, 0, r * 1.05, r * 0.85, 0, 0, TAU); c.fill(); c.stroke();
    c.fillStyle = e.dark; for (let i = 0; i < 5; i++) { const a = -1.2 + i * 0.6; c.beginPath(); c.arc(Math.cos(a) * r * 0.55, Math.sin(a) * r * 0.45 - r * 0.1, r * 0.1, 0, TAU); c.fill(); }
    c.strokeStyle = this.OUT; c.lineWidth = 3; for (const s of [-1, 1]) { c.beginPath(); c.moveTo(-r * 0.9, s * r * 0.3); c.lineTo(-r * 1.1, s * r * 0.55); c.stroke(); }
    c.fillStyle = 'rgba(255,255,255,0.3)'; c.beginPath(); c.ellipse(-r * 0.25, -r * 0.35, r * 0.4, r * 0.18, 0, 0, TAU); c.fill();
    c.restore();
    // eye stalks
    for (const s of [-1, 1]) {
      const bx = e.x + fx * r * 0.55 + px * s * r * 0.35, by = e.y + fy * r * 0.55 + py * s * r * 0.35;
      const tx = bx + fx * 14 + px * s * 6, ty = by + fy * 14 + py * s * 6 - 16;
      c.strokeStyle = this.OUT; c.lineWidth = 5; c.beginPath(); c.moveTo(bx, by); c.lineTo(tx, ty); c.stroke();
      c.fillStyle = '#fff'; c.beginPath(); c.arc(tx, ty, 9, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.stroke();
      c.fillStyle = enraged ? '#ff2a2a' : this.OUT; c.beginPath(); c.arc(tx + fx * 3, ty + fy * 3, 4.5, 0, TAU); c.fill();
      c.fillStyle = '#fff'; c.beginPath(); c.arc(tx + fx * 3 - 1.5, ty + fy * 3 - 1.5, 1.6, 0, TAU); c.fill();
    }
    if (enraged) { c.strokeStyle = `rgba(255,60,60,${0.4 + 0.3 * Math.sin(G.time * 12)})`; c.lineWidth = 4; c.beginPath(); c.arc(e.x, e.y, r * 1.25, 0, TAU); c.stroke(); }
    this.statusOverlay(e, e.x, e.y, r);
  },
  // the Skull King: a giant skull floating on a cushion of fire, jaw working, flames in the sockets, a crown of bone spikes

  drawSkullBoss(e) {
    if (e.unmarked) for (const g of e.unmarked) { // nightmare: unmarked graves, bare dirt mounds (no headstone: nothing to summon), shaking when something rises
      const c = this.ctx, sx = g.shake > 0 ? Math.sin(G.time * 60) * 3 : 0;
      c.fillStyle = '#4a3a2a'; c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.beginPath(); c.ellipse(g.x + sx, g.y, 34, 16, 0, 0, TAU); c.fill(); c.stroke();
      c.fillStyle = '#6a5a48'; c.beginPath(); c.ellipse(g.x + sx, g.y - 4, 26, 9, 0, 0, TAU); c.fill();
      for (let k = 0; k < 3; k++) { c.fillStyle = '#2b2018'; c.fillRect(g.x + sx - 14 + k * 11, g.y - 5 + (k % 2) * 4, 5, 3); } // clods
    }
    const c = this.ctx, B = BOSSES[e.type], r = e.r, col = e.flash > 0 ? '#fff' : e.color, enraged = e.hp < e.maxhp * 0.3;
    const bob = Math.sin(G.time * 2.5 + e.seed) * 6, y = e.y + bob - r * 0.35;
    const fx = Math.cos(e.angle), fy = Math.sin(e.angle);
    c.fillStyle = 'rgba(0,0,0,0.22)'; c.beginPath(); c.ellipse(e.x + 6, e.y + r * 0.75, r * 1.1, r * 0.4, 0, 0, TAU); c.fill();
    for (let i = 0; i < 7; i++) { const ph = (G.time * 1.6 + i / 7 + e.seed) % 1, fr = r * (0.45 - ph * 0.3); c.fillStyle = `rgba(${i % 2 ? '255,209,102' : '255,120,40'},${0.55 * (1 - ph)})`; c.beginPath(); c.arc(e.x + Math.sin(i * 1.9 + G.time * 3) * r * 0.7, e.y + r * 0.5 - ph * r * 1.1, fr, 0, TAU); c.fill(); } // the fire it floats on
    c.save(); c.translate(e.x, y);
    for (let i = -3; i <= 3; i++) { const a = -Math.PI / 2 + i * 0.33, L = r * (i === 0 ? 0.55 : 0.4); c.fillStyle = '#d9d4c7'; c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.moveTo(Math.cos(a - 0.12) * r * 0.9, Math.sin(a - 0.12) * r * 0.9); c.lineTo(Math.cos(a) * (r + L), Math.sin(a) * (r + L)); c.lineTo(Math.cos(a + 0.12) * r * 0.9, Math.sin(a + 0.12) * r * 0.9); c.closePath(); c.fill(); c.stroke(); } // the crown
    c.fillStyle = col; c.strokeStyle = this.OUT; c.lineWidth = 5; c.beginPath(); c.ellipse(0, -r * 0.1, r, r * 0.95, 0, 0, TAU); c.fill(); c.stroke(); // cranium
    c.fillStyle = 'rgba(255,255,255,0.3)'; c.beginPath(); c.ellipse(-r * 0.3, -r * 0.5, r * 0.4, r * 0.18, -0.3, 0, TAU); c.fill();
    c.strokeStyle = e.dark; c.lineWidth = 2.5; c.beginPath(); c.moveTo(r * 0.2, -r * 0.95); c.lineTo(r * 0.1, -r * 0.55); c.lineTo(r * 0.3, -r * 0.3); c.stroke(); c.beginPath(); c.moveTo(-r * 0.6, -r * 0.5); c.lineTo(-r * 0.45, -r * 0.25); c.stroke(); // cracks
    const snap = e.clawT > 0 ? Math.abs(Math.sin(e.clawT * 25)) * r * 0.3 : Math.abs(Math.sin(G.time * 1.5)) * r * 0.08;
    c.fillStyle = col; c.strokeStyle = this.OUT; c.lineWidth = 4; this.roundRect(-r * 0.62, r * 0.45 + snap, r * 1.24, r * 0.45, 6); c.fill(); c.stroke(); // the jaw
    c.strokeStyle = this.OUT; c.lineWidth = 2.5; for (let i = -2; i <= 2; i++) { c.beginPath(); c.moveTo(i * r * 0.22, r * 0.45 + snap); c.lineTo(i * r * 0.22, r * 0.85 + snap); c.stroke(); c.beginPath(); c.moveTo(i * r * 0.22, r * 0.5); c.lineTo(i * r * 0.22, r * 0.2); c.stroke(); } // teeth, upper and lower
    c.fillStyle = this.OUT; c.beginPath(); c.moveTo(0, -r * 0.05); c.lineTo(-r * 0.14, r * 0.3); c.lineTo(r * 0.14, r * 0.3); c.closePath(); c.fill(); // nose
    for (const s of [-1, 1]) { // eye sockets with fire in them, looking at you
      c.fillStyle = this.OUT; c.beginPath(); c.ellipse(s * r * 0.4, -r * 0.2, r * 0.3, r * 0.34, 0, 0, TAU); c.fill();
      const g = 0.5 + 0.5 * Math.sin(G.time * 14 + s);
      c.fillStyle = enraged ? `rgba(255,${60 + 60 * g},40,1)` : `rgba(255,${150 + 60 * g},40,1)`; c.beginPath(); c.ellipse(s * r * 0.4 + fx * r * 0.08, -r * 0.2 + fy * r * 0.08 - g * 3, r * 0.14, r * 0.2 + g * 3, 0, 0, TAU); c.fill();
      c.fillStyle = '#fff5c0'; c.beginPath(); c.arc(s * r * 0.4 + fx * r * 0.08, -r * 0.2 + fy * r * 0.08 + 2, r * 0.06, 0, TAU); c.fill();
    }
    c.restore();
    if (enraged) { c.strokeStyle = `rgba(255,60,60,${0.4 + 0.3 * Math.sin(G.time * 12)})`; c.lineWidth = 4; c.beginPath(); c.arc(e.x, y, r * 1.3, 0, TAU); c.stroke(); }
    this.statusOverlay(e, e.x, y, r);
  },
  // the Jungle Serpent: a long green body with yellow diamonds, a crest of feathers on the head, a forked tongue

  drawSerpent(e) {
    const c = this.ctx, B = BOSSES[e.type], r = e.r, col = e.flash > 0 ? '#fff' : e.color, enraged = e.hp < e.maxhp * 0.3, SR = B.segR;
    c.fillStyle = 'rgba(0,0,0,0.18)'; for (const s of e.segPos) { c.beginPath(); c.ellipse(s.x + 3, s.y + 8, SR, SR * 0.5, 0, 0, TAU); c.fill(); } c.beginPath(); c.ellipse(e.x + 4, e.y + 10, r, r * 0.5, 0, 0, TAU); c.fill();
    for (let i = e.segPos.length - 1; i >= 0; i--) {
      const s = e.segPos[i], rr = SR * (1 - i / (e.segPos.length + 6) * 0.5);
      this.circle(s.x, s.y, rr, i % 2 ? e.dark : col, this.OUT, 3.5);
      if (i % 2 === 0) { c.fillStyle = '#ffd166'; c.beginPath(); c.moveTo(s.x, s.y - rr * 0.6); c.lineTo(s.x + rr * 0.4, s.y); c.lineTo(s.x, s.y + rr * 0.6); c.lineTo(s.x - rr * 0.4, s.y); c.closePath(); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.stroke(); } // diamonds
      if (i === e.segPos.length - 1) { c.strokeStyle = '#ff5c5c'; c.lineWidth = 3; for (let k = -1; k <= 1; k++) { c.beginPath(); c.moveTo(s.x, s.y); c.lineTo(s.x - 18 + k * 6, s.y + 14 + Math.sin(G.time * 6 + k) * 4); c.stroke(); } } // tail feathers
    }
    const fx = Math.cos(e.angle), fy = Math.sin(e.angle), px = -Math.sin(e.angle), py = Math.cos(e.angle);
    for (let i = -2; i <= 2; i++) { // the crest: red, teal, gold feathers fanning back from the head
      const a = e.angle + Math.PI + i * 0.3, L = r * (i === 0 ? 1.5 : 1.2), fxx = e.x + Math.cos(a) * L, fyy = e.y + Math.sin(a) * L - 6;
      c.strokeStyle = this.OUT; c.lineWidth = 8; c.lineCap = 'round'; c.beginPath(); c.moveTo(e.x - fx * r * 0.3, e.y - fy * r * 0.3); c.lineTo(fxx, fyy); c.stroke();
      c.strokeStyle = ['#ff5c5c', '#4ad0e0', '#ffd166', '#4ad0e0', '#ff5c5c'][i + 2]; c.lineWidth = 4.5; c.stroke();
    }
    this.circle(e.x, e.y, r, col, this.OUT, 4.5); this.shine(e.x, e.y, r);
    c.fillStyle = '#ffd166'; c.beginPath(); c.ellipse(e.x - fx * r * 0.2, e.y - fy * r * 0.2, r * 0.3, r * 0.5, e.angle, 0, TAU); c.fill(); // a gold diamond on the brow
    const snap = e.clawT > 0 ? 0.5 : 0; c.strokeStyle = '#ff5c8a'; c.lineWidth = 3; c.beginPath(); c.moveTo(e.x + fx * r, e.y + fy * r); const tl = r * (0.6 + snap) + Math.sin(G.time * 12) * 4; c.lineTo(e.x + fx * (r + tl), e.y + fy * (r + tl)); c.stroke(); for (const s of [-1, 1]) { c.beginPath(); c.moveTo(e.x + fx * (r + tl), e.y + fy * (r + tl)); c.lineTo(e.x + fx * (r + tl + 8) + px * s * 6, e.y + fy * (r + tl + 8) + py * s * 6); c.stroke(); } // forked tongue
    this.eyes(e.x, e.y, e.angle, r, { spread: r * 0.5, fwd: r * 0.25, er: r * 0.28, pupil: enraged ? '#ff2a2a' : '#2a1a00' });
    c.fillStyle = '#fff'; for (const s of [-1, 1]) { const bx = e.x + fx * r * 0.8 + px * s * 8, by = e.y + fy * r * 0.8 + py * s * 8; c.beginPath(); c.moveTo(bx - px * 3, by - py * 3); c.lineTo(bx + px * 3, by + py * 3); c.lineTo(bx + fx * 9, by + fy * 9); c.closePath(); c.fill(); } // fangs
    if (enraged) { c.strokeStyle = `rgba(255,60,60,${0.4 + 0.3 * Math.sin(G.time * 12)})`; c.lineWidth = 4; c.beginPath(); c.arc(e.x, e.y, r * 1.3, 0, TAU); c.stroke(); }
    this.statusOverlay(e, e.x, e.y, r);
  },
  // the Brain Overlord: a tall-domed, wrinkle-brained alien standing on a hovering disc, ray gun in hand, with the
  // segments it abducted orbiting it as its gun. Beam, tractor beam and saucer telegraphs are drawn around it.

  drawOverlord(e) {
    const c = this.ctx, B = BOSSES[e.type], r = e.r, col = e.flash > 0 ? '#fff' : e.color, enraged = e.hp < e.maxhp * 0.3;
    const bob = Math.sin(G.time * 2 + e.seed) * 5, y = e.y + bob, fx = Math.cos(e.angle), fy = Math.sin(e.angle);
    c.fillStyle = 'rgba(0,0,0,0.2)'; c.beginPath(); c.ellipse(e.x + 5, e.y + r * 0.9, r * 1.3, r * 0.45, 0, 0, TAU); c.fill();
    // the tractor beam telegraph
    if (e.abd) { const A = e.abd, k = A.phase === 'warn' ? 1 - A.t / A.warn : 1, R = B.abductR; c.fillStyle = `rgba(155,240,106,${0.12 + 0.18 * k})`; c.beginPath(); c.moveTo(e.x - 10, y); c.lineTo(e.x + 10, y); c.lineTo(A.x + R, A.y); c.lineTo(A.x - R, A.y); c.closePath(); c.fill(); c.strokeStyle = A.phase === 'pull' ? '#fff' : `rgba(155,240,106,${0.5 + 0.5 * k})`; c.lineWidth = 4; c.setLineDash([8, 6]); c.lineDashOffset = -G.time * 60; c.beginPath(); c.ellipse(A.x, A.y + 4, R, R * 0.75, 0, 0, TAU); c.stroke(); c.setLineDash([]); this.text(A.phase === 'pull' ? 'ABDUCTING' : 'TRACTOR BEAM', A.x, A.y - R - 12, 15, A.phase === 'pull' ? '#fff' : '#9bf06a', 'center'); if (A.phase === 'pull') for (let i = 0; i < 8; i++) { const ph = (G.time * 2 + i / 8) % 1; c.fillStyle = `rgba(200,255,170,${1 - ph})`; c.beginPath(); c.arc(A.x + Math.cos(i * 0.8) * R * 0.5 * (1 - ph), A.y - ph * 120, 3, 0, TAU); c.fill(); } }
    // the laser
    if (e.beam) { const L = e.beam, ex = e.x + Math.cos(L.a) * B.beamLen, ey = y + Math.sin(L.a) * B.beamLen; c.lineCap = 'round'; if (L.phase === 'warn') { c.strokeStyle = `rgba(255,92,240,${0.4 + 0.5 * Math.sin(G.time * 20)})`; c.lineWidth = 3; c.setLineDash([10, 10]); c.beginPath(); c.moveTo(e.x, y); c.lineTo(ex, ey); c.stroke(); c.setLineDash([]); } else { c.globalAlpha = 0.55; c.strokeStyle = '#ff5cf0'; c.lineWidth = 22; c.beginPath(); c.moveTo(e.x, y); c.lineTo(ex, ey); c.stroke(); c.globalAlpha = 1; c.lineWidth = 8; c.stroke(); c.strokeStyle = '#fff'; c.lineWidth = 3; c.stroke(); } }
    // the disc it stands on
    c.fillStyle = '#9ba3b0'; c.strokeStyle = this.OUT; c.lineWidth = 4; c.beginPath(); c.ellipse(e.x, y + r * 0.55, r * 1.35, r * 0.42, 0, 0, TAU); c.fill(); c.stroke();
    for (let i = -3; i <= 3; i++) { const on = (Math.floor(G.time * 5) + i) % 4 === 0; c.fillStyle = on ? '#ff5cf0' : '#7df9ff'; c.beginPath(); c.arc(e.x + i * r * 0.4, y + r * 0.72, r * 0.08, 0, TAU); c.fill(); }
    for (let i = 0; i < 5; i++) { const ph = (G.time * 1.5 + i / 5) % 1; c.fillStyle = `rgba(155,240,106,${0.5 * (1 - ph)})`; c.beginPath(); c.ellipse(e.x, y + r * 0.6 + ph * r * 0.9, r * (0.5 + ph * 0.8), r * 0.12, 0, 0, TAU); c.fill(); } // the thrust
    // the body: gawky, a tiny torso in a silver suit, long thin arms, the ray gun
    c.fillStyle = '#c9ced6'; c.strokeStyle = this.OUT; c.lineWidth = 3.5; this.roundRect(e.x - r * 0.3, y - r * 0.2, r * 0.6, r * 0.75, 6); c.fill(); c.stroke();
    c.strokeStyle = this.OUT; c.lineWidth = 5; c.beginPath(); c.moveTo(e.x + r * 0.3, y + r * 0.05); c.lineTo(e.x + fx * r * 0.9 + r * 0.2, y + fy * r * 0.3 + r * 0.1); c.stroke(); c.strokeStyle = '#c9ced6'; c.lineWidth = 2.5; c.stroke();
    c.save(); c.translate(e.x + fx * r * 0.9 + r * 0.2, y + fy * r * 0.3 + r * 0.1); c.rotate(e.angle); c.fillStyle = '#7a8090'; c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.beginPath(); c.moveTo(-6, -5); c.lineTo(r * 0.5, -4); c.lineTo(r * 0.5, 4); c.lineTo(-6, 6); c.closePath(); c.fill(); c.stroke(); c.fillStyle = '#ff5cf0'; c.beginPath(); c.arc(r * 0.5, 0, 4 + (e.beam ? 3 : 0), 0, TAU); c.fill(); c.restore(); // the ray gun
    // the head: a huge dome brain over big black eyes, with a crown-like ridge
    c.fillStyle = col; c.strokeStyle = this.OUT; c.lineWidth = 4.5; c.beginPath(); c.ellipse(e.x, y - r * 0.55, r * 0.95, r * 0.85, 0, 0, TAU); c.fill(); c.stroke();
    c.strokeStyle = e.dark; c.lineWidth = 2; for (let i = 0; i < 5; i++) { c.beginPath(); c.moveTo(e.x - r * 0.75 + i * r * 0.37, y - r * 0.9); c.quadraticCurveTo(e.x - r * 0.6 + i * r * 0.37, y - r * 1.35, e.x - r * 0.4 + i * r * 0.37, y - r * 0.8); c.stroke(); } c.beginPath(); c.moveTo(e.x, y - r * 1.4); c.lineTo(e.x, y - r * 0.2); c.stroke();
    c.fillStyle = this.OUT; for (const sg of [-1, 1]) { c.beginPath(); c.ellipse(e.x + sg * r * 0.4, y - r * 0.15, r * 0.3, r * 0.17, sg * 0.5, 0, TAU); c.fill(); c.fillStyle = enraged ? '#ff5c5c' : '#7df9ff'; c.beginPath(); c.arc(e.x + sg * r * 0.4 + fx * 3, y - r * 0.15 + fy * 2, r * 0.07, 0, TAU); c.fill(); c.fillStyle = this.OUT; }
    c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.moveTo(e.x - 5, y + r * 0.12); c.lineTo(e.x + 5, y + r * 0.12); c.stroke(); // a thin, unimpressed mouth
    // the captives: your segments, orbiting as its gun
    if (e.captives) for (const cp of e.captives) { const W = BULLETS[cp.id], cx = e.x + Math.cos(cp.orb) * (r + 40), cy = y + Math.sin(cp.orb) * (r + 40) * 0.6; c.fillStyle = 'rgba(155,240,106,0.25)'; c.beginPath(); c.arc(cx, cy, 16, 0, TAU); c.fill(); c.fillStyle = W.color; c.strokeStyle = '#9bf06a'; c.lineWidth = 2.5; this.roundRect(cx - 11, cy - 11, 22, 22, 5); c.fill(); c.stroke(); Icons.tile(W, cx, cy, 22, c); }
    if (enraged) { c.strokeStyle = `rgba(255,60,60,${0.4 + 0.3 * Math.sin(G.time * 12)})`; c.lineWidth = 4; c.beginPath(); c.arc(e.x, y - r * 0.2, r * 1.5, 0, TAU); c.stroke(); }
    this.statusOverlay(e, e.x, y, r);
  },
  // the stomp telegraph: a shadow that grows where the foot will land, with a red ring. Drawn under everything.

  drawStompShadow() {
    const c = this.ctx;
    for (const e of G.enemies) {
      if (e.kind !== 'boss' || e.dead || !e.stomp) continue;
      const S = e.stomp, k = S.phase === 'warn' ? 1 - S.t / S.warn : 1, blink = S.phase === 'warn' && S.t < 0.4 && Math.floor(G.time * 12) % 2 === 0;
      c.fillStyle = `rgba(0,0,0,${0.15 + 0.3 * k})`; c.beginPath(); c.ellipse(S.x, S.y + 6, S.r * (0.3 + 0.7 * k), S.r * (0.3 + 0.7 * k) * 0.8, 0, 0, TAU); c.fill();
      c.strokeStyle = blink ? '#fff' : `rgba(255,70,70,${0.4 + 0.5 * k})`; c.lineWidth = 4; c.setLineDash([10, 8]); c.lineDashOffset = -G.time * 80; c.beginPath(); c.ellipse(S.x, S.y + 6, S.r, S.r * 0.8, 0, 0, TAU); c.stroke(); c.setLineDash([]);
      if (S.phase === 'warn') this.text(S.ship ? 'LANDING' : 'STOMP', S.x, S.y - S.r - 12, 16, blink ? '#fff' : '#ff7a5c', 'center');
    }
  },
  // the foot itself, falling in from above: drawn over everything

  drawStompFoot() {
    for (const e of G.enemies) {
      if (e.kind !== 'boss' || e.dead || !e.stomp) continue;
      const S = e.stomp, H = 560;
      const h = S.phase === 'warn' ? H + 200 - (1 - S.t / S.warn) * 200 : S.phase === 'down' ? H * (S.t / (S.ship ? 0.35 : 0.22)) : S.phase === 'hold' ? 0 : H * (1 - S.t / (S.ship ? 0.8 : 0.5));
      if (S.ship) this.drawSaucer(S.x, S.y, S.r, h); else this.drawFoot(S.x, S.y, S.r, h);
    }
  },

  drawSaucer(x, y, R, h) { // the Overlord's saucer coming down: a wide disc, a dome, running lights, legs out
    const c = this.ctx; c.save(); c.translate(x, y - h);
    c.strokeStyle = this.OUT; c.lineWidth = 5; for (const a of [0.4, Math.PI / 2, Math.PI - 0.4]) { c.beginPath(); c.moveTo(Math.cos(a) * R * 0.6, Math.sin(a) * R * 0.2); c.lineTo(Math.cos(a) * R * 0.95, Math.sin(a) * R * 0.5 + 10); c.stroke(); } // landing legs
    c.fillStyle = '#c9ced6'; c.strokeStyle = this.OUT; c.lineWidth = 4; c.beginPath(); c.ellipse(0, 0, R * 1.05, R * 0.4, 0, 0, TAU); c.fill(); c.stroke();
    c.fillStyle = '#9ba3b0'; c.beginPath(); c.ellipse(0, -R * 0.25, R * 0.5, R * 0.35, 0, Math.PI, 0); c.fill(); c.stroke();
    for (let i = -4; i <= 4; i++) { const on = (Math.floor(G.time * 8) + i) % 3 === 0; c.fillStyle = on ? '#ff5cf0' : '#7df9ff'; c.beginPath(); c.arc(i * R * 0.22, R * 0.08, R * 0.05, 0, TAU); c.fill(); }
    c.fillStyle = this.OUT; c.beginPath(); c.ellipse(R * 0.1, -R * 0.3, R * 0.12, R * 0.07, 0, 0, TAU); c.fill(); // the pilot
    c.restore();
  },

  drawFoot(x, y, R, h) { // a skeletal foot seen from above, hovering h px over (x,y), big enough to cover radius R
    const c = this.ctx, col = '#eae4d4', out = this.OUT;
    c.save(); c.translate(x, y - h); c.lineCap = 'round';
    c.strokeStyle = out; c.lineWidth = R * 0.42; c.beginPath(); c.moveTo(0, -R * 0.1); c.lineTo(0, -R * 6); c.stroke(); // the shin, up out of frame
    c.strokeStyle = col; c.lineWidth = R * 0.3; c.stroke();
    c.fillStyle = col; c.strokeStyle = out; c.lineWidth = 4; c.beginPath(); c.ellipse(0, R * 0.15, R * 0.55, R * 0.45, 0, 0, TAU); c.fill(); c.stroke(); // heel and ankle
    for (let i = 0; i < 5; i++) { // five toe bones
      const a = -Math.PI / 2 + (i - 2) * 0.34, len = R * (i === 2 ? 1 : i === 0 || i === 4 ? 0.8 : 0.93), tx = Math.cos(a) * len, ty = Math.sin(a) * len * 0.85 + R * 0.15, sx = Math.cos(a) * R * 0.3, sy = Math.sin(a) * R * 0.3 + R * 0.15;
      c.strokeStyle = out; c.lineWidth = R * 0.22; c.beginPath(); c.moveTo(sx, sy); c.lineTo(tx, ty); c.stroke();
      c.strokeStyle = col; c.lineWidth = R * 0.13; c.stroke();
      c.strokeStyle = out; c.lineWidth = 2; c.beginPath(); c.moveTo((sx + tx) / 2 - Math.sin(a) * R * 0.08, (sy + ty) / 2 + Math.cos(a) * R * 0.08); c.lineTo((sx + tx) / 2 + Math.sin(a) * R * 0.08, (sy + ty) / 2 - Math.cos(a) * R * 0.08); c.stroke(); // a knuckle
      this.circle(tx, ty, R * 0.12, col, out, 3);
    }
    c.restore();
  },
  // the Clown Car: a tiny round car with far too many faces in the windows, a flower on the bonnet, big balloon tyres.
  // Headlights flash before a run; doors fly open for the clowns (and are gone after half hp); a pump jack refuels it.
  drawClownCar(e) {
    // The Clown Car, side on so it reads as a CAR: a bubble-roofed tiny car on two huge balloon tyres, pink with yellow
    // polka dots, a grinning chrome bumper, two big headlights, a flower on the bonnet, a bulb horn, an exhaust pipe, and
    // far too many clowns: faces crammed in every window, two out of the sunroof waving, one hanging out of the door.
    // It flips to face the way it drives. Headlights flash before a run; out of gas the bonnet is up and steaming.
    const c = this.ctx, B = BOSSES[e.type], r = e.r, col = e.flash > 0 ? '#fff' : e.color, enraged = e.hp < e.maxhp * 0.3, C = e.car || { state: 'drive', wheel: 0 };
    const gas = C.state === 'gas', warn = C.state === 'warn', run = C.state === 'run', facing = Math.cos(e.angle) >= 0 ? 1 : -1;
    const W = r * 2.7, H = r * 1.5, bounce = run ? Math.sin(G.time * 40) * 3 : gas ? Math.sin(G.time * 50) * 1.2 : Math.sin(G.time * 5) * 1.5;
    c.fillStyle = 'rgba(0,0,0,0.22)'; c.beginPath(); c.ellipse(e.x + 6, e.y + r * 0.85, W * 0.55, r * 0.4, 0, 0, TAU); c.fill();
    if (gas) { c.fillStyle = `rgba(255,209,102,${0.12 + 0.08 * Math.sin(G.time * 8)})`; c.beginPath(); c.arc(e.x, e.y, r * 1.9, 0, TAU); c.fill(); c.strokeStyle = '#ffd166'; c.lineWidth = 3; c.setLineDash([8, 8]); c.lineDashOffset = -G.time * 40; c.beginPath(); c.arc(e.x, e.y, r * 1.9, 0, TAU); c.stroke(); c.setLineDash([]); this.text('OUT OF GAS · HIT IT!', e.x, e.y - r * 2.1, 18, '#ffd166', 'center'); }
    if (warn) { const k = 0.5 + 0.5 * Math.sin(G.time * 30); c.strokeStyle = `rgba(255,255,255,${0.3 + 0.5 * k})`; c.lineWidth = 3; c.setLineDash([12, 10]); c.beginPath(); c.moveTo(e.x, e.y); c.lineTo(e.x + Math.cos(e.angle) * 900, e.y + Math.sin(e.angle) * 900); c.stroke(); c.setLineDash([]); } // the line it is about to take
    c.save(); c.translate(e.x, e.y - r * 0.25 + bounce); c.scale(facing, 1);
    const O = this.OUT, wheelY = H * 0.42, wr = r * 0.4;
    // exhaust pipe at the back, puffing
    c.fillStyle = '#555'; c.strokeStyle = O; c.lineWidth = 2.5; this.roundRect(-W / 2 - r * 0.35, H * 0.2, r * 0.4, r * 0.16, 3); c.fill(); c.stroke();
    for (let i = 0; i < 3; i++) { const ph = (G.time * (run ? 2.5 : 0.8) + i / 3) % 1; c.fillStyle = `rgba(${run ? '255,170,220' : '150,150,160'},${0.4 * (1 - ph)})`; c.beginPath(); c.arc(-W / 2 - r * 0.5 - ph * r * 0.9, H * 0.25 - ph * r * 0.6, r * (0.1 + ph * 0.25), 0, TAU); c.fill(); }
    // two huge balloon tyres with yellow hubs (they turn)
    for (const wx of [-W * 0.3, W * 0.3]) { c.save(); c.translate(wx, wheelY); c.rotate(C.wheel * facing); this.circle(0, 0, wr, '#2a2a2a', O, 3.5); c.strokeStyle = '#ffd166'; c.lineWidth = 3; c.beginPath(); c.moveTo(-wr * 0.75, 0); c.lineTo(wr * 0.75, 0); c.moveTo(0, -wr * 0.75); c.lineTo(0, wr * 0.75); c.stroke(); this.circle(0, 0, wr * 0.3, '#ffd166', O, 2); c.restore(); }
    // the body: a chassis with a bubble roof, pink with yellow polka dots and a white racing stripe
    c.fillStyle = col; c.strokeStyle = O; c.lineWidth = 4.5;
    c.beginPath(); c.moveTo(-W / 2, H * 0.3); c.lineTo(-W / 2, -H * 0.05); c.quadraticCurveTo(-W / 2, -H * 0.2, -W * 0.4, -H * 0.2); c.lineTo(-W * 0.32, -H * 0.2); c.quadraticCurveTo(-W * 0.3, -H * 0.72, 0, -H * 0.72); c.quadraticCurveTo(W * 0.22, -H * 0.72, W * 0.3, -H * 0.25); c.lineTo(W * 0.42, -H * 0.2); c.quadraticCurveTo(W / 2, -H * 0.2, W / 2, 0); c.lineTo(W / 2, H * 0.3); c.closePath(); c.fill(); c.stroke();
    c.save(); c.clip(); c.fillStyle = '#ffd166'; for (let i = 0; i < 9; i++) { const px = -W * 0.45 + (i % 3) * W * 0.3 + (i % 2) * r * 0.2, py = -H * 0.55 + Math.floor(i / 3) * H * 0.33; c.beginPath(); c.arc(px, py, r * 0.1, 0, TAU); c.fill(); } c.fillStyle = 'rgba(255,255,255,0.45)'; c.fillRect(-W / 2, H * 0.05, W, r * 0.12); c.restore();
    // the door, with a clown hanging out of it (gone once the doors fall off: a dark hole and the clowns pour out)
    if (!C.doorsOff) { const open = C.doorT > 0; c.save(); c.translate(-W * 0.02, -H * 0.15); if (open) c.rotate(-0.9); c.fillStyle = col; c.strokeStyle = O; c.lineWidth = 3; this.roundRect(-r * 0.28, 0, r * 0.56, H * 0.42, 5); c.fill(); c.stroke(); c.fillStyle = O; c.beginPath(); c.arc(r * 0.18, H * 0.2, 2.5, 0, TAU); c.fill(); c.restore(); }
    else { c.fillStyle = '#1a0a14'; c.strokeStyle = O; c.lineWidth = 3; this.roundRect(-W * 0.02 - r * 0.28, -H * 0.15, r * 0.56, H * 0.42, 5); c.fill(); c.stroke(); this.clownHead(-W * 0.02, -H * 0.1 + Math.sin(G.time * 9) * 2, r * 0.2, '#9bf06a'); }
    // windows crammed with faces: the side window and the windscreen
    c.fillStyle = '#1f3a5a'; c.strokeStyle = O; c.lineWidth = 3; c.beginPath(); c.moveTo(-W * 0.27, -H * 0.2); c.quadraticCurveTo(-W * 0.25, -H * 0.6, 0, -H * 0.6); c.lineTo(0, -H * 0.2); c.closePath(); c.fill(); c.stroke();
    c.beginPath(); c.moveTo(W * 0.04, -H * 0.2); c.lineTo(W * 0.04, -H * 0.6); c.quadraticCurveTo(W * 0.18, -H * 0.6, W * 0.26, -H * 0.25); c.closePath(); c.fill(); c.stroke();
    const wigs = ['#ff5c8a', '#7df9ff', '#9bf06a', '#f3c6ff', '#ff8a3d', '#ffd166'];
    for (let i = 0; i < 5; i++) { const fx = -W * 0.2 + i * W * 0.1 + (i > 2 ? W * 0.03 : 0), fy = -H * 0.38 + Math.sin(G.time * 7 + i * 1.3) * 2; this.clownHead(fx, fy, r * 0.13, wigs[i % 6]); }
    // two out of the sunroof, waving
    for (const [sx, k] of [[-W * 0.14, 0], [W * 0.02, 1]]) { const hy = -H * 0.72 - r * 0.22, wave = Math.sin(G.time * 8 + k * 2) * 0.6; c.strokeStyle = O; c.lineWidth = 3; c.lineCap = 'round'; c.beginPath(); c.moveTo(sx + r * 0.2, hy + r * 0.2); c.lineTo(sx + r * 0.45, hy - r * 0.3 - wave * r * 0.3); c.stroke(); c.strokeStyle = wigs[(k + 2) % 6]; c.lineWidth = 1.5; c.stroke(); c.fillStyle = col; c.strokeStyle = O; c.lineWidth = 2.5; this.roundRect(sx - r * 0.2, hy + r * 0.1, r * 0.4, r * 0.3, 4); c.fill(); c.stroke(); this.clownHead(sx, hy, r * 0.2, wigs[(k + 4) % 6]); }
    // the front: bonnet (up and steaming when out of gas), headlights, a chrome bumper with a grin, the flower
    if (gas) { c.save(); c.translate(W * 0.3, -H * 0.2); c.rotate(-0.8); c.fillStyle = col; c.strokeStyle = O; c.lineWidth = 3; this.roundRect(0, -r * 0.12, W * 0.22, r * 0.24, 4); c.fill(); c.stroke(); c.restore(); for (let i = 0; i < 3; i++) { const ph = (G.time * 1.2 + i / 3) % 1; c.fillStyle = `rgba(235,235,245,${0.5 * (1 - ph)})`; c.beginPath(); c.arc(W * 0.38 + Math.sin(G.time * 3 + i) * 4, -H * 0.3 - ph * r * 0.9, r * (0.08 + ph * 0.2), 0, TAU); c.fill(); } }
    for (const hy of [-H * 0.05, H * 0.17]) { const on = warn ? Math.floor(G.time * 12) % 2 === 0 : run; this.circle(W / 2 - 2, hy, r * 0.14, on ? '#fff' : '#ffe9a8', O, 2.5); if (on) { c.fillStyle = 'rgba(255,255,200,0.18)'; c.beginPath(); c.moveTo(W / 2, hy); c.lineTo(W / 2 + r * 2.6, hy - r * 0.5); c.lineTo(W / 2 + r * 2.6, hy + r * 0.5); c.closePath(); c.fill(); } }
    c.fillStyle = '#d8d8e0'; c.strokeStyle = O; c.lineWidth = 3; this.roundRect(-W * 0.1, H * 0.26, W * 0.62, r * 0.22, 4); c.fill(); c.stroke(); c.strokeStyle = O; c.lineWidth = 2; for (let i = 0; i < 5; i++) { c.beginPath(); c.moveTo(W * 0.05 + i * W * 0.1, H * 0.26); c.lineTo(W * 0.05 + i * W * 0.1, H * 0.26 + r * 0.22); c.stroke(); } // the bumper grins
    { const sq = e.clawT > 0 ? 1 + Math.sin(e.clawT * 30) * 0.3 : 1; c.strokeStyle = '#2a7a2a'; c.lineWidth = 2.5; c.beginPath(); c.moveTo(W * 0.3, -H * 0.2); c.lineTo(W * 0.34, -H * 0.42); c.stroke(); c.fillStyle = '#ffd166'; for (let i = 0; i < 6; i++) { const a = i * TAU / 6; c.beginPath(); c.arc(W * 0.34 + Math.cos(a) * r * 0.11 * sq, -H * 0.42 + Math.sin(a) * r * 0.11 * sq, r * 0.07, 0, TAU); c.fill(); } this.circle(W * 0.34, -H * 0.42, r * 0.06, '#ff5c8a', O, 1); } // the squirting flower
    // the bulb horn on the back of the roof
    c.strokeStyle = O; c.lineWidth = 4; c.beginPath(); c.moveTo(-W * 0.3, -H * 0.3); c.lineTo(-W * 0.42, -H * 0.5); c.stroke(); c.strokeStyle = '#ffd166'; c.lineWidth = 2; c.stroke(); this.circle(-W * 0.44, -H * 0.52, r * 0.13, '#ff3a3a', O, 2);
    c.restore();
    if (gas) { // the pump jack bobbing beside it
      const px = e.x - facing * r * 2.2, py = e.y - r * 0.4, bob = Math.sin(C.pump || 0) * 8; c.strokeStyle = this.OUT; c.lineWidth = 5; c.lineCap = 'round'; c.beginPath(); c.moveTo(px, py + 20); c.lineTo(px, py - 10); c.stroke(); c.beginPath(); c.moveTo(px - 22, py - 10 + bob); c.lineTo(px + 22, py - 10 - bob); c.stroke(); c.strokeStyle = '#ff5c5c'; c.lineWidth = 2.5; c.stroke(); this.circle(px, py - 10, 5, '#ffd166', this.OUT, 2); c.strokeStyle = '#8a1f3a'; c.lineWidth = 3; c.beginPath(); c.moveTo(px + 22, py - 10 - bob); c.quadraticCurveTo(px + 30, py + 10, e.x - facing * r * 1.3, e.y); c.stroke();
      this.text('refuelling', px, py + 36, 12, '#ffd166', 'center');
    }
    if (enraged) { c.strokeStyle = `rgba(255,60,60,${0.4 + 0.3 * Math.sin(G.time * 12)})`; c.lineWidth = 4; c.beginPath(); c.arc(e.x, e.y, r * 1.6, 0, TAU); c.stroke(); }
    this.statusOverlay(e, e.x, e.y, r);
  },
  // a clown's head: white face, red nose, a coloured wig either side, a big grin

  clownHead(x, y, r, wig) {
    const c = this.ctx, O = this.OUT;
    c.fillStyle = wig; c.strokeStyle = O; c.lineWidth = Math.max(1.2, r * 0.18); for (const s of [-1, 1]) { c.beginPath(); c.arc(x + s * r * 0.95, y - r * 0.2, r * 0.55, 0, TAU); c.fill(); c.stroke(); }
    this.circle(x, y, r, '#fff5e0', O, Math.max(1.5, r * 0.2));
    c.fillStyle = O; for (const s of [-1, 1]) { c.beginPath(); c.arc(x + s * r * 0.38, y - r * 0.2, Math.max(1, r * 0.14), 0, TAU); c.fill(); }
    this.circle(x, y + r * 0.1, r * 0.28, '#ff3a3a', O, Math.max(1, r * 0.12));
    c.strokeStyle = O; c.lineWidth = Math.max(1, r * 0.12); c.beginPath(); c.arc(x, y + r * 0.25, r * 0.5, 0.3, Math.PI - 0.3); c.stroke();
  },
});
