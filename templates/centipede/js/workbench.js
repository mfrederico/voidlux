// Pause-screen workbench. Items live in two rows: the body and the bag.
//   A/D: move the cursor.   W/S: switch rows.   Q/E: shift a body segment toward head / tail.
//   X: drop a body segment into the bag.   ENTER (bag): add/swap the bag bullet into the body.
//   C: mark the selected item as an ingredient, then select another and press C to FUSE them (one slot).
//   DEL / BACKSPACE: destroy the selected item.   ESC: cancel a mark.
// Four tabs (Render.drawPaused in render-bench*.js): LOADOUT (panel 'body': the centipede, the blueprint, the dps readout, the
// gallery), BAG (panel 'forge': the bag, drag one onto another to fuse, the inspector), LAB (the recipe cards and the reactor)
// and POWERS (panel 'powers': a hub of three big buttons opening the HATS, MEGAS and PERKS pages). TAB, the tabs, or a swipe
// on a phone switches; ESC or the back button on a POWERS page returns to the hub. Every fuse
// goes through Build.fuse so BenchFx (benchfx.js) can play the choreography: the model changes instantly, the animation follows.
const Build = {
  row: 'body', sel: 0, bagSel: 0, mark: null, flash: null,
  panel: 'forge', help: false, inspKey: null, inspT: 0, press: null,
  cardSel: 0, cardFirst: 0, // the crafting cards: the selected one, and the first one shown (the grid pages when there are more than fit)
  hit: { body: [], bag: [], fusions: [], buttons: [], tabs: [], trash: null, bodyEnd: null }, // rects recorded by Render.drawPaused
  drag: null, // { from:{where,i}, id, x, y, sx, sy, moved }
  need: null, // { t, r, cost, have } shown after a fuse was refused for lack of dots
  // the workbench opens: remember the body and what was already missing, so leaving it can tell your edits from your losses
  open(panel) { this.picked = null; this.need = null; this.megaArm = null; this.fly = null; this.held = null; this.openBody = Player.segments.map(s => s.id).join(','); this.lockAsked = false; this.followLock = false; this.ghost = false; this.help = false; this.press = null; this.inspT = 0; this.panel = panel || (Player.bag.length ? 'forge' : 'body'); if (this.panel === 'lab') this.row = 'cards'; this.ask = null; for (const s of Player.segments) s.fresh = false; Player.freshBag.clear(); /* last visit's crafts lose their lightning */ Input.pinch = null; Input.pts.clear(); BenchFx.reset(); Gallery.reset(); },
  // take body segment i off into the bag (X, DEL on the BODY page, the STASH square)
  stash(i) { const P = Player, s = P.segments[i]; if (!s) return; s.fresh = false; if (P.isMinion(s)) { G.msg('A riding minion cannot go in the bag.', '#ff9b8b'); return; } const src = BenchFx.rectOf({ where: 'body', i }); if (P.toBag(i)) { SFX.play('shed'); this.mark = null; this.anim(s.id, src, { where: 'bag', id: s.id }); G.msg(`${BULLETS[s.id].name} taken off: it is in the bag.`, '#ccc'); } else G.msg(`The bag has no room for ${BULLETS[s.id].name}.`, '#ff9b8b'); },
  PANELS: ['body', 'forge', 'lab', 'powers'], POWER_PAGES: ['hats', 'megas', 'perks'], lastPower: null, hubSel: 0, perkSel: 0, megaSel: 0, gearSel: 0, pillSel: 0, held: null, // held: a body segment picked up with ENTER, to swap with the next one ENTER lands on (a controller's way to reorder)
  tabOf(p) { return this.POWER_PAGES.includes(p) ? 'powers' : p; }, // which tab a panel sits under
  // a tab: POWERS reopens the page you last had there (from another tab), or goes back to its hub (from one of its pages)
  goTab(t) { if (t !== 'powers') { this.setPanel(t); return; } this.setPanel(this.POWER_PAGES.includes(this.panel) ? 'powers' : this.lastPower || 'powers'); },
  setPanel(k) { if (this.panel === k) return; if (this.POWER_PAGES.includes(k)) this.lastPower = k; else if (k === 'powers') this.lastPower = null;
    if (this.row !== 'tabs' && (k === 'perks' || k === 'megas' || k === 'hats' || k === 'lab' || k === 'powers')) this.row = k === 'hats' ? 'gears' : k === 'lab' ? 'cards' : k === 'powers' ? 'hub' : k; else if (['perks', 'megas', 'gears', 'cards', 'hub'].includes(this.row)) this.row = 'body'; /* (each page's cursor lands on its own cards) */ this.panel = k; this.mark = null; this.ghost = false; SFX.play('tab'); },
  // where a result card's fusion will land (mirrors Player.craft): the body ingredient's slot, or the bag
  destOf(f, to) {
    if (f.near) return { where: 'none' };
    let A = f.A, B = f.B; if (A.where !== 'body' && B.where === 'body') { const t = A; A = B; B = t; }
    const ai = A.where === B.where && B.i < A.i ? A.i - 1 : A.i;
    if (Player.isGear(f.r)) return { where: 'gear' };
    if (to === 'bag') return { where: 'bag', id: f.r };
    if (to === 'body' && A.where === 'bag') return this.bodyRoom(f) ? { where: 'body', i: Player.firstMinion() } : { where: 'bag', id: f.r };
    return A.where === 'body' ? { where: 'body', i: ai } : { where: 'bag', id: f.r };
  },
  // can this card's result go on the body? Yes when an ingredient comes off the body (it takes that slot), or there is a free slot
  bodyRoom(f) { return !f.near && (f.A.where === 'body' || f.B.where === 'body' || Player.weaponCount() < Player.maxSegs); },
  // the LAB's cards: every fusion you can make now (Player.fusions: affordable first), then the recipes you hold one half of,
  // dimmed with the half to find ({ near: true, have, need }), so the page reads as a recipe book
  labCards() {
    const F = Player.fusions(), key = Player._fuseKey; if (this._labKey === key && this._lab) return this._lab;
    const P = Player, held = P.holdings(), made = new Set(F.map(f => f.r)), seen = new Set(), near = [];
    for (const [a, b, r] of RECIPES) {
      if (made.has(r) || seen.has(r) || !BULLETS[r] || (P.isGear(r) && P.ownsGear(r))) continue;
      const ha = (held[a] || 0) > 0, hb = (held[b] || 0) > 0; if (ha === hb && !(a === b)) continue; if (a === b && (held[a] || 0) !== 1) continue;
      seen.add(r); near.push({ a, b, r, near: true, have: ha ? a : b, need: ha ? b : a, cost: P.craftCost(r) });
    }
    near.sort((p, q) => BULLETS[q.r].tier - BULLETS[p.r].tier);
    this._labKey = key; this._lab = F.concat(near); return this._lab;
  },
  // a crafted piece loses its lightning frame once you touch it
  touch(L) { const P = Player; if (!L) return; if (L.where === 'body' && P.segments[L.i]) P.segments[L.i].fresh = false; else if (L.where === 'bag') { const id = P.idAt(L); if (id) P.freshBag.delete(id); } },
  // fuse A and B for real, then hand the animation layer everything it needs: the source tiles, where they collide
  // (`origin`: the card, the drop point), where the result landed, and the dps before and after
  fuse(A, B, origin, srcs, to) { // srcs: where the ingredients fly from when that is not their own tile (the LAB's reactor)
    const P = Player; BenchFx.finish();
    const a = P.idAt(A), b = P.idAt(B); if (!a || !b) return null;
    const srcA = srcs ? srcs.a : BenchFx.rectOf(A), srcB = srcs ? srcs.b : BenchFx.rectOf(B), before = P.estimateDps(0, 1);
    const r = P.craft(A, B, false, to); if (!r) return null;
    { const L = srcA && srcB ? { x: (srcA.cx + srcB.cx) / 2, y: (srcA.cy + srcB.cy) / 2 } : origin || (srcA ? { x: srcA.cx, y: srcA.cy } : { x: CFG.W / 2, y: CFG.H / 2 }); BenchFx.light(L.x, L.y, BULLETS[r].color); } // the flash of the fusion
    G.msg(`Fused ${BULLETS[a].name} + ${BULLETS[b].name} into ${BULLETS[r].name}: ${BULLETS[r].desc}`, BULLETS[r].color); this.mark = null;
    const d = P.lastCraft; if (d.where === 'gear') { const h = this.hit.head, t0 = this.hit.body[0], at = h ? { x: h.x + h.w / 2, y: h.y + h.w / 2 } : t0 ? { x: t0.x - (t0.tile || t0.w) * 0.55, y: t0.y + (t0.tile || t0.h) / 2 } : { x: CFG.W / 2, y: CFG.H / 2 }; BenchFx.burst({ r, at }); /* the fusion's burst, on the head */ G.msg(d.worn ? `${BULLETS[r].name} is on your head. Head-first crashes just bounce you off now.` : `${BULLETS[r].name} hangs on your hat rack. Tap it on the HAT RACK card to wear it.`, BULLETS[r].color); return r; } // head gear: no slot to fly to (BenchFx.burst plays the fuse sound)
    const dest = d.where === 'body' ? { where: 'body', i: d.i } : { where: 'bag', id: r };
    const at = origin || (srcA && srcB ? { x: (srcA.cx + srcB.cx) / 2, y: (srcA.cy + srcB.cy) / 2 - 40 } : srcA ? { x: srcA.cx, y: srcA.cy } : srcB ? { x: srcB.cx, y: srcB.cy } : { x: CFG.W / 2, y: CFG.H / 2 });
    BenchFx.fuse({ a, b, r, srcA, srcB, at, dest, dpsBefore: before, dpsAfter: P.estimateDps(0, 1), beams: !!srcs });
    // the cursor stays where you were working (the bag, the cards): the animation and the slot pulse show where the result went
    if (this.row === 'bag' && this.bagSel >= P.bagGroups().length) this.bagSel = Math.max(0, P.bagGroups().length - 1);
    return r;
  },
  // a plain move (placing, swapping, stashing): the tile flies from where it was to where it went. `src` is captured before the model changes
  anim(id, src, dest) { if (src) BenchFx.move({ id, src, dest }); },
  close() { const P = Player, was = P.segments.map(s => s.id).join(','); for (const id of [...P.segments.map(s => s.id), ...P.bag]) Settings.benchSeen.add(id); Settings.save(); /* everything here has been seen: no more NEW */ P.rebuild(); if (P.autoRebuild && P.segments.map(s => s.id).join(',') !== was) G.msg('Your saved loadout is back in place. To keep a different body, press SAVE CHANGES on the workbench.', '#ffe9a8'); /* the lock only changes on the padlock: leaving puts the locked build back */ if (typeof Save !== 'undefined') Save.write();
    if (G.mode === 'world' && P.alive) { P.ready = false; P.reorient = true; P.reorientText = 'Workbench closed'; } }, // the world holds still until you press a direction, as it does leaving a dungeon: look, then go
  cursor(nth) { if (this.row === 'cards' || this.row === 'tabs' || this.row === 'perks' || this.row === 'megas' || this.row === 'gears') return { where: 'none', i: -1 }; if (this.row === 'pills') return { where: 'none', i: -1 }; if (this.row === 'body') return { where: 'body', i: this.sel }; const g = Player.bagGroups()[this.bagSel]; return { where: 'bag', i: g ? g.idx[Math.min(nth || 0, g.idx.length - 1)] : 0 }; },
  // the cursor walks the screen: A/D along a row, W/S to the nearest tile in the row above or below, across the body rows,
  // the bag rows and the crafting cards alike (the drawer's hit rects say where everything is). Past the last card row it pages.
  // Above the top row sit the BODY / FORGE tabs: A/D there switches the panel at once
  // a chip on the MEGA DECK card: switch the active mega. Switching empties the bar, so with charge in it the first press
  // only asks (megaArm) and a second press on the same chip switches
  pickMega(id) {
    if (!Mega.has(id)) { SFX.play('lose'); G.msg('Not in your deck yet: roll it in the perk shop, or beat a unique monster that drops it.', '#aaa'); this.megaArm = null; return; }
    if (Mega.current() === id) { this.megaArm = null; SFX.play('dot'); return; }
    if (G.mega.charge > 0 && this.megaArm !== id) { this.megaArm = id; SFX.play('tab'); return; }
    const r = (this.hit.megas || []).find(t => t.id === id); this.megaArm = null; if (Mega.activate(id) && r) this.fly = { kind: 'megas', id, from: r, t0: performance.now() }; // it floats into the panel
  },
  // a hat on the HAT RACK card: wear it (free; the one you took off stays on the rack)
  wearGear(id) {
    const P = Player;
    if (!P.ownsGear(id)) { SFX.play('lose'); G.msg(`${BULLETS[id].name} is not on your rack yet: find it on monsters, or fuse it.`, '#aaa'); return; }
    if (P.gear === id) { SFX.play('dot'); return; }
    const r = (this.hit.gears || []).find(t => t.id === id); P.equipGear(id); if (r) this.fly = { kind: 'hats', id, from: r, t0: performance.now() }; // it floats into the panel
  },
  padlock() { const P = Player; if (!P.autoRebuild) P.lock(); else if (!P.lockMatches()) { P.lock(true); SFX.play('loadout'); G.msg('Loadout saved.', '#ffd166'); } else P.unlock(); }, // the padlock: lock, update a lock the body has moved away from, or unlock
  nav(dx, dy) {
    const H = this.hit, nodes = [];
    if (this.panel !== 'lab') for (const t of H.body) if (!t.end) nodes.push({ zone: 'body', i: t.i, cx: t.x + t.w / 2, cy: t.y + (t.tile || t.h) / 2, h: t.tile || t.h });
    for (const t of H.bag) nodes.push({ zone: 'bag', i: t.i, cx: t.x + t.w / 2, cy: t.y + (t.tile || t.h) / 2, h: t.tile || t.h });
    for (const t of H.fusions) nodes.push({ zone: 'cards', i: t.i, cx: t.x + t.w / 2, cy: t.y + t.h / 2, h: t.h });
    (H.pills || []).forEach((t, i) => nodes.push({ zone: 'pills', i, cx: t.x + t.w / 2, cy: t.y + t.h / 2, h: t.h })); // BODY: FILL and SAVE LOADOUT
    for (const t of (H.perks || [])) nodes.push({ zone: 'perks', i: t.i, cx: t.x + t.w / 2, cy: t.y + t.h / 2, h: t.h });
    for (const t of (H.megas || [])) nodes.push({ zone: 'megas', i: t.i, cx: t.x + t.w / 2, cy: t.y + t.h / 2, h: t.h }); // LOADOUT: the mega deck's chips
    for (const t of (H.gears || [])) nodes.push({ zone: 'gears', i: t.i, cx: t.x + t.w / 2, cy: t.y + t.h / 2, h: t.h }); // ...and the hat rack's
    for (const t of (H.hub || [])) nodes.push({ zone: 'hub', i: t.i, cx: t.x + t.w / 2, cy: t.y + t.h / 2, h: t.h }); // POWERS: its three buttons
    for (const t of H.tabs) nodes.push({ zone: 'tabs', i: this.PANELS.indexOf(t.panel), panel: t.panel, cx: t.x + t.w / 2, cy: t.y + t.h / 2, h: t.h });
    if (!nodes.length) return;
    const idx = this.row === 'body' ? this.sel : this.row === 'bag' ? this.bagSel : this.row === 'tabs' ? this.PANELS.indexOf(this.tabOf(this.panel)) : this.row === 'hub' ? this.hubSel : this.row === 'perks' ? this.perkSel : this.row === 'pills' ? this.pillSel : this.row === 'megas' ? this.megaSel : this.row === 'gears' ? this.gearSel : this.cardSel;
    let cur = nodes.find(n => n.zone === this.row && n.i === idx) || nodes.find(n => n.zone === this.row) || nodes[0];
    let best = null, bd = 1e12;
    if (dx) { for (const n of nodes) { if (n === cur || Math.abs(n.cy - cur.cy) > Math.max(cur.h, n.h) * 0.5) continue; if (dx > 0 ? n.cx <= cur.cx + 1 : n.cx >= cur.cx - 1) continue; const d = Math.abs(n.cx - cur.cx); if (d < bd) { bd = d; best = n; } } }
    else { // the nearest row in that direction, then the nearest tile in it
      let rowY = null; for (const n of nodes) { if (dy > 0 ? n.cy <= cur.cy + cur.h * 0.4 : n.cy >= cur.cy - cur.h * 0.4) continue; const d = Math.abs(n.cy - cur.cy); if (d < bd) { bd = d; rowY = n.cy; } }
      if (rowY != null) { bd = 1e12; for (const n of nodes) { if (Math.abs(n.cy - rowY) > n.h * 0.5) continue; const d = Math.abs(n.cx - cur.cx); if (d < bd) { bd = d; best = n; } } }
      else if (cur.zone === 'cards' && H.cardGrid) { // off the end of the visible cards: page the grid
        const g = H.cardGrid, F = this.labCards().length;
        if (dy > 0 && this.cardFirst + g.cols * g.rows < F) { this.cardFirst += g.cols; this.cardSel = Math.min(F - 1, this.cardSel + g.cols); SFX.play('dot'); }
        else if (dy < 0 && this.cardFirst > 0) { this.cardFirst = Math.max(0, this.cardFirst - g.cols); this.cardSel = Math.max(0, this.cardSel - g.cols); SFX.play('dot'); }
        return;
      }
    }
    if (!best) return;
    if (best.zone === 'tabs' && cur.zone !== 'tabs') best = nodes.find(n => n.zone === 'tabs' && n.panel === this.tabOf(this.panel)) || best; // up onto the tabs: land on the open one
    this.row = best.zone; if (best.zone === 'body') this.sel = best.i; else if (best.zone === 'bag') this.bagSel = best.i; else if (best.zone === 'tabs') { if (this.tabOf(this.panel) !== best.panel) { this.goTab(best.panel); return; } } else if (best.zone === 'hub') this.hubSel = best.i; else if (best.zone === 'perks') this.perkSel = best.i; else if (best.zone === 'megas') { this.megaSel = best.i; this.megaArm = null; } else if (best.zone === 'gears') this.gearSel = best.i; else if (best.zone === 'pills') this.pillSel = best.i; else this.cardSel = best.i;
    SFX.play('dot');
  },
  // craft card k (absolute index into Player.fusions()), from a tap or ENTER
  pageCards(d) { const g = this.hit.cardGrid, F = this.labCards().length; if (!g) return; const per = g.cols * g.rows, nf = U.clamp(this.cardFirst + d * per, 0, Math.max(0, F - 1)); if (nf === this.cardFirst) return; this.cardFirst = nf - (nf % g.cols); this.row = 'cards'; this.cardSel = this.cardFirst; SFX.play('dot'); }, // a whole page of crafting cards at a time
  craftCard(k, to) { // to: 'bag' or 'body' (the reactor's two buttons), else the default
    const f = this.labCards()[k]; if (!f) return;
    if (f.near) { SFX.play('lose'); G.msg(`${BULLETS[f.r].name} needs ${BULLETS[f.need].name} too: find one, then come back to the LAB.`, '#ffe9a8'); return; }
    const R = this.hit.reactor, card = this.hit.fusions.find(q => q.i === k);
    if (R && R.k === k) this.fuse(f.A, f.B, { x: R.core.cx, y: R.core.cy }, { a: R.a, b: R.b }, to); // the reactor: the two ingredients fly in along the beams and fuse in the core
    else this.fuse(f.A, f.B, card ? { x: card.cx, y: card.cy } : null, null, to);
  },
  inside(p, r) { return !!r && p.x >= r.x && p.x <= r.x + r.w && p.y >= r.y && p.y <= r.y + r.h; },
  // which tile is under a point: { where, i (list index for body / first index of the stack for bag), gi (bag group index) }
  tileAt(p) {
    for (const t of this.hit.body) if (!t.end && this.inside(p, t)) return { where: 'body', i: t.i }; // (the empty placeholders are drop targets, not tiles: dragging one carried nothing and broke the drawer)
    for (const t of this.hit.bag) if (this.inside(p, t)) { const g = Player.bagGroups()[t.i]; return { where: 'bag', i: g.idx[0], gi: t.i, count: g.idx.length }; }
    return null;
  },
  // ---- pointer / touch: press, move, release. A short press is a tap, a longer move is a drag. ----
  pointerDown(p) {
    if (Input.pinch) return; // two fingers: the magnifier, not a tap
    if (this.ask) { this.tap(p); return; } // the question is modal
    if (this.need) { this.need = null; return; } // the dots callout: a tap closes it
    BenchFx.finish();
    const t = this.panel === 'lab' ? null : this.tileAt(p);
    if (t) { this.touch(t); this.drag = { from: t, id: Player.idAt(t), x: p.x, y: p.y, sx: p.x, sy: p.y, moved: false }; return; }
    this.press = { sx: p.x, sy: p.y };
  },
  pointerMove(p) {
    if (!this.drag) return;
    this.drag.x = p.x; this.drag.y = p.y;
    if (!this.drag.moved && Math.hypot(p.x - this.drag.sx, p.y - this.drag.sy) > 14) { this.drag.moved = true; this.mark = null; }
  },
  pointerUp(p) {
    const pr = this.press; this.press = null;
    if (pr) { const F = this.hit.fusions, inCards = F.length && pr.sx >= Math.min(...F.map(q => q.x)) && pr.sx <= Math.max(...F.map(q => q.x + q.w)) && pr.sy >= Math.min(...F.map(q => q.y)) - 30 && pr.sy <= Math.max(...F.map(q => q.y + q.h)) + 10;
      if (inCards && Math.abs(p.y - pr.sy) > 50 && Math.abs(p.y - pr.sy) > Math.abs(p.x - pr.sx)) { this.pageCards(p.y < pr.sy ? 1 : -1); return; } // a swipe up or down on the crafting cards turns their page
      if (Math.abs(p.x - pr.sx) > 110 && Math.abs(p.y - pr.sy) < 90) { const L = this.PANELS, i = L.indexOf(this.tabOf(this.panel)); this.goTab(L[(i + (p.x > pr.sx ? 1 : L.length - 1)) % L.length]); /* finger moves right: the tab to the right */ } else this.tap({ x: pr.sx, y: pr.sy }); return; } // a sideways swipe flips the panel
    const d = this.drag; this.drag = null;
    if (!d) return;
    if (!d.moved) { this.tap(p); return; }
    this.drop(d, p);
  },
  drop(d, p) {
    const P = Player, from = d.from;
    if (this.inside(p, this.hit.trash)) { const id = P.destroy(from); if (id) { SFX.play('pellet'); G.msg(`Trashed ${BULLETS[id].name}: +${CFG.TRASH_DOTS} dots (${G.dots})`, '#ffe9a8'); } return; }
    if (from.where === 'body' && this.hit.stash && this.inside(p, this.hit.stash)) { const src = BenchFx.rectOf(from); if (P.toBag(from.i)) { SFX.play('shed'); this.anim(d.id, src, { where: 'bag', id: d.id }); } return; } // the STASH square
    const t = this.tileAt(p);
    const sameSpot = t && t.where === from.where && t.i === from.i;
    if (t && (!sameSpot || (t.where === 'bag' && t.count >= 2))) {
      // same bag stack dropped on itself: fuse two of the stack
      let target = t;
      if (t.where === 'bag' && from.where === 'bag' && P.bag[t.i] === d.id) { if (t.count < 2) return; target = { where: 'bag', i: Player.bagGroups()[t.gi].idx.find(i => i !== from.i) }; }
      const r = findRecipe(d.id, P.idAt(target)), movable = from.where !== 'bag' || target.where !== 'bag'; // (two bag stacks have nowhere to move to: they just fuse)
      if (r && movable) { // a fusion partner: FUSE or MOVE? Asked when the dots are there; without them it simply moves (the fuse would only be refused)
        const cost = P.craftCost(r);
        if (G.dots >= cost) { this.ask = { from, target, id: d.id, r, cost, p: { x: p.x, y: p.y } }; SFX.play('dot'); return; }
        SFX.play('lose'); this.need = { t: 3, r, cost, have: G.dots }; return; // short of dots: nothing moves (it was a fuse you meant); the callout says how many more
      } else if (r) { this.fuse(target, from, { x: p.x, y: p.y }); return; } // fuse: the result lands where you dropped it
      this.move(from, target, d.id); return;
    }
    if (from.where === 'body' && this.inside(p, this.hit.bodyRow) && P.segments.length > 1) { if (from.i !== P.segments.length - 1) this.move(from, { where: 'body', i: P.segments.length - 1 }, d.id); return; } // a segment dropped on the empty slots: to the end of the body
    if (from.where === 'bag' && (this.inside(p, this.hit.bodyEnd) || this.inside(p, this.hit.bodyRow))) { const src = BenchFx.rectOf(from), at = P.firstMinion(); if (P.fromBag(from.i, -1)) { SFX.play('pickup'); this.row = 'body'; this.sel = at; this.anim(d.id, src, { where: 'body', i: at }); } else G.msg('Body is full. Drop it onto a segment to swap.', '#ff9b8b'); return; }
    if (from.where === 'body' && this.inside(p, this.hit.bagRow)) { const src = BenchFx.rectOf(from); if (P.toBag(from.i)) { SFX.play('shed'); this.anim(d.id, src, { where: 'bag', id: d.id }); } return; }
  },
  // a plain move between two tiles: reorder within the body, swap a bag bullet into a body slot, or stash a segment on a bag stack
  move(from, target, id, swap) {
    const P = Player;
    if (swap && from.where === 'body' && target.where === 'body') { const src = BenchFx.rectOf(from), S = P.segments; [S[from.i], S[target.i]] = [S[target.i], S[from.i]]; P.resolveBuild(); this.row = 'body'; this.sel = target.i; SFX.play('dot'); this.anim(id, src, { where: 'body', i: target.i }); return; } // swap: the two trade places
    if (from.where === 'body' && target.where === 'body') { const src = BenchFx.rectOf(from), seg = P.segments.splice(from.i, 1)[0]; P.segments.splice(target.i, 0, seg); P.resolveBuild(); this.row = 'body'; this.sel = target.i; SFX.play('dot'); this.anim(id, src, { where: 'body', i: target.i }); return; } // reorder
    if (from.where === 'bag' && target.where === 'body') { const src = BenchFx.rectOf(from); if (P.fromBag(from.i, target.i)) { SFX.play('pickup'); this.row = 'body'; this.sel = target.i; this.anim(id, src, { where: 'body', i: target.i }); } return; } // swap in
    if (from.where === 'body' && target.where === 'bag') { const src = BenchFx.rectOf(from); if (P.toBag(from.i)) { SFX.play('shed'); this.anim(id, src, { where: 'bag', id }); } }
  },
  // the FUSE or MOVE question: ENTER / SPACE / Esc on a keyboard (M is mute and F is fullscreen, game-wide), the card's buttons on a tap
  answer(code) {
    const a = this.ask; if (!a) return; this.ask = null;
    if (a.kind === 'lock') { // the locked build met a change: follow it, drop the lock, or keep the lock (the change is undone on leaving)
      const P = Player;
      if (code === 'Enter') { this.followLock = true; P.lock(true); SFX.play('loadout'); G.msg('Loadout saved: your changes stay.', '#9bf06a'); }
      else if (code === 'KeyU') P.unlock();
      else { SFX.play('dot'); G.msg('Loadout kept: leaving the workbench puts it back. (SAVE CHANGES keeps a change.)', '#ffe9a8'); }
      return;
    }
    if (code === 'Enter') this.fuse(a.target, a.from, a.p);
    else if (code === 'Space') this.move(a.from, a.target, a.id, a.swap);
    else SFX.play('shed');
  },
  // tap: select a tile; tap the selected tile again and it does the obvious thing (a bag bullet joins the body, or swaps
  // with the selected body slot when the body is full; a body segment goes to the bag). With an ingredient marked (C on a
  // keyboard), a tap picks the partner. Tap a result card to craft.
  tap(p) {
    if (this.help) { this.help = false; return; }
    if (this.ask) { for (const b of (this.hit.ask || [])) if (this.inside(p, b)) { this.answer(b.code); return; } this.answer('Escape'); return; } // the question is modal: a tap outside it cancels
    if (this.hit.back && this.inside(p, this.hit.back)) { this.setPanel('powers'); SFX.play('tab'); return; } // a POWERS page: back to the hub
    for (const t of this.hit.tabs) if (this.inside(p, t)) { this.goTab(t.panel); return; }
    for (const t of (this.hit.perks || [])) if (this.inside(p, t)) { this.row = 'perks'; this.perkSel = t.i; SFX.play('dot'); return; }
    for (const t of (this.hit.goto || [])) if (this.inside(p, t)) { if (t.i != null) this.hubSel = t.i; this.setPanel(t.panel); return; } // LOADOUT's MEGA / HAT cards and the POWERS hub's buttons open their pages
    for (const t of (this.hit.megas || [])) if (this.inside(p, t)) { this.row = 'megas'; this.megaSel = t.i; this.pickMega(t.id); return; }
    for (const t of (this.hit.gears || [])) if (this.inside(p, t)) { this.row = 'gears'; this.gearSel = t.i; this.wearGear(t.id); return; }
    if (this.hit.head && this.inside(p, this.hit.head)) { this.toggleGhost(); return; } // the head: see the locked build, in order
    for (const b of this.hit.buttons) if (this.inside(p, b)) { Input.tapKey(b.code); return; }
    if (this.hit.trash && this.inside(p, this.hit.trash)) { Input.tapKey('Delete'); return; } // the bin, tapped: destroy the selected piece
    if (this.hit.cardPage) for (const k of ['up', 'down']) { const r = this.hit.cardPage[k]; if (r && this.inside(p, r)) { this.pageCards(k === 'down' ? 1 : -1); return; } } // the page buttons over the cards
    for (const f of this.hit.fusions) if (this.inside(p, f)) { const now = performance.now(), dbl = this.lastCard && this.lastCard.i === f.i && now - this.lastCard.t < 400 && this.row === 'cards' && this.cardSel === f.i; this.lastCard = { i: f.i, t: now }; if (dbl) { this.lastCard = null; this.craftCard(f.i); } else if (!(this.row === 'cards' && this.cardSel === f.i)) { this.row = 'cards'; this.cardSel = f.i; SFX.play('dot'); } return; } // a tap selects a card (the info card shows it, with CRAFT); a quick double tap crafts it
    const t = this.panel === 'lab' ? null : this.tileAt(p);
    if (!t) return;
    if (this.mark) { if (t.where === 'body') { this.row = 'body'; this.sel = t.i; } else { this.row = 'bag'; this.bagSel = t.gi; } Input.tapKey('KeyC'); return; }
    const prev = this.picked, again = prev && prev.where === t.where && prev.i === t.i; // tapped this same piece last: a second tap acts (the cursor's resting place does not count)
    { // a bag piece is selected and you tap a body piece it fuses with (or the other way round): FUSE or SWAP?
      const pid = prev && Player.idAt(prev), tid = Player.idAt(t); this.picked = null; // (only a piece you tapped counts, not the cursor's resting place)
      if (pid && prev.where !== t.where) { const r = findRecipe(pid, tid);
        if (r) { const bag = prev.where === 'bag' ? prev : t, body = prev.where === 'body' ? prev : { where: 'body', i: t.i }, bagL = { where: 'bag', i: bag.i }, cost = Player.craftCost(r), tt = this.hit.body.find(q => q.i === body.i);
          if (G.dots >= cost) { this.ask = { from: bagL, target: body, id: Player.idAt(bagL), r, cost, p: tt ? { x: tt.x + tt.w / 2, y: tt.y + (tt.tile || tt.h) / 2 } : null }; SFX.play('dot'); } else { SFX.play('lose'); this.need = { t: 3, r, cost, have: G.dots }; }
          return; } }
      this.picked = { where: t.where, i: t.i }; // remembered for the next tap
    }
    if (t.where === 'body') { const was = again && this.row === 'body' && this.sel === t.i; this.row = 'body'; this.sel = t.i; if (was) { const id = Player.segments[t.i].id, name = BULLETS[id].name, src = BenchFx.rectOf(t); if (Player.toBag(t.i)) { SFX.play('shed'); G.msg(`${name} stashed in the bag.`, '#ccc'); this.anim(id, src, { where: 'bag', id }); } } }
    else { const was = again && this.row === 'bag' && this.bagSel === t.gi; this.row = 'bag'; this.bagSel = t.gi; if (was) this.place(t.i); }
  },
  toggleGhost() { if (!Player.blueprint.length) { G.msg('No loadout saved yet. Save one with the padlock (B).', '#aaa'); return; } this.ghost = !this.ghost; SFX.play('dot'); },
  // put bag bullet i on the body: into a free slot, or swapped with the selected body segment when the body is full
  place(i) {
    const P = Player, id = P.bag[i]; P.freshBag.delete(id); const name = BULLETS[id].name, room = P.weaponCount() < P.maxSegs, src = BenchFx.rectOf({ where: 'bag', i });
    if (room) { const at = P.firstMinion(); if (P.fromBag(i, -1)) { SFX.play('pickup'); this.row = 'body'; this.sel = at; G.msg(`${name} joins the body.`, '#9bf06a'); this.anim(id, src, { where: 'body', i: at }); } return; }
    const slot = Math.min(this.sel, P.segments.length - 1); if (slot < 0 || P.isMinion(P.segments[slot])) { G.msg('Body is full: tap a body segment first, then tap the bag bullet twice to swap it in.', '#ff9b8b'); return; }
    const old = BULLETS[P.segments[slot].id].name; if (P.fromBag(i, slot)) { SFX.play('pickup'); this.row = 'body'; this.sel = slot; G.msg(`${name} swapped in for ${old} (now in the bag).`, '#9bf06a'); this.anim(id, src, { where: 'body', i: slot }); }
  },
  // AUTO: fill the empty body slots from the bag with what fits the build you are going for. It never replaces a segment
  // you placed, and it prefers bullets that share the body's main element (a freeze build gets ice first), then higher tiers.
  auto() {
    const P = Player; if (P.autoRebuild) { G.msg('Your loadout fills its own slots. Clear it (B) to fill from the bag.', '#ffe9a8'); return; } if (!P.bag.length || P.weaponCount() >= P.maxSegs) { G.msg(!P.bag.length ? 'AUTO: the bag is empty.' : 'AUTO: the body is full. Stash something first (tap a segment twice).', '#ff9b8b'); return; }
    const count = {}; for (const s of P.segments) for (const e of (BULLETS[s.id].elems || [])) count[e] = (count[e] || 0) + 1;
    const main = Object.keys(count).sort((a, b) => count[b] - count[a])[0] || null;
    const score = id => { const B = BULLETS[id]; return B.tier + ((B.elems || []).includes(main) ? 2.5 : 0) + (B.craft ? 0.5 : 0) + (main && (B.elems || []).length && !(B.elems || []).includes(main) ? -0.5 : 0); };
    const added = [];
    while (P.weaponCount() < P.maxSegs && P.bag.length) { let b = 0; for (let i = 1; i < P.bag.length; i++) if (score(P.bag[i]) > score(P.bag[b])) b = i; const id = P.bag[b]; if (!P.fromBag(b, -1)) break; added.push(BULLETS[id].name); }
    if (added.length) { SFX.play('loadout'); this.flash = { t: 1.2, id: P.segments[P.segments.length - 1].id }; G.msg(`AUTO added ${added.join(', ')}${main ? ` (favouring ${ELEMENTS[main].name.toLowerCase()}, your main element)` : ''}.`, '#9bf06a'); }
  },
  update(dt) {
    dt = dt || 1 / 60;
    const P = Player, nb = P.segments.length, nbag = P.bagGroups().length; // (nb counts riding minions too: they are tiles you can select and trash, not swap)
    this.inspT += dt;
    if (BenchFx.busy() && Object.keys(Input.pressed).some(k => Input.pressed[k])) BenchFx.finish(); // any key lands whatever is in flight
    if (Input.once('Tab')) { const L = this.PANELS; this.goTab(L[(L.indexOf(this.tabOf(this.panel)) + 1) % L.length]); } // LOADOUT -> BAG -> LAB -> POWERS
    if (Input.once('Slash')) { this.help = !this.help; SFX.play('dot'); }
    if (this.help) { if (Input.once('Escape') || Input.once('Enter')) this.help = false; return; }
    if (this.ask) { for (const k of this.ask.kind === 'lock' ? ['Enter', 'KeyU', 'Escape'] : ['Enter', 'Space', 'Escape']) if (Input.once(k)) this.answer(k); Input.pressed = {}; return; } // FUSE or MOVE? (or: what about the lock?) nothing else until it is answered
    if (this.need && Object.keys(Input.pressed).some(k => Input.pressed[k])) this.need = null; // the dots callout: any key closes it (the key still does its thing)
    { const dx = (Input.once('KeyD') || Input.once('ArrowRight') ? 1 : 0) - (Input.once('KeyA') || Input.once('ArrowLeft') ? 1 : 0), dy = (Input.once('KeyS') || Input.once('ArrowDown') ? 1 : 0) - (Input.once('KeyW') || Input.once('ArrowUp') ? 1 : 0); if (dx) this.nav(dx, 0); else if (dy) this.nav(0, dy); }
    if (Input.once('Escape')) { if (this.held != null) { this.held = null; SFX.play('shed'); } else if (this.mark) this.mark = null; else if (this.POWER_PAGES.includes(this.panel)) { this.setPanel('powers'); return; } else { this.close(); G.state = 'playing'; return; } } // (a POWERS page: back to its hub) // ESC: drop the mark, or leave the workbench
    if (this.panel === 'megas' || this.panel === 'hats') { // the MEGA DECK and HAT RACK pages: pick a card (ENTER) and it becomes the active one
      const z = this.panel === 'megas' ? 'megas' : 'gears'; if (this.row !== 'tabs' && this.row !== z) this.row = z;
      if (Input.once('KeyO')) Settings.open('paused'); else if (this.row === 'tabs' && Input.once('Enter')) this.nav(0, 1);
      else if (this.row === z && Input.once('Enter')) { const r = (this.hit[z] || [])[z === 'megas' ? this.megaSel : this.gearSel]; if (r) { if (z === 'megas') this.pickMega(r.id); else this.wearGear(r.id); } }
      Input.pressed = {}; return;
    }
    if (this.panel === 'powers') { // the POWERS hub: three big buttons (HATS, MEGA, PERKS); ENTER opens the one under the cursor
      if (this.row !== 'tabs') this.row = 'hub';
      if (Input.once('KeyO')) Settings.open('paused'); else if (this.row === 'tabs' && Input.once('Enter')) this.nav(0, 1); else if (this.row === 'hub' && Input.once('Enter')) { const t = (this.hit.hub || [])[this.hubSel]; if (t) this.setPanel(t.panel); }
      Input.pressed = {}; return;
    }
    if (this.panel === 'lab') { // the LAB: walk the cards, ENTER (or the reactor's button) crafts the selected one
      const n = this.labCards().length; if (this.row !== 'tabs') this.row = 'cards'; this.cardSel = Math.min(this.cardSel, Math.max(0, n - 1));
      if (Input.once('KeyO')) Settings.open('paused'); else if (this.row === 'tabs' && Input.once('Enter')) this.nav(0, 1); else if (this.row === 'cards') { const f = this.labCards()[this.cardSel]; if (Input.once('Enter')) this.craftCard(this.cardSel, f && this.bodyRoom(f) ? 'body' : 'bag'); else if (Input.once('Space')) this.craftCard(this.cardSel, 'bag'); } // ENTER: craft it onto the body (the bag when it cannot go there); SPACE: into the bag
      Input.pressed = {}; return;
    }
    if (this.panel === 'perks') { if (this.row !== 'tabs' && this.row !== 'perks') this.row = 'perks'; if (Input.once('KeyO')) Settings.open('paused'); else if (this.row === 'tabs' && Input.once('Enter')) this.nav(0, 1); Input.pressed = {}; return; } // the perks page only shows: nothing to move or craft here
    if (Input.once('KeyC')) {
      let L = this.cursor();
      // marking a stack and pressing C on the same stack fuses two of its items (Bullet + Bullet)
      if (this.mark && this.row === 'bag' && this.mark.where === 'bag' && P.bag[this.mark.i] === P.idAt(L) && this.cursor(1).i !== this.mark.i) L = this.cursor(1);
      if (!P.idAt(L)) {}
      else if (!this.mark) { this.mark = L; SFX.play('dot'); }
      else if (this.mark.where === L.where && this.mark.i === L.i) this.mark = null;
      else if (P.canCraft(this.mark, L)) this.fuse(this.mark, L);
      else { SFX.play('lose'); G.msg(`${BULLETS[P.idAt(this.mark)].name} + ${BULLETS[P.idAt(L)].name}: no recipe. ${BULLETS[P.idAt(L)].name} is ingredient A now.`, '#ff9b8b'); this.mark = L; }
    }
    const F = P.fusions();
    if (Input.once('Delete') || Input.once('Backspace')) { const L = this.cursor(); if (this.panel === 'body' && L.where === 'body') this.stash(L.i); else { const id = P.destroy(L); if (id) { SFX.play('pellet'); G.msg(`Scrapped ${BULLETS[id].name}: +${CFG.TRASH_DOTS} dots (${G.dots})`, '#ffe9a8'); this.mark = null; } } } // DEL: on the BODY page it takes the segment off (to the bag); only the FORGE scraps
    if (Input.once('KeyZ')) this.auto(); // Z (or the AUTO button): fill the empty slots to suit the build
    if (Input.once('KeyO')) { Settings.open('paused'); return; }
    if (Input.once('KeyB')) this.padlock();
    if (Input.once('KeyV')) this.toggleGhost(); // view the locked build (same as tapping the head)
    if (this.row === 'pills') { const r = this.hit.pills[this.pillSel]; if (!r) this.row = 'body'; else if (Input.once('Enter')) { if (r.code === 'KeyZ') this.auto(); else this.padlock(); } } // ENTER on FILL / SAVE LOADOUT presses it
    else if (this.row === 'tabs') { if (Input.once('Enter')) this.nav(0, 1); } // ENTER on a tab: down into its panel
    else if (this.row === 'megas') { const r = (this.hit.megas || [])[this.megaSel]; if (!r) this.row = 'body'; else if (Input.once('Enter')) this.pickMega(r.id); }
    else if (this.row === 'gears') { const r = (this.hit.gears || [])[this.gearSel]; if (!r) this.row = 'body'; else if (Input.once('Enter')) this.wearGear(r.id); }
    else if (this.row === 'body') {
      if (this.held != null && !P.segments[this.held]) this.held = null;
      if (Input.once('Enter') && P.segments[this.sel]) { // ENTER picks a segment up; ENTER on another swaps them (or asks FUSE / SWAP for a recipe); ENTER on itself puts it back
        if (this.held == null) { this.held = this.sel; SFX.play('dot'); }
        else if (this.held === this.sel) { this.held = null; SFX.play('shed'); }
        else {
          const from = { where: 'body', i: this.held }, target = { where: 'body', i: this.sel }, id = P.segments[this.held].id, r = findRecipe(id, P.segments[this.sel].id), t = this.hit.body.find(q => q.i === this.sel); this.held = null;
          if (r && G.dots >= P.craftCost(r)) { this.ask = { from, target, id, r, cost: P.craftCost(r), p: t ? { x: t.x + t.w / 2, y: t.y + (t.tile || t.h) / 2 } : null, swap: true }; SFX.play('dot'); }
          else this.move(from, target, id, true);
        }
      }
      if (Input.once('KeyQ')) { if (P.moveSeg(this.sel, -1)) { this.sel--; SFX.play('dot'); this.mark = null; } }
      if (Input.once('KeyE')) { if (P.moveSeg(this.sel, 1)) { this.sel++; SFX.play('dot'); this.mark = null; } }
      if (Input.once('KeyX')) this.stash(this.sel);
    } else {
      if (Input.once('Enter')) {
        const slot = P.weaponCount() < P.maxSegs ? -1 : this.sel, L = this.cursor(), id = P.idAt(L), src = BenchFx.rectOf(L), at = slot < 0 ? P.firstMinion() : slot; // room: append; full: swap with the selected body slot
        if (P.fromBag(L.i, slot)) { SFX.play('pickup'); this.mark = null; this.anim(id, src, { where: 'body', i: at }); if (!P.bag.length) this.row = 'body'; }
      }
    }
    this.sel = Math.min(this.sel, Math.max(0, P.segments.length - 1));
    this.bagSel = Math.min(this.bagSel, Math.max(0, P.bagGroups().length - 1));
    this.cardSel = Math.min(this.cardSel, Math.max(0, F.length - 1)); if (this.cardFirst > this.cardSel) this.cardFirst = Math.max(0, this.cardSel - (this.cardSel % Math.max(1, (this.hit.cardGrid || { cols: 1 }).cols)));
    if (this.mark && !P.idAt(this.mark)) this.mark = null;
    if (this.row === 'cards') this.row = P.bag.length ? 'bag' : 'body'; // (the cards live on the LAB)
    if (this.row === 'bag' && !P.bag.length) this.row = 'body';
    // a locked build: the first change to the body on this visit asks what the lock should do (leaving would quietly undo it);
    // once you choose to follow, the lock keeps up with every change
    if (P.autoRebuild && !P.lockMatches() && P.segments.map(s => s.id).join(',') !== this.openBody) {
      if (this.followLock) P.lock(true);
      else if (!this.lockAsked && !this.ask) { this.lockAsked = true; this.ask = { kind: 'lock' }; SFX.play('dot'); }
    }
    if (this.flash) { this.flash.t -= dt; if (this.flash.t <= 0) this.flash = null; }
    if (this.need) { this.need.t -= dt; if (this.need.t <= 0) this.need = null; }
  },
};
