// The workbench LAB page: the body strip across the top (a picture: the beams start there), the recipe cards (what you can
// make now, then the recipes you hold one half of, dimmed with what to find), and the REACTOR on the right: the selected
// card's two ingredients, labelled with where they come from, arcing into the core; CRAFT IT pulls them in along the beams
// (BenchFx.fuse with beams), and the result flies back into the body slot it came from, or into the bag pocket.
// Build.labCards() is the list; Build.hit.reactor tells Build.craftCard where the ingredients and the core are.
// Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  benchLab() {
    const c = this.ctx, M = this.BENCH.M, H = CFG.H, P = Player, B = Build, T = this.benchText(), TOP = this.benchTopH(), I = this.benchInfo(), lw = I.x - 24 - M;
    // the body strip: compact (slot numbers, no names), so the cards get the room; its hit rects let the beams find the slots
    const slots = P.segments.length + (P.weaponCount() < P.maxSegs ? 1 : 0), tile = Math.max(34, Math.min(Input.touch ? 58 : 54, Math.floor(lw / (slots + 1.6)) - 8));
    const row = this.benchBodyRow({ y: TOP + 20 * T + tile / 2, x0: M, w: lw, big: false, maxTile: tile });
    const L = B.labCards(), ready = L.filter(f => !f.near && G.dots >= f.cost).length, short = L.filter(f => !f.near && f.cost > G.dots).length, near = L.filter(f => f.near).length;
    const hy = row.bottom + 16 * T;
    Icons.draw('electric', M + 9, hy, 8 * T, '#7df9ff');
    this.text(this.fitText(L.length ? `LAB  ·  ${ready} ready${short ? `  ·  ${short} short of dots` : ''}${near ? `  ·  ${near} to find` : ''}${Input.touch ? '' : '  ·  ENTER crafts'}` : 'LAB: no recipes yet. Pick up two bullets that react.', lw - 30, 13 * T), M + 14 + 8 * T, hy, 13 * T, '#7df9ff', 'left');
    this.benchLabCards(M, hy + 16 * T, lw, H - 14 - (hy + 16 * T), L);
    c.fillStyle = 'rgba(255,255,255,0.1)'; c.fillRect(I.x - 12, TOP + 10, 1, H - TOP - 24);
    this.benchReactor(I.x, I.y, I.w, I.h, L);
  },

  // the cards, paged: the result big on the left, its name and tier, the two ingredients under it, the price and where it lands
  benchLabCards(x, y, w, h, L) {
    const c = this.ctx, B = Build, T = this.benchText(), touch = Input.touch, cols = w >= (touch ? 1500 : 760) ? 3 : 2, cg = 12, ch = touch ? 118 : 92, rg = 12;
    const rows = Math.max(1, Math.floor((h - (touch ? 34 : 0)) / (ch + rg))), perPage = cols * rows, cw = Math.floor((w - (cols - 1) * cg) / cols);
    B.hit.cardGrid = { cols, rows };
    B.cardFirst = Math.max(0, Math.min(B.cardFirst - (B.cardFirst % cols), Math.max(0, L.length - 1) - (Math.max(0, L.length - 1) % cols)));
    if (B.cardSel < B.cardFirst) B.cardFirst = B.cardSel - (B.cardSel % cols); else if (B.cardSel >= B.cardFirst + perPage) B.cardFirst = B.cardSel - (B.cardSel % cols) - (rows - 1) * cols; // the cursor's card is always on the page
    B.cardFirst = Math.max(0, B.cardFirst);
    const above = B.cardFirst, below = Math.max(0, L.length - B.cardFirst - perPage); B.hit.cardPage = null;
    if (above || below) { // page buttons at the right of the header (a thumb can hit them)
      const bh = touch ? 30 : 22, bw = (touch ? 120 : 96) * Math.min(T, 1.2), py = y - 16 * T - bh / 2, px = x + w; B.hit.cardPage = {};
      const pill = (label, rx, on) => { const r = { x: rx - bw, y: py, w: bw, h: bh }; c.fillStyle = on ? this.tint('#7df9ff', 0.22) : 'rgba(255,255,255,0.05)'; this.roundRect(r.x, r.y, r.w, r.h, bh / 2); c.fill(); c.strokeStyle = on ? '#7df9ff' : 'rgba(255,255,255,0.2)'; c.lineWidth = 2; c.stroke(); this.text(label, r.x + r.w / 2, r.y + bh / 2 + 1, Math.round(bh * 0.5), on ? '#7df9ff' : 'rgba(255,255,255,0.35)', 'center'); return r; };
      B.hit.cardPage.down = below ? pill(`▼ ${below} more`, px, true) : null;
      B.hit.cardPage.up = above ? pill(`▲ back`, px - bw - 10, true) : null;
    }
    const rs = Math.min(64 * T, ch * 0.62), sm = Math.min(22 * T, ch * 0.24), nfs = 15 * T, tfs = (touch ? 13 : 11) * T;
    L.slice(B.cardFirst, B.cardFirst + perPage).forEach((f, i) => {
      const k = B.cardFirst + i, x0 = x + (i % cols) * (cw + cg), y0 = y + Math.floor(i / cols) * (ch + rg), R = BULLETS[f.r], ok = !f.near && G.dots >= f.cost, sel = B.row === 'cards' && B.cardSel === k, dest = B.destOf(f);
      c.save(); c.globalAlpha = f.near ? 0.5 : ok ? 1 : 0.75;
      c.fillStyle = sel ? this.tint(R.color, 0.18) : 'rgba(255,255,255,0.07)'; this.roundRect(x0, y0, cw, ch, 14); c.fill();
      if (f.near) c.setLineDash([7, 6]); c.strokeStyle = sel ? '#fff' : ok ? R.color : 'rgba(255,255,255,0.3)'; c.lineWidth = sel ? 3.5 : 2; c.stroke(); c.setLineDash([]);
      const rx = x0 + 12 + rs / 2, ry = y0 + ch / 2;
      c.fillStyle = R.color; this.roundRect(rx - rs / 2, ry - rs / 2, rs, rs, rs * 0.18); c.fill(); c.strokeStyle = '#fff'; c.lineWidth = 2.5; c.stroke(); Icons.tile(R, rx, ry, rs, c);
      if (!Settings.benchSeen.has(f.r) && !f.near) this.newBadge(rx, ry, rs); // you have never had this one
      const nx = rx + rs / 2 + 12, right = x0 + cw - 12, ft = this.benchFit(R.name, right - nx - 50 * T, nfs, 12), y1 = y0 + ch * 0.28, y2 = y0 + ch * 0.68;
      this.text(ft.text, nx, y1, ft.size, '#fff', 'left'); if (!touch) this.benchTier(R.tier, nx + c.measureText(ft.text).width + 10, y1, 6, true); // (a phone: the stars are in the reactor)
      // the two halves: tiny tiles; on a dimmed card the half you are missing is a dashed "?"
      const half = (id, tx, missing) => { const Wb = BULLETS[id]; if (missing) { c.setLineDash([4, 4]); c.strokeStyle = Wb.color; c.lineWidth = 2; this.roundRect(tx - sm / 2, y2 - sm / 2, sm, sm, 5); c.stroke(); c.setLineDash([]); this.text('?', tx, y2 + 1, sm * 0.7, Wb.color, 'center'); } else { c.fillStyle = Wb.color; this.roundRect(tx - sm / 2, y2 - sm / 2, sm, sm, 5); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.stroke(); Icons.tile(Wb, tx, y2, sm, c); } };
      half(f.a, nx + sm / 2, f.near && f.need === f.a && f.have !== f.a); this.text('+', nx + sm * 1.35, y2 + 1, 12 * T, '#d8d0e8', 'center'); half(f.b, nx + sm * 2.2, f.near && f.need === f.b && (f.have !== f.b || f.a === f.b));
      const label = f.near ? `find ${BULLETS[f.need].name}` : `${BULLETS[f.a].name} + ${BULLETS[f.b].name}`;
      this.text(this.fitText(label, right - (nx + sm * 2.9) - 60 * T, tfs), nx + sm * 2.9, y2 + 1, tfs, f.near ? '#ffe9a8' : 'rgba(255,255,255,0.7)', 'left', 'normal');
      if (!f.near) { // the price (top right) and where it lands (bottom right)
        Icons.strip([{ icon: 'dot', color: '#ffe9a8', text: `${f.cost}`, textColor: ok ? '#9bf06a' : '#ff9b8b' }], right, y1, touch ? 12 : 6, 'right');
        const col = dest.where === 'body' ? '#ffb86b' : '#aaa', it = dest.where === 'gear' ? { icon: 'helmet', color: R.color } : dest.where === 'body' ? { icon: 'segment', text: String(dest.i + 1), color: col, textColor: col } : { icon: 'bag', color: col };
        Icons.strip([it], right, y2, tfs * 0.6, 'right'); this.text('→', right - Icons.stripWidth([it], tfs * 0.6) - 2, y2, tfs, col, 'right');
      }
      c.restore();
      B.hit.fusions.push({ i: k, x: x0, y: y0, w: cw, h: ch, cx: rx, cy: ry });
    });
  },

  // the REACTOR: the selected card's ingredients (with where each comes from) arcing into the core, the result, CRAFT IT
  benchReactor(x, y, w, h, L) {
    const c = this.ctx, B = Build, P = Player, T = this.benchText(), f = L[B.cardSel], R = f ? BULLETS[f.r] : null, col = R ? R.color : 'rgba(125,249,255,0.5)', pad = 18 * T, busy = BenchFx.busy();
    c.fillStyle = '#140e22'; this.roundRect(x, y, w, h, 16); c.fill(); c.strokeStyle = col; c.lineWidth = 2.5; c.stroke();
    Icons.draw('electric', x + pad + 6, y + 24 * T, 8 * T, '#7df9ff'); this.text('REACTOR', x + pad + 20 * T, y + 24 * T, 14 * T, '#7df9ff', 'left');
    { // the bag pocket (top right): bag results fly here; it bulges when one lands
      const pw = 64 * T, ph = 30 * T, px = x + w - pad - pw, py = y + 24 * T - ph / 2, pulse = Object.keys(BenchFx.pulse).filter(q => q.startsWith('bag:')).reduce((m, q) => Math.max(m, BenchFx.pulse[q] / 0.7), 0), s = 1 + 0.25 * pulse;
      c.save(); c.translate(px + pw / 2, py + ph / 2); c.scale(s, s); c.translate(-px - pw / 2, -py - ph / 2);
      c.fillStyle = pulse ? this.tint('#7df9ff', 0.25) : 'rgba(255,255,255,0.06)'; this.roundRect(px, py, pw, ph, ph / 2); c.fill(); c.strokeStyle = pulse ? '#7df9ff' : 'rgba(255,255,255,0.25)'; c.lineWidth = 2; c.stroke();
      Icons.strip([{ icon: 'bag', text: String(P.bag.length), color: '#fff', textColor: '#fff' }], px + pw / 2, py + ph / 2, 6 * T, 'center');
      c.restore();
      if (pulse) this.text('+1', px + pw / 2, py + ph + 14 * T, 13 * T, '#7df9ff', 'center');
      B.hit.bagPocket = { x: px, y: py, w: pw, h: ph };
    }
    { const tk = BenchFx.tokens.find(q => q.beams); if (tk) { // a craft in flight: the reactor shows it, not the next card
      const Rr = BULLETS[tk.r], cy = y + 182 * T, k = 0.5 + 0.5 * Math.sin(G.time * 12), g = c.createRadialGradient(x + w / 2, cy, 2, x + w / 2, cy, 70 * T);
      g.addColorStop(0, '#ffffff'); g.addColorStop(0.4, this.tint(Rr.color, 0.7 + 0.3 * k)); g.addColorStop(1, 'rgba(0,0,0,0)'); c.save(); c.globalCompositeOperation = 'lighter'; c.fillStyle = g; c.beginPath(); c.arc(x + w / 2, cy, 70 * T, 0, TAU); c.fill(); c.restore();
      this.text(`FUSING ${Rr.name.toUpperCase()}`, x + w / 2, cy + 96 * T, 18 * T, Rr.color, 'center'); return;
    } }
    if (!f) { this.benchWrap('Pick up two bullets that react, and their recipe shows up here. Scrapping a piece on the BAG page gives dots back.', x + pad, y + 70 * T, 14 * T, 'rgba(255,255,255,0.6)', w - pad * 2, 20 * T); return; }
    // the two ingredients and the core
    const tight = Input.touch, ts = Math.min((tight ? 52 : 60) * T, w * 0.22), iy = y + (tight ? 74 : 86) * T, ax = x + w * 0.22, bx = x + w * 0.78, core = { cx: x + w / 2, cy: iy + (tight ? 70 : 96) * T }, cr = (tight ? 22 : 28) * T; // (a phone: tighter, so the result's words fit above the buttons)
    const origin = (id, Lc, missing) => missing ? 'find one' : Lc && Lc.where === 'body' ? `body #${Lc.i + 1}` : `bag ×${P.holdings()[id] - P.segments.filter(s => s.id === id).length}`;
    const side = (id, cx, Lc, missing) => {
      const W = BULLETS[id]; c.save(); c.globalAlpha = busy ? 0.25 : missing ? 0.45 : 1;
      if (missing) { c.setLineDash([6, 5]); c.strokeStyle = W.color; c.lineWidth = 2.5; this.roundRect(cx - ts / 2, iy - ts / 2, ts, ts, ts * 0.18); c.stroke(); c.setLineDash([]); this.text('?', cx, iy + 2, ts * 0.5, W.color, 'center'); }
      else { c.fillStyle = W.color; this.roundRect(cx - ts / 2, iy - ts / 2, ts, ts, ts * 0.18); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.stroke(); Icons.tile(W, cx, iy, ts, c); }
      c.restore();
      this.text(this.fitText(W.name, w * 0.42, 12 * T), cx, iy + ts / 2 + 14 * T, 12 * T, '#fff', 'center', 'normal');
      this.text(origin(id, Lc, missing), cx, iy + ts / 2 + 30 * T, 11 * T, missing ? '#ffe9a8' : Lc && Lc.where === 'body' ? '#ffb86b' : '#aaa', 'center', 'normal');
      return { cx, cy: iy, size: ts };
    };
    const missA = f.near && f.need === f.a && f.have !== f.a, missB = f.near && f.need === f.b && (f.have !== f.b || f.a === f.b);
    const ra = side(f.a, ax, f.A, missA), rb = side(f.b, bx, f.B, missB), ok = !f.near && G.dots >= f.cost;
    // the beams: a body ingredient arcs down from its slot on the strip into its reactor tile; both arc into the core when the recipe is live
    if (!busy && !f.near) {
      for (const [Lc, rr, id] of [[f.A, ra, f.a], [f.B, rb, f.b]]) if (Lc && Lc.where === 'body') { const sr = BenchFx.rectOf(Lc); if (sr) { this.crackle(sr, { cx: rr.cx, cy: rr.cy - rr.size / 2 + rr.size * 0.35, size: 0 }, BULLETS[id].color, BULLETS[id].color, -40); /* (sagging: it never crosses the tabs) */ c.save(); c.setLineDash([5, 5]); c.lineDashOffset = G.time * 30; c.strokeStyle = BULLETS[id].color; c.lineWidth = 2.5; this.roundRect(sr.cx - sr.size / 2 - 3, sr.cy - sr.size / 2 - 3, sr.size + 6, sr.size + 6, sr.size * 0.18 + 3); c.stroke(); c.restore(); } }
      if (ok) { this.crackle(ra, { cx: core.cx, cy: core.cy, size: 0 }, BULLETS[f.a].color, '#ffffff'); this.crackle(rb, { cx: core.cx, cy: core.cy, size: 0 }, BULLETS[f.b].color, '#ffffff'); }
    }
    { // the core: a breathing orb in the result's colour, the result faint inside it
      const k = 0.5 + 0.5 * Math.sin(G.time * (ok ? 6 : 2)), g = c.createRadialGradient(core.cx, core.cy, 2, core.cx, core.cy, cr * (1.6 + 0.3 * k));
      g.addColorStop(0, ok ? '#ffffff' : this.tint(R.color, 0.6)); g.addColorStop(0.35, this.tint(R.color, ok ? 0.75 : 0.35)); g.addColorStop(1, 'rgba(0,0,0,0)');
      c.save(); c.globalCompositeOperation = 'lighter'; c.fillStyle = g; c.beginPath(); c.arc(core.cx, core.cy, cr * (1.6 + 0.3 * k), 0, TAU); c.fill(); c.restore();
      this.circle(core.cx, core.cy, cr, 'rgba(20,14,34,0.6)', R.color, 2.5);
      if (!busy) { c.save(); c.globalAlpha = ok ? 0.9 : 0.4; this.benchTile(f.r, core.cx, core.cy, cr * 1.15); c.restore(); }
    }
    // what comes out, and where it goes
    let yy = core.cy + cr + (tight ? 20 : 26) * T;
    this.text(this.fitText(R.name, w - pad * 2 - 60 * T, 20 * T), x + pad, yy, 20 * T, '#fff', 'left'); { const ts2 = Input.touch ? 8 : 7, tw = R.tier * ts2 * 2.2 + ts2 * 2.8; this.benchTier(R.tier, x + w - pad - tw, yy, ts2, true); } /* the stars, right-aligned */ yy += 22 * T;
    const items = Icons.bulletItems(R), ss = Input.touch ? 11 : 8;
    if (Icons.stripWidth(items, ss) <= w - pad * 2) { Icons.strip(items, x + pad, yy, ss, 'left'); yy += ss * 3.6; } // one row when it fits: more room for the words
    else { Icons.strip(items.slice(0, Math.ceil(items.length / 2)), x + pad, yy, ss, 'left'); Icons.strip(items.slice(Math.ceil(items.length / 2)), x + pad, yy + ss * 3.2, ss, 'left'); yy += ss * 6.6; }
    { const room = Math.floor((y + h - 20 * T - 50 * T - 14 * T - yy) / (18 * T)); if (room > 0) yy = this.benchWrap(Settings.howItFires(R), x + pad, yy, 13 * T, '#ffe9a8', w - pad * 2, 18 * T, Math.min(room, 4)); } // only the lines that fit above the buttons
    if (!f.near && P.isGear(f.r)) this.text(P.gear ? '→ your hat rack' : '→ onto your head', x + pad, yy + 12 * T, 13 * T, '#aaa', 'left', 'normal');
    { // the action: two buttons side by side. Left, BAG: craft it into the bag. Right, BODY (the head icon): craft it onto the
      // centipede, to wear at once. Head gear gets one CRAFT IT, and a grey pill says what is missing (dots, an ingredient)
      const bh = 50 * T, by = y + h - 20 * T - bh, k = 0.5 + 0.5 * Math.sin(G.time * 5), gap = 12 * T, bw = (w - pad * 2 - gap) / 2;
      const button = (bx, bw, label, sub, code, draw, live) => {
        if (live) { c.save(); c.shadowColor = '#7df9ff'; c.shadowBlur = 8 + 12 * k; c.fillStyle = '#7df9ff'; this.roundRect(bx, by, bw, bh, 14 * T); c.fill(); c.restore(); }
        else { c.fillStyle = 'rgba(255,255,255,0.06)'; this.roundRect(bx, by, bw, bh, 14 * T); c.fill(); c.strokeStyle = 'rgba(255,255,255,0.25)'; c.lineWidth = 2; c.stroke(); }
        const ix = bx + 24 * T, ink = live ? this.OUT : 'rgba(255,255,255,0.4)'; draw(ix, by + bh / 2, live);
        this.ink(this.fitText(label, bw - 52 * T, 15 * T), bx + 44 * T, by + bh / 2 - (sub ? 7 * T : 0), 15 * T, ink, 'left');
        if (sub) this.ink(this.fitText(sub, bw - 52 * T, 11 * T), bx + 44 * T, by + bh / 2 + 10 * T, 11 * T, ink, 'left');
        if (live && code) B.hit.buttons.push({ code, x: bx, y: by, w: bw, h: bh });
      };
      const head = (hx, hy, live) => { const r = 11 * T; this.circle(hx, hy, r, live ? '#ffb86b' : 'rgba(255,184,107,0.4)', this.OUT, 2); if (live) this.eyes(hx, hy, -Math.PI / 2, r, { fwd: 0, spread: r * 0.42, er: r * 0.3 }); };
      const bag = (bx, by2, live) => Icons.draw('bag', bx, by2, 9 * T, live ? this.OUT : 'rgba(255,255,255,0.4)');
      if (!ok) this.benchPill(x + pad, by, w - pad * 2, bh, f.near ? `find ${BULLETS[f.need].name} first` : `needs ${f.cost - G.dots} more dots`, f.near ? 'eye' : 'dot', '#999', false, null);
      else if (P.isGear(f.r)) button(x + pad, w - pad * 2, `CRAFT IT  ·  ${f.cost} dots`, Input.touch ? '' : 'ENTER', 'Enter', (hx, hy) => Icons.draw('helmet', hx, hy, 9 * T, this.OUT), true);
      else {
        const room = B.bodyRoom(f), d = B.destOf(f, 'body');
        button(x + pad, bw, 'BAG', `${f.cost} dots${Input.touch ? '' : '  ·  SPACE'}`, 'Space', bag, true);
        button(x + pad + bw + gap, bw, 'BODY', room ? `${d.where === 'body' ? `slot ${d.i + 1}` : ''}${Input.touch ? '' : '  ·  ENTER'}` : 'body full', 'Enter', head, room);
      }
    }
    if (!f.near) B.hit.reactor = { k: B.cardSel, a: ra, b: rb, core };
  },
});
