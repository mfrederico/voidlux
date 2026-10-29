// Ev: the Events tab of the sprite page (loaded after the page's own script; it uses its globals: el, esc, say, apiUrl, apiHeaders).
// The producer's rules for how things react: on <event> → if <conditions> → do <actions>, per sprite, per group or for
// everything. Only what the game declares (assets/events-vocab.json, the builder's) can be chosen; the rules are
// assets/events.json (lib/Rules.php checks them before they are written; kit/js/kit-events.js runs them).
const EV_OK = ['evSub', 'evWire', 'evSave', 'evBody', 'evAskBox', 'evAsk', 'evAskBtn', 'evProps', 'evWho', 'evRules', 'evWhoTitle', 'evNew'].every(id => el(id));
if (!EV_OK) { el('fresh').classList.remove('hidden'); el('freshWhy').textContent = '(the Events tab is newer than this copy of the page)'; }
const Ev = {
  S: null, rules: [], who: '*', dirty: false, t: 0,
  OPS: [['is', 'is'], ['not', 'is not'], ['>', '>'], ['<', '<'], ['>=', '≥'], ['<=', '≤'], ['has', 'has']],
  async refresh(keep) {
    if (!EV_OK) return;
    try { const r = await fetch(apiUrl('events'), { cache: 'no-store' }), j = await r.json(); if (!r.ok) throw new Error(j.error || r.status); this.S = j; if (!keep || !this.dirty) { this.rules = JSON.parse(JSON.stringify(j.rules || [])); this.dirty = false; } }
    catch (e) { el('evSub').textContent = 'the events could not be read: ' + e.message; return; }
    this.draw(); clearTimeout(this.t); if ((this.S.proposals || []).some(p => p.state === 'reading' || p.state === 'building')) this.t = setTimeout(() => { if (!el('tab-events').classList.contains('hidden')) this.refresh(true); }, 5000);
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
  say(r) { // a rule in words: on die → if self.burning is yes and count fires (game) < 3 → SetBurning to nearby enemies (radius 80)
    const lim = a => [a.max && `max ${a.max}`, a.once && 'once', a.cooldown && `every ${a.cooldown}s at most`, a.chance != null && a.chance < 1 && `${Math.round(a.chance * 100)}% chance`, a.delay && `after ${a.delay}s`].filter(Boolean).join(', ');
    const args = a => Object.entries(a.args || {}).map(([k, v]) => `${k} ${v}`).join(', ');
    const to = a => a.to ? ` → ${a.to.target}${a.to.who ? ' ' + a.to.who.replace('group:', 'every ') : ''}${a.to.args ? ' (' + Object.entries(a.to.args).map(([k, v]) => `${k} ${v}`).join(', ') + ')' : ''}` : '';
    const op = c => (this.OPS.find(o => o[0] === c.op) || [, c.op])[1];
    const cond = c => c.op === 'chance' ? `${Math.round(c.value * 100)}% chance` : c.state != null ? `${c.state} ${op(c)} ${c.value}` : c.count != null ? `count ${c.count}${c.on === 'game' ? ' (game)' : ''} ${op(c)} ${c.value}` : `${c.field} ${op(c)} ${c.value}`;
    return `on ${r.on}` + ((r.if || []).length ? ' → if ' + r.if.map(cond).join(' and ') : '')
      + ' → ' + (r.do || []).map(a => (a.emit ? `fire “${a.emit}”` : a.fn) + to(a) + ((args(a) || lim(a)) ? ` (${[args(a), lim(a)].filter(Boolean).join('; ')})` : '')).join(', then ');
  },
  sel(opts, val, onch, empty) { const s = document.createElement('select'); if (empty) s.add(new Option(empty, '')); for (const o of opts) { const [v, t] = Array.isArray(o) ? o : [o, o]; s.add(new Option(t, v)); } s.value = val ?? ''; s.onchange = () => { onch(s.value); this.touch(); }; return s; },
  inp(val, onch, ph, w) { const i = document.createElement('input'); i.value = val ?? ''; if (ph) i.placeholder = ph; if (w) i.style.width = w; i.oninput = () => { onch(i.value); this.touch(); }; return i; },
  num(v) { return v === '' || v == null ? undefined : (isNaN(+v) ? v : +v); },
  span(t, cls) { return Object.assign(document.createElement('span'), { className: cls || '', textContent: t }); },
  whoOpts() { return [['*', 'anything'], ...this.groups().map(g => ['group:' + g, 'every ' + g]), ...Object.keys(this.V().subjects).sort()]; },
  BUILTIN: { Count: { about: 'add to a counter (on this thing, or game-wide)', params: { name: { about: 'the counter' }, by: { kind: 'number', default: 1 }, on: { values: ['self', 'game'], default: 'self' } } },
    SetCount: { about: 'set a counter (0 resets it)', params: { name: { about: 'the counter' }, value: { kind: 'number', default: 0 }, on: { values: ['self', 'game'], default: 'self' } } } },
  param(obj, key, p, pd, w) { // one setting: a list when it has values, a box otherwise
    const lab = document.createElement('label'); lab.className = 'small'; lab.title = pd.about || ''; const cur = (obj[key] || {})[p];
    const set = v => { obj[key] = obj[key] || {}; if (v === '' || v === undefined) delete obj[key][p]; else obj[key][p] = pd.kind === 'number' ? this.num(v) : v; if (!Object.keys(obj[key]).length) delete obj[key]; };
    lab.append(p + ' ', pd.values ? this.sel(pd.values, cur ?? '', set, pd.default != null ? `(${pd.default})` : '…') : this.inp(cur, set, pd.default != null ? String(pd.default) : '', w || '64px')); return lab;
  },
  card(r) {
    const V = this.V(), d = document.createElement('div'); d.className = 'evrule' + (r.off ? ' off' : '') + (r.broken ? ' broken' : '');
    const head = document.createElement('div'); head.className = 'row evhead'; head.innerHTML = `<b class="small">${esc(this.say(r))}</b>`;
    const off = document.createElement('label'); off.className = 'small'; off.innerHTML = '<input type="checkbox"> off'; off.querySelector('input').checked = !!r.off; off.querySelector('input').onchange = e => { r.off = e.target.checked; this.touch(); this.draw(); };
    const del = document.createElement('button'); del.textContent = 'Delete'; del.onclick = () => { if (confirm('Delete this rule?')) { this.rules.splice(this.rules.indexOf(r), 1); this.touch(); this.draw(); } };
    head.append(off, del); d.appendChild(head); if (r.broken) { const b = document.createElement('div'); b.className = 'small evbroken'; b.textContent = 'This rule no longer fits the game: ' + r.broken; d.appendChild(b); }
    const redraw = () => { this.touch(); this.draw(); };
    // on
    const on = document.createElement('div'); on.className = 'evrow'; on.append(this.span('on', 'evk'), this.sel(Object.keys(V.events).sort(), r.on, v => { r.on = v; r.if = (r.if || []).filter(c => c.field == null); redraw(); }));
    const ab = V.events[r.on]; if (ab && ab.about) on.append(this.span(ab.about, 'muted small')); d.appendChild(on);
    // if: a field of the event, the game's state, a counter, or chance
    const fields = (V.events[r.on] || {}).fields || {}, states = V.state || {}; r.if = r.if || [];
    const valueBox = (c, f) => f.values ? this.sel(f.values, c.value, v => c.value = v, 'value…') : this.inp(c.value, v => c.value = f.kind === 'number' || c.count != null ? this.num(v) : v, f.kind === 'number' || c.count != null ? 'a number' : 'a value', '110px');
    r.if.forEach((c, i) => { const row = document.createElement('div'); row.className = 'evrow'; row.append(this.span(i ? 'and' : 'if', 'evk'));
      if (c.op === 'chance') row.append(this.span('chance'), this.inp(c.value, v => c.value = this.num(v), '0.5', '70px'), this.span('(0 to 1)', 'muted small'));
      else if (c.state != null) { const f = states[c.state] || {}; row.append(this.span('state'), this.sel(Object.keys(states).sort(), c.state, v => { c.state = v; redraw(); }), this.sel(this.OPS, c.op, v => c.op = v), valueBox(c, f)); if (f.about) row.append(this.span(f.about, 'muted small')); }
      else if (c.count != null) row.append(this.span('counter'), this.inp(c.count, v => c.count = v, 'name', '90px'), this.sel([['self', 'on this thing'], ['game', 'game-wide']], c.on || 'self', v => c.on = v), this.sel(this.OPS, c.op, v => c.op = v), valueBox(c, {}));
      else { const f = fields[c.field] || {}; row.append(this.sel(Object.keys(fields), c.field, v => { c.field = v; redraw(); }, 'field…'), this.sel(this.OPS, c.op, v => c.op = v), valueBox(c, f)); if (f.about) row.append(this.span(f.about, 'muted small')); }
      const x = document.createElement('button'); x.textContent = '×'; x.title = 'remove this condition'; x.onclick = () => { r.if.splice(i, 1); redraw(); }; row.append(x); d.appendChild(row); });
    const addIf = document.createElement('div'); addIf.className = 'row evadd'; addIf.innerHTML = '<button>+ field</button><button title="the game\'s state: its health, the player\'s segments, the level…">+ state</button><button title="a counter the rules keep (Count / SetCount)">+ counter</button><button>+ chance</button>'; const [b1, b2, b3, b4] = addIf.querySelectorAll('button');
    b1.onclick = () => { r.if.push({ field: Object.keys(fields)[0] || '', op: 'is', value: '' }); redraw(); }; b2.onclick = () => { r.if.push({ state: Object.keys(states).sort()[0] || '', op: 'is', value: '' }); redraw(); };
    b3.onclick = () => { r.if.push({ count: '', on: 'self', op: '>=', value: 1 }); redraw(); }; b4.onclick = () => { r.if.push({ op: 'chance', value: 0.5 }); redraw(); };
    if (!Object.keys(fields).length) b1.disabled = true; if (!Object.keys(states).length) { b2.disabled = true; b2.title = 'the game declares no state yet (Update the game\'s events)'; } d.appendChild(addIf);
    // do: an action (the game's, or a built-in counter), or fire an event; on the thing itself or on what a target finds
    r.do = r.do || []; const targets = V.targets || {};
    r.do.forEach((a, i) => { const row = document.createElement('div'); row.className = 'evrow evdo'; row.append(this.span(i ? 'then' : 'do', 'evk'));
      if (a.emit !== undefined) row.append(this.span('fire event'), this.sel(Object.keys(V.events).sort(), a.emit, v => { a.emit = v; redraw(); }));
      else { const def = V.actions[a.fn] || this.BUILTIN[a.fn] || {};
        row.append(this.sel([...Object.keys(V.actions).sort(), ['Count', 'Count (counter)'], ['SetCount', 'SetCount (counter)']], a.fn, v => { a.fn = v; a.args = {}; redraw(); }, 'action…'));
        for (const [p, pd] of Object.entries(def.params || {})) row.append(this.param(a, 'args', p, pd, p === 'name' ? '90px' : '64px'));
        if (def.about) row.append(this.span(def.about + (def.lasting ? ' (lasting)' : ''), 'muted small')); }
      const tg = document.createElement('div'); tg.className = 'row small evlim'; tg.append(this.span('on'));
      tg.append(this.sel([['', 'this thing'], ...Object.keys(targets).sort()], a.to ? a.to.target : '', v => { if (!v) delete a.to; else a.to = { target: v }; redraw(); }));
      if (a.to) { for (const [p, pd] of Object.entries((targets[a.to.target] || {}).params || {})) tg.append(this.param(a.to, 'args', p, pd)); tg.append(this.span('only'), this.sel(this.whoOpts(), a.to.who || '*', v => { if (v === '*') delete a.to.who; else a.to.who = v; this.touch(); })); }
      if (!Object.keys(targets).length) tg.title = 'the game declares no targets yet (Update the game\'s events)';
      const lim = document.createElement('div'); lim.className = 'row small evlim';
      const L = (name, ph, w, tip) => { const l = document.createElement('label'); l.title = tip; l.append(name + ' ', this.inp(a[name], v => { const n = this.num(v); if (n === undefined) delete a[name]; else a[name] = n; }, ph, w)); return l; };
      const once = document.createElement('label'); once.innerHTML = '<input type="checkbox"> once'; once.title = 'only the first time, per thing'; once.querySelector('input').checked = !!a.once; once.querySelector('input').onchange = e => { if (e.target.checked) a.once = true; else delete a.once; this.touch(); };
      lim.append(L('max', '∞', '48px', a.emit !== undefined || !(V.actions[a.fn] || {}).lasting ? 'at most this many times per thing' : 'at most this many at once per thing'), once, L('cooldown', '0', '48px', 'seconds before it can happen again, per thing'), L('chance', '1', '48px', 'probability, 0 to 1'), L('delay', '0', '48px', 'seconds to wait before it happens'));
      const x = document.createElement('button'); x.textContent = '×'; x.title = 'remove'; x.onclick = () => { r.do.splice(i, 1); redraw(); }; lim.append(x); row.append(tg, lim); d.appendChild(row); });
    const addDo = document.createElement('div'); addDo.className = 'row evadd'; addDo.innerHTML = '<button>+ action</button><button title="count something: the third time, only 3 times a game…">+ counter</button><button title="a chain: another event, on this thing or on what a target finds, which other rules react to">+ fire an event</button>'; const [d1, d2, d3] = addDo.querySelectorAll('button');
    d1.onclick = () => { r.do.push({ fn: Object.keys(V.actions).sort()[0] || '' }); redraw(); }; d2.onclick = () => { r.do.push({ fn: 'Count', args: { name: 'times', on: 'self' } }); redraw(); }; d3.onclick = () => { r.do.push({ emit: Object.keys(V.events).sort()[0] || '' }); redraw(); }; d.appendChild(addDo);
    const nt = document.createElement('div'); nt.className = 'evrow'; nt.append(this.span('note', 'evk'), this.inp(r.note, v => r.note = v, 'why this rule (optional)', '100%')); d.appendChild(nt);
    return d;
  },
  drawProposals() {
    const box = el('evProps'); box.innerHTML = '';
    for (const p of (this.S.proposals || []).filter(p => !['added', 'dismissed', 'superseded'].includes(p.state))) {
      const d = document.createElement('div'); d.className = 'creq'; const miss = p.missing || [];
      d.innerHTML = `<div><b>“${esc(p.text)}”</b></div>` + (p.state === 'reading' ? `<div class="muted small">${p.after ? 'the builder has added what it needed; the rules are being written again…' : 'the artist is working out the rules…'}</div>`
        : p.state === 'building' ? `<div class="small">The builder is adding what this needs: ${miss.map(esc).join('; ')}. When it is done, the rules are written again by themselves and appear here to add.</div>`
        : p.state === 'failed' ? `<div class="small">no rules: ${esc(p.error || '')}</div>`
        : `${p.explain ? `<div class="small">${esc(p.explain)}</div>` : ''}${(p.rules || []).length ? `<ul>${p.rules.map(r => `<li class="small"><b class="mono">${esc(r.who)}</b>: ${esc(this.say(r))}</li>`).join('')}</ul>` : ''}`
          + (miss.length ? `<div class="small"><b>The game cannot do all of this yet.</b> It needs:</div><ul>${miss.map(m => `<li class="small">${esc(m)}</li>`).join('')}</ul>` : (p.error ? `<div class="small evbroken">${esc(p.error)}</div>` : '')));
      const row = document.createElement('div'); row.className = 'row';
      if (p.state === 'proposed' && miss.length) { const b = document.createElement('button'); b.className = 'primary'; b.textContent = 'Build what\'s missing, then write the rules'; b.title = 'one builder run adds these to the game; then the rules for your concept are written again, by themselves, for you to add';
        b.onclick = () => { if (confirm(`Ask the builder to add ${miss.length} thing(s) to the game (one run), then write the rules for “${p.text}” again?`)) this.post({ do: 'build', id: p.id }, j => j.queued ? 'the builder is busy: it adds these on its next run, then the rules are written' : 'the builder is adding what the concept needs; the rules follow by themselves'); }; row.appendChild(b); }
      else if (p.state === 'proposed' && (p.rules || []).length) { const b = document.createElement('button'); b.className = 'primary'; b.textContent = `Add ${p.rules.length} rule${p.rules.length > 1 ? 's' : ''}`; b.onclick = () => { if (this.dirty && !confirm('You have unsaved changes to the rules; adding these reloads the rules from the game, and your changes are lost. Add anyway?')) return; this.dirty = false; this.post({ do: 'add', id: p.id }, j => `${(j.added || []).length} rule(s) added; the game uses them next time it starts`); }; row.appendChild(b); }
      if (!['reading', 'building'].includes(p.state)) { const b = document.createElement('button'); b.textContent = 'Dismiss'; b.onclick = () => this.post({ do: 'dismiss', id: p.id }, 'dismissed'); row.appendChild(b); }
      d.appendChild(row); box.appendChild(d);
    }
  },
  async post(body, ok) {
    try { const r = await fetch(apiUrl('events'), { method: 'POST', headers: apiHeaders(), body: JSON.stringify(body) }); const j = await r.json(); if (!r.ok) throw new Error(j.error || r.status); const t = typeof ok === 'function' ? ok(j) : ok; say(t, 'ok'); toast(t, 'ok'); if (body.do === 'save') this.dirty = false; }
    catch (e) { say(e.message, 'err'); toast(e.message, 'err'); return; }
    this.refresh(body.do !== 'save' && body.do !== 'add');
  },
};
if (EV_OK) {
el('evSave').onclick = () => Ev.post({ do: 'save', rules: Ev.rules.map(r => { const c = Object.assign({}, r); delete c.broken; return c; }) }, j => `${j.saved} rule(s) saved; the game uses them next time it starts`);
el('evNew').onclick = () => { const V = Ev.V(), on = V.events.hit ? 'hit' : Object.keys(V.events).sort()[0]; if (!on) return; Ev.rules.push({ id: 'r' + Date.now().toString(36), who: Ev.who, on, if: [], do: [] }); Ev.touch(); Ev.draw(); };
el('evAskBtn').onclick = () => { const t = el('evAsk').value.trim(); if (!t) { say('describe the rule first', 'err'); return; } Ev.post({ do: 'describe', text: t }, j => { el('evAsk').value = ''; return j.queued ? 'the art lane is full: your rule is written when a slot frees' : 'the artist is writing the rules; they appear here to add or dismiss'; }); };
el('evWire').onclick = () => { const extra = Ev.S.vocab ? prompt('What should the game be able to react to or do that it cannot yet? (blank: just bring the events up to date with the game)', '') : ''; if (extra === null) return;
  if (!Ev.S.vocab && !confirm('Ask the builder to wire events into the game (one run)? It declares what can happen and what the game can do, emits the events, and moves reactions that are fixed in code today into rules you can edit, checking with a playtest before and after.')) return;
  Ev.post({ do: 'wire', extra }, j => j.queued ? 'the builder is busy: wiring events is its next run' : 'the builder is wiring events in; watch it on the game page\'s Build tab'); };
}
window.addEventListener('beforeunload', e => { if (Ev.dirty) { e.preventDefault(); e.returnValue = ''; } });
if (EV_OK && location.hash === '#events') Ev.refresh(); // the page opened on this tab before this file loaded
