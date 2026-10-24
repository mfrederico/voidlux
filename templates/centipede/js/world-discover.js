// Things to discover: beehives, coffins, geysers, tractors, crop circles, jack-in-the-boxes and the fortune teller.
// Extends World (see world.js). Loads after it.
Object.assign(World, {
  // ---- things to discover: beehives, coffins, geysers, tractors, crop circles, jack-in-the-boxes, the fortune teller ----
  // a bullet hit a beehive tree: angry bees pour out and fight for you (Enemies.charm on fresh gnats)
  shakeHive(t) {
    const K = CFG.HIVE; if (!t.hive || !U.chance(K.chance)) return;
    t.hive = false; t.hiveT = 0.6;
    for (let k = 0; k < K.bees; k++) { const b = Enemies.spawnBug('gnat', t.x + U.rand(-20, 20), t.y - t.r + U.rand(-10, 10), t.region, false); b.hidden = false; b.emerge = 0; b.bee = true; b.color = '#ffd166'; b.dark = '#3a2a1a'; b.hp = b.maxhp = 6; b.speed = 200; Enemies.charm(b, K.charm); b.charmed = K.charm; }
    Combat.burst(t.x, t.y - t.r, 14, '#ffd166', 2); SFX.play('parasite');
    G.msg(`You hit a BEEHIVE! ${K.bees} angry bees pour out and go for the nearest monsters (${K.charm}s, then they burst).`, '#ffd166');
  },
  updateDeco(dt) {
    const P = Player, ri = this.regionAt(P.x);
    for (const e of G.enemies) if (e.geyserT > 0) e.geyserT -= dt;
    if (P.geyserT > 0) P.geyserT -= dt;
    for (let k = Math.max(0, ri - 1); k <= Math.min(this.regions.length - 1, ri + 1); k++) for (const d of this.regions[k].deco) {
      if (d.kind === 'geyser') { // erupts on a timer; anything over it goes flying
        const K = CFG.GEYSER; d.t -= dt;
        if (d.on > 0) {
          d.on -= dt;
          for (const e of G.enemies) { if (e.dead || e.hidden || e.kind === 'boss' || e.geyserT > 0) continue; if (U.dist2(e.x, e.y, d.x, d.y) < (K.r + e.r) ** 2) { e.geyserT = 1.2; Combat.damage(e, K.dmg * G.powerMult, '#9fe3ff'); e.stun = Math.max(e.stun || 0, K.stun); const a = Math.atan2(e.y - d.y, e.x - d.x); e.x += Math.cos(a) * 70; e.y += Math.sin(a) * 70; Combat.burst(e.x, e.y, 8, '#9fe3ff', 2); } }
          if (P.alive && !P.flight && U.dist2(P.x, P.y, d.x, d.y) < (K.r * 0.7) ** 2 && !(P.geyserT > 0)) { P.geyserT = 1.5; const a = Math.atan2(P.y - d.y, P.x - d.x) + U.rand(-0.5, 0.5); P.angle = a; P.x += Math.cos(a) * 90; P.y += Math.sin(a) * 90; P.invuln = Math.max(P.invuln, 0.8); Combat.burst(P.x, P.y, 10, '#9fe3ff', 2); G.msg('The geyser tossed you!', '#9fe3ff'); }
          if (U.chance(dt * 30)) Combat.particles.push(Combat.mk(d.x + U.rand(-20, 20), d.y, U.rand(-30, 30), U.rand(-260, -120), 0.9, U.chance(0.6) ? '#dff4ff' : '#9fe3ff', 4));
        } else if (d.t <= 0) { d.t = K.every; d.on = K.on; SFX.play('geyser'); G.shake = Math.max(G.shake, 0.15); }
      }
      else if (d.kind === 'jack') { // a jack-in-the-box springs when you pass
        if (d.sprung) { d.t += dt; continue; }
        if (P.alive && U.dist2(P.x, P.y, d.x, d.y) < 70 * 70) { d.sprung = true; d.t = 0; const cl = Enemies.spawnBug('kamiclown', d.x, d.y - 10, k, false); cl.hidden = false; cl.emerge = 0; SFX.play('honk'); Combat.burst(d.x, d.y - 10, 12, '#ffd166', 2); G.msg('POP goes the jack-in-the-box: a clown with a mallet!', '#ff5c8a'); }
      }
      else if (d.kind === 'coffin') { // creep up to it and it creaks open: a prize, or a flaming skull
        if (d.open) continue;
        if (P.alive && U.dist2(P.x, P.y, d.x, d.y) < 80 * 80) {
          d.open = true; SFX.play('enter');
          if (U.chance(0.5)) { this.pickups.push(this.mkPickup(d.x, d.y - 20, 'weapon', this.pickBullet(3), k)); this.dropDots(d.x, d.y + 10, k, 4); G.msg('The coffin creaks open: treasure!', '#ffd166'); }
          else { const s = Enemies.spawnBug('skull', d.x, d.y - 10, k, false); s.emerge = 0.4; const s2 = Enemies.spawnBug('skull', d.x + 20, d.y, k, false); s2.emerge = 0.7; G.msg('The coffin creaks open: it was not empty.', '#ff8a3d'); SFX.play('roar'); }
        }
      }
      else if (d.kind === 'tractor') { // climb aboard and plow
        if (d.used) continue;
        if (P.alive && U.dist2(P.x, P.y, d.x, d.y) < 44 * 44) { d.used = true; P.giant = CFG.GIANT.time; P.tractor = true; SFX.play('vroom'); G.shake = Math.max(G.shake, 0.3); G.banner = { text: 'TRACTOR', sub: `${CFG.GIANT.time}s of plowing through everything: nothing can hurt you`, t: 1.6 }; G.msg('You climbed onto the TRACTOR: plow straight through them.', '#ffd166'); }
      }
      else if (d.kind === 'circle' && d.gift) { // circle a crop circle (the same lap as a grave or a tree) and a bullet beams down into it
        const R = d.big ? 260 : 130 + (d.seed % 3) * 25, d2 = U.dist2(d.x, d.y, P.x, P.y);
        if (!P.alive || d2 > (R + 60) ** 2) { if (d.spin) { d.spinT = (d.spinT || 0) + dt; if (d.spinT > 1.5) { d.spin = 0; d.lastAng = null; } } }
        else {
          const ang = Math.atan2(P.y - d.y, P.x - d.x);
          if (d.lastAng != null) { let dd = ang - d.lastAng; while (dd > Math.PI) dd -= TAU; while (dd < -Math.PI) dd += TAU; if (d.spin && Math.sign(dd) !== Math.sign(d.spin)) d.spin *= 0.985; d.spin = (d.spin || 0) + dd; }
          d.lastAng = ang; d.spinT = 0;
          const laps = Math.abs(d.spin) / TAU, need = Perks.summonLaps();
          if (laps > 0.05 && U.chance(dt * (6 + 30 * laps / need))) Combat.particles.push(Combat.mk(d.x + U.rand(-30, 30), d.y + U.rand(-10, 10), 0, -80 - 60 * laps / need, 0.8, '#9bf06a', 3)); // the beam gathers as you go round
          if (laps >= 0.4 * need && !d.cheered) { d.cheered = true; G.msg('The crop circle hums... keep circling and something beams down!', '#9bf06a'); }
          if (laps >= need) { d.gift = false; this.pickups.push(this.mkPickup(d.x, d.y, 'weapon', this.pickBullet(2), k)); Combat.burst(d.x, d.y, 20, '#9bf06a', 3); SFX.play('abduct'); SFX.play('moo'); G.msg('Something beams DOWN into the crop circle: a bullet. Grab it.', '#9bf06a'); }
        }
      }
      else if (d.kind === 'fortune') { if (!d.used && P.alive && U.dist2(P.x, P.y, d.x, d.y) < 50 * 50) this.tellFortune(d, k); }
    }
  },
  // the fortune teller: one card, one of four oddities
  tellFortune(d, ri) {
    d.used = true; SFX.play('power'); G.flash = 0.3; G.tint = { color: '243,198,255', t: 1.5 };
    const near = G.enemies.filter(e => !e.dead && !e.hidden && !(e.ally > 0) && e.kind !== 'boss' && e.kind !== 'stone' && e.kind !== 'rock' && U.dist(e.x, e.y, d.x, d.y) < CFG.FORTUNE.range);
    const card = U.pick(['chickens', 'rubber', 'rain', 'mega']);
    if (card === 'chickens') { let n = 0; for (const e of near) { if (e.kind !== 'bug' && e.kind !== 'snake') continue; const c = Enemies.spawnBug('chicken', e.x, e.y, e.region, false); c.hidden = false; c.emerge = 0; e.dead = true; Combat.burst(e.x, e.y, 10, '#fff5e0', 2); n++; } G.banner = { text: 'THE CARD: CHICKENS', sub: n ? `${n} monsters are now chickens. Chase them, they drop loot` : 'nobody near enough to transform', t: 2.4 }; SFX.play('cluck'); }
    else if (card === 'rubber') { G.rubber = 12; G.banner = { text: 'THE CARD: RUBBER CHICKENS', sub: 'your bullets squeak and hit for half again, 12s', t: 2.4 }; SFX.play('squeak'); }
    else if (card === 'rain') { this.dropDots(d.x, d.y + 40, ri, 14); for (let k = 0; k < 2; k++) this.pickups.push(this.mkPickup(d.x + U.rand(-60, 60), d.y + U.rand(20, 70), 'weapon', this.pickBullet(2), ri)); G.banner = { text: 'THE CARD: RAIN', sub: 'dots and bullets fall out of the tent', t: 2.4 }; SFX.play('pickup'); }
    else { G.mega.charge = CFG.MEGA_MAX; G.banner = { text: 'THE CARD: THE STAR', sub: 'your mega is charged. Press E', t: 2.4 }; SFX.play('megaReady'); }
    G.msg('The fortune teller flips a card...', '#f3c6ff');
  },
});
