// Procedural icons: no fonts, no images. A shared visual language for bullets, elements, stats and buttons, so the
// workbench, the shop and the HUD read without English. Every glyph is drawn inside a square of half-size `s` at (x, y).
const ELEM_COLORS = { fire: '#ff8a3d', ice: '#cfeeff', electric: '#7df9ff', poison: '#9bf06a', dazzle: '#ffffff' };
const Icons = {
  // which glyph stands for a bullet's delivery
  kindOf(B) {
    if (B.kind === 'head') return B.icon || 'mega'; // head gear: its element's glyph
    if (B.kind === 'trail') return 'trail';
    if (B.spear) return 'spear';
    if (B.laser) return 'laser';
    if (B.burst) return 'burst';
    if (B.shards) return 'shards';
    if (B.kind === 'chain') return 'boomerang';
    if (B.kind === 'shot') return B.pierce ? 'pierce' : 'shot';
    return B.kind; // boomerang, orbit, homing, bounce, mine, pulse
  },
  draw(name, x, y, s, color, c) {
    c = c || Render.ctx; const O = Render.OUT, col = color || '#fff';
    c.save(); c.translate(x, y); c.lineCap = 'round'; c.lineJoin = 'round';
    const lw = Math.max(1.5, s * 0.14), out = (w) => { c.strokeStyle = O; c.lineWidth = w; };
    const poly = (pts, close) => { c.beginPath(); pts.forEach(([px, py], i) => i ? c.lineTo(px * s, py * s) : c.moveTo(px * s, py * s)); if (close) c.closePath(); };
    const stroke2 = (pts, w) => { poly(pts); out(w * 1.9); c.stroke(); c.strokeStyle = col; c.lineWidth = w; c.stroke(); };
    const disc = (r, f) => { c.beginPath(); c.arc(0, 0, r * s, 0, TAU); c.fillStyle = f || col; c.fill(); out(lw); c.stroke(); };
    switch (name) {
      case 'shot': stroke2([[-0.95, -0.35], [-0.45, -0.35]], lw); stroke2([[-0.95, 0.35], [-0.45, 0.35]], lw); c.translate(s * 0.25, 0); disc(0.5); break;
      case 'pierce': disc(0.42, 'rgba(255,255,255,0.15)'); stroke2([[-0.95, 0], [0.75, 0]], lw * 1.1); stroke2([[0.45, -0.3], [0.8, 0], [0.45, 0.3]], lw); break;
      case 'laser': stroke2([[-0.9, 0], [0.9, 0]], lw * 1.6); stroke2([[0.5, -0.4], [0.9, 0], [0.5, 0.4]], lw * 0.8); break;
      case 'burst': for (const dx of [-0.6, 0, 0.6]) { c.save(); c.translate(dx * s, 0); disc(0.26); c.restore(); } break;
      case 'shards': poly([[0, -0.95], [0.25, -0.25], [0.95, 0], [0.25, 0.25], [0, 0.95], [-0.25, 0.25], [-0.95, 0], [-0.25, -0.25]], true); c.fillStyle = col; c.fill(); out(lw); c.stroke(); break;
      case 'boomerang': c.beginPath(); c.arc(-0.2 * s, 0, 0.75 * s, -1.05, 1.05); out(lw * 2.4); c.stroke(); c.strokeStyle = col; c.lineWidth = lw * 1.3; c.stroke(); break;
      case 'orbit': c.beginPath(); c.arc(0, 0, 0.62 * s, 0, TAU); out(lw * 1.8); c.stroke(); c.strokeStyle = col; c.lineWidth = lw * 0.9; c.stroke(); c.translate(0.62 * s, 0); disc(0.28); break;
      case 'homing': for (const sx of [-1, 1]) { c.beginPath(); c.ellipse(sx * 0.45 * s, 0, 0.42 * s, 0.6 * s, sx * 0.5, 0, TAU); c.fillStyle = col; c.fill(); out(lw); c.stroke(); } c.beginPath(); c.ellipse(0, 0.05 * s, 0.16 * s, 0.5 * s, 0, 0, TAU); c.fillStyle = O; c.fill(); break;
      case 'bounce': stroke2([[-0.85, 0.6], [-0.3, -0.6], [0.25, 0.6], [0.85, -0.6]], lw); break;
      case 'mine': for (let i = 0; i < 8; i++) { const a = i * TAU / 8; stroke2([[Math.cos(a) * 0.55, Math.sin(a) * 0.55], [Math.cos(a) * 0.95, Math.sin(a) * 0.95]], lw); } disc(0.5); break;
      case 'pulse': for (const r of [0.3, 0.6, 0.9]) { c.beginPath(); c.arc(0, 0, r * s, 0, TAU); out(lw * 1.6); c.stroke(); c.strokeStyle = col; c.lineWidth = lw * 0.8; c.stroke(); } break;
      case 'trail': [[-0.6, 0.55, 0.35], [0, 0, 0.3], [0.6, -0.55, 0.25]].forEach(([px, py, r], i) => { c.save(); c.translate(px * s, py * s); c.globalAlpha = 0.5 + i * 0.25; disc(r); c.restore(); }); break;
      case 'spear': stroke2([[-0.9, 0.35], [0.45, -0.3]], lw * 1.3); for (const [px, py] of [[-0.9, 0.35], [-0.72, 0.55], [-1, 0.15]]) { c.save(); c.translate(px * s, py * s); disc(0.14); c.restore(); } poly([[0.3, -0.5], [0.95, -0.55], [0.55, 0.05]], true); c.fillStyle = col; c.fill(); out(lw * 0.8); c.stroke(); break; // a bone with a pointed head
      case 'skull': disc(0.62); c.fillStyle = col; c.fillRect(-0.4 * s, 0.35 * s, 0.8 * s, 0.45 * s); out(lw); c.strokeRect(-0.4 * s, 0.35 * s, 0.8 * s, 0.45 * s); c.fillStyle = O; for (const sg of [-1, 1]) { c.beginPath(); c.ellipse(sg * 0.26 * s, -0.08 * s, 0.17 * s, 0.2 * s, 0, 0, TAU); c.fill(); } c.beginPath(); c.moveTo(0, 0.1 * s); c.lineTo(-0.08 * s, 0.28 * s); c.lineTo(0.08 * s, 0.28 * s); c.closePath(); c.fill(); for (const dx of [-0.2, 0, 0.2]) { c.beginPath(); c.moveTo(dx * s, 0.4 * s); c.lineTo(dx * s, 0.75 * s); c.lineWidth = lw * 0.6; c.strokeStyle = O; c.stroke(); } break;
      case 'trophy': poly([[-0.7, -0.9], [0.7, -0.9], [0.55, 0], [0.2, 0.3], [0.2, 0.6], [0.55, 0.6], [0.55, 0.9], [-0.55, 0.9], [-0.55, 0.6], [-0.2, 0.6], [-0.2, 0.3], [-0.55, 0]], true); c.fillStyle = col; c.fill(); out(lw); c.stroke(); for (const sg of [-1, 1]) { c.beginPath(); c.arc(sg * 0.75 * s, -0.45 * s, 0.3 * s, sg > 0 ? -1.3 : 1.3 + Math.PI, sg > 0 ? 1.3 : -1.3 + Math.PI, sg < 0); out(lw * 1.6); c.stroke(); c.strokeStyle = col; c.lineWidth = lw * 0.8; c.stroke(); } break;
      case 'door': c.beginPath(); c.moveTo(-0.7 * s, 0.95 * s); c.lineTo(-0.7 * s, -0.2 * s); c.arc(0, -0.2 * s, 0.7 * s, Math.PI, 0); c.lineTo(0.7 * s, 0.95 * s); c.closePath(); c.fillStyle = col; c.fill(); out(lw); c.stroke(); c.beginPath(); c.moveTo(-0.4 * s, 0.95 * s); c.lineTo(-0.4 * s, -0.1 * s); c.arc(0, -0.1 * s, 0.4 * s, Math.PI, 0); c.lineTo(0.4 * s, 0.95 * s); c.closePath(); c.fillStyle = O; c.fill(); break;
      case 'grave': c.beginPath(); c.moveTo(-0.6 * s, 0.9 * s); c.lineTo(-0.6 * s, -0.3 * s); c.arc(0, -0.3 * s, 0.6 * s, Math.PI, 0); c.lineTo(0.6 * s, 0.9 * s); c.closePath(); c.fillStyle = col; c.fill(); out(lw); c.stroke(); stroke2([[-0.3, -0.2], [0.3, -0.2]], lw * 0.7); stroke2([[-0.3, 0.15], [0.3, 0.15]], lw * 0.7); break;
      // elements
      case 'fire': c.beginPath(); c.moveTo(0, -0.95 * s); c.bezierCurveTo(0.9 * s, -0.2 * s, 0.75 * s, 0.9 * s, 0, 0.9 * s); c.bezierCurveTo(-0.75 * s, 0.9 * s, -0.9 * s, -0.2 * s, 0, -0.95 * s); c.fillStyle = col; c.fill(); out(lw); c.stroke();
        c.beginPath(); c.moveTo(0, -0.2 * s); c.bezierCurveTo(0.4 * s, 0.2 * s, 0.3 * s, 0.6 * s, 0, 0.6 * s); c.bezierCurveTo(-0.3 * s, 0.6 * s, -0.4 * s, 0.2 * s, 0, -0.2 * s); c.fillStyle = '#ffe08a'; c.fill(); break;
      case 'ice': for (let i = 0; i < 3; i++) { const a = i * Math.PI / 3; stroke2([[Math.cos(a) * 0.9, Math.sin(a) * 0.9], [-Math.cos(a) * 0.9, -Math.sin(a) * 0.9]], lw * 0.9); for (const sg of [1, -1]) { const bx = Math.cos(a) * 0.5 * sg, by = Math.sin(a) * 0.5 * sg; stroke2([[bx, by], [bx + Math.cos(a + sg * 0.9) * 0.3, by + Math.sin(a + sg * 0.9) * 0.3]], lw * 0.7); stroke2([[bx, by], [bx + Math.cos(a - sg * 0.9) * 0.3, by + Math.sin(a - sg * 0.9) * 0.3]], lw * 0.7); } } break;
      case 'electric': poly([[0.25, -0.95], [-0.55, 0.1], [0, 0.1], [-0.25, 0.95], [0.6, -0.15], [0.05, -0.15]], true); c.fillStyle = col; c.fill(); out(lw * 0.9); c.stroke(); break;
      case 'poison': c.beginPath(); c.moveTo(0, -0.95 * s); c.bezierCurveTo(0.85 * s, 0.15 * s, 0.6 * s, 0.9 * s, 0, 0.9 * s); c.bezierCurveTo(-0.6 * s, 0.9 * s, -0.85 * s, 0.15 * s, 0, -0.95 * s); c.fillStyle = col; c.fill(); out(lw); c.stroke(); c.fillStyle = 'rgba(255,255,255,0.5)'; c.beginPath(); c.arc(-0.2 * s, 0.3 * s, 0.15 * s, 0, TAU); c.fill(); break;
      case 'dazzle': for (let i = 0; i < 8; i++) { const a = i * TAU / 8; stroke2([[Math.cos(a) * 0.5, Math.sin(a) * 0.5], [Math.cos(a) * 0.95, Math.sin(a) * 0.95]], lw * 0.8); } disc(0.4); break;
      // stats and things
      case 'dmg': poly([...Array(16)].map((_, i) => { const a = i * TAU / 16, r = i % 2 ? 0.45 : 0.95; return [Math.cos(a) * r, Math.sin(a) * r]; }), true); c.fillStyle = col; c.fill(); out(lw * 0.8); c.stroke(); break;
      case 'range': stroke2([[-0.9, 0], [0.85, 0]], lw); stroke2([[0.4, -0.45], [0.85, 0], [0.4, 0.45]], lw); break;
      case 'speed': stroke2([[-0.7, -0.6], [-0.15, 0], [-0.7, 0.6]], lw); stroke2([[0.1, -0.6], [0.65, 0], [0.1, 0.6]], lw); break;
      case 'rate': c.beginPath(); c.arc(0, 0, 0.65 * s, -1.2, 4.2); out(lw * 1.9); c.stroke(); c.strokeStyle = col; c.lineWidth = lw; c.stroke(); stroke2([[0.1, -0.95], [0.5, -0.6], [0.1, -0.25]], lw * 0.9); break;
      case 'blast': c.beginPath(); c.arc(0, 0, 0.45 * s, 0, TAU); out(lw * 1.8); c.stroke(); c.strokeStyle = col; c.lineWidth = lw; c.stroke(); for (let i = 0; i < 6; i++) { const a = i * TAU / 6 + 0.3; stroke2([[Math.cos(a) * 0.62, Math.sin(a) * 0.62], [Math.cos(a) * 0.95, Math.sin(a) * 0.95]], lw * 0.8); } break;
      case 'wall': for (const [bx, by, bw] of [[-0.9, -0.85, 0.85], [0.05, -0.85, 0.85], [-0.9, -0.25, 0.4], [-0.4, -0.25, 0.85], [0.55, -0.25, 0.35], [-0.9, 0.35, 0.85], [0.05, 0.35, 0.85]]) { c.fillStyle = col; c.fillRect(bx * s, by * s, bw * s, 0.5 * s); out(lw * 0.6); c.strokeRect(bx * s, by * s, bw * s, 0.5 * s); } break;
      case 'segment': disc(0.62); c.fillStyle = 'rgba(255,255,255,0.35)'; c.beginPath(); c.arc(-0.2 * s, -0.25 * s, 0.2 * s, 0, TAU); c.fill(); for (const sg of [-1, 1]) for (const dx of [-0.35, 0.35]) stroke2([[dx, sg * 0.6], [dx - 0.15, sg * 0.95]], lw * 0.7); break;
      case 'minion': for (let i = 0; i < 3; i++) for (const sg of [-1, 1]) { const a = (i - 1) * 0.45; stroke2([[0, 0], [sg * Math.cos(a) * 0.95, Math.sin(a) * 0.95 - 0.2]], lw * 0.8); } disc(0.42); c.fillStyle = O; for (const sg of [-1, 1]) { c.beginPath(); c.arc(sg * 0.17 * s, -0.08 * s, 0.1 * s, 0, TAU); c.fill(); } break;
      case 'spider-x': Icons.draw('minion', 0, 0, s * 0.8, col, c); stroke2([[0.35, 0.35], [0.95, 0.95]], lw * 1.2); stroke2([[0.95, 0.35], [0.35, 0.95]], lw * 1.2); break;
      case 'dot': disc(0.55, '#ffe9a8'); c.fillStyle = 'rgba(255,255,255,0.6)'; c.beginPath(); c.arc(-0.18 * s, -0.2 * s, 0.16 * s, 0, TAU); c.fill(); break;
      case 'ghost': Render.ghostIcon(0, 0, s * 0.85, col); break;
      case 'helmet': c.beginPath(); c.arc(0, 0.3 * s, 0.78 * s, Math.PI, 0); c.closePath(); c.fillStyle = col; c.fill(); out(lw); c.stroke(); c.beginPath(); c.rect(-0.95 * s, 0.3 * s, 1.9 * s, 0.32 * s); c.fill(); c.stroke(); c.beginPath(); c.moveTo(0, -0.48 * s); c.lineTo(0, 0.3 * s); c.stroke(); break; // Fast and Hard: a dome, its brim and a ridge
      case 'shield': poly([[0, -0.95], [0.85, -0.6], [0.75, 0.3], [0, 0.95], [-0.75, 0.3], [-0.85, -0.6]], true); c.fillStyle = col; c.fill(); out(lw); c.stroke(); break;
      case 'magnet': c.beginPath(); c.arc(0, -0.1 * s, 0.6 * s, Math.PI, 0); c.lineTo(0.6 * s, 0.6 * s); c.lineTo(0.25 * s, 0.6 * s); c.lineTo(0.25 * s, -0.1 * s); c.arc(0, -0.1 * s, 0.25 * s, 0, Math.PI, true); c.lineTo(-0.25 * s, 0.6 * s); c.lineTo(-0.6 * s, 0.6 * s); c.closePath(); c.fillStyle = col; c.fill(); out(lw); c.stroke(); c.fillStyle = '#fff'; c.fillRect(-0.6 * s, 0.3 * s, 0.35 * s, 0.3 * s); c.fillRect(0.25 * s, 0.3 * s, 0.35 * s, 0.3 * s); break;
      case 'luck': for (const [px, py] of [[0, -0.5], [-0.5, 0.1], [0.5, 0.1]]) { c.save(); c.translate(px * s, py * s); disc(0.4); c.restore(); } stroke2([[0, 0.1], [0.15, 0.95]], lw * 0.8); break;
      case 'mega': poly([...Array(10)].map((_, i) => { const a = -Math.PI / 2 + i * TAU / 10, r = i % 2 ? 0.42 : 0.95; return [Math.cos(a) * r, Math.sin(a) * r]; }), true); c.fillStyle = col; c.fill(); out(lw * 0.9); c.stroke(); break;
      case 'eye': c.beginPath(); c.ellipse(0, 0, 0.95 * s, 0.55 * s, 0, 0, TAU); c.fillStyle = '#fff'; c.fill(); out(lw); c.stroke(); disc(0.3, O); break;
      // buttons
      case 'lock': c.beginPath(); c.arc(0, -0.3 * s, 0.4 * s, Math.PI, 0); out(lw * 1.6); c.stroke(); c.strokeStyle = col; c.lineWidth = lw * 0.9; c.stroke(); c.beginPath(); c.rect(-0.62 * s, -0.1 * s, 1.24 * s, 0.95 * s); c.fillStyle = col; c.fill(); out(lw); c.stroke(); disc(0.14, O); break;
      case 'unlock': c.beginPath(); c.arc(-0.42 * s, -0.3 * s, 0.4 * s, Math.PI, -0.35); out(lw * 1.6); c.stroke(); c.strokeStyle = col; c.lineWidth = lw * 0.9; c.stroke(); c.beginPath(); c.rect(-0.62 * s, -0.1 * s, 1.24 * s, 0.95 * s); c.fillStyle = col; c.fill(); out(lw); c.stroke(); disc(0.14, O); break;
      case 'play': poly([[-0.55, -0.8], [0.8, 0], [-0.55, 0.8]], true); c.fillStyle = col; c.fill(); out(lw); c.stroke(); break;
      case 'plus': stroke2([[-0.8, 0], [0.8, 0]], lw * 1.6); stroke2([[0, -0.8], [0, 0.8]], lw * 1.6); break;
      case 'swap': stroke2([[-0.85, -0.35], [0.7, -0.35]], lw * 1.2); stroke2([[0.35, -0.7], [0.7, -0.35], [0.35, 0]], lw); stroke2([[0.85, 0.35], [-0.7, 0.35]], lw * 1.2); stroke2([[-0.35, 0], [-0.7, 0.35], [-0.35, 0.7]], lw); break;
      case 'sound': poly([[-0.85, -0.3], [-0.4, -0.3], [0.1, -0.75], [0.1, 0.75], [-0.4, 0.3], [-0.85, 0.3]], true); c.fillStyle = col; c.fill(); out(lw); c.stroke(); for (const r of [0.45, 0.8]) { c.beginPath(); c.arc(0.1 * s, 0, r * s, -0.7, 0.7); out(lw * 1.9); c.stroke(); c.strokeStyle = col; c.lineWidth = lw; c.stroke(); } break; // a speaker (mute)
      case 'cross': stroke2([[-0.7, -0.7], [0.7, 0.7]], lw * 1.5); stroke2([[0.7, -0.7], [-0.7, 0.7]], lw * 1.5); break;
      case 'bag': c.beginPath(); c.moveTo(-0.35 * s, -0.5 * s); c.bezierCurveTo(-1.05 * s, -0.1 * s, -0.95 * s, 0.95 * s, 0, 0.95 * s); c.bezierCurveTo(0.95 * s, 0.95 * s, 1.05 * s, -0.1 * s, 0.35 * s, -0.5 * s); c.closePath(); c.fillStyle = col; c.fill(); out(lw); c.stroke(); c.fillStyle = col; c.fillRect(-0.45 * s, -0.75 * s, 0.9 * s, 0.3 * s); out(lw * 0.8); c.strokeRect(-0.45 * s, -0.75 * s, 0.9 * s, 0.3 * s); stroke2([[-0.3, -0.95], [-0.45, -0.75]], lw * 0.8); stroke2([[0.3, -0.95], [0.45, -0.75]], lw * 0.8); break; // a drawstring pouch
      case 'trash': c.fillStyle = col; out(lw); c.beginPath(); c.moveTo(-0.7 * s, -0.45 * s); c.lineTo(0.7 * s, -0.45 * s); c.lineTo(0.5 * s, 0.9 * s); c.lineTo(-0.5 * s, 0.9 * s); c.closePath(); c.fill(); c.stroke(); c.beginPath(); c.rect(-0.85 * s, -0.75 * s, 1.7 * s, 0.28 * s); c.fill(); c.stroke(); c.beginPath(); c.rect(-0.28 * s, -0.98 * s, 0.56 * s, 0.25 * s); c.fill(); c.stroke(); break;
      case 'shovel': stroke2([[-0.85, 0.85], [0.1, -0.1]], lw * 1.5); stroke2([[-0.95, 0.55], [-0.55, 0.95]], lw * 1.2); poly([[0.05, -0.15], [0.45, -0.95], [0.95, -0.45], [0.15, -0.05]], true); c.fillStyle = col; c.fill(); out(lw); c.stroke(); break; // a spade: blade up and right, T-handle bottom left
      case 'cog': for (let i = 0; i < 8; i++) { const a = i * TAU / 8; c.save(); c.rotate(a); c.fillStyle = col; c.strokeStyle = O; c.lineWidth = lw; c.beginPath(); c.rect(-0.22 * s, -0.98 * s, 0.44 * s, 0.4 * s); c.fill(); c.stroke(); c.restore(); } disc(0.62); c.fillStyle = O; c.beginPath(); c.arc(0, 0, 0.22 * s, 0, TAU); c.fill(); break;
      case 'hammer': stroke2([[-0.7, 0.85], [0.3, -0.15]], lw * 1.4); poly([[0, -0.55], [0.5, -0.95], [0.95, -0.5], [0.45, 0]], true); c.fillStyle = col; c.fill(); out(lw); c.stroke(); break;
      default: disc(0.5);
    }
    c.restore();
  },
  // a row of [icon, text] pairs, left-aligned at x (or centred when align === 'center'); returns the width
  // how wide a strip of items draws at icon size s (the HUD panel sizes itself to it)
  stripWidth(items, s, c) { c = c || Render.ctx; return items.reduce((a, it) => a + s * 2 + (it.text ? (c.font = `bold ${Math.round(s * 1.6)}px "Trebuchet MS", "Segoe UI", sans-serif`, c.measureText(it.text).width + s * 0.5) : 0) + s * 1.1, 0); },
  strip(items, x, y, s, align, c) {
    c = c || Render.ctx;
    const measure = it => s * 2 + (it.text ? (c.font = `bold ${Math.round(s * 1.6)}px "Trebuchet MS", "Segoe UI", sans-serif`, c.measureText(it.text).width + s * 0.5) : 0) + s * 1.1;
    const total = items.reduce((a, it) => a + measure(it), 0);
    let px = align === 'center' ? x - total / 2 : align === 'right' ? x - total : x;
    for (const it of items) {
      this.draw(it.icon, px + s, y, s, it.color, c);
      if (it.text) Render.text(it.text, px + s * 2.3, y + 1, Math.round(s * 1.6), it.textColor || '#fff', 'left');
      px += measure(it);
    }
    return total;
  },
  // the stat strip of a bullet: delivery, damage, elements, special bits, range, rate
  bulletItems(B, opts) {
    opts = opts || {};
    if (B.minion) return [{ icon: 'minion', color: B.color }, { icon: 'dmg', color: '#ff7b6b', text: `${+(BULLETS.bullet.dmg * CFG.MINIONS.spitMult * (opts.raw ? 1 : G.powerMult)).toFixed(1)}` }, { icon: 'rate', color: '#ccc', text: `${+(1 / CFG.MINIONS.spitCd).toFixed(1)}/s` }, { icon: 'blast', color: '#ffd166' }]; // a riding minion: spit damage, rate, and the dive
    const ramp = Combat.ramp(B), dmg = B.dmg * (opts.raw ? 1 : G.powerMult) * ramp, items = [];
    items.push({ icon: this.kindOf(B), color: B.color });
    items.push({ icon: 'dmg', color: '#ff7b6b', text: B.kind === 'trail' ? `${+dmg.toFixed(1)}/s` : `${+dmg.toFixed(1)}` });
    for (const e of (B.elems || [])) items.push({ icon: e, color: ELEM_COLORS[e] });
    if (B.pierce) items.push({ icon: 'pierce', color: '#fff', text: B.pierce >= 99 ? '∞' : '×' + B.pierce });
    if (B.burst) items.push({ icon: 'burst', color: B.color, text: '×' + B.burst });
    if (B.shards) items.push({ icon: 'shards', color: B.color, text: '×' + B.shards });
    if (B.bounces && B.kind === 'bounce') items.push({ icon: 'bounce', color: '#fff', text: '×' + B.bounces });
    if (B.hops) items.push({ icon: 'bounce', color: '#fff', text: '×' + B.hops + ' hops' });
    if (B.bounces && B.kind === 'shot') items.push({ icon: 'bounce', color: '#ffd166', text: '×' + B.bounces });
    if (B.blast || ['gas', 'bomb', 'napalm'].includes(B.combo)) items.push({ icon: 'blast', color: '#ffd166' });
    if (B.combo === 'berserk' || B.combo === 'madmoth') items.push({ icon: 'eye', color: '#ff7bff' });
    if (B.wallMult) items.push({ icon: 'wall', color: '#b8a48e', text: '×' + B.wallMult });
    if (!opts.short) {
      if (B.kind === 'trail') items.push({ icon: 'rate', color: '#ccc', text: `${B.life}s` });
      else {
        if (B.range) items.push({ icon: 'range', color: '#ccc', text: `${Math.round(B.range * G.mods.range)}` });
        if (B.kind !== 'orbit' && B.kind !== 'pulse' && B.kind !== 'mine' && B.kind !== 'head' && Number.isFinite(B.speed)) items.push({ icon: 'speed', color: '#ccc', text: `${Math.round(B.speed * G.mods.bspeed)}` }); // px/s: you crawl at about 205
        items.push({ icon: 'rate', color: '#ccc', text: `${+(1 / (B.cd * G.mods.cd)).toFixed(1)}/s` });
      }
    }
    return items;
  },
  // one bullet tile: colour square, delivery glyph, element badges in the corner
  tile(B, cx, cy, size, c) {
    c = c || Render.ctx;
    const el = B.elems || [];
    this.draw(this.kindOf(B), cx, cy - (el.length ? size * 0.05 : 0), size * 0.28, B.dark, c);
    if (B.bounces && B.kind === 'shot') this.draw('bounce', cx - size * 0.32, cy + size * 0.32, size * 0.1, '#ffd166', c); // the ricochet badge
    el.forEach((e, k) => { const bx = cx + size * 0.32 - k * size * 0.26, by = cy + size * 0.32; c.fillStyle = Render.OUT; c.beginPath(); c.arc(bx, by, size * 0.15, 0, TAU); c.fill(); this.draw(e, bx, by, size * 0.09, ELEM_COLORS[e], c); });
  },
};
