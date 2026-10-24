// The cards and tips that pop up over play: a new bullet type found, a dungeon's entrance, the swarm, leaving a
// dungeon, the first perk, the first craft.
// Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  // ---- the discovery card: a bullet type you have never held swoops in ----
  drawDiscover() {
    const c = this.ctx, D = G.discover; if (!D) return; if (D.tip === 'swarm') { this.drawSwarmTip(D); return; } if (D.tip === 'dungeon') { this.drawDungeonCard(D); return; } if (D.tip === 'perks') { this.drawPerkTip(D); return; } if (D.tip === 'leave') { this.drawLeaveCard(D); return; } if (D.tip) { this.drawCraftTip(D); return; } const B = BULLETS[D.id], k = Math.min(1, D.t / 0.45), ease = 1 - Math.pow(1 - k, 3);
    c.fillStyle = `rgba(10,4,20,${0.55 * k})`; c.fillRect(0, 0, CFG.W, CFG.H);
    const w = Math.min(720, CFG.W - 60), h = 330, x = CFG.W / 2 - w / 2 + (1 - ease) * (CFG.W * 0.7), y = CFG.H / 2 - h / 2, tile = 120;
    c.save(); c.translate(x, y);
    c.fillStyle = '#1f1830'; this.roundRect(0, 0, w, h, 22); c.fill(); c.strokeStyle = B.color; c.lineWidth = 4; c.stroke();
    this.text('NEW SEGMENT', 28, 34, 15, B.color, 'left');
    const spin = Math.sin(D.t * 6) * 0.08 * (1 - k);
    c.save(); c.translate(28 + tile / 2, 70 + tile / 2); c.rotate(spin); c.fillStyle = B.color; this.roundRect(-tile / 2, -tile / 2, tile, tile, tile * 0.18); c.fill(); c.strokeStyle = B.craft ? '#fff' : this.OUT; c.lineWidth = 4; c.stroke(); Icons.tile(B, 0, 0, tile, c); c.restore();
    this.text(B.name, 28 + tile + 24, 84, 30, '#fff', 'left');
    this.text(`tier ${B.tier}${B.craft ? ' · fused' : ''}`, 28 + tile + 24, 110, 14, 'rgba(255,255,255,0.6)', 'left', 'normal');
    Icons.strip(Icons.bulletItems(B), 28 + tile + 24, 140, 9, 'left');
    const how = Settings.howItFires(B), words = how.split(' '); let line = '', ly = 175; c.font = 'normal 15px "Trebuchet MS", "Segoe UI", sans-serif';
    for (const wd of words) { const t = line ? line + ' ' + wd : wd; if (c.measureText(t).width > w - tile - 80) { this.text(line, 28 + tile + 24, ly, 15, '#ffe9a8', 'left', 'normal'); ly += 20; line = wd; } else line = t; } if (line) this.text(line, 28 + tile + 24, ly, 15, '#ffe9a8', 'left', 'normal');
    this.text(B.desc, 28, 70 + tile + 34, 14, 'rgba(255,255,255,0.75)', 'left', 'normal');
    // the checkbox: show these cards next time
    const cbx = 28, cby = h - 40, on = Settings.data.discover; c.fillStyle = on ? '#9bf06a' : 'rgba(255,255,255,0.1)'; this.roundRect(cbx, cby - 11, 22, 22, 5); c.fill(); c.strokeStyle = on ? '#9bf06a' : 'rgba(255,255,255,0.5)'; c.lineWidth = 2; c.stroke();
    if (on) { c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.moveTo(cbx + 5, cby); c.lineTo(cbx + 10, cby + 5); c.lineTo(cbx + 18, cby - 6); c.stroke(); }
    this.text(`show new segments next time${Input.touch ? '' : '  (C)'}`, cbx + 32, cby, 14, '#fff', 'left', 'normal');
    G.discoverBox = { x: x + cbx - 10, y: y + cby - 20, w: 300, h: 40 };
    { // the workbench button: fit the new piece right now
      const bw = 190, bh = 40, bx = w - 28 - bw, by = h - 40 - bh / 2;
      c.fillStyle = 'rgba(255,209,102,0.18)'; this.roundRect(bx, by, bw, bh, 12); c.fill(); c.strokeStyle = '#ffd166'; c.lineWidth = 2; c.stroke();
      Icons.draw('hammer', bx + 24, by + bh / 2, 11, '#ffd166');
      this.text(`WORKBENCH${Input.touch ? '' : '  (P)'}`, bx + 44, by + bh / 2 + 1, 15, '#ffd166', 'left');
      G.discoverBench = { x: x + bx, y: y + by, w: bw, h: bh };
    }
    if (D.t > 0.4 && Math.floor(G.time * 2) % 2 === 0) this.text(Input.touch ? 'TAP to carry on' : 'ENTER to carry on', w - 28, h - 92, 16, '#9bf06a', 'right');
    c.restore();
  },

  // the guide's crafting explainer (Settings.explainCraft): the same card as a discovery, about fusing
  // the get-ready card (Settings.dungeonCard): flies in from the right with a little overshoot as you step into a dungeon
  drawDungeonCard(D) {
    const c = this.ctx, B = D.B, k = Math.min(1, D.t / 0.5), s1 = 1.70158, back = 1 + (s1 + 1) * Math.pow(k - 1, 3) + s1 * Math.pow(k - 1, 2), T = Input.touch ? 1.25 : 1;
    c.fillStyle = `rgba(10,4,20,${0.6 * k})`; c.fillRect(0, 0, CFG.W, CFG.H);
    const w = Math.min(700 * T, CFG.W - 60), lh = 21 * T;
    c.font = `normal ${16 * T}px "Trebuchet MS", "Segoe UI", sans-serif`; // measure first, so the card fits its lines
    let n = 0; for (const [t] of B.lines) { let line = ''; n++; for (const wd of t.split(' ')) { const tt = line ? line + ' ' + wd : wd; if (c.measureText(tt).width > w - 80 && line) { n++; line = wd; } else line = tt; } }
    const h = 150 * T + n * lh + B.lines.length * 6 + 80 * T, x = CFG.W / 2 - w / 2 + (1 - back) * CFG.W * 0.75, y = CFG.H / 2 - h / 2;
    c.save(); c.translate(x, y);
    c.fillStyle = '#1f1830'; this.roundRect(0, 0, w, h, 22); c.fill(); c.strokeStyle = B.color; c.lineWidth = 4; c.stroke();
    Icons.draw('door', 40, 36 * T, 12 * T, B.color); this.text('GET READY', 62, 36 * T, 15 * T, B.color, 'left');
    this.text(B.name, w / 2, 96 * T, 34 * T, '#fff', 'center');
    let ly = 146 * T; for (const [t, col] of B.lines) ly = this.benchWrap(t, 40, ly, 16 * T, col, w - 80, lh) + 6;
    // the checkbox: show this card next time
    const cbx = 40, cby = h - 36 * T, on = Settings.data.dungeonCard, bs = 22 * T; c.fillStyle = on ? '#9bf06a' : 'rgba(255,255,255,0.1)'; this.roundRect(cbx, cby - bs / 2, bs, bs, 5); c.fill(); c.strokeStyle = on ? '#9bf06a' : 'rgba(255,255,255,0.5)'; c.lineWidth = 2; c.stroke();
    if (on) { c.strokeStyle = this.OUT; c.lineWidth = 3; c.beginPath(); c.moveTo(cbx + 5 * T, cby); c.lineTo(cbx + 10 * T, cby + 5 * T); c.lineTo(cbx + 18 * T, cby - 6 * T); c.stroke(); }
    this.text(`show before every dungeon${Input.touch ? '' : '  (C)'}`, cbx + bs + 10, cby, 14 * T, '#fff', 'left', 'normal');
    G.discoverBox = { x: x + cbx - 10, y: y + cby - 20 * T, w: 300 * T, h: 40 * T }; G.discoverBench = null;
    if (D.t > 0.4 && Math.floor(G.time * 2) % 2 === 0) this.text(Input.touch ? 'TAP when ready' : 'ENTER when ready', w - 40, cby, 16 * T, '#9bf06a', 'right');
    c.restore();
  },

  // the swarm's card (Settings.swarmCard): you ran into the walls a swarm sealed
  drawSwarmTip(D) {
    const c = this.ctx, S = G.swarm, k = Math.min(1, D.t / 0.45), ease = 1 - Math.pow(1 - k, 3), T = Input.touch ? 1.25 : 1;
    c.fillStyle = `rgba(10,4,20,${0.6 * k})`; c.fillRect(0, 0, CFG.W, CFG.H);
    const w = Math.min(700 * T, CFG.W - 60), h = 300 * T, x = CFG.W / 2 - w / 2, y = CFG.H / 2 - h / 2 + (1 - ease) * CFG.H * 0.6;
    c.save(); c.translate(x, y);
    c.fillStyle = '#1f1830'; this.roundRect(0, 0, w, h, 22); c.fill(); c.strokeStyle = '#ffd166'; c.lineWidth = 4; c.stroke();
    Icons.draw('skull', 40, 36 * T, 12 * T, '#ffd166'); this.text('PEST CONTROL', 62, 36 * T, 15 * T, '#ffd166', 'left');
    this.text('Do your job: pest control that swarm!', w / 2, 96 * T, 30 * T, '#fff', 'center');
    let ly = 140 * T;
    ly = this.benchWrap('The swarm has sealed this region: no wall breaks, no breach lets you through and no dungeon lets you in until every last one of them is popped.', 40, ly, 16 * T, '#ffe9a8', w - 80, 22 * T) + 6;
    ly = this.benchWrap(`${S ? S.left : 0} left. They hunt you, and they get faster the longer they live: turn round and fight.`, 40, ly, 16 * T, '#fff', w - 80, 22 * T);
    G.discoverBench = null; G.discoverBox = null;
    if (D.t > 0.4 && Math.floor(G.time * 2) % 2 === 0) this.text(Input.touch ? 'TAP to carry on' : 'ENTER to carry on', w / 2, h - 32 * T, 16 * T, '#9bf06a', 'center');
    c.restore();
  },

  // the dungeon's exit asks first (Dungeon.arrive): leaving before it is complete closes it for good. EXIT / STAY
  drawLeaveCard(D) {
    const c = this.ctx, k = Math.min(1, D.t / 0.3), ease = 1 - Math.pow(1 - k, 3), Dg = Dungeon;
    c.fillStyle = `rgba(10,4,20,${0.6 * k})`; c.fillRect(0, 0, CFG.W, CFG.H);
    const w = Math.min(640, CFG.W - 60), h = 290, x = CFG.W / 2 - w / 2, y = CFG.H / 2 - h / 2 + (1 - ease) * 60;
    c.save(); c.globalAlpha = k; c.translate(x, y);
    c.fillStyle = '#1f1830'; this.roundRect(0, 0, w, h, 22); c.fill(); c.strokeStyle = '#ff7b6b'; c.lineWidth = 4; c.stroke();
    Icons.draw('door', 40, 36, 12, '#ff7b6b'); this.text('THE EXIT', 62, 36, 15, '#ff7b6b', 'left');
    this.text('There is no going back!', w / 2, 88, 32, '#fff', 'center');
    this.text('Exit the dungeon before it is complete?', w / 2, 126, 19, '#ffe9a8', 'center', 'normal');
    this.text(`Leave now and it closes behind you: no perk, no cleanse, no way back in.${Dg.dotsLeft > 0 ? `  ${Dg.dotsLeft} dot${Dg.dotsLeft > 1 ? 's' : ''} left.` : ''}`, w / 2, 156, 14, 'rgba(255,255,255,0.7)', 'center', 'normal');
    const bw = 200, bh = 56, by = 190, btn = (i, label, col, bx) => {
      const on = D.sel === i; c.fillStyle = on ? this.tint(col, 0.35) : this.tint(col, 0.12); this.roundRect(bx, by, bw, bh, bh / 2); c.fill();
      c.strokeStyle = on ? '#fff' : col; c.lineWidth = on ? 5 : 2.5; c.stroke(); this.text(label, bx + bw / 2, by + bh / 2 + 1, 24, col, 'center');
      return { x: x + bx, y: y + by, w: bw, h: bh };
    };
    G.discoverBox = btn(0, 'EXIT', '#ff7b6b', w / 2 - bw - 16); // (a tap on EXIT is KeyC, on STAY KeyP: Input.discoverKey)
    G.discoverBench = btn(1, 'STAY', '#9bf06a', w / 2 + 16);
    if (!Input.touch) this.text('A / D to choose  ·  ENTER  ·  ESC stays', w / 2, h - 18, 13, 'rgba(255,255,255,0.55)', 'center', 'normal');
    c.restore();
  },

  // the first perks you ever buy (Settings.perkTip): they are all kept, in full, on the workbench's POWERS tab (its PERKS page)
  drawPerkTip(D) {
    const c = this.ctx, k = Math.min(1, D.t / 0.45), ease = 1 - Math.pow(1 - k, 3);
    c.fillStyle = `rgba(10,4,20,${0.6 * k})`; c.fillRect(0, 0, CFG.W, CFG.H);
    const w = Math.min(720, CFG.W - 60), h = 330, x = CFG.W / 2 - w / 2 + (1 - ease) * (CFG.W * 0.7), y = CFG.H / 2 - h / 2;
    c.save(); c.translate(x, y);
    c.fillStyle = '#1f1830'; this.roundRect(0, 0, w, h, 22); c.fill(); c.strokeStyle = '#c58aff'; c.lineWidth = 4; c.stroke();
    this.text('YOUR PERKS', 40, 36, 15, '#c58aff', 'left');
    const ids = D.ids.slice(0, 4), bx0 = w / 2 - (ids.length - 1) * 45; // the badges you just bought
    ids.forEach((id, i) => { const Pk = PERKS[id], PI = PERK_ICONS[id] || [Pk.mega ? 'mega' : 'dmg', []], bx = bx0 + i * 90, s = 1 + Math.max(0, Math.sin((D.t - i * 0.12) * 4)) * 0.08 * (1 - k); this.circle(bx, 90, 30 * s, Pk.color, this.OUT, 3.5); this.shine(bx, 90, 30 * s); Icons.draw(PI[0], bx, 90, 18 * s, this.OUT); });
    let ly = 150;
    ly = this.benchWrap(`Every perk you take is kept in the workbench: open it (the CRAFT button${Input.touch ? '' : ', or P'}) and pick POWERS, then PERKS, to read each card in full.`, 40, ly, 16, '#fff', w - 80, 22) + 6;
    this.benchWrap('Each card there also says what all your copies add up to.', 40, ly, 16, '#ffe9a8', w - 80, 22);
    { // straight there
      const bw = 210, bh = 42, bx = w - 40 - bw, by = h - 30 - bh;
      c.fillStyle = 'rgba(197,138,255,0.18)'; this.roundRect(bx, by, bw, bh, 12); c.fill(); c.strokeStyle = '#c58aff'; c.lineWidth = 2; c.stroke();
      Icons.draw('hammer', bx + 26, by + bh / 2, 11, '#c58aff'); this.text(`SEE MY PERKS${Input.touch ? '' : '  (P)'}`, bx + 46, by + bh / 2 + 1, 15, '#c58aff', 'left');
      G.discoverBench = { x: x + bx, y: y + by, w: bw, h: bh }; G.discoverBox = null;
      if (D.t > 0.4 && Math.floor(G.time * 2) % 2 === 0) this.text(Input.touch ? 'TAP to carry on' : 'ENTER to carry on', 40, by + bh / 2 + 1, 16, '#9bf06a', 'left');
    }
    c.restore();
  },

  drawCraftTip(D) {
    const c = this.ctx, P = Player, f = D.f, A = BULLETS[f.a], Bb = BULLETS[f.b], R = BULLETS[f.r], k = Math.min(1, D.t / 0.45), ease = 1 - Math.pow(1 - k, 3);
    c.fillStyle = `rgba(10,4,20,${0.6 * k})`; c.fillRect(0, 0, CFG.W, CFG.H);
    const w = Math.min(760, CFG.W - 60), h = 360, x = CFG.W / 2 - w / 2 + (1 - ease) * (CFG.W * 0.7), y = CFG.H / 2 - h / 2;
    c.save(); c.translate(x, y);
    c.fillStyle = '#1f1830'; this.roundRect(0, 0, w, h, 22); c.fill(); c.strokeStyle = '#ffd166'; c.lineWidth = 4; c.stroke();
    Icons.draw('hammer', 40, 36, 12, '#ffd166'); this.text('TIME TO CRAFT', 62, 36, 15, '#ffd166', 'left');
    // the fusion you can make right now: A + B → R, as tiles
    const tile = (W, cx, cy, s) => { c.fillStyle = W.color; this.roundRect(cx - s / 2, cy - s / 2, s, s, s * 0.18); c.fill(); c.strokeStyle = W.craft ? '#fff' : this.OUT; c.lineWidth = 3; c.stroke(); Icons.tile(W, cx, cy, s, c); };
    const cy = 110; tile(A, 90, cy, 72); this.text('+', 150, cy, 30, '#d8d0e8', 'center'); tile(Bb, 210, cy, 72); this.text('→', 272, cy, 30, '#d8d0e8', 'center'); tile(R, 345, cy, 96);
    this.text(R.name, 410, cy - 14, 26, '#fff', 'left'); Icons.strip(Icons.bulletItems(R, { short: true }), 410, cy + 18, 9, 'left');
    this.text(`${A.name} + ${Bb.name}  ·  ${f.cost} dots (you have ${G.dots})`, 410, cy + 48, 13, 'rgba(255,255,255,0.65)', 'left', 'normal');
    const lines = [
      ['Two bullets fuse into ONE stronger bullet that takes a single slot.', '#fff'],
      [`Your body holds ${P.maxSegs} segments. Fusing is how it gets stronger without getting longer.`, '#ffe9a8'],
      ['Fusing costs dots: the pellets you eat in dungeons. Loot only drops from kills, so a stronger body means more loot, which means more fusions.', '#fff'],
      ['Open the workbench whenever the CRAFT button lights up gold.', '#9bf06a'],
    ];
    let ly = 190; for (const [t, col] of lines) ly = this.benchWrap(t, 40, ly, 15, col, w - 80, 20) + 4;
    { // the workbench button, and carry on
      const bw = 210, bh = 42, bx = w - 40 - bw, by = h - 30 - bh;
      c.fillStyle = 'rgba(255,209,102,0.18)'; this.roundRect(bx, by, bw, bh, 12); c.fill(); c.strokeStyle = '#ffd166'; c.lineWidth = 2; c.stroke();
      Icons.draw('hammer', bx + 26, by + bh / 2, 11, '#ffd166'); this.text(`OPEN WORKBENCH${Input.touch ? '' : '  (C)'}`, bx + 46, by + bh / 2 + 1, 15, '#ffd166', 'left');
      G.discoverBench = { x: x + bx, y: y + by, w: bw, h: bh }; G.discoverBox = null;
      if (D.t > 0.4 && Math.floor(G.time * 2) % 2 === 0) this.text(Input.touch ? 'TAP to carry on' : 'ENTER to carry on', 40, h - 30 - bh / 2 + 1, 16, '#9bf06a', 'left');
    }
    c.restore();
  },
});
