// The body as a build: piping, element synergy runs, segment counts, the dps estimates (and the load-time fusion tuning), and crafting.
// Extends Player (see player.js). Loads after it.
Object.assign(Player, {
  // ---- build ----
  elemOf(s) { return (BULLETS[s.id].elems || [])[0] || null; },
  // jam = this segment can't fire right now. A feeding parasite jams its segment and every segment behind it.
  resolveBuild() {
    const segs = this.segments;
    let speedMult = this.headSlow > 0 ? 0.75 : 1, jamFrom = -1;
    for (let i = 0; i < segs.length; i++) {
      const s = segs[i];
      if (s.para) { if (jamFrom < 0) jamFrom = i; if (s.para.id === 'slug') speedMult *= 0.85; }
      s.jam = jamFrom >= 0;
      s.maxInflight = BULLETS[s.id].maxInflight || 1;
      // piping: the element (or laser glare) of the segment ahead leaks into this one
      s.pipe = null;
      if (i === 0 && this.gear) { const g = BULLETS[this.gear].gear.pipe, B0 = BULLETS[s.id]; if (g && !(ELEMENTS[g] && (B0.elems || []).length) && !(g === 'dry' && B0.spear) && B0.kind !== 'trail' && !B0.minion) s.pipe = g; } // head gear feeds segment 1
      if (i > 0) { // the segment ahead leaks: a bouncing bullet ahead pipes +ricochet into a shot or moth (unless it bounces already), a laser pipes +dazzle, an element pipes its minor status
        const F = BULLETS[segs[i - 1].id], B0 = BULLETS[s.id], mine = B0.elems || [], bouncy = F.kind === 'bounce' || F.bounces > 0, canRic = (B0.kind === 'shot' || B0.kind === 'homing') && !(B0.bounces > 0);
        const src = bouncy && canRic ? 'ricochet' : F.shards && !B0.shards && B0.kind !== 'trail' ? 'split' : F.kind === 'homing' && B0.kind === 'shot' && !B0.laser ? 'homing' : F.burst && !B0.burst && (B0.kind === 'shot' || B0.kind === 'homing' || B0.kind === 'bounce' || B0.kind === 'boomerang') ? 'burst' : F.spear && !(F.elems || []).length && !B0.spear ? 'dry' : F.laser ? 'dazzle' : (F.elems || [])[0]; if (src && !(ELEMENTS[src] && mine.length)) s.pipe = src; } // an element only soaks into a weapon with none of its own (fused weapons are the mixed ones); behaviours (ricochet, split, homing, burst, dry, dazzle) go into anything // (+split: a Splitter, Cascade or Shatter ahead makes this one's hits burst into shards; +homing: a moth ahead bends this one's shots after enemies)
    }
    this.speedMult = Math.max(0.4, speedMult);
    { // element synergy: CFG.SYNERGY.need segments of one element switch its bonus on (riding minions do not count)
      const n = {}, runs = this.synRuns(); if (CFG.SYNERGY.inRow) { for (const r of runs.all) n[r.el] = Math.max(n[r.el] || 0, r.len); } else for (const s of segs) { const B = BULLETS[s.id]; if (!B || B.minion) continue; for (const el of new Set(B.elems || [])) n[el] = (n[el] || 0) + 1; }
      this.synAt = runs.at; // which segments glow, and with what
      // charged piping (+++): every run of CFG.SYNERGY.need or more charges ALL the guns behind it with that element's status (bone: +++dry),
      // stacking run after run down the body; a segment whose own pipe matches a charge ahead pipes it charged too
      for (const s of segs) { s.pipeBoost = false; s.charged = null; }
      for (const r of runs.all) { const kind = r.el === 'bone' ? 'dry' : r.el; if (r.len < CFG.SYNERGY.need || !PIPES[kind]) continue; for (let i = r.from + r.len; i < segs.length; i++) { const s = segs[i]; if (BULLETS[s.id].minion) continue; if (!s.charged) s.charged = []; if (!s.charged.includes(kind)) s.charged.push(kind); } }
      for (const s of segs) if (s.pipe && s.charged && s.charged.includes(s.pipe)) s.pipeBoost = true;
      const was = this.syn || {}, on = {}; for (const el in n) if (n[el] >= CFG.SYNERGY.need) on[el] = true;
      this.synN = n; this.syn = on;
      if (G.state === 'playing' || G.state === 'paused') for (const el in on) if (!was[el]) { G.msg(`${synInfo(el).name.toUpperCase()} SYNERGY: ${this.synText(el)}`, synInfo(el).color); SFX.play('synergy'); }
    }
  },
  // runs of segments side by side sharing an element (riding minions break a run): all = every run { el, from, len }; at[i] =
  // the elements segment i glows with, from a run long enough to count (CFG.SYNERGY.need)
  synRuns() {
    const segs = this.segments, all = [], at = segs.map(() => []), K = CFG.SYNERGY;
    for (const el of Object.keys(ELEMENTS).concat(Object.keys(SYN_EXTRA))) {
      let from = -1;
      for (let i = 0; i <= segs.length; i++) {
        const B = i < segs.length ? BULLETS[segs[i].id] : null, has = B && !B.minion && (SYN_EXTRA[el] ? SYN_EXTRA[el].has(B) : (B.elems || []).includes(el));
        if (has && from < 0) from = i;
        if (!has && from >= 0) { const len = i - from; all.push({ el, from, len }); if (len >= K.need) for (let k = from; k < i; k++) at[k].push(el); from = -1; }
      }
    }
    return { all, at };
  },
  // what an element's synergy does, in a line (the workbench, the PERKS tab, the message when it switches on)
  synText(el) {
    const K = CFG.SYNERGY, pen = `pierces ${Math.round(K.pen * 100)}% resist`;
    if (el === 'bone') return `dried-out foes take +${Math.round((CFG.DRY.synergy - 1) * 100)}% from everything`;
    return (el === 'electric' ? `sparks arc to ${K.arcs} more enemy · ${pen}` : el === 'fire' ? `burning enemies take +${Math.round((K.burningTaken - 1) * 100)}% · ${pen}` : el === 'ice' ? `frozen enemies take +${Math.round((K.frozenTaken - 1) * 100)}% · ${pen}` : `clouds last ×${K.cloudTime} and spread ×${K.cloudSize} · ${pen}`);
  },
  mkSeg(id) { return { kind: 'weapon', id, cd: 0, inflight: 0, jam: false, para: null }; },
  isMinion(s) { return !!BULLETS[s.id].minion; },
  weaponCount() { let n = 0; for (const s of this.segments) if (!BULLETS[s.id].minion) n++; return n; }, // minion segments take no slot
  minionCount() { return this.segments.length - this.weaponCount(); },
  // ---- build editing (pause screen) ----
  moveSeg(i, dir) { const j = i + dir; if (i < 0 || j < 0 || i >= this.segments.length || j >= this.segments.length) return false; const t = this.segments[i]; this.segments[i] = this.segments[j]; this.segments[j] = t; this.resolveBuild(); return true; },
  toBag(i) { if (i < 0 || i >= this.segments.length || this.bagFull(this.segments[i].id) || this.isMinion(this.segments[i])) return false; const s = this.segments[i]; if (s.para) s.para = null; this.segments.splice(i, 1); this.bag.push(s.id); this.resolveBuild(); return true; },
  // rough time-to-kill estimate against a target (for the god-mode tuning readout). Includes armor on plain
  // bullets, a flat hit chance for dodging and stones, and a bonus for elements and combos.
  // one bullet type's damage per second at base stats (no perks, no level ramp, no armor): the yardstick for tuneFusions
  rawDps(B) {
    if (!B || B.minion) return 0;
    const flight = B.life || (B.range && B.speed ? B.range / B.speed : 0.5), cycle = B.cd + (B.kind === 'orbit' || B.kind === 'pulse' ? flight : flight * 0.9);
    let per = B.dmg || 0; if ((B.elems && B.elems.length) || B.combo) per *= 1.3; if (B.craft) per *= B.tier >= 4 ? 3.2 : 2.4;
    return per * (B.maxInflight || 1) * (B.burst || 1) / Math.max(0.05, cycle);
  },
  // at load: every fusion must beat its two ingredients together by CFG.BALANCE.fuseFloor, or it was a trap to craft
  tuneFusions() {
    const K = CFG.BALANCE, tuned = [];
    const rs = RECIPES.filter(([a, b, r]) => BULLETS[a] && BULLETS[b] && BULLETS[r] && BULLETS[r].kind !== 'trail').sort((x, y) => BULLETS[x[2]].tier - BULLETS[y[2]].tier);
    for (let pass = 0; pass < 3; pass++) for (const [a, b, r] of rs) {
      const R = BULLETS[r], need = K.fuseFloor * (this.rawDps(BULLETS[a]) + this.rawDps(BULLETS[b])), have = this.rawDps(R);
      if (have <= 0 || have >= need * 0.999) continue;
      const k = Math.min(need / have, K.fuseBoostMax / (R.tuned || 1)); if (k <= 1.001) continue;
      R.dmg = Math.round(R.dmg * k * 100) / 100; R.tuned = (R.tuned || 1) * k; tuned.push(`${R.name} ×${R.tuned.toFixed(2)}`);
    }
    if (tuned.length) console.log('[balance] fusion damage raised:', [...new Set(tuned)].join(', '));
  },
  estimateDps(armor, hitChance) {
    let dps = 0;
    const segs = this.segments.length ? this.segments : [this.head];
    for (const s of segs) {
      const B = BULLETS[s.id]; if (!B || s.jam) continue;
      if (B.minion) { dps += BULLETS.bullet.dmg * G.powerMult * CFG.MINIONS.spitMult / CFG.MINIONS.spitCd * 0.5; continue; } // a riding minion's spit, roughly
      if (B.range && B.range * G.mods.range < 280) { dps += 0; continue; } // too short to reach a boss that keeps its distance
      const flight = B.life ? B.life * G.mods.range : (B.range * G.mods.range) / (B.speed * G.mods.bspeed);
      const cycle = B.cd * G.mods.cd + (B.kind === 'orbit' || B.kind === 'pulse' ? flight : flight * 0.9);
      const twin = (B.kind === 'shot' || B.kind === 'bounce' || B.kind === 'homing' || B.kind === 'boomerang') ? 1 + (G.mods.twin || 0) * Perks.twinMult(G.mods.twin || 0) : 1; // Twin Shot copies at their share (40%, 30% each, 25% each...)
      const shots = (B.maxInflight || 1) * (B.burst || 1) * twin / Math.max(0.05, cycle);
      let per = B.dmg * G.powerMult * Combat.ramp(B); // the level ramp is real damage: a boss must be sized for it
      const plain = !(B.elems && B.elems.length) && !B.combo;
      if (plain) per *= armor; else per *= 1.3 * G.mods.elem; // status damage over time, roughly
      if (B.craft) per *= B.tier >= 4 ? 3.2 : 2.4; // calibrated against autopilot boss fights: pierce, combos and the mega all add up
      dps += per * shots;
    }
    return dps * hitChance;
  },
  // ---- crafting ----
  // a location is { where: 'body'|'bag', i }. idAt / takeAt / putAt work on both.
  idAt(L) { return L.where === 'body' ? (this.segments[L.i] && this.segments[L.i].id) : this.bag[L.i]; },
  takeAt(L) { if (L.where === 'body') { const s = this.segments.splice(L.i, 1)[0]; return s && s.id; } return this.bag.splice(L.i, 1)[0]; },
  putAt(L, id) { if (L.where === 'body') this.segments.splice(L.i, 0, this.mkSeg(id)); else this.bag.splice(L.i, 0, id); },
  canCraft(A, B) { const a = this.idAt(A), b = this.idAt(B); if (!a || !b || (A.where === B.where && A.i === B.i)) return null; return findRecipe(a, b); },
  craftCost(r) { return CFG.CRAFT_COST[BULLETS[r].tier] || 20; },
  // every fusion you could make right now from the body + bag, one entry per recipe, with the two locations to use
  fusions() {
    // memoised: the body, the bag and the dot count decide the answer, and the HUD, the workbench and the guide all ask every frame
    const key = this.segments.map(s => s.id).join(',') + '|' + this.bag.join(',') + '|' + G.dots + '|' + (this.gears || []).join(',');
    if (this._fuseKey === key) return this._fuseList;
    const out = this.fusionsRaw(); this._fuseKey = key; this._fuseList = out; return out;
  },
  fusionsRaw() {
    // pairs over the body's segments and ONE bullet per bag kind (a stack of ten Pebbles is one candidate, not ten), plus
    // each stack of two or more paired with itself (Bullet + Bullet). A bag of 200 items is then a few hundred pairs, not 20,000
    const items = this.segments.map((s, i) => ({ where: 'body', i, id: s.id })), groups = this.bagGroups();
    for (const g of groups) items.push({ where: 'bag', i: g.idx[0], id: g.id });
    const seen = new Set(), out = [], add = (A, B) => { const r = findRecipe(A.id, B.id); if (!r || (this.isGear(r) && this.ownsGear(r))) return; /* (head gear already on the rack: no card) */ const key = [A.id, B.id].sort().join('+'); if (seen.has(key)) return; seen.add(key); out.push({ a: A.id, b: B.id, r, A, B, cost: this.craftCost(r) }); };
    for (let x = 0; x < items.length; x++) for (let y = x + 1; y < items.length; y++) add(items[x], items[y]);
    for (const g of groups) if (g.idx.length >= 2) add({ where: 'bag', i: g.idx[0], id: g.id }, { where: 'bag', i: g.idx[1], id: g.id });
    // affordable first, then the strongest result
    return out.sort((p, q) => ((G.dots >= q.cost) - (G.dots >= p.cost)) || (BULLETS[q.r].tier - BULLETS[p.r].tier));
  },
  // fuse A and B (costs dots). The body takes precedence: if either ingredient is on the body the result lands in that
  // slot (the other slot is freed); two bag bullets make a result that joins the body when there is room, else stays in the bag.
  craft(A, B, quiet, to) { // to: 'bag' (always the bag), 'body' (on the centipede: the body ingredient's slot, or a free slot), or the default
    const r = this.canCraft(A, B); if (!r) return null;
    const cost = this.craftCost(r); if (G.dots < cost) { G.msg(`${BULLETS[r].name} needs ${cost} dots (you have ${G.dots}). Eat dots in a dungeon.`, '#ff9b8b'); SFX.play('lose'); Build.need = { t: 3, r, cost, have: G.dots }; return null; }
    G.dots -= cost;
    if (A.where !== 'body' && B.where === 'body') { const t = A; A = B; B = t; } // the body ingredient is the one whose slot keeps the result
    // take B first if it is after A in the same list, so A's index stays valid
    const order = (A.where === B.where && B.i > A.i) || A.where !== B.where ? [B, A] : [A, B];
    const taken = {}; for (const L of order) taken[L === A ? 'a' : 'b'] = this.takeAt(L);
    // A's index may have shifted if B was before it in the same list
    const ai = A.where === B.where && B.i < A.i ? A.i - 1 : A.i;
    if (this.isGear(r)) { const bare = !this.gear; this.gainGear(r, false, true); this.lastCraft = { where: 'gear', worn: bare }; this.resolveBuild(); return r; } // head gear: onto the hat rack (and the head, if it is bare: only the rack swaps hats)
    // by default the result takes the body ingredient's slot, and a craft from the bag alone lands in the bag; the LAB's buttons
    // can send it to the bag either way, or put a bag-only craft straight on the body when there is a free slot. Either way it
    // wears the lab's lightning frame until you touch it or the workbench opens again (seg.fresh, Player.freshBag)
    let L = { where: A.where, i: ai };
    if (to === 'bag') L = { where: 'bag', i: this.bag.length };
    else if (to === 'body' && A.where === 'bag' && this.weaponCount() < this.maxSegs) L = { where: 'body', i: this.firstMinion() };
    this.putAt(L, r); const at = L.i; if (L.where === 'body') { this.maxLen = Math.max(this.maxLen, this.segments.length); G.maxLen = Math.max(G.maxLen, this.segments.length); }
    if (L.where === 'body') { this.lastCraft = { where: 'body', i: at }; this.segments[at].fresh = true; } else { this.lastCraft = { where: 'bag', i: at, id: r }; this.freshBag.add(r); } // (lastCraft: where it landed, for the workbench's animation)
    if (!quiet && !this.autoRebuild && !this.lockHint) { this.lockHint = true; G.msg('Tip: save a loadout (the padlock, B) and lost pieces are rebuilt in place.', '#ffe9a8'); } // (a locked build follows your workbench edits by itself)
    this.resolveBuild(); return r;
  },
});
