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
    this.cv.addEventListener('dblclick', e => { if (this.kind !== 'rig') return; const h = this.hitBone(this.world(e)); if (!h) return; const c = h.chain || h.chainOf; if (!c) return; this.lastChain = c; this.selJoint = { chain: h.chain || null, bone: h.bone || null, root: !!h.root, chainOf: h.chainOf || null, name: h.name }; this.flipBend(); });
    this.cv.addEventListener('pointerdown', e => this.down(e)); this.cv.addEventListener('pointermove', e => this.move(e)); const up = () => { if (this.drag && this.kind === 'rig') { this.drag = null; this.tlUI(); this.draw(); } else this.drag = null; }; this.cv.addEventListener('pointerup', up); this.cv.addEventListener('pointercancel', up); this.cv.addEventListener('pointerleave', () => { if (!this.drag && this.hover) { this.hover = null; this.draw(); } });
    el('hintPlan').onchange = () => { this.plan = el('hintPlan').value; this.names(); this.draw(); };
    el('hintAdd').onclick = () => { this.adding = !this.adding; el('hintAdd').classList.toggle('on', this.adding); el('hintMsg').textContent = this.adding ? 'click the figure to place a joint; click a joint to select it; drag to move' : ''; };
    el('hintClear').onclick = () => { if (!this.joints.length || confirm('Clear every mark?')) { this.snapshot(); this.joints = []; this.sel = -1; this.hintsUI(); this.draw(); } };
    el('hintSave').onclick = () => this.saveHints(true);
    el('rigGoBtn').onclick = () => this.rigGo();
    el('mMotion').onchange = () => this.pickMotion(el('mMotion').value); el('mNew').onclick = () => this.newMotion(); el('mDel').onclick = () => this.delMotion();
    el('mDup').onclick = () => this.dupMotion(); el('mRename').onclick = () => this.renameMotion(); el('mAsk').onclick = () => { if (cur && entry) openAnim({ asset: cur }, cur); };
    for (const id of ['mSecs', 'mFps', 'mLoop']) el(id).onchange = () => { const M = this.M(); if (!M) return; this.snapshot(); M.secs = Math.max(0.1, +el('mSecs').value || 1); M.fps = Math.max(2, Math.min(30, +el('mFps').value || 12)); M.loop = el('mLoop').checked; this.tlUI(); this.draw(); };
    el('mBones').onchange = () => this.draw(); el('mOnion').onchange = () => this.draw(); el('mGround').onchange = () => this.draw(); el('kHold').onclick = () => this.holdPrev();
    el('mGroundBottom').onclick = () => this.setGround(this.half); el('mGroundFeet').onclick = () => this.setGround(this.feetY);
    el('mPlay').onclick = () => this.play(); el('kFlip').onclick = () => this.flipBend(); el('kAdd').onclick = () => this.addKey(); el('kDup').onclick = () => this.dupKey(); el('kDel').onclick = () => this.delKey(); el('kMirror').onclick = () => this.mirrorKey();
    el('kT').onchange = () => { const K = this.K(); if (!K) return; this.snapshot(); K.t = Math.max(0, Math.min(1, +el('kT').value || 0)); this.M().keys.sort((a, b) => a.t - b.t); this.keyI = this.M().keys.indexOf(K); this.tlUI(); this.draw(); };
    el('kEase').onchange = () => { const K = this.K(); if (K) { this.snapshot(); K.ease = el('kEase').value; } };
    el('tlScrub').oninput = () => { this.scrub = +el('tlScrub').value / 1000; this.keyI = -1; this.tlUI(); this.draw(); };
    const bar = el('tlBar'), frac = e => { const r = bar.getBoundingClientRect(); return Math.max(0, Math.min(1, (e.clientX - r.left) / r.width)); };
    bar.addEventListener('pointerdown', e => { bar.setPointerCapture(e.pointerId); const M = this.M(); if (!M) return; const key = e.target.closest('.tlkey'); this.tlDrag = key ? { i: +key.dataset.i, moved: false, t0: frac(e) } : { scrub: true };
      if (!key) { this.scrub = frac(e); this.keyI = -1; this.tlUI(); this.draw(); } });
    bar.addEventListener('pointermove', e => { const D = this.tlDrag; if (!D) return; const M = this.M(); if (!M) return; const f = frac(e);
      if (D.scrub) { this.scrub = f; this.tlUI(); this.draw(); return; }
      if (!D.moved && Math.abs(f - D.t0) < 0.01) return; if (!D.moved) { this.snapshot(); D.moved = true; D.key = M.keys[D.i]; } let t = Math.round(f * 100) / 100; if (M.keys.some(o => o !== D.key && Math.abs(o.t - t) < 0.005)) return; D.key.t = t; this.keyI = D.i; this.scrub = D.key.t; this.tlUI(); this.draw(); });
    const barUp = () => { const D = this.tlDrag; this.tlDrag = null; if (!D || D.scrub) return; const M = this.M(); if (!M) return; if (D.moved) { M.keys.sort((a, b) => a.t - b.t); this.keyI = M.keys.indexOf(D.key); } else { this.keyI = D.i; this.scrub = M.keys[D.i].t; } el('tlScrub').value = Math.round(this.scrub * 1000); this.tlUI(); this.draw(); };
    bar.addEventListener('pointerup', barUp); bar.addEventListener('pointercancel', barUp);
    el('rigSave').onclick = () => this.saveRig(); el('skelBtn').onclick = () => this.skelToggle(); el('skinGo').onclick = () => this.reskinGo();
    el('skinDl').onclick = () => { const v = el('skinFrom').value, file = v.startsWith('file:') ? v.slice(5) : (M.sprites[v] || {}).file; if (!file) { el('rigMsg').textContent = 'pick a drawing first'; return; } const a = document.createElement('a'); a.href = ASSET(file) + '?t=' + Date.now(); a.download = file; document.body.appendChild(a); a.click(); a.remove(); el('rigMsg').textContent = `downloading ${file}`; };
    el('skinUp').onclick = () => { if (el('skinFrom').value) el('skinFile').click(); };
    el('skinFile').onchange = async () => { const f = el('skinFile').files[0]; el('skinFile').value = ''; if (!f) return; let k; try { k = await this.skinKey(); } catch (e) { el('rigMsg').textContent = e.message; return; } const s = M.sprites[k];
      try { const text = await f.text(); if (!/<svg[\s>]/i.test(text)) throw new Error('that file is not an SVG'); if (!confirm(`Replace the drawing ${k} with ${f.name}?`)) return;
        const r = await fetch(apiUrl('spritesave'), { method: 'POST', headers: apiHeaders(), body: JSON.stringify({ key: k, svg: text, w: s ? s.w : 64, h: s ? s.h : 64, smooth: true, source: 'inkscape' }) }); const j = await r.json(); if (!r.ok) throw new Error(j.error || r.status);
        M = { sprites: j.sprites, files: j.files }; drawList(); el('rigMsg').textContent = `${k} updated from ${f.name}: now press Re-skin GO!`; }
      catch (e) { el('rigMsg').textContent = 'not uploaded: ' + e.message; } }; el('mUndo').onclick = () => this.undo(); el('mRedo').onclick = () => this.redo();
    document.addEventListener('keydown', e => { if (el('tab-move').classList.contains('hidden') || e.target.matches('input, textarea, select')) return; const k = e.key.toLowerCase(); if ((e.ctrlKey || e.metaKey) && k === 'z') { e.preventDefault(); e.shiftKey ? this.redo() : this.undo(); return; } if ((e.ctrlKey || e.metaKey) && k === 'y') { e.preventDefault(); this.redo(); return; }
      if (this.kind !== 'rig' || !this.M()) return; const M = this.M(), n = Rig.frames(M);
      if (e.key === ' ') { e.preventDefault(); this.play(); } else if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { e.preventDefault(); this.stop(); this.scrub = ((Math.round(this.scrub * n) + (e.key === 'ArrowRight' ? 1 : -1)) % n + n) % n / n; this.keyI = M.keys.findIndex(K => Math.abs(K.t - this.scrub) < 0.5 / n); el('tlScrub').value = Math.round(this.scrub * 1000); this.tlUI(); this.draw(); }
      else if (e.key === ']' || e.key === '[') { e.preventDefault(); if (!M.keys.length) return; const i = this.keyI >= 0 ? this.keyI : (e.key === ']' ? M.keys.findIndex(K => K.t > this.scrub) - 1 : M.keys.findIndex(K => K.t >= this.scrub)); this.keyI = ((i + (e.key === ']' ? 1 : -1)) % M.keys.length + M.keys.length) % M.keys.length; this.scrub = M.keys[this.keyI].t; el('tlScrub').value = Math.round(this.scrub * 1000); this.tlUI(); this.draw(); }
      else if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); this.delKey(); } else if (k === 'd') { this.dupKey(); } else if (k === 'h') { this.holdPrev(); } else if (k === 'm') { this.mirrorKey(); } else if (k === 'f') { this.flipBend(); } else if (k === 'k') { this.addKey(); } });
    window.addEventListener('resize', () => this.draw());
  },
  // ---- undo: a snapshot of the motions (or the marks) before every change; Ctrl+Z back, Ctrl+Y forward ----
  snapshot() { const s = JSON.stringify({ motions: this.R ? this.R.motions : null, motion: this.motion, keyI: this.keyI, joints: this.joints, plan: this.plan, bones: this.def ? this.def.bones : null, skin: this.def ? this.def.skin || null : null }); if (this.hist[this.hist.length - 1] === s) return; this.hist.push(s); if (this.hist.length > 100) this.hist.shift(); this.redoS = []; },
  current() { return JSON.stringify({ motions: this.R ? this.R.motions : null, motion: this.motion, keyI: this.keyI, joints: this.joints, plan: this.plan, bones: this.def ? this.def.bones : null, skin: this.def ? this.def.skin || null : null }); },
  restore(s) { const d = JSON.parse(s); this.stop(); if (this.def && d.bones) { this.def.bones = d.bones; if (d.skin) this.def.skin = d.skin; else delete this.def.skin; this.redefine(); } if (this.R && d.motions) { this.R.motions = d.motions; this.motionList(d.motions[d.motion] ? d.motion : Object.keys(d.motions)[0] || ''); this.keyI = Math.min(d.keyI, (this.M() ? this.M().keys.length : 0) - 1); if (this.keyI >= 0) { this.scrub = this.M().keys[this.keyI].t; el('tlScrub').value = Math.round(this.scrub * 1000); } this.tlUI(); } if (this.kind === 'pic') { this.joints = d.joints || []; this.plan = d.plan || this.plan; el('hintPlan').value = this.plan; this.names(); this.hintsUI(); } this.draw(); },
  undo() { if (!this.hist.length) { say('nothing to undo on Move', ''); return; } this.redoS.push(this.current()); this.restore(this.hist.pop()); say('undone', 'ok'); },
  redo() { if (!this.redoS.length) return; this.hist.push(this.current()); this.restore(this.redoS.pop()); say('redone', 'ok'); },
  // ---- what is on the stage ----
  async refresh() {
    if (!this.cv) this.init(); this.stop();
    const has = !!(cur && entry); el('moveTitle').textContent = has ? cur : 'no sprite chosen'; el('moveAnimBtn').disabled = !has; el('moveAnimBtn').classList.toggle('hidden', !!(has && entry.kind === 'rig')); el('rigGoBtn').disabled = !has; el('moveDupBtn').disabled = !has; el('moveDupBtn').onclick = () => el('dupBtn').click();
    el('hintsPanel').classList.toggle('hidden', !has || entry.kind === 'rig'); el('rigPanel').classList.toggle('hidden', !(has && entry.kind === 'rig')); el('moveTl').classList.toggle('hidden', !(has && entry.kind === 'rig'));
    this.kind = !has ? 'none' : (entry.kind === 'rig' ? 'rig' : 'pic'); this.base = null; this.R = null; this.joints = []; this.sel = -1; this.hist = []; this.redoS = []; this.selJoint = null; if (this.skel) this.skelToggle(); this.skelDirty = false;
    if (!has) { el('moveSub').textContent = 'pick a kept sprite on the left to give it motion'; this.draw(); return; }
    try {
      if (this.kind === 'rig') {
        this.text = await (await fetch(ASSET(entry.file) + '?t=' + Date.now(), { cache: 'no-store' })).text();
        this.R = this.evalRig(this.text); this.half = this.R.half; const names = Object.keys(this.R.motions);
        this.feetY = this.restFeet(); this.groundY = this.R.ground !== null ? this.R.ground : this.feetY; // the ground: the rig's own, else where the feet stand at rest
        el('moveSub').textContent = `a rig: ${Object.keys(this.R.bones).length} bones, ${Object.keys(this.R.chains).length} IK chains, ${this.R.parts.length} parts`;
        el('mMotion').innerHTML = names.map(n => `<option>${esc(n)}</option>`).join(''); this.pickMotion(names[0] || '');
        { const stem = cur.replace(/-rig$/, ''), first = (a, b) => (b.startsWith(stem) - a.startsWith(stem)) || a.localeCompare(b);
          const keys = Object.entries(M.sprites).filter(([, s]) => /\.(svg|png)$/i.test(s.file)).map(([k]) => k).sort(first), loose = (M.files || []).filter(f => /\.(svg|png)$/i.test(f)).sort(first);
          el('skinFrom').innerHTML = (keys.map(k => `<option value="${esc(k)}">${esc(k)}</option>`).join('') + loose.map(f => `<option value="file:${esc(f)}">${esc(f)} (file)</option>`).join('')) || '<option value="">no SVG or picture sprites yet</option>';
          const best = [...el('skinFrom').options].find(o => o.value.replace(/^file:/, '').startsWith(stem)); if (best) el('skinFrom').value = best.value; el('skinGo').disabled = !(keys.length || loose.length); }
        { let rj = null; try { rj = JSON.parse(localStorage.getItem('vl.sprites.' + GAME + '.rigjob') || 'null'); } catch (e) {} if (rj && rj.key === cur) this.watch(rj.id, rj.key, 'picking the job up again…'); else el('moveOut').classList.add('hidden'); }
        this.listJobs();
      } else {
        this.half = Math.max(8, (entry.w || 48) / 2); const px = Math.round(this.N * 0.9);
        if (entry.kind === 'code' || /\.js$/i.test(entry.file)) { const t = await (await fetch(ASSET(entry.file) + '?t=' + Date.now(), { cache: 'no-store' })).text(); this.base = renderCode(t, px, 0).canvas; }
        else { const im = await loadImage(ASSET(entry.file) + '?t=' + Date.now()); const c = document.createElement('canvas'); c.width = c.height = px; const g = c.getContext('2d'); g.imageSmoothingEnabled = !/\.png$/i.test(entry.file); const s = Math.min(px / im.naturalWidth, px / im.naturalHeight), w = im.naturalWidth * s, h = im.naturalHeight * s; g.drawImage(im, (px - w) / 2, (px - h) / 2, w, h); this.base = c; }
        el('moveSub').textContent = 'a drawing: mark its bones and send it to the rigger; once a rig is kept, the timeline and posing appear here';
        const h = await (await fetch(apiUrl('spritehints') + '?key=' + encodeURIComponent(cur), { cache: 'no-store' })).json(); this.plan = h.plan || 'biped'; this.joints = Array.isArray(h.joints) ? h.joints : []; el('hintPlan').value = this.plan; el('rigNotes').value = h.notes || ''; this.names(); this.hintsUI();
        const codeBase = entry.kind === 'code' || /\.js$/i.test(entry.file); el('rigKey').value = codeBase ? cur + '-rig' : cur; el('rigKeyWhy').textContent = codeBase ? `a new key, so the coded ${cur} stays; use ${cur} to replace it` : `the rig takes the sprite's place under ${cur}; change it to keep both`;
        let rj = null; try { rj = JSON.parse(localStorage.getItem('vl.sprites.' + GAME + '.rigjob') || 'null'); } catch (e) {} if (rj && rj.key === cur) this.watch(rj.id, rj.key, 'picking the job up again…'); else el('moveOut').classList.add('hidden');
        this.listJobs();
      }
    } catch (e) { el('moveSub').textContent = 'could not load it: ' + e.message; }
    this.draw();
  },
  restFeet() { // the bottom of the DRAWN figure at rest (soles hang below the ankle joints): the lowest painted pixel, in rig units
    try { const n = 256, c = document.createElement('canvas'); c.width = c.height = n; const g = c.getContext('2d'), k = n * 0.9 / (2 * this.half); g.translate(n / 2, n / 2); g.scale(k, k); const was = Draw.ctx; Draw.ctx = g; try { Rig.paint(this.R, '', 0, g); } finally { Draw.ctx = was; }
      const px = g.getImageData(0, 0, n, n).data; for (let y = n - 1; y >= 0; y--) for (let x = 0; x < n; x++) if (px[(y * n + x) * 4 + 3] > 40) return Math.round(((y + 1) - n / 2) / k * 10) / 10; } catch (e) {}
    const W = Rig.solve(this.R, { pose: {}, root: { x: 0, y: 0 }, ik: {} }); let gy = -Infinity; for (const w of Object.values(W)) gy = Math.max(gy, w.ty, w.y); return gy; },
  setGround(y) { this.groundY = Math.round(y * 10) / 10; this.R.ground = this.groundY; this.groundDirty = true; this.draw(); el('rigMsg').textContent = `ground at ${this.groundY} (${this.groundY >= this.half - 0.05 ? 'the bottom of the box' : 'inside the box'}); Save the rig to keep it`; },
  evalRig(text) { let def = null, name = ''; const real = Rig.define; Rig.define = (k, r) => { def = r; name = k; this.def = r; }; try { new Function('Rig', 'Draw', 'TAU', 'U', text)(Rig, Draw, TAU, U); } finally { Rig.define = real; } if (!def) throw new Error('no Rig.define in the file'); const key = 'move:' + cur, R = Rig.define(key, def); R.realKey = name; if (name && name !== key) Rig.rigs.set(name, R); return R; },
  names() { el('boneNames').innerHTML = (this.PLANS[this.plan] || []).map(n => `<option value="${n}">`).join(''); },
  // ---- the stage ----
  sx(x) { return this.N / 2 + x * this.k; }, sy(y) { return this.N / 2 + y * this.k; },
  world(e) { const r = this.cv.getBoundingClientRect(); return { x: ((e.clientX - r.left) / r.width * this.N - this.N / 2) / this.k, y: ((e.clientY - r.top) / r.height * this.N - this.N / 2) / this.k }; },
  draw() {
    const g = this.g, N = this.N; g.clearRect(0, 0, N, N); this.k = N * 0.9 / (2 * this.half);
    if (this.kind === 'none') { g.fillStyle = 'rgba(128,128,128,.5)'; g.font = '14px sans-serif'; g.textAlign = 'center'; g.fillText('pick a kept sprite on the left', N / 2, N / 2); return; }
    if (this.kind === 'pic') { if (this.base) g.drawImage(this.base, (N - this.base.width) / 2, (N - this.base.height) / 2); this.drawHints(); return; }
    const P = this.poseNow(), W = Rig.solve(this.R, P);
    if (el('mGround').checked && this.groundY !== undefined) { g.save(); g.setLineDash([6, 6]); g.strokeStyle = 'rgba(124,180,204,.6)'; g.lineWidth = 1.5; g.beginPath(); g.moveTo(0, this.sy(this.groundY)); g.lineTo(N, this.sy(this.groundY)); g.stroke(); g.restore(); }
    if (el('mOnion').checked) { const Mo = this.M(); if (Mo && Mo.keys.length > 1 && this.keyI >= 0) { const n = Mo.keys.length, ghosts = [[Mo.keys[(this.keyI - 1 + n) % n], 'rgba(124,180,204,.45)'], [Mo.keys[(this.keyI + 1) % n], 'rgba(224,119,0,.45)']]; for (const [Kg, col] of ghosts) { if (Kg === this.K()) continue; const Wg = Rig.solve(this.R, { pose: Kg.pose || {}, root: Kg.root || { x: 0, y: 0 }, ik: Kg.ik || {}, bend: Kg.bend || {} }); g.lineWidth = 3; g.strokeStyle = col; g.lineCap = 'round'; for (const w of Object.values(Wg)) { g.beginPath(); g.moveTo(this.sx(w.x), this.sy(w.y)); g.lineTo(this.sx(w.tx), this.sy(w.ty)); g.stroke(); } } } }
    g.save(); g.translate(N / 2, N / 2); g.scale(this.k, this.k); const was = Draw.ctx; Draw.ctx = g;
    for (const part of this.R.parts) { const w = W[part.bone]; if (!w) continue; g.save(); Rig.partFrame(this.R, part, w, g); try { part.draw(g, Rig.partBone(this.R, part, w), P); } catch (e) {} g.restore(); }
    Draw.ctx = was; g.restore();
    if (el('mBones').checked) this.drawBones(W, P);
    if (this.selJoint) { const s = this.selJoint, at = s.chain ? (() => { const b = W[this.R.chains[s.chain].bones[1]]; return [b.tx, b.ty]; })() : s.root ? (() => { const b = W[Object.values(this.R.bones).find(b => !b.parent).name]; return [b.x, b.y]; })() : (W[s.bone] ? [W[s.bone].tx, W[s.bone].ty] : null); if (at) { const g = this.g, x = this.sx(at[0]), y = this.sy(at[1]); g.beginPath(); g.arc(x, y, 10, 0, TAU); g.strokeStyle = 'rgba(80,220,120,.95)'; g.lineWidth = 2.5; g.stroke(); g.beginPath(); g.arc(x, y, 15, 0, TAU); g.strokeStyle = 'rgba(80,220,120,.35)'; g.lineWidth = 1.5; g.stroke(); } }
    const h = this.drag && this.drag.at ? this.drag : this.hover; if (h && h.at) { const g = this.g, x = this.sx(h.at[0]), y = this.sy(h.at[1]), live = !!this.drag; g.beginPath(); g.arc(x, y, live ? 11 : 13, 0, TAU); g.strokeStyle = live ? 'rgba(80,220,120,1)' : 'rgba(80,220,120,.55)'; g.lineWidth = live ? 3 : 2; g.stroke(); g.font = '12px ui-monospace, monospace'; g.textAlign = 'left'; const label = (live ? '✥ ' : '') + h.name; g.fillStyle = 'rgba(6,6,8,.75)'; const wl = g.measureText(label).width + 10; g.fillRect(x + 14, y - 20, wl, 18); g.fillStyle = 'rgba(120,255,160,1)'; g.fillText(label, x + 19, y - 7); }
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
    if (this.kind === 'rig' && this.skel) { const h = this.skelHit(p); if (!h) return; this.snapshot(); this.drag = h; el('tlInfo').textContent = 'moving the joint ' + h.name; this.draw(); return; }
    if (this.kind === 'rig') { const hit = this.hitBone(p); if (!hit && el('mGround').checked && this.groundY !== undefined && Math.abs(p.y - this.groundY) < 8 / this.k) { this.drag = { ground: true, name: 'ground' }; el('tlInfo').textContent = 'dragging the ground line'; return; }
      if (!hit) { if (this.selJoint) { this.selJoint = null; this.draw(); } return; }
      this.selJoint = { chain: hit.chain || null, bone: hit.bone || null, root: !!hit.root, chainOf: hit.chainOf || null, name: hit.name }; if (hit.chain || hit.chainOf) this.lastChain = hit.chain || hit.chainOf; if (!this.K()) { if (!this.M()) { say('make a motion first', 'err'); return; } this.addKey(); say('a key was made at the cursor for this pose', 'ok'); } this.drag = this.hitBone(p); if (this.drag) { this.snapshot(); if (this.drag.chain) { const W = Rig.solve(this.R, this.poseNow()), ch = this.R.chains[this.drag.chain]; this.drag.elbow = [W[ch.bones[0]].tx, W[ch.bones[0]].ty]; } el('tlInfo').textContent = 'dragging ' + this.drag.name; this.draw(); } }
  },
  move(e) {
    if (!this.drag) { if (this.kind !== 'rig' || !this.R) return; const q = this.world(e); let h = this.skel ? this.skelHit(q) : this.hitBone(q); if (!h && el('mGround').checked && this.groundY !== undefined && Math.abs(q.y - this.groundY) < 8 / this.k) h = { name: 'ground (drag to move it)', at: [q.x, this.groundY] }; const was = this.hover && this.hover.name; this.hover = h; if ((h && h.name) !== was) this.draw(); return; } const p = this.world(e);
    if (this.kind === 'pic') { const j = this.joints[this.drag.joint]; j.x = Math.round(p.x * 10) / 10; j.y = Math.round(p.y * 10) / 10; this.draw(); return; }
    const d = this.drag; if (d.skel) { this.skelDrag(d, p); return; } if (d.ground) { this.setGround(p.y); return; } const K = this.K(); if (!K) return;
    d.at = [p.x, p.y];
    const shift = e.shiftKey;
    if (d.root) { let rx = p.x - d.ox, ry = p.y - d.oy; if (shift) { const r0 = d.root0 || (d.root0 = { x: (K.root || {}).x || 0, y: (K.root || {}).y || 0 }); if (Math.abs(rx - r0.x) > Math.abs(ry - r0.y)) ry = r0.y; else rx = r0.x; } K.root = { x: Math.round(rx * 10) / 10, y: Math.round(ry * 10) / 10 }; }
    else if (d.chain) { const r = K.root || { x: 0, y: 0 }; K.ik = K.ik || {}; const ch = this.R.chains[d.chain], W0 = Rig.solve(this.R, this.poseNow()), O = W0[ch.bones[0]], reach = (this.R.bones[ch.bones[0]].len + this.R.bones[ch.bones[1]].len) * 0.995; let tx = p.x, ty = p.y; const dist = Math.hypot(tx - O.x, ty - O.y); if (dist > reach) { tx = O.x + (tx - O.x) * reach / dist; ty = O.y + (ty - O.y) * reach / dist; }
      if (el('mGround').checked && this.groundY !== undefined && /leg|foot/i.test(d.chain + ch.bones[1]) && Math.abs(ty - this.groundY) < 3.5 / Math.max(1, this.k / 4)) ty = this.groundY; // a foot near the ground stands on it
      K.ik[d.chain] = { x: Math.round((tx - r.x) * 10) / 10, y: Math.round((ty - r.y) * 10) / 10 }; this.lastChain = d.chain;
      if (d.elbow) { // of the two folds, keep the one whose elbow stays nearer where it just was: the joint swings round on its own when the limb passes through straight
        const ch = this.R.chains[d.chain], cur = Rig.bendAt(this.M(), this.keyI, d.chain, ch.bend || 1), e = s => { K.bend = K.bend || {}; const was = K.bend[d.chain]; K.bend[d.chain] = s; const W = Rig.solve(this.R, this.poseNow()); if (was === undefined) delete K.bend[d.chain]; else K.bend[d.chain] = was; const b = W[ch.bones[0]]; return [b.tx, b.ty]; };
        const e1 = e(cur), e2 = e(-cur), d1 = Math.hypot(e1[0] - d.elbow[0], e1[1] - d.elbow[1]), d2 = Math.hypot(e2[0] - d.elbow[0], e2[1] - d.elbow[1]);
        if (d2 + 0.5 < d1) { K.bend = K.bend || {}; K.bend[d.chain] = -cur; d.elbow = e2; } else d.elbow = e1; } }
    else if (d.bone) { const W = Rig.solve(this.R, this.poseNow()), w = W[d.bone], b = this.R.bones[d.bone], par = b.parent ? W[b.parent].angle : 0; let ang = Math.atan2(p.y - w.y, p.x - w.x); if (shift) ang = Math.round(ang / (Math.PI / 12)) * (Math.PI / 12); K.pose = K.pose || {}; K.pose[d.bone] = Math.round((ang - par - b.angle) * 1000) / 1000; if (K.ik && d.chainOf) delete K.ik[d.chainOf]; }
    this.draw();
  },
  hitBone(p) { // what a press on the posed figure takes hold of: the nearest handle, whatever it is: a chain's end (IK), the root (move), a bone's tip (turn)
    const P = this.poseNow(), W = Rig.solve(this.R, P), r = 12 / this.k; let best = null, bd = r;
    const ends = {}; for (const [cn, ch] of Object.entries(this.R.chains)) ends[ch.bones[1]] = cn;
    for (const [n, w] of Object.entries(W)) { if (!(w.len > 0)) continue; const d = Math.hypot(w.tx - p.x, w.ty - p.y) - (ends[n] ? 1e-6 : 0); if (d < bd) { bd = d; best = ends[n] ? { chain: ends[n], at: [w.tx, w.ty], name: n + ' (' + ends[n] + ', IK)' } : { bone: n, chainOf: (Object.entries(this.R.chains).find(([, ch]) => ch.bones.includes(n)) || [null])[0], at: [w.tx, w.ty], name: n }; } }
    for (const b of Object.values(this.R.bones)) if (!b.parent) { const w = W[b.name], d = Math.hypot(w.x - p.x, w.y - p.y); if (d <= bd + 1e-6 || d < 6 / this.k) { bd = d; best = { root: true, ox: p.x - P.root.x, oy: p.y - P.root.y, at: [w.x, w.y], name: b.name + ' (root: moves the whole body)' }; } } // the root wins a tie, and anything very close to it
    return best;
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
    clearInterval(this.jobT); try { localStorage.setItem('vl.sprites.' + GAME + '.rigjob', JSON.stringify({ id, key })); } catch (e) {} const box = el('moveOut'); if (box.parentElement !== el('moveSide')) el('moveSide').appendChild(box); box.classList.remove('hidden'); el('moveOutCand').classList.add('hidden'); el('moveOutStatus').textContent = status; el('moveOut').scrollIntoView({ behavior: 'smooth', block: 'center' });
    const tick = async () => { try { const j = await (await fetch(apiUrl('sketch') + '?id=' + encodeURIComponent(id), { cache: 'no-store' })).json();
      if (!j.result) { el('moveOutStatus').textContent = j.status || 'working…'; artBusy((j.status || 'the artist is working…') + ' · ' + jobTitle(j)); return; } clearInterval(this.jobT); artIdle(); try { localStorage.removeItem('vl.sprites.' + GAME + '.rigjob'); } catch (e) {}
      if (j.result.ok === false) { el('moveOutStatus').textContent = 'the rigger could not do it: ' + (j.result.error || ''); return; }
      const f = (j.result.files || [])[0]; if (!f) { el('moveOutStatus').textContent = 'no file came back'; return; } const url = j.base + f;
      el('moveOutStatus').textContent = (j.kind === 'animate' ? `done: the ${j.cycle || ''} motion, rigged in; watch it, then keep it (it replaces ${key} with the motion inside)` : 'done: watch it move, then keep it'); el('moveOutNote').textContent = j.result.note || ''; const c = el('moveOutCand'); c.classList.remove('hidden'); takeThumb(c.querySelector('img'), url + '?t=' + Date.now(), j.cycle || undefined);
      cand = url; pickCand(); el('aiKey').value = key;
      el('moveKeep').textContent = (j.kind === 'reskin' && this.R) ? `Use this look for ${key}` : (j.kind === 'animate' && this.R) ? `Add the ${j.cycle || 'new'} motion to ${key}` : 'Keep as rig';
      el('moveKeep').onclick = async () => { el('moveKeep').disabled = true;
        try {
          if (j.kind === 'reskin' && this.R && cur === key) { await this.reskinFrom(url); this.listJobs(); }
          else if (j.kind === 'animate' && this.R && cur === key) { await this.addMotionFrom(url, j.cycle); this.listJobs(); } // merge: only the new motion joins the rig as it is now
          else { el('aiKey').value = key; await keep(false); if (M.sprites[key]) open(key); else this.refresh(); }
        } catch (e) { say('not kept: ' + e.message, 'err'); } finally { el('moveKeep').disabled = false; }
        el('moveOut').classList.add('hidden'); };
      el('moveView').onclick = () => openViewer(id, f, url, j.result.note || '', `${key} · ${j.cycle || 'rig'}`, j.cycle || undefined, key);
    } catch (e) {} };
    this.jobT = setInterval(tick, 4000); tick();
  },
  // a motion from an artist's take (a copy of the rig with the motion added) merged into the rig as it is now
  async addMotionFrom(url, cycle) {
    const text = await (await fetch(url, { cache: 'no-store' })).text(); let def = null; const real = Rig.define; Rig.define = (k, r) => { def = r; }; try { new Function('Rig', 'Draw', 'TAU', 'U', text)(Rig, Draw, TAU, U); } finally { Rig.define = real; }
    const name = cycle && def && def.motions && def.motions[cycle] ? cycle : (def && def.motions ? Object.keys(def.motions).find(n => !this.R.motions[n]) || Object.keys(def.motions)[0] : null); if (!name) throw new Error('the take has no motion to add');
    if (this.R.motions[name] && !confirm(`${cur} already has a motion named ${name}. Replace it with the artist's?`)) return null;
    this.snapshot(); this.R.motions[name] = def.motions[name]; this.motionList(name); await this.saveRig(); say(`the motion ${name} is in ${cur} now; everything else in the rig stayed as you had it`, 'ok'); return name;
  },
  async listJobs() { // the artist's finished takes for this sprite: motions and rigs to add to a rig, or rigs to keep for a drawing
    const box = el('moveJobs'), L = el('moveJobsList'); if (!cur || !entry) { box.classList.add('hidden'); return; }
    try { const j = await (await fetch(apiUrl('sketches'), { cache: 'no-store' })).json(); const jobs = j.jobs.filter(o => (o.key === cur || o.asset === cur) && o.result && o.result.ok && (this.R ? (o.kind === 'animate' || o.kind === 'rig' || o.kind === 'reskin') : o.kind === 'rig') && (o.result.files || []).length).slice(0, 8);
      box.classList.toggle('hidden', !jobs.length || el('tab-move').classList.contains('hidden')); el('moveJobsFor').textContent = cur; L.innerHTML = '';
      for (const o of jobs) { const d = document.createElement('div'); d.className = 'row'; const url = o.base + o.result.files[0]; d.innerHTML = `<img alt="" style="width:40px;height:40px;object-fit:contain;image-rendering:pixelated;border-radius:6px;background:repeating-conic-gradient(var(--check1) 0 25%, var(--check2) 0 50%) 0 0 / 10px 10px"><span class="small"><b>${esc(o.kind === 'reskin' ? 'a new look' : o.kind === 'animate' ? (o.cycle || 'motion') + ' motion' : 'a rig')}</b> <span class="muted">· ${esc(new Date(o.at * 1000).toLocaleString())}${o.result.note ? ' · ' + esc(o.result.note.slice(0, 80)) : ''}</span></span><button class="primary small" data-add="${esc(url)}" data-cycle="${esc(o.cycle || '')}">${o.kind === 'reskin' ? 'Use this look' : o.kind === 'animate' ? 'Add this motion' : this.R ? 'Replace the rig with this' : `Keep as the rig (${esc(o.key)})`}</button><button data-view="${esc(url)}" data-label="${esc(cur + ' · ' + (o.cycle || 'rig'))}">view</button>`;
        d.style.cssText = 'display:grid;grid-template-columns:40px 1fr;gap:6px;align-items:center'; d.querySelector('span.small').style.cssText = 'overflow-wrap:anywhere'; const btns = document.createElement('div'); btns.className = 'row'; btns.style.gridColumn = '1 / -1'; btns.append(d.querySelector('[data-add]'), d.querySelector('[data-view]')); d.appendChild(btns);
        takeThumb(d.querySelector('img'), url, o.cycle || undefined); d.querySelector('[data-add]').onclick = async ev => { const b = ev.currentTarget; b.disabled = true; try { if (o.kind === 'reskin' && this.R) await this.reskinFrom(url); else if (o.kind === 'animate' && this.R) await this.addMotionFrom(url, o.cycle); else { if (!this.R && o.key === cur && !confirm(`The rig takes the place of the drawing ${cur} in the game (the SVG file stays in assets). Keep it as ${cur}?`)) return; cand = url; el('aiKey').value = o.key; await keep(false); open(o.key); } } catch (e) { say('not added: ' + e.message, 'err'); } finally { b.disabled = false; } };
        d.querySelector('[data-view]').onclick = () => openViewer(o.id, o.result.files[0], url, o.result.note || '', cur + ' · ' + (o.cycle || 'rig'), o.cycle || undefined, o.key); L.appendChild(d); }
    } catch (e) { box.classList.add('hidden'); }
  },
  // the drawing chosen for a re-skin, as a key: a loose file is first listed as <stem>-drawing
  async skinKey() { const v = el('skinFrom').value; if (!v) throw new Error('pick a drawing first'); if (!v.startsWith('file:')) return v; const file = v.slice(5), key = file.replace(/\.[a-z0-9]+$/i, '').toLowerCase().replace(/[^a-z0-9_-]+/g, '-') + '-drawing';
    if (!M.sprites[key]) { const r = await fetch(apiUrl('spritesave'), { method: 'POST', headers: apiHeaders(), body: JSON.stringify({ key, file, source: 'upload' }) }); const j = await r.json(); if (!r.ok) throw new Error(j.error || r.status); M = { sprites: j.sprites, files: j.files }; drawList(); }
    const o = document.createElement('option'); o.value = key; o.textContent = key; el('skinFrom').prepend(o); el('skinFrom').value = key; return key; },
  redefine() { const motions = this.R.motions, ground = this.R.ground, key = 'move:' + cur; this.R = Rig.define(key, Object.assign({}, this.def, { motions, ground })); this.R.realKey = this.R.realKey || cur; if (cur) Rig.rigs.set(cur, this.R); },
  // ---- edit skeleton: drag a joint where it belongs on the drawing; bones rebuilt, parts compensated, motions untouched ----
  skelToggle() { this.skel = !this.skel; el('skelBtn').classList.toggle('on', this.skel); el('skelBtn').textContent = this.skel ? '✓ Done editing the skeleton' : '✥ Edit skeleton'; this.stop(); this.selJoint = null; this.hover = null;
    el('skelMsg').textContent = this.skel ? 'the rig at rest: drag a joint dot (or the hips ring) to where it belongs on the drawing; the drawing stays, the pivot moves' : (this.skelDirty ? 'skeleton changed: Save the rig to keep it' : '');
    for (const id of ['mPlay', 'kAdd', 'kDup', 'kDel', 'kMirror', 'kFlip', 'kHold']) el(id).disabled = this.skel; this.draw(); },
  skelHit(p) { const W = Rig.solve(this.R, { pose: {}, root: { x: 0, y: 0 }, ik: {} }), r = 12 / this.k; let best = null, bd = r;
    for (const b of Object.values(this.R.bones)) if (!b.parent) { const w = W[b.name], d = Math.hypot(w.x - p.x, w.y - p.y); if (d < bd) { bd = d; best = { skel: true, root: true, name: b.name + ' (root)', at: [w.x, w.y] }; } }
    for (const [n, w] of Object.entries(W)) { if (!(w.len > 0)) continue; const d = Math.hypot(w.tx - p.x, w.ty - p.y); if (d < bd) { bd = d; best = { skel: true, bone: n, name: n + ' — the joint at its end', at: [w.tx, w.ty] }; } }
    return best; },
  skelDrag(d, p) {
    if (!this.def.skin) this.def.skin = Object.fromEntries(Object.entries(Rig.restWorld(this.R)).map(([n, w]) => [n, { x: Math.round(w.x * 1000) / 1000, y: Math.round(w.y * 1000) / 1000, a: Math.round(w.a * 10000) / 10000, len: w.len }])); // where the parts were drawn
    const W = Rig.restWorld(this.R), rootName = Object.values(this.R.bones).find(b => !b.parent).name, root = d.root ? { x: p.x, y: p.y } : { x: W[rootName].x, y: W[rootName].y }, tips = {};
    for (const [n, w] of Object.entries(W)) tips[n] = { x: w.tx, y: w.ty }; if (d.bone) tips[d.bone] = { x: p.x, y: p.y };
    Rig.rebuild(this.def, this.R, root, tips); this.redefine(); d.at = [p.x, p.y]; this.skelDirty = true; this.draw(); },
  async reskinGo() {
    let skin; try { skin = await this.skinKey(); } catch (e) { el('rigMsg').textContent = e.message; return; } if (!cur || !this.R) return; el('skinGo').disabled = true;
    try { const r = await fetch(apiUrl('sketch'), { method: 'POST', headers: apiHeaders(), body: JSON.stringify({ reskin: true, asset: cur, skin, key: cur, size: Math.round(this.half * 2) }) }); const j = await r.json(); if (!r.ok) throw new Error(j.error || r.status);
      this.watch(j.id, cur, (j.queued ? 'another sprite job is running: the re-skin is next. ' : 'the artist is re-skinning it: ') + `${cur} from ${skin}; the new look appears here, and keeping it changes only the parts.`); }
    catch (e) { el('rigMsg').textContent = 'not sent: ' + e.message; } finally { el('skinGo').disabled = false; }
  },
  // the parts block of a take's source spliced into the rig's source, nothing else touched (parts are code, so this is done on the text)
  block(text, name, open) { const m = new RegExp('\\b' + name + '\\s*:\\s*\\' + open).exec(text); if (!m) return null; const close = open === '[' ? ']' : '}'; let i = m.index + m[0].length - 1, depth = 0, q = null;
    for (let j = i; j < text.length; j++) { const ch = text[j], nx = text[j + 1]; if (q) { if (ch === '\\') { j++; continue; } if (ch === q) q = null; continue; } if (ch === '/' && nx === '/') { j = text.indexOf('\n', j); if (j < 0) return null; continue; } if (ch === '/' && nx === '*') { j = text.indexOf('*/', j + 2) + 1; if (j <= 0) return null; continue; } if (ch === '"' || ch === "'" || ch === '`') { q = ch; continue; } if (ch === open) depth++; else if (ch === close) { depth--; if (depth === 0) return { start: m.index, end: j + 1, body: text.slice(m.index, j + 1) }; } } return null; },
  // the Rig.define(…) call in a source: where it starts, where its second argument starts and ends (strings and comments skipped)
  defineCall(text) { const at = text.search(/Rig\.define\s*\(/); if (at < 0) return null; let i = text.indexOf('(', at), depth = 0, q = null, comma = -1;
    for (let j = i; j < text.length; j++) { const ch = text[j], nx = text[j + 1]; if (q) { if (ch === '\\') { j++; continue; } if (ch === q) q = null; continue; } if (ch === '/' && nx === '/') { j = text.indexOf('\n', j); if (j < 0) return null; continue; } if (ch === '/' && nx === '*') { j = text.indexOf('*/', j + 2) + 1; if (j <= 0) return null; continue; } if (ch === '"' || ch === "'" || ch === '`') { q = ch; continue; }
      if ('([{'.includes(ch)) depth++; else if (')]}'.includes(ch)) { depth--; if (depth === 0) return comma < 0 ? null : { start: at, argStart: comma + 1, argEnd: j, end: j + 1 }; } else if (ch === ',' && depth === 1 && comma < 0) comma = j; } return null; },
  async reskinFrom(url) {
    const take = await (await fetch(url, { cache: 'no-store' })).text(), call = this.defineCall(take); if (!call) throw new Error('the take has no Rig.define(key, { … }) call');
    const raw = this.def || {}, lit = o => JSON.stringify(o, (k, v) => typeof v === 'number' ? Math.round(v * 1000) / 1000 : v, 2).replace(/"([a-zA-Z_][a-zA-Z0-9_]*)":/g, '$1:');
    const kept = { half: raw.half || this.R.half, ground: this.R.ground !== null && this.R.ground !== undefined ? this.R.ground : undefined, bones: raw.bones, chains: raw.chains, motions: this.R.motions };
    const out = `// the look: the artist's re-skin from a drawing; the skeleton, limits, ground and motions are the rig's own, kept here as data\nconst __kept = ${lit(kept)};\n`
      + take.slice(0, call.start) + `Rig.define('${cur}', Object.assign(${take.slice(call.argStart, call.argEnd).trim()}, __kept))` + take.slice(call.end);
    renderCode(out, 32, 0); // it must still render
    this.text = out; const R = this.evalRig(out); R.motions = this.R.motions; R.ground = this.R.ground; this.R = R; // the new look; the motions as they are in the editor
    await this.saveRig(); this.draw(); say(`${cur} has its new look; bones, limits, ground and motions are as they were`, 'ok');
  },
  // ---- the rig's motions and keys ----
  M() { return this.R && this.R.motions[this.motion] || null; }, K() { const M = this.M(); return M && this.keyI >= 0 ? M.keys[this.keyI] : null; },
  poseNow() { if (this.skel) return { pose: {}, root: { x: 0, y: 0 }, ik: {}, bend: {}, bendMix: {} }; const M = this.M(); if (!M) return { pose: {}, root: { x: 0, y: 0 }, ik: {} }; const K = this.K(); if (K) return { pose: K.pose || {}, root: K.root || { x: 0, y: 0 }, ik: K.ik || {}, bend: Rig.keyBend(M, this.keyI, this.R), bendMix: {} }; return Rig.pose(M, this.scrub, this.R); },
  flipBend() { const K = this.K(), M = this.M(); if (!K) { say('select a key first', 'err'); return; } const s = this.selJoint, c = (s && (s.chain || s.chainOf)) || this.lastChain || Object.keys(this.R.chains)[0]; if (!c) return; if (s && !s.chain && !s.chainOf) { say(`${s.name} is not on a limb with a joint to flip: select a hand, foot, elbow or knee`, 'err'); return; } this.snapshot(); K.bend = K.bend || {}; const now = Rig.bendAt(M, this.keyI, c, this.R.chains[c].bend || 1); K.bend[c] = -now; if (K.bend[c] === (this.R.chains[c].bend || 1)) delete K.bend[c]; this.draw(); say(`${c} folds the other way in this key`, 'ok'); },
  pickMotion(name) { this.stop(); this.motion = name; const M = this.M(); if (!M) { this.keyI = -1; this.tlUI(); this.draw(); return; } if (!M.keys) M.keys = []; M.keys.sort((a, b) => a.t - b.t); el('mSecs').value = M.secs || 1; el('mFps').value = M.fps || 12; el('mLoop').checked = M.loop !== false; this.keyI = M.keys.length ? 0 : -1; this.scrub = this.keyI >= 0 ? M.keys[0].t : 0; el('tlScrub').value = Math.round(this.scrub * 1000); this.tlUI(); this.draw(); },
  tlUI() {
    const M = this.M(), bar = el('tlBar'); for (const k of bar.querySelectorAll('.tlkey')) k.remove(); el('tlCursor').style.left = (this.scrub * 100) + '%';
    if (!M) { el('tlInfo').textContent = 'no motion: make one'; el('kInfo').textContent = ''; return; }
    M.keys.forEach((K, i) => { const d = document.createElement('div'); d.className = 'tlkey' + (i === this.keyI ? ' on' : ''); d.style.left = (K.t * 100) + '%'; const stack = M.keys.filter(o => Math.abs(o.t - K.t) < 0.005).length; d.title = `key at ${K.t} — click to select, drag to retime` + (stack > 1 ? ` (${stack} keys here: [ and ] step through them, Delete removes the selected one)` : ''); d.dataset.i = i; if (stack > 1) { d.style.outline = '2px solid #c2410c'; d.style.outlineOffset = '2px'; } bar.appendChild(d); });
    const stacked = M.keys.filter((K, i) => M.keys.some((o, j) => j !== i && Math.abs(o.t - K.t) < 0.005)).length;
    const K = this.K(); el('kFields').style.visibility = K ? 'visible' : 'hidden'; if (K) { el('kT').value = K.t; el('kEase').value = K.ease || 'io'; el('kInfo').textContent = `key ${this.keyI + 1} of ${M.keys.length}`; }
    el('tlInfo').textContent = `${this.motion}: ${M.keys.length} key${M.keys.length === 1 ? '' : 's'} · ${Rig.frames(M)} frames · cursor at ${this.scrub.toFixed(2)}${K ? ` · key ${this.keyI + 1} selected` : ' (tween)'}` + (this.selJoint ? ` · joint: ${this.selJoint.name}` : '') + (stacked ? ` · ${stacked} keys share a time (outlined): [ ] steps through them, Delete removes one` : '');
  },
  addKey() { const M = this.M(); if (!M) { say('make a motion first', 'err'); return; } const same = M.keys.findIndex(K => Math.abs(K.t - this.scrub) < 0.005); if (same >= 0) { this.keyI = same; this.tlUI(); this.draw(); say('a key is already at this time: it is selected', ''); return; } this.snapshot(); const P = Rig.pose(M, this.scrub, this.R); const bend = Object.assign({}, P.bend || {}); for (const [c, mx] of Object.entries(P.bendMix || {})) { const v = mx.u >= 0.5 ? mx.to : mx.from; if (v !== (this.R.chains[c].bend || 1)) bend[c] = v; } // the fold in force at the cursor comes along, so a new key continues the pose rather than swinging back
      const K = { t: Math.round(this.scrub * 100) / 100, pose: Object.assign({}, P.pose), root: Object.assign({}, P.root), ik: JSON.parse(JSON.stringify(P.ik)) }; if (Object.keys(bend).length) K.bend = bend; M.keys.push(K); M.keys.sort((a, b) => a.t - b.t); this.keyI = M.keys.indexOf(K); this.tlUI(); this.draw(); },
  dupKey() { const M = this.M(), K = this.K(); if (!K) return; this.snapshot(); let t = Math.min(1, Math.round((K.t + 0.1) * 100) / 100); while (M.keys.some(o => Math.abs(o.t - t) < 0.005) && t < 1) t = Math.round((t + 0.01) * 100) / 100; const C = JSON.parse(JSON.stringify(K)); C.t = t; M.keys.push(C); M.keys.sort((a, b) => a.t - b.t); this.keyI = M.keys.indexOf(C); this.scrub = t; el('tlScrub').value = Math.round(t * 1000); this.tlUI(); this.draw(); },
  holdPrev() { const M = this.M(), K = this.K(); if (!K || M.keys.length < 2) return; const prev = M.keys[(this.keyI - 1 + M.keys.length) % M.keys.length]; this.snapshot(); K.pose = JSON.parse(JSON.stringify(prev.pose || {})); K.root = Object.assign({}, prev.root || { x: 0, y: 0 }); K.ik = JSON.parse(JSON.stringify(prev.ik || {})); K.bend = JSON.parse(JSON.stringify(prev.bend || {})); this.draw(); say('this key holds the previous pose', 'ok'); },
  delKey() { const M = this.M(); if (!M || this.keyI < 0) return; this.snapshot(); M.keys.splice(this.keyI, 1); this.keyI = Math.min(this.keyI, M.keys.length - 1); this.tlUI(); this.draw(); },
  mirrorKey() { // left and right limbs trade places: bones and chains named …L / …R
    const K = this.K(); if (!K) return; this.snapshot(); const swap = n => /L$/.test(n) ? n.slice(0, -1) + 'R' : /R$/.test(n) ? n.slice(0, -1) + 'L' : n;
    const pose = {}; for (const [n, a] of Object.entries(K.pose || {})) pose[swap(n)] = a; K.pose = pose; const ik = {}; for (const [c, t] of Object.entries(K.ik || {})) ik[swap(c)] = t; K.ik = ik; const bend = {}; for (const [c, s] of Object.entries(K.bend || {})) bend[swap(c)] = s; K.bend = bend; this.draw();
  },
  newMotion() { const name = prompt('Name of the new motion (letters, digits, dashes):', 'idle'); if (!name || !/^[a-z][a-z0-9-]{0,20}$/.test(name)) return; if (this.R.motions[name] && !confirm(`${name} exists. Start it over?`)) return; this.snapshot(); this.R.motions[name] = { secs: 1, fps: 12, loop: true, keys: [{ t: 0, pose: {}, root: { x: 0, y: 0 }, ik: {} }] }; el('mMotion').innerHTML = Object.keys(this.R.motions).map(n => `<option>${esc(n)}</option>`).join(''); el('mMotion').value = name; this.pickMotion(name); },
  motionList(select) { el('mMotion').innerHTML = Object.keys(this.R.motions).map(n => `<option>${esc(n)}</option>`).join(''); el('mMotion').value = select; this.pickMotion(select); },
  dupMotion() { const M = this.M(); if (!M) return; const name = prompt(`Name for the copy of the motion "${this.motion}" (it stays inside the rig ${cur}; to copy the whole rig use "Duplicate sprite…" above):`, this.motion === 'run' ? 'attack' : this.motion + '-2'); if (!name || !/^[a-z][a-z0-9-]{0,20}$/.test(name)) return; if (this.R.motions[name] && !confirm(`${name} exists. Replace it?`)) return; this.snapshot(); this.R.motions[name] = JSON.parse(JSON.stringify(M)); this.motionList(name); el('rigMsg').textContent = `the motion ${name} is a copy of ${this.motion === name ? 'the motion' : this.motion}, inside ${cur}: pose it, then Save the rig`; },
  renameMotion() { const M = this.M(); if (!M) return; const name = prompt('New name for ' + this.motion + ':', this.motion); if (!name || name === this.motion || !/^[a-z][a-z0-9-]{0,20}$/.test(name)) return; if (this.R.motions[name] && !confirm(`${name} exists. Replace it?`)) return; this.snapshot(); this.R.motions[name] = M; delete this.R.motions[this.motion]; this.motionList(name); el('rigMsg').textContent = `renamed to ${name}: Save the rig to keep it (the game then calls Draw.rig('${cur}', '${name}', …))`; },
  delMotion() { if (!this.M() || !confirm(`Remove the motion ${this.motion}?`)) return; this.snapshot(); delete this.R.motions[this.motion]; const names = Object.keys(this.R.motions); el('mMotion').innerHTML = names.map(n => `<option>${esc(n)}</option>`).join(''); this.pickMotion(names[0] || ''); },
  play() { if (this.playing) { this.stop(); return; } const M = this.M(); if (!M) return; this.keyI = -1; el('mPlay').textContent = '■ stop'; let f = 0; const n = Rig.frames(M); this.playing = setInterval(() => { f = (f + 1) % n; this.scrub = f / n; el('tlScrub').value = Math.round(this.scrub * 1000); this.tlUI(); this.draw(); }, 1000 / (M.fps || 12)); },
  stop() { clearInterval(this.playing); this.playing = null; if (el('mPlay')) el('mPlay').textContent = '▶ play'; },
  // the motions written back into the rig's source: the `motions: { … }` block replaced, the rest untouched
  serialize() {
    const round = v => typeof v === 'number' ? Math.round(v * 1000) / 1000 : v;
    const clean = JSON.parse(JSON.stringify(this.R.motions, (k, v) => round(v)));
    const body = JSON.stringify(clean, null, 2).replace(/"([a-zA-Z_][a-zA-Z0-9_]*)":/g, '$1:').replace(/\n/g, '\n  ');
    let text = this.text; if (this.R.ground !== null && this.R.ground !== undefined) { if (/\bground\s*:\s*-?[0-9.]+/.test(text)) text = text.replace(/\bground\s*:\s*-?[0-9.]+/, 'ground: ' + this.R.ground); else text = text.replace(/(\bhalf\s*:\s*[0-9.]+\s*,)/, '$1 ground: ' + this.R.ground + ','); }
    const m = /motions\s*:\s*\{/.exec(text); if (!m) throw new Error('the rig file has no motions block to replace');
    let i = m.index + m[0].length - 1, depth = 0, j = i; for (; j < text.length; j++) { const ch = text[j]; if (ch === '{') depth++; else if (ch === '}') { depth--; if (depth === 0) break; } }
    if (depth !== 0) throw new Error('the motions block does not close'); return text.slice(0, m.index) + 'motions: ' + body + text.slice(j + 1);
  },
  // the rig's data as one const laid over the file's definition: an existing __kept is replaced, else the Rig.define call is wrapped
  withKept(text) { const raw = this.def || {}, lit = o => JSON.stringify(o, (k, v) => typeof v === 'number' ? Math.round(v * 10000) / 10000 : v, 2).replace(/"([a-zA-Z_][a-zA-Z0-9_]*)":/g, '$1:');
    const kept = { half: raw.half || this.R.half, ground: this.R.ground !== null && this.R.ground !== undefined ? this.R.ground : undefined, bones: raw.bones, chains: raw.chains, skin: raw.skin, motions: this.R.motions }, decl = `const __kept = ${lit(kept)};`;
    const at = text.indexOf('const __kept = {'); if (at >= 0) { const end = this.matchEnd(text, text.indexOf('{', at)); if (end < 0) throw new Error('the kept data does not close'); return text.slice(0, at) + decl + text.slice(text[end] === ';' ? end + 1 : end); }
    const call = this.defineCall(text); if (!call) throw new Error('the rig file has no Rig.define(key, { … }) call');
    return `// the rig's data (bones, limits, skin, ground, motions), kept by the editor and laid over the definition below\n${decl}\n` + text.slice(0, call.start) + `Rig.define('${cur}', Object.assign(${text.slice(call.argStart, call.argEnd).trim()}, __kept))` + text.slice(call.end); },
  matchEnd(text, i) { let depth = 0, q = null; for (let j = i; j < text.length; j++) { const ch = text[j], nx = text[j + 1]; if (q) { if (ch === '\\') { j++; continue; } if (ch === q) q = null; continue; } if (ch === '/' && nx === '/') { j = text.indexOf('\n', j); if (j < 0) return -1; continue; } if (ch === '/' && nx === '*') { j = text.indexOf('*/', j + 2) + 1; if (j <= 0) return -1; continue; } if (ch === '"' || ch === "'" || ch === '`') { q = ch; continue; } if ('([{'.includes(ch)) depth++; else if (')]}'.includes(ch)) { depth--; if (depth === 0) return j + 1; } } return -1; },
  async saveRig() {
    if (!cur || !this.R) return; let text; try { text = this.withKept(this.text); renderCode(text, 32, 0); } catch (e) { el('rigMsg').textContent = 'not saved: ' + e.message; return; }
    el('rigSave').disabled = true; try { const r = await fetch(apiUrl('spritesave'), { method: 'POST', headers: apiHeaders(), body: JSON.stringify({ key: cur, rig: text, source: entry.source || 'editor' }) }); const j = await r.json(); if (!r.ok) throw new Error(j.error || r.status); this.text = text; this.skelDirty = false; el('skelMsg').textContent = ''; el('rigMsg').textContent = `saved ${Object.keys(this.R.motions).length} motion${Object.keys(this.R.motions).length === 1 ? '' : 's'} (${Object.keys(this.R.motions).join(', ')}) into assets/${j.sprite.file}`; say(`rig saved · Draw.rig('${cur}', '${this.motion}', Game.time, x, y, ${j.sprite.w})`, 'ok'); }
    catch (e) { el('rigMsg').textContent = 'not saved: ' + e.message; } finally { el('rigSave').disabled = false; }
  },
};
