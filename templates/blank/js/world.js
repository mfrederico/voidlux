// The arena: the player ship, enemies, bullets, pickups and particles, and the rules that move them. Pure simulation: no
// drawing here (render.js) and no screens (main.js). World.reset() starts a run; World.update(dt) steps it.
const World = {
  player: null, enemies: [], bullets: [], pickups: [], parts: null, wave: 0, waveT: 0, alive: true, t: 0,
  reset() {
    this.player = { x: CFG.W / 2, y: CFG.H / 2, r: CFG.PLAYER.r, hp: CFG.PLAYER.hp, inv: 0, cd: 0, aim: -Math.PI / 2, vx: 0, vy: 0 };
    this.enemies = []; this.bullets = []; this.pickups = []; this.wave = 0; this.waveT = 0; this.alive = true; this.t = 0;
    this.parts = this.parts || new Pool(() => ({}), p => { p.life = 0; });
    this.parts.clear();
    this.nextWave();
  },
  nextWave() {
    this.wave++; this.waveT = CFG.WAVE_SECS;
    const n = CFG.ENEMIES.start + CFG.ENEMIES.perWave * (this.wave - 1), kinds = Object.keys(KINDS);
    for (let i = 0; i < n; i++) {
      const kind = kinds[Math.min(kinds.length - 1, Math.floor(Rng.next() * Math.min(kinds.length, 1 + this.wave / 2)))], K = KINDS[kind];
      const side = Rng.randi(0, 3), m = 40; // spawn just outside an edge
      const x = side === 0 ? -m : side === 1 ? Game.W + m : Rng.rand(0, Game.W), y = side === 2 ? -m : side === 3 ? CFG.H + m : Rng.rand(0, CFG.H);
      this.enemies.push({ kind, x, y, r: CFG.ENEMIES.r * (K.hp > 1 ? 1.3 : 1), hp: K.hp, speed: Rng.rand(CFG.ENEMIES.speedMin, CFG.ENEMIES.speedMax) * K.speed, a: Rng.rand(0, TAU), flash: 0, orbit: Rng.rand(0, TAU) });
    }
    if (this.wave > 1) { Game.score += CFG.SCORE.wave; Game.msg('Wave ' + this.wave, '#ffcc00'); SFX.play('wave'); }
  },
  update(dt) {
    this.t += dt; const P = this.player, ax = Input.axis();
    // the ship: velocity follows the stick, the nose follows the mouse (or the way it moves), fire on Space / click / the FIRE button
    P.vx += (ax.x * CFG.PLAYER.speed - P.vx) * U.damp(12, dt); P.vy += (ax.y * CFG.PLAYER.speed - P.vy) * U.damp(12, dt);
    P.x = U.clamp(P.x + P.vx * dt, P.r, Game.W - P.r); P.y = U.clamp(P.y + P.vy * dt, P.r, CFG.H - P.r);
    if (Input.mouse.inside && !Input.touch) P.aim = Math.atan2(Input.mouse.y - P.y, Input.mouse.x - P.x);
    else if (Math.hypot(P.vx, P.vy) > 30) P.aim = U.angLerp(P.aim, Math.atan2(P.vy, P.vx), 8 * dt);
    if (Input.touch && this.enemies.length) { const e = this.nearest(P.x, P.y); if (e) P.aim = U.angLerp(P.aim, Math.atan2(e.y - P.y, e.x - P.x), 10 * dt); } // on a phone the ship aims itself
    P.cd -= dt; P.inv -= dt;
    if ((Input.down('Space') || Input.mouse.down) && P.cd <= 0) { P.cd = CFG.PLAYER.fireCd; this.bullets.push({ x: P.x + Math.cos(P.aim) * P.r, y: P.y + Math.sin(P.aim) * P.r, vx: Math.cos(P.aim) * CFG.PLAYER.bulletSpeed, vy: Math.sin(P.aim) * CFG.PLAYER.bulletSpeed, r: CFG.PLAYER.bulletR, life: 1.2 }); SFX.play('shoot'); }
    // bullets fly, hit enemies
    for (const b of this.bullets) { b.x += b.vx * dt; b.y += b.vy * dt; b.life -= dt; }
    for (const e of this.enemies) {
      const K = KINDS[e.kind], dx = P.x - e.x, dy = P.y - e.y, d = Math.hypot(dx, dy) || 1;
      if (K.move === 'chase') e.a = U.angLerp(e.a, Math.atan2(dy, dx), 3 * dt);
      else if (K.move === 'drift') { e.a += Math.sin(this.t * 1.7 + e.orbit) * 2 * dt; if (d > 420) e.a = U.angLerp(e.a, Math.atan2(dy, dx), 1.5 * dt); }
      else { e.orbit += dt * 1.2; const tx = P.x + Math.cos(e.orbit) * 220, ty = P.y + Math.sin(e.orbit) * 220; e.a = U.angLerp(e.a, Math.atan2(ty - e.y, tx - e.x), 4 * dt); }
      e.x += Math.cos(e.a) * e.speed * dt; e.y += Math.sin(e.a) * e.speed * dt; e.flash -= dt;
      // stay near the arena: a wanderer that leaves comes back
      if (e.x < -80 || e.x > Game.W + 80 || e.y < -80 || e.y > CFG.H + 80) e.a = Math.atan2(dy, dx);
      for (const b of this.bullets) if (b.life > 0 && U.hit(b.x, b.y, b.r, e.x, e.y, e.r)) { b.life = 0; e.hp--; e.flash = 0.08; this.burst(b.x, b.y, K.color, 4); if (e.hp <= 0) { e.dead = true; this.kill(e); } else SFX.at(e.x, () => SFX.play('hit')); }
      if (!e.dead && P.inv <= 0 && U.hit(P.x, P.y, P.r, e.x, e.y, e.r)) this.hurt(e);
    }
    this.enemies = this.enemies.filter(e => !e.dead); this.bullets = this.bullets.filter(b => b.life > 0 && b.x > -20 && b.x < Game.W + 20 && b.y > -20 && b.y < CFG.H + 20);
    for (const k of this.pickups) { k.t -= dt; if (U.hit(P.x, P.y, P.r + 6, k.x, k.y, k.r)) { k.t = 0; Game.score += CFG.SCORE.pickup; if (P.hp < CFG.PLAYER.hp) P.hp++; SFX.play('pickup'); Game.msg('+' + CFG.SCORE.pickup, '#9bf06a'); } }
    this.pickups = this.pickups.filter(k => k.t > 0);
    this.parts.sweep(p => (p.life -= dt) <= 0); for (const p of this.parts.live) { p.x += p.vx * dt; p.y += p.vy * dt; p.vx *= 0.96; p.vy *= 0.96; }
    this.waveT -= dt; if (!this.enemies.length || this.waveT <= 0) this.nextWave();
    Test.assert(this.enemies.length < 400, 'enemy count stays sane');
  },
  nearest(x, y) { let best = null, bd = 1e9; for (const e of this.enemies) { const d = U.dist2(x, y, e.x, e.y); if (d < bd) { bd = d; best = e; } } return best; },
  kill(e) { const K = KINDS[e.kind]; Game.score += Math.round(CFG.SCORE.kill * K.score); this.burst(e.x, e.y, K.color, 14); SFX.at(e.x, () => SFX.play('kill')); Game.shake = Math.max(Game.shake, 3); if (Rng.next() < CFG.ENEMIES.dropChance) this.pickups.push({ x: e.x, y: e.y, r: 10, t: 8 }); },
  hurt(e) { const P = this.player; P.hp--; P.inv = CFG.PLAYER.invuln; Game.shake = CFG.SHAKE.hit; this.burst(P.x, P.y, '#ff5c5c', 10); if (P.hp <= 0) { this.alive = false; Game.shake = CFG.SHAKE.die; SFX.play('die'); } else SFX.play('hurt'); const d = Math.hypot(P.x - e.x, P.y - e.y) || 1; e.x -= (P.x - e.x) / d * 60; e.y -= (P.y - e.y) / d * 60; },
  burst(x, y, color, n) { for (let i = 0; i < n; i++) { const p = this.parts.take(), a = Rng.rand(0, TAU), s = Rng.rand(60, 260); p.x = x; p.y = y; p.vx = Math.cos(a) * s; p.vy = Math.sin(a) * s; p.life = Rng.rand(0.2, 0.6); p.color = color; p.r = Rng.rand(1.5, 4); } },
  // the autopilot for soak tests: run from the nearest enemy, aim at it, fire; grab pickups when safe
  bot(dt) {
    const P = this.player, e = this.nearest(P.x, P.y), B = Input.bot; let x = 0, y = 0;
    if (e) { const d = Math.hypot(e.x - P.x, e.y - P.y) || 1; if (d < 260) { x -= (e.x - P.x) / d; y -= (e.y - P.y) / d; } P.aim = Math.atan2(e.y - P.y, e.x - P.x); Input.keys.Space = true; } else Input.keys.Space = false;
    const k = this.pickups[0]; if (k && (!e || Math.hypot(e.x - P.x, e.y - P.y) > 200)) { const d = Math.hypot(k.x - P.x, k.y - P.y) || 1; x += (k.x - P.x) / d * 0.8; y += (k.y - P.y) / d * 0.8; }
    x += (Game.W / 2 - P.x) / Game.W * 0.6; y += (CFG.H / 2 - P.y) / CFG.H * 0.6; // drift to the middle: corners are death
    B.axis.x = x; B.axis.y = y;
  },
};
