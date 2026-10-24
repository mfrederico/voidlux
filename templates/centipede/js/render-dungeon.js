// The dungeon: maze walls and floor, dots and pellets, the exit, the Shovel and the Siphon.
// Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  // the maze's item counter (shovel digs, hammer time): drawn after the vignette, so it is never darkened
  drawDungeonLabels() {
    const D = Dungeon, C = D.cell, items = [];
    if (Player.digs > 0) items.push({ icon: 'shovel', color: '#ffd166', text: `×${Player.digs}` });
    if (Player.hammerT > 0) items.push({ icon: 'hammer', color: '#ff5c8a', text: `HAMMER TIME  ${Math.ceil(Player.hammerT)}s` });
    if (items.length) Icons.strip(items, CFG.W / 2, D.oy - 14, Math.max(9, C * 0.18), 'center');
  },
  // ---- dungeon ----
  drawDungeon() {
    const c = this.ctx, D = Dungeon, cath = D.theme() === 'cathedral', temple = D.theme() === 'temple', ufo = D.theme() === 'ufo', top = D.theme() === 'bigtop';
    c.fillStyle = cath ? '#14121a' : temple ? '#1c1810' : ufo ? '#0e141c' : top ? '#1a0a1e' : '#1a1430'; c.fillRect(0, 0, this.viewW(), this.viewH());
    const C = D.cell;
    if (top) { // the big top: striped canvas overhead, sweeping stage lights, a ring in the middle
      c.save(); c.globalAlpha = 0.08; for (let i = 0; i < 24; i++) { c.fillStyle = i % 2 ? '#ff5c8a' : '#fff'; c.beginPath(); c.moveTo(CFG.W / 2, -200); c.lineTo(-400 + i * (CFG.W + 800) / 24, CFG.H + 50); c.lineTo(-400 + (i + 1) * (CFG.W + 800) / 24, CFG.H + 50); c.closePath(); c.fill(); } c.restore();
      for (let k = 0; k < 3; k++) { const a = G.time * (0.6 + k * 0.2) + k * 2, x = CFG.W / 2 + Math.cos(a) * CFG.W * 0.4, y = CFG.H / 2 + Math.sin(a * 1.4) * CFG.H * 0.4; const sp = c.createRadialGradient(x, y, 0, x, y, 180); sp.addColorStop(0, `hsla(${(k * 120 + G.time * 30) % 360},90%,70%,0.16)`); sp.addColorStop(1, 'rgba(255,255,255,0)'); c.fillStyle = sp; c.fillRect(0, 0, CFG.W, CFG.H); }
    }
    if (cath) { // shafts of coloured light through the high windows
      c.save(); c.globalAlpha = 0.07;
      for (let k = 0; k < 4; k++) { c.fillStyle = ['#c9b8ff', '#ffd6a0', '#a0e0ff', '#ffb0c8'][k]; c.beginPath(); const x = D.ox + k * (D.cols * C) / 4 + 60; c.moveTo(x, D.oy - 40); c.lineTo(x + 140, D.oy - 40); c.lineTo(x + 320, D.oy + D.rows * C + 40); c.lineTo(x + 180, D.oy + D.rows * C + 40); c.closePath(); c.fill(); }
      c.restore();
    }
    for (let r = 0; r < D.rows; r++) for (let col = 0; col < D.cols; col++) {
      const x = D.ox + col * C, y = D.oy + r * C;
      if (D.grid[r][col] === 1) {
        if (cath) { // stone pillars with a pointed arch cut into the face, candles here and there
          c.fillStyle = '#4a4752'; this.roundRect(x + 1, y + 1, C - 2, C - 2, 3); c.fill();
          c.fillStyle = '#6b6775'; this.roundRect(x + 4, y + 4, C - 8, C - 14, 3); c.fill();
          c.fillStyle = '#2a2830'; c.beginPath(); c.moveTo(x + C * 0.3, y + C - 5); c.lineTo(x + C * 0.3, y + C * 0.5); c.quadraticCurveTo(x + C * 0.3, y + C * 0.22, x + C * 0.5, y + C * 0.18); c.quadraticCurveTo(x + C * 0.7, y + C * 0.22, x + C * 0.7, y + C * 0.5); c.lineTo(x + C * 0.7, y + C - 5); c.closePath(); c.fill();
          if ((r * 7 + col * 13) % 11 === 0) { const fl = 0.7 + 0.3 * Math.sin(G.time * 12 + r + col); c.fillStyle = '#e8e0c8'; c.fillRect(x + C * 0.46, y + C * 0.55, C * 0.08, C * 0.25); c.fillStyle = `rgba(255,200,90,${0.9 * fl})`; c.beginPath(); c.ellipse(x + C * 0.5, y + C * 0.5 - fl * 3, C * 0.05, C * 0.1 * fl, 0, 0, TAU); c.fill(); c.fillStyle = `rgba(255,200,90,${0.12 * fl})`; c.beginPath(); c.arc(x + C * 0.5, y + C * 0.5, C * 0.6, 0, TAU); c.fill(); } // a candle
        } else if (ufo) { // riveted metal panels, some with a blinking light
          c.fillStyle = '#3a4452'; this.roundRect(x + 1, y + 1, C - 2, C - 2, 4); c.fill();
          c.fillStyle = '#5a6678'; this.roundRect(x + 4, y + 4, C - 8, C - 10, 3); c.fill();
          c.fillStyle = '#8a96a8'; for (const [rx, ry] of [[0.18, 0.18], [0.82, 0.18], [0.18, 0.82], [0.82, 0.82]]) { c.beginPath(); c.arc(x + rx * C, y + ry * C, C * 0.05, 0, TAU); c.fill(); }
          if ((r * 7 + col * 13) % 7 === 0) { const on = (Math.floor(G.time * 3 + r + col) % 2) === 0; c.fillStyle = on ? '#ff5cf0' : '#7df9ff'; c.beginPath(); c.arc(x + C * 0.5, y + C * 0.5, C * 0.1, 0, TAU); c.fill(); c.fillStyle = on ? 'rgba(255,92,240,0.12)' : 'rgba(125,249,255,0.08)'; c.beginPath(); c.arc(x + C * 0.5, y + C * 0.5, C * 0.6, 0, TAU); c.fill(); }
        } else if (top) { // striped canvas blocks in wild colours, a fairground bulb on some
          const hue = ((r * 37 + col * 61) % 6) * 60 + G.time * 20;
          c.fillStyle = `hsl(${hue % 360},70%,28%)`; this.roundRect(x + 1, y + 1, C - 2, C - 2, 6); c.fill();
          c.save(); c.beginPath(); this.roundRect(x + 4, y + 4, C - 8, C - 10, 5); c.clip(); c.fillStyle = `hsl(${hue % 360},85%,55%)`; c.fillRect(x, y, C, C); c.fillStyle = 'rgba(255,255,255,0.75)'; for (let s = -C; s < C * 2; s += C * 0.4) c.fillRect(x + s, y, C * 0.18, C); c.restore();
          if ((r * 7 + col * 13) % 5 === 0) { const on = (Math.floor(G.time * 4 + r + col) % 2) === 0; c.fillStyle = on ? '#fff' : '#ffd166'; c.beginPath(); c.arc(x + C * 0.5, y + C * 0.5, C * 0.1, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.stroke(); if (on) { c.fillStyle = 'rgba(255,255,255,0.1)'; c.beginPath(); c.arc(x + C * 0.5, y + C * 0.5, C * 0.6, 0, TAU); c.fill(); } }
        } else if (temple) { // sandstone blocks carved with a step-fret, torches here and there
          c.fillStyle = '#6b5f3a'; this.roundRect(x + 1, y + 1, C - 2, C - 2, 3); c.fill();
          c.fillStyle = '#9a8a58'; this.roundRect(x + 4, y + 4, C - 8, C - 12, 3); c.fill();
          c.strokeStyle = '#5a4e2e'; c.lineWidth = 1.5; c.beginPath(); c.moveTo(x + C * 0.25, y + C * 0.65); c.lineTo(x + C * 0.25, y + C * 0.4); c.lineTo(x + C * 0.5, y + C * 0.4); c.lineTo(x + C * 0.5, y + C * 0.25); c.lineTo(x + C * 0.75, y + C * 0.25); c.stroke(); // the fret
          if ((r * 7 + col * 13) % 9 === 0) { const fl = 0.7 + 0.3 * Math.sin(G.time * 12 + r + col); c.fillStyle = '#5a3a1a'; c.fillRect(x + C * 0.45, y + C * 0.5, C * 0.1, C * 0.3); c.fillStyle = `rgba(255,170,60,${0.9 * fl})`; c.beginPath(); c.ellipse(x + C * 0.5, y + C * 0.45 - fl * 3, C * 0.07, C * 0.12 * fl, 0, 0, TAU); c.fill(); c.fillStyle = `rgba(255,170,60,${0.12 * fl})`; c.beginPath(); c.arc(x + C * 0.5, y + C * 0.45, C * 0.7, 0, TAU); c.fill(); } // a torch
        } else {
          c.fillStyle = '#3a2a6e'; this.roundRect(x + 2, y + 2, C - 4, C - 4, 8); c.fill();
          c.fillStyle = '#5a46a8'; this.roundRect(x + 5, y + 5, C - 10, C - 16, 7); c.fill();
        }
      } else {
        c.fillStyle = D.grid[r][col] === 2 ? (cath ? '#2d2a3a' : temple ? '#3a4a2a' : ufo ? '#2a4a3a' : top ? '#4a1a3a' : '#2d4a2a') : cath ? ((r + col) % 2 ? '#1e1b26' : '#242030') : temple ? ((r + col) % 2 ? '#2a2416' : '#30291a') : ufo ? ((r + col) % 2 ? '#18202a' : '#1c2530') : top ? ((r + col) % 2 ? '#24102a' : '#2c1434') : '#241c42'; c.fillRect(x, y, C, C);
        const d = D.dots[r] ? D.dots[r][col] : 0;
        if (d === 1) { if (cath) { c.fillStyle = 'rgba(255,220,120,0.12)'; c.beginPath(); c.arc(x + C / 2, y + C / 2, 7, 0, TAU); c.fill(); } if (ufo) { c.fillStyle = 'rgba(155,240,106,0.15)'; c.beginPath(); c.arc(x + C / 2, y + C / 2, 7, 0, TAU); c.fill(); } if (top) { c.fillStyle = '#fff5e0'; c.strokeStyle = this.OUT; c.lineWidth = 1.2; for (const [dx, dy] of [[-3, 1], [3, 1], [0, -3]]) { c.beginPath(); c.arc(x + C / 2 + dx, y + C / 2 + dy, 3.2, 0, TAU); c.fill(); c.stroke(); } } else this.circle(x + C / 2, y + C / 2, 4, temple ? '#5fd9a8' : ufo ? '#9bf06a' : '#ffe9a8'); } // (popcorn in the big top: still a dot, still a coin)
        else if (d === 2) { const p = 7 + Math.sin(G.time * 6) * 2; if (top) { c.fillStyle = '#ffb3d9'; c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); for (let i = 0; i < 8; i++) { const a = i * TAU / 8 + G.time, rr = p + (i % 2) * 3; c.lineTo(x + C / 2 + Math.cos(a) * rr, y + C / 2 + Math.sin(a) * rr); } c.closePath(); c.fill(); c.stroke(); c.fillStyle = '#fff'; c.fillRect(x + C / 2 - 1.5, y + C / 2, 3, p + 6); } else { this.circle(x + C / 2, y + C / 2, p, '#ffd166', this.OUT, 2); this.shine(x + C / 2, y + C / 2, p); } } // (cotton candy)
      }
    }
    if (cath) { // the rose window on the floor of the ghosts' pen (the altar)
      const px = D.cx(D.pen.c), py = D.cy(D.pen.r), R = C * 1.3;
      c.save(); c.globalAlpha = 0.45;
      for (let i = 0; i < 8; i++) { c.fillStyle = ['#c9b8ff', '#ffd6a0', '#a0e0ff', '#ffb0c8'][i % 4]; c.beginPath(); c.moveTo(px, py); c.arc(px, py, R, i * TAU / 8, (i + 1) * TAU / 8); c.closePath(); c.fill(); }
      c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.arc(px, py, R, 0, TAU); c.stroke(); c.beginPath(); c.arc(px, py, R * 0.4, 0, TAU); c.stroke();
      for (let i = 0; i < 8; i++) { c.beginPath(); c.moveTo(px, py); c.lineTo(px + Math.cos(i * TAU / 8) * R, py + Math.sin(i * TAU / 8) * R); c.stroke(); }
      c.restore();
    }
    if (ufo) { // the reactor core at the pen
      const px = D.cx(D.pen.c), py = D.cy(D.pen.r), R = C * 1.2, g = 0.5 + 0.5 * Math.sin(G.time * 3);
      c.save(); c.fillStyle = `rgba(125,249,255,${0.08 + 0.06 * g})`; c.beginPath(); c.arc(px, py, R * 1.6, 0, TAU); c.fill();
      c.strokeStyle = '#7df9ff'; c.lineWidth = 3; c.setLineDash([12, 8]); c.lineDashOffset = -G.time * 40; c.beginPath(); c.arc(px, py, R, 0, TAU); c.stroke(); c.setLineDash([]);
      c.fillStyle = `rgba(125,249,255,${0.35 + 0.3 * g})`; c.beginPath(); c.arc(px, py, R * 0.45, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 2; c.stroke();
      c.restore();
    }
    if (temple) { // the sun stone on the floor of the pen (the altar)
      const px = D.cx(D.pen.c), py = D.cy(D.pen.r), R = C * 1.3;
      c.save(); c.globalAlpha = 0.5; c.fillStyle = '#b5a060'; c.beginPath(); c.arc(px, py, R, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 2; c.stroke();
      for (let i = 0; i < 16; i++) { const a = i * TAU / 16; c.beginPath(); c.moveTo(px + Math.cos(a) * R * 0.55, py + Math.sin(a) * R * 0.55); c.lineTo(px + Math.cos(a) * R * (i % 2 ? 0.85 : 1), py + Math.sin(a) * R * (i % 2 ? 0.85 : 1)); c.stroke(); }
      c.beginPath(); c.arc(px, py, R * 0.55, 0, TAU); c.stroke(); c.fillStyle = '#ff8a3d'; c.beginPath(); c.arc(px, py, R * 0.22, 0, TAU); c.fill(); c.stroke();
      c.restore();
    }
    if (top) { // the centre ring at the pen, and the high striker
      const px = D.cx(D.pen.c), py = D.cy(D.pen.r), R = C * 1.4;
      c.save(); c.globalAlpha = 0.5; c.strokeStyle = '#ffd166'; c.lineWidth = 5; c.beginPath(); c.arc(px, py, R, 0, TAU); c.stroke(); c.strokeStyle = '#ff5c8a'; c.lineWidth = 3; c.beginPath(); c.arc(px, py, R * 0.7, 0, TAU); c.stroke(); c.fillStyle = 'rgba(255,209,102,0.15)'; c.beginPath(); c.arc(px, py, R * 0.7, 0, TAU); c.fill(); c.restore();
      if (D.striker) { const S = D.striker, s = C / 64, h = C * 0.9, k = S.rung ? Math.max(0, S.t / 2.5) : 0; c.fillStyle = '#8a5a3a'; c.strokeStyle = this.OUT; c.lineWidth = 2.5 * s; c.fillRect(S.x - 4 * s, S.y - h, 8 * s, h + 8 * s); c.strokeRect(S.x - 4 * s, S.y - h, 8 * s, h + 8 * s); c.fillStyle = '#ffd166'; this.roundRect(S.x - 16 * s, S.y - 2 * s, 32 * s, 12 * s, 3 * s); c.fill(); c.stroke(); for (let i = 0; i < 4; i++) { c.fillStyle = ['#9bf06a', '#ffd166', '#ff8a3d', '#ff5c5c'][i]; c.fillRect(S.x - 3 * s, S.y - h * (0.2 + i * 0.2), 6 * s, h * 0.15); } this.circle(S.x, S.y - h - 6 * s, 7 * s, S.rung ? '#fff' : '#ffd166', this.OUT, 2 * s); if (k > 0) { c.fillStyle = '#ff5c5c'; c.fillRect(S.x - 4 * s, S.y - h * (1 - k * 0.1), 8 * s, 6 * s); this.text('DING!', S.x, S.y - h - 24 * s, Math.max(10, 12 * s), '#fff', 'center'); } else if (!S.rung) { c.fillStyle = '#ff5c5c'; c.fillRect(S.x - 4 * s, S.y - 6 * s, 8 * s, 6 * s); this.text('STRIKER', S.x, S.y + 20 * s, Math.max(9, 10 * s), '#ffd166', 'center'); } }
      for (const m of D.moles) { const s = C / 64, up = Math.min(1, m.t > 0.4 ? 1 : m.t / 0.4) * Math.min(1, (CFG.MOLE.up - m.t) * 4), r = 9 * s; c.fillStyle = '#3a2a1a'; c.beginPath(); c.ellipse(m.x, m.y + 6 * s, 13 * s, 6 * s, 0, 0, TAU); c.fill(); c.save(); c.beginPath(); c.rect(m.x - 20 * s, m.y - 40 * s, 40 * s, 46 * s); c.clip(); const my = m.y + 6 * s - up * 16 * s; this.circle(m.x, my, r, '#8a5a3a', this.OUT, 2 * s); c.fillStyle = '#ffb3c6'; c.beginPath(); c.ellipse(m.x, my + 2 * s, 3.5 * s, 2.5 * s, 0, 0, TAU); c.fill(); this.eyes(m.x, my - 2 * s, -Math.PI / 2, r, { spread: 4 * s, fwd: 0, er: 2.2 * s }); c.fillStyle = '#ffe9a8'; c.beginPath(); c.arc(m.x + 8 * s, my - 2 * s, 3 * s, 0, TAU); c.fill(); c.restore(); } // a mole holding the dot it is about to steal
      if (Player.hammerT > 0 && !(D.swingT > 0)) { const P = Player; c.save(); c.translate(P.x, P.y); c.rotate(Math.sin(G.time * 18) * 1.2); Icons.draw('hammer', 0, -C * 0.5, C * 0.35, '#ff5c8a'); c.restore(); } // held high between swings
      if (D.swingT > 0) { const k = D.swingT / 0.3, P = Player; for (const dy of [-1, 1]) { c.fillStyle = `rgba(255,92,138,${0.35 * k})`; c.fillRect(D.ox + P.gcol * C, D.oy + (P.grow + dy) * C, C, C); } c.save(); c.translate(P.x, P.y); c.rotate((1 - k) * Math.PI * 2 * (P.gdir.x || 1)); Icons.draw('hammer', 0, -C * 0.5, C * 0.35, '#ff5c8a'); c.restore(); } // the slam
    }
    for (const d of D.dug) { const x = D.ox + d.c * C, y = D.oy + d.r * C; c.fillStyle = 'rgba(255,255,255,0.06)'; c.fillRect(x, y, C, C); c.fillStyle = cath ? '#6b6775' : temple ? '#9a8a58' : top ? '#ff5c8a' : '#5a46a8'; for (const [rx, ry, rr] of [[0.25, 0.3, 0.1], [0.7, 0.65, 0.08], [0.5, 0.8, 0.07], [0.8, 0.25, 0.06]]) { c.beginPath(); c.arc(x + rx * C, y + ry * C, rr * C, 0, TAU); c.fill(); } } // rubble where a wall was dug out
    if (D.shovel && !D.shovel.taken) this.drawShovel(D.shovel);
    if (D.hammer && !D.hammer.taken) { const S = D.hammer, s = C / 64, bob = Math.sin(G.time * 3) * 2 * s; c.fillStyle = `rgba(255,92,138,${0.15 + 0.08 * Math.sin(G.time * 5)})`; c.beginPath(); c.arc(S.x, S.y, C * 0.45, 0, TAU); c.fill(); Icons.draw('hammer', S.x, S.y + bob, C * 0.3, '#ff5c8a'); this.text('HAMMER', S.x, S.y + C * 0.42 + 8 * s, Math.max(10, 11 * s), '#ff5c8a', 'center'); }
    if (D.siphon) this.drawSiphon(D.siphon);
    if (Player.stopped && G.mode === 'dungeon') { const k = 0.5 + 0.5 * Math.sin(G.time * 6); c.strokeStyle = `rgba(255,209,102,${0.35 + 0.4 * k})`; c.lineWidth = 3; c.beginPath(); c.arc(Player.x, Player.y, CFG.HEAD_R + 10 + k * 4, 0, TAU); c.stroke(); const P = Player, opts = [[0, -1], [0, 1], [-1, 0], [1, 0]].filter(([dx, dy]) => D.isFloor(P.grow + dy, P.gcol + dx) && !(D.exitT > 0 && D.grid[P.grow + dy][P.gcol + dx] === 2)); for (const [dx, dy] of opts) { c.fillStyle = `rgba(255,209,102,${0.5 + 0.4 * k})`; c.beginPath(); const ax = P.x + dx * C * 0.5, ay = P.y + dy * C * 0.5; c.moveTo(ax + dx * 7, ay + dy * 7); c.lineTo(ax - dy * 6, ay + dx * 6); c.lineTo(ax + dy * 6, ay - dx * 6); c.closePath(); c.fill(); } } // stopped at a wall: little arrows show the ways out
    const ex = D.cx(D.startCell.c), ey = D.cy(D.rows - 1);
    if (D.exitT > 0) { c.fillStyle = 'rgba(0,0,0,0.45)'; c.fillRect(ex - C / 2, ey - C / 2, C, C); this.text(`EXIT in ${Math.ceil(D.exitT)}`, ex, ey, 11, '#aaa', 'center'); } else this.text('EXIT', ex, ey, 12, '#9bf06a', 'center');
    if (D.fright > 0) { c.fillStyle = `rgba(120,200,255,${0.08 + 0.05 * Math.sin(G.time * 10)})`; c.fillRect(0, 0, this.viewW(), this.viewH()); }
  },

  drawShovel(S) {
    const c = this.ctx, C = Dungeon.cell, s = C / 64, bob = Math.sin(G.time * 3) * 2 * s;
    c.fillStyle = `rgba(255,209,102,${0.15 + 0.08 * Math.sin(G.time * 5)})`; c.beginPath(); c.arc(S.x, S.y, C * 0.45, 0, TAU); c.fill();
    Icons.draw('shovel', S.x, S.y + bob, C * 0.3, '#ffd166');
    this.text('SHOVEL', S.x, S.y + C * 0.42 + 8 * s, Math.max(10, 11 * s), '#ffd166', 'center');
  },
  // the Siphon: a squat brass pump with a gear, a nozzle and a lever. Idle = lever up; running = gear spins, dots fly in, sound rings; spent = grey and smoking.

  drawSiphon(S) {
    const c = this.ctx, C = Dungeon.cell, x = S.x, y = S.y, on = S.state === 'on', spent = S.state === 'spent', moving = S.state === 'moving', s = C / 64;
    if (on) { for (let k = 0; k < 3; k++) { const ph = (G.time * 0.9 + k / 3) % 1; c.strokeStyle = `rgba(255,179,71,${0.5 * (1 - ph)})`; c.lineWidth = 3; c.setLineDash([6, 8]); c.beginPath(); c.arc(x, y, C * 0.6 + ph * C * 2.2, 0, TAU); c.stroke(); c.setLineDash([]); } }
    for (const m of S.motes) { const k = 1 - m.t / m.d, e = k * k; this.circle(m.x + (x - m.x) * e, m.y + (y - m.y) * e, 4 + 2 * k, '#ffe9a8'); }
    const body = spent ? '#5a5560' : '#8a6a3a', trim = spent ? '#3b3740' : '#5a4322', w = C * 0.78, h = C * 0.62;
    c.fillStyle = 'rgba(0,0,0,0.2)'; c.beginPath(); c.ellipse(x + 2, y + h * 0.55, w * 0.5, h * 0.22, 0, 0, TAU); c.fill();
    if (on) { c.fillStyle = `rgba(255,209,102,${0.14 + 0.08 * Math.sin(G.time * 12)})`; c.beginPath(); c.arc(x, y, C * 0.72, 0, TAU); c.fill(); }
    c.fillStyle = trim; this.roundRect(x - w * 0.12, y - h / 2 - C * 0.22, w * 0.24, C * 0.26, 4 * s); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 2.5 * s; c.stroke();
    if (moving) for (const sx of [-1, 1]) { const wx = x + sx * w * 0.32, wy = y + h / 2 + 2 * s; this.circle(wx, wy, 6 * s, '#3b3740', this.OUT, 2 * s); c.strokeStyle = '#aaa'; c.lineWidth = 1.5 * s; c.beginPath(); c.moveTo(wx - Math.cos(S.spin) * 4 * s, wy - Math.sin(S.spin) * 4 * s); c.lineTo(wx + Math.cos(S.spin) * 4 * s, wy + Math.sin(S.spin) * 4 * s); c.stroke(); } // cart wheels
    c.fillStyle = body; this.roundRect(x - w / 2, y - h / 2, w, h, 8 * s); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 3 * s; c.stroke();
    const gr = h * 0.36;
    c.save(); c.translate(x, y); c.rotate(S.spin); c.fillStyle = spent ? '#8d8894' : '#ffd166';
    for (let i = 0; i < 6; i++) { c.rotate(TAU / 6); c.fillRect(-3 * s, -gr - 4 * s, 6 * s, 8 * s); }
    c.beginPath(); c.arc(0, 0, gr, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 2.5 * s; c.stroke();
    c.fillStyle = this.OUT; c.beginPath(); c.arc(0, 0, gr * 0.3, 0, TAU); c.fill(); c.restore();
    const lx = x + w / 2 - 4 * s, ly = y - h * 0.1, up = S.state === 'idle', ex = lx + (up ? 6 : 12) * s, ey = ly + (up ? -16 : 8) * s;
    c.strokeStyle = this.OUT; c.lineWidth = 3.5 * s; c.lineCap = 'round'; c.beginPath(); c.moveTo(lx, ly); c.lineTo(ex, ey); c.stroke();
    this.circle(ex, ey, 4 * s, up ? '#ff6b9d' : '#aaa', this.OUT, 2 * s);
    if (spent) for (let k = 0; k < 3; k++) { const ph = (G.time * 0.5 + k * 0.33) % 1; c.fillStyle = `rgba(200,200,210,${0.35 * (1 - ph)})`; c.beginPath(); c.arc(x + Math.sin(G.time + k) * 4 * s, y - h / 2 - C * 0.2 - ph * C * 0.6, (3 + ph * 7) * s, 0, TAU); c.fill(); }
    const label = on ? `SIPHON  ${S.taken}/${S.max}` : spent ? `spent  ·  ${S.eaten}/${S.need} ghosts` : moving ? 'trundling...' : 'SIPHON';
    this.text(label, x, y + h / 2 + 11 * s, Math.max(10, 11 * s), on ? '#ffb347' : spent ? '#aaa' : '#ffe9a8', 'center');
    if (S.state === 'idle') this.text('touch to run', x, y + h / 2 + 23 * s, Math.max(9, 9.5 * s), 'rgba(255,255,255,0.65)', 'center', 'normal');
    else if (spent) this.text('eat ghosts to recharge', x, y + h / 2 + 23 * s, Math.max(9, 9.5 * s), 'rgba(255,255,255,0.55)', 'center', 'normal');
  },

  // a woken boulder: the same rock, rolling (cracks and moss rotate), with angry eyes that stay upright
});
