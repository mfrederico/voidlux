// The workbench (the pause screen): two panels under one top bar.
//   BODY  — the centipede front and centre, the blueprint under it, the build readout (dps big), the gallery lane.
//   FORGE — the working area on the left two thirds (a compact body strip centred over it, the bag, the result cards
//           with where each result will land) and the info card on the right third: the selected piece, what it does,
//           what you can do with it, joined to its tile by a connector line.
// On touch screens the text that matters is drawn bigger (benchText: tabs, names, the info card, the cards' names and
// destinations, the dps) while the layout stays the same, and a two-finger pinch magnifies the whole screen to read it,
// springing back when the fingers lift (Input.pinch). Every tile and button records a hit rect in Build.hit; Build
// (workbench.js) does the tapping, dragging and keys; BenchFx (benchfx.js) plays the fusion choreography on top.
// The workbench is split by page: this file has the shared widgets, the screen frame and top bar, and the body row both
// panels use; render-bench-body.js the BODY (LOADOUT) panel, render-bench-perks.js the PERKS, MEGAS and HATS pages,
// render-bench-forge.js the FORGE. Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  benchText() { return Input.touch ? 1.5 : 1; }, // the important text, 50% bigger on a phone or tablet

  BENCH: { M: 40 },

  // ---- widgets ----
  // a pill button with an icon and a label; records a hit rect for `code` and returns the rect
  benchPill(x, y, w, h, label, icon, col, active, code, small) {
    const c = this.ctx, T = this.benchText();
    c.fillStyle = active ? this.tint(col, 0.22) : 'rgba(255,255,255,0.08)';
    this.roundRect(x, y, w, h, h / 2); c.fill(); c.strokeStyle = active ? col : 'rgba(255,255,255,0.35)'; c.lineWidth = active ? 2 : 1.5; c.stroke();
    const fs = (small ? 12 : 14) * T, is = (small ? 7 : 8) * T, tx = icon ? x + 20 + is * 2 : x + w / 2;
    if (icon) Icons.draw(icon, x + 10 + is, y + h / 2, is, active ? col : '#ddd');
    this.text(this.fitText(label, w - (icon ? 30 + is * 2 : 16), fs), tx, y + h / 2 + 1, fs, active ? col : '#ddd', icon ? 'left' : 'center');
    const r = { code, x, y, w, h }; if (code) Build.hit.buttons.push(r); return r;
  },

  // the largest font size down to `minSize` at which `s` fits in maxW, truncating only as a last resort: { size, text }
  benchFit(s, maxW, size, minSize) { const c = this.ctx; for (let f = size; f >= (minSize || size * 0.7); f -= 1) { c.font = `bold ${f}px "Trebuchet MS", "Segoe UI", sans-serif`; if (c.measureText(s).width <= maxW) return { size: f, text: s }; } const f = minSize || size * 0.7; return { size: f, text: this.fitText(s, maxW, f) }; },

  fitText(s, maxW, size) { const c = this.ctx; c.font = `bold ${size}px "Trebuchet MS", "Segoe UI", sans-serif`; if (c.measureText(s).width <= maxW) return s; while (s.length > 1 && c.measureText(s + '…').width > maxW) s = s.slice(0, -1); return s + '…'; },

  // a bullet's tier as stars (and the hammer when it is fused); returns the x after them
  benchTier(n, x, y, s, fused) { for (let k = 0; k < n; k++) Icons.draw('mega', x + s + k * s * 2.2, y, s, '#ffd166'); let ex = x + n * s * 2.2 + s * 0.4; if (fused) { Icons.draw('hammer', ex + s, y, s, '#ffe9a8'); ex += s * 2.4; } return ex; },

  // wrapped text; returns the y below the last line
  benchWrap(s, x, y, size, color, maxW, lh, maxLines) {
    const c = this.ctx; c.font = `normal ${size}px "Trebuchet MS", "Segoe UI", sans-serif`;
    const words = String(s || '').split(' '), max = maxLines || 99; let line = '', n = 0;
    for (const w of words) {
      const t = line ? line + ' ' + w : w;
      if (c.measureText(t).width <= maxW || !line) { line = t; continue; }
      if (n + 1 >= max) { // the last line is full and words remain: it ends in an ellipsis, inside the width
        while (line && c.measureText(line + '…').width > maxW) line = line.slice(0, -1);
        line = line.replace(/[\s.,;:]+$/, '') + '…'; break;
      }
      this.text(line, x, y, size, color, 'left', 'normal'); y += lh; n++; line = w;
      c.font = `normal ${size}px "Trebuchet MS", "Segoe UI", sans-serif`; // (text() may have set another font: measure in this one)
    }
    if (line) { this.text(line, x, y, size, color, 'left', 'normal'); y += lh; }
    return y;
  },

  // the chips under a body segment: what it does to what it hits (burn, freeze, spark, poison, blind, berserk) and the
  // status it inherits from the segment ahead (the pipe, ringed in its colour with a +). [{ icon, color, pipe }]
  pipeIcon(pipe) { return pipe === 'ricochet' ? 'bounce' : pipe === 'split' ? 'shards' : pipe === 'dry' ? 'spear' : pipe; },

  buildChips(B, pipe) {
    const out = [], seen = new Set(), add = (icon, color, p) => { if (seen.has(icon + (p ? '+' : ''))) return; seen.add(icon + (p ? '+' : '')); out.push({ icon, color, pipe: !!p }); };
    if (pipe && PIPES[pipe]) add(this.pipeIcon(pipe), PIPES[pipe].color, true);
    for (const e of (B.elems || [])) add(e, ELEM_COLORS[e]);
    if (B.combo === 'gas' || B.combo === 'bomb') add('poison', ELEM_COLORS.poison);
    if (B.combo === 'napalm') add('fire', ELEM_COLORS.fire);
    if (B.combo === 'berserk' || B.combo === 'madmoth') add('eye', '#ff7bff');
    if (B.combo === 'steam') add('dazzle', ELEM_COLORS.dazzle);
    if (B.bounces || B.hops || B.kind === 'bounce') add('bounce', '#ffd166');
    return out;
  },

  drawChips(chips, cx, y, s) {
    const c = this.ctx, r = s * 1.45, pitch = r * 2 + 3; let x = cx - ((chips.length - 1) * pitch) / 2;
    for (const ch of chips) {
      c.fillStyle = this.OUT; c.beginPath(); c.arc(x, y, r, 0, TAU); c.fill();
      if (ch.pipe) { c.strokeStyle = ch.color; c.lineWidth = Math.max(1.2, s * 0.25); c.stroke(); }
      Icons.draw(ch.icon, x, y, s, ch.color);
      if (ch.pipe) { c.fillStyle = ch.color; c.beginPath(); c.arc(x + r * 0.75, y - r * 0.75, Math.max(2.5, s * 0.5), 0, TAU); c.fill(); this.text('+', x + r * 0.75, y - r * 0.75 + 1, Math.max(6, s * 0.9), this.OUT, 'center'); }
      x += pitch;
    }
  },

  // what segment i would hand down to the one behind it (the piping rule in Player.resolveBuild): its bounce, its laser's
  // glare, or its first element. The segment behind only takes it if it does not have it already
  pipeFrom(i) { const s = Player.segments[i]; if (!s) return null; const F = BULLETS[s.id]; return F.kind === 'bounce' || F.bounces > 0 ? 'ricochet' : F.shards ? 'split' : F.kind === 'homing' ? 'homing' : F.burst ? 'burst' : F.spear && !(F.elems || []).length ? 'dry' : F.laser ? 'dazzle' : (F.elems || [])[0] || null; },

  // the status chain of body segment i, centred at cx: the segment ahead and the status it hands down, this segment with
  // its own statuses, and what it passes to the one behind. Returns the height used
  benchChain(cx, y, i, T, avail) {
    const c = this.ctx, P = Player, segs = P.segments, s = segs[i]; if (!s) return 0;
    const ahead = i > 0 ? segs[i - 1] : null, behind = segs[i + 1] || null, gives = this.pipeFrom(i), takes = behind && behind.pipe === gives ? gives : null;
    // roomy: the tiles sit on one line, the chip rides above each arrow and its tag sits below it, the slot labels
    // hang well under the tiles, and the segment's own status chips float above it
    const K = Math.min(T, 1.3), small = 40 * K, big = 56 * K, fs = (Input.touch ? 13 : 11) * T, ty = y + 26 * T + big / 2, width = avail || 340;
    const gap = Math.max(60, Math.min(120, (width - big - small * 2 - 24) / 2)), x0 = cx - big / 2 - gap, x2 = cx + big / 2 + gap; // x0 / x2: the inner edges of the outer tiles
    const tile = (id, x, sz, dim) => { const W = BULLETS[id]; c.save(); if (dim) c.globalAlpha = 0.55; c.fillStyle = W.color; this.roundRect(x - sz / 2, ty - sz / 2, sz, sz, sz * 0.18); c.fill(); c.strokeStyle = W.craft ? '#fff' : this.OUT; c.lineWidth = 2; c.stroke(); Icons.tile(W, x, ty, sz, c); c.restore(); };
    const arrow = (x1, x2, col) => { c.strokeStyle = col; c.lineWidth = 2; c.setLineDash([4, 4]); c.beginPath(); c.moveTo(x1, ty); c.lineTo(x2, ty); c.stroke(); c.setLineDash([]); c.beginPath(); c.moveTo(x2 - 7, ty - 5); c.lineTo(x2, ty); c.lineTo(x2 - 7, ty + 5); c.stroke(); };
    const link = (xa, xb, pipe, none, boost) => { // an arrow with what travels along it: the chip above, the tag below
      const mx = (xa + xb) / 2; arrow(xa + 6, xb - 6, pipe ? PIPES[pipe].color : 'rgba(255,255,255,0.25)');
      if (pipe) { this.drawChips([{ icon: this.pipeIcon(pipe), color: PIPES[pipe].color, pipe: true }], mx, ty - 18 * T, 5 * T); this.text((boost ? '+++' : '+') + PIPES[pipe].tag, mx, ty + 18 * T, boost ? fs * 1.15 : fs, PIPES[pipe].color, 'center'); } // +++: charged, behind a run of 3+
      else this.text(none, mx, ty + 18 * T, fs, 'rgba(255,255,255,0.4)', 'center', 'normal');
    };
    const label = (x, n, bright) => { const col = bright ? '#fff' : 'rgba(255,255,255,0.6)'; Icons.strip([{ icon: 'segment', text: String(n), color: col, textColor: col }], x, ty + big / 2 + 16 * T, fs * 0.6, 'center'); }; // a slot: the segment icon and its number
    if (ahead) { tile(ahead.id, x0 - small / 2, small, !s.pipe); link(x0, cx - big / 2, s.pipe, '—', s.pipeBoost); label(x0 - small / 2, i); }
    tile(s.id, cx, big, false); label(cx, i + 1, true);
    const own = this.buildChips(BULLETS[s.id], null); if (own.length) this.drawChips(own, cx, ty - big / 2 - 14 * T, 5 * T);
    if (behind) { link(cx + big / 2, x2, takes, !gives ? '—' : (BULLETS[behind.id].elems || []).includes(gives) ? '✓' : ELEMENTS[gives] && (BULLETS[behind.id].elems || []).length ? '×' : '✓', takes && behind.pipeBoost); /* ✓ it has that already, × an element will not soak into a weapon with its own */ tile(behind.id, x2 + small / 2, small, !takes); label(x2 + small / 2, i + 2); }
    else if (gives) this.text(`→ ${P.synAt && P.synAt[i] && P.synAt[i].includes(gives) ? '+++' : '+'}${PIPES[gives].tag}`, cx + big / 2 + 16, ty, fs, 'rgba(255,255,255,0.5)', 'left', 'normal'); // what it would hand on to a segment behind it
    { const ch = (s.charged || []).filter(k => k !== s.pipe); // the charges the runs ahead send all the way down the body (the pipe from the segment ahead is on its arrow)
      if (ch.length) { this.text(this.fitText('charged by the runs ahead: ' + ch.map(k => '+++' + PIPES[k].tag).join('  '), Math.max(160, (avail || 300) - 10), fs * 0.85), cx, ty + big / 2 + 42 * T, fs * 0.85, PIPES[ch[0]].color, 'center'); return 26 * T + big + 60 * T; } }
    return 26 * T + big + 34 * T;
  },

  // one bullet tile with its states. st = { sel, marked, pairs, jam, ghost, hov, where, k }
  benchTile(id, cx, cy, size, st) {
    const c = this.ctx, w = BULLETS[id]; st = st || {};
    const pend = st.where != null && BenchFx.isPending(st.where, st.k), pulse = st.where != null ? BenchFx.slotPulse(st.where, st.k) : 0, ls = st.where != null ? BenchFx.landScale(st.where, st.k) : null;
    if (pulse > 0) { c.save(); c.globalAlpha = pulse; c.strokeStyle = w.color; c.lineWidth = 5; const g = (1 - pulse) * 22; this.roundRect(cx - size / 2 - 8 - g, cy - size / 2 - 8 - g, size + 16 + g * 2, size + 16 + g * 2, size * 0.18 + 8); c.stroke(); c.restore(); }
    if (pend) { // the slot waits for its tile: a dashed outline in the colour of what is coming, with a spark
      c.save(); c.setLineDash([6, 6]); c.lineDashOffset = -G.time * 40; c.strokeStyle = w.color; c.lineWidth = 3; c.globalAlpha = 0.8; this.roundRect(cx - size / 2, cy - size / 2, size, size, size * 0.18); c.stroke(); c.restore();
      Icons.draw('dazzle', cx, cy, size * 0.12, this.tint(w.color, 0.5 + 0.5 * Math.sin(G.time * 10)));
      return;
    }
    c.save(); if (ls) { c.translate(cx, cy + size / 2); c.scale(ls.sx, ls.sy); c.translate(-cx, -cy - size / 2); }
    c.globalAlpha = st.ghost ? 0.3 : 1;
    c.fillStyle = st.jam ? '#5a3b8f' : w.color; this.roundRect(cx - size / 2, cy - size / 2, size, size, size * 0.18); c.fill();
    c.strokeStyle = st.sel ? '#fff' : this.OUT; c.lineWidth = st.sel ? 5 : 3; c.stroke();
    if (st.sel && !st.ghost) { c.strokeStyle = '#ffd166'; c.lineWidth = 3; this.roundRect(cx - size / 2 - 6, cy - size / 2 - 6, size + 12, size + 12, size * 0.18 + 4); c.stroke(); }
    if (st.marked) { c.strokeStyle = '#7df9ff'; c.lineWidth = 4; this.roundRect(cx - size / 2 - 9, cy - size / 2 - 9, size + 18, size + 18, size * 0.18 + 6); c.stroke(); this.text('A', cx + size / 2 - 6, cy - size / 2 + 6, Math.max(12, size * 0.2), '#7df9ff', 'center'); }
    else if (st.pairs) { c.strokeStyle = `rgba(125,249,255,${0.5 + 0.5 * Math.sin(G.time * 6)})`; c.lineWidth = st.hov ? 7 : 4; this.roundRect(cx - size / 2 - 9, cy - size / 2 - 9, size + 18, size + 18, size * 0.18 + 6); c.stroke(); this.text('+', cx + size / 2 - 6, cy - size / 2 + 6, Math.max(12, size * 0.22), '#7df9ff', 'center'); }
    else if (st.hov) { c.strokeStyle = '#fff'; c.lineWidth = 5; this.roundRect(cx - size / 2 - 9, cy - size / 2 - 9, size + 18, size + 18, size * 0.18 + 6); c.stroke(); }
    Icons.tile(w, cx, cy, size, c);
    c.restore();
    if (st.fresh && !st.ghost) this.zapFrame(cx, cy, size); // just out of the LAB
    if (st.isNew && !st.ghost) this.newBadge(cx, cy, size); // a kind you have never had before
  },
  // the lab's lightning frame: arcs running round a freshly crafted tile (cyan, a white core, a few sparks), redrawn each frame
  zapFrame(cx, cy, size) {
    const c = this.ctx, h = size / 2 + 7, seed = Math.floor(G.time * 16) + Math.round(cx * 7 + cy), rnd = n => { const v = Math.sin(seed * 12.9898 + n * 78.233) * 43758.5453; return v - Math.floor(v); };
    const pts = [], per = 7; // walk the square's edge, a jagged step at a time
    for (let side = 0; side < 4; side++) for (let i = 0; i < per; i++) {
      const t = i / per, j = (rnd(side * 31 + i) - 0.5) * 7, [x, y] = side === 0 ? [-h + 2 * h * t, -h + j] : side === 1 ? [h + j, -h + 2 * h * t] : side === 2 ? [h - 2 * h * t, h + j] : [-h + j, h - 2 * h * t];
      pts.push([cx + x, cy + y]);
    }
    c.save(); c.globalCompositeOperation = 'lighter'; c.lineJoin = 'round';
    for (const [lw, col] of [[7, 'rgba(125,249,255,0.22)'], [3, 'rgba(125,249,255,0.75)'], [1.2, '#ffffff']]) { c.strokeStyle = col; c.lineWidth = lw; c.beginPath(); pts.forEach(([x, y], i) => i ? c.lineTo(x, y) : c.moveTo(x, y)); c.closePath(); c.stroke(); }
    c.fillStyle = '#fff'; for (let i = 0; i < 3; i++) { const [x, y] = pts[Math.floor(rnd(i * 13 + 5) * pts.length)]; c.beginPath(); c.arc(x, y, 1.8, 0, TAU); c.fill(); }
    c.restore();
  },
  // NEW: a gold pill on the tile's top-left corner, breathing
  newBadge(cx, cy, size) {
    const c = this.ctx, fs = Math.max(11, Math.min(15, size * 0.22)), k = 0.5 + 0.5 * Math.sin(G.time * 4), w = fs * 3.1, h = fs + 7, x = cx - size / 2 - 4, y = cy - size / 2 - 4; // (tucked into the corner: nothing above the tile gets covered)
    c.save(); c.shadowColor = '#ffd166'; c.shadowBlur = 6 + 6 * k; c.fillStyle = '#ffd166'; this.roundRect(x, y, w, h, h / 2); c.fill(); c.restore();
    c.strokeStyle = this.OUT; c.lineWidth = 1.5; this.roundRect(x, y, w, h, h / 2); c.stroke(); this.ink('NEW', x + w / 2, y + h / 2 + 1, fs, '#2a1608', 'center'); // (ink: no dark outline on the gold)
  },

  // the trash bin: a drop target (records Build.hit.trash)
  benchBin(cx, cy, s) {
    const B = Build, D = B.drag && B.drag.moved ? B.drag : null, w = s || 90, hov = D && B.inside({ x: D.x, y: D.y }, { x: cx - w / 2, y: cy - w / 2, w, h: w });
    B.hit.trash = this.square(cx, cy, w, { icon: 'trash', color: hov ? '#ff5c5c' : '#aaa', hov });
    Icons.strip([{ icon: 'dot', color: '#ffe9a8', text: `+${CFG.TRASH_DOTS}` }], cx, cy + 36 * (w / 90), 7 * (w / 90), 'center');
  },

  // ---- the screen ----
  drawPaused() {
    const c = this.ctx, B = Build, D = B.drag && B.drag.moved ? B.drag : null;
    c.fillStyle = 'rgba(10,4,20,0.96)'; c.fillRect(0, 0, CFG.W, CFG.H);
    B.hit = { body: [], bag: [], fusions: [], buttons: [], tabs: [], perks: [], pills: [], megas: [], gears: [], goto: [], hub: [], back: null, trash: null, bodyEnd: null, bodyRow: null, bagRow: null, head: null };
    // the pinch magnifier (touch): the whole screen scales about the pinch point while two fingers are down, then springs back
    c.save();
    const Z = Input.pinch;
    if (Z) { if (Z.release) { Z.scale += (1 - Z.scale) * 0.3; Z.dx *= 0.7; Z.dy *= 0.7; if (Z.scale < 1.01) Input.pinch = null; } c.translate(Z.cx + Z.dx, Z.cy + Z.dy); c.scale(Z.scale, Z.scale); c.translate(-Z.cx, -Z.cy); }
    this.benchTop();
    if (B.panel === 'body') this.benchBody(); else if (B.panel === 'lab') this.benchLab(); else if (B.panel === 'powers') this.benchHub(); else if (B.panel === 'perks') this.benchPerks(); else if (B.panel === 'megas' || B.panel === 'hats') this.benchDeck(B.panel); else this.benchForge();
    if (B.need) { // not enough dots: said in the info card on the right, how many and where dots come from
      const T = this.benchText(), k = Math.min(1, B.need.t), I = this.benchInfo(), { x, y, w } = I, pad = 18 * T;
      c.save(); c.globalAlpha = k;
      c.fillStyle = '#781828'; this.roundRect(x, y, w, I.h, 18); c.fill(); c.strokeStyle = '#ff9b8b'; c.lineWidth = 3; c.stroke();
      this.circle(x + pad + 14 * T, y + 34 * T, 12 * T, '#ffe9a8', this.OUT, 2);
      this.text(`NEED ${B.need.cost - B.need.have} MORE DOTS`, x + pad + 36 * T, y + 34 * T, 20 * T, '#fff', 'left');
      let ly = y + 70 * T;
      if (B.need.r) { this.text(this.fitText(`${BULLETS[B.need.r].name} costs ${B.need.cost}, you have ${B.need.have}.`, w - 2 * pad, 15 * T), x + pad, ly, 15 * T, '#ffe9a8', 'left'); ly += 28 * T; }
      this.benchWrap('Dots are the pellets in dungeons: find a cave and eat them all. Trashing a segment you don\'t need gives some back.', x + pad, ly, 14 * T, '#fff', w - 2 * pad, 20 * T, 6);
      this.text(Input.touch ? 'tap to close' : 'any key to close', x + w / 2, y + I.h - 20 * T, 12 * T, 'rgba(255,255,255,0.65)', 'center', 'normal');
      c.restore();
    }
    if (B.ask && B.ask.kind !== 'lock') { const ra = BenchFx.rectOf(B.ask.from), rb = BenchFx.rectOf(B.ask.target); if (ra && rb) this.crackle(ra, rb, BULLETS[B.ask.id].color, BULLETS[Player.idAt(B.ask.target)].color); } // the pair being asked about keeps crackling
    if (B.ask) this.benchAsk(B.ask);
    if (B.ask && B.ask.kind !== 'lock') { const fb = (B.hit.ask || []).find(q => q.code === 'Enter'), rb = BenchFx.rectOf(B.ask.target); if (fb && rb) this.crackle(rb, { cx: fb.x + fb.h * 0.55, cy: fb.y + fb.h * 0.85, size: fb.h }, BULLETS[Player.idAt(B.ask.target)].color, BULLETS[B.ask.r].color, -30); } // (it lands on the button's hammer, clear of its words) // ...and on into the FUSE button: that is what the pair can do
    if (D) { // dragging over a piece it fuses with: the fusion string arcs between them before you let go
      const t = B.tileAt({ x: D.x, y: D.y }), tid = t ? Player.idAt(t) : null, same = t && t.where === D.from.where && (t.where === 'body' ? t.i === D.from.i : tid === D.id && t.count < 2);
      if (t && !same && findRecipe(D.id, tid)) { const r = BenchFx.rectOf(t), h = BenchFx.rectOf(D.from); if (r) this.crackle(h || { cx: D.x, cy: D.y - 20 + 34, size: 96 }, r, BULLETS[D.id].color, BULLETS[tid].color); } // (from where the piece came from: the dragged tile itself covers the target)
    }
    if (D) { c.globalAlpha = 0.9; this.benchTile(D.id, D.x, D.y - 20, 96); c.globalAlpha = 1; if (!Input.touch) this.text(`${BULLETS[D.id].name}  ·  drop on + to fuse  ·  a slot to place or swap  ·  the bin to destroy`, CFG.W / 2, CFG.H - 20, 13, '#7df9ff', 'center', 'normal'); }
    BenchFx.draw();
    if (B.help) this.benchHelp();
    if (G.god) { const crab = BOSSES.crab, lvl = Math.max(1, G.level), hp = Enemies.bossHp(crab, lvl), dps = Player.estimateDps(crab.armor, 0.6); this.text(`GOD · crab L${lvl}: ${hp} hp vs est ${dps.toFixed(1)} dps ≈ ${dps > 0 ? Math.round(hp / dps) + 's' : '∞'} · presets 1-6 · B arena · V mortal`, CFG.W / 2, CFG.H - 8, 11, '#ffd166', 'center'); }
    c.restore();
  },

  // crackling lightning between two tiles (a recipe's ingredients): a few jagged bolts that re-strike every few frames,
  // arcing over the row when the two sit side by side, in both ingredients' colours, with sparks at the ends
  crackle(a, b, colA, colB, bow) { // bow: how far the arc bulges (default: upward, more for longer arcs; negative sags it)
    const c = this.ctx, seed = Math.floor(G.time * 14), rnd = n => { const v = Math.sin(seed * 12.9898 + n * 78.233) * 43758.5453; return v - Math.floor(v); };
    const x1 = a.cx, y1 = a.cy - a.size * 0.35, x2 = b.cx, y2 = b.cy - b.size * 0.35, d = Math.hypot(x2 - x1, y2 - y1) || 1, lift = bow != null ? bow : Math.min(70, 18 + d * 0.25), nx = -(y2 - y1) / d, ny = (x2 - x1) / d, sgn = ny > 0 ? -1 : 1; // (bow upward)
    c.save(); c.globalCompositeOperation = 'lighter'; c.lineCap = 'round'; c.lineJoin = 'round';
    for (let k = 0; k < 3; k++) {
      const steps = Math.max(6, Math.round(d / 14)), pts = [];
      for (let i = 0; i <= steps; i++) { const t = i / steps, bow = Math.sin(Math.PI * t) * lift * sgn, j = (i && i < steps) ? (rnd(i * 3 + k * 50) - 0.5) * 18 : 0; pts.push([x1 + (x2 - x1) * t + nx * (bow + j), y1 + (y2 - y1) * t + ny * (bow + j)]); }
      const grad = c.createLinearGradient(x1, y1, x2, y2); grad.addColorStop(0, colA); grad.addColorStop(1, colB);
      for (const [lw, al, st] of [[7, 0.18, grad], [3, 0.65, grad], [1.2, 0.9, '#ffffff']]) { c.globalAlpha = al * (k ? 0.55 : 1); c.strokeStyle = st; c.lineWidth = lw; c.beginPath(); pts.forEach(([px, py], i) => i ? c.lineTo(px, py) : c.moveTo(px, py)); c.stroke(); }
    }
    c.globalAlpha = 0.9; for (const [px, py, col] of [[x1, y1, colA], [x2, y2, colB]]) { c.fillStyle = col; for (let i = 0; i < 3; i++) { const an = rnd(i + px) * TAU, r = 4 + rnd(i * 7 + py) * 9; c.beginPath(); c.arc(px + Math.cos(an) * r, py + Math.sin(an) * r, 1.6, 0, TAU); c.fill(); } }
    c.restore();
  },

  // the info card while the keyboard cursor is on the toolbar: what FILL / SAVE LOADOUT would do
  benchPillInfo(x, y, w, h) {
    const P = Player, T = this.benchText(), r = Build.hit.pills[Build.pillSel], pad = 20, c = this.ctx; if (!r) return;
    const locked = P.autoRebuild, moved = locked && !P.lockMatches(), free = P.maxSegs - P.weaponCount();
    const [title, col, body] = r.code === 'KeyZ' ? [`FILL ${free} EMPTY SLOT${free > 1 ? 'S' : ''}`, '#ffd166', 'Puts the best bullets from your bag into the empty slots, favouring your main element.']
      : moved ? ['SAVE CHANGES', '#ffd166', 'Updates your saved loadout to this body. Without it, leaving the workbench puts the saved one back.']
      : locked ? ['LOADOUT SAVED', '#9bf06a', 'This body is your loadout: pieces you lose are rebuilt in order from the parts you find. Pressing it again clears the loadout.']
      : ['SAVE LOADOUT', '#ffd166', 'Keeps this body as your loadout: pieces you lose are rebuilt in order from the parts you find, and new pickups go to the bag.'];
    c.fillStyle = '#1b1428'; this.roundRect(x, y, w, h, 16); c.fill(); c.strokeStyle = col; c.lineWidth = 2.5; c.stroke();
    this.text(this.fitText(title, w - pad * 2, 18 * T), x + pad, y + 32 * T, 18 * T, col, 'left');
    const ly = this.benchWrap(body, x + pad, y + 64 * T, 14 * T, '#e4dcf0', w - pad * 2, 20 * T, 8);
    if (!Input.touch) this.text('ENTER to press  ·  S / down for the body', x + pad, ly + 16 * T, 12 * T, 'rgba(255,255,255,0.55)', 'left', 'normal');
  },

  // the FUSE or MOVE choice: a segment dropped on a fusion partner, asked in the info card on the right (where the piece's
  // story already is) rather than over the page. F / M / Esc, or the buttons
  benchAsk(a) {
    if (a.kind === 'lock') { this.benchLockAsk(); return; }
    const c = this.ctx, B = Build, T = this.benchText(), A = BULLETS[a.id], Bb = BULLETS[Player.idAt(a.target)], R = BULLETS[a.r], I = this.benchInfo(), { x, y, w, h } = I, pad = 18 * T;
    c.fillStyle = '#1f1830'; this.roundRect(x, y, w, h, 18); c.fill(); c.strokeStyle = '#ffd166'; c.lineWidth = 3; c.stroke();
    this.text(a.swap ? 'FUSE, OR JUST SWAP?' : 'FUSE, OR JUST MOVE IT?', x + w / 2, y + 28 * T, 16 * T, '#ffd166', 'center');
    const ts = Math.min(52 * T, (w - 2 * pad) / 5.4), ty = y + 52 * T + ts / 2, step = (w - 2 * pad) / 4, tile = (W, cx, s) => { c.fillStyle = W.color; this.roundRect(cx - s / 2, ty - s / 2, s, s, s * 0.18); c.fill(); c.strokeStyle = W.craft ? '#fff' : this.OUT; c.lineWidth = 3; c.stroke(); Icons.tile(W, cx, ty, s, c); };
    const cx = k => x + pad + step * k;
    tile(A, cx(0.45), ts); this.text('+', cx(1.05), ty, 22 * T, '#d8d0e8', 'center'); tile(Bb, cx(1.65), ts); this.text('→', cx(2.4), ty, 22 * T, '#d8d0e8', 'center'); tile(R, cx(3.35), ts * 1.2);
    let ly = ty + ts * 0.6 + 24 * T;
    this.text(this.fitText(`${R.name}  ·  ${a.cost} dots`, w - 2 * pad, 16 * T), x + w / 2, ly, 16 * T, R.color, 'center'); ly += 26 * T;
    ly = this.benchWrap(R.desc, x + pad, ly, 13 * T, '#e4dcf0', w - 2 * pad, 18 * T, 5) + 10 * T; // what you would get
    const bh = 40 * T, gap = 12 * T, by = Math.max(ly + 10 * T, y + h - 40 * T - bh * 2 - gap);
    B.hit.ask = [
      Object.assign(this.benchPill(x + pad, by, w - 2 * pad, bh, `FUSE into ${R.name}${Input.touch ? '' : '  (ENTER)'}`, 'hammer', '#ffd166', true, null), { code: 'Enter' }),
      Object.assign(this.benchPill(x + pad, by + bh + gap, w - 2 * pad, bh, `${a.swap ? 'SWAP them' : 'MOVE it here'}${Input.touch ? '' : '  (SPACE)'}`, 'swap', '#7df9ff', false, null), { code: 'Space' }),
    ];
    this.text(Input.touch ? 'tap outside to cancel' : 'ESC to cancel', x + w / 2, by + bh * 2 + gap + 18 * T, 11 * T, 'rgba(255,255,255,0.5)', 'center', 'normal');
  },

  // the lock's question: the body just changed while a build is locked (Build.update asks once a visit)
  benchLockAsk() { // asked in the info card on the right, like the FUSE / MOVE choice
    const c = this.ctx, B = Build, T = this.benchText(), I = this.benchInfo(), { x, y, w, h } = I, pad = 18 * T;
    c.fillStyle = '#1f1830'; this.roundRect(x, y, w, h, 18); c.fill(); c.strokeStyle = '#ffd166'; c.lineWidth = 3; c.stroke();
    Icons.draw('lock', x + pad + 10 * T, y + 30 * T, 11 * T, '#ffd166'); this.text('LOADOUT SAVED', x + pad + 30 * T, y + 30 * T, 16 * T, '#ffd166', 'left');
    let ly = this.benchWrap('Leaving the workbench puts your saved loadout back, so this change would be undone. Keep these changes?', x + pad, y + 66 * T, 14 * T, '#fff', w - 2 * pad, 20 * T, 6) + 8 * T;
    ly = this.benchWrap('Save: your changes stay. Clear: no saved loadout. Keep: undone when you leave.', x + pad, ly, 12 * T, 'rgba(255,255,255,0.6)', w - 2 * pad, 17 * T, 4);
    const bh = 40 * T, gap = 12 * T, by = Math.max(ly + 16 * T, y + h - 24 * T - bh * 3 - gap * 2), k = Input.touch ? '' : '  ';
    B.hit.ask = [
      Object.assign(this.benchPill(x + pad, by, w - 2 * pad, bh, `SAVE CHANGES${k}${Input.touch ? '' : '(ENTER)'}`, 'lock', '#9bf06a', true, null), { code: 'Enter' }),
      Object.assign(this.benchPill(x + pad, by + bh + gap, w - 2 * pad, bh, `CLEAR LOADOUT${k}${Input.touch ? '' : '(U)'}`, 'unlock', '#7df9ff', false, null), { code: 'KeyU' }),
      Object.assign(this.benchPill(x + pad, by + (bh + gap) * 2, w - 2 * pad, bh, `KEEP LOADOUT${k}${Input.touch ? '' : '(ESC)'}`, 'cross', '#ffe9a8', false, null), { code: 'Escape' }),
    ];
  },

  // the info card while the cursor is on a crafting card: the result, its parts, the price, where it lands, and CRAFT


  benchTopH() { return Math.round(64 * this.benchText()); },

  // the top bar: title, the two tabs, the run's numbers, resume / settings / help
  benchTop() {
    const c = this.ctx, P = Player, B = Build, T = this.benchText(), M = this.BENCH.M, megaId = Mega.current(), TOP = this.benchTopH(), y = TOP / 2 + 2;
    c.fillStyle = 'rgba(255,255,255,0.04)'; c.fillRect(0, 0, CFG.W, TOP); c.fillStyle = 'rgba(255,255,255,0.1)'; c.fillRect(0, TOP - 1, CFG.W, 1);
    if (B.POWER_PAGES.includes(B.panel)) { // a POWERS page: the title spot is the way back to the hub
      if (!Input.touch) B.hit.back = this.benchPill(M, y - 17 * T, 150 * T, 34 * T, '◀ POWERS', null, '#c58aff', false, null);
      else { const r = 17 * T, bx = M + r; c.fillStyle = 'rgba(197,138,255,0.18)'; c.beginPath(); c.arc(bx, y, r, 0, TAU); c.fill(); c.strokeStyle = '#c58aff'; c.lineWidth = 2.5; c.stroke(); this.text('◀', bx - 1, y + 1, 15 * T, '#c58aff', 'center'); B.hit.back = { x: bx - r - 6, y: y - r - 6, w: r * 2 + 12, h: r * 2 + 12 }; }
    } else if (!Input.touch) this.text('WORKBENCH', M, y, 24 * T, '#fff', 'left'); else Icons.draw('hammer', M + 14 * T, y, 12 * T, '#fff'); // (a phone: the icon, so five tabs fit)
    { // tabs
      const TABS = B.PANELS, gap = 8, th = 34 * T, left = !Input.touch ? M + 190 * T : M + 40 * T, right = CFG.W * 0.66; // five tabs, as wide as fits between the title and the run's numbers
      const tw = Math.min(118 * T, (right - left - gap * (TABS.length - 1)) / TABS.length), total = TABS.length * tw + (TABS.length - 1) * gap, x0 = Math.max(left, Math.min(CFG.W / 2 - total / 2, right - total)); this.tabsRight = x0 + total;
      const TCOL = { body: '#ffb86b', forge: '#ffd166', lab: '#7df9ff', powers: '#c58aff' }, TICON = { body: 'segment', forge: 'bag', lab: 'electric', powers: 'mega' }, TNAME = { body: 'LOADOUT', forge: 'BAG' };
      for (const [i, k] of TABS.entries()) {
        const active = B.tabOf(B.panel) === k, x = x0 + i * (tw + gap), col = TCOL[k];
        const r = this.benchPill(x, y - th / 2, tw, th, TNAME[k] || k.toUpperCase(), /* (panel 'body' is LOADOUT, panel 'forge' is the BAG) */ TICON[k], col, active, null);
        B.hit.tabs.push({ panel: k, ...r });
        if (B.row === 'tabs' && active) { c.strokeStyle = '#fff'; c.lineWidth = 2.5; this.roundRect(x - 4, y - th / 2 - 4, tw + 8, th + 8, th / 2 + 4); c.stroke(); } // the keyboard cursor is on the tabs
        if (k === 'lab') { const n = P.fusions().filter(f => G.dots >= f.cost).length; if (n) { const br = 10 * T; c.fillStyle = '#9bf06a'; c.beginPath(); c.arc(x + tw - 4, y - th / 2 + 4, br, 0, TAU); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 2; c.stroke(); this.text(String(n), x + tw - 4, y - th / 2 + 5, 12 * T, this.OUT, 'center'); } } // how many fusions you can afford right now
      }
      if (!Input.touch) this.keyCap('TAB', CFG.W / 2, y + 27, 'rgba(255,255,255,0.4)', 13);
    }
    { // buttons at the right: resume, settings, help
      const cb = Input.touch && TOUCH.buttons.find(b => b.id === 'pause'); // a phone: the play button sits exactly where the CRAFT button was, the same size, so it reads as that button flipping
      const r = cb ? cb.r : 22 * T, gap = cb ? 22 : 12, by = cb ? cb.y : y, k = r / (22 * T); let x = cb ? cb.x : CFG.W - M - r;
      const btn = (icon, code, col, label) => { const y = by; c.fillStyle = icon === 'play' ? 'rgba(155,240,106,0.22)' : 'rgba(255,255,255,0.1)'; c.beginPath(); c.arc(x, y, r, 0, TAU); c.fill(); c.strokeStyle = col; c.lineWidth = 2.5; c.stroke(); if (icon) Icons.draw(icon, x + (icon === 'play' ? 2 : 0), y, (icon === 'cog' ? 10 : 11) * T * k, col); else this.text(label, x, y + 1, 18 * T, col, 'center'); B.hit.buttons.push({ code, x: x - r - 6, y: y - r - 6, w: r * 2 + 12, h: r * 2 + 12 }); x -= r * 2 + gap; };
      btn('play', 'KeyP', '#9bf06a'); btn('cog', 'KeyO', '#ffe9a8'); if (!Input.touch) btn(null, 'Slash', B.help ? '#fff' : '#aaa', '?');
      // the run's numbers, right-aligned before the buttons, in order of importance; the strip sheds its tail rather than run into the tabs
      const tabsRight = (this.tabsRight || CFG.W / 2) + 5, room = (x - r + 4) - tabsRight - 16, s = 8 * T;
      const items = [{ icon: 'segment', color: '#ffb86b', text: `${P.weaponCount()}/${P.maxSegs}` }, { icon: 'dot', color: '#ffe9a8', text: `${G.dots}` }];
      if (!Input.touch) items.push({ icon: 'ghost', color: '#7de0ff', text: `${G.ghosts}` }, { icon: 'minion', color: '#9bf06a', text: `${P.minionCount()}/${Minions.cap()}` }, { icon: 'dmg', color: '#ff7b6b', text: `×${+G.powerMult.toFixed(2)}` }, { icon: 'mega', color: MEGAS[megaId].color, text: `${Math.floor(100 * G.mega.charge / CFG.MEGA_MAX)}%` });
      const width = list => { c.font = `bold ${Math.round(s * 1.6)}px "Trebuchet MS", "Segoe UI", sans-serif`; return list.reduce((a, it) => a + s * 2 + (it.text ? c.measureText(it.text).width + s * 0.5 : 0) + s * 1.1, 0); };
      while (items.length && width(items) > room) items.pop();
      if (items.length) Icons.strip(items, x - r + 4, y, s, 'right');
    }
  },

  // ---- the body row (both panels) ----
  // o = { y (first row centre), x0, w (the width to centre in), big (names, pipe tags, the locked-build view), maxTile, scale }
  // Returns { bottom, tile, cellAt }. Rows wrap at ten tiles (big) or whatever fits (compact); the head leads the first row.
  benchBodyRow(o) {
    const c = this.ctx, P = Player, B = Build, T = this.benchText(), D = B.drag && B.drag.moved ? B.drag : null, dragFrom = D ? D.from : null;
    const isMark = (where, i) => (B.mark && B.mark.where === where && B.mark.i === i), isDragSrc = (where, i) => dragFrom && dragFrom.where === where && dragFrom.i === i;
    const markId = D ? D.id : (B.mark ? P.idAt(B.mark) : null), hover = D ? B.tileAt({ x: D.x, y: D.y }) : null;
    const ghostView = o.big && !!(B.ghost && P.blueprint.length), n = ghostView ? P.blueprint.length : P.segments.length, free = ghostView ? 0 : Math.max(0, P.maxSegs - P.weaponCount()), room = free > 0, slots = n + free;
    const gap0 = o.big ? 26 : 8, PER = o.big ? (slots <= 14 ? Math.max(10, slots) : 10) : Math.max(4, Math.floor((o.w + gap0) / ((o.maxTile || 44) + gap0)) - 1), rows = Math.max(1, Math.ceil(slots / PER)), wrapped = rows > 1;
    const tile = o.big ? (wrapped ? Math.min(64, Math.floor((o.w - PER * 12) / (PER + 1))) : Math.min(slots > 10 ? 70 : slots > 8 ? 86 : slots > 6 ? 100 : o.maxTile || 108, Math.floor((o.w - slots * 26) / (slots + 1)))) : (o.maxTile || 44);
    const gap = o.big ? (wrapped ? 12 : 26) : 8, pitch = tile + (o.big ? 12 : 8), y = o.y, names = o.big && !wrapped, nf = Math.min(15 * T, Math.max(12, (tile + gap) * 0.17)); // name font: shrinks so names stay apart
    const rowCount = r => r === 0 ? Math.min(PER, slots) : Math.min(PER, slots - r * PER), rowW = r => (rowCount(r) + (r === 0 ? 1 : 0)) * tile + (rowCount(r) - (r === 0 ? 0 : 1)) * gap;
    const cx0 = o.x0 + o.w / 2, cellAt = k => { const r = Math.floor(k / PER), i = k % PER; return { cx: cx0 - rowW(r) / 2 + (r === 0 ? tile + gap : 0) + i * (tile + gap) + tile / 2, cy: y + r * pitch }; };
    const bottom = y + (rows - 1) * pitch + tile / 2 + (names ? 30 + nf : 10);
    B.hit.bodyRow = { x: o.x0, y: y - tile / 2 - 20, w: o.w, h: bottom - (y - tile / 2 - 20) };
    const sc = o.scale || 1; c.save(); if (sc !== 1) { const my = (y + bottom - tile / 2) / 2; c.translate(cx0, my); c.scale(sc, sc); c.translate(-cx0, -my); }
    { // the head leads the row and faces away from the body (it is the front). Tap it to see the locked build in order
      const hr = tile * 0.4, hx = cx0 - rowW(0) / 2 + tile / 2, ry = y;
      if (ghostView) { c.fillStyle = 'rgba(255,209,102,0.25)'; c.beginPath(); c.arc(hx, ry, hr + 10, 0, TAU); c.fill(); }
      this.circle(hx, ry, hr, '#ffb86b', this.OUT, o.big ? 3.5 : 2.5); this.shine(hx, ry, hr); this.blush(hx, ry, Math.PI, hr); this.eyes(hx, ry, Math.PI, hr, { fwd: hr * 0.5 }); this.mouth(hx, ry, Math.PI, hr, true); if (P.gear) this.drawGear(hx, ry, Math.PI, hr, P.gear, 0); /* head gear on the bench's head */
      if (o.big && P.blueprint.length) this.text(ghostView ? 'back' : 'build', hx, ry + hr + (wrapped ? 12 : 18), (wrapped ? 11 : 13) * T, '#ffd166', 'center');
      if (o.big) B.hit.head = { x: hx - hr - 10, y: ry - hr - 10, w: hr * 2 + 20, h: hr * 2 + 40 };
    }
    if (ghostView) { // the locked build, slot by slot: held pieces bright, missing ones faded and dashed
      const have = P.holdings(), seen = {};
      P.blueprint.forEach((id, i) => {
        const w = BULLETS[id], { cx, cy } = cellAt(i), held = (have[id] || 0) - (seen[id] || 0) > 0; seen[id] = (seen[id] || 0) + 1;
        c.save(); c.globalAlpha = held ? 0.75 : 0.35; this.benchTile(id, cx, cy, tile); c.restore();
        c.setLineDash([6, 6]); c.strokeStyle = held ? 'rgba(255,255,255,0.7)' : '#ffd166'; c.lineWidth = 2.5; this.roundRect(cx - tile / 2, cy - tile / 2, tile, tile, tile * 0.18); c.stroke(); c.setLineDash([]);
        c.fillStyle = this.OUT; c.beginPath(); c.arc(cx - tile / 2 + 12, cy - tile / 2 + 12, 11, 0, TAU); c.fill(); this.text(String(i + 1), cx - tile / 2 + 12, cy - tile / 2 + 13, 12, '#ffd166', 'center');
        if (names) this.text(w.name + (held ? '' : ' (missing)'), cx, cy + tile / 2 + 18, nf, held ? '#fff' : '#ffd166', 'center', 'normal');
      });
      this.text(`YOUR LOADOUT, in order (${P.missingBlueprint().length ? P.missingBlueprint().length + ' missing' : 'complete'}${P.blueprint.length < P.maxSegs ? `, ${P.blueprint.length} of ${P.maxSegs} slots: shorter on purpose` : ''})  ·  tap the head${Input.touch ? '' : ' or V'} to go back`, cx0, bottom - 6, 13 * T, '#ffd166', 'center');
    }
    else P.segments.forEach((s, i) => {
      const w = BULLETS[s.id], { cx, cy } = cellAt(i), sel = B.row === 'body' && B.sel === i;
      const hov = hover && hover.where === 'body' && hover.i === i && !isDragSrc('body', i);
      for (const el of (P.synAt && P.synAt[i]) || []) this.synergyAura(el, cx, cy, tile, i); // 3+ in a row of one element: it shows around them
      const lifted = Build.held === i, ly = lifted ? cy - tile * 0.18 : cy; // held (ENTER): lifted off the row, ringed, until ENTER lands it
      if (lifted) { const k = 0.5 + 0.5 * Math.sin(G.time * 6); c.save(); c.strokeStyle = '#7df9ff'; c.lineWidth = 3 + 2 * k; c.globalAlpha = 0.6 + 0.4 * k; this.roundRect(cx - tile / 2 - 8, ly - tile / 2 - 8, tile + 16, tile + 16, tile * 0.25); c.stroke(); c.restore(); }
      this.benchTile(s.id, cx, ly, tile, { sel: sel && !D, marked: isMark('body', i), pairs: markId && !isMark('body', i) && !isDragSrc('body', i) && findRecipe(markId, s.id), jam: s.jam, ghost: isDragSrc('body', i), hov, where: 'body', k: i, fresh: s.fresh, isNew: !P.isMinion(s) && !Settings.benchSeen.has(s.id) });
      if (B.row === 'bag' && !D && i === B.sel && !P.isMinion(s) && P.weaponCount() >= P.maxSegs) { // the body is full: this is the slot a bag bullet would swap into (ENTER)
        const k2 = 0.5 + 0.5 * Math.sin(G.time * 5); c.save(); c.strokeStyle = `rgba(125,249,255,${0.35 + 0.55 * k2})`; c.lineWidth = 3 + 2 * k2; this.roundRect(cx - tile / 2 - 7, cy - tile / 2 - 7, tile + 14, tile + 14, tile * 0.18 + 5); c.stroke(); c.restore();
        Icons.draw('swap', cx, cy - tile / 2 - 12 * T, 6 * T, '#7df9ff'); if (names) this.text('swap', cx + 14 * T, cy - tile / 2 - 12 * T, 10 * T, '#7df9ff', 'left');
      }
      const chips = this.buildChips(w, s.pipe);
      if (names) { this.text(this.fitText(w.name, tile + gap - 2, nf), cx, cy + tile / 2 + 18, nf, '#fff', 'center', 'normal'); if (chips.length) this.drawChips(chips, cx, cy + tile / 2 + 18 + nf + 8, Math.min(6 * T, (tile + gap) / (chips.length * 4))); }
      else if (chips.length) this.drawChips(chips, cx - tile / 2 + 4 + chips.length * 5.5, cy - tile / 2 + 8, 3.6); // small tiles: a row of tiny chips along the top-left edge
      if (!o.big) { const br = 8 * T; c.fillStyle = 'rgba(0,0,0,0.45)'; c.beginPath(); c.arc(cx + tile / 2 - br, cy + tile / 2 - br, br, 0, TAU); c.fill(); this.text(String(i + 1), cx + tile / 2 - br, cy + tile / 2 - br + 1, 10 * T, '#fff', 'center'); } // slot numbers: the cards say "→ slot 3"
      B.hit.body.push({ i, x: cx - tile / 2 - (names ? 10 : 4), y: cy - tile / 2 - (names ? 10 : 4), w: tile + (names ? 20 : 8), h: tile + (names ? 50 : 8), tile, name: names });
    });
    if (room) for (let k = 0; k < free; k++) { // every empty slot, as a placeholder: drop a bag bullet on any of them to add it (the first is the one it fills)
      const { cx, cy } = cellAt(n + k), first = k === 0, hov = D && dragFrom.where === 'bag' && B.inside({ x: D.x, y: D.y }, { x: cx - tile / 2 - 10, y: cy - tile / 2 - 10, w: tile + 20, h: tile + 20 });
      const addT = first && B.row === 'bag' && !D, k2 = 0.5 + 0.5 * Math.sin(G.time * 5); // a bag bullet is selected: this is where ENTER puts it
      c.setLineDash([8, 8]); c.strokeStyle = hov ? '#fff' : addT ? `rgba(125,249,255,${0.5 + 0.5 * k2})` : first ? 'rgba(255,255,255,0.45)' : 'rgba(255,255,255,0.22)'; c.lineWidth = hov ? 5 : addT ? 3 + 2 * k2 : 3; this.roundRect(cx - tile / 2, cy - tile / 2, tile, tile, tile * 0.18); c.stroke(); c.setLineDash([]);
      if (first) this.text(D && dragFrom.where === 'bag' ? 'drop' : '+', cx, cy + 1, D ? 18 : tile * 0.4, 'rgba(255,255,255,0.6)', 'center');
      if (first && names) this.text(`${free} free`, cx, cy + tile / 2 + 18, nf * 0.9, 'rgba(255,255,255,0.6)', 'center', 'normal');
      if (first) B.hit.bodyEnd = { x: cx - tile / 2 - 10, y: cy - tile / 2 - 10, w: tile + 20, h: tile + 20 };
      B.hit.body.push({ i: n + k, x: cx - tile / 2 - 4, y: cy - tile / 2 - 4, w: tile + 8, h: tile + 8, tile, name: names, end: true });
    }
    c.restore();
    return { bottom, tile, cellAt };
  },

  // the blueprint line: the padlock, then the locked build as tiles (held bright, missing faded with what they need)
  benchBlueprint(x, y, w) {
    const c = this.ctx, P = Player, T = this.benchText(), lines = P.blueprintLines(), locked = P.autoRebuild, pw = 164 * T, ph = 30 * T, fs = 12 * T;
    const moved = locked && !P.lockMatches(); // the body has moved away from the lock: the padlock now updates it
    Build.hit.pills.push(this.benchPill(x, y - ph / 2, pw, ph, moved ? 'SAVE CHANGES' : locked ? 'LOADOUT SAVED' : 'SAVE LOADOUT', locked ? 'lock' : 'unlock', moved ? '#ffd166' : locked ? '#9bf06a' : '#ffd166', locked, 'KeyB', true));
    if (!lines.length) { const hf = Input.touch ? 15 * T : fs; this.text(this.fitText(Input.touch ? 'No loadout saved: save one to keep this body.' : 'No loadout saved: the padlock keeps this body (lost pieces are rebuilt in order).', w - pw - 14, hf), x + pw + 14, y + 1, hf, 'rgba(255,233,168,0.9)', 'left', 'normal'); return; }
    const RP = P.rebuildPlan(), TL = 24 * T, xmax = x + w, have = P.holdings(), seen = {}; let tx = x + pw + 14;
    this.text('BLUEPRINT', tx, y + 1, fs, '#ffe9a8', 'left'); tx += 78 * T;
    c.font = `normal ${fs}px "Trebuchet MS", "Segoe UI", sans-serif`;
    for (const id of P.blueprint) {
      const B2 = BULLETS[id], ghost = (have[id] || 0) - (seen[id] || 0) <= 0; seen[id] = (seen[id] || 0) + 1;
      let label = '';
      if (ghost) { const e = RP.entries.find(q => q.id === id); label = B2.name + (B2.craft && e ? `: needs ${[...new Set(e.parts)].map(q => BULLETS[q].name + (e.parts.filter(z => z === q).length > 1 ? ' ×' + e.parts.filter(z => z === q).length : '')).join(' + ')}${e.dots ? ' + ' + e.dots + ' dots' : ''}` : ''); }
      const tw = TL + (label ? c.measureText(label).width + 10 : 0);
      if (tx + tw > xmax) { this.text('…', tx, y + 1, fs, '#aaa', 'left'); break; }
      c.save(); c.globalAlpha = ghost ? 0.4 : 1; c.fillStyle = B2.color; this.roundRect(tx, y - TL / 2, TL, TL, 5); c.fill(); c.strokeStyle = ghost ? '#fff' : this.OUT; c.lineWidth = 1.5; if (ghost) c.setLineDash([3, 3]); c.stroke(); c.setLineDash([]); Icons.tile(B2, tx + TL / 2, y, TL, c); c.restore();
      if (!ghost) { c.strokeStyle = '#9bf06a'; c.lineWidth = 2; c.beginPath(); c.moveTo(tx + TL - 7, y + TL / 2 - 1); c.lineTo(tx + TL - 4, y + TL / 2 + 2); c.lineTo(tx + TL + 1, y + TL / 2 - 4); c.stroke(); }
      if (label) this.text(label, tx + TL + 6, y + 1, fs, '#ffe9a8', 'left', 'normal');
      tx += tw + (label ? 14 : 4);
    }
    // what the lock's state means right now: the body has moved away from it (leaving the bench puts the locked build back,
    // unless the padlock updates it), or the body is exactly the lock
    const say = (t, col) => this.text(this.fitText(t, xmax - tx - 6, fs), tx + 6, y + 1, fs, col, 'left');
    if (moved) say('⟲ changed from your loadout: leaving puts it back (SAVE CHANGES keeps this)', '#ffe9a8');
    else if (!RP.missing.length) say('✓ loadout equipped', '#9bf06a');
  },
});
