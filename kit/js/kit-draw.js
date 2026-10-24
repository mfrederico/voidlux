// VoidLux kit: drawing helpers on the game canvas, and the sprite atlas.
//   Draw.init(canvas)             the context (Draw.ctx), its pixel scale (Draw.px) for the atlas
//   Draw.text / Draw.ink          outlined text (game text over a busy scene) / plain text (on a badge or button)
//   Draw.circle, Draw.roundRect, Draw.ring, Draw.glow
//   Draw.sprite(key, half, paint) a thing drawn once into the atlas by paint() (at 0,0, facing angle 0, within radius half),
//                                 then Draw.stamp(S, x, y, angle) puts it down every frame after. Hundreds of enemies of a few
//                                 kinds stamped cost a fraction of drawing each one stroke by stroke: use it for anything that
//                                 repeats. Anything upright or changing every frame (shadows, bars) is drawn live around it.
//   Draw.textStamp(s, x, y, size, color, align)  the same for text that repeats (damage numbers, scores)
//   Draw.loadManifest()           assets/sprites.json, written by the sprite editor and the builder: key → { file, w, h, frames,
//                                 fps }. Game.boot calls it; no file means no sprites. Draw.load(key, url) fetches one picture by hand.
//   Draw.pic(key, x, y, w, h, angle, frame)  draws a sprite centred, crisp (nothing, and false, until its file has arrived; a file
//                                 that will not load is a console error, which the soak reports). A strip of frames: frame picks one;
//                                 Draw.anim(key, Game.time, x, y, w) runs through them at the manifest's fps. Draw.loaded(key) says
//                                 when it is there; something stamped hundreds of times goes into the atlas then:
//                                 Draw.sprite(key, half, g => g.drawImage(Draw.images.get(key), -half, -half, half * 2, half * 2)).
// The atlas is one 2048 px canvas per page (one for sprites, one for text) packed in shelves; a full page is wiped and refills.
const Draw = {
  ctx: null, px: 1, sprites: new Map(), pages: {}, ATLAS: 2048, camM: null, FONT: '"Trebuchet MS", "Segoe UI", sans-serif',
  init(canvas) { this.ctx = this.ctx0 = canvas.getContext('2d'); this.canvas = canvas; },
  setPx(k) { if (k !== this.px) { this.px = k; this.sprites.clear(); for (const P of Object.values(this.pages)) { P.keys = []; P.x = P.y = P.h = 0; if (P.cv) P.cv.getContext('2d').clearRect(0, 0, this.ATLAS, this.ATLAS); } } },
  alloc(page, id, w, h) {
    const A = this.ATLAS, P = this.pages[page] || (this.pages[page] = { cv: null, x: 0, y: 0, h: 0, keys: [] });
    if (!P.cv) { P.cv = document.createElement('canvas'); P.cv.width = P.cv.height = A; }
    if (P.x + w > A) { P.x = 0; P.y += P.h + 2; P.h = 0; }
    if (P.y + h > A) { for (const k of P.keys) this.sprites.delete(k); P.keys = []; P.cv.getContext('2d').clearRect(0, 0, A, A); P.x = P.y = P.h = 0; }
    const S = { cv: P.cv, x: P.x, y: P.y, w, h }; P.x += w + 2; P.h = Math.max(P.h, h); P.keys.push(id); this.sprites.set(id, S); return S;
  },
  sprite(key, half, paint) {
    const k = this.px, id = key + '@' + k; let S = this.sprites.get(id); if (S) return S;
    const n = Math.ceil(half * 2 * k) + 2; S = this.alloc('spr', id, n, n); S.hw = S.hh = n / 2 / k;
    this.paintInto(S, n / 2, n / 2, paint); return S;
  },
  paintInto(S, ox, oy, paint) {
    const g = S.cv.getContext('2d'), was = this.ctx, k = this.px;
    g.save(); g.beginPath(); g.rect(S.x, S.y, S.w, S.h); g.clip(); g.translate(S.x + ox, S.y + oy); g.scale(k, k); g.lineCap = 'round';
    this.ctx = g; try { paint(g); } finally { this.ctx = was; g.restore(); }
  },
  images: new Map(), meta: new Map(),
  load(key, url, m) { const im = new Image(); im.onerror = () => console.error(`Draw.load: ${url} did not load (key ${key})`); im.src = url; this.images.set(key, im); if (m) this.meta.set(key, m); return im; },
  loaded(key) { const im = this.images.get(key); return !!(im && im.complete && im.naturalWidth); },
  loadManifest(url) {
    url = url || 'assets/sprites.json'; const dir = url.slice(0, url.lastIndexOf('/') + 1);
    return fetch(url, { cache: 'no-store' }).then(r => { if (r.status === 404) return null; if (!r.ok) throw new Error(url + ': HTTP ' + r.status); return r.json(); })
      .then(j => { let n = 0; for (const [k, m] of Object.entries((j && j.sprites) || {})) { this.load(k, dir + m.file, m); n++; } return n; })
      .catch(e => { console.error('Draw.loadManifest: ' + e.message); return 0; });
  },
  pic(key, x, y, w, h, a, frame) {
    const im = this.images.get(key); if (!im || !im.complete || !im.naturalWidth) return false;
    const m = this.meta.get(key), n = m && m.frames > 1 ? m.frames : 1, fw = im.naturalWidth / n, fh = im.naturalHeight, f = n > 1 ? ((frame | 0) % n + n) % n : 0;
    const c = this.ctx, sm = c.imageSmoothingEnabled; h = h || w * fh / fw; c.imageSmoothingEnabled = !!(m && m.smooth);
    if (!a) { c.drawImage(im, f * fw, 0, fw, fh, x - w / 2, y - h / 2, w, h); c.imageSmoothingEnabled = sm; return true; }
    const t = c.getTransform(); c.translate(x, y); c.rotate(a); c.drawImage(im, f * fw, 0, fw, fh, -w / 2, -h / 2, w, h); c.setTransform(t.a, t.b, t.c, t.d, t.e, t.f); c.imageSmoothingEnabled = sm; return true;
  },
  anim(key, t, x, y, w, h, a) { const m = this.meta.get(key); return this.pic(key, x, y, w, h, a, m ? Math.floor(t * (m.fps || 8)) : 0); },
  textSprite(s, size, color, align) {
    const k = this.px, id = 'T' + s + '|' + size + '|' + color + '|' + (align || 'left') + '@' + k; let S = this.sprites.get(id); if (S) return S;
    const c = this.ctx; c.font = `bold ${size}px ${this.FONT}`; const tw = c.measureText(s).width, pad = Math.max(3, size * 0.18) + 2;
    const w = Math.ceil((tw + pad * 2) * k), h = Math.ceil((size * 1.3 + pad * 2) * k); S = this.alloc('txt', id, w, h);
    const ax = align === 'center' ? tw / 2 + pad : align === 'right' ? tw + pad : pad; S.hw = ax; S.hh = h / 2 / k; S.ww = w / k; S.wh = h / k;
    this.paintInto(S, ax * k, h / 2, () => this.text(s, 0, 0, size, color, align)); return S;
  },
  textStamp(s, x, y, size, color, align) { const S = this.textSprite(s, size, color, align); this.ctx.drawImage(S.cv, S.x, S.y, S.w, S.h, x - S.hw, y - S.hh, S.ww, S.wh); },
  // put a sprite down at (x, y) turned by a. The transform is set outright from the camera's (camM, kept for the world pass)
  // and put back after: save()/restore() copy the whole context state and cost more than the stamp itself
  stamp(S, x, y, a) {
    const c = this.ctx, hw = S.hw, hh = S.hh; if (!a) { c.drawImage(S.cv, S.x, S.y, S.w, S.h, x - hw, y - hh, hw * 2, hh * 2); return; }
    const m = c === this.ctx0 && this.camM || c.getTransform(), co = Math.cos(a), si = Math.sin(a);
    c.setTransform(m.a * co + m.c * si, m.b * co + m.d * si, m.c * co - m.a * si, m.d * co - m.b * si, m.a * x + m.c * y + m.e, m.b * x + m.d * y + m.f);
    c.drawImage(S.cv, S.x, S.y, S.w, S.h, -hw, -hh, hw * 2, hh * 2); c.setTransform(m.a, m.b, m.c, m.d, m.e, m.f);
  },
  circle(x, y, r, fill, stroke, lw) { const c = this.ctx; c.beginPath(); c.arc(x, y, r, 0, TAU); if (fill) { c.fillStyle = fill; c.fill(); } if (stroke) { c.strokeStyle = stroke; c.lineWidth = lw || 2; c.stroke(); } },
  ring(x, y, r, color, lw) { const c = this.ctx; c.beginPath(); c.arc(x, y, r, 0, TAU); c.strokeStyle = color; c.lineWidth = lw || 2; c.stroke(); },
  roundRect(x, y, w, h, r, fill, stroke, lw) { const c = this.ctx; c.beginPath(); c.moveTo(x + r, y); c.arcTo(x + w, y, x + w, y + h, r); c.arcTo(x + w, y + h, x, y + h, r); c.arcTo(x, y + h, x, y, r); c.arcTo(x, y, x + w, y, r); c.closePath(); if (fill) { c.fillStyle = fill; c.fill(); } if (stroke) { c.strokeStyle = stroke; c.lineWidth = lw || 2; c.stroke(); } },
  // a soft radial glow (cheap: one gradient fill; do not draw hundreds a frame)
  glow(x, y, r, color, a) { const c = this.ctx, g = c.createRadialGradient(x, y, 0, x, y, r); g.addColorStop(0, U.tint(color, a == null ? 0.5 : a)); g.addColorStop(1, U.tint(color, 0)); c.fillStyle = g; c.fillRect(x - r, y - r, r * 2, r * 2); },
  text(s, x, y, size, color, align, weight) {
    const c = this.ctx; c.font = `${weight || 'bold'} ${size}px ${this.FONT}`; c.textAlign = align || 'left'; c.textBaseline = 'middle';
    c.lineWidth = Math.max(3, size * 0.18); c.strokeStyle = 'rgba(10,8,20,0.85)'; c.lineJoin = 'round'; c.strokeText(s, x, y); c.fillStyle = color || '#fff'; c.fillText(s, x, y);
  },
  ink(s, x, y, size, color, align, weight) { const c = this.ctx; c.font = `${weight || 'bold'} ${size}px ${this.FONT}`; c.textAlign = align || 'left'; c.textBaseline = 'middle'; c.fillStyle = color || '#fff'; c.fillText(s, x, y); },
  // a button: a rounded pill with a label; returns its rect so Input can test taps against it
  button(x, y, w, h, label, fill, ink, size) { this.roundRect(x - w / 2, y - h / 2, w, h, h / 2, fill || '#ff6600', 'rgba(0,0,0,0.35)', 2); this.ink(label, x, y + 1, size || Math.round(h * 0.42), ink || '#111', 'center'); return { x: x - w / 2, y: y - h / 2, w, h }; },
  // a bar (hp, timer): the track and the fill from 0..1
  bar(x, y, w, h, f, fill, track) { this.roundRect(x, y, w, h, h / 2, track || 'rgba(0,0,0,0.5)'); if (f > 0) this.roundRect(x, y, Math.max(h, w * U.clamp(f, 0, 1)), h, h / 2, fill || '#9bf06a'); },
};
