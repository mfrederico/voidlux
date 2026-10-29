// Events — the game's rules engine. The producer writes rules on the sprite page's Events tab (assets/events.json); the game
// says what can happen and what it can do (assets/events-vocab.json, written by the builder) and implements it here.
// A rule is a chain:   on <event>  →  if <conditions>  →  do <actions>
//   e.g. on die → if self.burning is yes → do Count fires (on game) → if count fires < 3 → SetBurning on nearby enemies (radius 80)
//
// What the game provides (before load):
//   Events.action('SetBurning', (ctx, args, end) => { … })     each action the vocabulary names; ctx.target is the object it acts on
//   Events.state('player.segments', (target, ctx) => P.segments.length)   each state the vocabulary names (self.* reads the target)
//   Events.target('nearby', (ctx, args) => [{ key, obj }, …])  each target finder the vocabulary names (never the thing itself)
//   Events.clock = () => G.time; Events.rng = U.rand01         the game's time and randomness, if it has its own
//   await Events.load();  Events.update() every frame           (update runs delayed actions when they are due)
//   Events.emit('hit', 'ant', { type: 'fire', amount: 3 }, e)  an event happened to a thing: event, subject key, fields, the object
//   Events.reset('game')                                       clears the game-wide counters (a new run); 'all' clears every counter
//
// The vocabulary (assets/events-vocab.json):
//   { "events":   { "hit": { "about": "…", "fields": { "type": { "values": ["fire", "ice"] }, "amount": { "kind": "number" } } } },
//     "actions":  { "SetBurning": { "about": "…", "params": { "dur": { "kind": "number", "default": 3 } }, "lasting": true } },
//     "subjects": { "ant": { "about": "the ant enemy", "groups": ["enemy"] } },
//     "state":    { "self.hp": { "about": "its health, 0 to 1", "kind": "number" }, "player.segments": { "kind": "number" } },
//     "targets":  { "nearby": { "about": "things near it", "params": { "radius": { "kind": "number", "default": 80 } } },
//                   "player": { "about": "the player" }, "source": { "about": "what caused the event" } } }
// The rules (assets/events.json):
//   { "version": 1, "rules": [ { "id": "r1", "who": "ant" | "group:enemy" | "*", "on": "hit", "off": false, "note": "…",
//       "if": [ { "field": "type", "op": "is", "value": "fire" },             a field of the event
//               { "state": "player.segments", "op": "<", "value": 3 },        the game's state (self.* is the thing's own)
//               { "count": "burns", "on": "self", "op": ">=", "value": 3 },  a counter (on: self or game), 0 until set
//               { "op": "chance", "value": 0.5 } ],
//       "do": [ { "fn": "SetBurning", "args": { "dur": 2 }, "max": 3, "once": false, "cooldown": 0, "chance": 1, "delay": 0,
//                 "to": { "target": "nearby", "args": { "radius": 120 }, "who": "group:enemy" } },
//               { "fn": "Count", "args": { "name": "burns", "by": 1, "on": "self" } },     built in: add to a counter
//               { "fn": "SetCount", "args": { "name": "burns", "value": 0, "on": "game" } }, built in: set a counter
//               { "emit": "ignite", "args": { "power": 2 }, "to": { "target": "nearby" } } ] } ] }
// Condition ops: is, not, >, <, >=, <=, has (a list or text contains the value), chance (passes with that probability).
// to: the action (or the event fired) goes to each thing the target finder returns, filtered by who; without to, it is the
// thing the event happened to. delay: seconds of the game's clock before it happens. Limits are counted per object acted on
// and per rule: max (at most this many at once for a "lasting" action, whose code calls end() when its effect is over;
// otherwise this many in that object's life), once, cooldown (seconds), chance.
// A cascade: { "emit": "<event>" } fires that event, with the event's fields, the given args and from (the key it came from);
// a chain deeper than 8 is a loop, stopped with an error. Nothing is guessed: a rule naming an event, field, state, target,
// action or subject the vocabulary does not have is an error in the console at load, and that rule does not run.
// What fired: Events.trace (the last 300 steps); with ?events in the page's address each step is also logged to the console.
const Events = (() => {
  const impl = {}, readers = {}, finders = {}, byKey = {}, trace = [], gameCount = {}, later = [];
  const BUILTIN = { Count: { about: 'add to a counter', params: { name: {}, by: { kind: 'number', default: 1 }, on: { values: ['self', 'game'], default: 'self' } } },
    SetCount: { about: 'set a counter', params: { name: {}, value: { kind: 'number', default: 0 }, on: { values: ['self', 'game'], default: 'self' } } } };
  let objs = new WeakMap(), vocab = { events: {}, actions: {}, subjects: {}, state: {}, targets: {} }, rules = [], depth = 0, loaded = false, ticked = 0, warnedTick = false;
  const verbose = typeof location !== 'undefined' && /[?&]events\b/.test(location.search);
  const note = (step) => { step.t = +E.clock().toFixed(2); trace.push(step); if (trace.length > 300) trace.shift(); if (verbose) console.debug('Events', step); };
  const bad = (rule, why) => { console.error(`Events: rule ${rule.id || '?'} (${rule.who} on ${rule.on}) is off: ${why}`); return false; };
  const OPS = ['is', 'not', '>', '<', '>=', '<=', 'has'];
  const whoOk = w => w === '*' || (w.startsWith('group:') ? Object.values(vocab.subjects).some(s => (s.groups || []).includes(w.slice(6))) : !!vocab.subjects[w]);
  const matches = (w, key) => { const s = vocab.subjects[key]; return w === '*' || w === key || (!!s && w.startsWith('group:') && (s.groups || []).includes(w.slice(6))); };
  const check = (r) => { // a rule the vocabulary can run, or an error naming why not
    if (typeof r.who !== 'string' || typeof r.on !== 'string') return bad(r, 'it names no subject (who) or no event (on)');
    const ev = vocab.events[r.on]; if (!ev) return bad(r, `no event "${r.on}" in the vocabulary`); if (!whoOk(r.who)) return bad(r, `no subject or group "${r.who}"`);
    for (const c of r.if || []) { if (c.op === 'chance') continue; if (!OPS.includes(c.op)) return bad(r, `unknown op "${c.op}"`);
      if (c.state != null) { if (!vocab.state[c.state]) return bad(r, `no state "${c.state}" in the vocabulary`); if (!readers[c.state]) return bad(r, `the game does not read the state "${c.state}"`); }
      else if (c.count != null) { if (!['self', 'game'].includes(c.on || 'self')) return bad(r, `a counter is on self or game, not "${c.on}"`); }
      else if (!(ev.fields || {})[c.field]) return bad(r, `the event "${r.on}" has no field "${c.field}"`); }
    for (const a of r.do || []) {
      if (a.to) { if (!vocab.targets[a.to.target]) return bad(r, `no target "${a.to.target}" in the vocabulary`); if (!finders[a.to.target]) return bad(r, `the game does not find the target "${a.to.target}"`); if (a.to.who && !whoOk(a.to.who)) return bad(r, `no subject or group "${a.to.who}" to aim at`); }
      if (a.emit) { if (!vocab.events[a.emit]) return bad(r, `it emits "${a.emit}", which is not an event`); continue; }
      if (BUILTIN[a.fn]) { if (!a.args || !/^[A-Za-z][A-Za-z0-9_-]{0,30}$/.test(String(a.args.name || ''))) return bad(r, `${a.fn} needs a counter name`); continue; }
      if (!vocab.actions[a.fn]) return bad(r, `no action "${a.fn}" in the vocabulary`); if (!impl[a.fn]) return bad(r, `the game does not implement "${a.fn}"`); }
    return true;
  };
  const mine = obj => { let s = objs.get(obj); if (!s) { s = { slots: {}, count: {} }; objs.set(obj, s); } return s; };
  const value = (c, ctx) => c.state != null ? readers[c.state](ctx.target, ctx) : c.count != null ? ((c.on === 'game' ? gameCount : mine(ctx.target).count)[c.count] || 0) : ctx[c.field];
  const pass = (c, ctx) => { if (c.op === 'chance') return E.rng() < +c.value; let v; try { v = value(c, ctx); } catch (e) { console.error(`Events: reading ${c.state} threw`, e); return false; } const w = c.value;
    switch (c.op) { case 'is': return String(v) === String(w); case 'not': return String(v) !== String(w); case '>': return +v > +w; case '<': return +v < +w; case '>=': return +v >= +w; case '<=': return +v <= +w;
      case 'has': return Array.isArray(v) ? v.map(String).includes(String(w)) : String(v ?? '').includes(String(w)); } return false; };
  const slot = (obj, id) => mine(obj).slots[id] || (mine(obj).slots[id] = { live: 0, total: 0, last: -1e9 });
  const act = (rule, i, a, key, ctx, obj) => { // one action on one object, within its limits
    const name = a.fn || a.emit, s = slot(obj, rule.id + ':' + i), now = E.clock(), def = vocab.actions[a.fn] || BUILTIN[a.fn] || {};
    if (a.once && s.total > 0) return note({ rule: rule.id, key, skip: name, why: 'once' });
    if (a.cooldown && now - s.last < +a.cooldown) return note({ rule: rule.id, key, skip: name, why: 'cooldown' });
    if (a.max && (def.lasting ? s.live : s.total) >= +a.max) return note({ rule: rule.id, key, skip: name, why: `max ${a.max}` });
    if (a.chance != null && +a.chance < 1 && E.rng() >= +a.chance) return note({ rule: rule.id, key, skip: name, why: 'chance' });
    s.total++; s.last = now;
    if (a.emit) { note({ rule: rule.id, key, emit: a.emit }); E.emit(a.emit, key, Object.assign({}, ctx, a.args || {}), obj); return; }
    const args = {}; for (const [p, d] of Object.entries(def.params || {})) args[p] = (a.args && a.args[p] !== undefined) ? a.args[p] : d.default; Object.assign(args, a.args || {});
    if (BUILTIN[a.fn]) { const bag = args.on === 'game' ? gameCount : mine(obj).count; bag[args.name] = a.fn === 'Count' ? (bag[args.name] || 0) + (+args.by || 0) : +args.value || 0; note({ rule: rule.id, key, did: a.fn, args, now: bag[args.name] }); return; }
    let ended = false; const end = () => { if (!ended && def.lasting) { ended = true; s.live = Math.max(0, s.live - 1); } }; if (def.lasting) s.live++;
    note({ rule: rule.id, key, did: a.fn, args });
    try { impl[a.fn](Object.assign({}, ctx, { target: obj, key }), args, end); } catch (e) { end(); console.error(`Events: ${a.fn} (rule ${rule.id}) threw`, e); }
  };
  const run = (rule, i, a, key, ctx, obj, due) => { // the action now (due: its delay is over) or later, on the thing itself or on each thing its target finds
    if (a.delay > 0 && !due) { later.push({ due: E.clock() + +a.delay, rule, i, a, key, ctx, obj }); note({ rule: rule.id, key, wait: a.fn || a.emit, secs: +a.delay });
      if (!warnedTick && ticked === 0) setTimeout(() => { if (ticked === 0 && !warnedTick) { warnedTick = true; console.error('Events: a rule has a delay but the game never calls Events.update(): delayed actions will not happen'); } }, 3000); return; }
    if (!a.to) return act(rule, i, a, key, ctx, obj);
    let list; try { list = finders[a.to.target](Object.assign({}, ctx, { target: obj, key }), Object.assign({}, a.to.args || {})) || []; } catch (e) { console.error(`Events: finding ${a.to.target} threw`, e); return; }
    const from = Object.assign({}, ctx, { from: key });
    for (const t of list) { if (!t || !t.key || t.obj === obj || (a.to.who && !matches(a.to.who, t.key))) continue; act(rule, i, a, t.key, from, t.obj); }
  };
  const E = {
    clock: () => (typeof performance !== 'undefined' ? performance.now() : Date.now()) / 1000, rng: Math.random, trace,
    action(name, fn) { impl[name] = fn; }, state(name, fn) { readers[name] = fn; }, target(name, fn) { finders[name] = fn; },
    async load(base = 'assets/') {
      const get = async f => { const r = await fetch(base + f, { cache: 'no-cache' }); if (r.status === 404) return null; if (!r.ok) throw new Error(`${base}${f} answered ${r.status}`); return r.json(); };
      const v = await get('events-vocab.json'); if (!v) { console.error(`Events: ${base}events-vocab.json is missing: the game has not declared its events`); return; }
      vocab = { events: v.events || {}, actions: v.actions || {}, subjects: v.subjects || {}, state: v.state || {}, targets: v.targets || {} };
      for (const n of Object.keys(vocab.actions)) if (!impl[n]) console.error(`Events: the vocabulary names the action "${n}" but the game does not implement it (Events.action('${n}', …))`);
      for (const n of Object.keys(vocab.state)) if (!readers[n]) console.error(`Events: the vocabulary names the state "${n}" but the game does not read it (Events.state('${n}', …))`);
      for (const n of Object.keys(vocab.targets)) if (!finders[n]) console.error(`Events: the vocabulary names the target "${n}" but the game does not find it (Events.target('${n}', …))`);
      const r = await get('events.json'); rules = ((r && r.rules) || []).filter(x => !x.off && check(x));
      for (const k of Object.keys(byKey)) delete byKey[k]; loaded = true;
    },
    emit(name, key, ctx, obj) {
      if (!loaded) return; if (depth >= 8) { console.error(`Events: the chain reached ${name} on ${key} 8 deep: a loop, stopped`); return; }
      if (!vocab.events[name]) { console.error(`Events: emit("${name}") is not an event in the vocabulary`); return; }
      const sub = vocab.subjects[key]; if (!sub) { console.error(`Events: emit(…, "${key}") is not a subject in the vocabulary`); return; }
      const list = byKey[key + '|' + name] || (byKey[key + '|' + name] = rules.filter(r => r.on === name && matches(r.who, key)));
      if (!list.length) return; obj = obj || sub; ctx = Object.assign({}, ctx || {}, { target: obj, key });
      depth++; try { for (const rule of list) { if (!(rule.if || []).every(c => pass(c, ctx))) { if (verbose) note({ rule: rule.id, key, on: name, why: 'if' }); continue; } (rule.do || []).forEach((a, i) => run(rule, i, a, key, ctx, obj)); } } finally { depth--; }
    },
    update() { ticked++; if (!later.length) return; const now = E.clock(); for (let i = 0; i < later.length;) { const L = later[i]; if (L.due > now) { i++; continue; } later.splice(i, 1); depth++; try { run(L.rule, L.i, L.a, L.key, L.ctx, L.obj, true); } finally { depth--; } } },
    reset(which = 'game') { for (const k of Object.keys(gameCount)) delete gameCount[k]; later.length = 0; if (which === 'all') objs = new WeakMap(); },
    count(name, on = 'game', obj) { return ((on === 'game' ? gameCount : mine(obj).count)[name]) || 0; },
    rules() { return rules.slice(); }, vocab() { return vocab; }, builtins() { return BUILTIN; },
  };
  if (typeof window !== 'undefined') window.__EVENTS = E;
  return E;
})();
