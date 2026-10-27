// VoidLux kit: skeleton rigs. A character as bones, parts drawn on the bones, and motions as keyframes tweened in between,
// with two-bone inverse kinematics for arms and legs. The look is the parts (yours); the motion is data any character
// with the same bones can play. A rig lives in assets/<key>.rig.js ("kind": "rig" in the manifest) as one call:
//
//   Rig.define('ninja', {
//     half: 24,                                   the radius the drawn figure fits in, like a code sprite
//     skin: { spine: { x, y, a, len }, … },       where each bone sat at rest when its part was drawn (written by the editor's
//                                                 edit-skeleton mode): a part is drawn there, so moving a joint moves the pivot,
//                                                 not the drawing
//     ground: 24,                                 where the feet stand, in the rig's space (y down; half = the box's bottom);
//                                                 Draw.rig(…) with a stand=true last argument puts that line at (x, y)
//     bones: {                                    name → { parent, x, y, len, angle }: the origin sits at the parent's tip
//       hips:   { parent: null, x: 0, y: 2, len: 6, angle: -1.5708 },      plus (x, y) in the parent's space; the root's
//       spine:  { parent: 'hips', len: 10, angle: 0 },                     (x, y) is where the figure's hips sit; angle is
//       head:   { parent: 'spine', len: 7, angle: 0 },                     the rest pose, radians, 0 = along the parent,
//       thighL: { parent: 'hips', x: -6, y: 0, len: 9, angle: 3.1416 },    measured so that a bone's own x axis runs from
//       shinL:  { parent: 'thighL', len: 9, angle: 0 }, …                  its joint to its tip
//     },
//     chains: { legL: { bones: ['thighL', 'shinL'], bend: 1, fold: [0, 150] }, … },  two-bone IK chains; bend 1 bows the joint
//                                                                          toward +x (a knee, forward), -1 toward -x (an elbow, back);
//                                                                          fold: how far the joint may close, degrees from straight
//                                                                          (0) to fully folded (180); default 0..155
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
    const rig = { key, half: r.half || 24, ground: typeof r.ground === 'number' ? r.ground : null, bones: {}, order: [], chains: r.chains || {}, parts: (r.parts || []).slice().sort((a, b) => (a.z || 0) - (b.z || 0)), motions: r.motions || {} };
    for (const [n, b] of Object.entries(r.bones || {})) rig.bones[n] = { name: n, parent: b.parent || null, x: b.x || 0, y: b.y || 0, len: b.len || 0, angle: b.angle || 0 };
    // parents before children, whatever the order written
    const seen = new Set(); const visit = n => { if (seen.has(n)) return; const b = rig.bones[n]; if (!b) throw new Error(`rig ${key}: bone ${n} is missing`); if (b.parent) visit(b.parent); seen.add(n); rig.order.push(n); };
    for (const n of Object.keys(rig.bones)) visit(n);
    for (const [cn, ch] of Object.entries(rig.chains)) if (!ch.bones || ch.bones.length !== 2 || !rig.bones[ch.bones[0]] || !rig.bones[ch.bones[1]] || rig.bones[ch.bones[1]].parent !== ch.bones[0]) throw new Error(`rig ${key}: chain ${cn} must be two bones, the second the child of the first`);
    // parts drawn when the bones sat elsewhere: the transform from the bone's rest frame now to the frame it was drawn in
    rig.comp = {}; if (r.skin) { const W0 = this.solve(rig, { pose: {}, root: { x: 0, y: 0 }, ik: {} }); for (const [n, s] of Object.entries(r.skin)) { const w = W0[n]; if (!w) continue; const dx = s.x - w.x, dy = s.y - w.y, c = Math.cos(-w.angle), sn = Math.sin(-w.angle); rig.comp[n] = { x: dx * c - dy * sn, y: dx * sn + dy * c, a: (s.a || 0) - w.angle, len: s.len }; } }
    this.rigs.set(key, rig); if (typeof Draw !== 'undefined' && Draw.sprites) for (const id of [...Draw.sprites.keys()]) if (id.startsWith(key + '#')) Draw.sprites.delete(id);
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
    // a key's fold: its own, or the rig's; but a key with no target for the chain is posed by angles, and its fold is wherever its elbow lies
    const foldOf = (key, i, c) => { const d = rig && rig.chains[c] ? (rig.chains[c].bend || 1) : 1; if (!rig || !rig.chains[c] || (key.ik && key.ik[c])) return this.bendAt(motion, i, c, d); return this.foldSide(rig, key, c, d); };
    for (const c of chains) { const d = rig && rig.chains[c] ? (rig.chains[c].bend || 1) : 1, fa = foldOf(a, ia0, c), fb = foldOf(b, ib0, c); if (fa === fb) { if (fa !== d) out.bend[c] = fa; } else out.bendMix[c] = { from: fa, to: fb, u: ease }; }
    for (const n of new Set([...Object.keys(pa), ...Object.keys(pb)])) { const x = pa[n] || 0, y = pb[n] || 0; let d = y - x; while (d > Math.PI) d -= TAU; while (d < -Math.PI) d += TAU; out.pose[n] = x + d * ease; }
    const ra = a.root || { x: 0, y: 0 }, rb = b.root || { x: 0, y: 0 }; out.root = { x: (ra.x || 0) + ((rb.x || 0) - (ra.x || 0)) * ease, y: (ra.y || 0) + ((rb.y || 0) - (ra.y || 0)) * ease };
    const ia = a.ik || {}, ib = b.ik || {};
    const fkEnd = (key, c) => { const r = key.root || { x: 0, y: 0 }, W = this.solve(rig, { pose: key.pose || {}, root: r, ik: {} }), e = W[rig.chains[c].bones[1]]; return { x: e.tx - r.x, y: e.ty - r.y }; };
    for (const c of new Set([...Object.keys(ia), ...Object.keys(ib)])) { let s = ia[c], t = ib[c]; if (rig && rig.chains[c]) { if (!s) s = fkEnd(a, c); if (!t) t = fkEnd(b, c); } else { s = s || t; t = t || s; } out.ik[c] = { x: s.x + (t.x - s.x) * ease, y: s.y + (t.y - s.y) * ease }; }
    return out;
  },
  // the fold of a chain at key i: the key's own, else the rig's (a fold is a property of a pose, not a switch that stays on)
  bendAt(motion, i, c, d) { const K = motion.keys || []; const k = K[i]; return (k && k.bend && k.bend[c]) || d; },
  // the side a posed (angle-driven) limb's joint lies on, as a fold sign: the IK fold that reproduces that elbow for the same end point
  foldSide(rig, key, c, d) {
    const ch = rig.chains[c], P0 = { pose: key.pose || {}, root: key.root || { x: 0, y: 0 }, ik: {} }, W0 = this.solve(rig, P0), A = W0[ch.bones[0]], E = W0[ch.bones[1]], r = P0.root;
    const T = { x: E.tx - r.x, y: E.ty - r.y }; let best = d, bd = Infinity;
    for (const s of [d, -d]) { const W = this.solve(rig, { pose: P0.pose, root: r, ik: { [c]: T }, bend: { [c]: s } }), dd = Math.hypot(W[ch.bones[0]].tx - A.tx, W[ch.bones[0]].ty - A.ty); if (dd < bd - 1e-6) { bd = dd; best = s; } }
    return best;
  },
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
      const tx = T.x + P.root.x, ty = T.y + P.root.y, dx = tx - A.x, dy = ty - A.y; let d = Math.max(1e-6, Math.min(l1 + l2 - 1e-4, Math.hypot(dx, dy)));
      // the joint may not close past its limit: the end then stops short of the target along the same line
      const lim = ch.fold || [0, 155], kneeMax = Math.PI - lim[0] * Math.PI / 180, kneeMin = Math.PI - lim[1] * Math.PI / 180, dMin = Math.sqrt(Math.max(0, l1 * l1 + l2 * l2 - 2 * l1 * l2 * Math.cos(kneeMin))), dMax = Math.sqrt(l1 * l1 + l2 * l2 - 2 * l1 * l2 * Math.cos(kneeMax));
      d = Math.max(Math.min(d, dMax), dMin);
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
    for (const part of rig.parts) { const w = W[part.bone]; if (!w) continue; g.save(); this.partFrame(rig, part, w, g); part.draw(g, this.partBone(rig, part, w), P); g.restore(); }
  },
  partFrame(rig, part, w, g) { g.translate(w.x, w.y); g.rotate(w.angle); const c = rig.comp && rig.comp[part.bone]; if (c) { g.translate(c.x, c.y); g.rotate(c.a); } },
  // the bone as the part sees it: the frame it is drawn in (origin, angle) and the length it was drawn for, so a part that reads w.angle (a foot kept level) or w.len stays right
  partBone(rig, part, w) { const c = rig.comp && rig.comp[part.bone]; if (!c) return w; const cs = Math.cos(w.angle), sn = Math.sin(w.angle); return Object.assign({}, w, { x: w.x + c.x * cs - c.y * sn, y: w.y + c.x * sn + c.y * cs, angle: w.angle + c.a, len: c.len !== undefined ? c.len : w.len }); },
  // the rest pose's world frame of every bone: { x, y, a, len, tx, ty }
  restWorld(rig) { const W = this.solve(rig, { pose: {}, root: { x: 0, y: 0 }, ik: {} }), out = {}; for (const [n, w] of Object.entries(W)) out[n] = { x: w.x, y: w.y, a: w.angle, len: w.len, tx: w.tx, ty: w.ty }; return out; },
  // bones rebuilt from joint positions: the root's origin and each bone's tip, in rest-pose world coordinates; a bone of zero length stays zero
  rebuild(def, rig, root, tips) {
    const W0 = this.restWorld(rig), W = {}, r = v => Math.round(v * 1000) / 1000;
    for (const n of rig.order) { const b = def.bones[n], par = b.parent ? W[b.parent] : null, pa = par ? par.a : 0; let ox, oy;
      if (!par) { ox = root.x; oy = root.y; b.x = r(ox); b.y = r(oy); } else { const c = Math.cos(pa), s = Math.sin(pa); ox = par.tx + (b.x || 0) * c - (b.y || 0) * s; oy = par.ty + (b.x || 0) * s + (b.y || 0) * c; }
      const zero = !(W0[n].len > 0), t = zero ? { x: ox, y: oy } : (tips[n] || { x: W0[n].tx, y: W0[n].ty }), dx = t.x - ox, dy = t.y - oy, len = Math.hypot(dx, dy), wa = len > 1e-6 ? Math.atan2(dy, dx) : W0[n].a;
      if (!zero) b.len = r(len); b.angle = Math.round((wa - pa) * 10000) / 10000; W[n] = { a: wa, tx: ox + Math.cos(wa) * (zero ? 0 : len), ty: oy + Math.sin(wa) * (zero ? 0 : len) }; }
  },
  // the skeleton itself, for editors: joints and bones over the figure
  bones(rig, motionName, phase) { return this.solve(rig, this.pose(rig.motions[motionName] || { keys: [] }, phase, rig)); },
  frames(motion) { return Math.max(2, Math.round((motion.secs || 1) * (motion.fps || 12))); },
};
