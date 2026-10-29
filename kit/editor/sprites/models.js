// 3D: the 3D cast tab of the sprite page (loaded after the page's own script; it uses its globals: el, esc, say, ASSET, apiUrl,
// apiHeaders, glbScene, glbThumb, showTab). The cast is assets/models.json (lib/Cast.php): one item per thing the game draws in 3D.
//   List the 3D things   one artist run reads the game's 3D code and writes the cast; items already there are kept
//   Make all missing     a model job per item with no model (one take each, one job at a time); each is kept as it lands
//   Wire into the game   one builder run hooks every item in through kit/js/kit-models.js; after that a kept model shows up by itself
//   per item             look at it turning, change what it is or its size, make it again, or revise it with one change
const Cast = {
  S: null, jobs: [], open: '', t: 0, viewT: 0,
  async refresh() {
    try {
      const [c, j] = await Promise.all([fetch(apiUrl('cast'), { cache: 'no-store' }), fetch(apiUrl('sketches'), { cache: 'no-store' })]);
      const cj = await c.json(); if (!c.ok) throw new Error(cj.error || c.status); this.S = cj; this.jobs = (await j.json()).jobs || [];
    } catch (e) { el('castSub').textContent = 'the cast could not be read: ' + e.message; return; }
    this.draw(); clearTimeout(this.t); if (this.busy()) this.t = setTimeout(() => { if (!el('tab-3d').classList.contains('hidden')) this.refresh(); }, 5000);
  },
  busy() { const S = this.S; if (!S) return false; return (S.list && S.list.busy) || Object.values(S.items).some(it => it.state === 'making') || this.jobs.some(j => j.kind === 'remodel' && !j.result); },
  items() { return Object.entries(this.S ? this.S.items : {}); },
  draw() {
    const S = this.S, all = this.items(), n = s => all.filter(([, it]) => it.state === s).length, missing = all.filter(([, it]) => it.state === 'none' || it.state === 'failed').length;
    el('castSub').textContent = !S.three ? 'This game has no three.js of its own (js/vendor/three.*.js): the cast is for games that draw in 3D.'
      : all.length ? `${all.length} things · ${n('kept')} with a model · ${n('making')} being made${S.working ? ` (${S.working} at a time)` : ''} · ${missing} still the stand-in · ${all.filter(([, it]) => it.hooked).length} hooked into the game` + (S.wired ? '' : ' · not wired in yet')
      : 'No cast yet: list the 3D things first.';
    const L = S.list; el('castList').textContent = L && L.busy ? 'Listing…' : all.length ? 'List again' : 'List the 3D things'; el('castList').disabled = !S.three || !!(L && L.busy);
    el('castMake').textContent = `Make all missing (${missing})`; el('castMake').disabled = !missing;
    const unhooked = all.filter(([, it]) => !it.hooked).length; el('castWire').textContent = S.wired && !unhooked ? 'Wired in' : `Wire into the game${unhooked ? ` (${unhooked})` : ''}`; el('castWire').disabled = !all.length || (S.wired && !unhooked);
    el('castNote').textContent = L && L.result && !L.result.ok ? 'the last listing failed: ' + (L.result.error || '') : '';
    const g = el('castGrid'); g.innerHTML = '';
    for (const grp of ['player', 'enemy', 'boss', 'npc', 'prop', 'pickup', 'terrain', 'other']) {
      const rows = all.filter(([, it]) => (it.group || 'other') === grp); if (!rows.length) continue;
      const h = document.createElement('h3'); h.textContent = grp; h.className = 'castgrp'; g.appendChild(h);
      for (const [k, it] of rows) {
        const d = document.createElement('button'); d.className = 'ccard' + (this.open === k ? ' on' : ''); d.type = 'button';
        d.innerHTML = `<img alt=""><div><div><b class="mono">${esc(k)}</b> <span class="pill s-${esc(it.state)}">${{ kept: 'model', made: 'made', making: 'making…', failed: 'failed', none: 'stand-in' }[it.state] || esc(it.state)}</span>${it.hooked ? ' <span class="pill s-hooked" title="the game asks Models.make for it">in game</span>' : ''}</div><div class="muted small">${esc(it.what || '')}</div><div class="muted small">${+it.size || '?'} units${it.error ? ' · ' + esc(it.error) : ''}</div></div>`;
        const img = d.querySelector('img'); if (it.file) glbThumb(img, ASSET(it.file) + '?v=' + (it.v || ''), 96); else img.style.visibility = 'hidden';
        d.onclick = () => { this.open = this.open === k ? '' : k; this.draw(); };
        g.appendChild(d); if (this.open === k) g.appendChild(this.detail(k, it));
      }
    }
  },
  detail(k, it) {
    const d = document.createElement('div'); d.className = 'cdetail'; const revs = this.jobs.filter(j => j.kind === 'remodel' && j.key === k).slice(0, 3);
    d.innerHTML = `<div class="cview"></div><div class="cform">
      <label>What it is <input data-f="what" value="${esc(it.what || '')}"></label>
      <label>For the modeller <textarea data-f="note" rows="3">${esc(it.note || '')}</textarea></label>
      <div class="row"><label>Size, world units <input data-f="size" type="number" step="any" min="0.01" value="${+it.size || 1}" style="width:90px"></label><button data-a="save">Save</button></div>
      <div class="muted small">Replaces: <span class="mono">${esc(it.replaces || '?')}</span>${it.moves ? ` · the game animates: ${esc(it.moves)}` : ''}${it.file ? ` · <span class="mono">assets/${esc(it.file)}</span>${it.recipe ? ', with its recipe' : ', no recipe kept (a revision remakes it)'}` : ''}</div>
      ${it.file ? `<label>Change one thing <textarea data-f="rev" rows="2" placeholder="e.g. bigger eyes, antennae curled forward"></textarea></label><div class="row"><button class="primary" data-a="revise">Revise &gt;&gt;</button></div>` : ''}
      <div class="crevs"></div>
      <div class="row"><button data-a="make">${it.file ? 'Make it again from scratch' : 'Make it'}</button><button data-a="drop" title="out of the cast; a kept model file stays in assets/">Drop from the cast</button></div></div>`;
    const v = d.querySelector('.cview'); if (it.file) this.view(v, ASSET(it.file) + '?v=' + (it.v || '')); else v.innerHTML = `<div class="muted small">No model yet: the game draws its stand-in.${it.state === 'making' ? ' The artist is making one.' : ''}</div>`;
    const R = d.querySelector('.crevs');
    for (const j of revs) {
      const r = document.createElement('div'); r.className = 'crev'; const ok = j.result && j.result.ok && (j.result.files || [])[0], kept = it.kept === j.id;
      r.innerHTML = `<div class="small"><b>Revision:</b> ${esc(j.note || '')}<div class="muted">${!j.result ? 'the artist is on it…' : !j.result.ok ? 'failed: ' + esc(j.result.error || '') : kept ? 'kept: this is the model now' : esc(j.result.note || '')}</div></div>`;
      if (ok && !kept) { const im = document.createElement('img'); glbThumb(im, j.base + ok, 96); im.title = 'see it turning'; im.style.cursor = 'pointer'; im.onclick = () => this.view(v, j.base + ok); r.prepend(im);
        const b = document.createElement('div'); b.className = 'row'; b.innerHTML = '<button class="primary">Keep it</button><button>Discard</button>'; const [kb, db] = b.querySelectorAll('button');
        kb.onclick = () => this.post(apiUrl('spritesave'), { key: k, take: { id: j.id, file: ok }, size: +it.size || 1 }, `${k}: the revision is the model now; the game loads it next time it starts`);
        db.onclick = () => this.post(apiUrl('sketchdelete'), { id: j.id }, 'revision discarded'); r.appendChild(b); }
      R.appendChild(r);
    }
    const f = n => d.querySelector(`[data-f="${n}"]`);
    d.querySelector('[data-a="save"]').onclick = () => this.act({ do: 'edit', key: k, fields: { what: f('what').value, note: f('note').value, size: +f('size').value } }, 'saved');
    d.querySelector('[data-a="make"]').onclick = () => { if (it.file && !confirm(`Make ${k} again from scratch? When it is done it replaces the model the game has now.`)) return; this.act({ do: 'make', keys: [k] }, `the artist is making ${k}`); };
    d.querySelector('[data-a="drop"]').onclick = () => { if (confirm(`Drop ${k} from the cast? The game keeps its stand-in for it.`)) { this.open = ''; this.act({ do: 'edit', key: k, drop: true }, `${k} is out of the cast`); } };
    const rv = d.querySelector('[data-a="revise"]'); if (rv) rv.onclick = () => { const t = f('rev').value.trim(); if (!t) { say('say what to change', 'err'); return; } this.act({ do: 'revise', key: k, note: t }, `the artist is revising ${k}; the revision appears here to keep or discard`); };
    return d;
  },
  async view(box, url) { // the model turning, drag to turn it
    box.innerHTML = ''; clearInterval(this.viewT); try { const s = await glbScene(url), cv = document.createElement('canvas'); cv.width = cv.height = 300; box.appendChild(cv); const r = new s.T.WebGLRenderer({ canvas: cv, alpha: true, antialias: true });
      let a = 0.7, drag = null; cv.addEventListener('pointerdown', e => { drag = e.clientX; cv.setPointerCapture(e.pointerId); }); cv.addEventListener('pointermove', e => { if (drag !== null) { a += (e.clientX - drag) / 120; drag = e.clientX; } }); cv.addEventListener('pointerup', () => { drag = null; });
      this.viewT = setInterval(() => { if (!cv.isConnected) { clearInterval(this.viewT); r.dispose(); return; } if (drag === null) a += 0.01; s.aim(a); r.render(s.scene, s.cam); }, 33); }
    catch (e) { box.textContent = 'it could not be shown: ' + e.message; }
  },
  async act(body, ok) { return this.post(apiUrl('cast'), body, ok); },
  async post(url, body, ok) {
    try { const r = await fetch(url, { method: 'POST', headers: apiHeaders(), body: JSON.stringify(body) }); const j = await r.json(); if (!r.ok) throw new Error(j.error || r.status); say(typeof ok === 'function' ? ok(j) : ok, 'ok'); }
    catch (e) { say(e.message, 'err'); }
    this.refresh();
  },
};
el('castList').onclick = () => Cast.act({ do: 'list' }, j => j.queued ? 'another sprite job is running: listing the cast is next' : 'the artist is reading the game for its 3D things (a few minutes); the list appears here');
el('castMake').onclick = () => { const n = Cast.items().filter(([, it]) => it.state === 'none' || it.state === 'failed').length;
  const k = Cast.S.slots || 1; if (!confirm(`Make ${n} model(s)? The artist makes ${k} at a time, about 2 to 3 minutes each (about ${Math.ceil(n / k) * 2.5} minutes in all), each a run of the forge's Claude. Each is kept as it lands; revise any of them afterwards.`)) return;
  Cast.act({ do: 'make' }, j => `${(j.started || []).length} model job(s) started; they are made one after another`); };
el('castWire').onclick = () => { if (!confirm('Ask the builder to hook every thing in the cast into the game (one run)? After that, a model kept or revised here shows up in the game by itself.')) return;
  Cast.act({ do: 'wire' }, j => j.queued ? 'the builder is busy: wiring the cast is its next run' : 'the builder is wiring the cast in; watch it on the game page\'s Build tab'); };
if (location.hash === '#3d') Cast.refresh(); // the page opened on this tab before this file loaded
