// The workbench POWERS tab: its hub (benchHub, three big buttons) and its pages: PERKS (every perk you hold, its level and
// next price), the MEGA DECK and the HAT RACK (the cards you have found; pick one and it floats over to the right panel).
// The shared widgets are in render-bench.js.
// Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  // ---- the POWERS hub: three big buttons, each showing what you have now: HATS (the hat you wear), MEGA (the active mega and
  // its charge), PERKS (how many you have taken, the biggest first). A tap (or ENTER) opens that page; ◀ comes back ----
  benchHub() {
    const c = this.ctx, B = Build, P = Player, T = this.benchText(), M = this.BENCH.M, top = this.benchTopH() + 30 * T, gap = 22 * T;
    const w = (CFG.W - M * 2 - gap * 2) / 3, h = Math.min(CFG.H - top - 40 * T, 380 * T), mega = Mega.current(), perks = [...new Set(G.perks)].sort((a, b) => Perks.level(b) - Perks.level(a));
    const cards = [
      { panel: 'hats', title: 'HATS', col: '#eae4d4', line: P.gear ? BULLETS[P.gear].name : 'bare head', sub: `${(P.gears || []).length} found`, draw: (cx, cy, r) => { this.circle(cx, cy, r, '#ffb86b', this.OUT, 3.5); this.shine(cx, cy, r); this.eyes(cx, cy, Math.PI / 2, r, { fwd: r * 0.3 }); this.mouth(cx, cy, Math.PI / 2, r, true); if (P.gear) this.drawGear(cx, cy, -Math.PI / 2, r, P.gear, 0); } },
      { panel: 'megas', title: 'MEGA', col: MEGAS[mega].color, line: Mega.label(mega), sub: `${G.mega.owned.length} found`, bar: Math.min(1, G.mega.charge / CFG.MEGA_MAX), draw: (cx, cy, r) => this.deckBadge('megas', mega, cx, cy, r, false) },
      { panel: 'perks', title: 'PERKS', col: '#c58aff', line: `${G.perks.length} taken`, sub: perks.slice(0, 3).map(id => PERKS[id].name + (Perks.level(id) > 1 ? ` ×${Perks.level(id)}` : '')).join(' · ') || 'none yet: clear a dungeon', draw: (cx, cy, r) => Icons.draw('trophy', cx, cy, r * 0.55, '#c58aff') },
    ];
    cards.forEach((o, i) => {
      const x = M + i * (w + gap), y = top, sel = B.row === 'hub' && B.hubSel === i, k = 0.5 + 0.5 * Math.sin(G.time * 3 + i), cx = x + w / 2, r = Math.min(w, h) * 0.16, py = y + h * 0.38, ly = py + r * 1.45 + 26 * T; // (a hat sits well outside the head: the line goes under it)
      c.fillStyle = sel ? this.tint(o.col, 0.16) : 'rgba(255,255,255,0.05)'; this.roundRect(x, y, w, h, 20 * T); c.fill();
      c.strokeStyle = sel ? '#fff' : this.tint(o.col, 0.5 + 0.3 * k); c.lineWidth = sel ? 4 : 2.5; c.stroke();
      this.text(o.title, cx, y + 36 * T, 24 * T, o.col, 'center');
      o.draw(cx, py, r);
      this.text(this.fitText(o.line, w - 30 * T, 18 * T), cx, ly, 18 * T, '#fff', 'center');
      if (o.bar != null) { const bw = w * 0.6, bx = cx - bw / 2, by = ly + 20 * T; c.fillStyle = 'rgba(0,0,0,0.4)'; this.roundRect(bx, by, bw, 10 * T, 5 * T); c.fill(); if (o.bar > 0) { c.fillStyle = o.col; this.roundRect(bx, by, bw * o.bar, 10 * T, 5 * T); c.fill(); } this.text(`${Math.floor(o.bar * 100)}% charged`, cx, by + 24 * T, 12 * T, '#ffe9a8', 'center', 'normal'); }
      this.benchWrap(o.sub, x + 20 * T, y + h - 34 * T, 13 * T, 'rgba(255,255,255,0.65)', w - 40 * T, 18 * T, 2);
      const rect = { panel: o.panel, i, x, y, w, h }; B.hit.goto.push(rect); B.hit.hub.push(rect);
    });
    if (!Input.touch) this.text('TAB switches tabs  ·  arrows choose  ·  ENTER opens  ·  ESC back', CFG.W / 2, CFG.H - 18, 12, 'rgba(255,255,255,0.45)', 'center', 'normal');
  },

  // ---- the PERKS tab: every perk you have, its level, what your copies add up to and what the next one costs; then your mega
  // (only one at a time: where it comes from, how charged it is). Selecting a card shows its whole story on the right ----
  benchPerks() {
    const c = this.ctx, B = Build, T = this.benchText(), M = this.BENCH.M, top = this.benchTopH() + 24 * T;
    const counts = new Map(); for (const id of G.perks) counts.set(id, (counts.get(id) || 0) + 1);
    const owned = [...counts.entries()].filter(([id]) => PERKS[id]).sort((a, b) => PERKS[a[0]].name.localeCompare(PERKS[b[0]].name)) /* alphabetical by name */, I = this.benchInfo(), infoW = I.w, gridW = CFG.W - M * 3 - infoW;
    const ch = 132 * T, gap = 14 * T, n = owned.length, y0 = top + 22 * T, availH = CFG.H - 14 * T - y0;
    // too many for the screen: the cards stack in columns, each overlapping the one above so only its header shows
    // (a column per alphabetical run), and the selected one is pulled out whole on top. Nothing scrolls
    let cols = Math.max(1, Math.floor((gridW + gap) / (232 * T + gap))), cw = 232 * T;
    const lay = k => { const rows = Math.ceil(n / k); return { k, rows, cw: Math.min(232 * T, (gridW - gap * (k - 1)) / k), peek: rows > 1 ? Math.min(ch + gap, (availH - ch) / (rows - 1)) : ch + gap }; };
    let L = lay(cols); if (L.peek < ch + gap) { const L2 = lay(cols + 1); if (L2.cw >= 200 * T && L2.peek > L.peek) L = L2; }
    cols = L.k; cw = L.cw; const rowsN = L.rows, peek = L.peek, stacked = peek < ch + gap;
    this.text(`PERKS  ·  ${G.perks.length} taken`, M, top, 18 * T, '#c58aff', 'left');
    Icons.strip([{ icon: 'ghost', color: '#7de0ff', text: `${G.ghosts} to spend at the next shop` }], CFG.W - M * 2 - infoW, top, 8 * T, 'right');
    let selId = null;
    if (!owned.length) this.benchWrap('No perks yet. Clear a dungeon (eat every dot) and the perk shop opens: one pick is free, and every ghost you ate buys another.', M, y0 + 20 * T, 16 * T, 'rgba(255,255,255,0.7)', gridW, 22 * T);
    if (B.perkSel >= owned.length) B.perkSel = Math.max(0, owned.length - 1);
    // where each card sits: down each column (a stack reads alphabetically); in a stack each card shows its header and the
    // selected one opens to full height, pushing the rest of its stack down (the stack runs on under the panel's bottom edge)
    const head = 56 * T, neat = q => q > head && q < 84 * T ? head : q, bottom = y0 + availH, selI = B.row === 'perks' ? B.perkSel : -1, pos = [];
    for (let col = 0; col * rowsN < n; col++) {
      const ids = []; for (let r = 0; r < rowsN && col * rowsN + r < n; r++) ids.push(col * rowsN + r);
      const m = ids.length, hasSel = ids.includes(selI) && selI !== ids[m - 1];
      const p = !stacked ? ch + gap : neat(hasSel ? Math.min(peek, m > 2 ? (availH - ch - gap - head) / (m - 2) : peek) : peek);
      let y = y0; for (const i of ids) { const open = !stacked || i === selI || i === ids[m - 1]; pos[i] = { x: M + col * (cw + gap), y, h: Math.min(open ? ch : p, bottom - y) }; y += i === selI && stacked ? ch + gap : p; }
    }
    if (!stacked) owned.forEach((_, i) => { pos[i] = { x: M + (i % cols) * (cw + gap), y: y0 + Math.floor(i / cols) * (ch + gap), h: ch }; });
    c.save(); if (stacked) { c.beginPath(); c.rect(0, y0 - 8 * T, M + gridW + 8 * T, availH + 8 * T + 2); c.clip(); }
    owned.forEach(([id, cnt], i) => {
      const Pk = PERKS[id], PI = PERK_ICONS[id] || ['dmg'], { x, y } = pos[i], sel = B.row === 'perks' && B.perkSel === i;
      if (sel) selId = id;
      if (stacked) { c.fillStyle = '#1a1228'; this.roundRect(x, y, cw, ch, 14 * T); c.fill(); } // opaque: the card in front hides the body of the one behind
      c.fillStyle = sel ? 'rgba(255,255,255,0.13)' : 'rgba(255,255,255,0.05)'; this.roundRect(x, y, cw, ch, 14 * T); c.fill(); c.strokeStyle = sel ? Pk.color : 'rgba(255,255,255,0.2)'; c.lineWidth = sel ? 3 : 1.5; c.stroke();
      this.circle(x + 30 * T, y + 30 * T, 18 * T, Pk.color, this.OUT, 2.5); Icons.draw(PI[0], x + 30 * T, y + 30 * T, 11 * T, this.OUT);
      this.text(this.fitText(Pk.name, cw - 120 * T, 16 * T), x + 56 * T, y + 24 * T, 16 * T, '#fff', 'left');
      this.text(`Lv ${cnt}`, x + 56 * T, y + 44 * T, 13 * T, '#ffe9a8', 'left');
      let ly = y + 72 * T; for (const line of Perks.total(id, cnt)) { this.text(this.fitText(line, cw - 28 * T, 13 * T), x + 14 * T, ly, 13 * T, '#d8f0c8', 'left', 'normal'); ly += 18 * T; }
      if (!Pk.mega) { Icons.strip([{ icon: 'ghost', color: '#7de0ff', text: `next ${Perks.priceAt(id, cnt)}` }], x + cw - 10 * T, y + 24 * T, 6.5 * T, 'right'); }
      B.hit.perks.push({ i, x, y, w: cw, h: pos[i].h });
    });
    c.restore();
    // element synergy, under the cards (the mega's card lives on the BODY page)
    const sy = (stacked ? Math.max(...pos.map(q => q.y + q.h)) + gap : y0 + Math.max(1, Math.ceil(n / cols)) * (ch + gap)) + 14 * T;
    if (sy < CFG.H - 20 * T) this.benchSynergy(M, sy, gridW);
    // the info panel: the selected perk's whole story
    const ix = I.x, iy = I.y, ih = I.h; // the same card as BODY and FORGE
    c.fillStyle = 'rgba(255,255,255,0.05)'; this.roundRect(ix, iy, infoW, ih, 14 * T); c.fill(); c.strokeStyle = 'rgba(255,255,255,0.2)'; c.lineWidth = 1.5; c.stroke();
    if (selId) {
      const Pk = PERKS[selId], n = counts.get(selId); let ly = iy + 30 * T;
      this.text(Pk.name, ix + 18 * T, ly, 20 * T, Pk.color, 'left'); ly += 26 * T;
      this.text(`Lv ${n}  ·  the next copy costs ${Perks.priceAt(selId, n)} ghost${Perks.priceAt(selId, n) === 1 ? '' : 's'}`, ix + 18 * T, ly, 13 * T, '#ffe9a8', 'left', 'normal'); ly += 26 * T;
      ly = this.benchWrap(Pk.desc, ix + 18 * T, ly, 14 * T, '#fff', infoW - 36 * T, 20 * T, 9) + 14;
      this.text('All your copies together:', ix + 18 * T, ly, 13 * T, 'rgba(255,255,255,0.6)', 'left', 'normal'); ly += 20 * T;
      for (const line of Perks.total(selId, n)) { ly = this.benchWrap('· ' + line, ix + 18 * T, ly, 14 * T, '#d8f0c8', infoW - 36 * T, 19 * T, 2) + 2; }
    } else this.benchWrap(owned.length ? (Input.touch ? 'Tap a perk to see what it does.' : 'Arrow keys (or a click) pick a perk to see what it does.') : 'Your perks show here once you have some.', ix + 18 * T, iy + 30 * T, 14 * T, 'rgba(255,255,255,0.6)', infoW - 36 * T, 20 * T);
  },

  // the help overlay (the ? button or /): every key, in one place instead of a permanent line of hints
  benchHelp() {
    const c = this.ctx, w = 560, h = 300, x = CFG.W / 2 - w / 2, y = CFG.H / 2 - h / 2;
    c.fillStyle = 'rgba(10,4,20,0.7)'; c.fillRect(0, 0, CFG.W, CFG.H);
    c.fillStyle = '#1f1830'; this.roundRect(x, y, w, h, 18); c.fill(); c.strokeStyle = '#ffd166'; c.lineWidth = 3; c.stroke();
    this.text('KEYS', x + 24, y + 32, 20, '#ffd166', 'left');
    const rows = [['TAB', 'switch between LOADOUT, FORGE, PERKS, MEGAS and HATS'], ['WASD / arrows', 'move the cursor: the tabs, body rows, bag rows, crafting cards'], ['ENTER', 'bag: add to the body (swap when full)  ·  card: craft it'], ['X', 'stash the body segment in the bag'], ['Q / E', 'shift a segment toward the head / tail'], ['C', 'mark an ingredient, C on a partner fuses'], ['DEL', 'BODY: take the segment off (to the bag)  ·  FORGE: scrap it (+2 dots)'], ['Z  ·  B  ·  V', 'fill empty slots from the bag  ·  save the loadout  ·  view the loadout'], ['ESC  ·  P', 'back to the game (ESC also drops a mark)']];
    rows.forEach(([k, d], i) => { const ry = y + 66 + i * 22; this.text(k, x + 24, ry, 13, '#7df9ff', 'left'); this.text(d, x + 190, ry, 13, '#fff', 'left', 'normal'); });
    Build.hit.buttons.push({ code: 'Slash', x, y, w, h });
  },

  // the deck pages' shared bits: the items, which you own, which is active, and a round badge for each
  deckOf(kind) {
    const mega = kind === 'megas', P = Player;
    return { mega, all: mega ? Object.keys(MEGAS) : Object.keys(BULLETS).filter(k => BULLETS[k].kind === 'head'), owns: id => mega ? Mega.has(id) : P.ownsGear(id), act: mega ? Mega.current() : P.gear, color: id => (mega ? MEGAS[id] : BULLETS[id]).color, name: id => (mega ? MEGAS[id] : BULLETS[id]).name };
  },

  deckBadge(kind, id, x, y, r, dim) {
    const c = this.ctx; c.save(); if (dim) c.globalAlpha *= 0.35;
    if (kind === 'megas') { this.circle(x, y, r, MEGAS[id].color, this.OUT, 2.5); this.shine(x, y, r); Icons.draw('mega', x, y, r * 0.55, this.OUT); }
    else { this.circle(x, y, r, '#ffb86b', this.OUT, 2.5); this.shine(x, y, r); this.drawGear(x, y, -Math.PI / 2, r, id, 0); }
    c.restore();
  },

  gearItems(B) { // a head gear's own numbers (its bullet stats are zero: the hit lives in gear)
    const K = B.gear || {}, col = B.color, items = [];
    if (K.type) items.push({ icon: 'helmet', text: K.type, color: col, textColor: col });
    if (K.dmg) items.push({ icon: 'dmg', text: `${Math.round(K.dmg * (G.powerMult || 1))}`, color: '#ff7b6b' }); else if (K.dps) items.push({ icon: 'dmg', text: `${Math.round(K.dps * (G.powerMult || 1))}/s`, color: '#ff7b6b' });
    if (K.range) items.push({ icon: 'range', text: `${K.range}`, color: '#ccc' });
    const el = K.el || K.pipe; if (el && ['fire', 'ice', 'electric', 'poison'].includes(el)) items.push({ icon: el, color: ELEM_COLORS[el] });
    return items;
  },

  // ---- the MEGA DECK and HAT RACK pages: every mega (or hat) you have found as a card like the PERKS page (unfound ones stay
  // hidden; a line under the cards says where more come from). The active one lives in the right panel; pick a card and it
  // floats over to take its place ----
  benchDeck(kind) {
    const c = this.ctx, B = Build, T = this.benchText(), M = this.BENCH.M, D = this.deckOf(kind), zone = kind === 'megas' ? 'megas' : 'gears', I = this.benchInfo();
    const top = this.benchTopH() + 24 * T, gridW = I.x - 24 * T - M, y0 = top + 22 * T, availH = CFG.H - 14 * T - y0, gap = 14 * T;
    const list = D.all.filter(D.owns), n = list.length, ownedN = n; // only what you have found (finding them is the point); every card keeps its place
    this.text(D.mega ? `MEGA DECK  ·  ${ownedN} found` : `HAT RACK  ·  ${ownedN} found`, M, top, 18 * T, D.mega ? '#7df9ff' : '#eae4d4', 'left');
    if (ownedN < D.all.length) this.benchWrap(D.mega ? 'More megas are out there: unique monsters guard them, and the perk shop turns one up now and then.' : ownedN ? 'More hats are out there: monsters drop them, and some can be fused.' : 'No hats yet: monsters drop them, and some can be fused.', M, y0 + Math.ceil(n / Math.max(1, Math.floor((gridW + gap) / (220 * T + gap)))) * (Math.min(150 * T, availH) + gap) + 10 * T, 14 * T, 'rgba(255,255,255,0.45)', gridW, 20 * T, 2);
    this.text(this.fitText(D.mega ? 'one active at a time · switching empties the bar' : 'wear one at a time · switching is free', gridW * 0.55, 13 * T), M + gridW, top, 13 * T, 'rgba(255,255,255,0.55)', 'right', 'normal');
    const cols = Math.max(1, Math.floor((gridW + gap) / (220 * T + gap))), cw = (gridW - gap * (cols - 1)) / cols, rows = Math.max(1, Math.ceil(n / cols)), ch = Math.min(150 * T, (availH - gap * (rows - 1)) / rows);
    const sel = B.row === zone ? (zone === 'megas' ? B.megaSel : B.gearSel) : -1;
    list.forEach((id, i) => {
      const x = M + (i % cols) * (cw + gap), y = y0 + Math.floor(i / cols) * (ch + gap), on = i === sel, armed = D.mega && B.megaArm === id, col = D.color(id);
      const isAct = id === D.act; // the active one: lit up in its colour (and shown in full in the panel too)
      if (isAct) { const k = 0.5 + 0.5 * Math.sin(G.time * 3); c.save(); c.shadowColor = col; c.shadowBlur = 14 + 10 * k; c.fillStyle = this.tint(col, 0.16); this.roundRect(x, y, cw, ch, 14 * T); c.fill(); c.restore(); }
      c.fillStyle = on ? 'rgba(255,255,255,0.13)' : 'rgba(255,255,255,0.06)'; this.roundRect(x, y, cw, ch, 14 * T); c.fill();
      c.strokeStyle = armed ? '#ffb347' : isAct ? col : on ? col : this.tint(col, 0.6); c.lineWidth = isAct ? 4 : on || armed ? 3 : 1.5; c.stroke();
      if (on && isAct) { c.strokeStyle = '#fff'; c.lineWidth = 2; this.roundRect(x - 5, y - 5, cw + 10, ch + 10, 17 * T); c.stroke(); } // (the keyboard cursor on the active card)
      if (isAct) { const r = 14 * T, cx = x + cw - r - 10 * T, cy = y + ch - r - 10 * T; this.circle(cx, cy, r, '#9bf06a', this.OUT, 2.5); c.strokeStyle = this.OUT; c.lineWidth = 3.5 * T; c.lineCap = 'round'; c.lineJoin = 'round'; c.beginPath(); c.moveTo(cx - r * 0.45, cy + r * 0.02); c.lineTo(cx - r * 0.1, cy + r * 0.38); c.lineTo(cx + r * 0.48, cy - r * 0.35); c.stroke(); } // a green check, bottom right
      const ny = y + 20 * T, sy = y + 41 * T, br = 18 * T; this.deckBadge(kind, id, x + 14 * T + br, (ny + sy) / 2, br, false); // the name and a line under it, the badge beside both
      const tx = x + 26 * T + br * 2; this.text(this.fitText(D.name(id), cw - (tx - x) - 10 * T, 16 * T), tx, ny, 16 * T, col, 'left');
      if (!D.mega) Icons.strip(this.gearItems(BULLETS[id]), tx, sy, 6 * T, 'left');
      const by = y + 62 * T, lines = Math.max(1, Math.floor((y + ch - 8 * T - by) / (18 * T)) + 1);
      let txt = D.mega ? MEGAS[id].card : BULLETS[id].desc.replace(/^head gear: /, '');
      if (armed) txt = `${Input.touch ? 'Tap' : 'Pick'} it again to switch: your bar (${Math.floor(100 * G.mega.charge / CFG.MEGA_MAX)}%) empties.`;
      this.benchWrap(txt, x + 14 * T, by, 13 * T, armed ? '#ffb347' : 'rgba(255,255,255,0.78)', cw - 28 * T, 18 * T, lines);
      B.hit[zone].push({ x, y, w: cw, h: ch, i, id });
    });
    // the right panel: the active one, in full. While a picked card floats over, the panel waits for it
    const ix = I.x, iy = I.y, iw = I.w, ih = I.h, F = B.fly && B.fly.kind === kind ? B.fly : null, k = F ? U.clamp((performance.now() - F.t0) / 380, 0, 1) : 1;
    const act = D.act, acol = act ? D.color(act) : 'rgba(255,255,255,0.3)';
    c.fillStyle = 'rgba(255,255,255,0.05)'; this.roundRect(ix, iy, iw, ih, 14 * T); c.fill(); c.strokeStyle = act ? acol : 'rgba(255,255,255,0.2)'; c.lineWidth = act ? 2.5 : 1.5; c.stroke();
    this.text(D.mega ? 'ACTIVE MEGA' : 'WEARING', ix + 18 * T, iy + 24 * T, 13 * T, 'rgba(255,255,255,0.55)', 'left');
    const bx = ix + 18 * T + 30 * T, byy = iy + 84 * T; // (room above for a hat's flourish: the Flamethrower's petals)
    if (k >= 1) {
      if (F && !F.landed) { F.landed = true; BenchFx.light(bx, byy, act ? D.color(act) : '#fff'); SFX.play('land'); }
      if (!act) this.benchWrap(Input.touch ? 'Bare head. Tap a hat to wear it.' : 'Bare head. Pick a hat (arrows + ENTER, or a click) to wear it.', ix + 18 * T, iy + 56 * T, 14 * T, 'rgba(255,255,255,0.6)', iw - 36 * T, 20 * T);
      else {
        this.deckBadge(kind, act, bx, byy, 30 * T, false);
        this.text(this.fitText(D.mega ? Mega.label(act) : D.name(act), iw - 110 * T, 20 * T), bx + 42 * T, byy - 8 * T, 20 * T, acol, 'left');
        let ly = byy + 50 * T;
        if (D.mega) { const q = Math.min(1, G.mega.charge / CFG.MEGA_MAX); this.text(`${Math.floor(100 * q)}% charged`, bx + 42 * T, byy + 16 * T, 13 * T, '#ffe9a8', 'left', 'normal'); c.fillStyle = 'rgba(0,0,0,0.35)'; this.roundRect(ix + 18 * T, ly - 10 * T, iw - 36 * T, 8 * T, 4 * T); c.fill(); if (q > 0) { c.fillStyle = acol; this.roundRect(ix + 18 * T, ly - 10 * T, (iw - 36 * T) * q, 8 * T, 4 * T); c.fill(); } ly += 14 * T; }
        else { Icons.strip(this.gearItems(BULLETS[act]), bx + 42 * T, byy + 16 * T, 7 * T, 'left'); }
        ly = this.benchWrap(D.mega ? MEGAS[act].desc : BULLETS[act].desc.replace(/^head gear: /, '') + '. Head-first crashes bounce you off while you wear it.', ix + 18 * T, ly, 14 * T, '#fff', iw - 36 * T, 20 * T, 10) + 10 * T;
        if (n > 1) this.benchWrap(D.mega ? (G.mega.charge > 0 ? `Switching empties the bar. ${Input.touch ? 'Tap' : 'Pick'} a card twice to switch.` : `${Input.touch ? 'Tap' : 'Pick'} a card to make it active.`) : `${Input.touch ? 'Tap' : 'Pick'} a hat to wear it instead.`, ix + 18 * T, ly, 13 * T, 'rgba(255,255,255,0.55)', iw - 36 * T, 18 * T, 3);
      }
    } else { // the picked card on its way: from where it sat to the top of the panel, shrinking into the badge
      const e = 1 - Math.pow(1 - k, 3), f = F.from, to = { x: ix + 10 * T, y: iy + 40 * T, w: iw - 20 * T, h: 70 * T }, x = f.x + (to.x - f.x) * e, y = f.y + (to.y - f.y) * e - Math.sin(k * Math.PI) * 40 * T, w = f.w + (to.w - f.w) * e, h = f.h + (to.h - f.h) * e;
      c.save(); c.shadowColor = D.color(F.id); c.shadowBlur = 20; c.fillStyle = '#241a36'; this.roundRect(x, y, w, h, 14 * T); c.fill(); c.restore(); c.strokeStyle = D.color(F.id); c.lineWidth = 3; this.roundRect(x, y, w, h, 14 * T); c.stroke();
      const br = Math.min(22 * T, h * 0.3); this.deckBadge(kind, F.id, x + 14 * T + br, y + h / 2, br, false); this.text(this.fitText(D.name(F.id), w - br * 2 - 40 * T, 16 * T), x + 26 * T + br * 2, y + h / 2, 16 * T, D.color(F.id), 'left');
    }
  },

  // LOADOUT's two small cards under the body: the active mega (its charge along the bottom) and the hat you wear. Each opens its page
  benchMega(x, y, w, h) {
    const c = this.ctx, T = this.benchText(), mid = Mega.current(), MG = MEGAS[mid], k = Math.min(1, G.mega.charge / CFG.MEGA_MAX);
    c.fillStyle = 'rgba(255,255,255,0.05)'; this.roundRect(x, y, w, h, 14 * T); c.fill(); c.strokeStyle = MG.color; c.lineWidth = 2; c.stroke();
    c.fillStyle = 'rgba(0,0,0,0.35)'; this.roundRect(x + 10, y + h - 9, w - 20, 5, 2.5); c.fill(); if (k > 0) { c.fillStyle = MG.color; this.roundRect(x + 10, y + h - 9, (w - 20) * k, 5, 2.5); c.fill(); }
    const br = Math.min(16 * T, h * 0.2); this.deckBadge('megas', mid, x + 12 * T + br, y + h * 0.3, br, false);
    this.text(this.fitText(`MEGA  ·  ${Mega.label(mid)}  ·  ${Math.floor(100 * k)}%`, w - br * 2 - 80 * T, 16 * T), x + 22 * T + br * 2, y + h * 0.3, 16 * T, MG.color, 'left');
    this.text(`deck ${Mega.owned().length}  ›`, x + w - 12 * T, y + h * 0.3, 13 * T, 'rgba(255,255,255,0.6)', 'right');
    this.benchWrap(MG.card, x + 14 * T, y + h * 0.62, 13 * T, 'rgba(255,255,255,0.75)', w - 28 * T, 16 * T, 2);
    Build.hit.goto.push({ panel: 'megas', x, y, w, h });
  },

  benchGear(x, y, w, h) {
    const c = this.ctx, T = this.benchText(), P = Player, id = P.gear, B = id && BULLETS[id], col = B ? B.color : 'rgba(255,255,255,0.35)', all = this.deckOf('hats').all.length;
    c.fillStyle = 'rgba(255,255,255,0.05)'; this.roundRect(x, y, w, h, 14 * T); c.fill(); c.strokeStyle = col; c.lineWidth = 2; c.stroke();
    const br = Math.min(16 * T, h * 0.2);
    if (B) this.deckBadge('hats', id, x + 12 * T + br, y + h * 0.3, br, false); else Icons.draw('helmet', x + 12 * T + br, y + h * 0.3, br * 0.7, col);
    this.text(this.fitText(`HAT  ·  ${B ? B.name : 'bare head'}`, w - br * 2 - 80 * T, 16 * T), x + 22 * T + br * 2, y + h * 0.3, 16 * T, B ? col : 'rgba(255,255,255,0.6)', 'left');
    this.text(`rack ${(P.gears || []).length}  ›`, x + w - 12 * T, y + h * 0.3, 13 * T, 'rgba(255,255,255,0.6)', 'right');
    if (B) { const items = this.gearItems(B), sw = Icons.stripWidth(items, 6.5 * T); Icons.strip(items, x + 14 * T, y + h * 0.66, 6.5 * T, 'left'); this.text(this.fitText(B.desc.replace(/^head gear: /, ''), w - 40 * T - sw, 13 * T), x + 24 * T + sw, y + h * 0.66, 13 * T, 'rgba(255,255,255,0.75)', 'left', 'normal'); }
    else this.benchWrap((P.gears || []).length ? 'Tap to pick a hat from your rack.' : 'Find head gear on monsters, or fuse it.', x + 14 * T, y + h * 0.62, 13 * T, 'rgba(255,255,255,0.6)', w - 28 * T, 16 * T, 2);
    Build.hit.goto.push({ panel: 'hats', x, y, w, h });
  },
});
