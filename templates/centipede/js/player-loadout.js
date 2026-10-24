// The loadout (the blueprint): the body in order, regenerating lost pieces from what you carry, and the bag.
// Extends Player (see player.js). Loads after it.
Object.assign(Player, {
  // ---- the blueprint: the body in order; rebuild lost pieces from the parts you are carrying and put them back in their place ----
  // the padlock: lock = this body, in this order, is my build (rebuilt and re-ordered when pieces are lost); unlock = forget it
  lock(quiet) {
    this.snapshot(); this.autoRebuild = true;
    if (!quiet) { SFX.play('loadout'); G.msg(`Loadout saved: ${this.blueprint.map(id => BULLETS[id].name).join(', ')}. Lost pieces are regenerated and put back in this order, and new pickups go to the bag. Only the padlock changes it.`, '#ffd166'); }
  },
  lockMatches() { return this.segments.filter(s => !this.isMinion(s)).map(s => s.id).join(',') === this.blueprint.join(','); }, // the body is exactly the lock, in its order
  unlock() { this.blueprint = []; this.autoRebuild = false; SFX.play('shed'); G.msg('Loadout cleared: lost pieces won\'t be rebuilt until you save one again.', '#aaa'); },
  // the body order becomes the blueprint. `keep` = ids that were already missing when the workbench opened (lost, not edited away):
  // they stay in, at their old place, while the body has room for them
  snapshot(keep) {
    const old = this.blueprint, bp = this.segments.filter(s => !this.isMinion(s)).map(s => s.id);
    if (keep && keep.length) {
      keep = keep.slice(); const h = this.holdings();
      old.forEach((id, i) => { if (h[id] > 0) { h[id]--; return; } const k = keep.indexOf(id); if (k < 0 || bp.length >= this.maxSegs) return; keep.splice(k, 1); bp.splice(Math.min(i, bp.length), 0, id); });
    }
    this.blueprint = bp;
  },
  // put the body in blueprint order: each entry takes its matching segment (or pulls one from the bag when there is room); extras follow, minions stay the tail
  arrange() {
    const bp = this.blueprint; if (!bp.length) return false;
    const weapons = this.segments.filter(s => !this.isMinion(s)), minions = this.segments.filter(s => this.isMinion(s)), used = new Set(), ordered = [];
    for (const id of bp) {
      const j = weapons.findIndex((s, k) => s.id === id && !used.has(k));
      if (j >= 0) { used.add(j); ordered.push(weapons[j]); continue; }
      const bi = this.bag.indexOf(id);
      if (bi >= 0 && ordered.length + (weapons.length - used.size) < this.maxSegs) { this.bag.splice(bi, 1); ordered.push(this.mkSeg(id)); }
    }
    const extras = []; // not part of the locked build: off to the bag (the lock owns its empty slots), unless the bag has no room for that kind
    weapons.forEach((s, k) => { if (used.has(k)) return; if (!this.bagFull(s.id)) { this.bag.push(s.id); extras.push(BULLETS[s.id].name); } else ordered.push(s); });
    if (extras.length) G.msg(`${extras.join(', ')} moved to the bag: your loadout keeps the body as set.`, '#ffe9a8');
    const changed = ordered.length !== weapons.length || ordered.some((s, k) => s !== weapons[k]);
    if (changed) { this.segments = ordered.concat(minions); this.resolveBuild(); this.computeSegPos(); }
    return changed;
  },
  // what a crafted bullet is made of (the first recipe that makes it; ricochet versions are base + Bouncer)
  partsOf(id) { const B = BULLETS[id]; if (!B) return null; if (B.ricLevel) return [B.ricLevel > 1 ? B.ricRoot + '_ric' + (B.ricLevel - 1) : B.ricRoot, 'bouncer']; const rc = RECIPES.find(r => r[2] === id); return rc ? [rc[0], rc[1]] : null; },
  // how many of each id you hold (body + bag), and which blueprint entries are not covered by that
  holdings() { const h = {}; for (const s of this.segments) h[s.id] = (h[s.id] || 0) + 1; for (const id of this.bag) h[id] = (h[id] || 0) + 1; return h; },
  missingBlueprint() { const h = this.holdings(), miss = []; for (const id of this.blueprint) { if (h[id] > 0) h[id]--; else miss.push(id); } return miss; },
  // dry run: can `id` be made from `pool` (a copy of holdings minus what other blueprint entries need)? Returns { base: {id: n}, dots } or null.
  // Base bullets on the body that are themselves part of the blueprint are never consumed; everything in the bag is fair game.
  plan(id, pool, depth) {
    depth = depth || 0; if (depth > 6) return null;
    if (pool[id] > 0) { pool[id]--; return { dots: 0 }; }
    const parts = this.partsOf(id); if (!parts) return null;
    let dots = this.craftCost(id);
    for (const p of parts) { const sub = this.plan(p, pool, depth + 1); if (!sub) return null; dots += sub.dots; }
    return { dots };
  },
  // make `id` for real: build any missing part first (recursively), then fuse the two parts, bag items first
  build(id, depth) {
    depth = depth || 0; if (depth > 6) return false;
    const have = () => { const bag = this.bag.indexOf(id); if (bag >= 0) return { where: 'bag', i: bag }; const body = this.segments.findIndex(s => s.id === id && this.spare(s.id)); return body >= 0 ? { where: 'body', i: body } : null; };
    const parts = this.partsOf(id); if (!parts) return false;
    for (const p of parts) { if (!this.locate(p)) { if (!this.build(p, depth + 1)) return false; } }
    const A = this.locate(parts[0]); if (!A) return false;
    const B = this.locate(parts[1], A); if (!B) return false;
    return this.craft(A, B, true) === id;
  },
  // a body bullet is spare if it is not itself keeping a blueprint entry satisfied
  spare(bid) { if (!BULLETS[bid].craft) return true; const want = this.blueprint.filter(x => x === bid).length, held = this.segments.filter(s => s.id === bid).length + this.bag.filter(x => x === bid).length; return held > want; },
  locate(id, notThis) { for (let i = 0; i < this.bag.length; i++) if (this.bag[i] === id && !(notThis && notThis.where === 'bag' && notThis.i === i)) return { where: 'bag', i }; for (let i = 0; i < this.segments.length; i++) if (this.segments[i].id === id && this.spare(id) && !(notThis && notThis.where === 'body' && notThis.i === i)) return { where: 'body', i }; return null; },
  // called after pickups, dots and dungeons: rebuild whatever is missing and affordable, strongest first
  rebuild() {
    if (!this.autoRebuild || !this.blueprint.length) return;
    const miss = this.missingBlueprint().filter(id => BULLETS[id].craft); // base bullets cannot be made, only found
    miss.sort((a, b) => BULLETS[b].tier - BULLETS[a].tier);
    const made = [];
    for (const id of miss) {
      const pool = this.holdings(); for (const b of this.blueprint) if (pool[b] > 0) pool[b]--; // what the rest of the build already uses stays untouched
      const pl = this.plan(id, pool); if (!pl || pl.dots > G.dots) continue;
      const before = G.dots; if (this.build(id)) made.push(`${BULLETS[id].name} (${before - G.dots} dots)`);
    }
    if (made.length) { this.resolveBuild(); this.computeSegPos(); SFX.play('loadout'); G.msg(`BLUEPRINT: regenerated ${made.join(', ')} from the parts you found.`, '#ffd166'); if (G.state === 'playing') G.banner = { text: 'REGENERATED', sub: made.map(m => m.split(' (')[0]).join(' · '), t: 2 }; }
    this.arrange(); // back into the build's order (status effects fire in body order, so the order is part of the build)
  },
  // the base bullets a blueprint entry still needs (not in the pool) and the dots its missing fusions cost
  needs(id, pool, out, depth) {
    out = out || { parts: [], dots: 0 }; depth = depth || 0; if (depth > 6) return out;
    if (pool[id] > 0) { pool[id]--; return out; }
    const parts = this.partsOf(id); if (!parts) { out.parts.push(id); return out; }
    out.dots += this.craftCost(id);
    for (const q of parts) this.needs(q, pool, out, depth + 1);
    return out;
  },
  // the rebuild preview: every blueprint entry, held or not, with the base bullets and dots it still needs, and the set of wanted bullet ids
  rebuildPlan() {
    const pool = this.holdings(), entries = [], wanted = new Set();
    for (const b of this.blueprint) if (pool[b] > 0) pool[b]--; // what the held part of the build uses stays untouched
    for (const id of [...new Set(this.blueprint)]) {
      const want = this.blueprint.filter(x => x === id).length, held = Math.min(want, this.holdings()[id] || 0);
      const e = { id, want, held, parts: [], dots: 0 };
      for (let k = held; k < want; k++) { const nd = this.needs(id, pool); e.parts.push(...nd.parts); e.dots += nd.dots; }
      e.parts.forEach(q => wanted.add(q));
      entries.push(e);
    }
    return { entries, missing: entries.filter(e => e.held < e.want), wanted };
  },
  // a short status line for the workbench: every blueprint entry, held or what it still needs
  blueprintLines() {
    const h = this.holdings(), out = [];
    for (const id of [...new Set(this.blueprint)]) { const want = this.blueprint.filter(x => x === id).length, held = Math.min(want, h[id] || 0); let s = BULLETS[id].name + (want > 1 ? ` ×${want}` : ''); if (held >= want) s += ' ✓'; else { const pool = this.holdings(); for (const b of this.blueprint) if (pool[b] > 0) pool[b]--; const pl = this.plan(id, pool); s += pl ? (pl.dots > G.dots ? ` (parts in hand: needs ${pl.dots} dots)` : ' (rebuilding...)') : ` (needs ${(this.partsOf(id) || []).map(p => BULLETS[p].name).join(' + ')})`; } out.push(s); }
    return out;
  },
  // trash a bullet: it is gone, but it transmutes into a few dots toward your next fusion
  destroy(L) { const id = this.idAt(L); if (!id) return null; this.takeAt(L); this.resolveBuild(); G.dots += CFG.TRASH_DOTS; return id; }, // (a locked piece stays in the lock: only the padlock changes it)
  fromBag(b, i) { // put bag item b into body slot i (swap) or append if there is room
    if (b < 0 || b >= this.bag.length) return false;
    const id = this.bag[b];
    if (i >= 0 && i < this.segments.length) { const old = this.segments[i]; if (this.isMinion(old)) return false; this.bag[b] = old.id; this.segments[i] = this.mkSeg(id); }
    else if (this.weaponCount() < this.maxSegs) { this.bag.splice(b, 1); this.segments.splice(this.firstMinion(), 0, this.mkSeg(id)); } // weapons go in ahead of the riding minions
    else return false;
    this.resolveBuild(); return true;
  },
  firstMinion() { const i = this.segments.findIndex(s => this.isMinion(s)); return i < 0 ? this.segments.length : i; },
  bagMax() { return G.god ? 99 : CFG.BAG_MAX; }, // kinds of bullet the bag holds: a stack of one kind counts once
  bagTypes() { return new Set(this.bag).size; },
  stackOf(id) { let n = 0; for (const b of this.bag) if (b === id) n++; return n; },
  stackFull(id) { return !G.god && this.stackOf(id) >= CFG.STACK_MAX; },
  bagFull(id) { return this.stackFull(id) || (!this.bag.includes(id) && this.bagTypes() >= this.bagMax()); }, // full for `id`: a new kind when every kind-slot is taken (a kind you already carry always stacks)
  // level complete: the body is reset to `n` segments (the strongest stay, in their order) and the cap goes back to `n`;
  // the rest go to the bag so a crafted bullet is never lost. Returns { kept, bagged, lost }.
  shrinkTo(n) {
    const segs = this.segments.map((s, i) => ({ s, i, tier: BULLETS[s.id].tier }));
    const keep = new Set(segs.slice().sort((a, b) => b.tier - a.tier || a.i - b.i).slice(0, n).map(o => o.i));
    const kept = [], out = { kept: 0, bagged: 0, lost: 0 };
    for (const o of segs) { if (keep.has(o.i)) { o.s.para = null; kept.push(o.s); out.kept++; } else if (!this.bagFull(o.s.id)) { this.bag.push(o.s.id); out.bagged++; } else out.lost++; }
    this.segments = kept; this.maxSegs = n; this.headSlow = 0; this.resolveBuild(); this.computeSegPos();
    return out;
  },
  // the bag shown as stacks: one entry per bullet type, in first-seen order, with every index that holds it
  bagGroups() { const m = new Map(); this.bag.forEach((id, i) => { if (!m.has(id)) m.set(id, { id, idx: [] }); m.get(id).idx.push(i); }); return [...m.values()].sort((a, b) => BULLETS[a.id].name.localeCompare(BULLETS[b.id].name)); }, // alphabetical, so a stack never jumps when you trash or place one of it
  wouldTake(id, pk) { return (pk && pk.loose) || this.isGear(id) || (this.weaponCount() < this.maxSegs && !this.lockedShort()) || !this.bagFull(id); }, // a loose piece of your own body always fits
  // a new bullet joins the body if there is room, otherwise it goes in the bag. Returns 'body' | 'bag' | null.
  // a locked build owns its empty slots: a short body is a choice (fewer segments, harder to hit), so new pickups go to the bag, not the body
  lockedShort() { return this.autoRebuild && this.blueprint.length > 0; },
  wantedNow(id) { return this.lockedShort() && this.rebuildPlan().wanted.has(id); }, // the locked build is missing this (or a part that makes it): it may go straight onto the body
  addSegment(seg, force) { // force: a piece of your own lost segment always fits (the bag may run over its cap until the rebuild fuses it)
    if (this.weaponCount() >= this.maxSegs || (this.lockedShort() && !force && !this.wantedNow(seg.id))) {
      if (this.bagFull(seg.id) && !force) return null;
      this.bag.push(seg.id); return 'bag';
    }
    this.segments.splice(this.firstMinion(), 0, seg); // ahead of the riding minions, which stay the tail
    this.maxLen = Math.max(this.maxLen, this.segments.length);
    G.maxLen = Math.max(G.maxLen, this.segments.length);
    this.resolveBuild();
    return 'body';
  },
  removeAt(i, cause) {
    if (i < 0 || i >= this.segments.length) return;
    const p = this.segPos[i], s = this.segments[i];
    if (p) Combat.burst(p.x, p.y, 10, this.segColor(s), 2.5);
    this.segments.splice(i, 1);
    this.resolveBuild();
    SFX.play('lose');
    G.shake = Math.max(G.shake, 0.18);
    if (G.mode === 'world' && cause !== 'shed' && cause !== 'sacrifice' && !this.isMinion(s) && p) this.scatter(s.id, p, !this.isCrash(cause));
    else if (G.mode === 'deck' && cause !== 'shed' && cause !== 'sacrifice' && !this.isMinion(s) && p) Deck.drop(s.id, p); // on the deck the pieces pop up and fall back for you to catch // every loss bursts into its parts: a crash throws them away from what you hit, an attack sprays them out all round (Sonic's rings) // a crash scatters the pieces; an enemy destroys the segment outright
    if (this.autoRebuild && cause !== 'shed') { const gap = this.missingBlueprint().length; this.rebuild(); if (this.missingBlueprint().length < gap) G.msg('A spare from the pouch took its place.', '#9bf06a'); } // a spare in the pouch (or the parts and dots) fills the gap at once
  },
});
