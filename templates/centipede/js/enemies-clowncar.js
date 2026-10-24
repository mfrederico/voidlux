// The Clown Car: burst runs, out of gas, kamikaze clowns, the horn and the flower.
// Extends Enemies (see enemies.js). Loads after it.
Object.assign(Enemies, {
  // the Clown Car: drives rings round you, then BURST-RUNS (a rev and a headlight flash, then a straight charge), then sputters
  // OUT OF GAS and refuels on the spot, wide open (it takes gasMult damage); the doors fly open and kamikaze clowns run out;
  // the horn shoves you and your bullets back; the flower sprays a fan of water that pushes; laughing gas trails its runs.
  // At half hp the doors fall off and the clowns never stop coming.
  updateClownCar(e, dt, mood) {
    const P = Player, B = BOSSES[e.type], R = World.regions[e.region];
    const frozen = !!e.st.freeze, enraged = e.hp < e.maxhp * 0.3, desperate = mood === 4, rate = desperate ? 0.55 : enraged ? 0.75 : 1, frac = e.hp / e.maxhp;
    const dx = P.x - e.x, dy = P.y - e.y, d = Math.hypot(dx, dy) || 1, toP = Math.atan2(dy, dx);
    if (!e.car) e.car = { state: 'drive', t: 0, runT: B.runCd * 0.6, clownT: B.clownCd * 0.5, hornT: B.hornCd * 0.7, flowerT: B.flowerCd * 0.8, doorsOff: false, wheel: 0, exhaustT: 0, honkT: 2, pump: 0 };
    const C = e.car;
    e.gasOpen = C.state === 'gas';
    if (!C.doorsOff && frac < 0.5) { C.doorsOff = true; Combat.burst(e.x, e.y, 24, '#ff5c8a', 3); SFX.play('wall'); SFX.play('honk'); G.msg(`${B.name}'s doors fall off! The clowns pour out nonstop now.`, '#ff5c8a'); G.shake = Math.max(G.shake, 0.4); }
    C.t -= dt;
    let mx = 0, my = 0, sp = 0;
    if (C.state === 'drive') {
      e.strafeT -= dt; if (e.strafeT <= 0) { e.strafeT = U.rand(2, 4); e.strafe = -e.strafe; }
      const want = enraged ? B.keep * 0.8 : B.keep, radial = d > want + 60 ? 1 : d < want - 60 ? -1 : 0;
      mx = -Math.sin(toP) * e.strafe * 0.8 + Math.cos(toP) * radial * 0.7; my = Math.cos(toP) * e.strafe * 0.8 + Math.sin(toP) * radial * 0.7;
      sp = e.speed * (enraged ? 1.3 : 1) * (e.stun > 0 ? 0.3 : 1) * (e.st.slow ? 0.5 : 1);
      if (mx || my) e.angle = U.angLerp(e.angle, Math.atan2(my, mx), 4 * dt); // a car faces where it drives
      C.runT -= dt;
      if (!frozen && C.runT <= 0 && P.alive && d < 900) { C.state = 'warn'; C.t = B.runWarn; SFX.play('vroom'); G.msg(`${B.name} REVS ITS ENGINE: it is about to run you down. Step aside!`, '#ffd166'); }
    } else if (C.state === 'warn') {
      e.angle = U.angLerp(e.angle, toP, 6 * dt);
      if (U.chance(dt * 30)) Combat.particles.push(Combat.mk(e.x - Math.cos(e.angle) * e.r, e.y - Math.sin(e.angle) * e.r, U.rand(-40, 40), U.rand(-60, -20), 0.5, '#aaa', 4)); // exhaust puffs
      if (C.t <= 0) { C.state = 'run'; C.t = B.runT * (0.65 + 0.35 * frac); const q = P.predict(d / B.runSpeed); C.dir = Math.atan2(q.y - e.y, q.x - e.x); e.angle = C.dir; SFX.play('honk'); SFX.play('vroom'); G.banner = { text: 'VROOOM', sub: '', t: 0.8 }; G.shake = Math.max(G.shake, 0.3); }
    } else if (C.state === 'run') {
      sp = B.runSpeed * (desperate ? 1.2 : 1); mx = Math.cos(C.dir); my = Math.sin(C.dir); e.angle = C.dir;
      C.wheel += dt * 30;
      C.exhaustT -= dt; if (C.exhaustT <= 0) { C.exhaustT = 0.16; Combat.clouds.push({ x: e.x - mx * e.r * 1.2, y: e.y - my * e.r * 1.2, r: 46, t: 2.4, mag: 0, kind: 'laugh', enemy: true, seed: Math.random() * 10 }); } // laughing gas behind it
      if (e.x < R.x0 + 120 || e.x > R.x1 - 120) { C.dir = Math.PI - C.dir; mx = Math.cos(C.dir); SFX.play('wall'); G.shake = Math.max(G.shake, 0.3); }
      if (e.y < 90 || e.y > CFG.WORLD_H - 90) { C.dir = -C.dir; my = Math.sin(C.dir); SFX.play('wall'); G.shake = Math.max(G.shake, 0.3); }
      if (G.nightmare && P.alive) { // NIGHTMARE: it rams straight THROUGH you: every segment it crosses comes off (one a beat), invulnerable or not
        C.ramT = (C.ramT || 0) - dt;
        if (C.ramT <= 0) for (let j = 0; j < P.segPos.length; j++) { const s = P.segPos[j]; if (U.dist2(e.x, e.y, s.x, s.y) < (e.r + CFG.SEG_R) ** 2) { P.removeAt(j, 'car'); C.ramT = 0.09; SFX.play('honk'); break; } }
      }
      if (P.alive && P.invuln <= 0) { // it runs you down: the head loses runDmg, a segment it clips is gone
        if (U.dist2(e.x, e.y, P.x, P.y) < (e.r + CFG.HEAD_R + 6) ** 2) { P.hit(B.runDmg, 'the clown car', e.x, e.y); SFX.play('honk'); }
        else for (let j = 0; j < P.segPos.length; j++) { const s = P.segPos[j]; if (U.dist2(e.x, e.y, s.x, s.y) < (e.r + CFG.SEG_R) ** 2) { P.removeAt(j, 'car'); P.invuln = 0.6; G.msg('The clown car ran over a segment!', '#ff9b8b'); break; } }
      }
      if (C.t <= 0 && G.nightmare) { C.state = 'drive'; C.runT = B.runCd * rate * 0.6; } // NIGHTMARE: it never runs out of gas
      else if (C.t <= 0) { C.state = 'gas'; C.t = B.gasT * (1 + 0.5 * (1 - frac)) * (desperate ? 0.7 : 1); C.pump = 0; SFX.play('sputter'); G.msg(`${B.name} sputters and dies: OUT OF GAS. It takes ${Math.round((B.gasMult - 1) * 100)}% extra while it refuels.`, '#ffd166'); for (let k = 0; k < 10; k++) Combat.particles.push(Combat.mk(e.x - mx * e.r, e.y - my * e.r, U.rand(-60, 60), U.rand(-80, -20), 0.8, '#555', 5)); }
    } else if (C.state === 'gas') {
      C.pump += dt * 6;
      if (U.chance(dt * 6)) Combat.particles.push(Combat.mk(e.x + U.rand(-10, 10), e.y - e.r, U.rand(-10, 10), U.rand(-50, -20), 0.7, '#aaa', 4));
      if (C.t <= 0) { C.state = 'drive'; C.runT = B.runCd * rate; SFX.play('vroom'); G.msg(`${B.name} is fuelled up and rolling again.`, '#ff5c8a'); }
    }
    if (!frozen && sp) { e.x += mx * sp * dt; e.y += my * sp * dt; }
    e.x = U.clamp(e.x, R.x0 + 110, R.x1 - 110); e.y = U.clamp(e.y, 80, CFG.WORLD_H - 80);
    if (C.state === 'drive' && sp) C.wheel += sp * dt / 12;
    // the clown payload
    C.clownT -= dt;
    if (!frozen && (C.state !== 'run' || G.nightmare) && C.clownT <= 0 && P.alive) { // (nightmare: they tumble out mid-charge too, twice as often)
      C.clownT = B.clownCd * (C.doorsOff ? 0.5 : 1) * rate * (G.nightmare ? 0.5 : 1);
      const alive = this.list.filter(o => o.kind === 'bug' && o.type === 'kamiclown' && !o.dead).length, n = B.clownN + (C.doorsOff ? 2 : 0);
      if (alive < (G.nightmare ? 14 : 9)) {
        for (let k = 0; k < n; k++) { const a = e.angle + Math.PI / 2 * (k % 2 ? 1 : -1) + U.rand(-0.4, 0.4); const cl = this.spawnBug('kamiclown', e.x + Math.cos(a) * (e.r + 14), e.y + Math.sin(a) * (e.r + 14), e.region, false); cl.hidden = false; cl.emerge = 0; cl.hp = cl.maxhp = 8 + 3 * e.level; cl.angle = a; cl.born = 0.35; }
        C.doorT = 0.6; SFX.play('honk'); G.msg(`${n} clowns pile out of the car with mallets!`, '#ffd166');
      }
    }
    // the horn: a shockwave that shoves you and turns your bullets around
    C.hornT -= dt;
    if (!frozen && C.state === 'drive' && C.hornT <= 0 && P.alive && d < B.hornR + 80) {
      C.hornT = B.hornCd * rate; SFX.play('honk'); SFX.play('horn'); G.ring = { x: e.x, y: e.y, r: 0, max: B.hornR, t: 0.6 }; G.shake = Math.max(G.shake, 0.35);
      if (d < B.hornR + CFG.HEAD_R && !P.flight) { P.x += Math.cos(toP) * B.hornPush; P.y += Math.sin(toP) * B.hornPush; P.x = U.clamp(P.x, R.x0 + 60, R.x1 - 60); P.y = U.clamp(P.y, CFG.HEDGE + 30, CFG.WORLD_H - CFG.HEDGE - 30); P.angle = toP; P.initTrail(P.x, P.y, P.angle); P.computeSegPos(); P.invuln = Math.max(P.invuln, 0.5); }
      let turned = 0; for (const p of Combat.projectiles) { if (p.owner !== 'player' || p.kind === 'orbit' || p.kind === 'pulse' || p.kind === 'mine') continue; if (U.dist2(p.x, p.y, e.x, e.y) < B.hornR * B.hornR) { p.vx = -p.vx; p.vy = -p.vy; p.angle += Math.PI; p.hit.clear(); turned++; } }
      G.msg(`HOOOONK! The horn shoves you back${turned ? ` and turns ${turned} of your bullets around` : ''}.`, '#ffd166');
    }
    // the squirting flower: a fan of water that pushes
    C.flowerT -= dt;
    if (!frozen && C.state === 'drive' && C.flowerT <= 0 && P.alive && d < 520) {
      C.flowerT = B.flowerCd * rate; SFX.play('squirt'); e.clawT = 0.5;
      for (let k = 0; k < B.flowerN; k++) Combat.fireEnemy(e.x + Math.cos(toP) * e.r, e.y + Math.sin(toP) * e.r, toP + (k - (B.flowerN - 1) / 2) * 0.14, { speed: 400, power: 0, size: 5, color: '#9fe3ff', life: 1.3, push: true });
      G.msg(`${B.name} squirts its flower at you!`, '#9fe3ff');
    }
    if (C.doorT > 0) C.doorT -= dt;
    if (e.clawT > 0) e.clawT -= dt;
    C.honkT -= dt; if (C.honkT <= 0 && Math.abs(P.x - e.x) < 1300) { C.honkT = enraged ? U.rand(1.5, 3) : U.rand(4, 7); SFX.play('honk'); }
    if (C.state !== 'run' && P.alive && P.invuln <= 0 && U.dist2(e.x, e.y, P.x, P.y) < (e.r + CFG.HEAD_R) ** 2) { P.hit(e.power, `the ${B.name}`, e.x, e.y); e.stun = 0.5; }
  },
});
