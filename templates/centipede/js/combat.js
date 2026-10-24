// Projectiles, status effects, poison clouds, particles, lightning arcs, damage numbers.
const Combat = {
  projectiles: [], clouds: [], particles: [], arcs: [], floaters: [],
  reset() {
    this.projectiles = []; this.clouds = []; this.particles = []; this.arcs = []; this.floaters = [];
    for (const s of Player.segments) s.inflight = 0; // every bullet is gone, so every segment is reloaded
    if (Player.head) Player.head.inflight = 0;
  },

  // Fire one bullet from a segment. The segment is busy until every projectile it owns is gone.
  // (a burst bullet fires B.burst projectiles at once; all of them must return before the segment reloads)
  fire(x, y, angle, B, mult, seg) {
    if (B.burst && !seg?._inBurst) { seg = seg || null; let last = null; const tag = seg || {}; tag._inBurst = true; for (let k = 0; k < B.burst; k++) last = this.fire(x, y, angle + (k - (B.burst - 1) / 2) * 0.08, B, mult, seg); tag._inBurst = false; return last; }
    const a = angle + U.rand(-(B.spread || 0), B.spread || 0);
    const M = G.mods, ramp = this.ramp(B) * (G.mode === 'deck' ? CFG.DECK_DMG : 1), spd = B.speed * (B.kind === 'orbit' || B.kind === 'pulse' ? 1 : M.bspeed), range = (B.range || 0) * M.range;
    const p = {
      x, y, vx: Math.cos(a) * spd, vy: Math.sin(a) * spd, angle: a, spd, range,
      dmg: B.dmg * mult * ramp, base: B.dmg * (mult / (G.powerMult || 1)) * ramp, /* base drives statuses: Brawn (powerMult) stays out of them */ elems: (B.elems || []).concat(G.deepFreeze > 0 && !(B.elems || []).includes('ice') ? ['ice'] : []) /* (Deep Freeze: every bullet is ice too) */, size: B.size, type: B, kind: B.kind,
      life: B.life ? B.life * M.range : range / spd, pierce: B.pierce || 0, hit: new Set(), owner: 'player', color: B.color, laser: !!B.laser,
      seg, spin: 0, travel: 0, returning: false, bounces: B.bounces || 0, arm: 0.5, hitT: 0,
    };
    p.combo = B.combo || null; p.beam = !!B.beam; p.pipe = seg && seg.pipe || null; p.pipeBoost = !!(seg && seg.pipeBoost); p.charged = seg && seg.charged || null;
    if (p.pipe === 'ricochet' && !(p.bounces > 0) && (B.kind === 'shot' || B.kind === 'homing')) p.bounces = CFG.PIPE_BOUNCES; // +ricochet piping: any shot or moth reflects
    if (B.kind === 'orbit') { p.orb = U.rand(0, TAU); }
    if (B.kind === 'mine') { p.vx = -Math.cos(angle) * 40; p.vy = -Math.sin(angle) * 40; }
    if (B.kind === 'pulse') { p.r = 10; p.grow = B.speed * M.range; p.life0 = p.life; } // Long Reach: the ring spreads faster AND lasts longer, so each copy makes it a lot wider (x1.69, x2.86...)
    if (this.projectiles.length >= this.MAX_PROJ) return null;
    if (seg) seg.inflight = (seg.inflight || 0) + 1;
    this.projectiles.push(p);
    // Twin Shot: every bullet that flies brings free copies beside it (no segment owns them, so the reload clock ignores them)
    if (M.twin > 0 && !p.extra && (B.kind === 'shot' || B.kind === 'bounce' || B.kind === 'homing' || B.kind === 'boomerang')) {
      for (let k = 1; k <= M.twin && this.projectiles.length < this.MAX_PROJ - 10; k++) {
        const off = (k % 2 ? 1 : -1) * Math.ceil(k / 2) * 0.13, q = Object.assign({}, p, { hit: new Set(), seg: null, extra: true, dmg: p.dmg * Perks.twinMult(M.twin), base: p.base, /* a copy hits softer, statuses at full */ angle: a + off, vx: Math.cos(a + off) * spd, vy: Math.sin(a + off) * spd, elems: p.elems.slice() });
        if (B.kind === 'orbit') q.orb = U.rand(0, TAU);
        this.projectiles.push(q);
      }
    }
    return p;
  },
  MAX_PROJ: 400, // hard safety cap; the real limit is one bullet per segment
  ramp(B) { return 1 + (B.tier >= 3 ? CFG.LEVEL_RAMP : CFG.LEVEL_RAMP_LOW) * ((G.level || 1) - 1); }, // every bullet grows with the level, fused ones faster
  spawnShard(parent, angle, frac) {
    if (this.projectiles.length >= this.MAX_PROJ) return;
    frac = frac || 0.6; // a Splitter's own shards carry 60% of its damage; piped shards a third
    const p = { x: parent.x, y: parent.y, vx: Math.cos(angle) * 420, vy: Math.sin(angle) * 420, angle, dmg: parent.dmg * frac, base: parent.base * frac, elems: [], size: 3, type: parent.type, kind: 'shot',
      life: 0.4, pierce: 0, hit: new Set(), owner: 'player', color: parent.color, laser: false, seg: parent.seg, spin: 0, travel: 0, returning: false, bounces: 0, arm: 0, hitT: 0, split: true, shard: true };
    if (p.seg) p.seg.inflight++;
    this.projectiles.push(p);
  },
  remove(i) {
    const p = this.projectiles[i];
    if (p.seg) { p.seg.inflight = Math.max(0, p.seg.inflight - 1); if (p.owner === 'player' && p.type && !p.shard) p.seg.cd = Math.max(p.seg.cd || 0, (p.type.cd || 0.2) * G.mods.cd * (G.storm > 0 ? 0.5 : 1)); } // the reload clock starts when the bullet lands, not when it leaves
    if (p.owner === 'enemy' && p.venom) this.clouds.push({ x: p.x, y: p.y, r: p.cloudR || 55, t: p.cloudT || 2.5, mag: 0, kind: 'venom', enemy: true, seed: Math.random() * 10 }); // a venom glob bursts wherever it stops
    if (p.owner === 'player' && (p.combo === 'gas' || p.combo === 'bomb') && !p.gassed && p.hit.size === 0) this.gasBlast(p, p.x, p.y);
    else if (p.owner === 'player' && p.type && p.type.cloudOnExpire && !p.exploded && p.hit.size === 0) this.clouds.push({ x: p.x, y: p.y, r: 44, t: 4.5, mag: p.base * 0.6 * G.mods.elem, seed: Math.random() * 10 });
    this.projectiles[i] = this.projectiles[this.projectiles.length - 1]; this.projectiles.pop();
  },
  // move a bullet one step, reflecting off anything solid while it has bounces left: fresh range and a cleared hit list each bounce
  reflectMove(p, dt) {
    const nx = p.x + p.vx * dt, ny = p.y + p.vy * dt, probe = { x: nx, y: p.y, size: p.size, owner: p.owner, dmg: p.dmg, type: p.type };
    const hitX = G.hitSolid(probe); if (hitX) { p.vx = -p.vx; } else p.x = nx;
    probe.x = p.x; probe.y = ny; const hitY = G.hitSolid(probe); if (hitY) { p.vy = -p.vy; } else p.y = ny;
    if (hitX || hitY) {
      p.bounces--; p.angle = Math.atan2(p.vy, p.vx); p.life += (p.range || 300) / p.spd * 0.6; p.hit.clear(); p.bounced = true; this.burst(p.x, p.y, 4, p.color, 1.5);
      if (p.dmg0 === undefined) { p.dmg0 = p.dmg; p.base0 = p.base; } p.bounceN = (p.bounceN || 0) + 1; // each bounce hits for less: full / (bounces so far + 1)
      const k = Math.max(CFG.RICOCHET.floor, 1 / (1 + CFG.RICOCHET.falloff * p.bounceN)); p.dmg = p.dmg0 * k; p.base = p.base0 * k;
    }
    return hitX || hitY;
  },
  explode(p, radius, dmgMult) {
    p.exploded = true; if (p.pipe && PIPES[p.pipe]) this.burst(p.x, p.y, 22, PIPES[p.pipe].color, 3.5); // a piped mine's blast in its element's colour
    this.burst(p.x, p.y, 26, p.color, 3); this.burst(p.x, p.y, 10, '#fff', 2);
    G.shake = Math.max(G.shake, 0.25); SFX.at(p.x, () => SFX.play('wall'));
    for (const e of (this.near || G.enemies)) {
      if (e.dead || e.hidden || e.ally > 0) continue;
      if (U.dist2(e.x, e.y, p.x, p.y) < (radius + e.r) ** 2) { this.damage(e, p.dmg * (dmgMult || 1), p.color); this.applyElements(p.elems, e, e.x, e.y, p.base); if (p.pipe || p.charged) this.applyPipe(p, e); } // (a piped mine blows its element too)
    }
  },
  // RESONANCE (CFG.RESONANCE): pulse ring p sweeps over mine m. The mine blows wider and harder and rings out a shockwave
  // of its own (the mine's elements, the pulse's reach), which sets off any other mine it crosses: lay a field, then pulse it
  resonate(m, p) {
    const K = CFG.RESONANCE;
    this.explode(m, m.type.blast * K.blastMult, K.dmgMult); m.life = -1;
    this.floaters.push({ x: m.x, y: m.y - 16, t: 0.7, text: 'RESONANCE', color: K.color });
    if (this.projectiles.length < this.MAX_PROJ) this.projectiles.push({ x: m.x, y: m.y, vx: 0, vy: 0, angle: 0, r: 10, grow: p.grow, life: p.life0, life0: p.life0, dmg: m.dmg * K.ringDmg, base: m.base * K.ringDmg, elems: m.elems.slice(), size: 4, type: p.type, kind: 'pulse', combo: p.combo, pierce: 0, hit: new Set([m]), owner: 'player', color: K.color, seg: null, fixed: true, resonance: true, spin: 0, travel: 0, arm: 0, hitT: 0 });
    SFX.play('zap');
  },
  segPos(p) { // where the segment that fired this bullet is right now (head if it's gone)
    const i = Player.segments.indexOf(p.seg);
    return i >= 0 && Player.segPos[i] ? Player.segPos[i] : Player; // head (or a lost segment) -> the head
  },
  fireEnemy(x, y, angle, o) {
    if (typeof Enemies !== 'undefined' && Enemies.acting) SFX.attack(Enemies.acting); // the monster firing it: its attack sound (once for a spread)
    const speed = o.speed || 260;
    this.projectiles.push({ x, y, vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed, dmg: 0, power: o.power === undefined ? 1 : o.power, size: o.size || 6, life: o.life || 2.6, owner: 'enemy', color: o.color || '#9ef04c', elems: [], hit: new Set(), pierce: 0, type: null, hostile: !!o.hostile, from: o.from || null, venom: !!o.venom, cloudR: o.cloudR, cloudT: o.cloudT, bounce: o.bounce || 0, pie: !!o.pie, push: !!o.push, spin: 0 });
  },

  // ---- damage & status ----
  damage(e, amount, color) {
    if (e.dead) return;
    if (e.minion) { Minions.hurt(e, amount); return; }
    if (e.kind === 'boss' && G.storm > 0) amount *= BOSSES[e.type].megaResist; // bosses shrug off part of a Bullet Storm
    e.hp -= amount;
    e.flash = 0.08;
    if (amount >= 1) this.floaters.push({ x: e.x + U.rand(-6, 6), y: e.y - 14, t: 0.7, text: Math.round(amount), color: color || '#fff' });
    if (e.hp <= 0) this.kill(e);
  },
  kill(e) {
    if (e.dead) return;
    e.dead = true;
    this.burst(e.x, e.y, 16, e.color || '#f66', 3);
    SFX.at(e.x, () => { // from its side of the screen
      if (e.kind === 'boss' && SFX.custom('death:' + SFX.typeOf(e))) SFX.layers(SFX.custom('death:' + SFX.typeOf(e))); // a boss's own death sound, once designed
      else if (e.kind === 'boss' || e.kind === 'stone' || e.kind === 'rock') SFX.play('kill'); else SFX.death(e); // each monster has its own noise
    });
    if (e.kind === 'boss' && Music.on && !Music.isLevelTheme(Music.name)) Music.stop(); // its theme ends with it (the boss's own update never sees it dead: the list drops it first)
    if (e.ally > 0) { e.onDeath = null; }
    if (e.onDeath) e.onDeath();
    if (e.ally > 0) return;
    Mega.charge(e);
  },
  // resistances (js/bestiary.js, from the bestiary editor): a monster's multiplier for an element is its own row's value,
  // else its family's, else 1, times its level's row and the nightmare row. 0 immune, 0.5 suppressed, 1.5 / 2 weak. Looked up once per monster and kept on it
  ELEM_OF: { burn: 'fire', poison: 'poison', freeze: 'ice', slow: 'ice', spark: 'electric', giddy: 'poison' }, // (giddy: laughing gas, so poison immunity blocks it)
  rowOf(e) {
    if (e.kind === 'boss') return 'boss-' + e.type;
    if (e.unique) return 'unique-' + e.unique;
    if (e.kind === 'snake') return 'snake-' + (e.bone ? 'bone' : e.python ? 'python' : e.balloon ? 'balloon' : 'snake');
    if (e.kind === 'parasite') return 'parasite-' + e.id;
    if (e.kind === 'bug') return (e.type === 'kamighost' || e.type === 'kamiclown' || e.type === 'chicken' ? 'summon-' : 'bug-') + e.type;
    return '';
  },
  resist(e, el) {
    if (!e.res) {
      const R = (typeof BESTIARY !== 'undefined' && BESTIARY.resist) || {}, row = R[this.rowOf(e)] || {}, fam = R['family-' + e.kind] || {};
      e.res = {}; for (const k of Object.keys(ELEMENTS)) e.res[k] = typeof row[k] === 'number' ? row[k] : typeof fam[k] === 'number' ? fam[k] : 1;
    }
    let r = (e.res[el] === undefined ? 1 : e.res[el]) * this.resistMod(e, el);
    if (Player.syn && Player.syn[el] && !e.minion) { const K = CFG.SYNERGY; if (r > 0 && r < 1) r += (1 - r) * K.pen; else if (r <= 0 && K.immuneFloor > 0) r = K.immuneFloor; } // synergy: penetration (immunity holds unless immuneFloor)
    return el === 'ice' && G.deepFreeze > 0 ? Math.max(CFG.DEEP_FREEZE.mult, r) : r; // Deep Freeze: no cold resistance survives it
  },
  // the level's row and, in nightmare, the nightmare row multiply on top (level-1 .. level-5, mode-nightmare; absent = 1)
  resistMod(e, el) {
    const R = (typeof BESTIARY !== 'undefined' && BESTIARY.resist) || {}, lv = R['level-' + Math.min(5, World.levelOf(e.region || 0))], nm = G.nightmare ? R['mode-nightmare'] : null;
    return (lv && typeof lv[el] === 'number' ? lv[el] : 1) * (nm && typeof nm[el] === 'number' ? nm[el] : 1);
  },
  // the floater that teaches it, from your side of the hit: STRONG! (red) / RESIST (yellow-orange) / IMMUNE (grey-white), at most once a second per monster.
  // Fine tuning (0.95, 1.11) says nothing: only a clear tilt (under 0.8, 1.25 and over) is worth a word
  resistTag(e, el, r) {
    if ((r > 0 && r >= 0.8 && r < 1.25) || (e.tagT || 0) > G.time) return; e.tagT = G.time + 1.1;
    this.floaters.push({ x: e.x, y: e.y - (e.r || 12) - 18, t: 0.9, text: r <= 0 ? 'IMMUNE' : r < 1 ? 'RESIST' : 'STRONG!', color: r <= 0 ? '#d8dce4' : r < 1 ? '#ffb347' : '#ff4a4a' }); // red: you are hurting it, yellow-orange: it shrugs some off, grey-white: nothing gets through
  },
  addStatus(e, name, t, mag, stack) {
    if (name === 'freeze' && e.st.dry) { if ((e.dryT || 0) < G.time) { e.dryT = G.time + 1; this.floaters.push({ x: e.x, y: e.y - (e.r || 12) - 18, t: 0.7, text: 'TOO DRY', color: '#e0c890' }); } return; } // nothing in it to freeze
    const el = this.ELEM_OF[name];
    if (el) { const r = this.resist(e, el); if (r <= 0) { this.resistTag(e, el, r); return; } t *= r; if (name !== 'spark') mag *= r; } // (a spark's zaps are scaled per monster as they land)
    if (e.kind === 'boss' && name === 'freeze') { // a boss freezes briefly, then shrugs ice off for a while: no build may lock one down for the whole fight
      if (e.freezeCd > 0) { if (U.chance(0.15)) this.floaters.push({ x: e.x, y: e.y - e.r - 10, t: 0.6, text: 'SHRUGS OFF ICE', color: '#ffb347' }); return; } // (the freeze fails, the damage does not: the same yellow-orange as RESIST)
      t = Math.min(t, 1.4); e.freezeCd = 7;
    }
    const s = e.st[name];
    if (s) {
      s.t = Math.max(s.t, t);
      s.mag = stack ? Math.min(s.mag + mag * 0.6, mag * 4) : Math.max(s.mag, mag);
    } else { e.st[name] = { t, mag, tick: 0, t0: t }; if (name === 'freeze') { this.burst(e.x, e.y, 12, '#e8f6ff', 2.5); this.burst(e.x, e.y, 6, '#9fdcff', 1.5); } } // freezing over: a puff of frost
  },
  applyHit(p, e) {
    let dmg = p.dmg, col = p.color;
    if (e.st.freeze) dmg *= Player.syn && Player.syn.ice ? CFG.SYNERGY.frozenTaken : 1.25; // (ice synergy: frozen takes more)
    if (e.st.burn && Player.syn && Player.syn.fire) dmg *= CFG.SYNERGY.burningTaken; // fire synergy: burning takes more
    if (e.st.hypno) dmg *= CFG.SONG.dmgMult; // entranced by a singing tree
    if (e.gasOpen) dmg *= BOSSES[e.type].gasMult; // the clown car, out of gas and refuelling
    if (e.fright) dmg *= 2;
    if (e.kind === 'stone' && p.type && p.type.wallMult) dmg *= p.type.wallMult; // rocks and boulders smash stones
    if (e.armor && !p.elems.length && !p.combo) { dmg *= e.armor; col = '#aaa'; if (U.chance(0.3)) this.floaters.push({ x: e.x, y: e.y - 26, t: 0.6, text: 'armor', color: '#aaa' }); }
    if (e.st.dry && Player.syn && Player.syn.bone) dmg *= CFG.DRY.synergy; // bone synergy: whatever hits a dried-out foe
    if (e.st.dry && p.elems.includes('fire')) { dmg *= CFG.DRY.fire; if ((e.tinderT || 0) < G.time) { e.tinderT = G.time + 1; this.floaters.push({ x: e.x, y: e.y - (e.r || 12) - 32, t: 0.8, text: 'TINDER!', color: '#ff8a3d' }); } } // dried out: fire takes hold
    if (p.elems.length) { let r = 0, tag = p.elems[0]; for (const el of p.elems) { const q = this.resist(e, el); r += q; if (q !== 1) tag = el; } r /= p.elems.length; if (G.deepFreeze > 0) r = Math.max(r, CFG.DEEP_FREEZE.mult); dmg *= r; this.resistTag(e, tag, r); } // an element the monster resists (or is weak to) scales the hit too
    if (p.combo === 'plasma' && e.armor) dmg *= 1.5;
    if (p.combo === 'shatter' && e.st.freeze) { dmg *= 3; this.burst(e.x, e.y, 14, '#ffd9c2', 3); }
    this.damage(e, dmg, col);
    this.burst(p.x, p.y, 4, p.color, 1.5);
    SFX.hitOn(e);
    this.applyElements(p.elems, e, p.x, p.y, p.base, p.combo);
    if (p.type && p.type.spear && !p.elems.includes('ice') && !e.dead) this.dry(e, CFG.DRY.time); // bone: it dries them out (an ice-tipped spear freezes instead)
    this.applyCombo(p, e, p.x, p.y);
    this.applyPipe(p, e);
  },
  // piping: minor status leaked from the segment ahead (see PIPES)
  // Laughing gas: poisoned (CFG.GIGGLE.poison s), and giddy for as long as the poison lasts: no attacks, wandering away giggling.
  // Bosses only take the poison; a poison-immune monster takes neither (giddy is a poison status)
  giggle(e, base) {
    if (e.kind === 'stone' || e.minion || e.dead) return;
    this.addStatus(e, 'poison', CFG.GIGGLE.poison, base * 0.5 * G.mods.elem, false);
    if (e.kind === 'boss' || !e.st.poison) return;
    const was = !!e.st.giddy; this.addStatus(e, 'giddy', e.st.poison.t, 0); if (e.st.giddy) e.st.giddy.t = Math.max(e.st.giddy.t, e.st.poison.t);
    if (!was && e.st.giddy) { e.windup = 0; if (e.tongue) e.tongue = null; this.burst(e.x, e.y, 6, '#ffaad8', 2); if ((e.hahaT || 0) < G.time) { e.hahaT = G.time + 1.5; this.floaters.push({ x: e.x, y: e.y - (e.r || 12) - 18, t: 0.9, text: 'HA HA!', color: '#ffaad8' }); SFX.play('laugh'); } }
  },
  // Desiccated: dried out for t seconds (fire does more, ice will not take), with a puff of dust going on
  dry(e, t) { if (e.kind === 'stone' || e.minion) return; const was = !!e.st.dry; this.addStatus(e, 'dry', t, 0); if (!was) { this.burst(e.x, e.y, 8, '#e0c890', 2); if ((e.dryT || 0) < G.time) { e.dryT = G.time + 1.2; this.floaters.push({ x: e.x, y: e.y - (e.r || 12) - 18, t: 0.8, text: 'DRIED OUT', color: '#e0c890' }); } } },
  // the pipe from the segment ahead (charged when a run ahead carries it), then every charged pipe the runs ahead send down the body
  applyPipe(p, e) {
    if (p.pipe) this.pipeHit(p.pipe, p.pipeBoost, p, e);
    if (p.charged) for (const kind of p.charged) if (kind !== p.pipe) this.pipeHit(kind, true, p, e);
  },
  pipeHit(kind, boost, p, e) {
    const k = boost ? CFG.SYNERGY.pipeBoost : 1; // charged (+++): behind a run of 3+ of the element
    switch (kind) {
      case 'ice': this.addStatus(e, 'slow', 1.2 * k, 0); if (boost) this.addStatus(e, 'freeze', 0.5, 0); break; // +++chill: a brief freeze too
      case 'electric': e.stun = Math.max(e.stun || 0, 0.3 * k); this.arcs.push({ x1: p.x, y1: p.y, x2: e.x, y2: e.y, t: boost ? 0.2 : 0.1, seed: Math.random() }); if (boost) this.addStatus(e, 'spark', 2, p.base * 0.3 * G.mods.elem, false); break; // +++jolt: a spark that keeps zapping
      case 'fire': this.addStatus(e, 'burn', 1.5, p.base * 0.2 * G.mods.elem * k, true); break;
      case 'poison': this.addStatus(e, 'poison', 1.2, p.base * 0.25 * G.mods.elem * k, false); break;
      case 'dazzle': this.addStatus(e, 'blind', 0.8, 0); break;
      case 'dry': this.dry(e, CFG.DRY.pipeTime); break;
    }
  },
  // combo effects on hit (see COMBOS in config)
  applyCombo(p, e, x, y) {
    switch (p.combo) {
      case 'gas': this.gasBlast(p, x, y); break;
      case 'berserk': case 'madmoth': if (e) { this.addStatus(e, 'berserk', 4, 0); this.burst(x, y, 8, '#ff7bff', 2); } break;
      case 'bomb': this.gasBlast(p, x, y); if (G.mode === 'world') { const w = World.wallAt(x, y, 130); if (w) World.damageWall(w, 60); } break;
      case 'steam': this.clouds.push({ x, y, r: 75, t: 3, mag: 0, kind: 'steam', seed: Math.random() * 10 }); break;
      case 'giggle': if (e) this.giggle(e, p.base); if (!p.giggled) { p.giggled = true; const K = CFG.GIGGLE; this.clouds.push({ x, y, r: K.cloud, t: K.cloudTime, mag: p.base * 0.4 * G.mods.elem, kind: 'giggle', seed: Math.random() * 10 }); } break; // laughing gas: a pink cloud where it lands
      case 'napalm': if (e) for (const o of G.enemies) { if (o.dead || o === e || o.ally > 0) continue; if (U.dist2(o.x, o.y, x, y) < 90 * 90) this.addStatus(o, 'burn', 3, p.base * 0.55 * G.mods.elem, true); } break;
    }
  },
  gasBlast(p, x, y) {
    if (p.gassed) return; p.gassed = true;
    const r = 130;
    this.burst(x, y, 30, '#b6ff5c', 4); this.burst(x, y, 12, '#fff', 2); G.shake = Math.max(G.shake, 0.3); SFX.play('wall');
    for (const o of G.enemies) { if (o.dead || o.hidden || o.ally > 0) continue; if (U.dist2(o.x, o.y, x, y) < (r + o.r) ** 2) { this.damage(o, p.dmg * 2.5, '#b6ff5c'); this.addStatus(o, 'poison', 2, p.base * 0.6 * G.mods.elem, false); } }
    if (G.mode === 'world') for (const t of World.trees) if (t.alive && U.dist2(t.x, t.y, x, y) < 80 * 80) World.meltTree(t);
    this.clouds.push({ x, y, r: 60, t: 3, mag: p.base * 0.6 * G.mods.elem, seed: Math.random() * 10 });
  },
  applyElements(elems, e, x, y, base, combo) {
    if (!elems.length) return;
    const n = {}, EM = G.mods.elem; // Potent Elements perk
    for (const el of elems) n[el] = (n[el] || 0) + 1;
    if (n.fire) SFX.extra('elem:fire'); if (n.poison) SFX.extra('elem:poison'); // (silent until designed in the SFX editor)
    if (n.fire) { this.addStatus(e, 'burn', 3, base * 0.55 * n.fire * EM * (combo === 'napalm' ? 3 : 1), true); this.burst(x, y, 5, '#ff8a3d', 2); }
    if (n.poison && combo !== 'gas') { const sy = Player.syn && Player.syn.poison, K = CFG.SYNERGY; this.clouds.push({ x, y, r: (40 + 10 * n.poison) * (sy ? K.cloudSize : 1), t: 4.5 * (sy ? K.cloudTime : 1), mag: base * 0.6 * n.poison * EM, seed: Math.random() * 10, kind: combo === 'charged' ? 'charged' : 'poison' }); }
    if (n.ice) { if (!e.st.freeze) SFX.play('freeze'); this.addStatus(e, 'freeze', (1.0 + 0.35 * n.ice) * EM, 0); }
    if (n.electric) {
      const rad = (95 + 25 * n.electric) * (combo === 'arc' ? 2 : 1);
      SFX.play('zap');
      for (const o of G.enemies) {
        if (o.dead || o.ally > 0) continue;
        if (U.dist2(o.x, o.y, x, y) < rad * rad) {
          this.addStatus(o, 'spark', 2.2, base * 0.35 * n.electric * EM, false);
          if (combo === 'arc') this.addStatus(o, 'poison', 2.5, base * 0.5 * EM, false);
          this.arcs.push({ x1: x, y1: y, x2: o.x, y2: o.y, t: 0.18, seed: Math.random() });
        }
      }
      if (Player.syn && Player.syn.electric) { // electric synergy: the spark leaps on to the nearest enemies beyond its reach
        const far = G.enemies.filter(o => !o.dead && !o.hidden && !(o.ally > 0) && o.kind !== 'stone' && U.dist2(o.x, o.y, x, y) >= rad * rad && U.dist2(o.x, o.y, x, y) < (rad * 2.5) ** 2).sort((a, b) => U.dist2(a.x, a.y, x, y) - U.dist2(b.x, b.y, x, y)).slice(0, CFG.SYNERGY.arcs);
        for (const o of far) { this.addStatus(o, 'spark', 2.2, base * 0.35 * n.electric * EM, false); this.arcs.push({ x1: x, y1: y, x2: o.x, y2: o.y, t: 0.25, seed: Math.random() }); }
      }
    }
  },
  tickStatus(e, dt) {
    const st = e.st;
    for (const k in st) {
      const s = st[k];
      s.t -= dt;
      if (s.t <= 0) { delete st[k]; if (k === 'freeze' && !e.dead) { for (let q = 0; q < 10; q++) { const a = Math.random() * TAU, v = U.rand(60, 160); this.particles.push(this.mk(e.x, e.y, Math.cos(a) * v, Math.sin(a) * v - 30, 0.5, U.pick(['#e8f6ff', '#bfe6ff', '#ffffff']), 3)); } } continue; } // thawing: the ice breaks off in shards
      if (k === 'dry') { if (U.chance(dt * 6)) this.particles.push(this.mk(e.x + U.rand(-8, 8), e.y + U.rand(-4, 8), U.rand(-20, 20), U.rand(-10, 10), 0.6, '#d8c08a', 3)); continue; } // dust puffing off them
      if (k === 'burn') { e.hp -= s.mag * dt * (st.dry ? CFG.DRY.fire : 1); if (U.chance(dt * 14)) this.particles.push(this.mk(e.x + U.rand(-8, 8), e.y + U.rand(-8, 8), U.rand(-10, 10), U.rand(-60, -30), 0.5, '#ff9a3d', 3)); }
      else if (k === 'poison') { e.hp -= s.mag * dt; if (U.chance(dt * 8)) this.particles.push(this.mk(e.x + U.rand(-8, 8), e.y + U.rand(-8, 8), 0, -25, 0.7, '#9bf06a', 2.5)); }
      else if (k === 'spark') {
        s.tick -= dt;
        if (s.tick <= 0) {
          s.tick = 0.45;
          for (const o of G.enemies) {
            if (o.dead || o.ally > 0) continue;
            if (U.dist2(o.x, o.y, e.x, e.y) < 95 * 95) {
              o.hp -= s.mag * this.resist(o, 'electric'); o.flash = 0.06;
              if (o !== e) this.arcs.push({ x1: e.x, y1: e.y, x2: o.x, y2: o.y, t: 0.12, seed: Math.random() });
              if (o.hp <= 0) this.kill(o);
            }
          }
        }
      }
    }
    if (e.hp <= 0 && !e.dead) this.kill(e);
  },

  // ---- particles ----
  mk(x, y, vx, vy, life, color, size) { return { x, y, vx, vy, t: life, life, color, size }; },
  burst(x, y, n, color, size) {
    if (this.particles.length > 700) return;
    for (let i = 0; i < n; i++) {
      const a = Math.random() * TAU, s = U.rand(30, 160);
      this.particles.push(this.mk(x, y, Math.cos(a) * s, Math.sin(a) * s, U.rand(0.25, 0.6), color, size * U.rand(0.6, 1.4)));
    }
  },

  // a segment's shield charges (an Orbiter's: BULLETS[id].shield), filled on first use
  shieldOf(s) { if (s.shield === undefined) s.shield = BULLETS[s.id].shield || 0; return s.shield; },
  // does a shielded orbiter ball catch this enemy shot? Spends a charge; the last one starts the recharge
  orbBlock(p, orbs) {
    for (const q of orbs) {
      const s = q.seg; if (s.shield <= 0 || U.dist2(q.x, q.y, p.x, p.y) > (q.size + p.size + 6) ** 2) continue;
      s.shield--; this.burst(p.x, p.y, 6, q.color, 2); SFX.play('block');
      if (s.shield <= 0) { s.shieldT = BULLETS[s.id].recharge || 6; this.burst(q.x, q.y, 10, '#ffffff', 2.5); }
      return true;
    }
    return false;
  },
  // ---- the hit grid: this frame's nearby enemies bucketed into GRID px cells, so a bullet tests only the few in its own cell
  // instead of every enemy near the player (a Bullet Storm in a swarm was some 20,000 tests a frame). An enemy goes in every
  // cell its hit circles cover, grown by GRID_PAD, so a bullet up to that size needs look only in the cell its centre is
  // in. Cells keep near's order, so whatever a bullet meets first is the same enemy as before. Built once per Combat.update
  GRID: 128, GRID_PAD: 32, grid: new Map(), NONE: [],
  buildGrid(near) {
    const g = this.grid, S = this.GRID, pad = this.GRID_PAD; g.clear();
    for (const e of near) {
      if (e.dead || e.hidden) continue;
      let x0 = e.x - e.r, x1 = e.x + e.r, y0 = e.y - e.r, y1 = e.y + e.r;
      if (e.kind !== 'bug' && e.kind !== 'parasite') for (const c of e.hitCircles()) { if (c.x - c.r < x0) x0 = c.x - c.r; if (c.x + c.r > x1) x1 = c.x + c.r; if (c.y - c.r < y0) y0 = c.y - c.r; if (c.y + c.r > y1) y1 = c.y + c.r; } // a snake's body, a boss's claws
      const cx0 = Math.floor((x0 - pad) / S), cx1 = Math.floor((x1 + pad) / S), cy0 = Math.floor((y0 - pad) / S), cy1 = Math.floor((y1 + pad) / S);
      for (let cx = cx0; cx <= cx1; cx++) for (let cy = cy0; cy <= cy1; cy++) { const k = cx * 65536 + cy; const a = g.get(k); if (a) a.push(e); else g.set(k, [e]); }
    }
  },
  cellAt(x, y) { return this.grid.get(Math.floor(x / this.GRID) * 65536 + Math.floor(y / this.GRID)) || this.NONE; },
  // every enemy in the cells a circle (x, y, r) touches, each once
  around(x, y, r) {
    const S = this.GRID, out = [], q = this.gridQ = (this.gridQ || 0) + 1;
    for (let cx = Math.floor((x - r) / S); cx <= Math.floor((x + r) / S); cx++) for (let cy = Math.floor((y - r) / S); cy <= Math.floor((y + r) / S); cy++) { const a = this.grid.get(cx * 65536 + cy); if (a) for (const e of a) if (e._gq !== q) { e._gq = q; out.push(e); } }
    return out;
  },
  // the enemies a bullet could meet this frame: alive and within a generous box around the player (bullets never fly further)
  nearby() { const P = Player, out = []; for (const e of G.enemies) if (!e.dead && Math.abs(e.x - P.x) < 1500 && Math.abs(e.y - P.y) < 1100) out.push(e); return out; },
  update(dt) {
    const P = Player, near = this.nearby(); this.near = near; // bullets only ever meet what is near the player, not the whole world's enemies
    this.buildGrid(near);
    let aimed = null; // what player homers may chase: on screen and fair game (worked out once, the first time a homer asks)
    // orbiter shields: a segment's charges recharge together once spent; the balls of segments with charges left block enemy shots
    for (const s of P.segments) if (s.shield <= 0 && s.shieldT > 0) { s.shieldT -= dt; if (s.shieldT <= 0) { s.shield = BULLETS[s.id].shield || 0; SFX.play('shieldup'); } }
    const orbs = []; for (const q of this.projectiles) if (q.kind === 'orbit' && q.owner === 'player' && q.seg && this.shieldOf(q.seg) > 0) orbs.push(q);
    // projectiles
    for (let i = this.projectiles.length - 1; i >= 0; i--) {
      const p = this.projectiles[i];
      p.life -= dt;
      let dead = false;
      if (G.mode === 'deck' && p.owner === 'player' && p.kind !== 'orbit' && Deck.isSolid({ x: p.x + p.vx * dt, y: p.y + p.vy * dt })) { this.remove(i); continue; } // the saucer deck: nothing bounces off its edges (ricochets made a whole build a wall of lead)
      if (p.owner === 'player') {
        switch (p.kind) {
          case 'boomerang': {
            if (!p.returning) {
              p.x += p.vx * dt; p.y += p.vy * dt; p.travel += p.spd * dt;
              if (p.travel >= p.range) { p.returning = true; p.hit.clear(); p.life = 4; }
            } else {
              const h = this.segPos(p), d = U.dist(p.x, p.y, h.x, h.y);
              if (d < 14) dead = true;
              else { p.x += (h.x - p.x) / d * p.spd * 1.15 * dt; p.y += (h.y - p.y) / d * p.spd * 1.15 * dt; }
            }
            p.spin += 18 * dt; break;
          }
          case 'orbit': {
            const h = this.segPos(p);
            p.orb += p.type.speed * dt;
            p.x = h.x + Math.cos(p.orb) * p.type.radius; p.y = h.y + Math.sin(p.orb) * p.type.radius;
            p.hitT -= dt; if (p.hitT <= 0) { p.hitT = 0.35; p.hit.clear(); }
            break;
          }
          case 'homing': {
            let best = null, bd = 1e12;
            const deck = G.mode === 'deck' && p.owner === 'player'; // the saucer deck has no auto-aim: a moth only bends toward what is already nearly ahead of it, and slowly
            if (!aimed) aimed = near.filter(e => !e.dead && !e.hidden && !(e.ally > 0) && Render.aimable(e));
            for (const e of aimed) { if (e.dead) continue; if (deck && Math.abs(U.angDiff(Math.atan2(e.y - p.y, e.x - p.x), p.angle)) > 0.45) continue; const d2 = U.dist2(e.x, e.y, p.x, p.y); if (d2 < bd) { bd = d2; best = e; } }
            if (best && !p.wallAim) p.angle = U.angLerp(p.angle, Math.atan2(best.y - p.y, best.x - p.x), p.type.turn * dt * (deck ? 0.5 : 1));
            p.angle += Math.sin(G.time * 20 + p.spin) * 0.6 * dt; // flutter
            p.vx = Math.cos(p.angle) * p.spd; p.vy = Math.sin(p.angle) * p.spd;
            if (p.bounces > 0) { this.reflectMove(p, dt); break; } // a ricochet moth caroms off trees instead of splatting on them
            p.x += p.vx * dt; p.y += p.vy * dt; break;
          }
          case 'chain': { // the Ricochet: fly at the current target; with none, straight on until the first leg runs out, then home
            if (!p.returning) {
              if (p.target && (p.target.dead || p.target.hidden)) p.target = null;
              if (!p.target) { let best = null, bd = p.range * p.range; for (const e of near) { if (e.dead || e.hidden || e.ally > 0 || e.kind === 'stone' || p.hit.has(e)) continue; const d2 = U.dist2(e.x, e.y, p.x, p.y); if (d2 < bd) { bd = d2; best = e; } } p.target = best; }
              if (p.target) { p.angle = U.angLerp(p.angle, Math.atan2(p.target.y - p.y, p.target.x - p.x), 14 * dt); p.vx = Math.cos(p.angle) * p.spd; p.vy = Math.sin(p.angle) * p.spd; }
              p.x += p.vx * dt; p.y += p.vy * dt; p.travel += p.spd * dt;
              if (!p.target && p.travel >= p.range) { p.returning = true; p.hit.clear(); p.life = 4; }
              if (p.travel > p.range * 6) { p.returning = true; p.hit.clear(); p.life = 4; } // a hop that never lands still ends
            } else {
              const h = this.segPos(p), d = U.dist(p.x, p.y, h.x, h.y);
              if (d < 14) dead = true;
              else { p.x += (h.x - p.x) / d * p.spd * 1.15 * dt; p.y += (h.y - p.y) / d * p.spd * 1.15 * dt; }
            }
            p.spin += 20 * dt; break;
          }
          case 'bounce': {
            const nx = p.x + p.vx * dt, ny = p.y + p.vy * dt;
            if (G.hitSolid({ x: nx, y: p.y, size: p.size, owner: 'none' })) { p.vx = -p.vx; p.bounces--; this.burst(p.x, p.y, 3, p.color, 1.5); }
            else p.x = nx;
            if (G.hitSolid({ x: p.x, y: ny, size: p.size, owner: 'none' })) { p.vy = -p.vy; p.bounces--; this.burst(p.x, p.y, 3, p.color, 1.5); }
            else p.y = ny;
            if (p.bounces < 0) dead = true;
            p.spin += 10 * dt; break;
          }
          case 'mine': {
            p.x += p.vx * dt; p.y += p.vy * dt; p.vx *= 0.9; p.vy *= 0.9;
            p.arm -= dt; if (p.exploded) break; // (a resonance or head gear already set it off this frame)
            if (p.arm <= 0) for (const e of near) {
              if (e.dead || e.hidden || e.ally > 0) continue;
              if (U.dist2(e.x, e.y, p.x, p.y) < (p.type.blast * 0.5 + e.r) ** 2) { this.explode(p, p.type.blast, 1); dead = true; break; }
            }
            break;
          }
          case 'pulse': {
            const h = p.fixed ? p : this.segPos(p); // (a resonance ring stays where its mine was)
            p.x = h.x; p.y = h.y; p.r += (p.grow || p.type.speed) * dt;
            for (const e of near) {
              if (e.dead || e.hidden || p.hit.has(e) || e.ally > 0) continue;
              const d = U.dist(e.x, e.y, p.x, p.y);
              if (Math.abs(d - p.r) < e.r + 6) {
                p.hit.add(e); this.damage(e, p.dmg, p.resonance ? p.color : '#fff'); e.stun = Math.max(e.stun || 0, 0.5); if (p.resonance && p.elems.length) this.applyElements(p.elems, e, e.x, e.y, p.base);
                if ((p.combo === 'sonar' || p.combo === 'sonicboom') && !e.dead && e.kind !== 'stone') { this.addStatus(e, 'berserk', 4, 0); this.burst(e.x, e.y, 6, '#ff7bff', 2); } // Sonar: it turns on its own kind
                if (p.combo === 'sonicboom') this.explode({ x: e.x, y: e.y, dmg: p.dmg, base: p.base, elems: [], color: p.color }, p.type.blast, p.type.blastMult); // Sonic Boom: and it blows up where it stands
              }
            }
            if (p.owner === 'player') for (const m of this.projectiles) if (m.kind === 'mine' && m.owner === 'player' && !m.exploded && m.arm <= 0 && !p.hit.has(m) && Math.abs(U.dist(m.x, m.y, p.x, p.y) - p.r) < m.size + 10) { p.hit.add(m); this.resonate(m, p); } // RESONANCE: the ring sets off your mines
            break;
          }
          default: {
            if (p.pipe === 'homing' && p.owner === 'player' && !p.returning) { // +homing piping: bend after the nearest enemy in reach, gently
              const K = CFG.PIPE_HOMING; if (!(p.homeT > 0) || !p.homeTo || p.homeTo.dead) { p.homeT = 0.15; let best = null, bd = K.reach * K.reach; for (const e of near) { if (e.dead || e.hidden || e.ally > 0 || e.kind === 'stone' || p.hit.has(e)) continue; const d2 = U.dist2(e.x, e.y, p.x, p.y); if (d2 < bd) { bd = d2; best = e; } } p.homeTo = best; } else p.homeT -= dt;
              if (p.homeTo) { const sp = Math.hypot(p.vx, p.vy), a = U.angLerp(Math.atan2(p.vy, p.vx), Math.atan2(p.homeTo.y - p.y, p.homeTo.x - p.x), K.turn * dt); p.vx = Math.cos(a) * sp; p.vy = Math.sin(a) * sp; p.angle = a; }
            }
            if (p.bounces > 0 && p.kind === 'shot') { this.reflectMove(p, dt); break; } // a ricochet modifier or +ricochet piping: reflect off anything solid
            p.x += p.vx * dt; p.y += p.vy * dt;
          }
        }
      } else if (p.bounce > 0) { // a juggler's pin: it ricochets a few times before it drops
        const nx = p.x + p.vx * dt, ny = p.y + p.vy * dt; p.spin += 12 * dt;
        if (G.hitSolid({ x: nx, y: p.y, size: p.size, owner: 'none' })) { p.vx = -p.vx; p.bounce--; this.burst(p.x, p.y, 3, p.color, 1.5); } else p.x = nx;
        if (G.hitSolid({ x: p.x, y: ny, size: p.size, owner: 'none' })) { p.vy = -p.vy; p.bounce--; this.burst(p.x, p.y, 3, p.color, 1.5); } else p.y = ny;
      } else { p.x += p.vx * dt; p.y += p.vy * dt; if (p.pie) p.spin += 8 * dt; }
      if (p.life <= 0 && p.kind !== 'boomerang' && p.kind !== 'chain') { dead = true; if (p.kind === 'homing' && p.type.blast && !p.exploded) this.explode(p, p.type.blast, 1); } // a Bomber Moth that catches nothing still goes off
      if ((p.kind === 'boomerang' || p.kind === 'chain') && p.life <= 0) dead = true;
      const solidKinds = ((p.kind === 'shot' || p.kind === 'homing') && !(p.bounces > 0)) || (p.owner === 'enemy' && !(p.bounce > 0)); // (a ricochet shot reflects instead, until its bounces are spent)
      if (!dead && solidKinds && G.hitSolid(p)) {
        dead = true; this.burst(p.x, p.y, 3, p.color, 1.5);
        if (p.kind === 'homing' && p.type.blast && !p.exploded) this.explode(p, p.type.blast, 1); // a Bomber Moth into a tree: it still blows
        const S = World.lastSolid;
        if (p.owner === 'player' && S) { if (p.combo === 'plasma' && S.boulder) World.fryBoulder(S.boulder); if (p.combo === 'gas' || p.combo === 'bomb') this.gasBlast(p, p.x, p.y); if (p.combo === 'napalm' && S.tree) World.meltTree(S.tree); if (S.tree && S.tree.hive) World.shakeHive(S.tree); if (S.mime) { this.floaters.push({ x: p.x, y: p.y - 10, t: 0.6, text: 'mime wall', color: '#fff' }); } }
      }
      if (!dead && p.kind === 'bounce' && p.owner === 'player' && World.lastSolid && World.lastSolid.tree && World.lastSolid.tree.hive && U.chance(0.3)) World.shakeHive(World.lastSolid.tree); // a ricochet off a hive counts too
      // a boomerang that smacks a tree on the way out turns around early
      if (!dead && p.kind === 'boomerang' && !p.returning && G.hitSolid(p)) { p.returning = true; p.hit.clear(); p.life = 4; this.burst(p.x, p.y, 3, p.color, 1.5); }
      if (!dead && p.kind === 'mine' && G.hitSolid({ x: p.x, y: p.y, size: p.size, owner: 'none' })) { p.vx = p.vy = 0; }
      if (!dead && p.kind !== 'pulse') {
        if (p.owner === 'player') {
          for (const e of (p.size <= this.GRID_PAD ? this.cellAt(p.x, p.y) : near)) { // (the grid's pad covers a bullet this size; a bigger one looks at everything)
            if (e.dead || e.hidden || p.hit.has(e) || e.ally > 0) continue;
            const circles = e.hitCircles();
            let hit = false;
            for (const c of circles) {
              const r = c.r + p.size;
              if (U.dist2(c.x, c.y, p.x, p.y) < r * r) { hit = true; break; }
            }
            if (hit) {
              p.hit.add(e);
              if (p.pipe === 'split' && !p.shard && (p.splitN || 0) < 6) { p.splitN = (p.splitN || 0) + 2; const a0 = Math.atan2(p.vy, p.vx) || p.angle || 0; for (const k of [-0.6, 0.6]) this.spawnShard(p, a0 + k, 0.34); } // +split piped from a Splitter ahead: two shards per hit, six per bullet
              if (p.kind === 'mine' || (p.kind === 'homing' && p.type.blast)) { this.explode(p, p.type.blast, 1); dead = true; break; } // a mine, or a Bomber Moth landing on its target
              SFX.at(e.x, () => this.applyHit(p, e)); // the hit, its elements and a kill all sound from where it happened
              if (p.type.shards && !p.split) { p.split = true; for (let k = 0; k < p.type.shards; k++) this.spawnShard(p, p.angle + (k - (p.type.shards - 1) / 2) * 0.5); dead = true; break; }
              if (p.kind === 'chain' && !p.returning) { // hop to the nearest enemy not hit yet, within chainR; out of hops or targets: home
                p.hops = (p.hops === undefined ? p.type.hops : p.hops) - 1; p.travel = 0;
                let best = null, bd = p.type.chainR * p.type.chainR; if (p.hops > 0) for (const o of near) { if (o.dead || o.hidden || o.ally > 0 || o.kind === 'stone' || p.hit.has(o)) continue; const d2 = U.dist2(o.x, o.y, p.x, p.y); if (d2 < bd) { bd = d2; best = o; } }
                if (best) { p.target = best; p.life = p.type.chainR / p.spd + 0.4; this.arcs.push({ x1: p.x, y1: p.y, x2: best.x, y2: best.y, t: 0.08, seed: Math.random() }); } /* every hop gets fresh time to arrive */ else { p.target = null; p.returning = true; p.hit.clear(); p.life = 4; }
                break;
              }
              if (p.kind === 'orbit') continue; // an orbiter sweeps on: it hits again every 0.35 s (hitT), and never dies on a hit
              if (p.pierce-- <= 0) { dead = true; break; }
            }
          }
        } else if (p.hostile) { // fired by a berserk enemy: hurts other enemies
          for (const e of near) { if (e.dead || e.hidden || e === p.from || (p.from && p.from.ally > 0 && e.ally > 0)) continue; if (U.dist2(e.x, e.y, p.x, p.y) < (e.r + p.size) ** 2) { this.damage(e, 6 * p.power, p.color); dead = true; break; } } // a charmed monster's shots spare its fellow allies
        } else if (orbs.length && this.orbBlock(p, orbs)) { dead = true; // an orbiter's ball caught it
        } else if (P.alive && P.invuln <= 0 && !(P.tiny > 0)) { // (Drink Me: enemy shots sail right over a tiny centipede)
          // enemy shots hit your minions too (they are a shield that wears down)
          if (G.mode === 'world') for (const m of near) { if (!m.minion || m.dead) continue; if (U.dist2(m.x, m.y, p.x, p.y) < (m.r + p.size) ** 2) { Minions.hurt(m, 5 * p.power); this.burst(p.x, p.y, 4, p.color, 1.5); dead = true; break; } }
          if (dead) { this.remove(i); continue; }
          const rh = CFG.HEAD_R + p.size;
          let touched = U.dist2(P.x, P.y, p.x, p.y) < rh * rh ? -1 : -2;
          if (touched === -2) { const rs = CFG.SEG_R + p.size; for (let j = 0; j < P.segPos.length; j++) { const s = P.segPos[j]; if (U.dist2(s.x, s.y, p.x, p.y) < rs * rs) { touched = j; break; } } }
          if (touched !== -2) {
            dead = true;
            if (p.pie) { G.splats.push({ x: U.rand(0.15, 0.85), y: U.rand(0.15, 0.8), t: 2.6, seed: Math.random() * 10 }); SFX.play('splat'); this.burst(p.x, p.y, 8, '#fff5e0', 3); if (U.chance(0.5)) G.msg('SPLAT. A cream pie, right in the lens.', '#fff5e0'); } // harmless: it lands on the screen
            else if (p.push) { const sp = Math.hypot(p.vx, p.vy) || 1; P.x += p.vx / sp * 34; P.y += p.vy / sp * 34; P.initTrail(P.x, P.y, P.angle); P.computeSegPos(); this.burst(p.x, p.y, 5, '#9fe3ff', 2); } // water: a shove, nothing more
            else if (touched === -1) P.hit(p.power, 'spit', p.x, p.y);
            else { P.removeAt(touched, 'spit'); P.invuln = 0.5; }
          }
        }
      }
      if (dead) this.remove(i);
    }
    // clouds
    for (let i = this.clouds.length - 1; i >= 0; i--) {
      const c = this.clouds[i];
      c.t -= dt;
      if (c.t <= 0) { this.clouds.splice(i, 1); continue; }
      c.tick = (c.tick || 0) - dt; const zap = c.kind === 'electric' && c.tick <= 0; // storm trails zap everything inside every 0.4s
      if (c.kind === 'laugh') { // laughing gas: monsters inside giggle and cannot attack; you inside get your controls mirrored
        if (P.alive && G.mode === 'world' && !P.flight && U.dist2(P.x, P.y, c.x, c.y) < (c.r + CFG.HEAD_R * 0.5) ** 2) { if (!(P.mirror > 0)) { G.msg('Laughing gas! Your legs have the giggles: the controls are backwards.', '#f3c6ff'); SFX.play('laugh'); } P.mirror = CFG.LAUGH.time; }
        for (const e of near) { if (e.dead || e.hidden || e.kind === 'boss' || e.kind === 'stone' || e.kind === 'rock') continue; if (U.dist2(e.x, e.y, c.x, c.y) < c.r * c.r) { this.addStatus(e, 'blind', 0.5, 0); if (U.chance(dt * 3)) { this.floaters.push({ x: e.x, y: e.y - 20, t: 0.6, text: 'ha ha', color: '#f3c6ff' }); } } }
        continue;
      }
      if (c.enemy) { // a boss's venom cloud: it costs you a segment if you sit in it (the usual invulnerability gates it), and it stings divers
        if (P.alive && P.invuln <= 0 && (G.mode === 'world' || G.mode === 'deck')) {
          if (U.dist2(P.x, P.y, c.x, c.y) < (c.r + CFG.HEAD_R * 0.5) ** 2) P.hit(1, 'a venom cloud', c.x, c.y);
          else for (let j = 0; j < P.segPos.length; j++) { const s = P.segPos[j]; if (U.dist2(s.x, s.y, c.x, c.y) < (c.r + CFG.SEG_R * 0.5) ** 2) { P.removeAt(j, 'venom'); P.invuln = 0.8; G.msg('The venom ate a segment!', '#9bf06a'); break; } }
        }
        for (const m of near) if (m.minion && !m.dead && U.dist2(m.x, m.y, c.x, c.y) < c.r * c.r) Minions.hurt(m, 8 * dt);
        continue;
      }
      for (const e of this.around(c.x, c.y, c.r)) { // (an enemy's centre inside the cloud: the grid's cells round it hold every candidate)
        if (e.dead || e.ally > 0 || e.hidden) continue;
        if (U.dist2(e.x, e.y, c.x, c.y) < c.r * c.r) {
          if (c.kind === 'steam') { this.addStatus(e, 'blind', 0.6, 0); continue; }
          if (c.kind === 'giggle') { if (!e.st.giddy || U.chance(dt * 1.5)) this.giggle(e, c.mag / 0.4); continue; } // laughing gas lingers: whatever wanders in goes giddy too
          if (c.kind === 'dust') { this.damage(e, c.mag * dt, '#e0c890'); this.dry(e, 1.2); continue; } // the Dustwalker's trail: it dries out whatever walks through it
          if (c.kind === 'fire') { this.damage(e, c.mag * dt * this.resist(e, 'fire'), '#ff9a3d'); this.addStatus(e, 'burn', 1.2, c.mag * 0.4, true); continue; }
          if (c.kind === 'ice') { if (!e.st.freeze && U.chance(0.5)) SFX.play('freeze'); this.addStatus(e, 'freeze', 0.45, 0); this.addStatus(e, 'slow', 1.0, 0); this.damage(e, c.mag * dt * this.resist(e, 'ice'), '#cfeeff'); continue; }
          if (c.kind === 'electric') { if (zap) { this.damage(e, c.mag * 0.4 * this.resist(e, 'electric'), '#7df9ff'); e.stun = Math.max(e.stun || 0, 0.25); this.arcs.push({ x1: c.x, y1: c.y, x2: e.x, y2: e.y, t: 0.12, seed: Math.random() }); } continue; }
          this.addStatus(e, 'poison', 2, c.mag, false);
          if (c.kind === 'charged' && U.chance(dt * 3)) { this.addStatus(e, 'spark', 1.5, c.mag * 0.6, false); this.arcs.push({ x1: c.x, y1: c.y, x2: e.x, y2: e.y, t: 0.12, seed: Math.random() }); }
        }
      }
      if (zap) c.tick = 0.4;
    }
    this.updateFx(dt);
  },
  // particles, arcs and floaters age and fade. Runs on every screen, including LEVEL COMPLETE and game over: some drawing
  // code puffs smoke and embers while it draws (exhausts, burning bone, geysers), so without this the pile grows without end
  updateFx(dt) {
    for (let i = this.particles.length - 1; i >= 0; i--) {
      const q = this.particles[i];
      q.t -= dt;
      if (q.t <= 0) { this.particles[i] = this.particles[this.particles.length - 1]; this.particles.pop(); continue; }
      q.x += q.vx * dt; q.y += q.vy * dt; q.vx *= 0.96; q.vy *= 0.96;
    }
    if (this.particles.length > this.MAX_PARTICLES) this.particles.splice(0, this.particles.length - this.MAX_PARTICLES); // a hard ceiling, whatever happens
    for (let i = this.arcs.length - 1; i >= 0; i--) { this.arcs[i].t -= dt; if (this.arcs[i].t <= 0) this.arcs.splice(i, 1); }
    for (let i = this.floaters.length - 1; i >= 0; i--) { const f = this.floaters[i]; f.t -= dt; f.y -= 30 * dt; if (f.t <= 0) this.floaters.splice(i, 1); }
  },
  MAX_PARTICLES: 1500,
};
