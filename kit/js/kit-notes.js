// VoidLux kit: the producer's notes. Alt+P (or the forge's Note button) pauses the game and lets you point at anything on
// the screen: hover highlights the nearest thing the game knows about, a click opens a small dialog anchored to it, and
// the note (what you clicked, where, in which scene, a screenshot crop, your words) goes to the forge, which keeps them
// until you send a batch to the builder. Self-contained DOM: works for games on the kit (Game/Draw/Input) and for games
// sprung from Centipede (G/Render). Loads last. Without a forge around (not in its iframe) notes queue in localStorage
// and are handed over the next time the game opens inside the forge.
//
//   What is "the thing I clicked"? Notes.describe(x, y) looks for objects with a numeric x and y near the point, in the
//   game's own state: arrays and singletons one or two levels under the usual roots (World, Game.scene, G, Enemies,
//   Player, Combat ...), in screen space and in world space (the point plus the camera). A game may give a better answer
//   with Game.describe = (x, y) => [{ label, fields }] (or G.describe for Centipede-style games).
const Notes = {
  known: [], open: false, picking: false, hover: null, picked: null, el: null, dlg: null, canvas: null, KEY: 'voidlux.notes.queue', slug: '',
  forge() { try { if (window.parent !== window) return window.parent; } catch (e) {} try { if (window.opener && !window.opener.closed) return window.opener; } catch (e) {} return null; },
  init() {
    this.canvas = document.getElementById('game'); if (!this.canvas) return;
    this.slug = (location.pathname.match(/\/games\/([a-z0-9-]+)\//) || [])[1] || (typeof Game !== 'undefined' ? Game.id : 'game');
    window.addEventListener('keydown', e => { if (e.altKey && e.code === 'KeyP') { e.preventDefault(); this.toggle(); } else if (e.code === 'Escape' && this.open) { e.preventDefault(); this.close(); } }, true);
    window.addEventListener('message', e => { const m = e.data || {}; if (m.type === 'forge:notes') { if (m.on) this.start(); else this.close(); } else if (m.type === 'forge:notes-list') { this.known = Array.isArray(m.notes) ? m.notes : []; if (this.open) this.pins(); } });
    this.canvas.addEventListener('touchstart', e => { if (e.touches.length === 3) { e.preventDefault(); this.toggle(); } else if (this.open && this.picking && e.touches.length === 1) { e.preventDefault(); const t = e.touches[0]; this._tp = { x: t.clientX, y: t.clientY }; } }, { passive: false });
    this.canvas.addEventListener('touchend', e => { if (this.open && this.picking && this._tp) { e.preventDefault(); const p = this.logical(this._tp.x, this._tp.y); this._tp = null; this.hover = this.describe(p.x, p.y); this.pick(p); } }, { passive: false });
    this.style(); this.flushQueue();
  },
  // ---- pause: a kit game has Game.paused; a Centipede-style game gets its G.update wrapped ----
  pause(on) {
    if (typeof Game !== 'undefined' && Game.scenes) { Game.paused = on; if (typeof Input !== 'undefined') { Input.keys = {}; Input.joy = null; } return; }
    if (typeof G !== 'undefined' && typeof G.update === 'function') { if (!this._u) { this._u = G.update.bind(G); G.update = dt => { if (!this.open) this._u(dt); }; } }
  },
  scene() { return typeof Game !== 'undefined' && Game.state ? Game.state : typeof G !== 'undefined' ? (G.state + (G.state === 'playing' && G.mode ? ':' + G.mode : '')) : '?'; },
  cam() { const c = (typeof Game !== 'undefined' && Game.cam) || (typeof G !== 'undefined' && G.cam) || { x: 0, y: 0 }; return { x: c.x || 0, y: c.y || 0 }; },
  logical(cx, cy) { const r = this.canvas.getBoundingClientRect(); return { x: (cx - r.left) / r.width * this.canvas.width, y: (cy - r.top) / r.height * this.canvas.height }; },
  css(x, y) { const r = this.canvas.getBoundingClientRect(); return { x: r.left + x / this.canvas.width * r.width, y: r.top + y / this.canvas.height * r.height }; },
  toggle() { if (this.open) this.close(); else this.start(); },
  start() {
    if (this.open) return; this.open = true; this.picking = true; this.pause(true);
    const el = this.el = document.createElement('div'); el.className = 'vl-notes'; el.innerHTML = '<div class="vl-notes-bar"><b>NOTE MODE</b> paused · ' + ('ontouchstart' in window ? 'tap anything on the screen · three-finger tap resumes' : 'click anything on the screen · <kbd>Esc</kbd> resumes') + '</div>'<svg class="vl-notes-wire" width="100%" height="100%"><line x1="0" y1="0" x2="0" y2="0"></line><circle cx="0" cy="0" r="4"></circle></svg><div class="vl-notes-ring"></div>';
    try { const f = this.forge(); if (f) f.postMessage({ type: 'forge:notes-open' }, '*'); } catch (e) {} // the forge scrolls the game into view, sends the notes so far
    document.body.appendChild(el);
    this._move = e => { if (!this.picking) return; const p = this.logical(e.clientX, e.clientY); this.hover = this.describe(p.x, p.y); this.ring(this.hover ? this.hover.at : p, !!this.hover); };
    this._click = e => { if (!this.picking) return; const t = e.target; if (this.dlg && this.dlg.contains(t)) return; if (!this.canvas.contains(t) && t !== this.el && !t.classList.contains('vl-notes-ring') && !t.closest('.vl-notes-pin')) return; /* a pin is a pick too: it sits on the thing it is about */ e.preventDefault(); e.stopPropagation(); const p = this.logical(e.clientX, e.clientY); this.pick(p); };
    window.addEventListener('mousemove', this._move, true); window.addEventListener('click', this._click, true);
    document.body.classList.add('vl-notes-on'); this.pins();
  },
  // the notes already on this scene, as pins where they were made: a teammate sees what is spoken for before adding to it
  pins() {
    if (!this.el) return; for (const old of this.el.querySelectorAll('.vl-notes-pin')) old.remove();
    const scene = this.scene(), s = this.canvas.getBoundingClientRect().width / this.canvas.width;
    for (const n of this.known.filter(n => n.scene === scene)) { const c = this.css(n.x, n.y), pin = document.createElement('div'); pin.className = 'vl-notes-pin ' + (n.state || 'open'); pin.style.left = (c.x - 9) + 'px'; pin.style.top = (c.y - 9) + 'px'; pin.title = (n.by ? n.by + ': ' : '') + n.text; pin.innerHTML = '<span>' + this.esc((n.by || '?').slice(0, 1).toUpperCase()) + '</span>'; this.el.appendChild(pin); }
  },
  // notes about the same thing: the same object by label (ignoring an array index, which changes between runs) and its
  // naming fields (kind, name, type, id), or, with nothing named, within 60 px in the same scene
  about(what, p) {
    const scene = this.scene(), base = what && what.label ? what.label.replace(/\[\d+\]|#\d+/g, '') : '', f = what && what.fields || {};
    const sig = o => ['kind', 'name', 'type', 'id'].map(k => o && o[k] !== undefined ? k + '=' + o[k] : '').join('|');
    return this.known.filter(n => n.scene === scene && (base && n.what && n.what.label && n.what.label.replace(/\[\d+\]|#\d+/g, '') === base && sig(n.what.fields) === sig(f) || (!base && Math.hypot(n.x - p.x, n.y - p.y) < 60)));
  },
  close() {
    if (!this.open) return; this.open = false; this.picking = false; this.pause(false);
    window.removeEventListener('mousemove', this._move, true); window.removeEventListener('click', this._click, true);
    if (this.dlg) { this.dlg.remove(); this.dlg = null; } if (this._place) window.removeEventListener('resize', this._place); if (this.el) { this.el.remove(); this.el = null; }
    document.body.classList.remove('vl-notes-on'); this.picked = null; this.hover = null;
    try { const f = this.forge(); if (f) f.postMessage({ type: 'forge:notes-closed' }, '*'); } catch (e) {}
  },
  ring(p, hit) { const r = this.el && this.el.querySelector('.vl-notes-ring'); if (!r) return; const c = this.css(p.x, p.y), s = this.canvas.getBoundingClientRect().width / this.canvas.width; const rad = (hit && this.hover && this.hover.r ? this.hover.r + 10 : 22) * s; r.style.cssText = `left:${c.x - rad}px;top:${c.y - rad}px;width:${rad * 2}px;height:${rad * 2}px;opacity:1;border-color:${hit ? '#ffcc00' : 'rgba(255,255,255,0.5)'}`; },
  // ---- what is at (x, y): the game's own describe hook, else the scan ----
  describe(x, y) {
    const hook = (typeof Game !== 'undefined' && Game.describe) || (typeof G !== 'undefined' && G.describe);
    if (hook) { try { const r = hook(x, y); if (r && r.length) return Object.assign({ at: { x, y } }, r[0]); } catch (e) {} }
    return this.scan(x, y);
  },
  // a game's systems are top-level `const`s of classic scripts: in the global lexical scope, not on window, so by name
  global(name) { try { return new Function('try { return ' + name + '; } catch (e) { return undefined; }')(); } catch (e) { return undefined; } },
  ROOTS: ['World', 'Enemies', 'Player', 'Combat', 'Minions', 'Deck', 'Dungeon', 'Game', 'G'],
  scan(x, y) {
    const cam = this.cam(), pts = [{ x, y, space: 'screen' }, { x: x + cam.x, y: y + cam.y, space: 'world' }];
    let best = null;
    const consider = (o, label, p) => {
      if (!o || typeof o !== 'object' || typeof o.x !== 'number' || typeof o.y !== 'number') return;
      const r = typeof o.r === 'number' ? o.r : typeof o.size === 'number' ? o.size / 2 : 16, d = Math.hypot(o.x - p.x, o.y - p.y) - Math.min(r, 60);
      if (d < 40 && (!best || d < best.d)) best = { d, label, obj: o, r, space: p.space, at: p.space === 'world' ? { x: o.x - cam.x, y: o.y - cam.y } : { x: o.x, y: o.y } };
    };
    for (const name of this.ROOTS) {
      const root = this.global(name); if (!root || typeof root !== 'object') continue;
      for (const p of pts) {
        consider(root, name, p);
        for (const k of Object.keys(root)) {
          const v = root[k]; if (!v || typeof v !== 'object' || k.startsWith('_')) continue;
          if (Array.isArray(v)) { for (let i = 0; i < v.length && i < 2000; i++) consider(v[i], `${name}.${k}[${i}]`, p); }
          else if (v instanceof Map) { let i = 0; for (const it of v.values()) consider(it, `${name}.${k}#${i++}`, p); }
          else { consider(v, `${name}.${k}`, p); if (v.live && Array.isArray(v.live)) for (let i = 0; i < v.live.length; i++) consider(v.live[i], `${name}.${k}.live[${i}]`, p); for (const k2 of Object.keys(v)) { const w = v[k2]; if (Array.isArray(w) && w.length && w.length < 2000) for (let i = 0; i < w.length; i++) consider(w[i], `${name}.${k}.${k2}[${i}]`, p); } }
        }
      }
    }
    if (!best) return null;
    const fields = {}; for (const [k, v] of Object.entries(best.obj)) { if (['string', 'number', 'boolean'].includes(typeof v) && Object.keys(fields).length < 14) fields[k] = typeof v === 'number' ? Math.round(v * 100) / 100 : v; }
    return { label: best.label, fields, r: best.r, at: best.at, space: best.space };
  },
  crop(p) { try { const w = 240, h = 160, c = document.createElement('canvas'); c.width = w; c.height = h; const g = c.getContext('2d'); g.fillStyle = '#000'; g.fillRect(0, 0, w, h); g.drawImage(this.canvas, Math.max(0, p.x - w / 2), Math.max(0, p.y - h / 2), w, h, 0, 0, w, h); g.strokeStyle = '#ffcc00'; g.lineWidth = 2; g.beginPath(); g.arc(Math.min(w / 2, p.x), Math.min(h / 2, p.y), 14, 0, Math.PI * 2); g.stroke(); return c.toDataURL('image/jpeg', 0.7); } catch (e) { return ''; } },
  pick(p) {
    const what = this.describe(p.x, p.y); this.picked = { x: Math.round(p.x), y: Math.round(p.y), scene: this.scene(), what, shot: this.crop(what ? what.at : p), t: Date.now() };
    this.picking = false; this.ring(what ? what.at : p, !!what);
    if (this.dlg) this.dlg.remove();
    const d = this.dlg = document.createElement('div'); d.className = 'vl-notes-dlg';
    const where = what ? `<b>${this.esc(what.label)}</b> ${this.esc(JSON.stringify(what.fields || {}))}` : `nothing the game names here: the spot <b>(${this.picked.x}, ${this.picked.y})</b>`;
    const prior = this.about(what, p), priorHtml = prior.length ? `<div class="vl-notes-prior"><b>already noted here</b>${prior.slice(0, 4).map(n => `<div data-note="${n.id}"><i>${this.esc(n.by || 'someone')}</i> · ${this.esc(n.state === 'sent' ? 'sent to build' : 'awaiting verdict')}${n.votes ? ' · ▲' + n.votes : ''}: ${this.esc(n.text)}${n.canManage ? ` <button type="button" data-act="scrap" data-note="${n.id}" title="to the scrapyard">scrap</button><button type="button" data-act="delete" data-note="${n.id}" title="gone for good">delete</button>` : ''}</div>`).join('')}${prior.length > 4 ? `<div>…and ${prior.length - 4} more</div>` : ''}</div>` : '';
    d.innerHTML = `<div class="vl-notes-what">${where}<div class="vl-notes-meta">scene ${this.esc(this.picked.scene)} · (${this.picked.x}, ${this.picked.y})</div></div>${priorHtml}<img class="vl-notes-shot" src="${this.picked.shot}" alt=""><textarea placeholder="What about it? 'too fast', 'make this gold and bigger', 'this should drop a shield'…"></textarea><div class="vl-notes-btns"><button data-act="note">Save note</button><button data-act="build" class="hot">Send to build</button><button data-act="again">Pick again</button><button data-act="close">Resume</button></div>`;
    document.body.appendChild(d); this.place(what ? what.at : p); this._place = () => { if (this.picked) this.place(what ? what.at : p); }; window.addEventListener('resize', this._place); const ta = d.querySelector('textarea'); setTimeout(() => ta.focus(), 50);
    d.addEventListener('click', e => { const b = e.target.closest('button'); if (!b) return; e.stopPropagation(); const act = b.dataset.act;
      if (act === 'scrap' || act === 'delete') { if (act === 'delete' && !confirm('Delete this note for good?')) return; try { const f = this.forge(); if (f) f.postMessage({ type: 'forge:note-action', id: +b.dataset.note, action: act }, '*'); } catch (err) {} const row = b.closest('[data-note]'); if (row) row.remove(); this.known = this.known.filter(n => n.id !== +b.dataset.note); this.pins(); this.toast(act === 'delete' ? 'note deleted' : 'note scrapped'); return; } if (act === 'close') this.close(); else if (act === 'again') { d.remove(); this.dlg = null; this.picking = true; this.wireOff(); } else this.send(act === 'build', ta.value.trim()); });
    ta.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); this.send(true, ta.value.trim()); } e.stopPropagation(); });
  },
  // the dialog sits in the middle of whichever half of the screen the pick is NOT in (never over it), vertically centred,
  // and a wire runs from its nearest edge to the ring, so it is always on screen and always says what it is about
  place(p) {
    const d = this.dlg; if (!d) return;
    if (window.innerWidth < 640) { d.classList.add('sheet'); d.style.left = ''; d.style.top = ''; this.wireOff(); return; } d.classList.remove('sheet'); const c = this.css(p.x, p.y), vw = window.innerWidth, vh = window.innerHeight, w = Math.min(330, vw - 16), h = d.offsetHeight || 360;
    const right = c.x < vw / 2; const left = right ? Math.min(vw - w - 8, Math.max(vw / 2 + 24, vw * 0.75 - w / 2)) : Math.max(8, Math.min(vw / 2 - w - 24, vw * 0.25 - w / 2));
    const top = Math.max(8, Math.min(vh - h - 8, vh / 2 - h / 2)); d.style.left = left + 'px'; d.style.top = top + 'px'; d.classList.toggle('from-left', !right);
    const svg = this.el && this.el.querySelector('.vl-notes-wire'); if (!svg) return;
    const x1 = right ? left : left + w, y1 = top + Math.min(h / 2, 70), line = svg.querySelector('line'), dot = svg.querySelector('circle');
    line.setAttribute('x1', x1); line.setAttribute('y1', y1); line.setAttribute('x2', c.x); line.setAttribute('y2', c.y); dot.setAttribute('cx', c.x); dot.setAttribute('cy', c.y); svg.style.opacity = 1;
  },
  send(build, text) {
    if (!text) { const ta = this.dlg && this.dlg.querySelector('textarea'); if (ta) { ta.placeholder = 'Say something about it first.'; ta.focus(); } return; }
    const note = Object.assign({ slug: this.slug, text, build }, this.picked);
    let delivered = false;
    try { const f = this.forge(); if (f) { f.postMessage({ type: 'forge:note', note }, '*'); delivered = true; } } catch (e) {}
    if (!delivered) { try { const q = JSON.parse(localStorage.getItem(this.KEY) || '[]'); q.push(note); localStorage.setItem(this.KEY, JSON.stringify(q.slice(-50))); } catch (e) {} }
    this.toast(delivered ? (build ? 'sent to the build' : 'note saved') : 'note queued: open the game from the forge to deliver it');
    this.dlg.remove(); this.dlg = null; this.picking = true; this.picked = null; this.wireOff();
  },
  wireOff() { const svg = this.el && this.el.querySelector('.vl-notes-wire'); if (svg) svg.style.opacity = 0; },
  flushQueue() { try { const q = JSON.parse(localStorage.getItem(this.KEY) || '[]'), f = this.forge(); if (q.length && f) { for (const n of q) f.postMessage({ type: 'forge:note', note: n }, '*'); localStorage.removeItem(this.KEY); } } catch (e) {} },
  toast(s) { const t = document.createElement('div'); t.className = 'vl-notes-toast'; t.textContent = s; document.body.appendChild(t); setTimeout(() => t.remove(), 2200); },
  esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch])); },
  style() {
    const s = document.createElement('style'); s.textContent = `
      .vl-notes { position: fixed; inset: 0; pointer-events: none; z-index: 9000; font: 13px/1.4 "IBM Plex Mono", "Courier New", monospace; }
      .vl-notes-bar { position: absolute; top: 10px; left: 50%; transform: translateX(-50%); background: rgba(6,6,8,0.9); color: #e8e8e8; border: 1px solid #ff6600; border-radius: 999px; padding: 6px 14px; letter-spacing: 1px; } .vl-notes-bar b { color: #ffcc00; } .vl-notes-bar kbd { background: #222; padding: 0 5px; border-radius: 3px; }
      .vl-notes-ring { position: fixed; border: 2px solid #ffcc00; border-radius: 50%; opacity: 0; box-shadow: 0 0 12px rgba(255,204,0,0.6); pointer-events: none; transition: left .05s, top .05s; }
      body.vl-notes-on canvas { cursor: crosshair !important; }
      .vl-notes-dlg { position: fixed; z-index: 9001; width: 330px; background: #0d0d10; color: #e8e8e8; border: 1px solid #ff6600; border-radius: 8px; padding: 10px; box-shadow: 0 20px 50px rgba(0,0,0,0.7); font: 13px/1.4 "IBM Plex Mono", "Courier New", monospace; }
      .vl-notes-wire { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; transition: opacity .15s; } .vl-notes-wire line { stroke: #ffcc00; stroke-width: 2; stroke-dasharray: 6 4; filter: drop-shadow(0 0 4px rgba(255,204,0,0.7)); } .vl-notes-wire circle { fill: #ffcc00; }
      .vl-notes-what { font-size: 12px; color: #ccc; word-break: break-word; max-height: 5.2em; overflow: hidden; } .vl-notes-what b { color: #ffcc00; } .vl-notes-meta { color: #777; font-size: 11px; margin-top: 2px; }
      .vl-notes-shot { display: block; width: 100%; height: 130px; object-fit: cover; margin: 8px 0; border: 1px solid #333; border-radius: 4px; }
      .vl-notes-dlg textarea { width: 100%; min-height: 64px; background: #1a1a1a; color: #fff; border: 1px solid #333; border-radius: 4px; padding: 6px 8px; font: inherit; resize: vertical; } .vl-notes-dlg textarea:focus { outline: none; border-color: #ff6600; }
      .vl-notes-btns { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; } .vl-notes-btns button { font: inherit; font-size: 12px; background: #1a1a1a; color: #ddd; border: 1px solid #444; border-radius: 4px; padding: 5px 10px; cursor: pointer; } .vl-notes-btns button:hover { border-color: #ff6600; color: #fff; } .vl-notes-btns button.hot { background: #ff6600; color: #111; border-color: #ff6600; font-weight: bold; }
      .vl-notes-pin { position: fixed; width: 18px; height: 18px; border-radius: 50% 50% 50% 0; transform: rotate(-45deg); background: #ffcc00; border: 2px solid #111; box-shadow: 0 0 8px rgba(255,204,0,0.7); pointer-events: auto; z-index: 9000; cursor: help; } .vl-notes-pin.sent { background: #7df9ff; } .vl-notes-pin span { display: block; transform: rotate(45deg); font: bold 10px/14px monospace; color: #111; text-align: center; }
      .vl-notes-prior button { font: inherit; font-size: 10px; background: #1a1a1a; color: #bbb; border: 1px solid #444; border-radius: 3px; padding: 0 5px; margin-left: 4px; cursor: pointer; } .vl-notes-prior button:hover { border-color: #ff4d6d; color: #fff; }
      .vl-notes-prior { margin-top: 8px; padding: 6px 8px; background: #17171a; border: 1px solid #333; border-radius: 4px; font-size: 11.5px; color: #ccc; max-height: 7em; overflow: auto; } .vl-notes-prior b { color: #ffcc00; display: block; margin-bottom: 2px; } .vl-notes-prior i { color: #7df9ff; font-style: normal; }
      @media (max-width: 640px) { .vl-notes-dlg.sheet { left: 6px !important; right: 6px; width: auto !important; top: auto !important; bottom: 6px; max-height: 70vh; overflow: auto; } .vl-notes-dlg.sheet .vl-notes-shot { height: 90px; } .vl-notes-bar { font-size: 11px; padding: 5px 10px; white-space: nowrap; max-width: 96vw; overflow: hidden; text-overflow: ellipsis; } }
      .vl-notes-toast { position: fixed; bottom: 18px; left: 50%; transform: translateX(-50%); z-index: 9002; background: #111; color: #ffcc00; border: 1px solid #333; border-radius: 999px; padding: 6px 14px; font: 13px "IBM Plex Mono", monospace; }`;
    document.head.appendChild(s);
  },
};
window.addEventListener('DOMContentLoaded', () => Notes.init());
if (document.readyState !== 'loading') Notes.init();
