// Move: the motion workspace of the sprite page (loaded after the page's own script; it uses its globals: el, cur, entry,
// ASSET, apiUrl, apiHeaders, say, esc, loadImage, renderCode, Draw, Rig, STYLE, SK, remember, showOut, pollSketch, showTab).
//
// For a drawn sprite (svg, png, code) it is the place to mark the skeleton for the rigger: click to place joints, name
// them, connect them, note what each is for; the marks go with the rig job as exact positions, the way notes go to the
// builder. For a kept rig it is the animation table: motions, a timeline of keys with a cursor that shows the tween, and
// posing on the stage with IK for hands and feet; Save writes the motions back into the rig's file.
const Move = {
  N: 448, half: 24, k: 1, kind: 'none', base: null, joints: [], sel: -1, adding: false, drag: null,
  R: null, text: '', motion: '', keyI: -1, scrub: 0, playing: null, hist: [], redoS: [],
  PLANS: {
    biped: ['hips', 'spine', 'neck', 'head', 'shoulderL', 'elbowL', 'handL', 'shoulderR', 'elbowR', 'handR', 'kneeL', 'footL', 'kneeR', 'footR', 'scarf1', 'scarf2', 'tail1', 'tail2'],
    quadruped: ['hips', 'spine', 'shoulders', 'neck', 'head', 'frontKneeL', 'frontFootL', 'frontKneeR', 'frontFootR', 'backKneeL', 'backFootL', 'backKneeR', 'backFootR', 'tail1', 'tail2', 'tail3'],
    winged: ['hips', 'spine', 'neck', 'head', 'shoulderL', 'elbowL', 'handL', 'shoulderR', 'elbowR', 'handR', 'kneeL', 'footL', 'kneeR', 'footR', 'wingL1', 'wingL2', 'wingR1', 'wingR2', 'tail1'],
    serpent: ['head', 'spine1', 'spine2', 'spine3', 'spine4', 'spine5', 'spine6', 'tail'],
    other: [],
  },
  // where each named joint hangs in its plan: a new joint attaches there when that joint is placed, else to the selected one
  TREE: {
    biped: { spine: 'hips', neck: 'spine', head: 'neck', shoulderL: 'neck', elbowL: 'shoulderL', handL: 'elbowL', shoulderR: 'neck', elbowR: 'shoulderR', handR: 'elbowR', kneeL: 'hips', footL: 'kneeL', kneeR: 'hips', footR: 'kneeR', scarf1: 'head', scarf2: 'scarf1', tail1: 'hips', tail2: 'tail1' },
    quadruped: { spine: 'hips', shoulders: 'spine', neck: 'shoulders', head: 'neck', frontKneeL: 'shoulders', frontFootL: 'frontKneeL', frontKneeR: 'shoulders', frontFootR: 'frontKneeR', backKneeL: 'hips', backFootL: 'backKneeL', backKneeR: 'hips', backFootR: 'backKneeR', tail1: 'hips', tail2: 'tail1', tail3: 'tail2' },
    winged: { spine: 'hips', neck: 'spine', head: 'neck', shoulderL: 'neck', elbowL: 'shoulderL', handL: 'elbowL', shoulderR: 'neck', elbowR: 'shoulderR', handR: 'elbowR', kneeL: 'hips', footL: 'kneeL', kneeR: 'hips', footR: 'kneeR', wingL1: 'spine', wingL2: 'wingL1', wingR1: 'spine', wingR2: 'wingR1', tail1: 'hips' },
    serpent: { spine1: 'head', spine2: 'spine1', spine3: 'spine2', spine4: 'spine3', spine5: 'spine4', spine6: 'spine5', tail: 'spine6' },
    other: {},
  },
  parentFor(name) { const want = (this.TREE[this.plan] || {})[name]; if (want && this.joints.some(j => j.name === want)) return want; if (this.sel >= 0 && this.joints[this.sel]) return this.joints[this.sel].name; return this.joints.length ? this.joints[this.joints.length - 1].name : ''; },
  init() {
    this.cv = el('moveCv'); this.g = this.cv.getContext('2d');
    this.cv.addEventListener('pointerdown', e => this.down(e)); this.cv.addEventListener('pointermove', e => this.move(e)); const up = () => { this.drag = null; }; this.cv.addEventListener('pointerup', up); this.cv.addEventListener('pointercancel', up);
    el('hintPlan').onchange = () => { this.plan = el('hintPlan').value; this.names(); this.draw(); };
    el('hintAdd').onclick = () => { this.adding = !this.adding; el('hintAdd').classList.toggle('on', this.adding); el('hintMsg').textContent = this.adding ? 'click the figure to place a joint; click a joint to select it; drag to move' : ''; };
    el('hintClear').onclick = () => { if (!this.joints.length || confirm('Clear every mark?')) { this.snapshot(); this.joints = []; this.sel = -1; this.hintsUI(); this.draw(); } };
    el('hintSave').onclick = () => this.saveHints(true);
    el('rigGoBtn').onclick = () => this.rigGo();
    el('mMotion').onchange = () => this.pickMotion(el('mMotion').value); el('mNew').onclick = () => this.newMotion(); el('mDel').onclick = () => this.delMotion();
    el('mDup').onclick = () => this.dupMotion(); el('mRename').onclick = () => this.renameMotion(); el('mAsk').onclick = () => { if (cur && entry) openAnim({ asset: cur }, cur); };
    for (const id of ['mSecs', 'mFps', 'mLoop']) el(id).onchange = () => { const M = this.M(); if (!M) return; this.snapshot(); M.secs = Math.max(0.1, +el('mSecs').value || 1); M.fps = Math.max(2, Math.min(30, +el('mFps').value || 12)); M.loop = el('mLoop').checked; this.tlUI(); this.draw(); };
    el('mBones').onchange = () => this.draw();
    el('mPlay').onclick = () => this.play(); el('kFlip').onclick = () => this.flipBend(); el('kAdd').onclick = () => this.addKey(); el('kDup').onclick = () => this.dupKey(); el('kDel').onclick = () => this.delKey(); el('kMirror').onclick = () => this.mirrorKey();
    el('kT').onchange = () => { const K = this.K(); if (!K) return; this.snapshot(); K.t = Math.max(0, Math.min(1, +el('kT').value || 0)); this.M().keys.sort((a, b) => a.t - b.t); this.keyI = this.M().keys.indexOf(K); this.tlUI(); this.draw(); };
    el('kEase').onchange = () => { const K = this.K(); if (K) { this.snapshot(); K.ease = el('kEase').value; } };
    el('tlScrub').oninput = () => { this.scrub = +el('tlScrub').value / 1000; this.keyI = -1; this.tlUI(); this.draw(); };
    el('rigSave').onclick = () => this.saveRig(); el('mUndo').onclick = () => this.undo(); el('mRedo').onclick = () => this.redo();
    document.addEventListener('keydown', e => { if (el('tab-move').classList.contains('hidden') || e.target.matches('input, textarea, select')) return; const k = e.key.toLowerCase(); if ((e.ctrlKey || e.metaKey) && k === 'z') { e.preventDefault(); e.shiftKey ? this.redo() : this.undo(); } else if ((e.ctrlKey || e.metaKey) && k === 'y') { e.preventDefault(); this.redo(); } });
    window.addEventListener('resize', () => this.draw());
  },
  // ---- undo: a snapshot of the motions (or the marks) before every change; Ctrl+Z back, Ctrl+Y forward ----
  snapshot() { const s = JSON.stringify({ motions: this.R ? this.R.motions : null, motion: this.motion, keyI: this.keyI, joints: this.joints, plan: this.plan }); if (this.hist[this.hist.length - 1] === s) return; this.hist.push(s); if (this.hist.length > 100) this.hist.shift(); this.redoS = []; },
  current() { return JSON.stringify({ motions: this.R ? this.R.motions : null, motion: this.motion, keyI: this.keyI, joints: this.joints, plan: this.plan }); },
  restore(s) { const d = JSON.parse(s); this.stop(); if (this.R && d.motions) { this.R.motions = d.motions; this.motionList(d.motions[d.motion] ? d.motion : Object.keys(d.motions)[0] || ''); this.keyI = Math.min(d.keyI, (this.M() ? this.M().keys.length : 0) - 1); if (this.keyI >= 0) { this.scrub = this.M().keys[this.keyI].t; el('tlScrub').value = Math.round(this.scrub * 1000); } this.tlUI(); } if (this.kind === 'pic') { this.joints = d.joints || []; this.plan = d.plan || this.plan; el('hintPlan').value = this.plan; this.names(); this.hintsUI(); } this.draw(); },
  undo() { if (!this.hist.length) { say('nothing to undo on Move', ''); return; } this.redoS.push(this.current()); this.restore(this.hist.pop()); say('undone', 'ok'); },
  redo() { if (!this.redoS.length) return; this.hist.push(this.current()); this.restore(this.redoS.pop()); say('redone', 'ok'); },
  // ---- what is on the stage ----
  async refresh() {
    if (!this.cv) this.init(); this.stop();
    const has = !!(cur && entry); el('moveTitle').textContent = has ? cur : 'no sprite chosen'; el('moveAnimBtn').disabled = !has; el('rigGoBtn').disabled = !has;
    el('hintsPanel').classList.toggle('hidden', !has || entry.kind === 'rig'); el('rigPanel').classList.toggle('hidden', !(has && entry.kind === 'rig')); el('moveTl').classList.toggle('hidden', !(has && entry.kind === 'rig'));
    this.kind = !has ? 'none' : (entry.kind === 'rig' ? 'rig' : 'pic'); this.base = null; this.R = null; this.joints = []; this.sel = -1; this.hist = []; this.redoS = [];
    if (!has) { el('moveSub').textContent = 'pick a kept sprite on the left to give it motion'; this.draw(); return; }
    try {
      if (this.kind === 'rig') {
        this.text = await (await fetch(ASSET(entry.file) + '?t=' + Date.now(), { cache: 'no-store' })).text();
        this.R = this.evalRig(this.text); this.half = this.R.half; const names = Object.keys(this.R.motions);
        el('moveSub').textContent = `a rig: ${Object.keys(this.R.bones).length} bones, ${Object.keys(this.R.chains).length} IK chains, ${this.R.parts.length} parts`;
        el('mMotion').innerHTML = names.map(n => `<option>${esc(n)}</option>`).join(''); this.pickMotion(names[0] || '');
      } else {
        this.half = Math.max(8, (entry.w || 48) / 2); const px = Math.round(this.N * 0.9);
        if (entry.kind === 'code' || /\.js$/i.test(entry.file)) { const t = await (await fetch(ASSET(entry.file) + '?t=' + Date.now(), { cache: 'no-store' })).text(); this.base = renderCode(t, px, 0).canvas; }
        else { const im = await loadImage(ASSET(entry.file) + '?t=' + Date.now()); const c = document.createElement('canvas'); c.width = c.height = px; const g = c.getContext('2d'); g.imageSmoothingEnabled = !/\.png$/i.test(entry.file); const s = Math.min(px / im.naturalWidth, px / im.naturalHeight), w = im.naturalWidth * s, h = im.naturalHeight * s; g.drawImage(im, (px - w) / 2, (px - h) / 2, w, h); this.base = c; }
        el('moveSub').textContent = 'mark its bones for the rigger on the right, or let the artist animate it as code frames';
        const h = await (await fetch(apiUrl('spritehints') + '?key=' + encodeURIComponent(cur), { cache: 'no-store' })).json(); this.plan = h.plan || 'biped'; this.joints = Array.isArray(h.joints) ? h.joints : []; el('hintPlan').value = this.plan; el('rigNotes').value = h.notes || ''; this.names(); this.hintsUI();
        const codeBase = entry.kind === 'code' || /\.js$/i.test(entry.file); el('rigKey').value = codeBase ? cur + '-rig' : cur; el('rigKeyWhy').textContent = codeBase ? `a new key, so the coded ${cur} stays; use ${cur} to replace it` : `the rig takes the sprite's place under ${cur}; change it to keep both`;
        let rj = null; try { rj = JSON.parse(localStorage.getItem('vl.sprites.' + GAME + '.rigjob') || 'null'); } catch (e) {} if (rj && rj.key === cur) this.watch(rj.id, rj.key, 'picking the rig job up again…'); else el('moveOut').classList.add('hidden');
      }
    } catch (e) { el('moveSub').textContent = 'could not load it: ' + e.message; }
    this.draw();
  },
  evalRig(text) { let def = null, name = ''; const real = Rig.define; Rig.define = (k, r) => { def = r; name = k; }; try { new Function('Rig', 'Draw', 'TAU', 'U', text)(Rig, Draw, TAU, U); } finally { Rig.define = real; } if (!def) throw new Error('no Rig.define in the file'); const key = 'move:' + cur, R = Rig.define(key, def); R.realKey = name; if (name && name !== key) Rig.rigs.set(name, R); return R; },
  names() { el('boneNames').innerHTML = (this.PLANS[this.plan] || []).map(n => `<option value="${n}">`).join(''); },
  // ---- the stage ----
  sx(x) { return this.N / 2 + x * this.k; }, sy(y) { return this.N / 2 + y * this.k; },
  world(e) { const r = this.cv.getBoundingClientRect(); return { x: ((e.clientX - r.left) / r.width * this.N - this.N / 2) / this.k, y: ((e.clientY - r.top) / r.height * this.N - this.N / 2) / this.k }; },
  draw() {
    const g = this.g, N = this.N; g.clearRect(0, 0, N, N); this.k = N * 0.9 / (2 * this.half);
    if (this.kind === 'none') { g.fillStyle = 'rgba(128,128,128,.5)'; g.font = '14px sans-serif'; g.textAlign = 'center'; g.fillText('pick a kept sprite on the left', N / 2, N / 2); return; }
    if (this.kind === 'pic') { if (this.base) g.drawImage(this.base, (N - this.base.width) / 2, (N - this.base.height) / 2); this.drawHints(); return; }
    const P = this.poseNow(), W = Rig.solve(this.R, P);
    g.save(); g.translate(N / 2, N / 2); g.scale(this.k, this.k); const was = Draw.ctx; Draw.ctx = g;
    for (const part of this.R.parts) { const w = W[part.bone]; if (!w) continue; g.save(); g.translate(w.x, w.y); g.rotate(w.angle); try { part.draw(g, w, P); } catch (e) {} g.restore(); }
    Draw.ctx = was; g.restore();
    if (el('mBones').checked) this.drawBones(W, P);
  },
  drawBones(W, P) {
    const g = this.g, ends = new Set(Object.values(this.R.chains).map(c => c.bones[1])), roots = Object.values(this.R.bones).filter(b => !b.parent).map(b => b.name);
    g.lineWidth = 2; g.strokeStyle = 'rgba(255,64,160,.85)';
    for (const w of Object.values(W)) { g.beginPath(); g.moveTo(this.sx(w.x), this.sy(w.y)); g.lineTo(this.sx(w.tx), this.sy(w.ty)); g.stroke(); }
    for (const [n, w] of Object.entries(W)) { const end = ends.has(n), root = roots.includes(n); g.beginPath(); g.arc(this.sx(w.tx), this.sy(w.ty), end ? 7 : 4.5, 0, TAU); g.fillStyle = end ? 'rgba(255,209,102,.95)' : 'rgba(255,64,160,.9)'; g.fill(); if (end) { g.strokeStyle = '#1d1530'; g.lineWidth = 1.5; g.stroke(); g.lineWidth = 2; g.strokeStyle = 'rgba(255,64,160,.85)'; }
      if (root) { g.beginPath(); g.arc(this.sx(w.x), this.sy(w.y), 8, 0, TAU); g.strokeStyle = 'rgba(124,180,204,.95)'; g.lineWidth = 2.5; g.stroke(); g.strokeStyle = 'rgba(255,64,160,.85)'; g.lineWidth = 2; } }
  },
  drawHints() {
    const g = this.g; g.lineWidth = 2.5; g.strokeStyle = 'rgba(255,64,160,.85)'; g.font = '11px ui-monospace, monospace'; g.textAlign = 'left';
    this.joints.forEach((j, i) => { const p = this.joints.find(o => o.name === j.parent); if (p) { g.beginPath(); g.moveTo(this.sx(p.x), this.sy(p.y)); g.lineTo(this.sx(j.x), this.sy(j.y)); g.stroke(); } });
    this.joints.forEach((j, i) => { g.beginPath(); g.arc(this.sx(j.x), this.sy(j.y), i === this.sel ? 7 : 5, 0, TAU); g.fillStyle = i === this.sel ? 'rgba(255,209,102,.95)' : (j.parent ? 'rgba(255,64,160,.9)' : 'rgba(124,180,204,.95)'); g.fill(); g.strokeStyle = '#1d1530'; g.lineWidth = 1.2; g.stroke(); g.strokeStyle = 'rgba(255,64,160,.85)'; g.lineWidth = 2.5;
      g.fillStyle = '#1d1530'; g.fillText(j.name, this.sx(j.x) + 8, this.sy(j.y) - 6); g.fillStyle = '#ffd166'; g.fillText(j.name, this.sx(j.x) + 7, this.sy(j.y) - 7); });
  },
  // ---- hints: joints and segments for the rigger ----
  hitJoint(p) { let best = -1, bd = 10 / this.k; this.joints.forEach((j, i) => { const d = Math.hypot(j.x - p.x, j.y - p.y); if (d < bd) { bd = d; best = i; } }); return best; },
  down(e) {
    this.cv.setPointerCapture(e.pointerId); const p = this.world(e);
    if (this.kind === 'pic') {
      const h = this.hitJoint(p);
      if (h >= 0) { this.sel = h; this.snapshot(); this.drag = { joint: h }; this.hintsUI(); this.draw(); return; }
      if (!this.adding) return;
      const used = new Set(this.joints.map(j => j.name)), name = (this.PLANS[this.plan] || []).find(n => !used.has(n)) || ('joint' + (this.joints.length + 1));
      this.snapshot(); const parent = this.joints.length ? this.parentFor(name) : '';
      this.joints.push({ name, x: Math.round(p.x * 10) / 10, y: Math.round(p.y * 10) / 10, parent, note: '' }); this.sel = this.joints.length - 1; this.hintsUI(); this.draw();
      const nextName = (this.PLANS[this.plan] || []).find(n => !this.joints.some(j => j.name === n)); el('hintMsg').textContent = nextName ? `placed ${name}${parent ? ' from ' + parent : ' (root)'} · next: ${nextName}${(this.TREE[this.plan] || {})[nextName] ? ' from ' + this.TREE[this.plan][nextName] : ''}` : `placed ${name}`; return;
    }
    if (this.kind === 'rig') { const K = this.K(); if (!K) { say('select a key on the timeline first (or add one at the cursor) to pose it', 'err'); return; } this.drag = this.hitBone(p); if (this.drag) this.snapshot(); }
  },
  move(e) {
    if (!this.drag) return; const p = this.world(e);
    if (this.kind === 'pic') { const j = this.joints[this.drag.joint]; j.x = Math.round(p.x * 10) / 10; j.y = Math.round(p.y * 10) / 10; this.draw(); return; }
    const K = this.K(); if (!K) return; const d = this.drag;
    if (d.root) { K.root = { x: Math.round((p.x - d.ox) * 10) / 10, y: Math.round((p.y - d.oy) * 10) / 10 }; }
    else if (d.chain) { const r = K.root || { x: 0, y: 0 }; K.ik = K.ik || {}; K.ik[d.chain] = { x: Math.round((p.x - r.x) * 10) / 10, y: Math.round((p.y - r.y) * 10) / 10 }; this.lastChain = d.chain; }
    else if (d.bone) { const W = Rig.solve(this.R, this.poseNow()), w = W[d.bone], b = this.R.bones[d.bone], par = b.parent ? W[b.parent].angle : 0; K.pose = K.pose || {}; K.pose[d.bone] = Math.round((Math.atan2(p.y - w.y, p.x - w.x) - par - b.angle) * 1000) / 1000; if (K.ik && d.chainOf) delete K.ik[d.chainOf]; }
    this.draw();
  },
  hitBone(p) { // what a press on the posed figure takes hold of: a chain's end (IK), the root (move), or a bone's tip (turn)
    const W = Rig.solve(this.R, this.poseNow()), r = 10 / this.k, P = this.poseNow();
    for (const [cn, ch] of Object.entries(this.R.chains)) { const w = W[ch.bones[1]]; if (Math.hypot(w.tx - p.x, w.ty - p.y) < r) return { chain: cn }; }
    for (const b of Object.values(this.R.bones)) if (!b.parent) { const w = W[b.name]; if (Math.hypot(w.x - p.x, w.y - p.y) < r) return { root: true, ox: p.x - P.root.x, oy: p.y - P.root.y }; }
    let best = null, bd = r; for (const [n, w] of Object.entries(W)) { const d = Math.hypot(w.tx - p.x, w.ty - p.y); if (d < bd) { bd = d; best = n; } }
    if (!best) return null; const chainOf = Object.entries(this.R.chains).find(([, ch]) => ch.bones.includes(best)); return { bone: best, chainOf: chainOf ? chainOf[0] : null };
  },
  hintsUI() {
    const L = el('hintList'); L.innerHTML = '';
    this.joints.forEach((j, i) => {
      const d = document.createElement('div'); d.className = 'hint' + (i === this.sel ? ' on' : '');
      d.innerHTML = `<input value="${esc(j.name)}" list="boneNames" placeholder="name" title="the bone that ends at this joint"><select title="attached to"><option value="">(root)</option>${this.joints.filter(o => o !== j).map(o => `<option${o.name === j.parent ? ' selected' : ''}>${esc(o.name)}</option>`).join('')}</select><button class="x danger" title="remove">✕</button><input class="note" value="${esc(j.note || '')}" placeholder="what it is for, e.g. holds the sword; bends forward; trails behind">`;
      const [nm, par, x, note] = d.querySelectorAll('input, select, button, .note').length ? [d.children[0], d.children[1], d.children[2], d.children[3]] : [];
      nm.onchange = () => { const old = j.name; j.name = nm.value.trim() || old; for (const o of this.joints) if (o.parent === old) o.parent = j.name; const want = (this.TREE[this.plan] || {})[j.name]; if (want && want !== j.name && this.joints.some(o => o.name === want)) j.parent = want; this.hintsUI(); this.draw(); };
      par.onchange = () => { j.parent = par.value; this.draw(); }; note.onchange = () => { j.note = note.value; };
      x.onclick = () => { const name = j.name; this.joints.splice(i, 1); for (const o of this.joints) if (o.parent === name) o.parent = j.parent || ''; this.sel = -1; this.hintsUI(); this.draw(); };
      d.onclick = ev => { if (ev.target === d) { this.sel = i; this.hintsUI(); this.draw(); } };
      L.appendChild(d);
    });
    if (!this.joints.length) L.innerHTML = '<div class="muted small">no marks yet: press “place joints”, then click the hips, then down each limb</div>';
  },
  hints() { return { plan: this.plan, half: this.half, joints: this.joints, notes: el('rigNotes').value.trim() }; },
  async saveHints(tell) {
    if (!cur) return false; try { const r = await fetch(apiUrl('spritehints'), { method: 'POST', headers: apiHeaders(), body: JSON.stringify(Object.assign({ key: cur }, this.hints())) }); const j = await r.json(); if (!r.ok) throw new Error(j.error || r.status); if (tell) el('hintMsg').textContent = `saved: ${this.joints.length} joint${this.joints.length === 1 ? '' : 's'} marked`; return true; }
    catch (e) { el('hintMsg').textContent = 'not saved: ' + e.message; return false; }
  },
  async rigGo() {
    if (!cur || !entry) return; if (this.joints.length) await this.saveHints(false);
    const rigKey = el('rigKey').value.trim() || cur; if (!/^[a-z][a-z0-9_-]{0,40}$/.test(rigKey)) { el('hintMsg').textContent = 'the rig\'s key is lowercase letters, digits, - and _, starting with a letter'; return; }
    el('rigGoBtn').disabled = true; el('aiStatus').textContent = 'sending the character and your marks to the rigger…';
    try {
      const r = await fetch(apiUrl('sketch'), { method: 'POST', headers: apiHeaders(), body: JSON.stringify({ rigjob: Object.assign({ notes: el('rigNotes').value.trim() }, this.joints.length ? { hints: this.hints() } : {}), asset: cur, note: el('rigNotes').value.trim(), size: Math.max(32, entry.w || 48), key: rigKey, style: STYLE[el('aiStyle').value], pixel: el('aiStyle').value !== 'hd' }) });
      const j = await r.json(); if (!r.ok) throw new Error(j.error || r.status);
      SK = j; remember(); showOut(null); el('aiKey').value = rigKey; clearInterval(skT); skT = setInterval(pollSketch, 4000); // Make's panel follows too, for whoever looks there
      this.watch(j.id, rigKey, (j.queued ? 'another sprite job is running: the rig is next. ' : 'the rigger is on it: ') + 'a minute or two; it appears here when it is done.');
    } catch (e) { el('hintMsg').textContent = 'not sent: ' + e.message; } finally { el('rigGoBtn').disabled = false; }
  },
  // the rig job followed here on Move: status while it runs, the take moving when it lands, Keep beside it
  watch(id, key, status) {
    clearInterval(this.jobT); try { localStorage.setItem('vl.sprites.' + GAME + '.rigjob', JSON.stringify({ id, key })); } catch (e) {} el('moveOut').classList.remove('hidden'); el('moveOutCand').classList.add('hidden'); el('moveOutStatus').textContent = status; el('moveOut').scrollIntoView({ behavior: 'smooth', block: 'center' });
    const tick = async () => { try { const j = await (await fetch(apiUrl('sketch') + '?id=' + encodeURIComponent(id), { cache: 'no-store' })).json();
      if (!j.result) { el('moveOutStatus').textContent = j.status || 'working…'; return; } clearInterval(this.jobT); try { localStorage.removeItem('vl.sprites.' + GAME + '.rigjob'); } catch (e) {}
      if (j.result.ok === false) { el('moveOutStatus').textContent = 'the rigger could not do it: ' + (j.result.error || ''); return; }
      const f = (j.result.files || [])[0]; if (!f) { el('moveOutStatus').textContent = 'no file came back'; return; } const url = j.base + f;
      el('moveOutStatus').textContent = 'done: watch it move, then keep it'; el('moveOutNote').textContent = j.result.note || ''; const c = el('moveOutCand'); c.classList.remove('hidden'); takeThumb(c.querySelector('img'), url + '?t=' + Date.now());
      cand = url; pickCand(); el('aiKey').value = key;
      el('moveKeep').onclick = async () => { el('aiKey').value = key; el('moveKeep').disabled = true; try { await keep(false); } finally { el('moveKeep').disabled = false; } el('moveOut').classList.add('hidden'); if (M.sprites[key]) open(key); else this.refresh(); };
      el('moveView').onclick = () => openViewer(id, f, url, j.result.note || '', `${key} · rig`);
    } catch (e) {} };
    this.jobT = setInterval(tick, 4000); tick();
  },
  // ---- the rig's motions and keys ----
  M() { return this.R && this.R.motions[this.motion] || null; }, K() { const M = this.M(); return M && this.keyI >= 0 ? M.keys[this.keyI] : null; },
  poseNow() { const M = this.M(); if (!M) return { pose: {}, root: { x: 0, y: 0 }, ik: {} }; const K = this.K(); if (K) return { pose: K.pose || {}, root: K.root || { x: 0, y: 0 }, ik: K.ik || {}, bend: K.bend || {}, bendMix: {} }; return Rig.pose(M, this.scrub, this.R); },
  flipBend() { const K = this.K(); if (!K) { say('select a key first', 'err'); return; } const c = this.lastChain || Object.keys(this.R.chains)[0]; if (!c) return; this.snapshot(); K.bend = K.bend || {}; const now = K.bend[c] || this.R.chains[c].bend || 1; K.bend[c] = -now; if (K.bend[c] === (this.R.chains[c].bend || 1)) delete K.bend[c]; this.draw(); say(`${c} folds ${now > 0 ? 'back' : 'forward'} in this key`, 'ok'); },
  pickMotion(name) { this.stop(); this.motion = name; const M = this.M(); if (!M) { this.keyI = -1; this.tlUI(); this.draw(); return; } if (!M.keys) M.keys = []; M.keys.sort((a, b) => a.t - b.t); el('mSecs').value = M.secs || 1; el('mFps').value = M.fps || 12; el('mLoop').checked = M.loop !== false; this.keyI = M.keys.length ? 0 : -1; this.scrub = this.keyI >= 0 ? M.keys[0].t : 0; el('tlScrub').value = Math.round(this.scrub * 1000); this.tlUI(); this.draw(); },
  tlUI() {
    const M = this.M(), bar = el('tlBar'); for (const k of bar.querySelectorAll('.tlkey')) k.remove(); el('tlCursor').style.left = (this.scrub * 100) + '%';
    if (!M) { el('tlInfo').textContent = 'no motion: make one'; el('kInfo').textContent = ''; return; }
    M.keys.forEach((K, i) => { const d = document.createElement('div'); d.className = 'tlkey' + (i === this.keyI ? ' on' : ''); d.style.left = (K.t * 100) + '%'; d.title = `key at ${K.t}`; d.onclick = () => { this.keyI = i; this.scrub = K.t; el('tlScrub').value = Math.round(K.t * 1000); this.tlUI(); this.draw(); }; bar.appendChild(d); });
    const K = this.K(); el('kFields').style.visibility = K ? 'visible' : 'hidden'; if (K) { el('kT').value = K.t; el('kEase').value = K.ease || 'io'; el('kInfo').textContent = `key ${this.keyI + 1} of ${M.keys.length}`; }
    el('tlInfo').textContent = `${this.motion}: ${M.keys.length} key${M.keys.length === 1 ? '' : 's'} · ${Rig.frames(M)} frames · cursor at ${this.scrub.toFixed(2)}${K ? '' : ' (tween)'}`;
  },
  addKey() { const M = this.M(); if (!M) { say('make a motion first', 'err'); return; } this.snapshot(); const P = Rig.pose(M, this.scrub, this.R); const K = { t: Math.round(this.scrub * 100) / 100, pose: Object.assign({}, P.pose), root: Object.assign({}, P.root), ik: JSON.parse(JSON.stringify(P.ik)) }; M.keys.push(K); M.keys.sort((a, b) => a.t - b.t); this.keyI = M.keys.indexOf(K); this.tlUI(); this.draw(); },
  dupKey() { const M = this.M(), K = this.K(); if (!K) return; this.snapshot(); const t = Math.min(1, Math.round((K.t + 0.1) * 100) / 100), C = JSON.parse(JSON.stringify(K)); C.t = t; M.keys.push(C); M.keys.sort((a, b) => a.t - b.t); this.keyI = M.keys.indexOf(C); this.scrub = t; el('tlScrub').value = Math.round(t * 1000); this.tlUI(); this.draw(); },
  delKey() { const M = this.M(); if (!M || this.keyI < 0) return; this.snapshot(); M.keys.splice(this.keyI, 1); this.keyI = Math.min(this.keyI, M.keys.length - 1); this.tlUI(); this.draw(); },
  mirrorKey() { // left and right limbs trade places: bones and chains named …L / …R
    const K = this.K(); if (!K) return; this.snapshot(); const swap = n => /L$/.test(n) ? n.slice(0, -1) + 'R' : /R$/.test(n) ? n.slice(0, -1) + 'L' : n;
    const pose = {}; for (const [n, a] of Object.entries(K.pose || {})) pose[swap(n)] = a; K.pose = pose; const ik = {}; for (const [c, t] of Object.entries(K.ik || {})) ik[swap(c)] = t; K.ik = ik; const bend = {}; for (const [c, s] of Object.entries(K.bend || {})) bend[swap(c)] = s; K.bend = bend; this.draw();
  },
  newMotion() { const name = prompt('Name of the new motion (letters, digits, dashes):', 'idle'); if (!name || !/^[a-z][a-z0-9-]{0,20}$/.test(name)) return; if (this.R.motions[name] && !confirm(`${name} exists. Start it over?`)) return; this.snapshot(); this.R.motions[name] = { secs: 1, fps: 12, loop: true, keys: [{ t: 0, pose: {}, root: { x: 0, y: 0 }, ik: {} }] }; el('mMotion').innerHTML = Object.keys(this.R.motions).map(n => `<option>${esc(n)}</option>`).join(''); el('mMotion').value = name; this.pickMotion(name); },
  motionList(select) { el('mMotion').innerHTML = Object.keys(this.R.motions).map(n => `<option>${esc(n)}</option>`).join(''); el('mMotion').value = select; this.pickMotion(select); },
  dupMotion() { const M = this.M(); if (!M) return; const name = prompt('Name for the copy:', this.motion === 'run' ? 'attack' : this.motion + '-2'); if (!name || !/^[a-z][a-z0-9-]{0,20}$/.test(name)) return; if (this.R.motions[name] && !confirm(`${name} exists. Replace it?`)) return; this.snapshot(); this.R.motions[name] = JSON.parse(JSON.stringify(M)); this.motionList(name); el('rigMsg').textContent = `${name} is a copy of ${this.motion === name ? 'the motion' : this.motion}: pose it, then Save the rig`; },
  renameMotion() { const M = this.M(); if (!M) return; const name = prompt('New name for ' + this.motion + ':', this.motion); if (!name || name === this.motion || !/^[a-z][a-z0-9-]{0,20}$/.test(name)) return; if (this.R.motions[name] && !confirm(`${name} exists. Replace it?`)) return; this.snapshot(); this.R.motions[name] = M; delete this.R.motions[this.motion]; this.motionList(name); el('rigMsg').textContent = `renamed to ${name}: Save the rig to keep it (the game then calls Draw.rig('${cur}', '${name}', …))`; },
  delMotion() { if (!this.M() || !confirm(`Remove the motion ${this.motion}?`)) return; this.snapshot(); delete this.R.motions[this.motion]; const names = Object.keys(this.R.motions); el('mMotion').innerHTML = names.map(n => `<option>${esc(n)}</option>`).join(''); this.pickMotion(names[0] || ''); },
  play() { if (this.playing) { this.stop(); return; } const M = this.M(); if (!M) return; this.keyI = -1; el('mPlay').textContent = '■ stop'; let f = 0; const n = Rig.frames(M); this.playing = setInterval(() => { f = (f + 1) % n; this.scrub = f / n; el('tlScrub').value = Math.round(this.scrub * 1000); this.tlUI(); this.draw(); }, 1000 / (M.fps || 12)); },
  stop() { clearInterval(this.playing); this.playing = null; if (el('mPlay')) el('mPlay').textContent = '▶ play'; },
  // the motions written back into the rig's source: the `motions: { … }` block replaced, the rest untouched
  serialize() {
    const round = v => typeof v === 'number' ? Math.round(v * 1000) / 1000 : v;
    const clean = JSON.parse(JSON.stringify(this.R.motions, (k, v) => round(v)));
    const body = JSON.stringify(clean, null, 2).replace(/"([a-zA-Z_][a-zA-Z0-9_]*)":/g, '$1:').replace(/\n/g, '\n  ');
    const m = /motions\s*:\s*\{/.exec(this.text); if (!m) throw new Error('the rig file has no motions block to replace');
    let i = m.index + m[0].length - 1, depth = 0, j = i; for (; j < this.text.length; j++) { const ch = this.text[j]; if (ch === '{') depth++; else if (ch === '}') { depth--; if (depth === 0) break; } }
    if (depth !== 0) throw new Error('the motions block does not close'); return this.text.slice(0, m.index) + 'motions: ' + body + this.text.slice(j + 1);
  },
  async saveRig() {
    if (!cur || !this.R) return; let text; try { text = this.serialize(); renderCode(text, 32, 0); } catch (e) { el('rigMsg').textContent = 'not saved: ' + e.message; return; }
    el('rigSave').disabled = true; try { const r = await fetch(apiUrl('spritesave'), { method: 'POST', headers: apiHeaders(), body: JSON.stringify({ key: cur, rig: text, source: entry.source || 'editor' }) }); const j = await r.json(); if (!r.ok) throw new Error(j.error || r.status); this.text = text; el('rigMsg').textContent = `saved ${Object.keys(this.R.motions).length} motion${Object.keys(this.R.motions).length === 1 ? '' : 's'} into assets/${j.sprite.file}`; say(`rig saved · Draw.rig('${cur}', '${this.motion}', Game.time, x, y, ${j.sprite.w})`, 'ok'); }
    catch (e) { el('rigMsg').textContent = 'not saved: ' + e.message; } finally { el('rigSave').disabled = false; }
  },
};
