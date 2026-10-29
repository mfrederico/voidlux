// Ev: the Events tab of the sprite page (loaded after the page's own script; it uses its globals: el, esc, say, apiUrl, apiHeaders).
// The producer's rules for how things react: on <event> → if <conditions> → do <actions>, per sprite, per group or for
// everything. Only what the game declares (assets/events-vocab.json, the builder's) can be chosen; the rules are
// assets/events.json (lib/Rules.php checks them before they are written; kit/js/kit-events.js runs them).
const Ev = {
  S: null, rules: [], who: '*', dirty: false, t: 0,
  OPS: [['is', 'is'], ['not', 'is not'], ['>', '>'], ['<', '<'], ['>=', '≥'], ['<=', '≤'], ['has', 'has']],
  async refresh(keep) {
    try { const r = await fetch(apiUrl('events'), { cache: 'no-store' }), j = await r.json(); if (!r.ok) throw new Error(j.error || r.status); this.S = j; if (!keep || !this.dirty) { this.rules = JSON.parse(JSON.stringify(j.rules || [])); this.dirty = false; } }
    catch (e) { el('evSub').textContent = 'the events could not be read: ' + e.message; return; }
    this.draw(); clearTimeout(this.t); if ((this.S.proposals || []).some(p => p.state === 'reading')) this.t = setTimeout(() => { if (!el('tab-events').classList.contains('hidden')) this.refresh(true); }, 5000);
  },
  V() { return this.S.vocab || { events: {}, actions: {}, subjects: {} }; },
  groups() { const g = new Set(); for (const s of Object.values(this.V().subjects)) for (const x of s.groups || []) g.add(x); return [...g].sort(); },
  touch() { this.dirty = true; el('evSave').disabled = false; el('evSave').textContent = 'Save rules *'; },
  draw() {
    const S = this.S, V = this.V(), nS = Object.keys(V.subjects).length, nE = Object.keys(V.events).length, nA = Object.keys(V.actions).length;
    el('evSub').textContent = !S.vocab ? 'The game declares no events yet. Wire events in first: the builder lists what can happen (hit, die, touch…), what the game can do about it (set burning, freeze, a burst…) and the things it happens to; then you write the rules here.'
      : `${this.rules.length} rule${this.rules.length === 1 ? '' : 's'} · the game has ${nE} events, ${nA} actions, ${nS} kinds of thing` + (S.wired ? '' : ' · the game does not load the rules yet (wire it in)') + (this.rules.some(r => r.broken) ? ' · some rules no longer fit the game (marked below)' : '');
    el('evWire').textContent = S.vocab ? 'Update the game\'s events…' : 'Wire events into the game'; el('evSave').disabled = !this.dirty; el('evSave').textContent = this.dirty ? 'Save rules *' : 'Saved';
    el('evBody').classList.toggle('hidden', !S.vocab); el('evAskBox').classList.toggle('hidden', !S.vocab);
    this.drawProposals(); if (!S.vocab) return;
    // who: everything, each group, each subject; with how many rules each has
    const L = el('evWho'); L.innerHTML = ''; const count = w => this.rules.filter(r => r.who === w).length;
    const add = (w, label, sub) => { const b = document.createElement('button'); b.type = 'button'; b.className = 'evwho' + (this.who === w ? ' on' : ''); b.innerHTML = `<span>${esc(label)}</span>${sub ? `<span class="muted small">${esc(sub)}</span>` : ''}<span class="pill">${count(w)}</span>`; b.onclick = () => { this.who = w; this.draw(); }; L.appendChild(b); };
    add('*', 'Everything', 'every kind of thing'); for (const g of this.groups()) add('group:' + g, 'Every ' + g, 'group');
    for (const [k, s] of Object.entries(V.subjects).sort()) add(k, k, s.about || (s.groups || []).join(', '));
    const R = el('evRules'); R.innerHTML = ''; const mine = this.rules.filter(r => r.who === this.who);
    el('evWhoTitle').textContent = this.who === '*' ? 'Rules for everything' : this.who.startsWith('group:') ? `Rules for every ${this.who.slice(6)}` : `Rules for ${this.who}`;
    if (!mine.length) R.innerHTML = '<div class="muted small">No rules here yet. Add one, or describe what you want above and the artist writes it.</div>';
    for (const r of mine) R.appendChild(this.card(r));
  },
  say(r) { // a rule in words: on hit → if type is fire → AddFireEffect (max 3)
    const lim = a => [a.max && `max ${a.max}`, a.once && 'once', a.cooldown && `every ${a.cooldown}s at most`, a.chance != null && a.chance < 1 && `${Math.round(a.chance * 100)}% chance`].filter(Boolean).join(', ');
    const args = a => Object.entries(a.args || {}).map(([k, v]) => `${k} ${v}`).join(', ');
    return `on ${r.on}` + ((r.if || []).length ? ' → if ' + r.if.map(c => c.op === 'chance' ? `${Math.round(c.value * 100)}% chance` : `${c.field} ${(this.OPS.find(o => o[0] === c.op) || [, c.op])[1]} ${c.value}`).join(' and ') : '')
      + ' → ' + (r.do || []).map(a => (a.emit ? `fire “${a.emit}”` : a.fn) + ((args(a) || lim(a)) ? ` (${[args(a), lim(a)].filter(Boolean).join('; ')})` : '')).join(', then ');
  },
  sel(opts, val, onch, empty) { const s = document.createElement('select'); if (empty) s.add(new Option(empty, '')); for (const o of opts) { const [v, t] = Array.isArray(o) ? o : [o, o]; s.add(new Option(t, v)); } s.value = val ?? ''; s.onchange = () => { onch(s.value); this.touch(); }; return s; },
  inp(val, onch, ph, w) { const i = document.createElement('input'); i.value = val ?? ''; if (ph) i.placeholder = ph; if (w) i.style.width = w; i.oninput = () => { onch(i.value); this.touch(); }; return i; },
  num(v) { return v === '' || v == null ? undefined : (isNaN(+v) ? v : +v); },
  card(r) {
    const V = this.V(), d = document.createElement('div'); d.className = 'evrule' + (r.off ? ' off' : '') + (r.broken ? ' broken' : '');
    const head = document.createElement('div'); head.className = 'row evhead'; head.innerHTML = `<b class="small">${esc(this.say(r))}</b>`;
    const off = document.createElement('label'); off.className = 'small'; off.innerHTML = '<input type="checkbox"> off'; off.querySelector('input').checked = !!r.off; off.querySelector('input').onchange = e => { r.off = e.target.checked; this.touch(); this.draw(); };
    const del = document.createElement('button'); del.textContent = 'Delete'; del.onclick = () => { if (confirm('Delete this rule?')) { this.rules.splice(this.rules.indexOf(r), 1); this.touch(); this.draw(); } };
    head.append(off, del); d.appendChild(head); if (r.broken) { const b = document.createElement('div'); b.className = 'small evbroken'; b.textContent = 'This rule no longer fits the game: ' + r.broken; d.appendChild(b); }
    const redraw = () => { this.touch(); this.draw(); };
    // on
    const on = document.createElement('div'); on.className = 'evrow'; on.append(Object.assign(document.createElement('span'), { className: 'evk', textContent: 'on' }), this.sel(Object.keys(V.events).sort(), r.on, v => { r.on = v; r.if = []; redraw(); }));
    const ab = V.events[r.on]; if (ab && ab.about) on.append(Object.assign(document.createElement('span'), { className: 'muted small', textContent: ab.about })); d.appendChild(on);
    // if
    const fields = (V.events[r.on] || {}).fields || {}; r.if = r.if || [];
    r.if.forEach((c, i) => { const row = document.createElement('div'); row.className = 'evrow'; row.append(Object.assign(document.createElement('span'), { className: 'evk', textContent: i ? 'and' : 'if' }));
      if (c.op === 'chance') row.append(Object.assign(document.createElement('span'), { textContent: 'chance' }), this.inp(c.value, v => c.value = this.num(v), '0.5', '70px'), Object.assign(document.createElement('span'), { className: 'muted small', textContent: '(0 to 1)' }));
      else { const f = fields[c.field] || {}; row.append(this.sel(Object.keys(fields), c.field, v => { c.field = v; redraw(); }, 'field…'), this.sel(this.OPS, c.op, v => { c.op = v; this.say(r); }));
        row.append(f.values ? this.sel(f.values, c.value, v => c.value = v, 'value…') : this.inp(c.value, v => c.value = f.kind === 'number' ? this.num(v) : v, f.kind === 'number' ? 'a number' : 'a value', '120px')); if (f.about) row.append(Object.assign(document.createElement('span'), { className: 'muted small', textContent: f.about })); }
      const x = document.createElement('button'); x.textContent = '×'; x.title = 'remove this condition'; x.onclick = () => { r.if.splice(i, 1); redraw(); }; row.append(x); d.appendChild(row); });
    const addIf = document.createElement('div'); addIf.className = 'row evadd'; addIf.innerHTML = '<button>+ condition</button><button>+ chance</button>'; const [b1, b2] = addIf.querySelectorAll('button');
    b1.onclick = () => { r.if.push({ field: Object.keys(fields)[0] || '', op: 'is', value: '' }); redraw(); }; b2.onclick = () => { r.if.push({ op: 'chance', value: 0.5 }); redraw(); }; if (!Object.keys(fields).length) b1.disabled = true; d.appendChild(addIf);
    // do
    r.do = r.do || [];
    r.do.forEach((a, i) => { const row = document.createElement('div'); row.className = 'evrow evdo'; row.append(Object.assign(document.createElement('span'), { className: 'evk', textContent: i ? 'then' : 'do' }));
      if (a.emit !== undefined) row.append(Object.assign(document.createElement('span'), { textContent: 'fire event' }), this.sel(Object.keys(V.events).sort(), a.emit, v => { a.emit = v; redraw(); }));
      else { const def = V.actions[a.fn] || {}; row.append(this.sel(Object.keys(V.actions).sort(), a.fn, v => { a.fn = v; a.args = {}; redraw(); }, 'action…'));
        for (const [p, pd] of Object.entries(def.params || {})) { const lab = document.createElement('label'); lab.className = 'small'; lab.title = pd.about || ''; lab.append(p + ' ', this.inp((a.args || {})[p], v => { a.args = a.args || {}; if (v === '') delete a.args[p]; else a.args[p] = pd.kind === 'number' ? this.num(v) : v; }, pd.default != null ? String(pd.default) : '', '64px')); row.append(lab); }
        if (def.about) row.append(Object.assign(document.createElement('span'), { className: 'muted small', textContent: def.about + (def.lasting ? ' (lasting)' : '') })); }
      const lim = document.createElement('div'); lim.className = 'row small evlim';
      const L = (name, ph, w, tip) => { const l = document.createElement('label'); l.title = tip; l.append(name + ' ', this.inp(a[name], v => { const n = this.num(v); if (n === undefined) delete a[name]; else a[name] = n; }, ph, w)); return l; };
      const once = document.createElement('label'); once.innerHTML = '<input type="checkbox"> once'; once.title = 'only the first time, per thing'; once.querySelector('input').checked = !!a.once; once.querySelector('input').onchange = e => { if (e.target.checked) a.once = true; else delete a.once; this.touch(); };
      lim.append(L('max', '∞', '48px', a.emit !== undefined || !(V.actions[a.fn] || {}).lasting ? 'at most this many times per thing' : 'at most this many at once per thing'), once, L('cooldown', '0', '48px', 'seconds before it can happen again, per thing'), L('chance', '1', '48px', 'probability, 0 to 1'));
      const x = document.createElement('button'); x.textContent = '×'; x.title = 'remove'; x.onclick = () => { r.do.splice(i, 1); redraw(); }; lim.append(x); row.append(lim); d.appendChild(row); });
    const addDo = document.createElement('div'); addDo.className = 'row evadd'; addDo.innerHTML = '<button>+ action</button><button title="a cascade: another event on the same thing, which other rules react to">+ fire an event</button>'; const [d1, d2] = addDo.querySelectorAll('button');
    d1.onclick = () => { r.do.push({ fn: Object.keys(V.actions).sort()[0] || '' }); redraw(); }; d2.onclick = () => { r.do.push({ emit: Object.keys(V.events).sort()[0] || '' }); redraw(); }; d.appendChild(addDo);
    const nt = document.createElement('div'); nt.className = 'evrow'; nt.append(Object.assign(document.createElement('span'), { className: 'evk', textContent: 'note' }), this.inp(r.note, v => r.note = v, 'why this rule (optional)', '100%')); d.appendChild(nt);
    return d;
  },
  drawProposals() {
    const box = el('evProps'); box.innerHTML = '';
    for (const p of (this.S.proposals || []).filter(p => p.state !== 'added' && p.state !== 'dismissed')) {
      const d = document.createElement('div'); d.className = 'creq';
      d.innerHTML = `<div><b>“${esc(p.text)}”</b></div>` + (p.state === 'reading' ? '<div class="muted small">the artist is writing the rules…</div>'
        : p.state === 'failed' ? `<div class="small">no rules: ${esc(p.error || '')}</div>`
        : `${p.explain ? `<div class="small">${esc(p.explain)}</div>` : ''}<ul>${(p.rules || []).map(r => `<li class="small"><b class="mono">${esc(r.who)}</b>: ${esc(this.say(r))}</li>`).join('')}</ul>${p.error ? `<div class="small evbroken">${esc(p.error)}</div>` : ''}`
          + ((p.missing || []).length ? `<div class="small">The game cannot do this yet:</div><ul>${p.missing.map((m, i) => `<li class="small">${esc(m)} <button data-m="${i}">Ask the builder for it</button></li>`).join('')}</ul>` : ''));
      const row = document.createElement('div'); row.className = 'row';
      if (p.state === 'proposed' && (p.rules || []).length) { const b = document.createElement('button'); b.className = 'primary'; b.textContent = `Add ${p.rules.length} rule${p.rules.length > 1 ? 's' : ''}`; b.onclick = () => { if (this.dirty && !confirm('You have unsaved changes to the rules; adding these reloads the rules from the game, and your changes are lost. Add anyway?')) return; this.dirty = false; this.post({ do: 'add', id: p.id }, j => `${(j.added || []).length} rule(s) added; the game uses them next time it starts`); }; row.appendChild(b); }
      if (p.state !== 'reading') { const b = document.createElement('button'); b.textContent = 'Dismiss'; b.onclick = () => this.post({ do: 'dismiss', id: p.id }, 'dismissed'); row.appendChild(b); }
      d.appendChild(row); d.querySelectorAll('[data-m]').forEach(b => b.onclick = () => this.post({ do: 'ask', what: p.missing[+b.dataset.m] }, j => j.queued ? 'the builder is busy: it adds this on its next run' : 'the builder is adding it; when it is done it appears in the choices here'));
      box.appendChild(d);
    }
  },
  async post(body, ok) {
    try { const r = await fetch(apiUrl('events'), { method: 'POST', headers: apiHeaders(), body: JSON.stringify(body) }); const j = await r.json(); if (!r.ok) throw new Error(j.error || r.status); const t = typeof ok === 'function' ? ok(j) : ok; say(t, 'ok'); toast(t, 'ok'); if (body.do === 'save') this.dirty = false; }
    catch (e) { say(e.message, 'err'); toast(e.message, 'err'); return; }
    this.refresh(body.do !== 'save' && body.do !== 'add');
  },
};
el('evSave').onclick = () => Ev.post({ do: 'save', rules: Ev.rules.map(r => { const c = Object.assign({}, r); delete c.broken; return c; }) }, j => `${j.saved} rule(s) saved; the game uses them next time it starts`);
el('evNew').onclick = () => { const V = Ev.V(), on = V.events.hit ? 'hit' : Object.keys(V.events).sort()[0]; if (!on) return; Ev.rules.push({ id: 'r' + Date.now().toString(36), who: Ev.who, on, if: [], do: [] }); Ev.touch(); Ev.draw(); };
el('evAskBtn').onclick = () => { const t = el('evAsk').value.trim(); if (!t) { say('describe the rule first', 'err'); return; } Ev.post({ do: 'describe', text: t }, j => { el('evAsk').value = ''; return j.queued ? 'the art lane is full: your rule is written when a slot frees' : 'the artist is writing the rules; they appear here to add or dismiss'; }); };
el('evWire').onclick = () => { const extra = Ev.S.vocab ? prompt('What should the game be able to react to or do that it cannot yet? (blank: just bring the events up to date with the game)', '') : ''; if (extra === null) return;
  if (!Ev.S.vocab && !confirm('Ask the builder to wire events into the game (one run)? It declares what can happen and what the game can do, emits the events, and moves reactions that are fixed in code today into rules you can edit, checking with a playtest before and after.')) return;
  Ev.post({ do: 'wire', extra }, j => j.queued ? 'the builder is busy: wiring events is its next run' : 'the builder is wiring events in; watch it on the game page\'s Build tab'); };
window.addEventListener('beforeunload', e => { if (Ev.dirty) { e.preventDefault(); e.returnValue = ''; } });
if (location.hash === '#events') Ev.refresh(); // the page opened on this tab before this file loaded
