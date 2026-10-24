// The workbench BAG page (panel 'forge'): the body strip, the bag in big thumb-sized tiles (drag one onto another to fuse),
// and the info card (the inspector) on the right. The recipe cards live on the LAB (render-bench-lab.js); the shared
// widgets are in render-bench.js.
// Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  // ---- BAG ----
  // the working area (left two thirds): the body strip centred over it, then the bag, as big as fits.
  // The info card (right third): the selected piece and its actions, joined to its tile by a line.
  benchForge() {
    const c = this.ctx, M = this.BENCH.M, W = CFG.W, H = CFG.H, P = Player, B = Build, T = this.benchText(), TOP = this.benchTopH(), focus = BenchFx.focus;
    const I = this.benchInfo(), IW = I.w, ix = I.x, lw = ix - 24 - M, left = M;
    // the band: the same toolbar, synergy line and body row as the BODY page
    const top = this.benchBand(1 + 0.22 * focus) + 16 * T, visibleH = H - 14 - top;
    // the bag has the column to itself: its tiles start big (a thumb has to hit and drag them) and shrink only until they fit
    const groups = P.bagGroups(), bn = groups.length, bgap = 16, bagPad = 30 + (T - 1) * 10, hintH = 30 * T;
    let bt = Input.touch ? 104 : 88, perRow, brows;
    for (;;) { perRow = Math.max(1, Math.floor((lw + bgap) / (bt + bgap))); brows = Math.max(1, Math.ceil(bn / perRow)); if (26 + brows * (bt + bagPad) + hintH <= visibleH || bt <= 44) break; bt -= 4; }
    c.save();
    if (focus > 0.01) { c.globalAlpha = 1 - 0.45 * focus; const s = 1 - 0.06 * focus, cx = left + lw / 2, cy = top + brows * (bt + bagPad) / 2; c.translate(cx, cy); c.scale(s, s); c.translate(-cx, -cy); } // the bag steps back while a result flies to the body
    this.benchBag(left, top, lw, bt, perRow, bagPad);
    c.restore();
    { const n = P.fusions().filter(f => G.dots >= f.cost).length; this.text(this.fitText(`Drag one onto another to fuse them  ·  ${n ? `${n} recipe${n > 1 ? 's' : ''} ready in the LAB` : 'recipes: the LAB'}`, lw, 13 * T), left + lw / 2, H - 14 - hintH / 2, 13 * T, n ? '#7df9ff' : 'rgba(255,255,255,0.5)', 'center', 'normal'); } // where the recipe cards went
    // the divider, then the info card
    c.fillStyle = 'rgba(255,255,255,0.1)'; c.fillRect(ix - 12, TOP + 10, 1, H - TOP - 24);
    this.benchInspector(I.x, I.y, I.w, I.h);
  },

  // the bag as stacks (alphabetical, so a stack never moves when you use one); returns the y below it
  benchBag(x, y, w, bt, perRow, pad) {
    const c = this.ctx, P = Player, B = Build, T = this.benchText(), D = B.drag && B.drag.moved ? B.drag : null, dragFrom = D ? D.from : null;
    const markId = D ? D.id : (B.mark ? P.idAt(B.mark) : null), hover = D ? B.tileAt({ x: D.x, y: D.y }) : null;
    const groups = P.bagGroups(), bn = groups.length, rows = Math.max(1, Math.ceil(bn / perRow)), gap = 16, pitch = bt + pad, nf = Math.min(13 * T, Math.max(11, bt * 0.22));
    { const col = B.row === 'bag' ? '#ffd166' : 'rgba(255,255,255,0.6)'; Icons.strip([{ icon: 'bag', text: `${P.bag.length}  ·  ${P.bagTypes()}/${P.bagMax()}`, color: col, textColor: col }], x - 4, y, 8 * T, 'left'); } // pieces in the bag · kinds of the most it holds
    const y0 = y + 22 + bt / 2;
    B.hit.bagRow = { x, y: y + 10, w, h: rows * pitch + 10 };
    if (!bn) this.text(D && dragFrom.where === 'body' ? 'drop here to stash it' : 'empty: bullets you pick up with a full body land here', x + w / 2, y0, 14 * T, '#888', 'center', 'normal');
    groups.forEach((g, i) => {
      const r = Math.floor(i / perRow), k = i % perRow, inRow = Math.min(perRow, bn - r * perRow), rowW = inRow * bt + (inRow - 1) * gap, bx = x + w / 2 - rowW / 2 + k * (bt + gap), cy = y0 + r * pitch, cx = bx + bt / 2;
      const sel = B.row === 'bag' && B.bagSel === i, marked = B.mark && B.mark.where === 'bag' && g.idx.includes(B.mark.i);
      const src = dragFrom && dragFrom.where === 'bag' && g.idx.includes(dragFrom.i);
      const pairs = markId && ((marked || src) ? (g.idx.length > 1 && findRecipe(markId, g.id)) : findRecipe(markId, g.id));
      const hov = hover && hover.where === 'bag' && hover.gi === i && !(src && g.idx.length < 2);
      this.benchTile(g.id, cx, cy, bt, { sel: sel && !D, marked, pairs, ghost: src && g.idx.length === 1, hov, where: 'bag', k: g.id, fresh: P.freshBag.has(g.id), isNew: !Settings.benchSeen.has(g.id) });
      if (g.idx.length > 1 && !BenchFx.isPending('bag', g.id)) { const shown = src ? g.idx.length - 1 : g.idx.length, ls = BenchFx.landScale('bag', g.id), fs = Math.round(11 * T); c.save(); if (ls) { c.translate(cx, cy); c.scale(ls.sx, ls.sy); c.translate(-cx, -cy); } c.font = `bold ${fs}px "Trebuchet MS", "Segoe UI", sans-serif`; const bw = c.measureText('x' + shown).width + 10, bh = fs + 7, rx = bx + bt + 5 - bw, ry = cy + bt / 2 + 5 - bh; c.fillStyle = this.OUT; this.roundRect(rx, ry, bw, bh, bh / 2); c.fill(); const full = shown >= CFG.STACK_MAX; c.strokeStyle = full ? '#ffd166' : '#fff'; c.lineWidth = full ? 2 : 1.5; c.stroke(); this.text('x' + shown, rx + bw / 2, ry + bh / 2 + 1, fs, full ? '#ffd166' : '#fff', 'center'); c.restore(); } /* tucked into the tile's bottom-right corner, sized to its number, so the icon stays visible */ // gold: a full stack (CFG.STACK_MAX)
      { const ft = this.benchFit(BULLETS[g.id].name, bt + gap - 4, nf, 11); this.text(ft.text, cx, cy + bt / 2 + 6 + nf * 0.75, ft.size, '#fff', 'center', 'normal'); }
      B.hit.bag.push({ i, x: bx - 8, y: cy - bt / 2 - 8, w: bt + 16, h: bt + 20 + nf, tile: bt });
    });
    return y + 22 + rows * pitch;
  },

  // the info card: the selected piece in the discovery card's style, with its actions; slides in when the selection
  // changes, and a line joins it to the tile it describes
  benchInspector(x, y, w, h, compact) { // compact: the BODY page's card (no trash or stash)
    if (Build.row === 'pills') { this.benchPillInfo(x, y, w, h); return; } // the toolbar: what the button under the cursor does // (first: it keeps its own slide-in key, and two keys a frame would restart the slide forever)
    const c = this.ctx, P = Player, B = Build, T = this.benchText(), L = B.cursor(), cur = P.idAt(L), key = cur ? `${L.where}:${L.i}:${cur}` : 'none', pad = 20;
    if (key !== B.inspKey) { B.inspKey = key; B.inspT = 0; }
    const k = Math.min(1, B.inspT / 0.3), ease = 1 - Math.pow(1 - k, 3), ox = (1 - ease) * 60;
    const Bw = cur ? BULLETS[cur] : null, col = Bw ? Bw.color : 'rgba(255,255,255,0.2)';
    // the connector: from the selected tile's edge to the card, in the piece's colour
    const r = cur ? BenchFx.rectOf(L) : null;
    if (r && ease > 0.2) { c.save(); c.globalAlpha = ease; c.strokeStyle = col; c.lineWidth = 3; c.setLineDash([]); const x1 = r.cx, y1 = r.cy - r.size / 2 - 4, x2 = x + ox, y2 = U.clamp(y1 - 14, y + 30, y + h - 30); /* up off the tile's top, then along just above the row to the card: it never crosses the other tiles */ c.beginPath(); c.moveTo(x1, y1); c.bezierCurveTo(x1, y2, x1 + Math.min(40, (x2 - x1) * 0.3), y2, x1 + Math.min(60, (x2 - x1) * 0.4), y2); c.lineTo(x2, y2); c.stroke(); c.fillStyle = col; c.beginPath(); c.arc(x1, y1, 5, 0, TAU); c.fill(); c.beginPath(); c.arc(x2, y2, 5, 0, TAU); c.fill(); c.restore(); }
    c.save(); c.globalAlpha = 0.3 + 0.7 * ease; c.translate(ox, 0);
    c.fillStyle = '#1b1428'; this.roundRect(x, y, w, h, 16); c.fill(); c.strokeStyle = col; c.lineWidth = cur ? 2.5 : 1.5; c.stroke();
    Icons.strip([{ icon: 'eye', color: cur ? col : 'rgba(255,255,255,0.6)' }, ...(cur ? [L.where === 'body' ? { icon: 'segment', text: String(L.i + 1), color: col, textColor: col } : { icon: 'bag', color: col }] : [])], x + pad - 4, y + 26, 9 * T, 'left'); // what this card shows: a body slot (its number) or the bag
    if (!cur) { this.benchWrap(compact ? 'Select a body segment: this card says what it does.' : 'Tap a bag bullet or a body segment: this card says what it does and what you can do with it.', x + pad, y + 60, 13 * T, 'rgba(255,255,255,0.6)', w - pad * 2, 18 * T); c.restore(); if (!compact) this.benchSquares(x, y, w, h, false, null); return; }
    const sg = L.where === 'body' ? P.segments[L.i] : null, onBody = L.where === 'body';
    const ts = 72; c.fillStyle = Bw.color; this.roundRect(x + pad, y + 44, ts, ts, ts * 0.18); c.fill(); c.strokeStyle = Bw.craft ? '#fff' : this.OUT; c.lineWidth = 3; c.stroke(); Icons.tile(Bw, x + pad + ts / 2, y + 44 + ts / 2, ts, c);
    const nx = x + pad + ts + 14, nw = w - pad - (nx - x);
    this.text(this.fitText(Bw.name, nw, 21 * T), nx, y + 44 + 24, 21 * T, '#fff', 'left');
    { const ty = y + 44 + 24 + 22 * T, tx = this.benchTier(Bw.tier, nx, ty, Input.touch ? 9 : 8, Bw.craft); if (sg && sg.pipe) this.text(`${sg.pipeBoost ? '+++' : '+'}${PIPES[sg.pipe].tag}`, tx + 6, ty, Input.touch ? 16 : 12, 'rgba(255,255,255,0.65)', 'left', 'normal'); } // tier as stars (a fused one gets the hammer), then its piping
    let yy = y + 44 + ts + 20 + (T - 1) * 10; const items = Icons.bulletItems(Bw), half = Math.ceil(items.length / 2), ss = Input.touch ? 12 : 8, sp = ss * 3.2;
    Icons.strip(items.slice(0, half), x + pad, yy, ss, 'left'); if (items.length > half) Icons.strip(items.slice(half), x + pad, yy + sp, ss, 'left'); yy += items.length > half ? sp * 2 : sp;
    if (onBody) { this.text('STATUS CHAIN', x + pad, yy + 6, 11 * T, 'rgba(255,255,255,0.6)', 'left'); yy += 6 + this.benchChain(x + w / 2, yy - 4, L.i, Math.min(T, 1.2), w - pad * 2); } // what it inherits and what it hands on
    yy = this.benchWrap(Settings.howItFires(Bw), x + pad, yy + 4, 13 * T, '#ffe9a8', w - pad * 2, 18 * T, Input.touch ? 2 : 3);
    if (!Input.touch) yy = this.benchWrap(Bw.desc, x + pad, yy + 6, 12, 'rgba(255,255,255,0.7)', w - pad * 2, 16, 3);
    // actions: two squares at the bottom, STASH (back to the bag) and TRASH, for a body segment; a bag bullet gets its
    // ADD / SWAP pill above the bin. Marking is dragging or a card now, so there is no button for it
    c.restore(); if (compact) return;
    this.benchSquares(x, y, w, h, onBody && !P.isMinion(sg), onBody ? null : [P.weaponCount() < P.maxSegs ? 'ADD to the body' : `SWAP into slot ${Math.min(B.sel, P.segments.length - 1) + 1}`, P.weaponCount() < P.maxSegs ? 'plus' : 'swap', '#7df9ff', 'Enter', true]);
  },

  // the bottom of the info card: the TRASH square (a drop target and, tapped, destroys the selected piece), the STASH
  // square beside it when a body segment is selected (a drop target too), and an optional pill above them
  // the info card's bottom row: TRASH at the far left, the piece's action (STASH, or ADD / SWAP for a bag bullet) at the far
  // right, the same size, with the card's width between them so a thumb meant for one never lands on the other
  benchSquares(x, y, w, h, stash, pill) {
    const B = Build, T = this.benchText(), sq = Math.round(84 * Math.min(T, 1.15)), cy = y + h - 34 - sq / 2, D = B.drag && B.drag.moved ? B.drag : null, lx = x + 20 + sq / 2, rx = x + w - 20 - sq / 2;
    this.benchBin(lx, cy, sq);
    B.hit.stash = null;
    if (stash) {
      const hov = D && D.from.where === 'body' && B.inside({ x: D.x, y: D.y }, { x: rx - sq / 2, y: cy - sq / 2, w: sq, h: sq });
      B.hit.stash = this.square(rx, cy, sq, { icon: 'bag', label: 'STASH', color: '#7df9ff', hov });
      B.hit.buttons.push({ code: 'KeyX', ...B.hit.stash });
    } else if (pill) {
      const [label, icon, col, code] = pill, add = /^ADD/.test(label), k = 0.5 + 0.5 * Math.sin(G.time * 5);
      const r = this.square(rx, cy, sq, { icon, label: add ? 'ADD' : 'SWAP', sub: add ? 'to the body' + (Input.touch ? '' : ' (ENTER)') : label.replace(/^SWAP /, '') + (Input.touch ? '' : ' (ENTER)'), color: col, on: true });
      { const c = this.ctx; c.save(); c.strokeStyle = col; c.globalAlpha = 0.4 + 0.5 * k; c.lineWidth = 2 + 2 * k; this.roundRect(r.x - 4, r.y - 4, r.w + 8, r.h + 8, 18); c.stroke(); c.restore(); } // the thing to press, pulsing
      if (code) B.hit.buttons.push({ code, ...r });
    }
  },
});
