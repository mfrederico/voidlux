// Head gear: one piece worn on the head, always on in the overworld (never in a maze or on the deck), firing forward only.
// It is armour too: with any gear on, a head-first crash always bounces you off (no segment lost). It feeds its element to
// segment 1 (if that one has none), survives the last stand, and sets off your own mines that sit in its forward reach.
// The pieces are BULLETS entries of kind 'head' (so the recipes, the cards and the pickups show them like any weapon), with
// a `gear` block: cone (Flamethrower, Pumpkin Head, Santa Hat), ram (Bone Helmet), arc (Thundercap), spores (Mushroom Cap),
// hammer (Front Hammer).
// Extends Player (see player.js). Loads after it.
Object.assign(Player, {
  // the Front Hammer's borrowed element: segment 1's (a reverse pipe), bone from a spear; null when segment 1 has none
  hammerEl() { const s = this.segments[0], B = s && BULLETS[s.id]; if (!B || B.minion) return null; return (B.elems || [])[0] || (B.spear || B.trail === 'dust' ? 'bone' : null); },
  // one smash on e at strength k: damage (x shatter on a frozen one, which breaks the ice), stun, a shove, and the borrowed element
  hammerHit(e, K, k, m) {
    const el = this.hammerEl(), frozen = !!e.st.freeze, dmg = K.dmg * k * m * (frozen ? K.shatter : 1) * (el && ELEMENTS[el] ? Combat.resist(e, el) : 1) * (e.st.dry && el === 'fire' ? CFG.DRY.fire : 1);
    Combat.damage(e, dmg, frozen ? '#cfeeff' : el ? synInfo(el).color : '#e8ecf2');
    if (frozen) { delete e.st.freeze; Combat.burst(e.x, e.y, 18, '#e8f6ff', 3.5); Combat.floaters.push({ x: e.x, y: e.y - (e.r || 12) - 30, t: 0.8, text: 'SHATTER!', color: '#cfeeff' }); SFX.play('freeze'); }
    e.stun = Math.max(e.stun || 0, K.stun * Math.min(2, k)); e.windup = 0;
    if (e.kind !== 'boss') { const d = Math.hypot(e.x - this.x, e.y - this.y) || 1, push = K.push * Math.min(3, k); e.x += (e.x - this.x) / d * push; e.y += (e.y - this.y) / d * push; }
    const base = K.dmg * 0.3 * k;
    if (el === 'fire') Combat.addStatus(e, 'burn', 3, base * G.mods.elem, true);
    else if (el === 'ice' && !frozen) Combat.addStatus(e, 'freeze', 0.8, 0);
    else if (el === 'electric') { Combat.addStatus(e, 'spark', 2, base * G.mods.elem, false); Combat.arcs.push({ x1: this.x, y1: this.y, x2: e.x, y2: e.y, t: 0.2, seed: Math.random() }); }
    else if (el === 'poison') Combat.addStatus(e, 'poison', 3, base * G.mods.elem, false);
    else if (el === 'bone') Combat.dry(e, CFG.DRY.time);
    Combat.burst(e.x, e.y, 10, el ? synInfo(el).color : '#e8ecf2', 3); SFX.play('stomp'); G.shake = Math.max(G.shake, 0.15);
  },
  // Fast and Hard with gear on: its hard head goes into the gear instead (+20% head-gear damage a copy; the bonk chance is moot)
  gearMult() { return 1 + CFG.GEAR.hardHead * (G.mods.hardHead || 0); },
  isGear(id) { return !!(BULLETS[id] && BULLETS[id].kind === 'head'); },
  // the HAT RACK: every head gear you own (this.gears). You wear one (this.gear) and switch for free on the LOADOUT page
  ownsGear(id) { return (this.gears || []).includes(id); },
  // a head gear found or crafted: it goes on the rack, and on your head only if it is bare (the rack is the one way to swap hats).
  // One you already own is scrapped for dots instead, so the rack never fills with copies
  // `quiet`: the workbench says it itself (a craft)
  gainGear(id, wear, quiet) {
    const B = BULLETS[id];
    if (this.ownsGear(id)) { if (wear) { this.equipGear(id, true); return true; } if (quiet) return false; G.dots += CFG.TRASH_DOTS; SFX.play('pellet'); G.msg(`Another ${B.name}: one already hangs on your hat rack. Scrapped for +${CFG.TRASH_DOTS} dots.`, '#ffe9a8'); return false; }
    this.gears = (this.gears || []).concat(id);
    if (!quiet) { SFX.play('fuse4'); G.flash = 0.3; G.banner = { text: 'HAT FOUND', sub: `${B.name} ${wear || !this.gear ? 'goes on your head' : 'hangs on your hat rack'} (${this.gears.length} found)`, t: 2.2 }; } // found like a mega
    if (wear || !this.gear) this.equipGear(id, quiet);
    else if (!quiet) { SFX.play('pickup'); G.msg(`${B.name} hangs on your hat rack (${this.gears.length}). Wear it from the LOADOUT page.`, B.color); }
    return true;
  },
  // wear one from the rack (nothing drops: the one you took off goes back on the rack)
  equipGear(id, quiet) {
    if (!this.ownsGear(id)) this.gears = (this.gears || []).concat(id);
    this.gear = id; this.gearT = 0; this.gearCharge = 0;
    this.resolveBuild();
    if (!quiet) { const B = BULLETS[id]; SFX.play('pickup'); G.msg(`HEAD GEAR: ${B.name}. ${B.desc}. Head-first crashes just bounce you off now.`, B.color); }
  },
  // a monster (or a mine) in front of the head: within range, inside the cone, or touching
  gearAhead(o, range, arc) { const dx = o.x - this.x, dy = o.y - this.y, d = Math.hypot(dx, dy), r = o.r || 6; if (d > range + r) return false; return d < CFG.HEAD_R + r + 4 || Math.abs(U.angDiff(Math.atan2(dy, dx), this.angle)) < arc; },
  // the mega-hit, k = how charged (0..1): a wave of fire, a bone shockwave, a long lightning chain, a great spore cloud
  gearUnleash(B, k, foes, fx, fy, m) {
    const K = B.gear; G.shake = Math.max(G.shake, 0.15 + 0.35 * k); SFX.play('gearBlast');
    Combat.floaters.push({ x: this.x + fx * 30, y: this.y + fy * 30 - 30, t: 1, text: B.name.toUpperCase() + '!', color: B.color });
    if (K.type === 'cone') { // a wave of fire, or a blizzard that freezes the whole cone
      const R = K.range * (1 + 0.8 * k), arc = K.arc * 1.4, ice = K.el === 'ice';
      for (let i = 0; i < 50 + 60 * k; i++) { const a = this.angle + U.rand(-arc, arc), v = U.rand(250, 520) * (1 + 0.5 * k); Combat.particles.push(Combat.mk(this.x + fx * 14, this.y + fy * 14, Math.cos(a) * v, Math.sin(a) * v, R / 420, U.pick(ice ? ['#ffffff', '#cfeeff', '#9fdcff'] : ['#ff8a3d', '#ffd166', '#ff5c3d', '#fff2a8']), U.rand(5, 10))); }
      for (const e of foes) if (this.gearAhead(e, R, arc)) {
        if (ice) { Combat.damage(e, K.dps * (0.6 + 3.4 * k) * m * Combat.resist(e, 'ice'), '#cfeeff'); Combat.addStatus(e, 'freeze', 1 + 2 * k, 0); }
        else { Combat.damage(e, K.dps * (0.6 + 3.4 * k) * m * Combat.resist(e, 'fire') * (e.st.dry ? CFG.DRY.fire : 1), '#ff9a3d'); Combat.addStatus(e, 'burn', 4, K.burn * (1 + 2 * k) * G.mods.elem, true); }
      }
    } else if (K.type === 'ram') {
      const R = 140 * (1 + 0.5 * k); Combat.burst(this.x + fx * 40, this.y + fy * 40, 24 + 30 * k, '#eae4d4', 4); G.ring = { x: this.x + fx * 40, y: this.y + fy * 40, r: 0, max: R, t: 0.6, color: '#eae4d4' };
      for (const e of foes) if (this.gearAhead(e, R, 0.9)) { Combat.damage(e, K.dmg * (1 + 4 * k) * m, '#eae4d4'); Combat.dry(e, 5); if (e.kind !== 'boss') { const d = Math.hypot(e.x - this.x, e.y - this.y) || 1, push = 30 + 80 * k; e.x += (e.x - this.x) / d * push; e.y += (e.y - this.y) / d * push; } }
    } else if (K.type === 'hammer') { // the overhead slam: a shockwave ahead that smashes everything it reaches
      const R = 130 * (1 + 0.5 * k), cx = this.x + fx * 50, cy = this.y + fy * 50; G.ring = { x: cx, y: cy, r: 0, max: R, t: 0.6, color: this.hammerEl() ? synInfo(this.hammerEl()).color : '#e8ecf2' }; Combat.burst(cx, cy, 30 + 30 * k, '#e8ecf2', 4.5);
      for (const e of foes) if (this.gearAhead(e, R, 1.0)) this.hammerHit(e, K, 1 + 4 * k, m);
    } else if (K.type === 'arc') {
      const n = 2 + Math.round(6 * k), hit = new Set(); let from = { x: this.x + fx * 12, y: this.y + fy * 12 }, range = K.range * 1.3;
      for (let j = 0; j < n; j++) {
        let best = null, bd = range * range; for (const e of foes) { if (hit.has(e) || (j === 0 && !this.gearAhead(e, range, K.arc * 1.3))) continue; const d2 = U.dist2(e.x, e.y, from.x, from.y); if (d2 < bd) { bd = d2; best = e; } }
        if (!best) break; hit.add(best); Combat.damage(best, K.dmg * (1 + 3 * k) * m * Combat.resist(best, 'electric'), '#7df9ff'); Combat.addStatus(best, 'spark', 3, K.dmg * 0.4 * G.mods.elem, false); best.stun = Math.max(best.stun || 0, 0.6);
        Combat.arcs.push({ x1: from.x, y1: from.y, x2: best.x, y2: best.y, t: 0.35, seed: Math.random() }); from = best; range = K.jump * 1.5;
      }
    } else if (K.type === 'spores') {
      Combat.clouds.push({ x: this.x + fx * K.ahead * 1.5, y: this.y + fy * K.ahead * 1.5, r: K.r * (1 + 1.5 * k), t: 5, mag: K.mag * (1 + 2 * k) * G.mods.elem * this.gearMult(), seed: Math.random() * 10 });
      Combat.burst(this.x + fx * K.ahead, this.y + fy * K.ahead, 20 + 25 * k, '#c58aff', 3);
    }
  },
  gearUpdate(dt) {
    const id = this.gear; if (!id || G.mode !== 'world' || !this.alive || this.flight || !this.ready) return;
    const B = BULLETS[id], K = B.gear, fx = Math.cos(this.angle), fy = Math.sin(this.angle), m = G.powerMult * this.gearMult(), near = Combat.near || G.enemies;
    const foes = near.filter(e => !e.dead && !e.hidden && !(e.ally > 0) && !e.minion && e.kind !== 'stone');
    this.gearT = (this.gearT || 0) - dt;
    { // hold fire to charge a mega-hit; fire again to unleash it (the longer the hold, up to CFG.GEAR.charge.max, the bigger)
      const C = CFG.GEAR.charge, was = this.gearCharge || 0;
      if (!this.firing) { this.gearCharge = Math.min(C.max, was + dt); if (was < C.max && this.gearCharge >= C.max) { SFX.play('megaReady'); Combat.floaters.push({ x: this.x, y: this.y - 36, t: 1, text: 'CHARGED!', color: B.color }); } }
      else { if (was >= C.min) this.gearUnleash(B, was / C.max, foes, fx, fy, m); this.gearCharge = 0; }
    }
    if (K.type === 'cone') { // a cone licking ahead: fire (the Pumpkin Head, the Flamethrower) or frosty breath (the Santa Hat)
      const ice = K.el === 'ice', pal = ice ? ['#ffffff', '#cfeeff', '#9fdcff'] : ['#ff8a3d', '#ffd166', '#ff5c3d'];
      if (U.chance(dt * 40)) { const a = this.angle + U.rand(-K.arc, K.arc) * 0.8, v = U.rand(220, 360); Combat.particles.push(Combat.mk(this.x + fx * 14, this.y + fy * 14, Math.cos(a) * v, Math.sin(a) * v, K.range / 300, U.pick(pal), U.rand(4, 7))); }
      if (this.gearT <= 0) { this.gearT = 0.1; this.gearFreezeT = (this.gearFreezeT || 0) - 0.1;
        const freeze = ice && this.gearFreezeT <= 0; if (freeze) this.gearFreezeT = K.freezeEvery;
        for (const e of foes) if (this.gearAhead(e, K.range, K.arc)) {
          if (ice) { Combat.damage(e, K.dps * 0.1 * m * Combat.resist(e, 'ice'), '#cfeeff'); Combat.addStatus(e, 'slow', 1, 0); if (freeze) Combat.addStatus(e, 'freeze', 0.6, 0); }
          else { Combat.damage(e, K.dps * 0.1 * m * Combat.resist(e, 'fire') * (e.st.dry ? CFG.DRY.fire : 1), '#ff9a3d'); Combat.addStatus(e, 'burn', 2, K.burn * G.mods.elem, true); }
        } }
    } else if (K.type === 'ram') { // the Bone Helmet: whatever the head runs into is battered, dried out and shoved aside
      for (const e of foes) { if ((e.ramT || 0) > G.time || U.dist2(e.x, e.y, this.x, this.y) > (CFG.HEAD_R + (e.r || 12) + 6) ** 2) continue; e.ramT = G.time + 0.4; Combat.damage(e, K.dmg * m, '#eae4d4'); Combat.dry(e, CFG.DRY.time); if (e.kind !== 'boss') { const d = Math.hypot(e.x - this.x, e.y - this.y) || 1; e.x += (e.x - this.x) / d * 30; e.y += (e.y - this.y) / d * 30; } Combat.burst(e.x, e.y, 8, '#eae4d4', 2.5); SFX.play('stomp'); G.shake = Math.max(G.shake, 0.1); }
    } else if (K.type === 'hammer') { // the Front Hammer: whatever the head runs into is smashed
      for (const e of foes) { if ((e.ramT || 0) > G.time || U.dist2(e.x, e.y, this.x, this.y) > (CFG.HEAD_R + (e.r || 12) + 8) ** 2) continue; e.ramT = G.time + 0.45; this.hammerHit(e, K, 1, m); }
    } else if (K.type === 'arc') { // the Thundercap: a bolt to the nearest monster ahead, then one more beyond
      if (this.gearT <= 0) {
        let best = null, bd = 1e12; for (const e of foes) { if (!this.gearAhead(e, K.range, K.arc)) continue; const d2 = U.dist2(e.x, e.y, this.x, this.y); if (d2 < bd) { bd = d2; best = e; } }
        if (best) { this.gearT = K.cd; const hit = (e, x0, y0, k) => { Combat.damage(e, K.dmg * k * m * Combat.resist(e, 'electric'), '#7df9ff'); Combat.addStatus(e, 'spark', 2, K.dmg * 0.3 * G.mods.elem, false); e.stun = Math.max(e.stun || 0, 0.3); Combat.arcs.push({ x1: x0, y1: y0, x2: e.x, y2: e.y, t: 0.25, seed: Math.random() }); };
          hit(best, this.x + fx * 12, this.y + fy * 12, 1); SFX.play('zap');
          let nx = null, nd = K.jump * K.jump; for (const e of foes) { if (e === best) continue; const d2 = U.dist2(e.x, e.y, best.x, best.y); if (d2 < nd) { nd = d2; nx = e; } } if (nx) hit(nx, best.x, best.y, 0.6); }
      }
    } else if (K.type === 'spores') { // the Mushroom Cap: puffs of poison spores hung in your path
      if (this.gearT <= 0) { this.gearT = K.cd; Combat.clouds.push({ x: this.x + fx * K.ahead, y: this.y + fy * K.ahead, r: K.r, t: K.t, mag: K.mag * G.mods.elem * this.gearMult(), seed: Math.random() * 10 }); }
    }
    // your own mines inside the forward reach go off: lay them, loop round, drive back through
    const M = CFG.GEAR.mines; for (const p of Combat.projectiles) if (p.kind === 'mine' && p.owner === 'player' && !p.exploded && p.arm <= 0 && this.gearAhead(p, M.range, M.arc)) { Combat.explode(p, p.type.blast, M.mult); p.life = -1; }
  },
});
