// The in-game HUD: the bars and counters, the boss health bar, the rebuild preview, the key hints, the touch controls and
// the MEGA bar (the phone's button, and its keyboard twin on the right edge).
// Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  // the ghost of your build: every missing blueprint entry as a faded tile, with the base bullets and dots it still needs.
  // Parts in hand are bright, parts to seek are ghosted too (and the matching pickups on the ground get a 'wanted' ring)
  drawRebuildPreview(x, y, w, F) {
    const c = this.ctx, P = Player, RP = P.rebuildPlan(), miss = RP.missing; if (!miss.length) return;
    const rows = miss.slice(0, 3), T = F(26), small = F(18), rowH = T + 14, h = 30 + rows.length * rowH;
    c.fillStyle = 'rgba(30,18,45,0.55)'; this.roundRect(x, y, w, h, 12); c.fill();
    this.text('LOADOUT  ·  go and find', x + 10, y + 15, F(12), '#9bf06a');
    const tile = (B, cx, cy, sz, ghost) => { c.save(); c.globalAlpha = ghost ? 0.4 : 1; c.fillStyle = B.color; this.roundRect(cx - sz / 2, cy - sz / 2, sz, sz, sz * 0.2); c.fill(); c.strokeStyle = ghost ? '#fff' : this.OUT; c.lineWidth = 1.5; if (ghost) c.setLineDash([3, 3]); c.stroke(); c.setLineDash([]); Icons.tile(B, cx, cy, sz, c); c.restore(); };
    const have = P.holdings();
    rows.forEach((e, i) => {
      const cy = y + 30 + i * rowH + T / 2, B = BULLETS[e.id];
      tile(B, x + 12 + T / 2, cy, T, true);
      let tx = x + 12 + T + 8;
      this.text(B.name + (e.want > 1 ? ` ×${e.want - e.held}` : ''), tx, cy - 6, F(12), '#ffe9a8'); // what is missing
      // what it needs: the base bullets, held ones bright, missing ones ghosted; then the dots
      const seen = {}; let px = tx; const py = cy + 9;
      if (!B.craft) this.text('find one', px, py, F(11), '#ffe9a8'); // a base bullet: nothing to fuse, just pick one up
      else { for (const q of e.parts) { const got = (have[q] || 0) - (seen[q] || 0) > 0; seen[q] = (seen[q] || 0) + 1; tile(BULLETS[q], px + small / 2, py, small, !got); px += small + 4; if (px > x + w - 60) break; }
        if (e.dots) this.text(`+${e.dots} dots`, px + 4, py, F(11), G.dots >= e.dots ? '#ffe9a8' : '#ff9b8b'); }
    });
    if (miss.length > rows.length) this.text(`+${miss.length - rows.length} more`, x + w - 10, y + 15, F(11), '#aaa', 'right');
  },

  drawHUD() {
    const c = this.ctx, P = Player, UI = this.ui(), F = n => Math.round(n * UI); // F(): a font size or spacing, scaled on touch screens
    if (G.state === 'paused' || (G.state === 'settings' && Settings.from === 'paused')) { this.drawPaused(); if (G.state === 'settings') this.drawSettings(); if (Input.touch) this.drawTouch(); return; } // the workbench covers the screen (render-bench.js): nothing of the HUD shows through
    // top-left stats: the panel sizes itself to the longest line. In a maze it fades almost away while you are in the corner under it
    const hudFade = G.mode === 'dungeon' && Player.gcol <= 5 && Player.grow <= 3; if (hudFade) c.globalAlpha = 0.22;
    const R = World.regions[World.regionAt(P.x)];
    // the numbers are icons (any language reads them): score, kills / power, body, minions / perks, ghosts, dots
    const it = (icon, text, color) => ({ icon, text: String(text), color }), body = it('segment', `${P.weaponCount()}/${P.maxSegs}`, '#ffb86b'), ghosts = it('ghost', G.ghosts, '#dfe6ff'), dots = it('dot', G.dots, '#ffe9a8');
    const lines = G.mode === 'dungeon' && Input.touch ? [ // in a dungeon on a phone the panel shrinks to two lines, so the maze is not hidden under it
      [`${Dungeon.name().toUpperCase()}  ·  dots left ${Dungeon.dotsLeft}`, '#fff'],
      [[body, ghosts, dots], '#7de0ff'],
    ] : [
      [G.mode === 'dungeon' ? `${Dungeon.name().toUpperCase()}  ·  dots left ${Dungeon.dotsLeft}` : G.mode === 'deck' ? `SAUCER DECK  ·  wave ${Math.min(Deck.wave, Deck.WAVES)} / ${Deck.WAVES}  ·  ${Deck.invaders.filter(e => !e.dead).length} invaders` : `${G.nightmare ? 'NIGHTMARE  ·  ' : ''}LEVEL ${R.level}  ·  REGION ${R.idx + 1}  ·  ${World.regionName(R)}`, '#fff'],
      [[it('mega', G.score, '#ffd166'), it('skull', G.kills, '#eae4d4')], '#ffe9a8'],
      [[it('dmg', `×${+G.powerMult.toFixed(2)}`, '#ff7b6b'), body, it('minion', `${Minions.count()}/${Minions.cap()}`, '#9bf06a')], '#9bf06a'],
      [[it('trophy', G.perks.length, '#ffd166'), ghosts, dots], '#7de0ff'],
    ];
    if (G.nightmare) lines[0] = ['☠ NIGHTMARE  ·  ' + lines[0][0].replace(/^NIGHTMARE  ·  /, ''), `hsl(${8 + 6 * Math.sin(G.time * 2.5)}, 100%, 62%)`]; // nightmare: the top line burns blood-orange, in every mode
    const isz = F(9.4); // icon size: its numbers come out at the panel's text size
    c.font = `bold ${F(15)}px "Trebuchet MS", "Segoe UI", sans-serif`;
    const pw = Math.max(F(200), ...lines.map(l => (Array.isArray(l[0]) ? Icons.stripWidth(l[0], isz) : (c.font = `bold ${F(15)}px "Trebuchet MS", "Segoe UI", sans-serif`, c.measureText(l[0]).width)) + 24));
    this.hudW = pw; // (the boss bar keeps clear of the panel)
    c.fillStyle = 'rgba(30,18,45,0.55)'; this.roundRect(12, 12, pw, 18 + lines.length * F(24), 12); c.fill();
    lines.forEach((l, i) => { const y = 30 + i * F(24); if (Array.isArray(l[0])) Icons.strip(l[0].map(q => ({ ...q, textColor: l[1] })), 20, y, isz, 'left'); else this.text(l[0], 24, y, F(15), l[1]); });
    // mega power bar (not in a dungeon on a phone: the compact panel leaves it out)
    if (!(G.mode === 'dungeon' && Input.touch)) { const by = G.hudBottom = 12 + 18 + lines.length * F(24) + 8, bw = pw, M = MEGAS[Mega.current()], k = U.clamp(G.mega.charge / CFG.MEGA_MAX, 0, 1), ready = Mega.ready();
      c.fillStyle = 'rgba(30,18,45,0.55)'; this.roundRect(12, by, bw, 40, 12); c.fill();
      c.fillStyle = 'rgba(0,0,0,0.35)'; this.roundRect(22, by + 22, bw - 20, 10, 5); c.fill();
      if (k > 0) { c.fillStyle = ready ? `hsl(${(G.time * 300) % 360},90%,65%)` : (M ? M.color : '#fff'); this.roundRect(22, by + 22, (bw - 20) * k, 10, 5); c.fill(); }
      c.strokeStyle = ready ? '#fff' : 'rgba(255,255,255,0.3)'; c.lineWidth = 1.5; this.roundRect(22, by + 22, bw - 20, 10, 5); c.stroke();
      const ML = M ? Mega.label(Mega.current()) : ''; this.text(M ? (ready ? ML : `${ML}  ·  ${Math.floor(k * 100)}%`) : 'No mega equipped', 22, by + 11, F(13), ready ? '#fff' : (M ? M.color : '#aaa'));
      if (M && ready && !Input.touch) this.keyCap('E', 22 + c.measureText(ML).width + F(16), by + 11, '#fff', F(16)); // ready: its key (a phone has the MEGA button)
      if (G.mode === 'world' && P.blueprint.length) this.drawRebuildPreview(12, by + 48, bw, F);
    }
    // segment chain
    const n = P.segments.length, free = Math.max(0, P.maxSegs - P.weaponCount()), size = F(n + free > 40 ? 10 : n + free > 25 ? 14 : 18), gap = 3;
    const total = (n + 1 + free) * (size + gap);
    let x = CFG.W / 2 - total / 2, y = 22;
    c.fillStyle = 'rgba(30,18,45,0.55)'; this.roundRect(x - 10, y - size / 2 - 7, total + 20, size + 14, 10); c.fill();
    this.circle(x + size / 2, y, size / 2, '#ffb86b', this.OUT, 2); x += size + gap;
    for (const s of P.segments) {
      const col = P.segColor(s);
      { c.fillStyle = s.jam ? '#5a3b8f' : col; this.roundRect(x, y - size / 2, size, size, 4); c.fill(); c.strokeStyle = s.para ? '#ff3a3a' : (BULLETS[s.id].craft ? '#fff' : this.OUT); c.lineWidth = 2; c.stroke(); }
      if (s.jam) this.text('!', x + size / 2, y + 1, size * 0.6, '#fff', 'center'); else if (size >= 10) Icons.tile(BULLETS[s.id], x + size / 2, y, size, c); // the bullet's own icon, as on the workbench (no letters: the game reads in any language)
      x += size + gap;
    }
    for (let k = 0; k < free; k++) { c.setLineDash([3, 3]); c.strokeStyle = 'rgba(255,255,255,0.5)'; c.lineWidth = 1.5; this.roundRect(x, y - size / 2, size, size, 4); c.stroke(); c.setLineDash([]); x += size + gap; } // empty slots: what the body can still take
    c.globalAlpha = 1; // (the stats panel's maze-corner fade ends here)
    // messages (hidden while paused so the pause screen stays clean)
    let my = CFG.H - 40;
    if (G.state === 'playing' && Input.touch) { // a phone (and only in play: the overlays have the screen): the newest three, top centre on dark pills, clear of both thumbs and the deck's cannon
      const fs = F(17), lh = fs + 14, maxW = CFG.W * 0.45, shown = G.msgs.slice(-3); let ty = (G.hudBottom || 160) + F(52) + lh / 2; // left column, under the stats and the mega bar: clear of the play's centre and both thumbs
      for (const m of shown) { // a long one wraps onto a second line (a third is cut with …)
        this.fitText('', 10, fs); const words = (m.n > 1 ? `${m.text} ×${m.n}` : m.text).split(' '), ls = []; let cur = '';
        for (const wd of words) { const tr = cur ? cur + ' ' + wd : wd; if (cur && c.measureText(tr).width > maxW - 28) { ls.push(cur); cur = wd; } else cur = tr; }
        if (cur) ls.push(cur); if (ls.length > 2) { ls[1] = this.fitText(ls.slice(1).join(' '), maxW - 28, fs); ls.length = 2; }
        const tw = Math.min(maxW, Math.max(...ls.map(l => c.measureText(l).width)) + 28), ph = lh - 4 + (ls.length - 1) * (fs + 6);
        c.globalAlpha = Math.min(1, m.t / 0.5); c.fillStyle = 'rgba(14,8,24,0.72)'; this.roundRect(14, ty - lh / 2 + 1, tw, ph, Math.min(ph, lh - 4) / 2); c.fill();
        ls.forEach((l, k) => this.text(l, 28, ty + k * (fs + 6), fs, m.color, 'left')); ty += lh + (ls.length - 1) * (fs + 6); c.globalAlpha = 1;
      }
    } else if (G.state !== 'paused' && !Input.touch) for (let i = G.msgs.length - 1; i >= 0; i--) {
      const m = G.msgs[i];
      c.globalAlpha = Math.min(1, m.t / 0.5);
      this.text(m.n > 1 ? `${m.text} ×${m.n}` : m.text, 20, my, F(15), m.color);
      my -= F(22); c.globalAlpha = 1;
    }
    // hints (not under the workbench)
    if (G.state !== 'paused' && !Input.touch) this.keyHints(G.mode === 'deck' ? [{ k: 'A', i: [] }, { k: 'D', i: ['speed'] }, { k: 'E', i: ['mega'] }] /* (the deck always fires: no SPACE) */ // keyboard screens: the controls, as key caps and icons
      : G.mode === 'dungeon' ? [{ k: 'WASD', i: ['speed'] }, { k: 'SPACE', i: ['shot'] }, { i: ['door'] }]
      : [{ k: 'WASD', i: ['speed'] }, { k: 'SPACE', i: ['shot'] }, { k: 'E', i: ['mega'] }, { i: ['shot', 'wall'] }, { k: 'M', i: ['sound'] }, { k: 'C', i: ['hammer'] }], CFG.W - 20, CFG.H - 20, 'rgba(255,255,255,0.75)', 'right', F(16));
    if (!Input.touch && G.state === 'playing') { const b = this.deskMega(); this.megaBar(b, Input.down('KeyE')); this.keyCap('E', b.x, b.y + b.hh + 20, Mega.ready() ? '#fff' : 'rgba(255,255,255,0.55)', 20); }
    G.craftBtn = null;
    if (!Input.touch && G.state === 'playing') { // keyboard screens: a CRAFT (P) pill at the top right, lit gold with a count when a fusion is affordable (the touch cluster has its own button)
      const n = Player.fusions().filter(f => G.dots >= f.cost).length, lit = n > 0, k = 0.5 + 0.5 * Math.sin(G.time * 6), bw = 76, bh = 32, bx = CFG.W - 20 - bw, by = G.god ? 48 : 12;
      c.fillStyle = lit ? this.tint('#ffd166', 0.2 + 0.15 * k) : 'rgba(255,255,255,0.08)'; this.roundRect(bx, by, bw, bh, bh / 2); c.fill(); c.strokeStyle = lit ? '#ffd166' : 'rgba(255,255,255,0.4)'; c.lineWidth = lit ? 3 : 2; c.stroke();
      Icons.draw('hammer', bx + 22, by + bh / 2, 9, lit ? '#ffd166' : '#ccc'); this.keyCap('C', bx + 50, by + bh / 2, lit ? '#ffd166' : '#ccc'); // the hammer and its key: no words
      if (lit) { c.fillStyle = '#9bf06a'; c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.arc(bx + bw - 4, by + 4, 10, 0, TAU); c.fill(); c.stroke(); this.text(String(n), bx + bw - 4, by + 5, 12, this.OUT, 'center'); }
      G.craftBtn = { x: bx, y: by, w: bw, h: bh };
    }
    if (G.god || G.showProf) { const gx = CFG.W - (Input.touch ? 400 : 150); if (G.god) { c.fillStyle = 'rgba(255,209,102,0.25)'; this.roundRect(gx, 12, 138, 28, 10); c.fill(); c.strokeStyle = '#ffd166'; c.lineWidth = 2; c.stroke(); this.text('GOD MODE  (G)', gx + 69, 26, 14, '#ffd166', 'center'); }
      // the profiler: smoothed ms per frame for update and draw, the real frame interval and its fps, and the count of slow frames
      const p = G.prof, fps = p.frame > 0 ? 1000 / p.frame : 0, hot = p.frame > 20, lines = [`frame ${p.frame.toFixed(1)} ms  ·  ${fps.toFixed(0)} fps`, `update ${p.upd.toFixed(2)}  ·  draw ${p.draw.toFixed(2)} ms`, `${G.enemies.length} enemies  ·  ${Combat.projectiles.length} bullets  ·  ${Combat.particles.length} particles  ·  ${p.slow} slow`];
      const big = Input.touch, fs = big ? 22 : 11, lh = big ? 30 : 16, pw = big ? 430 : 198, px = big ? CFG.W - 20 - pw : gx - 60, py = big ? 50 : 90; // twice the size on a phone, where it is read at arm's length
      c.fillStyle = 'rgba(30,18,45,0.75)'; this.roundRect(px, py, pw, 14 + lines.length * lh, 8); c.fill(); // under the CRAFT pill on keyboard screens
      lines.forEach((l, i) => this.text(l, px + 10, py + lh - 2 + i * lh, fs, i === 0 && hot ? '#ff9b8b' : '#ffe9a8', 'left', 'normal')); }
    if (!Player.firing && Player.alive && G.state === 'playing') { // weapons-off indicator
      const blink = 0.6 + 0.4 * Math.sin(G.time * 6);
      const bw = Input.touch ? 56 : 120; // holding fire: a crossed-out shot, and (with a keyboard) the key that resumes
      c.fillStyle = `rgba(120,20,40,${0.55 * blink})`; this.roundRect(CFG.W / 2 - bw / 2, 54, bw, 30, 10); c.fill(); c.strokeStyle = '#ff9b8b'; c.lineWidth = 2; c.stroke();
      const ix = Input.touch ? CFG.W / 2 : CFG.W / 2 - 30; Icons.draw('shot', ix, 69, 8, '#ffd6d6'); Icons.draw('cross', ix, 69, 9, '#ff5c5c');
      if (!Input.touch) this.keyCap('SPACE', CFG.W / 2 + 22, 69, '#ffd6d6', 18);
    }
    if (G.mode === 'world' && !Player.ready && Player.alive && G.state === 'playing') { if (Player.reorient) { c.fillStyle = 'rgba(10,4,20,0.35)'; c.fillRect(0, 0, CFG.W, CFG.H); } this.text(Player.reorient ? `${Player.reorientText || 'Back outside'}. ${Input.touch ? 'Drag your thumb to crawl on' : 'Press a direction to crawl on'}` : Input.touch ? 'Drag your left thumb to start crawling' : 'Press W A S or D to start crawling', CFG.W / 2, CFG.H / 2 + 70, F(26), '#fff', 'center'); }
    // banner
    if (G.banner) {
      const b = G.banner, k = Math.min(1, b.t / 0.4);
      c.globalAlpha = k;
      this.text(b.text, CFG.W / 2, CFG.H / 2 - 60, F(56), '#ffd166', 'center');
      if (b.sub) this.text(b.sub, CFG.W / 2, CFG.H / 2 - 15, F(20), '#fff', 'center');
      c.globalAlpha = 1;
    }
    if (Bot.trials && !Bot.trials.done) this.text(`TRIALS ${Bot.runs.length + 1} / ${Bot.trials.n}  ·  ×${Bot.trials.fast}  ·  ${Bot.offline ? 'scripted' : Bot.model}`, CFG.W / 2, CFG.H - 40, 14, '#ffd166', 'center');
    Bot.drawHUD();
    if (G.state === 'discover') { // a phone: the card a third bigger (its tap boxes scaled to match)
      const k = Input.touch ? 1.35 : 1, cx = CFG.W / 2, cy = CFG.H / 2; c.save(); if (k !== 1) { c.translate(cx, cy); c.scale(k, k); c.translate(-cx, -cy); }
      this.drawDiscover(); c.restore();
      if (k !== 1) for (const key of ['discoverBox', 'discoverBench']) { const r = G[key]; if (r && !r.scaled) G[key] = { x: cx + (r.x - cx) * k, y: cy + (r.y - cy) * k, w: r.w * k, h: r.h * k, scaled: true }; }
    }
    if (G.state === 'perk') this.drawPerks();
    if (Input.touch) this.drawTouch();
    if (G.state === 'won') this.drawWon();
    if (G.state === 'gameover') { const k = Input.touch ? 1.3 : 1, cx = CFG.W / 2, cy = CFG.H / 2 - 110; c.save(); if (k !== 1) { c.translate(cx, cy); c.scale(k, k); c.translate(-cx, -cy); } this.drawGameOver(); c.restore(); } // (a phone: a third bigger)
    // boss health bar
    const boss = this.shownBoss();
    if (boss && G.state === 'playing') {
      const bw = Math.min(CFG.W - 60, F(520), CFG.W - 2 * (12 + (this.hudW || 0) + 24)), bx = CFG.W / 2 - bw / 2, by = 128;
      c.fillStyle = 'rgba(30,18,45,0.7)'; this.roundRect(bx - 10, by - 22, bw + 20, F(44), 10); c.fill();
      this.text(`${BOSSES[boss.type].name}  ·  Level ${boss.level}`, CFG.W / 2, by - 8, F(14), '#ff7a5c', 'center');
      c.fillStyle = 'rgba(0,0,0,0.4)'; this.roundRect(bx, by + 4, bw, 12, 6); c.fill();
      c.fillStyle = boss.hp < boss.maxhp * 0.3 ? '#ff3a3a' : '#ff7a5c'; this.roundRect(bx, by + 4, Math.max(4, bw * U.clamp(boss.hp / boss.maxhp, 0, 1)), 12, 6); c.fill();
    }
    // the swarm's bar (World.updateSwarm), in the boss bar's place: a countdown while it gathers, then how many are left
    const S = G.swarm;
    if (S && !boss && G.state === 'playing' && G.mode === 'world') {
      const bw = Math.min(CFG.W - 60, F(420)), bx = CFG.W / 2 - bw / 2, by = 128, warn = S.phase === 'warn', pulse = 0.6 + 0.4 * Math.sin(G.time * 10);
      c.fillStyle = 'rgba(30,18,45,0.7)'; this.roundRect(bx - 10, by - 22, bw + 20, F(44), 10); c.fill();
      this.text(warn ? `SWARM  ·  ${Math.ceil(S.t)}` : `SWARM  ·  ${S.left}`, CFG.W / 2, by - 8, F(14), warn ? `rgba(255,209,102,${pulse})` : '#ffd166', 'center');
      if (!warn) Icons.strip([{ icon: 'skull', text: String(S.popped), color: '#eae4d4' }], bx + 2, by - 8, F(7), 'left'); // how many popped
      Icons.draw('lock', bx + bw - 6, by - 8, F(7), '#ffd166'); // the walls are sealed
      c.fillStyle = 'rgba(0,0,0,0.4)'; this.roundRect(bx, by + 4, bw, 12, 6); c.fill();
      c.fillStyle = '#ffd166'; this.roundRect(bx, by + 4, Math.max(4, bw * (warn ? 1 - S.t / CFG.SWARM.warn : U.clamp(S.left / S.total, 0, 1))), 12, 6); c.fill();
    }
  },

  // a keyboard key, drawn as a little key cap (a hint any language reads)
  keyCap(k, cx, cy, col, h) { const c = this.ctx; h = h || 18; c.font = `bold ${Math.round(h * 0.62)}px "Trebuchet MS", "Segoe UI", sans-serif`; const w = Math.max(h, c.measureText(k).width + h * 0.6); c.strokeStyle = col; c.lineWidth = 1.5; this.roundRect(cx - w / 2, cy - h / 2, w, h, 4); c.stroke(); this.text(k, cx, cy + 1, Math.round(h * 0.62), col, 'center'); return w; },

  keyCapW(k, h) { const c = this.ctx; h = h || 18; c.font = `bold ${Math.round(h * 0.62)}px "Trebuchet MS", "Segoe UI", sans-serif`; return Math.max(h, c.measureText(k).width + h * 0.6); },

  // control hints as key caps and icons: [{ k: 'SPACE', i: ['shot'] }, { i: ['shot', 'wall'] }, ...] from x (its right end when align is 'right')
  keyHints(list, x, y, col, align, h) {
    h = h || 16; const is = h * 0.45, iw = is * 2.3, gapIn = 5, gapOut = 16;
    const wOf = e => (e.k ? this.keyCapW(e.k, h) + (e.i.length ? gapIn : 0) : 0) + e.i.length * iw;
    const total = list.reduce((a, e) => a + wOf(e), 0) + gapOut * (list.length - 1);
    let px = align === 'right' ? x - total : align === 'center' ? x - total / 2 : x;
    for (const e of list) {
      if (e.k) { const w = this.keyCapW(e.k, h); this.keyCap(e.k, px + w / 2, y, col, h); px += w + (e.i.length ? gapIn : 0); }
      for (const ic of e.i) { Icons.draw(ic, px + iw / 2, y, is, col); px += iw; }
      px += gapOut;
    }
  },
  // touch controls: joystick where the thumb landed, button cluster bottom-right

  drawTouch() {
    const c = this.ctx, F = n => Math.round(n * this.ui());
    if (G.state === 'playing' || G.state === 'perk' || G.state === 'paused') {
      const j = Input.joy;
      if (G.mode === 'deck' && G.state === 'playing') { // the deck: two big zones, the left and right halves of the play
        /* the deck's speed steps are drawn by Deck.drawLabels */
      } else if (j && G.mode === 'dungeon' && G.state === 'playing') { // the maze stick: a plus of maze-sized squares round your thumb, so a square on the stick is a square in the maze
        const v = Input.joyVec() || { x: 0, y: 0, d: 0 }, S = Input.stickR(), P = Player, W = P.want;
        for (const d of [{ x: 0, y: 0 }, { x: 1, y: 0 }, { x: -1, y: 0 }, { x: 0, y: 1 }, { x: 0, y: -1 }]) {
          const ctr = !d.x && !d.y, on = W && W.x === d.x && W.y === d.y, cur = !ctr && P.gdir.x === d.x && P.gdir.y === d.y, x = j.x0 + d.x * S, y = j.y0 + d.y * S, h = S * 0.46;
          c.fillStyle = on ? 'rgba(255,209,102,0.35)' : cur ? 'rgba(255,255,255,0.16)' : 'rgba(255,255,255,0.07)'; this.roundRect(x - h, y - h, h * 2, h * 2, h * 0.3); c.fill();
          c.strokeStyle = on ? '#ffd166' : 'rgba(255,255,255,0.35)'; c.lineWidth = on ? 3 : 2; c.stroke();
          if (!ctr) { const s = h * 0.45; c.save(); c.translate(x, y); c.rotate(Math.atan2(d.y, d.x)); c.fillStyle = on ? '#ffd166' : cur ? 'rgba(255,255,255,0.6)' : 'rgba(255,255,255,0.25)'; c.beginPath(); c.moveTo(s, 0); c.lineTo(-s * 0.6, -s * 0.8); c.lineTo(-s * 0.6, s * 0.8); c.closePath(); c.fill(); c.restore(); }
        }
        this.circle(j.x0 + v.x * S, j.y0 + v.y * S, Math.min(TOUCH.knobR, S * 0.32), 'rgba(255,209,102,0.85)', this.OUT, 3);
      } else if (j) {
        const v = Input.joyVec() || { x: 0, y: 0, d: 0 };
        c.fillStyle = 'rgba(255,255,255,0.12)'; c.beginPath(); c.arc(j.x0, j.y0, TOUCH.joyR, 0, TAU); c.fill();
        c.strokeStyle = 'rgba(255,255,255,0.45)'; c.lineWidth = 3; c.stroke();
        this.circle(j.x0 + v.x * TOUCH.joyR, j.y0 + v.y * TOUCH.joyR, TOUCH.knobR, 'rgba(255,209,102,0.85)', this.OUT, 3);
      } else if (G.state === 'playing') {
        if (G.mode === 'dungeon') this.text('swipe here to turn', CFG.W * 0.72, CFG.H * 0.5, 18, 'rgba(255,255,255,0.35)', 'center'); // the maze: the right side takes swipes
        c.fillStyle = 'rgba(255,255,255,0.07)'; c.beginPath(); c.arc(150, 590, TOUCH.joyR, 0, TAU); c.fill();
        this.text('steer', 150, 590, 16, 'rgba(255,255,255,0.5)', 'center');
      }
      for (const b of TOUCH.buttons) {
        if (G.state === 'paused') continue; // the workbench has its own buttons (play, settings) and the pinch magnifier
        if (G.state !== 'playing' && b.id !== 'pause' && b.id !== 'full') continue;
        if ((G.state === 'title' || G.state === 'paused') && b.id === 'pause') continue; // the workbench has its own green play button
        if (b.id === 'full' && (Input.inFullscreen() || Input.standalone())) continue;
        if (b.id === 'fire' && G.mode === 'deck') continue; // the deck always fires: no button to knock off by accident
        // the same tones as Render.square (the title squares, the bin): idle = faint fill and a grey ring, lit = a tinted fill
        // and a ring in its colour, on = solid in its colour with dark ink. Round, because these are thumb targets
        const pressed = Input.down(b.code);
        if (b.bar) { this.megaBar(b, pressed); continue; }
        let fill = 'rgba(14,8,24,0.45)', ring = 'rgba(255,255,255,0.55)', lw = 2.5, ink = '#fff', label = b.label; // (a dark backing: legible over grass, boulders and water alike)
        if (b.id === 'fire') { if (Player.firing) { fill = this.tint('#9bf06a', 0.9); ring = this.OUT; lw = 3; ink = this.OUT; label = 'FIRE ON'; } else { fill = this.tint('#ff5c5c', 0.3); ring = '#ff9b8b'; lw = 3; label = 'FIRE OFF'; } }
        if (b.id === 'mega') { const ready = Mega.ready(); if (ready) { fill = `hsla(${(G.time * 300) % 360},90%,65%,0.9)`; ring = this.OUT; lw = 3; ink = this.OUT; label = 'MEGA!'; } else label = `${Math.floor(100 * G.mega.charge / CFG.MEGA_MAX)}%`; }
        let badge = 0;
        if (b.id === 'pause') { // the workbench button: lit gold when a fusion is affordable right now, with the FORGE tab's green count
          label = 'CRAFT';
          if (G.state === 'playing') { badge = Player.fusions().filter(f => G.dots >= f.cost).length; if (badge) { const k = 0.5 + 0.5 * Math.sin(G.time * 6); fill = this.tint('#ffd166', 0.2 + 0.15 * k); ring = '#ffd166'; lw = 3; } }
        }
        if (pressed) fill = 'rgba(255,255,255,0.35)';
        c.fillStyle = fill; c.beginPath(); c.arc(b.x, b.y, b.r, 0, TAU); c.fill();
        c.strokeStyle = ring; c.lineWidth = lw; c.stroke();
        const ts = Math.round(b.r * (b.id === 'full' || b.id === 'guide' ? 0.75 : b.id === 'pause' || b.id === 'fire' ? 0.4 : 0.46));
        if (ink === this.OUT) this.ink(label, b.x, b.y + 1, ts, ink, 'center', b.r * 1.7);
        else this.text(label, b.x, b.y + 1, ts, ink, 'center');
        if (b.id === 'pause' && G.state === 'playing') this.text('pause', b.x, b.y + b.r + 14, Math.round(b.r * 0.42), 'rgba(255,255,255,0.8)', 'center');
        if (badge) { const br = Math.round(b.r * (badge > 9 ? 0.38 : 0.32)), bx = b.x - b.r * 0.72, bby = b.y + b.r * 0.72; c.fillStyle = '#9bf06a'; c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.arc(bx, bby, br, 0, TAU); c.fill(); c.stroke(); this.text(String(badge), bx, bby + 1, Math.round(br * 1.1), this.OUT, 'center'); } // lower-left: the button hugs the screen's top-right corner, so the usual top-right badge would be cut off
      }
    }
  },
  // a keyboard screen's MEGA bar: the phone's, on the right edge, with its key under it (a click on it works too)
  deskMega() { return { id: 'mega', code: 'KeyE', x: CFG.W - 44, y: CFG.H / 2 - 20, hw: 22, hh: 105 }; },

  shownBoss() { return G.enemies.find(e => e.kind === 'boss' && !e.dead && e.awake && !e.hidden && e.region === World.regionAt(Player.x)); }, // the one whose health bar is up

  // the phone's MEGA button: a tall bar that fills from the bottom in the mega's colour; when it is ready it dresses like
  // the MEGA tab on the perk cards (shimmering hues, a dark outline, the mega icon at both ends) and reads MEGA up its length
  megaBar(b, pressed) {
    const c = this.ctx, M = MEGAS[Mega.current()], k = U.clamp(G.mega.charge / CFG.MEGA_MAX, 0, 1), ready = M && Mega.ready();
    const x = b.x - b.hw, y = b.y - b.hh, w = b.hw * 2, h = b.hh * 2, rad = b.hw * 0.7;
    c.fillStyle = 'rgba(14,8,24,0.55)'; this.roundRect(x, y, w, h, rad); c.fill();
    if (ready) {
      const g = c.createLinearGradient(0, y + h, 0, y), h0 = (G.time * 120) % 360;
      for (let i = 0; i <= 4; i++) g.addColorStop(i / 4, `hsl(${(h0 + i * 70) % 360},90%,65%)`);
      c.fillStyle = g; this.roundRect(x, y, w, h, rad); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 3; c.stroke();
      Icons.draw('mega', b.x, y + b.hw * 0.85, b.hw * 0.42, '#fff'); Icons.draw('mega', b.x, y + h - b.hw * 0.85, b.hw * 0.42, '#fff');
      c.save(); c.translate(b.x, b.y + 1); c.rotate(-Math.PI / 2); c.fillStyle = this.OUT; c.font = `bold ${Math.round(b.hw * 0.95)}px "Trebuchet MS", "Segoe UI", sans-serif`;
      c.textAlign = 'center'; c.textBaseline = 'middle'; c.letterSpacing = `${Math.round(b.hw * 0.3)}px`; c.fillText('MEGA', b.hw * 0.15, 0); c.letterSpacing = '0px'; c.restore();
    } else {
      if (k > 0) { c.save(); this.roundRect(x, y, w, h, rad); c.clip(); c.fillStyle = M ? M.color : '#aaa'; c.globalAlpha = 0.85; c.fillRect(x, y + h * (1 - k), w, h * k); c.restore(); }
      c.strokeStyle = 'rgba(255,255,255,0.55)'; c.lineWidth = 2.5; this.roundRect(x, y, w, h, rad); c.stroke();
      if (M) Icons.draw('mega', b.x, y + b.hw * 0.85, b.hw * 0.4, 'rgba(255,255,255,0.7)');
      this.text(M ? `${Math.floor(k * 100)}%` : '—', b.x, y + h - b.hw * 0.8, Math.round(b.hw * 0.55), '#fff', 'center');
    }
    if (pressed) { c.fillStyle = 'rgba(255,255,255,0.35)'; this.roundRect(x, y, w, h, rad); c.fill(); }
  },
});
