// Whole screens: the title, settings, game over, the win and the ascend cinematics. (The workbench, the pause screen, is
// in render-bench.js; the HUD in render-hud.js.)
// Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  drawAscend() {
    const c = this.ctx, P = Player, k = 1 - G.cinematic.t / G.cinematic.total;
    const g = c.createLinearGradient(0, 0, 0, CFG.H); g.addColorStop(0, '#1a0c2e'); g.addColorStop(1, '#4a2a5e');
    c.fillStyle = g; c.fillRect(0, 0, CFG.W, CFG.H);
    // cliff walls scrolling down (we go up)
    const scroll = (G.time * 420) % 80;
    c.fillStyle = '#3b2a44'; c.fillRect(0, 0, 260, CFG.H); c.fillRect(CFG.W - 260, 0, 260, CFG.H);
    c.fillStyle = '#2b1d3a';
    for (let y = -80 + scroll; y < CFG.H + 80; y += 80) { for (const x of [40, 150, CFG.W - 200, CFG.W - 90]) { c.beginPath(); c.ellipse(x, y, 34, 18, 0, 0, TAU); c.fill(); } }
    for (let i = 0; i < 40; i++) { const y = ((i * 97 + G.time * 600) % (CFG.H + 40)) - 20, x = 300 + (i * 173) % (CFG.W - 600); c.fillStyle = `rgba(255,255,255,${0.15 + (i % 3) * 0.1})`; c.fillRect(x, y, 2, 14); }
    // the centipede, climbing: head up, body trailing below, wiggling
    const cx = CFG.W / 2, hy = CFG.H * 0.62 - k * CFG.H * 0.25;
    for (let i = P.segments.length - 1; i >= 0; i--) {
      const s = P.segments[i], y = hy + (i + 1) * CFG.SEG_SPACING * 1.3, x = cx + Math.sin(G.time * 10 + i * 0.7) * 8;
      this.legs(x, y, -Math.PI / 2, CFG.SEG_R, i);
      this.circle(x, y, CFG.SEG_R, P.segColor(s), this.OUT, 3); this.shine(x, y, CFG.SEG_R);
    }
    this.legs(cx, hy, -Math.PI / 2, CFG.HEAD_R, -1);
    this.circle(cx, hy, CFG.HEAD_R, '#ffb86b', this.OUT, 3.5); this.shine(cx, hy, CFG.HEAD_R);
    this.eyes(cx, hy, -Math.PI / 2, CFG.HEAD_R, { spread: CFG.HEAD_R * 0.5, fwd: CFG.HEAD_R * 0.2, er: CFG.HEAD_R * 0.36 });
    this.text('LEVEL UP', CFG.W / 2, 120, 64, '#ffd166', 'center');
    this.text(`Six walls broken. Climbing to the ${World.regionName(G.cinematic.region)}.`, CFG.W / 2, 175, 22, '#fff', 'center');
    const Bz = BOSSES[World.bossFor(G.cinematic.region.level)];
    if (k > 0.55) { c.globalAlpha = Math.min(1, (k - 0.55) * 4); this.text(`${Bz.name} awaits: ${Bz.desc}`, CFG.W / 2, CFG.H - 60, 18, '#ff7a5c', 'center'); c.globalAlpha = 1; }
  },
  // the celebration: confetti, the winnings as icon strips, the three bullets you keep, and what the next level holds

  drawWon() {
    const c = this.ctx, W = G.won, P = Player;
    c.fillStyle = 'rgba(20,10,30,0.82)'; c.fillRect(0, 0, CFG.W, CFG.H);
    const cols = ['#ffd166', '#ff7eb6', '#7df9ff', '#9bf06a', '#c58aff', '#ff8a3d', '#fff'];
    const finale = W.demoEnd && G.nightmare, NC = finale ? 320 : 110, cz = finale ? 1.6 : 1; // the nightmare finale: a confetti storm
    for (let i = 0; i < NC; i++) { // confetti, each piece on its own loop
      const sp = 50 + (i % 5) * 22, x = (i * 197 + Math.sin(G.time * 1.5 + i) * 30) % (CFG.W + 40) - 20, y = ((G.time * sp + i * 131) % (CFG.H + 60)) - 30;
      c.save(); c.translate(x, y); c.rotate(G.time * (2 + i % 3) + i); c.fillStyle = cols[i % cols.length]; c.globalAlpha = 0.85; c.fillRect(-5 * cz, -2.5 * cz, 10 * cz, 5 * cz); c.restore();
    }
    const pulse = 1 + 0.03 * Math.sin(G.time * 5);
    for (let i = 0; i < 2; i++) { const bx = CFG.W / 2 + (i ? 1 : -1) * 330, by = 150 + Math.sin(G.time * 4 + i) * 6; this.circle(bx, by, 22, '#ffd166', this.OUT, 3); Icons.draw('trophy', bx, by, 11, '#fff'); } // trophies either side
    c.save(); c.translate(CFG.W / 2, 150); c.scale(pulse, pulse); this.text(`LEVEL ${W.level} COMPLETE`, 0, 0, 60, '#ffd166', 'center'); c.restore();
    this.text(`The ${W.boss} is scrap. Your winnings:`, CFG.W / 2, 205, 22, '#fff', 'center');
    const s = 11;
    Icons.strip([{ icon: 'trophy', color: '#ffd166', text: `${W.score}` }, { icon: 'skull', color: '#eae4d4', text: `${W.kills}` }, { icon: 'door', color: '#c58aff', text: `${W.dungeons}` }, { icon: 'plus', color: '#9bf06a', text: `${W.perks.length}` }, { icon: 'dmg', color: '#ff7b6b', text: `×${+W.power.toFixed(2)}` }], CFG.W / 2, 250, s, 'center');
    Icons.strip([{ icon: 'dot', color: '#ffe9a8', text: `${W.dots}` }, { icon: 'ghost', color: '#7de0ff', text: `${W.ghosts}` }, { icon: 'minion', color: '#9bf06a', text: `${W.minions}` }, { icon: 'segment', color: '#ffb86b', text: `${W.maxLen} max` }, { icon: 'mega', color: '#ffd166', text: '100%' }], CFG.W / 2, 290, s, 'center');
    if (W.perks.length) { const names = [...new Set(W.perks)].map(id => { const n = W.perks.filter(x => x === id).length; return PERKS[id].name + (n > 1 ? ` ×${n}` : ''); }).join('  ·  '); this.text(names.length > 110 ? names.slice(0, 108) + '…' : names, CFG.W / 2, 322, 14, '#c58aff', 'center', 'normal'); }
    // the body you crawl on with, and (when CFG.WIN_RESET is on) where the rest went
    const n = W.body.length, tile = n > 8 ? 44 : n > 5 ? 54 : 64, gap = n > 8 ? 10 : 18, x0 = CFG.W / 2 - ((n + 1) * tile + n * gap) / 2, ty = 390;
    this.circle(x0 + tile / 2, ty, tile * 0.4, '#ffb86b', this.OUT, 3.5); this.shine(x0 + tile / 2, ty, tile * 0.4); this.eyes(x0 + tile / 2, ty, 0, tile * 0.4, { spread: tile * 0.2, fwd: tile * 0.08, er: tile * 0.14 }); this.mouth(x0 + tile / 2, ty, 0, tile * 0.4, true);
    W.body.forEach((id, i) => { const w = BULLETS[id], cx = x0 + (i + 1) * (tile + gap) + tile / 2; c.fillStyle = w.color; this.roundRect(cx - tile / 2, ty - tile / 2, tile, tile, tile * 0.18); c.fill(); c.strokeStyle = w.craft ? '#fff' : this.OUT; c.lineWidth = 3; c.stroke(); Icons.tile(w, cx, ty, tile, c); if (tile >= 54) this.text(w.name, cx, ty + tile / 2 + 14, 12, w.color, 'center'); });
    const sh = W.shrink;
    this.text(sh.none ? `You keep everything: ${W.body.length} segments, ${W.bag} in the bag, every perk, dot and ghost. Onward.`
                      : `Body reset to ${CFG.WIN_RESET}: ${sh.bagged ? `${sh.bagged} bullet${sh.bagged > 1 ? 's' : ''} stashed in the bag` : 'nothing to stash'}${sh.lost ? `, ${sh.lost} lost (bag full)` : ''}. Perks, dots and ghosts stay. Grow again.`, CFG.W / 2, ty + 62, 15, '#ffe9a8', 'center', 'normal');
    const next = W.level + 1, nextB = BOSSES[World.bossFor(next)];
    if (W.demoEnd) { // the Clown Car was the last act: the first time into NIGHTMARE, the second time the finale
      const sc = 1 + 0.03 * Math.sin(G.time * 4); c.save(); c.translate(CFG.W / 2, 500); c.scale(sc, sc); this.text(finale ? 'NIGHTMARE CLEARED!' : "NOW THE NIGHTMARE", 0, 0, finale ? 48 : 40, finale ? '#ffd166' : '#c58aff', 'center'); c.restore();
      this.text(finale ? 'Ten levels, ten bosses, every nightmare beaten. You are the build. Thank you for playing.' : 'Five levels down. Now back to the meadow with everything you built, and everything is harder:', CFG.W / 2, 540, 17, '#fff', 'center', 'normal');
      this.text(finale ? 'Coming in the full game: the space-port, and a mode where you dig in and shoot down incoming missiles by tapping them.' : 'tougher, faster-shooting crowds, and bosses with new tricks. Beat the Clown Car once more to finish.', CFG.W / 2, 564, 14, '#ffe9a8', 'center', 'normal');
      if (Input.touch || Math.floor(G.time * 2) % 2 === 0) this.text(finale ? (Input.touch ? 'TAP for the title' : 'Press ENTER for the title') : (Input.touch ? 'TAP to enter the NIGHTMARE' : 'Press ENTER to enter the NIGHTMARE'), CFG.W / 2, 608, 24, finale ? '#9bf06a' : '#c58aff', 'center');
      return;
    }
    if (Math.floor(G.time * 2) % 2 === 0) this.text(Input.touch ? `TAP to crawl on to level ${next}` : `Press ENTER to crawl on to level ${next}`, CFG.W / 2, 520, 26, '#9bf06a', 'center');
    this.text(next === 2 ? 'THE GRAVEYARD: grey, haunted, charred. Circle a tombstone to raise a ghost. Bone spears drop. Cathedral dungeons.' : next === 3 ? 'THE JUNGLE: an Aztec city under the canopy. Earwigs lunge, cockroaches swarm, iguanas slurp segments. Temple dungeons.' : next === 4 ? 'THE FARM: they have landed. Brainlings, scout saucers, abducted cows, crop circles. Saucer-deck dungeons.' : next === 5 ? 'THE CIRCUS: evil clowns and pure psychedelia. Circle a cannon and it fires you. Juggling pins ricochet. Big-top dungeons with a hammer.' : 'Six more walls, then a bigger boss. The world only gets darker.', CFG.W / 2, 560, 16, next === 3 ? '#9bf06a' : next === 4 ? '#ff5cf0' : next === 5 ? '#ff5c8a' : '#dfe6ff', 'center', 'normal');
    this.text(`At the end: ${nextB.name}. ${nextB.desc}.`, CFG.W / 2, 584, 14, '#ff7a5c', 'center', 'normal');
  },

  // ---- the settings page ----
  drawSettings() { // laid out like the workbench pages: a scrolling list on the left (a swipe on a phone, the cursor on a keyboard),
    // the selected setting in full in the info card on the right, with big « value » controls; BACK pinned under the list
    const c = this.ctx, S = Settings, T = this.benchText(), M = 40, K = Input.touch ? 1.4 : 1, IW = Math.round(Math.min(340 * T, CFG.W * 0.3)), ix = CFG.W - M - IW, x0 = M, w = ix - 24 - M, y0 = 24, rowH = Math.round(54 * K);
    c.fillStyle = 'rgba(10,4,20,0.94)'; c.fillRect(0, 0, CFG.W, CFG.H);
    S.hit = [];
    Icons.draw('cog', x0 + 14 * K, y0 + 20 * K, 13 * K, '#ffe9a8'); this.text('SETTINGS', x0 + 38 * K, y0 + 20 * K, 26 * K, '#fff', 'left');
    this.text(Input.touch ? 'tap a row to choose it  ·  swipe to scroll' : 'W/S choose · A/D or ENTER change · ESC or O to go back', x0 + w, y0 + 20 * K, 12 * K, 'rgba(255,255,255,0.55)', 'right', 'normal');
    const top = y0 + 46 * K, bottom = CFG.H - 14, viewH = bottom - top, total = S.items().length * rowH, maxS = Math.max(0, total - viewH);
    if (!Input.touch || S.lastSel !== S.sel) { const sy = S.sel * rowH; if (sy - (S.scroll || 0) < 0) S.scroll = sy; if (sy + rowH - (S.scroll || 0) > viewH) S.scroll = sy + rowH - viewH; } S.lastSel = S.sel; // the selection stays in view
    S.scroll = U.clamp(S.scroll || 0, 0, maxS); S.view = { top, bottom, maxS };
    c.save(); c.beginPath(); c.rect(0, top - 4, ix - 12, viewH + 4); c.clip();
    S.items().forEach((it, i) => {
      const y = top + i * rowH - S.scroll, sel = i === S.sel, rh = rowH - 8; if (y + rh < top || y > bottom) return;
      c.fillStyle = sel ? 'rgba(255,209,102,0.12)' : 'rgba(255,255,255,0.05)'; this.roundRect(x0, y, w, rh, 12); c.fill(); c.strokeStyle = sel ? '#ffd166' : 'rgba(255,255,255,0.15)'; c.lineWidth = sel ? 2.5 : 1.5; c.stroke();
      const v = S.value(it), vw = Math.round(150 * K), vx = x0 + w - vw - 12, vh = Math.round(30 * K), vy = y + rh / 2 - vh / 2;
      this.text(this.fitText(it.label, vx - x0 - 30, Math.round(17 * K)), x0 + 18, y + rh / 2 + 1, Math.round(17 * K), sel ? '#ffd166' : '#fff', 'left');
      S.hit.push({ i, dir: 0, x: x0, y, w: vx - x0 - 6, h: rh }); // the row: select it
      c.fillStyle = it.action ? 'rgba(125,249,255,0.18)' : 'rgba(0,0,0,0.35)'; this.roundRect(vx, vy, vw, vh, 9); c.fill(); c.strokeStyle = it.action ? '#7df9ff' : 'rgba(255,255,255,0.3)'; c.lineWidth = 1.5; c.stroke();
      this.text(this.setLabel(it, v), vx + vw / 2, vy + vh / 2 + 1, Math.round(15 * K), it.action ? '#7df9ff' : '#ffd166', 'center');
      S.hit.push({ i, dir: 1, x: vx, y, w: vw, h: rh }); // the value: change it
    });
    c.restore();
    if (maxS > 0) { const th = Math.max(30, viewH * viewH / total), ty = top + (viewH - th) * (S.scroll / maxS); c.fillStyle = 'rgba(255,255,255,0.08)'; this.roundRect(x0 + w + 8, top, 5, viewH, 2.5); c.fill(); c.fillStyle = 'rgba(255,209,102,0.6)'; this.roundRect(x0 + w + 8, ty, 5, th, 2.5); c.fill(); } // where you are in the list
    { // the info card: the selected setting, the whole story, and its control
      const it = S.items()[S.sel], v = S.value(it), cb0 = Input.touch && TOUCH.buttons.find(b => b.id === 'pause'), iy = Math.max(top, cb0 ? cb0.y + cb0.r + 10 : y0 + 50 * K), ih = CFG.H - 14 - iy, pad = 20;
      c.fillStyle = '#1b1428'; this.roundRect(ix, iy, IW, ih, 16); c.fill(); c.strokeStyle = '#ffd166'; c.lineWidth = 2.5; c.stroke();
      this.text(this.fitText(it.label, IW - pad * 2, 20 * T), ix + pad, iy + 32 * T, 20 * T, '#ffd166', 'left');
      this.benchWrap(it.desc[0].toUpperCase() + it.desc.slice(1) + '.', ix + pad, iy + 66 * T, 14 * T, '#e4dcf0', IW - pad * 2, 20 * T, 9);
      const ch = Math.round(52 * T), cy = iy + ih - 24 - ch;
      if (it.action) { c.fillStyle = 'rgba(125,249,255,0.25)'; this.roundRect(ix + pad, cy, IW - pad * 2, ch, ch / 2); c.fill(); c.strokeStyle = '#7df9ff'; c.lineWidth = 2.5; c.stroke(); this.text(this.setLabel(it, v), ix + IW / 2, cy + ch / 2 + 1, Math.round(17 * T), '#7df9ff', 'center'); S.hit.push({ panel: true, dir: 1, x: ix + pad, y: cy, w: IW - pad * 2, h: ch }); }
      else {
        const aw = ch; for (const [dir, ax] of [[-1, ix + pad], [1, ix + IW - pad - aw]]) { c.fillStyle = 'rgba(255,255,255,0.08)'; this.roundRect(ax, cy, aw, ch, 12); c.fill(); c.strokeStyle = 'rgba(255,255,255,0.35)'; c.lineWidth = 2; c.stroke(); c.save(); c.translate(ax + aw / 2, cy + ch / 2); if (dir < 0) c.scale(-1, 1); Icons.draw('speed', 0, 0, 9 * T, '#fff'); c.restore(); S.hit.push({ panel: true, dir, x: ax, y: cy, w: aw, h: ch }); }
        c.fillStyle = 'rgba(0,0,0,0.35)'; this.roundRect(ix + pad + aw + 10, cy, IW - pad * 2 - aw * 2 - 20, ch, 12); c.fill(); this.text(this.setLabel(it, v), ix + IW / 2, cy + ch / 2 + 1, Math.round(20 * T), '#ffd166', 'center');
        if (it.values) { const n = it.values.length, k = it.values.indexOf(S.data[it.id]); for (let j = 0; j < n; j++) { c.fillStyle = j === k ? '#ffd166' : 'rgba(255,255,255,0.25)'; c.beginPath(); c.arc(ix + IW / 2 + (j - (n - 1) / 2) * 16, cy - 16, 4, 0, TAU); c.fill(); } } // which of the choices
      }
    }
    { // back to the game: the workbench's green play button, in the same place (where CRAFT sits on a phone)
      const cb = Input.touch && TOUCH.buttons.find(b => b.id === 'pause'), r = cb ? cb.r : 22 * T, px = cb ? cb.x : CFG.W - M - r, py = cb ? cb.y : y0 + 20 * K;
      c.fillStyle = 'rgba(155,240,106,0.22)'; c.beginPath(); c.arc(px, py, r, 0, TAU); c.fill(); c.strokeStyle = '#9bf06a'; c.lineWidth = 2.5; c.stroke(); Icons.draw('play', px + 2, py, 11 * T * (r / (22 * T)), '#9bf06a');
      S.hit.push({ close: true, x: px - r - 6, y: py - r - 6, w: r * 2 + 12, h: r * 2 + 12 });
    }
  },

  setLabel(it, v) { return it.id === 'save' ? 'save now' : it.action ? (v === 'on' ? 'leave fullscreen' : 'go fullscreen') : v; },

  drawGameOver() {
    const c = this.ctx;
    c.fillStyle = 'rgba(20,10,30,0.72)'; c.fillRect(0, 0, CFG.W, CFG.H);
    this.text('YOUR CENTIPEDE IS TOAST', CFG.W / 2, 150, 48, '#ff7eb6', 'center');
    this.text(`Done in by ${G.deathCause || 'something'}`, CFG.W / 2, 200, 20, '#fff', 'center');
    // two columns: the run's numbers on the left, the perks stacked top to bottom on the right (repeats collapse to ×N)
    const lines = [
      `Score  ${G.score}` + (G.score >= G.best && G.score > 0 ? '   NEW BEST!' : `   (best ${G.best})`),
      `Level ${G.level}  ·  region ${G.regionMax + 1}`,
      `Kills  ${G.kills}`,
      `Dungeons cleared  ${G.dungeonsCleared}`,
      `Longest body  ${G.maxLen}`,
      `Power reached  x${+G.powerMult.toFixed(2)}`,
      `Dots ${G.dots}  ·  ghosts ${G.ghosts}`,
    ];
    const counts = new Map(); for (const id of G.perks) counts.set(id, (counts.get(id) || 0) + 1);
    const perks = [...counts.entries()], two = perks.length > 10, lx = perks.length ? CFG.W / 2 - 330 : CFG.W / 2, align = perks.length ? 'left' : 'center';
    lines.forEach((l, i) => this.text(l, lx, 260 + i * 32, 22, '#ffe9a8', align));
    if (perks.length) {
      const px = CFG.W / 2 + (two ? 20 : 80), lh = two ? 24 : 28, colW = 230;
      this.text(`PERKS  (${G.perks.length})`, px, 260, 18, '#c58aff', 'left');
      perks.forEach(([id, n], i) => {
        const col = two ? i % 2 : 0, row = two ? Math.floor(i / 2) : i, x = px + col * colW, y = 292 + row * lh, P = PERKS[id], ic = PERK_ICONS[id];
        Icons.draw(ic ? ic[0] : 'mega', x + 9, y, 8, P.color);
        this.text(P.name + (n > 1 ? `  ×${n}` : ''), x + 26, y + 1, two ? 15 : 17, P.color, 'left');
      });
    }
    this.text(Input.touch ? 'TAP to crawl again' : 'Press R to crawl again  ·  ESC for the title', CFG.W / 2, Math.max(530, 300 + (perks.length ? (two ? Math.ceil(perks.length / 2) * 24 : perks.length * 28) : 0) + 20), 26, '#9bf06a', 'center');
  },

  drawTitle() {
    const c = this.ctx;
    const g = c.createLinearGradient(0, 0, 0, CFG.H); g.addColorStop(0, '#8fd27a'); g.addColorStop(1, '#5ea85a');
    c.fillStyle = g; c.fillRect(0, 0, CFG.W, CFG.H);
    c.fillStyle = this.grassTile; c.fillRect(0, 0, CFG.W, CFG.H);
    Title.draw(); // the attract mode: a random loadout racing across the top, firing at bugs along the edges
    this.vignette(); // under the title, its words and its buttons
    const t = G.time, cy = CFG.H / 2 - (Input.touch ? 70 : 40); // the whole page sits a little above the middle: room for the run's lines at the bottom
    // the title sits in the middle of the screen, clear of the centipede
    this.text('CENTIPEDE', CFG.W / 2, cy - 30, 84, '#ffd166', 'center'); G.titleWord = { x: CFG.W / 2 - 260, y: cy - 70, w: 520, h: 80 };
    if (G.godArmed) this.text('god mode armed for this run', CFG.W / 2, cy - 92, 13, '#ffd166', 'center');
    const K = Input.touch ? 1.45 : 1; // a phone reads this at arm's length: everything under the title half again as big
    this.text('a roguelike snake shooter · you are the build', CFG.W / 2, cy + 24 + (K - 1) * 10, Math.round(22 * K), '#fff', 'center');
    // the controls, and nothing else: the game explains itself as you go (messages, the discovery cards, the guide)
    const lines = Input.touch ? [['steer', 'left thumb'], ['FIRE', 'weapons on / off'], ['MEGA', 'when the bar is full'], ['CRAFT', 'the workbench']]
      : [['WASD', 'steer  ·  you never stop moving'], ['SPACE', 'weapons on / off'], ['E', 'mega, when the bar is full'], ['C', 'craft: the workbench'], ['T', 'the guide: it points at what to do next']];
    const ly = cy + 78 + (K - 1) * 20, lh = Math.round(30 * K);
    lines.forEach(([k, v], i) => { this.text(k, CFG.W / 2 - 24, ly + i * lh, Math.round(20 * K), '#ffe9a8', 'right'); this.text(v, CFG.W / 2, ly + i * lh, Math.round(17 * K), '#fff', 'left', 'normal'); });
    const after = ly + lines.length * lh + 22;
    const cont = Save.has();
    if (cont) this.text(`Saved run: ${Save.summary()} (${Save.when()})`, CFG.W / 2, after + (Input.touch ? 46 : 34), Input.touch ? 26 : 19, '#9bf06a', 'center');
    G.newRunBox = null; G.loadRunBox = null;
    { c.save(); c.globalAlpha = 0.8 + 0.2 * Math.sin(t * 3); this.text(cont ? (Input.touch ? 'TAP to continue' : 'Press SPACE to continue') : Input.touch ? 'TAP to play' : 'Press SPACE to play', CFG.W / 2, after, Math.round(32 * (Input.touch ? 1.35 : 1)), '#9bf06a', 'center'); c.restore(); } // a gentle pulse (the first tap plays; the title music wakes by itself once the browser lets sound run)
    { let nc = 0; try { nc = +(localStorage.getItem('centipede.nightmareCleared') || 0); } catch (e) {} if (nc) this.text(`★ NIGHTMARE CLEARED  ·  best ${nc}`, CFG.W / 2, cy - 108, Input.touch ? 20 : 15, '#c58aff', 'center'); } // the mark of a finished game
    if (Music.on && Music.name === 'title') this.text('♪ title theme', CFG.W - 24, 24, 14, 'rgba(255,255,255,0.6)', 'right', 'normal');
    this.text(typeof BUILD !== 'undefined' ? `${BUILD.channel === 'stable' ? '' : BUILD.channel + ' · '}build ${BUILD.id} · ${BUILD.date}` : 'dev build', CFG.W / 2, after + (cont ? (Input.touch ? 80 : 62) : (Input.touch ? 46 : 34)), Input.touch ? 20 : 15, 'rgba(255,255,255,0.75)', 'center', 'normal'); // which build this is (stamped by tools/deploy.sh): playtesters can say, and see they have the latest
    if (Bot.trials && Bot.trials.done) { const S = Bot.trials.done, lines = [`TRIALS DONE: ${S.runs} runs (${S.model}) in ${S.wallSecs}s  ·  ${S.wins} cleared the nightmare`, `avg ${S.secsAvg}s a run, region ${S.regionAvg} (best ${S.regionMax}), ${S.dungeonsAvg} dungeons, ${S.perksAvg} perks, body up to ${S.bodyMaxAvg}`, `killed by: ${Object.entries(S.killedBy).sort((a, b) => b[1] - a[1]).slice(0, 5).map(([k, v]) => `${k} ${v}`).join(', ')}`, `picks by the model: ${S.modelShare}  ·  perks: ${S.topPerks || '-'}`, `fusions: ${S.topFusions || '-'}`, `deck: ${S.deck.cleared}/${S.deck.entered} cleared, ${S.deck.secsAvg}s each, ${S.deck.segsLostAvg} segments lost  ·  full table in the console and localStorage 'centipede.trials'`]; lines.forEach((l, i) => this.text(l, CFG.W / 2, 40 + i * 22, 15, i ? '#fff' : '#ffd166', 'center', i ? 'normal' : 'bold')); }
    // the big square buttons, in the workbench bin's style: ENABLE SOUND at the left with LOAD RUN under it (when there is a
    // saved run), FULL SCREEN at the right with NEW RUN under it (and QUIT on the desktop build). Input.titleTap reads the rects back
    const bs = Input.touch ? 150 : CFG.W < 1100 ? 90 : 110, ios = Input.isIOS() && !Input.standalone(), soundOn = !!(SFX.ctx && SFX.ctx.state === 'running') && !SFX.muted;
    const selB = G.titleSel || 'play', row = k => cy + k * (bs + 34), lx = 40 + bs / 2, rx = CFG.W - 40 - bs / 2;
    G.titleBtns = { sound: this.square(lx, row(0), bs, SFX.muted ? { glyph: '♪', label: 'MUTED', sub: 'tap for sound', color: '#aaa' } : soundOn ? { glyph: '♪', label: 'SOUND', label2: 'ON', sub: 'tap to mute', color: '#9bf06a', on: true } : { glyph: '♪', label: 'ENABLE', label2: 'SOUND', color: '#9bf06a' }) };
    if (cont) { G.loadRunBox = G.titleBtns.load = this.square(lx, row(1), bs, { glyph: '▶', label: 'LOAD', label2: 'RUN', sub: 'pick up the saved run', color: '#9bf06a' }); G.newRunBox = this.square(rx, row(1), bs, { glyph: '↻', label: 'NEW', label2: 'RUN', sub: 'forgets the save', color: '#ffd166' }); }
    if (window.desktop) G.titleBtns.quit = this.square(rx, row(cont ? 2 : 1), bs, { glyph: '✕', label: 'QUIT', sub: Input.touch ? '' : 'or ESC', color: '#ff9b8b' }); // the desktop build: there is no tab to close
    if (!Input.standalone()) G.titleBtns.full = this.square(rx, row(0), bs, ios ? { glyph: '⛶', label: 'ADD TO', label2: 'HOME', sub: 'Share → Add to Home Screen', color: '#7de0ff' } : { glyph: '⛶', label: 'FULL', label2: 'SCREEN', sub: Input.inFullscreen() ? 'tap to exit' : '', color: '#7de0ff', on: Input.inFullscreen() });
    { // the keyboard's selection (WASD / arrows, Enter presses it): a white ring round its square, or arrows round the prompt for PLAY
      const r = selB === 'newrun' ? G.newRunBox : selB !== 'play' ? G.titleBtns[selB] : null, k = 0.5 + 0.5 * Math.sin(t * 6);
      if (!Input.touch && r) { c.save(); c.strokeStyle = '#fff'; c.lineWidth = 3 + 1.5 * k; c.globalAlpha = 0.7 + 0.3 * k; this.roundRect(r.x - 6, r.y - 6, r.w + 12, r.h + 12, 20); c.stroke(); c.restore(); }
      if (!Input.touch && selB === 'play') { const msg = cont ? 'Press SPACE to continue' : 'Press SPACE to play'; c.font = 'bold 32px "Trebuchet MS", "Segoe UI", sans-serif'; const w = c.measureText(msg).width / 2 + 28 + 6 * k; this.text('▶', CFG.W / 2 - w, after, 24, '#9bf06a', 'center'); this.text('◀', CFG.W / 2 + w, after, 24, '#9bf06a', 'center'); } /* (the prompt's own words, measured, so the arrows sit just outside them) */
      if (!Input.touch) this.text('SPACE to play  ·  WASD to choose a button  ·  ENTER to press it', CFG.W / 2, CFG.H - 16, 13, 'rgba(255,255,255,0.45)', 'center', 'normal');
    }
  },
});
