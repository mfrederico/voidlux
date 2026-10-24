// The workbench BODY (LOADOUT) panel: the centipede big, the element synergy chips and auras, the readout band and the
// summary cards that link to the other pages. The shared widgets and the body row are in render-bench.js.
// Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  // ---- BODY ----
  // The gallery lane is pinned at the bottom; the blueprint and the readout sit above it; the body gets everything
  // else and is centred in it, so a one-row body sits big and a three-row body (thirty segments) still fits.
  // element synergy, as a row of chips: each element on the body with its count (3 lights it up, with what it does)
  // the aura around a segment in a synergy run, drawn behind its tile: flames lick up, frost pulses and glints, lightning
  // crackles round the edge, poison puffs bubbles
  synergyAura(el, cx, cy, tile, i) {
    const c = this.ctx, t = G.time, h = tile / 2, col = synInfo(el).color; c.save();
    if (el === 'fire') {
      for (let k = 0; k < 9; k++) { const u = (k + 0.5) / 9, x = cx - h + u * tile, f = 0.55 + 0.45 * Math.sin(t * 9 + k * 1.7 + i), fh = tile * (0.22 + 0.2 * f), w = tile * 0.09;
        const g = c.createLinearGradient(x, cy - h, x, cy - h - fh); g.addColorStop(0, 'rgba(255,90,30,0.85)'); g.addColorStop(0.6, 'rgba(255,170,60,0.6)'); g.addColorStop(1, 'rgba(255,230,140,0)');
        c.fillStyle = g; c.beginPath(); c.moveTo(x - w, cy - h + 4); c.quadraticCurveTo(x - w * 0.6, cy - h - fh * 0.5, x + Math.sin(t * 6 + k) * w * 0.6, cy - h - fh); c.quadraticCurveTo(x + w * 0.6, cy - h - fh * 0.5, x + w, cy - h + 4); c.fill(); }
      c.shadowColor = '#ff8a3d'; c.shadowBlur = 18 + 8 * Math.sin(t * 7 + i); c.strokeStyle = 'rgba(255,138,61,0.7)'; c.lineWidth = 3; this.roundRect(cx - h - 3, cy - h - 3, tile + 6, tile + 6, tile * 0.2); c.stroke();
    } else if (el === 'ice') {
      const k = 0.5 + 0.5 * Math.sin(t * 3 + i * 0.8); c.shadowColor = '#cfeeff'; c.shadowBlur = 14 + 14 * k; c.strokeStyle = `rgba(207,238,255,${0.45 + 0.4 * k})`; c.lineWidth = 3; this.roundRect(cx - h - 5, cy - h - 5, tile + 10, tile + 10, tile * 0.22); c.stroke(); c.shadowBlur = 0;
      for (let s = 0; s < 6; s++) { const a = t * 0.7 + s * TAU / 6 + i, r = h + 10, x = cx + Math.cos(a) * r * 1.05, y = cy + Math.sin(a) * r, z = 3 + 2 * Math.sin(t * 5 + s); c.strokeStyle = 'rgba(255,255,255,0.9)'; c.lineWidth = 1.5; c.beginPath(); c.moveTo(x - z, y); c.lineTo(x + z, y); c.moveTo(x, y - z); c.lineTo(x, y + z); c.stroke(); }
    } else if (el === 'electric') {
      const seed = Math.floor(t * 14) + i * 7, rnd = n => { const v = Math.sin(seed * 12.9898 + n * 78.233) * 43758.5453; return v - Math.floor(v); };
      c.shadowColor = col; c.shadowBlur = 12; c.strokeStyle = col; c.lineWidth = 2; c.beginPath();
      const pts = 28, R = h + 6 * Math.min(1, tile / 80); for (let p = 0; p <= pts; p++) { const u = p / pts, side = Math.floor(u * 4), f = u * 4 - side, j = (rnd(p) - 0.5) * 9 * Math.min(1, tile / 80); let x, y;
        if (side === 0) { x = cx - R + f * 2 * R; y = cy - R + j; } else if (side === 1) { x = cx + R + j; y = cy - R + f * 2 * R; } else if (side === 2) { x = cx + R - f * 2 * R; y = cy + R + j; } else { x = cx - R + j; y = cy + R - f * 2 * R; }
        p ? c.lineTo(x, y) : c.moveTo(x, y); }
      c.stroke(); if (rnd(99) > 0.6) { c.lineWidth = 1.5; c.beginPath(); const a = rnd(5) * TAU; c.moveTo(cx + Math.cos(a) * R, cy + Math.sin(a) * R); c.lineTo(cx + Math.cos(a) * (R + 14), cy + Math.sin(a) * (R + 14) + (rnd(6) - 0.5) * 10); c.stroke(); }
    } else if (el === 'bone') { // bone: shards of bone tumbling slowly round the tile, and a dusty glow
      c.shadowColor = col; c.shadowBlur = 10 + 4 * Math.sin(t * 2 + i); c.strokeStyle = 'rgba(234,228,212,0.55)'; c.lineWidth = 2.5; this.roundRect(cx - h - 3, cy - h - 3, tile + 6, tile + 6, tile * 0.2); c.stroke(); c.shadowBlur = 0;
      for (let q = 0; q < 4; q++) { const a = t * 0.9 + q * TAU / 4 + i * 0.7, x = cx + Math.cos(a) * (h + 9), y = cy + Math.sin(a) * (h + 9), L = Math.max(5, tile * 0.11); c.save(); c.translate(x, y); c.rotate(a * 2); c.strokeStyle = '#eae4d4'; c.lineWidth = Math.max(2, tile * 0.035); c.lineCap = 'round'; c.beginPath(); c.moveTo(-L, 0); c.lineTo(L, 0); c.stroke(); c.fillStyle = '#eae4d4'; for (const s2 of [-1, 1]) { c.beginPath(); c.arc(s2 * L, -1.5, 2, 0, TAU); c.arc(s2 * L, 1.5, 2, 0, TAU); c.fill(); } c.restore(); }
    } else if (el === 'poison') {
      for (let b = 0; b < 7; b++) { const ph = (t * 0.6 + b / 7 + i * 0.13) % 1, side = b % 2 ? 1 : -1, x = cx + side * (h + 3 + ((b * 37 + i * 11) % 9) + ph * 6), y = cy + h - ph * (tile + 30), r = (3 + ph * 7) * Math.max(0.6, tile / 80); /* up the tile's sides, where they show */
        c.globalAlpha = 0.75 * (1 - ph); c.fillStyle = 'rgba(155,240,106,0.45)'; c.strokeStyle = col; c.lineWidth = 1.5; c.beginPath(); c.arc(x, y, r, 0, TAU); c.fill(); c.stroke(); }
      c.globalAlpha = 1; c.shadowColor = col; c.shadowBlur = 14 + 6 * Math.sin(t * 2.5 + i); c.strokeStyle = 'rgba(155,240,106,0.6)'; c.lineWidth = 3; this.roundRect(cx - h - 3, cy - h - 3, tile + 6, tile + 6, tile * 0.2); c.stroke();
    }
    c.restore();
  },

  benchSynergy(x, y, maxW) {
    const P = Player, T = this.benchText(), K = CFG.SYNERGY, n = P.synN || {}, els = Object.keys(n).sort((a, b) => n[b] - n[a]); if (!els.length) return;
    let cx = x; this.text('SYNERGY', cx, y, 12 * T, 'rgba(255,255,255,0.45)', 'left'); this.ctx.font = `bold ${Math.round(12 * T)}px "Trebuchet MS", "Segoe UI", sans-serif`; cx += this.ctx.measureText('SYNERGY').width + 12 * T;
    for (const el of els) {
      const on = P.syn && P.syn[el], col = synInfo(el).color, label = on ? `${n[el]}  ${P.synText(el)}` : `${n[el]}/${K.need}${K.inRow ? ' in a row' : ''}`;
      Icons.draw(synInfo(el).icon, cx + 8 * T, y, 7 * T, on ? col : 'rgba(255,255,255,0.35)'); cx += 18 * T;
      const t = this.fitText(label, Math.max(40, x + maxW - cx), 13 * T); this.text(t, cx, y, 13 * T, on ? col : 'rgba(255,255,255,0.45)', 'left', on ? 'bold' : 'normal');
      this.ctx.font = `${on ? 'bold' : 'normal'} ${Math.round(13 * T)}px "Trebuchet MS", "Segoe UI", sans-serif`; cx += this.ctx.measureText(t).width + 18 * T; if (cx > x + maxW - 40) break;
    }
  },

  // the info card on the right: the same place and size on every page (BODY, FORGE, PERKS), from under the tabs to the bottom
  benchInfo() { const M = this.BENCH.M, T = this.benchText(), w = Math.round(Math.min(340 * T, CFG.W * 0.3)), cb = Input.touch && TOUCH.buttons.find(b => b.id === 'pause'), y = Math.max(this.benchTopH() + 10, cb ? cb.y + cb.r + 10 : 0); /* (a phone: below the play and settings circles, which hang lower than the tabs) */ return { x: CFG.W - M - w, y, w, h: CFG.H - 14 - y }; },

  // the band at the top of BODY and FORGE, the same on both (so the body you select on is one thing, not two): the loadout
  // toolbar (FILL, SAVE LOADOUT and the locked build), the synergy line, and the body row. Returns its bottom
  benchBand(scale) {
    const M = this.BENCH.M, P = Player, T = this.benchText(), TOP = this.benchTopH(), I = this.benchInfo(), lw = I.x - 24 - M;
    const free = P.maxSegs - P.weaponCount(), fill = free > 0 && P.bag.length > 0 && !P.autoRebuild, aw = fill ? 150 * T : 0, ph = 30 * T, ly = TOP + 30 * T; // FILL (only unlocked, with empty slots and a bag to fill them from: a lock fills its own), then the padlock and the blueprint
    if (fill) Build.hit.pills.push(this.benchPill(M, ly - ph / 2, aw, ph, `FILL ${free} empty slot${free > 1 ? 's' : ''}`, 'dazzle', '#ffd166', true, 'KeyZ', true));
    const bx0 = M + (fill ? aw + 10 : 0);
    this.benchBlueprint(bx0, ly, M + lw - bx0);
    { const r = Build.row === 'pills' && Build.hit.pills[Build.pillSel]; if (r) { const k = 0.5 + 0.5 * Math.sin(G.time * 6), c = this.ctx; c.save(); c.strokeStyle = '#fff'; c.lineWidth = 2.5 + 1.5 * k; c.globalAlpha = 0.7 + 0.3 * k; this.roundRect(r.x - 5, r.y - 5, r.w + 10, r.h + 10, (r.h + 10) / 2); c.stroke(); c.restore(); } } // the keyboard cursor on FILL / SAVE LOADOUT
    const synY = ly + ph / 2 + 16 * T, rowTop = synY + 14 * T; // the synergy line has its own place under the toolbar; the body starts under it
    this.benchSynergy(M, synY, lw - 6); // the element synergy line, just above the body it describes
    const slots = (Build.ghost && P.blueprint.length ? P.blueprint.length : P.segments.length) + (P.weaponCount() < P.maxSegs ? 1 : 0), rows = Math.max(1, Math.ceil(slots / 10));
    const row = Input.touch ? this.benchBodyRow({ y: rowTop + 36, x0: M, w: lw, big: false, maxTile: Math.max(36, Math.min(84, Math.floor(lw / (slots + 1.6)) - 8)), scale }) // a phone: one strip (numbers, no names), big enough to drop a bag piece on with a thumb
      : this.benchBodyRow({ y: rowTop + (rows > 1 ? 32 : 54), x0: M, w: lw, big: true, scale });
    if (Build.held != null && P.segments[Build.held] && !Input.touch) this.text(`Holding ${BULLETS[P.segments[Build.held].id].name}  ·  A / D to choose  ·  ENTER to swap  ·  ESC to put it back`, M + lw / 2, row.bottom + 14 * T, 13 * T, '#7df9ff', 'center', 'normal');
    this.bandTile = row.tile; // (the FORGE's bag never draws bigger than the body)
    return row.bottom + (Build.held != null && !Input.touch ? 24 * T : 0);
  },

  benchBody() { // the band (toolbar, synergy, body), then the MEGA card and the gallery in the room under it; the info card on the right
    const M = this.BENCH.M, H = CFG.H, T = this.benchText(), I = this.benchInfo(), lw = I.x - 24 - M;
    const bottom = this.benchBand(), touch = Input.touch, megaH = touch ? 116 : Math.max(104, 104 + (T - 1) * 28), my = bottom + 12 * T, gy = my + megaH + 10, gh = Math.max(96, Math.min(140, H - 14 - gy)); /* stacked under the body: MEGA (a phone: full width, the HEAD card under it), then a compact gallery */
    this.benchInspector(I.x, I.y, I.w, I.h, true);
    { const gw = Math.round(lw * 0.48); this.benchMega(M, my, lw - gw - 10, megaH); this.benchGear(M + lw - gw, my, gw, megaH); } // the active MEGA and the HAT, side by side (each opens its page)
    Gallery.draw(M, gy, lw, gh);
  },
});
