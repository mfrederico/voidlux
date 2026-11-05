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
// From a 2D rig (kit-rig.js, a side view facing right): which bone is which part, where its joints stand, and its motions as key poses.
//   Poses.bonesOf(rig)                         { torso: 'spine', thighL: 'kneeL', … }: a bone named for the joint it ends at (kneeL: the thigh)
//                                              or for the segment it is (thighL); neck and shoulder bones fold into what they carry
//   Poses.rigJoints(Rig, rig)                  [{ part, at: [forward, up] }]: each part's joint at rest, as fractions of the figure's height
//   Poses.rigMotions(Rig, rig, { axes, height, names })   { name: { loop, seconds, keys } }: each motion sampled (8 keys a loop, 7 a one-off),
//                                              every bone's turn relative to its part's parent, as a turn about the model's side axis (axes: which way
//                                              the root part's x, y, z point, as the pose editor measures them); the hips' rise and fall as lift
//                                              (height: the model's height in its lift units). Feet the 2D rig held by IK may slide between keys.
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
  const FROM_2D = { torso: ['spine', 'torso', 'chest', 'body'], head: ['head'], upperArmL: ['elbowL', 'uarmL', 'upperArmL', 'armL'], lowerArmL: ['handL', 'farmL', 'lowerArmL', 'forearmL'],
    upperArmR: ['elbowR', 'uarmR', 'upperArmR', 'armR'], lowerArmR: ['handR', 'farmR', 'lowerArmR', 'forearmR'], thighL: ['kneeL', 'thighL', 'legL'], shinL: ['footL', 'shinL'],
    thighR: ['kneeR', 'thighR', 'legR'], shinR: ['footR', 'shinR'] };
  const PARENT = { torso: '', head: 'torso', upperArmL: 'torso', lowerArmL: 'upperArmL', upperArmR: 'torso', lowerArmR: 'upperArmR', thighL: 'torso', shinL: 'thighL', thighR: 'torso', shinR: 'thighR' };
  const wrap = a => { while (a > Math.PI) a -= 2 * Math.PI; while (a < -Math.PI) a += 2 * Math.PI; return a; }, r4 = v => Math.round(v * 1e4) / 1e4;
  function bonesOf(rig) { const out = {}; for (const [p, c] of Object.entries(FROM_2D)) { const b = c.find(n => rig.bones[n]); if (b) out[p] = b; } return out; }
  /* The 3D model's rest seen from its side, as page angles (x forward, y down): torso and head up, arms and legs hanging. Each turn is measured
     against it, not against the 2D rig's own rest (a rig may rest in a wide stance: the ninja's does). */
  const UP = -Math.PI / 2, DOWN = Math.PI / 2, REST3D = { torso: UP, head: UP, upperArmL: DOWN, lowerArmL: DOWN, upperArmR: DOWN, lowerArmR: DOWN, thighL: DOWN, shinL: DOWN, thighR: DOWN, shinR: DOWN };
  function rigFrame(Rig, rig) { // the rest pose; the figure's standing height (the head's top to the feet with straight legs) and its leg length
    const W = Rig.bones(rig, '', 0), root = rig.order[0], B = bonesOf(rig); let top = Infinity; for (const w of Object.values(W)) top = Math.min(top, w.y, w.ty);
    const leg = Math.max(...['L', 'R'].map(x => (B['thigh' + x] ? W[B['thigh' + x]].len : 0) + (B['shin' + x] ? W[B['shin' + x]].len : 0)), 1e-6);
    const ground = W[root].y + leg; return { W, root, B, top, ground, leg, h: Math.max(1e-6, ground - top) }; }
  function rigJoints(Rig, rig) { // where each part turns, standing straight: legs below the hips, forearms below the shoulders, the rest as the rig rests
    const F = rigFrame(Rig, rig), B = F.B, x0 = F.W[F.root].x, at = (x, y) => [r4((x - x0) / F.h), r4((F.ground - y) / F.h)], out = [];
    for (const part of Object.keys(B)) { const w = F.W[B[part]];
      if (part === 'torso' || part === 'thighL' || part === 'thighR') out.push({ part, at: at(x0, F.W[F.root].y) });
      else if (part === 'shinL' || part === 'shinR') out.push({ part, at: at(x0, F.W[F.root].y + (B['thigh' + part.slice(-1)] ? F.W[B['thigh' + part.slice(-1)]].len : 0)) });
      else if (part === 'lowerArmL' || part === 'lowerArmR') { const ua = B['upperArm' + part.slice(-1)], sh = ua ? F.W[ua] : w; out.push({ part, at: at(sh.x, sh.y + (ua ? F.W[ua].len : 0)) }); } // an elbow below its shoulder: the model rests with its arms down
      else out.push({ part, at: at(w.x, w.y) }); }
    return out; }
  function rigMotions(Rig, rig, o) {
    const F = rigFrame(Rig, rig), B = F.B, ax = o.axes || {}, side = ['x', 'y', 'z'].find(k => ax[k] === 'right' || ax[k] === 'left'); if (!side) throw new Error('the model\'s side axis is not known');
    const sign = ax[side] === 'right' ? 1 : -1, idx = 'xyz'.indexOf(side), rest = Object.assign({}, REST3D, o.rest || {}), out = {};
    const rel = (A, part) => { const pp = PARENT[part]; return pp && A[pp] !== undefined ? A[part] - A[pp] : A[part]; };
    for (const name of o.names || Object.keys(rig.motions || {})) { const M = rig.motions[name]; if (!M) continue; const loop = M.loop !== false, n = loop ? 8 : 7, keys = [];
      for (let i = 0; i < n; i++) { const t = loop ? i / n : i / (n - 1), W = Rig.bones(rig, name, t), A = {}, rot = {};
        for (const part of Object.keys(B)) A[part] = W[B[part]].angle;
        for (const part of Object.keys(B)) { const d = wrap(rel(A, part) - rel(rest, part)); if (Math.abs(d) < 0.01) continue; const v = [0, 0, 0]; v[idx] = r4(-d * sign); rot[part] = v; } // a turn on the page (y down) is the opposite turn about the figure's right
        const k = { t: r4(t), rot }, lift = -(W[F.root].y - F.W[F.root].y) / F.h * (o.height || 1); if (Math.abs(lift) > 1e-4) k.lift = r4(lift); keys.push(k); }
      out[name] = { loop, seconds: Math.max(0.05, M.secs || 1), keys }; }
    return out; }
  return {
    FROM_2D, PARENT, bonesOf, rigJoints, rigMotions,
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
