// Events — the game's rules engine. The producer writes rules on the sprite page's Events tab (assets/events.json); the game
// says what can happen and what it can do (assets/events-vocab.json, written by the builder) and implements the actions here.
// A rule is a chain:   on <event>  →  if <conditions>  →  do <actions>     e.g. on hit → if type is fire → do AddFireEffect (max 3)
//
//   Events.action('AddFireEffect', (ctx, args, end) => { … })   implement each action the vocabulary names (before load)
//   await Events.load()                                          reads the vocabulary and the rules; checks them against each other
//   Events.emit('hit', 'ant', { type: 'fire', amount: 3 }, e)    an event happened to a thing: its event, its key (a sprite or model
//                                                                key the vocabulary lists as a subject), its fields, and the object
//                                                                itself (limits are counted per object; ctx.target is it)
//   Events.clock = () => G.time; Events.rng = U.rand01           the game's time (cooldowns) and randomness (chance), if it has its own
//
// The vocabulary (assets/events-vocab.json):
//   { "events":   { "hit": { "about": "…", "fields": { "type": { "about": "…", "values": ["fire", "ice"] }, "amount": { "kind": "number" } } } },
//     "actions":  { "AddFireEffect": { "about": "…", "params": { "dur": { "kind": "number", "default": 1.5, "about": "seconds" } }, "lasting": true } },
//     "subjects": { "ant": { "about": "the ant enemy", "groups": ["enemy"] } } }
// The rules (assets/events.json):
//   { "version": 1, "rules": [ { "id": "r1", "who": "ant" | "group:enemy" | "*", "on": "hit", "off": false, "note": "…",
//       "if": [ { "field": "type", "op": "is", "value": "fire" }, { "op": "chance", "value": 0.5 } ],
//       "do": [ { "fn": "AddFireEffect", "args": { "dur": 2 }, "max": 3, "once": false, "cooldown": 0, "chance": 1 },
//               { "emit": "ignite", "args": { "power": 2 } } ] } ] }
// Condition ops: is, not, >, <, >=, <=, has (a list or text contains the value), chance (no field: passes with that probability).
// Limits on an action, counted per object and per rule: max (at most this many at once for a "lasting" action, whose code calls
// end() when its effect is over; for any other action, this many in the object's life), once, cooldown (seconds), chance.
// A cascade: { "emit": "<event>" } fires that event on the same object, with the event's fields and the given args; a chain
// deeper than 8 is a loop, stopped with an error. Nothing is guessed: a rule naming an event, field, action or subject the
// vocabulary does not have is an error in the console at load, and that rule does not run.
// What fired: Events.trace (the last 300 steps); with ?events in the page's address each step is also logged to the console.
const Events = (() => {
  const impl = {}, state = new WeakMap(), byKey = {}, trace = []; let vocab = { events: {}, actions: {}, subjects: {} }, rules = [], depth = 0, loaded = false;
  const verbose = typeof location !== 'undefined' && /[?&]events\b/.test(location.search);
  const note = (step) => { step.t = +E.clock().toFixed(2); trace.push(step); if (trace.length > 300) trace.shift(); if (verbose) console.debug('Events', step); };
  const bad = (rule, why) => { console.error(`Events: rule ${rule.id || '?'} (${rule.who} on ${rule.on}) is off: ${why}`); return false; };
  const check = (r) => { // a rule the vocabulary can run, or an error naming why not
    if (typeof r.who !== "string" || typeof r.on !== "string") return bad(r, "it names no subject (who) or no event (on)");
    const ev = vocab.events[r.on]; if (!ev) return bad(r, `no event "${r.on}" in the vocabulary`);
    if (r.who !== '*' && !(r.who.startsWith('group:') ? Object.values(vocab.subjects).some(s => (s.groups || []).includes(r.who.slice(6))) : vocab.subjects[r.who])) return bad(r, `no subject "${r.who}"`);
    for (const c of r.if || []) { if (c.op === 'chance') continue; if (!(ev.fields || {})[c.field]) return bad(r, `the event "${r.on}" has no field "${c.field}"`); if (!['is', 'not', '>', '<', '>=', '<=', 'has'].includes(c.op)) return bad(r, `unknown op "${c.op}"`); }
    for (const a of r.do || []) { if (a.emit) { if (!vocab.events[a.emit]) return bad(r, `it emits "${a.emit}", which is not an event`); continue; }
      if (!vocab.actions[a.fn]) return bad(r, `no action "${a.fn}" in the vocabulary`); if (!impl[a.fn]) return bad(r, `the game does not implement "${a.fn}"`); }
    return true;
  };
  const pass = (c, ctx) => { if (c.op === 'chance') return E.rng() < +c.value; const v = ctx[c.field], w = c.value;
    switch (c.op) { case 'is': return String(v) === String(w); case 'not': return String(v) !== String(w); case '>': return +v > +w; case '<': return +v < +w; case '>=': return +v >= +w; case '<=': return +v <= +w;
      case 'has': return Array.isArray(v) ? v.map(String).includes(String(w)) : String(v ?? '').includes(String(w)); } return false; };
  const slot = (obj, id) => { let s = state.get(obj); if (!s) { s = {}; state.set(obj, s); } return s[id] || (s[id] = { live: 0, total: 0, last: -1e9 }); };
  const run = (rule, i, a, key, ctx, obj) => { // one action, within its limits
    const id = rule.id + ':' + i, s = slot(obj, id), now = E.clock(), def = vocab.actions[a.fn] || {};
    if (a.once && s.total > 0) return note({ rule: rule.id, key, skip: a.fn || a.emit, why: 'once' });
    if (a.cooldown && now - s.last < +a.cooldown) return note({ rule: rule.id, key, skip: a.fn || a.emit, why: 'cooldown' });
    if (a.max && (def.lasting ? s.live : s.total) >= +a.max) return note({ rule: rule.id, key, skip: a.fn || a.emit, why: `max ${a.max}` });
    if (a.chance != null && +a.chance < 1 && E.rng() >= +a.chance) return note({ rule: rule.id, key, skip: a.fn || a.emit, why: 'chance' });
    s.total++; s.last = now;
    if (a.emit) { note({ rule: rule.id, key, emit: a.emit }); E.emit(a.emit, key, Object.assign({}, ctx, a.args || {}), obj); return; }
    const args = {}; for (const [p, d] of Object.entries(def.params || {})) args[p] = (a.args && a.args[p] !== undefined) ? a.args[p] : d.default; Object.assign(args, a.args || {});
    let ended = false; const end = () => { if (!ended && def.lasting) { ended = true; s.live = Math.max(0, s.live - 1); } }; if (def.lasting) s.live++;
    note({ rule: rule.id, key, did: a.fn, args });
    try { impl[a.fn](Object.assign({ target: obj, key }, ctx), args, end); } catch (e) { end(); console.error(`Events: ${a.fn} (rule ${rule.id}) threw`, e); }
  };
  const E = {
    clock: () => (typeof performance !== 'undefined' ? performance.now() : Date.now()) / 1000, rng: Math.random, trace,
    action(name, fn) { impl[name] = fn; },
    async load(base = 'assets/') {
      const get = async f => { const r = await fetch(base + f, { cache: 'no-cache' }); if (r.status === 404) return null; if (!r.ok) throw new Error(`${base}${f} answered ${r.status}`); return r.json(); };
      const v = await get('events-vocab.json'); if (!v) { console.error(`Events: ${base}events-vocab.json is missing: the game has not declared its events`); return; }
      vocab = { events: v.events || {}, actions: v.actions || {}, subjects: v.subjects || {} };
      for (const n of Object.keys(vocab.actions)) if (!impl[n]) console.error(`Events: the vocabulary names the action "${n}" but the game does not implement it (Events.action('${n}', …))`);
      const r = await get('events.json'); rules = ((r && r.rules) || []).filter(x => !x.off && check(x));
      for (const k of Object.keys(byKey)) delete byKey[k]; loaded = true;
    },
    emit(name, key, ctx, obj) {
      if (!loaded) return; if (depth >= 8) { console.error(`Events: the chain reached ${name} on ${key} 8 deep: a loop, stopped`); return; }
      if (!vocab.events[name]) { console.error(`Events: emit("${name}") is not an event in the vocabulary`); return; }
      const sub = vocab.subjects[key]; if (!sub) { console.error(`Events: emit(…, "${key}") is not a subject in the vocabulary`); return; }
      const list = byKey[key + '|' + name] || (byKey[key + '|' + name] = rules.filter(r => r.on === name && (r.who === '*' || r.who === key || (r.who.startsWith('group:') && (sub.groups || []).includes(r.who.slice(6))))));
      if (!list.length) return; ctx = ctx || {}; obj = obj || sub;
      depth++; try { for (const rule of list) { if (!(rule.if || []).every(c => pass(c, ctx))) { if (verbose) note({ rule: rule.id, key, on: name, why: 'if' }); continue; } (rule.do || []).forEach((a, i) => run(rule, i, a, key, ctx, obj)); } } finally { depth--; }
    },
    rules() { return rules.slice(); }, vocab() { return vocab; },
  };
  if (typeof window !== 'undefined') window.__EVENTS = E;
  return E;
})();
