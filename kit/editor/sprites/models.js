// 3D: the 3D cast tab of the sprite page (loaded after the page's own script; it uses its globals: el, esc, say, ASSET, apiUrl,
// apiHeaders, glbScene, glbThumb, showTab). The cast is assets/models.json (lib/Cast.php): one item per thing the game draws in 3D.
//   List the 3D things   one artist run reads the game's 3D code and writes the cast; items already there are kept
//   Make all missing     a model job per item with no model (one take each, one job at a time); each is kept as it lands
//   Wire into the game   one builder run hooks every item in through kit/js/kit-models.js; after that a kept model shows up by itself
//   per item             look at it turning, change what it is or its size, make it again, or revise it with one change
// a message where the person is looking (the page's own line is at the top, out of sight when they are down at a model)
function toast(t, cls) { let b = document.getElementById('toast'); if (!b) { b = document.createElement('div'); b.id = 'toast'; b.setAttribute('role', 'status'); document.body.appendChild(b); }
  b.textContent = t; b.className = 'on ' + (cls || ''); clearTimeout(toast.t); toast.t = setTimeout(() => b.className = '', cls === 'err' ? 9000 : 6000); }
const CAST_OK = ['castGrid', 'castHist', 'castReqs', 'castWaiting', 'castAsk', 'castAskBtn', 'castList', 'castMake', 'castWire', 'castSub', 'castNote'].every(id => el(id));
if (!CAST_OK) { el('fresh').classList.remove('hidden'); el('freshWhy').textContent = '(the 3D tab is newer than this copy of the page)'; }
const Cast = {
  S: null, jobs: [], open: '', focus: '', t: 0, viewT: 0,
  async refresh() {
    if (!CAST_OK) return;
    try {
      const [c, j] = await Promise.all([fetch(apiUrl('cast'), { cache: 'no-store' }), fetch(apiUrl('sketches'), { cache: 'no-store' })]);
      const cj = await c.json(); if (!c.ok) throw new Error(cj.error || c.status); this.S = cj; this.jobs = (await j.json()).jobs || [];
    } catch (e) { el('castSub').textContent = 'the cast could not be read: ' + e.message; return; }
    this.draw(); clearTimeout(this.t); if (this.busy()) this.t = setTimeout(() => { if (!el('tab-3d').classList.contains('hidden')) this.refresh(); }, 5000);
  },
  busy() { const S = this.S; if (!S) return false; return (S.list && S.list.busy) || (S.requests || []).some(q => q.state === 'reading') || Object.values(S.items).some(it => it.state === 'making') || this.jobs.some(j => j.kind === 'remodel' && !j.result); },
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
    this.drawRequests(); this.drawHistory();
    const W = S.waiting || [], wb = el('castWaiting'); wb.classList.toggle('hidden', !W.length);
    if (W.length) { wb.innerHTML = `<div><b>${W.length} revision${W.length > 1 ? 's' : ''} waiting</b> <span class="muted small">${W.map(esc).join(', ')}: open one below to compare it, or use them all.</span></div><div class="row"><button class="primary">Use all ${W.length}</button></div>`;
      wb.querySelector('button').onclick = () => { if (confirm(`Use all ${W.length} revisions? Each replaces the model the game has now; the old ones stay under Earlier versions on each model.`)) this.act({ do: 'keepall' }, j => `${(j.kept || []).length} revision(s) kept; the game loads them next time it starts`); }; }
    const g = el('castGrid'); g.innerHTML = '';
    for (const grp of ['player', 'enemy', 'boss', 'npc', 'prop', 'pickup', 'terrain', 'other']) {
      const rows = all.filter(([, it]) => (it.group || 'other') === grp); if (!rows.length) continue;
      const h = document.createElement('h3'); h.textContent = grp; h.className = 'castgrp'; g.appendChild(h);
      for (const [k, it] of rows) {
        const d = document.createElement('button'); d.className = 'ccard' + (this.open === k ? ' on' : ''); d.type = 'button';
        d.innerHTML = `<img alt=""><div><div><b class="mono">${esc(k)}</b> ${W.includes(k) ? '<span class="pill s-made">revision ready</span> ' : ''}<span class="pill s-${esc(it.state)}">${{ kept: 'model', made: 'made', making: 'making…', failed: 'failed', none: 'stand-in' }[it.state] || esc(it.state)}</span>${it.hooked ? ' <span class="pill s-hooked" title="the game asks Models.make for it">in game</span>' : ''}</div><div class="muted small">${esc(it.what || '')}</div><div class="muted small">${+it.size || '?'} units${it.error ? ' · ' + esc(it.error) : ''}</div></div>`;
        const img = d.querySelector('img'); if (it.file) glbThumb(img, ASSET(it.file) + '?v=' + (it.v || ''), 96); else img.style.visibility = 'hidden';
        d.onclick = () => { this.open = this.open === k ? '' : k; this.focus = ''; this.draw(); };
        g.appendChild(d); if (this.open === k) g.appendChild(this.detail(k, it));
      }
    }
  },
  drawHistory() { // every revision, newest first: what was asked, where it stands, and a link to it on its model
    const box = el('castHist'), revs = this.jobs.filter(j => j.kind === 'remodel'), items = this.S.items; box.classList.toggle('hidden', !revs.length); if (!revs.length) return;
    const ago = t => { const s = Date.now() / 1000 - t; return s < 90 ? 'just now' : s < 5400 ? Math.round(s / 60) + ' min ago' : s < 129600 ? Math.round(s / 3600) + ' h ago' : Math.round(s / 86400) + ' d ago'; };
    const state = j => !j.result ? ['making…', 's-making'] : !j.result.ok ? ['failed', 's-failed'] : items[j.key] && items[j.key].kept === j.id ? ['in use', 's-kept'] : items[j.key] && (items[j.key].history || []).some(e => e.job === j.id) ? ['used before', ''] : (this.S.waiting || []).includes(j.key) && this.jobs.find(x => x.kind === 'remodel' && x.key === j.key) === j ? ['ready to keep', 's-made'] : ['not kept', ''];
    const shown = this.histAll ? revs : revs.slice(0, 8);
    box.innerHTML = `<div class="row"><b>Revision history</b><span class="muted small">${revs.length} revision${revs.length > 1 ? 's' : ''}; click one to see it on its model</span></div><div class="hlist"></div>` + (revs.length > 8 ? `<button class="linkish">${this.histAll ? 'show fewer' : `show all ${revs.length}`}</button>` : '');
    const L = box.querySelector('.hlist');
    for (const j of shown) { const [st, cls] = state(j), a = document.createElement('button'); a.type = 'button'; a.className = 'hrow' + (this.focus === j.id ? ' on' : '');
      a.innerHTML = `<img alt=""><span class="mono">${esc(j.key)}</span><span class="small">${esc((j.note || '').slice(0, 90))}</span><span class="pill ${cls}">${st}</span><span class="muted small">${ago(j.at)}</span>`;
      const f = j.result && j.result.ok && (j.result.files || [])[0]; if (f) glbThumb(a.querySelector('img'), j.base + f, 48); else a.querySelector('img').style.visibility = 'hidden';
      a.onclick = () => { this.open = j.key; this.focus = j.id; this.draw(); const c = document.querySelector('.cdetail'); if (c) c.scrollIntoView({ behavior: 'smooth', block: 'center' }); };
      L.appendChild(a); }
    const more = box.querySelector('.linkish'); if (more) more.onclick = () => { this.histAll = !this.histAll; this.drawHistory(); };
  },
  drawRequests() { // the plain-words requests: the artist's reading of each, to start or cancel
    const box = el('castReqs'); box.innerHTML = '';
    for (const q of (this.S.requests || []).filter(q => ['reading', 'planned', 'failed'].includes(q.state) || (q.state === 'started' && Date.now() / 1000 - q.at < 3600))) {
      const d = document.createElement('div'); d.className = 'creq'; const ch = q.changes || [];
      d.innerHTML = `<div><b>“${esc(q.text)}”</b></div>` + (q.state === 'reading' ? '<div class="muted small">the artist is working out which models this means…</div>'
        : q.state === 'failed' ? `<div class="small">not worked out: ${esc(q.error || '')}</div><div class="row"><button data-a="cancel">Dismiss</button></div>`
        : q.state === 'started' ? `<div class="muted small">started: ${(q.started || []).length} revision(s) (${esc((q.started || []).join(', '))}); each appears on its model below to keep or discard</div>`
        : `<div class="small">${ch.length ? `${ch.length} model${ch.length > 1 ? 's' : ''} would change. Untick any to leave out:` : 'No kept model matches this.'}</div><ul>${ch.map(c => `<li><label><input type="checkbox" checked data-k="${esc(c.key)}"> <b class="mono">${esc(c.key)}</b>: ${esc(c.change)}</label></li>`).join('')}</ul>${q.unclear ? `<div class="muted small">Not placed: ${esc(q.unclear)}</div>` : ''}<div class="row">${ch.length ? `<button class="primary" data-a="go">Revise ${ch.length} &gt;&gt;</button>` : ''}<button data-a="cancel">Cancel</button></div>`);
      const go = d.querySelector('[data-a="go"]'), cb = [...d.querySelectorAll('input[data-k]')];
      if (go) { const upd = () => { const n = cb.filter(x => x.checked).length; go.textContent = `Revise ${n} >>`; go.disabled = !n; }; cb.forEach(x => x.onchange = upd);
        go.onclick = () => { const keys = cb.filter(x => x.checked).map(x => x.dataset.k), k = this.S.slots || 1; if (keys.length > 3 && !confirm(`Revise ${keys.length} models? ${k} at a time, about ${Math.ceil(keys.length / k) * 2.5} minutes, each a run of the forge's Claude.`)) return;
          this.act({ do: 'go', id: q.id, keys }, j => `${(j.started || []).length} revision(s) started; each appears on its model to keep or discard`); }; }
      const cn = d.querySelector('[data-a="cancel"]'); if (cn) cn.onclick = () => this.act({ do: 'cancel', id: q.id }, 'dismissed');
      box.appendChild(d);
    }
  },
  detail(k, it) {
    const d = document.createElement('div'); d.className = 'cdetail'; const mine = this.jobs.filter(j => j.kind === 'remodel' && j.key === k), revs = mine.slice(0, 3); const fj = mine.find(j => j.id === this.focus); if (fj && !revs.includes(fj)) revs.push(fj);
    d.innerHTML = `<div class="cview"></div><div class="cform">
      <label>What it is <input data-f="what" value="${esc(it.what || '')}"></label>
      <label>For the modeller <textarea data-f="note" rows="3">${esc(it.note || '')}</textarea></label>
      <div class="row"><label>Size, world units <input data-f="size" type="number" step="any" min="0.01" value="${+it.size || 1}" style="width:90px"></label><button data-a="save">Save</button></div>
      <div class="muted small">Replaces: <span class="mono">${esc(it.replaces || '?')}</span>${it.moves ? ` · the game animates: ${esc(it.moves)}` : ''}${it.file ? ` · <span class="mono">assets/${esc(it.file)}</span>${it.recipe ? ', with its recipe' : ', no recipe kept (a revision remakes it)'}` : ''}</div>
      ${it.file ? `<label>Change one thing <textarea data-f="rev" rows="2" placeholder="e.g. bigger eyes, antennae curled forward"></textarea></label><div class="row"><button class="primary" data-a="revise">Revise &gt;&gt;</button></div>` : ''}
      <div class="crevs"></div>
      <div class="cvers"></div>
      <div class="row"><button data-a="make">${it.file ? 'Make it again from scratch' : 'Make it'}</button><button data-a="drop" title="out of the cast; a kept model file stays in assets/">Drop from the cast</button></div></div>`;
    const v = d.querySelector('.cview'), ff = fj && fj.result && fj.result.ok && (fj.result.files || [])[0];
    if (ff) { this.view(v, fj.base + ff); const cap = document.createElement('div'); cap.className = 'muted small'; cap.textContent = `showing the revision “${(fj.note || '').slice(0, 80)}”`; v.after(cap); }
    else if (it.file) this.view(v, ASSET(it.file) + '?v=' + (it.v || '')); else v.innerHTML = `<div class="muted small">No model yet: the game draws its stand-in.${it.state === 'making' ? ' The artist is making one.' : ''}</div>`;
    const R = d.querySelector('.crevs');
    for (const j of revs) {
      const r = document.createElement('div'); r.className = 'crev' + (j.id === this.focus ? ' on' : ''); const ok = j.result && j.result.ok && (j.result.files || [])[0], kept = it.kept === j.id;
      r.innerHTML = `<div class="small"><b>Revision:</b> ${esc(j.note || '')}<div class="muted">${!j.result ? 'the artist is on it…' : !j.result.ok ? 'failed: ' + esc(j.result.error || '') : kept ? '<b>in use</b>: this is the model the game has now' : esc(j.result.note || '')}</div></div>`;
      if (ok && !kept) { const im = document.createElement('img'); glbThumb(im, j.base + ok, 96); im.title = 'see it turning'; im.style.cursor = 'pointer'; im.onclick = () => this.view(v, j.base + ok); r.prepend(im);
        const b = document.createElement('div'); b.className = 'row'; b.innerHTML = '<button class="primary" title="this revision replaces the model in the game; the one in use now is kept under Earlier versions">Use this model</button><button>Discard</button>'; const [kb, db] = b.querySelectorAll('button');
        kb.onclick = async () => { kb.disabled = db.disabled = true; kb.textContent = 'Switching…'; this.focus = j.id;
          await this.post(apiUrl('spritesave'), { key: k, take: { id: j.id, file: ok }, size: +it.size || 1 }, `${k}: this revision is now the model the game uses (it loads it next time it starts). The one it replaced is under Earlier versions.`); };
        db.onclick = () => this.post(apiUrl('sketchdelete'), { id: j.id }, 'revision discarded'); r.appendChild(b); }
      R.appendChild(r);
    }
    const H = it.history || [], VB = d.querySelector('.cvers');
    if (H.length) { VB.innerHTML = `<div class="small"><b>Earlier versions</b> <span class="muted">the models ${esc(k)} used before; any can be put back</span></div>`;
      for (const e of H) { const r = document.createElement('div'); r.className = 'crev'; const when = new Date((e.until || 0) * 1000);
        r.innerHTML = `<img alt="" title="see it turning" style="cursor:pointer"><div class="small">${esc(e.note || 'an earlier model')}<div class="muted">in use until ${when.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</div></div><div class="row"><button>Use this model</button></div>`;
        const im = r.querySelector('img'), url = `/games/${GAME}/thumbs/${k}-${e.v}.webp`; showStrip(im, url); im.onclick = () => this.view(v, `/games/${GAME}/versions/${k}/${e.v}.glb`);
        const ub = r.querySelector('button'); ub.onclick = async () => { ub.disabled = true; ub.textContent = 'Switching…'; await this.act({ do: 'use', key: k, v: e.v }, `${k}: the earlier version is back in use; the one it replaced is now under Earlier versions`); };
        VB.appendChild(r); } }
    const f = n => d.querySelector(`[data-f="${n}"]`);
    d.querySelector('[data-a="save"]').onclick = () => this.act({ do: 'edit', key: k, fields: { what: f('what').value, note: f('note').value, size: +f('size').value } }, 'saved');
    d.querySelector('[data-a="make"]').onclick = () => { if (it.file && !confirm(`Make ${k} again from scratch? When it is done it replaces the model the game has now.`)) return; this.act({ do: 'make', keys: [k] }, `the artist is making ${k}`); };
    d.querySelector('[data-a="drop"]').onclick = () => { if (confirm(`Drop ${k} from the cast? The game keeps its stand-in for it.`)) { this.open = ''; this.act({ do: 'edit', key: k, drop: true }, `${k} is out of the cast`); } };
    const rv = d.querySelector('[data-a="revise"]'); if (rv) rv.onclick = () => { const t = f('rev').value.trim(); if (!t) { say('say what to change', 'err'); return; } this.act({ do: 'revise', key: k, note: t }, `the artist is revising ${k}; the revision appears here to keep or discard`); };
    return d;
  },
  async view(box, url) { // the model turning, drag to turn it (the page's one viewer: see spin())
    try { await spin(box, url, 300); }
    catch (e) { box.textContent = 'it could not be shown: ' + e.message; }
  },
  async act(body, ok) { return this.post(apiUrl('cast'), body, ok); },
  async post(url, body, ok) {
    try { const r = await fetch(url, { method: 'POST', headers: apiHeaders(), body: JSON.stringify(body) }); const j = await r.json(); if (!r.ok) throw new Error(j.error || r.status); const t = typeof ok === 'function' ? ok(j) : ok; say(t, 'ok'); toast(t, 'ok'); }
    catch (e) { say(e.message, 'err'); toast(e.message, 'err'); }
    await this.refresh();
  },
};
if (CAST_OK) {
el('castAskBtn').onclick = () => { const t = el('castAsk').value.trim(); if (!t) { say('describe the change first', 'err'); return; }
  Cast.act({ do: 'request', text: t }, j => { el('castAsk').value = ''; return j.queued ? 'the art lane is full: your request is read when a slot frees' : 'the artist is working out which models you mean; the list appears here to check before anything changes'; }); };
el('castList').onclick = () => Cast.act({ do: 'list' }, j => j.queued ? 'another sprite job is running: listing the cast is next' : 'the artist is reading the game for its 3D things (a few minutes); the list appears here');
el('castMake').onclick = () => { const n = Cast.items().filter(([, it]) => it.state === 'none' || it.state === 'failed').length;
  const k = Cast.S.slots || 1; if (!confirm(`Make ${n} model(s)? The artist makes ${k} at a time, about 2 to 3 minutes each (about ${Math.ceil(n / k) * 2.5} minutes in all), each a run of the forge's Claude. Each is kept as it lands; revise any of them afterwards.`)) return;
  Cast.act({ do: 'make' }, j => `${(j.started || []).length} model job(s) started; they are made one after another`); };
el('castWire').onclick = () => { if (!confirm('Ask the builder to hook every thing in the cast into the game (one run)? After that, a model kept or revised here shows up in the game by itself.')) return;
  Cast.act({ do: 'wire' }, j => j.queued ? 'the builder is busy: wiring the cast is its next run' : 'the builder is wiring the cast in; watch it on the game page\'s Build tab'); };
}
if (CAST_OK && location.hash === '#3d') Cast.refresh(); // the page opened on this tab before this file loaded
