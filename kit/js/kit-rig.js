// VoidLux kit: skeleton rigs. A character as bones, parts drawn on the bones, and motions as keyframes tweened in between,
// with two-bone inverse kinematics for arms and legs. The look is the parts (yours); the motion is data any character
// with the same bones can play. A rig lives in assets/<key>.rig.js ("kind": "rig" in the manifest) as one call:
//
//   Rig.define('ninja', {
//     half: 24,                                   the radius the drawn figure fits in, like a code sprite
//     bones: {                                    name → { parent, x, y, len, angle }: the origin sits at the parent's tip
//       hips:   { parent: null, x: 0, y: 2, len: 6, angle: -1.5708 },      plus (x, y) in the parent's space; the root's
//       spine:  { parent: 'hips', len: 10, angle: 0 },                     (x, y) is where the figure's hips sit; angle is
//       head:   { parent: 'spine', len: 7, angle: 0 },                     the rest pose, radians, 0 = along the parent,
//       thighL: { parent: 'hips', x: -6, y: 0, len: 9, angle: 3.1416 },    measured so that a bone's own x axis runs from
//       shinL:  { parent: 'thighL', len: 9, angle: 0 }, …                  its joint to its tip
//     },
//     chains: { legL: { bones: ['thighL', 'shinL'], bend: 1 }, … },         two-bone IK chains; bend 1 bows the joint toward
//                                                                          +x (a knee, forward), -1 toward -x (an elbow, back)
//     parts: [ { bone: 'spine', z: 1, draw(g, b) { … } }, … ],             drawn in the bone's space: (0, 0) the joint, the
//                                                                          bone along +x to (b.len, 0); z orders back to front
//     motions: {
//       run: { secs: 0.6, fps: 12, loop: true, keys: [                      keys at t in 0..1; between them angles tween
//         { t: 0, pose: { thighL: 0.6, shinL: -0.4, armL: -0.5 }, root: { x: 0, y: -1 }, ik: { legR: { x: 4, y: 20 } }, bend: { armL: 1 } },
//                                                                          bend: a key may fold a chain the other way (an arm overhead); a key
//                                                                          without one uses the rig's fold, and the blend swings between folds
//         { t: 0.5, pose: { … }, root: { … } } ] },
//       idle: { … } },
//   });
//
// In the game: Draw.rig('ninja', 'run', Game.time, x, y, w, flip). Frames are painted once into the atlas like code sprites.
// A pose is angles added to the rest angles; a missing bone means 0; ik targets (in the rig's space, before root offset)
// win over the chain's posed angles.
const Rig = {
  rigs: new Map(),
  define(key, r) {
    const rig = { key, half: r.half || 24, bones: {}, order: [], chains: r.chains || {}, parts: (r.parts || []).slice().sort((a, b) => (a.z || 0) - (b.z || 0)), motions: r.motions || {} };
    for (const [n, b] of Object.entries(r.bones || {})) rig.bones[n] = { name: n, parent: b.parent || null, x: b.x || 0, y: b.y || 0, len: b.len || 0, angle: b.angle || 0 };
    // parents before children, whatever the order written
    const seen = new Set(); const visit = n => { if (seen.has(n)) return; const b = rig.bones[n]; if (!b) throw new Error(`rig ${key}: bone ${n} is missing`); if (b.parent) visit(b.parent); seen.add(n); rig.order.push(n); };
    for (const n of Object.keys(rig.bones)) visit(n);
    for (const [cn, ch] of Object.entries(rig.chains)) if (!ch.bones || ch.bones.length !== 2 || !rig.bones[ch.bones[0]] || !rig.bones[ch.bones[1]] || rig.bones[ch.bones[1]].parent !== ch.bones[0]) throw new Error(`rig ${key}: chain ${cn} must be two bones, the second the child of the first`);
    this.rigs.set(key, rig); if (typeof Draw !== 'undefined') for (const id of [...Draw.sprites.keys()]) if (id.startsWith(key + '#')) Draw.sprites.delete(id);
    return rig;
  },
  // the keyframes around a phase, blended: angles by shortest arc, root and ik targets by straight lerp. A chain with a target
  // in one key and none in the other blends toward where the posed limb ends in that other key (given the rig to solve it).
  pose(motion, phase, rig) {
    const K = motion.keys || []; if (!K.length) return { pose: {}, root: { x: 0, y: 0 }, ik: {} };
    const loop = motion.loop !== false; let p = loop ? phase - Math.floor(phase) : Math.min(1, Math.max(0, phase));
    let a = K[K.length - 1], b = K[0], span = 1 - a.t + b.t, u = 0;
    for (let i = 0; i < K.length; i++) { const k = K[i], nx = K[i + 1]; if (p >= k.t && (!nx || p < nx.t)) { a = k; b = nx || (loop ? K[0] : k); span = nx ? nx.t - k.t : (loop ? 1 - k.t + K[0].t : 1); u = span > 0 ? (p - k.t) / span : 0; break; } }
    const ease = (a.ease || motion.ease || 'io') === 'lin' ? u : (u < 0.5 ? 2 * u * u : 1 - Math.pow(-2 * u + 2, 2) / 2);
    const out = { pose: {}, root: { x: 0, y: 0 }, ik: {}, bend: {}, bendMix: {} }, pa = a.pose || {}, pb = b.pose || {};
    // a fold that differs between the two keys swings the joint round through a straight limb (solve() blends the elbow point)
    const ia0 = K.indexOf(a), ib0 = K.indexOf(b), chains = new Set([...(rig ? Object.keys(rig.chains) : [])]); for (const k of K) for (const c of Object.keys(k.bend || {})) chains.add(c);
    for (const c of chains) { const d = rig && rig.chains[c] ? (rig.chains[c].bend || 1) : 1, fa = this.bendAt(motion, ia0, c, d), fb = this.bendAt(motion, ib0, c, d); if (fa === fb) { if (fa !== d) out.bend[c] = fa; } else out.bendMix[c] = { from: fa, to: fb, u: ease }; }
    for (const n of new Set([...Object.keys(pa), ...Object.keys(pb)])) { const x = pa[n] || 0, y = pb[n] || 0; let d = y - x; while (d > Math.PI) d -= TAU; while (d < -Math.PI) d += TAU; out.pose[n] = x + d * ease; }
    const ra = a.root || { x: 0, y: 0 }, rb = b.root || { x: 0, y: 0 }; out.root = { x: (ra.x || 0) + ((rb.x || 0) - (ra.x || 0)) * ease, y: (ra.y || 0) + ((rb.y || 0) - (ra.y || 0)) * ease };
    const ia = a.ik || {}, ib = b.ik || {};
    const fkEnd = (key, c) => { const r = key.root || { x: 0, y: 0 }, W = this.solve(rig, { pose: key.pose || {}, root: r, ik: {} }), e = W[rig.chains[c].bones[1]]; return { x: e.tx - r.x, y: e.ty - r.y }; };
    for (const c of new Set([...Object.keys(ia), ...Object.keys(ib)])) { let s = ia[c], t = ib[c]; if (rig && rig.chains[c]) { if (!s) s = fkEnd(a, c); if (!t) t = fkEnd(b, c); } else { s = s || t; t = t || s; } out.ik[c] = { x: s.x + (t.x - s.x) * ease, y: s.y + (t.y - s.y) * ease }; }
    return out;
  },
  // the fold of a chain at key i: the key's own, else the rig's (a fold is a property of a pose, not a switch that stays on)
  bendAt(motion, i, c, d) { const K = motion.keys || []; const k = K[i]; return (k && k.bend && k.bend[c]) || d; },
  keyBend(motion, i, rig) { const out = {}; for (const c of Object.keys(rig.chains)) { const d = rig.chains[c].bend || 1, v = this.bendAt(motion, i, c, d); if (v !== d) out[c] = v; } return out; },
  // world transforms for a pose: each bone's origin, world angle and tip; then IK bends the chains that have targets
  solve(rig, P) {
    const W = {};
    const place = n => { const b = rig.bones[n], par = b.parent ? W[b.parent] : null, pa = par ? par.angle : 0, ox = par ? par.tx : 0, oy = par ? par.ty : 0;
      const cs = Math.cos(pa), sn = Math.sin(pa), x = ox + b.x * cs - b.y * sn + (par ? 0 : P.root.x), y = oy + b.x * sn + b.y * cs + (par ? 0 : P.root.y), ang = pa + b.angle + (P.pose[n] || 0);
      W[n] = { x, y, angle: ang, len: b.len, tx: x + Math.cos(ang) * b.len, ty: y + Math.sin(ang) * b.len }; };
    for (const n of rig.order) place(n);
    for (const [cn, ch] of Object.entries(rig.chains)) {
      const T = P.ik[cn]; if (!T) continue; const [an, bn] = ch.bones, A = W[an], B = W[bn], l1 = A.len, l2 = B.len;
      const tx = T.x + P.root.x, ty = T.y + P.root.y, dx = tx - A.x, dy = ty - A.y, d = Math.max(1e-6, Math.min(l1 + l2 - 1e-4, Math.hypot(dx, dy)));
      const cosK = (l1 * l1 + l2 * l2 - d * d) / (2 * l1 * l2), knee = Math.acos(Math.max(-1, Math.min(1, cosK))), cosA = (l1 * l1 + d * d - l2 * l2) / (2 * l1 * d), alpha = Math.acos(Math.max(-1, Math.min(1, cosA)));
      const mix = P.bendMix && P.bendMix[cn], base = Math.atan2(dy, dx); let a1, a2;
      if (mix) { // between two folds: the elbow travels from one solution's elbow to the other's, so the limb straightens and re-bends instead of jumping
        const e = s => { const q = base - s * alpha; return [A.x + Math.cos(q) * l1, A.y + Math.sin(q) * l1]; }, [x1, y1] = e(mix.from), [x2, y2] = e(mix.to), ex = x1 + (x2 - x1) * mix.u, ey = y1 + (y2 - y1) * mix.u;
        a1 = Math.atan2(ey - A.y, ex - A.x); const jx = A.x + Math.cos(a1) * l1, jy = A.y + Math.sin(a1) * l1; a2 = Math.atan2(ty - jy, tx - jx);
      } else { const bend = (P.bend && P.bend[cn]) || ch.bend || 1; a1 = base - bend * alpha; a2 = a1 + bend * (Math.PI - knee); }
      A.angle = a1; A.tx = A.x + Math.cos(a1) * l1; A.ty = A.y + Math.sin(a1) * l1; B.x = A.tx; B.y = A.ty; B.angle = a2; B.tx = B.x + Math.cos(a2) * l2; B.ty = B.y + Math.sin(a2) * l2;
      // the children of the chain's end (a foot, a hand) follow the new angle
      const fix = pn => { for (const n of rig.order) { const b = rig.bones[n]; if (b.parent !== pn) continue; const par = W[pn], cs = Math.cos(par.angle), sn = Math.sin(par.angle), w = W[n]; w.x = par.tx + b.x * cs - b.y * sn; w.y = par.ty + b.x * sn + b.y * cs; w.angle = par.angle + b.angle + (P.pose[n] || 0); w.tx = w.x + Math.cos(w.angle) * b.len; w.ty = w.y + Math.sin(w.angle) * b.len; fix(n); } };
      fix(bn);
    }
    return W;
  },
  // paint the figure at a phase of a motion on g, (0, 0) its centre, as a code sprite's paint would
  paint(rig, motionName, phase, g) {
    const motion = rig.motions[motionName] || rig.motions[Object.keys(rig.motions)[0]] || { keys: [] };
    const P = this.pose(motion, phase, rig), W = this.solve(rig, P);
    for (const part of rig.parts) { const w = W[part.bone]; if (!w) continue; g.save(); g.translate(w.x, w.y); g.rotate(w.angle); part.draw(g, w, P); g.restore(); }
  },
  // the skeleton itself, for editors: joints and bones over the figure
  bones(rig, motionName, phase) { return this.solve(rig, this.pose(rig.motions[motionName] || { keys: [] }, phase, rig)); },
  frames(motion) { return Math.max(2, Math.round((motion.secs || 1) * (motion.fps || 12))); },
};
