// Poses — a 3D model's key poses, from assets/<key>.poses.json (made in the pose editor, kit/editor/pose). A model made of named
// parts (torso > thighL > shinL …) is posed by turning those parts; each motion is a few key poses on a 0..1 strip, played in between
// with an eased slerp. A looping motion runs from its last key back round to its first.
//
//   Poses.init(THREE)                          once three is ready, beside Models.init
//   Poses.load('ninja')                        fetches assets/ninja.poses.json (a 404 is a model nobody has posed: has() stays false)
//   Poses.has('ninja', 'run')                  is that motion posed?
//   Poses.apply(model, 'ninja', [['run', f, w], ['sword', f2, 1]])
//        each layer is [motion, where on its strip 0..1, weight 0..1]; later layers win over earlier ones by their weight.
//        f for a loop is its cycle (a stride's phase / 2π); for a one-off, how far through it is (time since / its seconds).
//        The parts not named in a motion stay at rest. Call it every frame; rest is read from the model the first time.
//        A key's lift (the whole figure up or down, in the root part's parent's units) moves the root parts (those with no part above them) along
//        the model's up, whatever way the modelling tool laid its parts out (a z-up model inside a turned wrapper lifts up, not sideways).
//   Poses.upIn(node, model)                    the model's up (+y) as a direction in node's parent's frame
//   Poses.sample(data, motion, f)              { part: Quaternion offset } — what the editor shows between keys
//   Poses.seconds('ninja', 'sword')            the motion's length as the editor set it
//
// A file that does not parse is an error in the console naming it, and the model stays at rest.
const Poses = (() => {
  let T = null, base = 'assets/'; const data = {}, wait = {}, eu = () => new T.Euler(), qa = () => new T.Quaternion();
  const ease = x => x * x * (3 - 2 * x);
  const quatOf = (r, q) => q.setFromEuler(eu().set(r[0], r[1], r[2], 'XYZ'));
  function sample(d, motion, f, out) {
    out = out || {}; const m = d && d.motions && d.motions[motion]; if (!m || !m.keys || !m.keys.length) return out;
    const K = m.keys, n = K.length; f = m.loop ? ((f % 1) + 1) % 1 : Math.max(0, Math.min(1, f));
    let a = n - 1, b = 0; // the keys either side of f; a loop wraps from the last key to the first
    for (let i = 0; i < n; i++) if (K[i].t <= f) a = i; b = a + 1;
    let ka, kb, span, at;
    if (b < n && K[a].t <= f) { ka = K[a]; kb = K[b]; span = kb.t - ka.t; at = f - ka.t; }
    else if (K[a].t <= f) { ka = K[a]; kb = m.loop ? K[0] : K[a]; span = m.loop ? 1 - ka.t + kb.t : 0; at = f - ka.t; } // past the last key
    else { ka = m.loop ? K[n - 1] : K[0]; kb = K[0]; span = m.loop ? 1 - ka.t + kb.t : 0; at = m.loop ? 1 - ka.t + f : 0; } // before the first key
    const x = span > 1e-6 ? ease(at / span) : 0, names = new Set([...Object.keys(ka.rot || {}), ...Object.keys(kb.rot || {})]);
    for (const p of names) { const z = [0, 0, 0]; const q1 = quatOf((ka.rot || {})[p] || z, qa()), q2 = quatOf((kb.rot || {})[p] || z, qa()); out[p] = q1.slerp(q2, x); }
    const la = +ka.lift || 0, lb = +kb.lift || 0; if (la || lb) Object.defineProperty(out, 'lift', { value: la + (lb - la) * x, enumerable: false }); // the whole figure's height, eased like the turns
    return out;
  }
  function upIn(node, model) { // the model's up in node's parent's frame: the turns between the model and that parent, undone
    const q = qa(); for (let o = node.parent; o && o !== model; o = o.parent) q.premultiply(o.quaternion);
    return new T.Vector3(0, 1, 0).applyQuaternion(q.invert()); }
  function parts(model) { // name -> { node, rest }, read once per model
    if (model.userData.poseParts) return model.userData.poseParts; const P = {};
    model.traverse(o => { if (o !== model && o.name && !P[o.name]) P[o.name] = { node: o, rest: o.quaternion.clone(), restPos: o.position.clone(), up: upIn(o, model) }; });
    for (const n in P) { let up = P[n].node.parent, top = true; for (; up && up !== model; up = up.parent) if (up.name && P[up.name]) { top = false; break; } P[n].top = top; } // a root part: the lift moves it
    return (model.userData.poseParts = P);
  }
  return {
    init(THREE, from) { T = THREE; if (from) base = from; },
    load(key) {
      if (wait[key]) return wait[key];
      return (wait[key] = fetch(base + key + '.poses.json', { cache: 'no-cache' }).then(r => { if (r.status === 404) return null; if (!r.ok) throw new Error('answered ' + r.status); return r.json(); })
        .then(j => { if (j) data[key] = j; return j; }).catch(e => { console.error(`Poses: ${base}${key}.poses.json did not load: ${(e && e.message) || e}`); return null; }));
    },
    reload(key) { delete wait[key]; delete data[key]; return this.load(key); },
    set(key, d) { data[key] = d; },
    has(key, motion) { const m = data[key] && data[key].motions && data[key].motions[motion]; return !!(m && m.keys && m.keys.length); },
    seconds(key, motion) { const m = data[key] && data[key].motions && data[key].motions[motion]; return m ? m.seconds || 1 : 1; },
    sample, upIn,
    apply(model, key, layers) {
      if (!T || !model || !data[key]) return false; const P = parts(model), q = qa();
      for (const p in P) { P[p].node.quaternion.copy(P[p].rest); if (P[p].top) P[p].node.position.copy(P[p].restPos); }
      let lift = 0;
      for (const [motion, f, w = 1] of layers) { if (w <= 0) continue; const s = sample(data[key], motion, f);
        for (const p in s) { const it = P[p]; if (!it) continue; q.copy(it.rest).multiply(s[p]); it.node.quaternion.slerp(q, Math.min(1, w)); }
        lift += ((s.lift || 0) - lift) * Math.min(1, w); }
      if (lift) for (const p in P) if (P[p].top) P[p].node.position.copy(P[p].restPos).addScaledVector(P[p].up, lift);
      return true;
    },
  };
})();
if (typeof window !== 'undefined') window.Poses = Poses;
