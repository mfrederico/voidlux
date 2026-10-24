// The centipede: head, body segments, the rider parasites. (legs() is in render.js because the title and ascend screens use it too.)
// Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  // ---- the centipede ----
  // the tractor's fuel (the cake's time left): a bar over the head, blinking LOW FUEL (SHRINKING) in the last seconds
  drawGiantGauge() {
    const P = Player; if (!(P.giant > 0) || G.mode !== 'world') return;
    const c = this.ctx, f = U.clamp(P.giant / CFG.GIANT.time, 0, 1), low = P.giant < CFG.GIANT.warn, bw = 70, bx = P.x - bw / 2, by = P.y - 64;
    c.fillStyle = 'rgba(0,0,0,0.55)'; this.roundRect(bx - 3, by - 3, bw + 6, 12, 5); c.fill();
    c.fillStyle = low ? '#ff5c5c' : f < 0.5 ? '#ffd166' : '#9bf06a'; this.roundRect(bx, by, Math.max(3, bw * f), 6, 3); c.fill();
    if (low && Math.floor(G.time * 4) % 2 === 0) this.text(P.tractor ? 'LOW FUEL' : 'SHRINKING', P.x, by - 14, 15, '#ff5c5c', 'center');
  },
  // Lightning Storm: a crackling glow round the ball, and a lightning spike off every segment, flickering outward
  drawBall(sc) {
    const c = this.ctx, P = Player, k = P.ballK(), R = P.segPos.reduce((m, p) => Math.max(m, U.dist(p.x, p.y, P.x, P.y)), CFG.HEAD_R * sc) + CFG.SEG_R * sc;
    c.save(); c.globalCompositeOperation = 'lighter';
    const g = c.createRadialGradient(P.x, P.y, 0, P.x, P.y, R * 1.6); g.addColorStop(0, `rgba(125,249,255,${0.35 * k})`); g.addColorStop(1, 'rgba(125,249,255,0)'); c.fillStyle = g; c.beginPath(); c.arc(P.x, P.y, R * 1.6, 0, TAU); c.fill();
    c.strokeStyle = `rgba(200,255,255,${0.9 * k})`; c.lineWidth = 2.5; c.lineCap = 'round';
    for (const p of P.segPos) { const a = Math.atan2(p.y - P.y, p.x - P.x), L = (14 + 10 * Math.random()) * sc * k, r0 = CFG.SEG_R * sc; c.beginPath(); c.moveTo(p.x + Math.cos(a) * r0, p.y + Math.sin(a) * r0); const mx = p.x + Math.cos(a) * (r0 + L * 0.5) + U.rand(-4, 4), my = p.y + Math.sin(a) * (r0 + L * 0.5) + U.rand(-4, 4); c.lineTo(mx, my); c.lineTo(p.x + Math.cos(a) * (r0 + L), p.y + Math.sin(a) * (r0 + L)); c.stroke(); }
    c.restore();
  },
  // Will of the Lich: each segment off hunting as a flaming skull (bone white, dark sockets, flames licking back off its flight)
  drawLichSkulls() {
    const L = G.lich; if (!L) return; const c = this.ctx;
    for (const s of L.skulls) {
      const a = Math.atan2(s.vy, s.vx), back = a + Math.PI, f = 0.6 + 0.4 * Math.sin(G.time * 18 + s.seed);
      c.save(); c.globalCompositeOperation = 'lighter';
      for (let k = 0; k < 3; k++) { const sp = (k - 1) * 0.45, L2 = 18 + 10 * f + (k === 1 ? 8 : 0), bx = s.x + Math.cos(back + sp) * L2, by = s.y + Math.sin(back + sp) * L2; c.fillStyle = k === 1 ? 'rgba(255,209,102,0.85)' : 'rgba(255,138,61,0.75)'; c.beginPath(); c.moveTo(s.x + Math.cos(back + sp + 1.2) * 9, s.y + Math.sin(back + sp + 1.2) * 9); c.lineTo(bx, by); c.lineTo(s.x + Math.cos(back + sp - 1.2) * 9, s.y + Math.sin(back + sp - 1.2) * 9); c.closePath(); c.fill(); }
      c.restore();
      this.circle(s.x, s.y, 12, '#eae4d4', this.OUT, 2.5); c.fillStyle = '#eae4d4'; c.strokeStyle = this.OUT; c.lineWidth = 2; this.roundRect(s.x - 6, s.y + 5, 12, 7, 2); c.fill(); c.stroke(); // the cranium and the jaw
      for (const sx of [-1, 1]) { c.fillStyle = '#2a1a10'; c.beginPath(); c.arc(s.x + sx * 4.5, s.y - 1, 3.4, 0, TAU); c.fill(); c.fillStyle = `rgba(255,138,61,${f})`; c.beginPath(); c.arc(s.x + sx * 4.5, s.y - 1, 1.6, 0, TAU); c.fill(); } // sockets, with a fire in each
    }
  },
  drawPlayer() {
    const c = this.ctx, P = Player, sc = P.scale();
    if (P.invuln > 0 && !(P.giant > 0) && !P.flight && !G.bolt && !G.lich && Math.floor(G.time * 14) % 2 === 0) c.globalAlpha = 0.45; // (a mega's untouchable spell does not blink)
    if (P.flight) { for (let k = 1; k <= 4; k++) { c.fillStyle = `rgba(255,255,255,${0.3 - k * 0.06})`; c.beginPath(); c.arc(P.x - Math.cos(P.flight.a) * k * 22, P.y - Math.sin(P.flight.a) * k * 22, CFG.HEAD_R * (1 + k * 0.15), 0, TAU); c.fill(); } } // the cannonball's trail
    // shadow
    c.fillStyle = 'rgba(0,0,0,0.15)';
    const lift = P.ballK() * 22; // Lightning Storm: the ball rises, its shadow drops away below it
    if (!G.lich) for (const s of P.segPos) { c.beginPath(); c.ellipse(s.x + 3 + lift * 0.4, s.y + 7 * sc + lift, CFG.SEG_R * sc, CFG.SEG_R * sc * 0.5, 0, 0, TAU); c.fill(); }
    c.beginPath(); c.ellipse(P.x + 3 + lift * 0.4, P.y + 8 * sc + lift, CFG.HEAD_R * sc, CFG.HEAD_R * sc * 0.5, 0, 0, TAU); c.fill();
    if (G.bolt) this.drawBall(sc);
    if (P.giant > 0 && P.tractor) { this.drawTractor(P.x, P.y, P.angle, 1.4); if (!(P.invuln > 0 && Math.floor(G.time * 14) % 2 === 0)) c.globalAlpha = 1; } // on the tractor the body rides behind it
    // segments, tail first (none while they are off as the Lich's skulls)
    for (let i = G.lich ? -1 : P.segments.length - 1; i >= 0; i--) {
      const s = P.segments[i], p = P.segPos[i];
      if (!p) continue;
      const r = CFG.SEG_R * sc;
      this.legs(p.x, p.y, p.a, r, i);
      const col = P.segColor(s);
      this.circle(p.x, p.y, r, col, this.OUT, 3);
      this.shine(p.x, p.y, r);
      if (s.jam) { c.fillStyle = `rgba(90,40,140,${0.35 + 0.2 * Math.sin(G.time * 6 + i)})`; c.beginPath(); c.arc(p.x, p.y, r, 0, TAU); c.fill(); } // drained while a parasite feeds ahead of it
      const w = BULLETS[s.id];
      // a loaded segment shows a dot; an empty one (bullet in flight) shows a ring; a jammed one shows an X
      c.fillStyle = w.dark; c.strokeStyle = w.dark; c.lineWidth = 2;
      if (w.minion) { // a riding minion: eight little legs, red eyes, a green collar
        c.strokeStyle = this.OUT; c.lineWidth = 2; c.lineCap = 'round'; const fx = Math.cos(p.a), fy = Math.sin(p.a), px = -Math.sin(p.a), py = Math.cos(p.a);
        for (let k = -1.5; k <= 1.5; k++) for (const sg of [-1, 1]) { const kk = Math.sin(G.time * 14 + k * 1.3 + (sg > 0 ? 0 : Math.PI)) * 3, bx = p.x + fx * k * 4, by = p.y + fy * k * 4; c.beginPath(); c.moveTo(bx + px * sg * 6, by + py * sg * 6); c.lineTo(bx + px * sg * 14 + fx * kk, by + py * sg * 14 + fy * kk); c.stroke(); }
        c.strokeStyle = '#9bf06a'; c.lineWidth = 2; c.beginPath(); c.arc(p.x, p.y, r + 2, 0, TAU); c.stroke();
        this.eyes(p.x, p.y, p.a, r, { pupil: '#ff3a3a', spread: 4, fwd: 3, er: 3 });
      }
      else if (s.jam) { c.beginPath(); c.moveTo(p.x - 4, p.y - 4); c.lineTo(p.x + 4, p.y + 4); c.moveTo(p.x + 4, p.y - 4); c.lineTo(p.x - 4, p.y + 4); c.stroke(); }
      else { c.beginPath(); c.arc(p.x, p.y, r * 0.32, 0, TAU); if (s.inflight > 0) c.stroke(); else c.fill(); }
      if (s.para) this.drawRider(s.para, p.x, p.y, p.a, i);
    }
    // head
    const a = P.angle, HR = CFG.HEAD_R * sc;
    if (P.tractor && P.giant > 0) { c.globalAlpha = 1; return; } // (the tractor is the head for now)
    // antennae
    c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.lineCap = 'round';
    for (const s of [-1, 1]) {
      const bx = P.x + Math.cos(a) * HR * 0.6 - Math.sin(a) * s * HR * 0.5, by = P.y + Math.sin(a) * HR * 0.6 + Math.cos(a) * s * HR * 0.5;
      const wig = Math.sin(G.time * 8 + s) * 0.3;
      const tx = bx + Math.cos(a + s * (0.7 + wig)) * 16 * sc, ty = by + Math.sin(a + s * (0.7 + wig)) * 16 * sc;
      c.beginPath(); c.moveTo(bx, by); c.quadraticCurveTo(bx + Math.cos(a) * 10, by + Math.sin(a) * 10, tx, ty); c.stroke();
      this.circle(tx, ty, 3 * sc, '#ffd166', this.OUT, 2);
    }
    this.legs(P.x, P.y, a, HR, -1);
    const last = !P.segments.length && P.alive, blink = last && Math.floor(G.time * 5) % 2 === 0; // the last stand: a bare head blinks red
    this.circle(P.x, P.y, HR, P.shroom > 0 ? `hsl(${(G.time * 300) % 360},90%,65%)` : blink ? '#ff4a4a' : '#ffb86b', this.OUT, 3.5);
    this.shine(P.x, P.y, HR);
    this.blush(P.x, P.y, a, HR);
    if (P.shroom > 0) { for (const s of [-1, 1]) { const ex = P.x - Math.sin(a) * s * HR * 0.5 + Math.cos(a) * HR * 0.2, ey = P.y + Math.cos(a) * s * HR * 0.5 + Math.sin(a) * HR * 0.2; c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); for (let t = 0; t < 12; t += 0.3) c.lineTo(ex + Math.cos(t + G.time * 10) * t * 0.4, ey + Math.sin(t + G.time * 10) * t * 0.4); c.stroke(); } } // spiral eyes
    else this.eyes(P.x, P.y, a, HR, { spread: HR * 0.5, fwd: HR * 0.2, er: HR * 0.36 });
    this.mouth(P.x, P.y, a, HR, true);
    if (G.mods && G.mods.hardHead > 0 && !P.gear) this.drawHelmet(P.x, P.y, a, HR, G.mods.hardHead, P.bonkAt);
    if (P.gear) this.drawGear(P.x, P.y, a, HR, P.gear, P.gearCharge || 0); // head gear (player-gear.js)
    if (P.giant > 0) { c.strokeStyle = `rgba(255,209,102,${0.4 + 0.3 * Math.sin(G.time * 10)})`; c.lineWidth = 4; c.beginPath(); c.arc(P.x, P.y, HR + 10, 0, TAU); c.stroke(); }
    if (last) { const k = (G.time * 1.6) % 1; c.globalAlpha = 1; c.strokeStyle = `rgba(255,74,74,${0.8 * (1 - k)})`; c.lineWidth = 3; c.beginPath(); c.arc(P.x, P.y, HR + 6 + k * 26, 0, TAU); c.stroke(); } // and a ring pulses out of it
    c.globalAlpha = 1;
  },

  // Fast and Hard: a steel cap over the back of the head, heavier with each copy (1: the cap, 2: a brim band and rivets,
  // 3+: a gold crest). It flashes white when it saves you (bonkAt: when it last did)
  drawHelmet(x, y, a, HR, n, bonkAt) {
    const c = this.ctx, fl = bonkAt != null ? Math.max(0, 1 - (G.time - bonkAt) / 0.3) : 0, R = HR + 1.5;
    const fx = Math.cos(a), fy = Math.sin(a), px = -fy, py = fx, back = 0.28 * HR; // the cap's front edge sits just behind the eyes
    const at = (f, s) => [x + fx * f + px * s, y + fy * f + py * s];
    const h = Math.sqrt(Math.max(0, R * R - back * back)), e0 = Math.atan2(-back * fy + h * py, -back * fx + h * px), e1 = Math.atan2(-back * fy - h * py, -back * fx - h * px);
    c.save();
    c.beginPath(); c.moveTo(...at(-back, h)); c.arc(x, y, R, e0, e1, false); c.closePath(); // the dome: everything behind the brim line
    const g = c.createLinearGradient(...at(-R, -R), ...at(0, R)); g.addColorStop(0, '#8d96a3'); g.addColorStop(0.5, '#c9d1dc'); g.addColorStop(1, '#f2f5f9');
    c.fillStyle = g; c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.stroke();
    if (n >= 2) { // a brim band and rivets
      c.strokeStyle = '#59606b'; c.lineWidth = 3.5; c.beginPath(); c.moveTo(...at(-back - 2, h - 1)); c.lineTo(...at(-back - 2, -h + 1)); c.stroke();
      for (const s of [-0.55, 0.55]) { const [rx, ry] = at(-back - HR * 0.4, s * HR); this.circle(rx, ry, 1.8, '#e8ecf2', this.OUT, 1); }
    }
    if (n >= 3) { const [cx0, cy0] = at(-R + 1, 0), [cx1, cy1] = at(-back - 1, 0); c.strokeStyle = this.OUT; c.lineWidth = 5; c.beginPath(); c.moveTo(cx0, cy0); c.lineTo(cx1, cy1); c.stroke(); c.strokeStyle = '#ffd166'; c.lineWidth = 2.5; c.stroke(); } // a gold crest down the middle
    c.fillStyle = 'rgba(255,255,255,0.35)'; c.beginPath(); const [hx, hy] = at(-HR * 0.45, -HR * 0.35); c.ellipse(hx, hy, HR * 0.28, HR * 0.14, a + Math.PI / 2, 0, TAU); c.fill(); // a glint
    if (fl > 0) { c.globalAlpha = fl; c.fillStyle = '#fff'; c.beginPath(); c.moveTo(...at(-back, h)); c.arc(x, y, R, e0, e1, false); c.closePath(); c.fill(); c.globalAlpha = 1; c.strokeStyle = `rgba(255,240,180,${fl})`; c.lineWidth = 3; c.beginPath(); c.arc(x, y, HR + 6 + (1 - fl) * 14, 0, TAU); c.stroke(); } // CLANG
    c.restore();
  },
  // head gear on the head, facing a: the Flamethrower's petals ring the face, the Bone Helmet caps it in bone, the Thundercap
  // and the Mushroom Cap sit on it like toadstools. charge (s held, player-gear.js) glows round it, pulsing once full
  drawGear(x, y, a, HR, id, charge) {
    const c = this.ctx, B = BULLETS[id], t = G.time, fx = Math.cos(a), fy = Math.sin(a), px = -fy, py = fx, at = (f, s) => [x + fx * f + px * s, y + fy * f + py * s];
    c.save();
    if (charge > 0) { const k = Math.min(1, charge / CFG.GEAR.charge.max), full = k >= 1, pul = full ? 0.5 + 0.5 * Math.sin(t * 14) : 0; c.shadowColor = B.color; c.shadowBlur = 8 + 18 * k + 8 * pul; c.strokeStyle = B.color; c.globalAlpha = 0.35 + 0.5 * k; c.lineWidth = 2 + 3 * k; c.beginPath(); c.arc(x, y, HR + 6 + 6 * k + 3 * pul, -Math.PI / 2, -Math.PI / 2 + TAU * k); c.stroke(); c.shadowBlur = 0; c.globalAlpha = 1; }
    const type = B.gear.type;
    if (id === 'pumpkin') { // a jack-o'-lantern: an orange pumpkin over the head with a stem, its carved face glowing toward where you go
      const [cx, cy] = at(-HR * 0.1, 0), R = HR * 1.25, flick = 0.75 + 0.25 * Math.sin(t * 18) * Math.sin(t * 7);
      c.fillStyle = '#ff9a2a'; c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.beginPath(); c.ellipse(cx, cy, R * 1.02, R, a, 0, TAU); c.fill(); c.stroke();
      c.strokeStyle = 'rgba(160,80,10,0.7)'; c.lineWidth = 1.5; for (const s2 of [-0.45, 0, 0.45]) { c.beginPath(); c.ellipse(...at(-HR * 0.1, s2 * R * 0.9), R * 0.95, R * 0.28, a, 0, TAU); c.stroke(); } // the ribs
      const [sx, sy] = at(-R * 1.05, 0); c.fillStyle = '#4a8a2a'; c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.beginPath(); c.ellipse(sx, sy, HR * 0.18, HR * 0.3, a, 0, TAU); c.fill(); c.stroke(); // the stem
      c.fillStyle = `rgb(255,${200 + 40 * flick},${60 + 60 * flick})`; c.strokeStyle = '#3a1800'; c.lineWidth = 2; c.shadowColor = '#ffd166'; c.shadowBlur = 8 + 6 * flick;
      for (const s2 of [-1, 1]) { const [ex, ey] = at(R * 0.35, s2 * R * 0.35); c.beginPath(); c.moveTo(...at(R * 0.2, s2 * R * 0.22)); c.lineTo(...at(R * 0.5, s2 * R * 0.35)); c.lineTo(...at(R * 0.2, s2 * R * 0.5)); c.closePath(); c.stroke(); c.fill(); } // triangle eyes
      c.beginPath(); c.moveTo(...at(R * 0.62, -R * 0.4)); for (let q = 0; q <= 6; q++) c.lineTo(...at(R * (q % 2 ? 0.55 : 0.68), -R * 0.4 + q * R * 0.8 / 6)); c.lineTo(...at(R * 0.82, R * 0.3)); c.lineTo(...at(R * 0.82, -R * 0.3)); c.closePath(); c.stroke(); c.fill(); c.shadowBlur = 0; // a jagged grin
    } else if (id === 'santahat') { // a Santa hat: a red cone flopping back off the head, white trim and a bobbing pom-pom
      const [bx, by] = at(-HR * 0.2, 0), sway = Math.sin(t * 5) * 0.25, [tx, ty] = [x - fx * HR * 2.1 + px * HR * sway, y - fy * HR * 2.1 + py * HR * sway];
      c.fillStyle = '#ff4a4a'; c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.beginPath(); c.moveTo(...at(-HR * 0.2, -HR * 0.95)); c.quadraticCurveTo(x - fx * HR * 1.4 - px * HR * 0.6, y - fy * HR * 1.4 - py * HR * 0.6, tx, ty); c.quadraticCurveTo(x - fx * HR * 1.3 + px * HR * 0.7, y - fy * HR * 1.3 + py * HR * 0.7, ...at(-HR * 0.2, HR * 0.95)); c.closePath(); c.fill(); c.stroke();
      c.strokeStyle = '#fff'; c.lineWidth = HR * 0.35; c.lineCap = 'round'; c.beginPath(); c.moveTo(...at(-HR * 0.15, -HR * 0.95)); c.quadraticCurveTo(bx + fx * HR * 0.15, by + fy * HR * 0.15, ...at(-HR * 0.15, HR * 0.95)); c.stroke(); // the trim
      this.circle(tx, ty, HR * 0.28, '#fff', this.OUT, 1.5); // the pom-pom
    } else if (type === 'hammer') { // the Front Hammer: a steel block across the face on a short haft, glowing with the borrowed element
      const el = Player.hammerEl && Player.hammerEl(), glow = el ? synInfo(el).color : null, [hx, hy] = at(HR * 1.15, 0), L = HR * 1.25, Wd = HR * 0.55;
      c.strokeStyle = '#8a5a3a'; c.lineWidth = HR * 0.22; c.lineCap = 'round'; c.beginPath(); c.moveTo(...at(HR * 0.2, 0)); c.lineTo(hx, hy); c.stroke(); // the haft
      if (glow) { c.shadowColor = glow; c.shadowBlur = 12 + 4 * Math.sin(t * 6); }
      c.save(); c.translate(hx, hy); c.rotate(a); const g = c.createLinearGradient(-Wd / 2, -L, Wd / 2, L); g.addColorStop(0, '#dfe5ec'); g.addColorStop(0.5, '#a9b0bc'); g.addColorStop(1, '#6b7380');
      c.fillStyle = g; c.strokeStyle = glow || this.OUT; c.lineWidth = glow ? 3 : 2.5; this.roundRect(-Wd / 2, -L, Wd, L * 2, 3); c.fill(); c.stroke(); c.restore(); c.shadowBlur = 0;
    } else if (type === 'cone') { // a flower: petals round the face, open toward where you go
      for (let i = 0; i < 7; i++) { const ang = a + (i - 3) * 0.42, w = Math.sin(t * 6 + i) * 0.05, [ex, ey] = [x + Math.cos(ang + w) * HR * 1.05, y + Math.sin(ang + w) * HR * 1.05]; c.fillStyle = i % 2 ? '#ffd166' : '#ff8a3d'; c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.beginPath(); c.ellipse(ex, ey, HR * 0.38, HR * 0.2, ang, 0, TAU); c.fill(); c.stroke(); }
    } else if (type === 'ram') { // a bone helmet: a cream dome over the back of the head with a crack and two little horns
      const R = HR + 1.5, back = 0.28 * HR, h = Math.sqrt(R * R - back * back), e0 = Math.atan2(-back * fy + h * py, -back * fx + h * px), e1 = Math.atan2(-back * fy - h * py, -back * fx - h * px);
      c.fillStyle = '#eae4d4'; c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.beginPath(); c.moveTo(...at(-back, h)); c.arc(x, y, R, e0, e1, false); c.closePath(); c.fill(); c.stroke();
      c.strokeStyle = 'rgba(120,100,70,0.8)'; c.lineWidth = 1.2; c.beginPath(); c.moveTo(...at(-R * 0.85, -R * 0.1)); c.lineTo(...at(-R * 0.55, R * 0.12)); c.lineTo(...at(-R * 0.35, -R * 0.05)); c.stroke();
      for (const s2 of [-1, 1]) { const [hx, hy] = at(-back, s2 * h * 0.92); const [tx, ty] = at(-back + HR * 0.15, s2 * (h + HR * 0.45)); c.fillStyle = '#f4efe2'; c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.beginPath(); c.moveTo(hx - fx * 3, hy - fy * 3); c.lineTo(tx, ty); c.lineTo(hx + fx * 3, hy + fy * 3); c.closePath(); c.fill(); c.stroke(); }
    } else { // a toadstool cap over the back of the head: blue with a bolt (Thundercap), purple with green spots (Mushroom Cap)
      const thunder = type === 'arc', [cx, cy] = at(-HR * 0.25, 0), R = HR * 1.1;
      c.fillStyle = thunder ? '#3a8ad8' : '#8a3fc4'; c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.beginPath(); c.ellipse(cx, cy, R * 0.75, R, a, Math.PI / 2, Math.PI * 1.5); c.closePath(); c.fill(); c.stroke();
      if (thunder) { const flick = Math.floor(t * 8) % 5 === 0; c.strokeStyle = flick ? '#fff' : '#ffd166'; c.lineWidth = 2.5; c.beginPath(); c.moveTo(...at(-HR * 0.75, -HR * 0.35)); c.lineTo(...at(-HR * 0.45, 0.05 * HR)); c.lineTo(...at(-HR * 0.7, 0.1 * HR)); c.lineTo(...at(-HR * 0.4, HR * 0.45)); c.stroke(); }
      else for (const [f, s2, rr] of [[-0.6, -0.4, 0.16], [-0.75, 0.3, 0.13], [-0.4, 0.15, 0.11]]) { const [sx, sy] = at(HR * f, HR * s2); this.circle(sx, sy, HR * rr, '#9bf06a', this.OUT, 1); }
    }
    c.restore();
  },
  drawRider(para, x, y, a, i) {
    const c = this.ctx, Pz = PARASITES[para.id], k = para.t / para.hold, seed = para.seed || 0;
    const ph = (G.time * 2.2 + seed) % 1;                       // one suck per ~0.45s
    const swell = 1 + 0.3 * Math.pow(Math.sin(ph * Math.PI), 3);  // quick gulp, slow settle
    const fx = Math.cos(a), fy = Math.sin(a), px = -Math.sin(a), py = Math.cos(a), ry = y - 3;
    // legs clamp in time with the gulp
    c.strokeStyle = this.OUT; c.lineWidth = 2; c.lineCap = 'round';
    const grip = 12 - 3 * (swell - 1) / 0.3;
    for (let j = -1; j <= 1; j += 2) for (const s of [-1, 1]) { c.beginPath(); c.moveTo(x + fx * j * 3, ry + fy * j * 3); c.lineTo(x + fx * j * 5 + px * s * grip, ry + fy * j * 5 + py * s * grip); c.stroke(); }
    // droplets drawn out of the segment into the body
    const segCol = BULLETS[Player.segments[i].id].color;
    for (let d = 0; d < 3; d++) { const t = ((G.time * 1.6 + seed + d * 0.33) % 1), ang = a + Math.PI / 2 + d * 2.1, dist = (1 - t) * 11; c.globalAlpha = 0.9 * (1 - t); c.fillStyle = segCol; c.beginPath(); c.arc(x + Math.cos(ang) * dist, ry + Math.sin(ang) * dist, 2.2 + t * 1.5, 0, TAU); c.fill(); }
    c.globalAlpha = 1;
    if (World.regions[World.regionAt(x)].farm) { // a sheep clamped onto the segment, chewing
      c.fillStyle = '#f3efe6'; c.strokeStyle = this.OUT; c.lineWidth = 2; for (let j = 0; j < 5; j++) { const ang = j * TAU / 5 + seed; c.beginPath(); c.arc(x - fx * 3 + Math.cos(ang) * 4 * swell, ry - fy * 3 + Math.sin(ang) * 3 * swell, 4.5 * swell, 0, TAU); c.fill(); c.stroke(); }
      c.strokeStyle = Pz.color; c.lineWidth = 2; c.beginPath(); c.ellipse(x - fx * 3, ry - fy * 3, 6 * swell, 4.5 * swell, a, 0, TAU); c.stroke();
      this.circle(x + fx * 6, ry + fy * 6, 4, '#2b1d3a', this.OUT, 1.5); this.eyes(x + fx * 6, ry + fy * 6, a, 4, { pupil: '#ff3a3a', spread: 2.2, fwd: 1.2, er: 1.6 });
      c.strokeStyle = Pz.color; c.lineWidth = 2; c.beginPath(); c.arc(x, y, CFG.SEG_R + 5, -Math.PI / 2, -Math.PI / 2 + TAU * k); c.stroke();
      return;
    }
    // body: swollen abdomen behind, head in front with pinching mandibles
    c.fillStyle = Pz.color; c.strokeStyle = this.OUT; c.lineWidth = 2.5;
    c.beginPath(); c.ellipse(x - fx * 3, ry - fy * 3, 8 * swell, 6.5 * swell, a, 0, TAU); c.fill(); c.stroke();
    c.fillStyle = Pz.dark; c.beginPath(); c.ellipse(x - fx * 4, ry - fy * 4, 4 * swell, 3 * swell, a, 0, TAU); c.fill(); // belly spot
    c.fillStyle = Pz.color; c.beginPath(); c.arc(x + fx * 5, ry + fy * 5, 4.5, 0, TAU); c.fill(); c.stroke();
    const pinch = 0.35 + 0.35 * (swell - 1) / 0.3;
    for (const s of [-1, 1]) { c.beginPath(); c.moveTo(x + fx * 7 + px * s * 3, ry + fy * 7 + py * s * 3); c.lineTo(x + fx * 11 + px * s * 3 * pinch, ry + fy * 11 + py * s * 3 * pinch); c.stroke(); }
    this.eyes(x + fx * 5, ry + fy * 5, a, 5, { pupil: '#ff3a3a', spread: 2.6, fwd: 1.5, er: 2 });
    // time left as a shrinking purple arc
    c.strokeStyle = Pz.color; c.lineWidth = 2; c.beginPath(); c.arc(x, y, CFG.SEG_R + 5, -Math.PI / 2, -Math.PI / 2 + TAU * k); c.stroke();
  },
});
