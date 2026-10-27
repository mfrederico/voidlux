// The neighbourhood: the tile grid (from MAP and TILES in config.js), the cat and the rules that move it. Pure simulation:
// no drawing here (render.js) and no screens (main.js). World.reset() starts a run; World.update(dt) steps it. Positions
// are in tile units (a tile is 1 x 1, the cat stands at x + 0.5, y + 0.5 in the middle of tile x, y).
const World = {
  cat: null, N: 0, rows: [], alive: true, t: 0, goal: null, friends: [], belly: 0, cause: '', day: 1, runs: 0, detour: 0, critters: [], combo: 1, comboT: 0, cars: [], laneT: [], paws: 0, phase: 'day', clock: 0, dark: 0, sinceDawn: 99, witches: [], cushions: [],
  reset() {
    this.rows = MAP; this.N = MAP.length; this.alive = true; this.t = 0; this.goal = null; this.runs++; this.detour = 0; this.belly = CFG.BELLY.start; this.cause = ''; this.day = 1; this.spawnFriends(); this.combo = 1; this.comboT = 0; this.cars = []; this.laneT = CAR_LANES.map(() => Rng.rand(1, CFG.CARS.gapMax)); this.paws = CFG.PAWS.max; this.phase = 'day'; this.clock = CFG.DAY.daySecs; this.dark = 0; this.sinceDawn = 99; this.witches = []; this.cushions = []; for (let y = 0; y < this.N; y++) for (let x = 0; x < this.N; x++) if (TILES[this.rows[y][x]].cushion) this.cushions.push({ x: x + 0.5, y: y + 0.5 }); Input.keys.Space = false;
    this.cat = { x: CFG.CAT.startX, y: CFG.CAT.startY, fx: 1, fy: 1, moving: false, hop: 0, stuck: 0, meowing: false, dx: 1, dy: 0, lunge: 0, wind: 0, ld: null, got: false, inv: 0, kx: 0, ky: 0, kt: 0, hideT: 0, hidden: false, spot: 0 };
    this.spawnCritters();
  },
  tile(tx, ty) { return tx < 0 || ty < 0 || tx >= this.N || ty >= this.N ? 'T' : this.rows[ty][tx]; },
  solidAt(x, y) { return TILES[this.tile(Math.floor(x), Math.floor(y))].solid; },
  /* is a cat of radius r standing at x, y inside a wall? tests the four corners of its box */
  blocked(x, y, r = CFG.CAT.r) { return this.solidAt(x - r, y - r) || this.solidAt(x + r, y - r) || this.solidAt(x - r, y + r) || this.solidAt(x + r, y + r); },
  /* screen direction (as from the stick: x right, y down) to a tile-space direction, so up on the stick is up on the screen */
  toWorld(ax, ay) { const A = CFG.ISO.tw / 2, B = CFG.ISO.th / 2, u = ax / A, v = ay / B; return { x: (u + v) / 2, y: (v - u) / 2 }; },
  spawnFriends() {
    this.friends = FRIEND_SPAWNS.filter(f => f.day <= this.day).map(f => ({ kind: f.kind, x: f.x, y: f.y, hx: f.x, hy: f.y, tx: f.x, ty: f.y, wait: Rng.rand(0, CFG.FRIENDS.pauseMax), meter: 0, cd: 0, food: Rng.next() < CFG.FRIENDS.foodChance, fx: 1 }));
  },
  /* a combo link: every pat or catch raises the multiplier and restarts its clock */
  bump() { if (this.combo < CFG.COMBO.max) { this.combo++; SFX.play('combo'); } this.comboT = CFG.COMBO.window; },
  /* critters sit on grass away from the cat; a caught one waits out its respawn and comes back somewhere else */
  spawnCritters() {
    this.critters = [];
    for (const kind of ['bird', 'squirrel']) for (let i = 0, n = kind === 'bird' ? CFG.CRITTERS.birds : CFG.CRITTERS.squirrels; i < n; i++) { const c = { kind, x: 0, y: 0, tx: 0, ty: 0, state: 'gone', t: 0, alt: 0, alert: 0, fx: 1, dx: 0, dy: 0 }; this.place(c); this.critters.push(c); }
  },
  place(c) {
    const C = this.cat; for (let i = 0; i < 60; i++) { const x = Rng.randi(1, this.N - 2), y = Rng.randi(1, this.N - 2); if (this.tile(x, y) !== '.' || Math.hypot(x + 0.5 - C.x, y + 0.5 - C.y) < 5) continue; c.x = c.tx = x + 0.5; c.y = c.ty = y + 0.5; break; }
    c.state = 'idle'; c.t = Rng.rand(CFG.CRITTERS.hopMin, CFG.CRITTERS.hopMax); c.alt = 0; c.alert = 0;
  },
  /* a critter: sits and hops about on the grass; when the cat comes close it notices (a short react time), then runs
     directly away; birds take off and fly over anything, land when the flight is over; a caught one is 'gone' until it respawns */
  stepCritter(c, dt) {
    const K = CRITTER_KINDS[c.kind], C = this.cat, CR = CFG.CRITTERS;
    if (c.state === 'gone') { c.t -= dt; if (c.t <= 0) this.place(c); return; }
    const dist = Math.hypot(C.x - c.x, C.y - c.y);
    if (c.state === 'idle') {
      if (dist < K.alertR) { c.alert += dt; if (c.alert >= CR.react) { const a = Math.atan2(c.y - C.y, c.x - C.x) + Rng.rand(-0.5, 0.5); c.dx = Math.cos(a); c.dy = Math.sin(a); c.state = 'flee'; c.t = K.fleeSecs; c.alert = 0; } }
      else c.alert = Math.max(0, c.alert - dt);
      c.t -= dt;
      if (c.t <= 0 && c.alert <= 0) { c.t = Rng.rand(CR.hopMin, CR.hopMax); const x = c.x + Rng.rand(-1.2, 1.2), y = c.y + Rng.rand(-1.2, 1.2); if (this.tile(Math.floor(x), Math.floor(y)) === '.') { c.tx = x; c.ty = y; } }
      const dx = c.tx - c.x, dy = c.ty - c.y, d = Math.hypot(dx, dy);
      if (d > 0.05) { const s = Math.min(d, CR.hopSpeed * dt); c.x += dx / d * s; c.y += dy / d * s; c.fx = dx - dy > 0 ? 1 : -1; }
      c.alt = Math.max(0, c.alt - CR.liftRate * dt);
    } else {
      c.t -= dt; const s = K.flee * dt; c.fx = c.dx - c.dy > 0 ? 1 : -1;
      if (K.flies) {
        c.alt = Math.min(1, c.alt + CR.liftRate * dt); c.x = U.clamp(c.x + c.dx * s, 0.6, this.N - 0.6); c.y = U.clamp(c.y + c.dy * s, 0.6, this.N - 0.6);
        if (c.t <= 0 && !this.solidAt(c.x, c.y)) { c.state = 'idle'; c.tx = c.x; c.ty = c.y; c.t = CFG.CRITTERS.hopMin; }
      } else {
        const nx = c.x + c.dx * s, ny = c.y + c.dy * s, r = 0.25; let moved = false;
        if (!this.blocked(nx, c.y, r)) { c.x = nx; moved = true; } if (!this.blocked(c.x, ny, r)) { c.y = ny; moved = true; }
        if (!moved) { const a = Math.atan2(c.dy, c.dx) + (Rng.next() < 0.5 ? 1 : -1) * Math.PI / 2; c.dx = Math.cos(a); c.dy = Math.sin(a); }
        if (c.t <= 0) { c.state = 'idle'; c.tx = c.x; c.ty = c.y; c.t = CFG.CRITTERS.hopMin; }
      }
    }
  },
  /* the pounce: aims at the nearest critter within pounceRange (so it works on a touch screen), else the way the cat faces */
  startPounce() {
    const C = this.cat, CC = CFG.CAT; let best = null, bd = CC.pounceRange;
    for (const c of this.critters) { if (c.state === 'gone') continue; const d = Math.hypot(c.x - C.x, c.y - C.y); if (d < bd) { bd = d; best = c; } }
    let dx = C.dx, dy = C.dy; if (best) { dx = best.x - C.x; dy = best.y - C.y; } const l = Math.hypot(dx, dy) || 1;
    C.ld = { x: dx / l, y: dy / l }; C.lunge = CC.lungeTime; C.got = false; C.fx = dx - dy > 0 ? 1 : -1; C.fy = dx + dy > 0 ? 1 : -1; SFX.play('pounce');
  },
  /* one step of a lunge: a fast straight dash; it ends on a wall, on a catch, or when the time is up (a miss: the cat is winded) */
  stepLunge(dt) {
    const C = this.cat, CC = CFG.CAT, step = CC.lungeSpeed * dt, nx = C.x + C.ld.x * step, ny = C.y + C.ld.y * step;
    if (!this.blocked(nx, ny)) { C.x = nx; C.y = ny; } else C.lunge = 0.0001;
    for (const c of this.critters) if (c.state !== 'gone' && c.alt < 0.5 && Math.hypot(c.x - C.x, c.y - C.y) < CC.catchR) { this.catchCritter(c); C.got = true; C.lunge = 0; return; }
    C.lunge -= dt;
    if (C.lunge <= 0 && !C.got) { C.wind = CC.windSecs; SFX.play('miss'); Game.msg('missed!', '#5a3b2e'); }
  },
  catchCritter(c) {
    const K = CRITTER_KINDS[c.kind], pts = K.score * this.combo; Game.score += pts; SFX.play('catch'); Game.msg('+' + pts + ' ' + c.kind + (this.combo > 1 ? ' x' + this.combo : '') + '!', '#c9485b');
    this.bump(); c.state = 'gone'; c.t = CFG.CRITTERS.respawn;
  },
  /* where a car is on the map: its lane runs along x (a row) or y (a column); p is how far along, the lane fixes the other coordinate */
  carPos(c) { const L = CAR_LANES[c.lane]; if (L.axis === 'x') { c.x = c.p; c.y = L.line + 0.5; } else { c.x = L.line + 0.5; c.y = c.p; } },
  /* traffic: cars spawn off the map at each open lane's start after a random gap (shorter as the days go by), drive straight
     through and vanish off the far side; a faster van slows to the car in front. Each car warns (honk, flash) as it is
     warn seconds from the crossing, and knocks a paw off the cat that stands in its way */
  stepCars(dt) {
    const CA = CFG.CARS, C = this.cat, N = this.N, ramp = Math.max(CA.gapFloor, 1 - CA.gapDay * (this.day - 1));
    CAR_LANES.forEach((L, i) => {
      if (L.day > this.day) return; this.laneT[i] -= dt; if (this.laneT[i] > 0) return;
      const start = L.dir > 0 ? -2 : N + 2; this.laneT[i] = Rng.rand(CA.gapMin, CA.gapMax) * ramp;
      if (this.cars.some(c => c.lane === i && Math.abs(c.p - start) < 4)) { this.laneT[i] = 0.5; return; }
      const van = this.day >= CA.vanDay && Rng.next() < CA.vanChance, c = { lane: i, p: start, kind: van ? 'van' : 'car', v: van ? CA.van : CA.car, len: van ? CA.lenVan : CA.lenCar, color: CAR_COLORS[Rng.randi(0, CAR_COLORS.length - 1)], warned: false, warn: 0, x: 0, y: 0, dir: L.dir, axis: L.axis };
      this.carPos(c); this.cars.push(c);
    });
    for (const c of this.cars) {
      let v = c.v; for (const o of this.cars) if (o !== c && o.lane === c.lane && o.v < v && (o.p - c.p) * c.dir > 0 && (o.p - c.p) * c.dir < 2.5) v = o.v;
      c.p += c.dir * v * dt; this.carPos(c);
      const entry = c.dir > 0 ? CA.crossFrom - c.len / 2 : CA.crossTo + c.len / 2, away = (entry - c.p) * c.dir, exit = c.dir > 0 ? CA.crossTo + c.len / 2 : CA.crossFrom - c.len / 2;
      c.warn = away > 0 && away <= CA.warn * v ? 1 - away / (CA.warn * v) : 0; /* 0 = quiet, up to 1 as it arrives */
      if (c.warn > 0 && !c.warned) { c.warned = true; SFX.play('honk'); }
      c.inX = (exit - c.p) * c.dir > 0 && away <= 0; /* inside the crossing now */
      /* the hit: the car's box against the cat's circle, in the lane's own axes */
      const along = c.axis === 'x' ? C.x - c.x : C.y - c.y, across = c.axis === 'x' ? C.y - c.y : C.x - c.x;
      if (C.inv <= 0 && this.alive && Math.abs(along) < c.len / 2 + CFG.CAT.r && Math.abs(across) < CA.width / 2 + CFG.CAT.r) this.hitCat(c, across);
    }
    this.cars = this.cars.filter(c => c.dir > 0 ? c.p < N + 3 : c.p > -3);
  },
  /* a car hit: one paw, a shove out of the lane (the way the cat was already off-centre), a blink of safety; no paws left ends the run */
  hitCat(c, across) {
    const C = this.cat, CA = CFG.CARS; this.paws--; C.inv = CFG.PAWS.invuln; C.lunge = 0; C.kt = CA.knockT; this.combo = 1; this.comboT = 0; Game.shake = CA.shake;
    const side = across >= 0 ? 1 : -1; C.kx = c.axis === 'x' ? 0 : side * CA.knock; C.ky = c.axis === 'x' ? side * CA.knock : 0;
    SFX.play('splat'); Game.msg('bonk!', '#e03030');
    if (this.paws <= 0) { this.alive = false; this.cause = 'car'; SFX.play('die'); Game.shake = CA.shake * 2; }
  },
  /* can a point see another: step along the line and fail on the first wall, house, tree or hedge */
  los(x0, y0, x1, y1) { const d = Math.hypot(x1 - x0, y1 - y0), n = Math.ceil(d / 0.2); for (let i = 1; i < n; i++) if (this.solidAt(x0 + (x1 - x0) * i / n, y0 + (y1 - y0) * i / n)) return false; return true; },
  /* how far a ray from x, y along angle a travels before it hits something solid, up to max (shapes the lantern cone and its shadows) */
  ray(x, y, a, max) { const cx = Math.cos(a), cy = Math.sin(a); let d = 0; while (d < max && !this.solidAt(x + cx * d, y + cy * d)) d += 0.15; return Math.min(d, max); },
  /* the clock of days: sun, dusk, the full-moon night with witches, dawn; surviving a night pays a bonus and starts a harder day */
  stepClock(dt) {
    const D = CFG.DAY; this.clock -= dt; this.sinceDawn += dt;
    if (this.phase === 'day') {
      this.dark = Math.max(this.clock < D.duskSecs ? 1 - this.clock / D.duskSecs : 0, this.sinceDawn < D.dawnSecs ? 1 - this.sinceDawn / D.dawnSecs : 0);
      if (this.clock <= 0) this.startNight();
    } else { this.dark = 1; if (this.clock <= 0) this.endNight(); }
  },
  startNight() {
    this.phase = 'night'; this.clock = CFG.DAY.nightSecs; this.dark = 1; SFX.play('moon'); Game.msg('The full moon rises... hide!', '#7a5cd6');
    const C = this.cat, n = Math.min(WITCH_SPAWNS.length - 1, 1 + Math.floor((this.day - 1) / 2)), spots = WITCH_SPAWNS.slice().sort((a, b) => Math.hypot(b.x - C.x, b.y - C.y) - Math.hypot(a.x - C.x, a.y - C.y));
    this.witches = spots.slice(0, n).map((p, i) => { const W = { x: p.x, y: p.y, a: Rng.rand(0, TAU), state: 'patrol', wx: p.x, wy: p.y, wait: 1 + i, spot: 0, loseT: 0, fx: 1, poly: [] }; for (let k = 0; k <= CFG.WITCH.coneRays + 1; k++) W.poly.push({ x: 0, y: 0 }); return W; });
  },
  endNight() {
    const bonus = CFG.DAY.bonus * this.day; Game.score += bonus; this.witches = []; this.day++; this.phase = 'day'; this.clock = CFG.DAY.daySecs; this.sinceDawn = 0;
    SFX.play('dawn'); Game.msg('Dawn! Night survived +' + bonus, '#c9485b'); Game.msg('Day ' + this.day, '#5a3b2e');
    for (const f of FRIEND_SPAWNS) if (f.day === this.day) this.friends.push({ kind: f.kind, x: f.x, y: f.y, hx: f.x, hy: f.y, tx: f.x, ty: f.y, wait: 0, meter: 0, cd: 0, food: Rng.next() < CFG.FRIENDS.foodChance, fx: 1 });
  },
  /* try to step a witch along angle a; if a wall is there swing round it to either side so she flows past corners */
  moveWitch(W, a, step) {
    for (const t of [0, 0.7, -0.7, 1.4, -1.4]) { const nx = W.x + Math.cos(a + t) * step, ny = W.y + Math.sin(a + t) * step; if (!this.blocked(nx, ny, 0.3)) { W.x = nx; W.y = ny; return true; } }
    return false;
  },
  /* a witch: patrols between waypoints sweeping her lantern; the cat in the cone (with a clear line) fills the spotted ring, and a full ring
     means a chase at a speed just under the cat's; touching an uncovered cat is the end. Hiding on a cushion or behind a wall breaks it */
  stepWitch(W, dt) {
    const C = this.cat, K = CFG.WITCH, dx = C.x - W.x, dy = C.y - W.y, dist = Math.hypot(dx, dy), vis = this.los(W.x, W.y, C.x, C.y), D = this.day - 1;
    const inCone = dist < K.range && Math.abs(U.angDiff(Math.atan2(dy, dx), W.a)) < K.half && vis, seen = inCone && !C.hidden;
    if (W.state === 'patrol') {
      W.spot = U.clamp(W.spot + (seen ? dt / K.spotSecs : -dt / K.spotSecs * 1.5), 0, 1);
      if (W.spot >= 1) { W.state = 'chase'; W.loseT = 0; SFX.play('witch'); }
      else if (W.wait > 0) { W.wait -= dt; W.a += Math.sin(this.t * 2 + W.wx) * dt * 1.2; if (W.wait <= 0) this.pickWaypoint(W); }
      else {
        const wdx = W.wx - W.x, wdy = W.wy - W.y, d = Math.hypot(wdx, wdy);
        if (d < 0.3) W.wait = Rng.rand(1, 2.5); else { W.a = U.angLerp(W.a, Math.atan2(wdy, wdx), K.turn * dt); this.moveWitch(W, Math.atan2(wdy, wdx), K.patrol * (1 + K.patrolDay * D) * dt); }
      }
    } else {
      const visible = vis && dist < K.loseR && !C.hidden; W.loseT = visible ? 0 : W.loseT + dt;
      W.a = U.angLerp(W.a, Math.atan2(dy, dx), K.turn * 2 * dt);
      if (dist > K.grabR * 0.8) this.moveWitch(W, Math.atan2(dy, dx), Math.min(K.chaseMax, K.chase + K.chaseDay * D) * dt);
      if (W.loseT > K.loseSecs) { W.state = 'patrol'; W.spot = 0; this.pickWaypoint(W); }
    }
    W.fx = Math.cos(W.a) - Math.sin(W.a) > 0 ? 1 : -1;
    if (this.alive && dist < K.grabR && !C.hidden && vis) { this.alive = false; this.cause = 'witch'; SFX.play('cackle'); SFX.play('die'); Game.shake = CFG.CARS.shake * 2; }
    /* the lit cone: a fan of rays, each cut short by the first wall, so hedges and houses throw shadows */
    W.poly[0].x = W.x; W.poly[0].y = W.y;
    for (let i = 0; i <= K.coneRays; i++) { const a = W.a - K.half + 2 * K.half * i / K.coneRays, r = this.ray(W.x, W.y, a, K.range); W.poly[i + 1].x = W.x + Math.cos(a) * r; W.poly[i + 1].y = W.y + Math.sin(a) * r; }
  },
  /* the next place a witch walks to: half the time somewhere near the cat, so the night has teeth, else anywhere open */
  pickWaypoint(W) {
    const C = this.cat; for (let i = 0; i < 20; i++) { const near = Rng.next() < 0.5, x = near ? C.x + Rng.rand(-5, 5) : Rng.rand(1, this.N - 1), y = near ? C.y + Rng.rand(-5, 5) : Rng.rand(1, this.N - 1); if (x > 1 && y > 1 && x < this.N - 1 && y < this.N - 1 && !this.blocked(x, y, 0.4)) { W.wx = x; W.wy = y; return; } }
  },
  /* a wanderer: stand a while, pick an open non-road tile near home, stroll there; a person being meowed at stands still and looks at the cat */
  stepFriend(F, dt) {
    const K = FRIEND_KINDS[F.kind], C = this.cat, FR = CFG.FRIENDS;
    if (F.cd > 0) { F.cd -= dt; if (F.cd <= 0) F.food = Rng.next() < FR.foodChance; }
    if (F.meter > 0 && Math.hypot(C.x - F.x, C.y - F.y) < FR.range * 1.5) { F.fx = C.x - F.x > C.y - F.y ? 1 : -1; return; }
    if (!K.wander) return;
    if (F.wait > 0) { F.wait -= dt; if (F.wait > 0) return; for (let i = 0; i < 8; i++) { const x = Math.floor(F.hx + Rng.rand(-FR.wanderR, FR.wanderR)) + 0.5, y = Math.floor(F.hy + Rng.rand(-FR.wanderR, FR.wanderR)) + 0.5, t = this.tile(Math.floor(x), Math.floor(y)); if (!this.blocked(x, y, 0.3) && !TILES[t].road) { F.tx = x; F.ty = y; break; } } }
    const dx = F.tx - F.x, dy = F.ty - F.y, d = Math.hypot(dx, dy);
    if (d < 0.1) { F.wait = Rng.rand(FR.pauseMin, FR.pauseMax); return; }
    const s = Math.min(d, K.speed * dt), nx = F.x + dx / d * s, ny = F.y + dy / d * s; F.fx = dx - dy > 0 ? 1 : -1;
    if (!this.blocked(nx, ny, 0.3)) { F.x = nx; F.y = ny; } else { F.tx = F.x; F.ty = F.y; F.wait = 0.5; }
  },
  /* the nearest friend who can be patted right now and is within reach of the cat, or null */
  reach() { const C = this.cat; let best = null, bd = CFG.FRIENDS.range * CFG.FRIENDS.range; for (const F of this.friends) { if (F.cd > 0) continue; const d = U.dist2(C.x, C.y, F.x, F.y); if (d < bd) { bd = d; best = F; } } return best; },
  /* a full heart meter: score for the pat, belly too if this friend carried food, then a rest before the next pat */
  pat(F) {
    const K = FRIEND_KINDS[F.kind], pts = Math.round(CFG.FRIENDS.pat * K.pat * this.combo); Game.score += pts; SFX.play('purr'); Game.msg('+' + pts + ' pat' + (this.combo > 1 ? ' x' + this.combo : ''), '#c9485b'); this.bump();
    if (F.food) { this.belly = Math.min(CFG.BELLY.max, this.belly + K.belly); SFX.play('pickup'); Game.msg('yum! +' + K.belly + ' belly', '#2f7a3f'); }
    F.cd = CFG.FRIENDS.cool; F.meter = 0;
  },
  update(dt) {
    this.t += dt; const C = this.cat, ax = Input.axis(), mag = Math.min(1, Math.hypot(ax.x, ax.y)), meow = Input.down('Space'), F = this.reach(), FR = CFG.FRIENDS;
    /* Space beside a friend meows at them; Space anywhere else is a pounce, unless the cat is winded or already mid-lunge */
    C.wind = Math.max(0, C.wind - dt); C.inv = Math.max(0, C.inv - dt);
    if (C.kt > 0) { C.kt -= dt; const nx = C.x + C.kx * dt, ny = C.y + C.ky * dt; if (!this.blocked(nx, C.y)) C.x = nx; if (!this.blocked(C.x, ny)) C.y = ny; }
    if (Input.once('Space') && !F && C.wind <= 0 && C.lunge <= 0) this.startPounce();
    C.moving = mag > 0.1 && C.lunge <= 0;
    if (C.lunge > 0) this.stepLunge(dt);
    else if (C.moving) {
      if (Math.abs(ax.x) > 0.25) C.fx = ax.x > 0 ? 1 : -1; if (Math.abs(ax.y) > 0.25) C.fy = ax.y > 0 ? 1 : -1;
      const d = this.toWorld(ax.x, ax.y), l = Math.hypot(d.x, d.y) || 1, step = CFG.CAT.speed * mag * dt * (C.wind > 0 ? CFG.CAT.windSpeed : 1); C.dx = d.x / l; C.dy = d.y / l;
      /* slide along walls: try each axis on its own */
      const nx = C.x + d.x / l * step, ny = C.y + d.y / l * step;
      if (!this.blocked(nx, C.y)) C.x = nx; if (!this.blocked(C.x, ny)) C.y = ny;
      C.hop += dt * CFG.CAT.hopRate;
    } else C.hop = 0;
    /* meowing: hold Space beside a friend to fill their heart meter; let go and it drains away */
    const meowing = meow && !!F && C.lunge <= 0;
    if (meowing && !C.meowing) SFX.play('meow');
    C.meowing = meowing;
    for (const G of this.friends) { this.stepFriend(G, dt); if (G === F && meowing) G.meter = Math.min(1, G.meter + dt / FR.meowSecs); else G.meter = Math.max(0, G.meter - dt / FR.meowSecs * FR.decay); }
    if (F && F.meter >= 1) this.pat(F);
    for (const c of this.critters) this.stepCritter(c, dt);
    this.stepCars(dt);
    /* hiding: a porch cushion hides the cat for hideSecs, then she is noticed again; leaving it lets the timer run down */
    const onCushion = !!TILES[this.tile(Math.floor(C.x), Math.floor(C.y))].cushion;
    C.hideT = onCushion ? C.hideT + dt : Math.max(0, C.hideT - 2 * dt); C.hidden = onCushion && C.hideT < CFG.WITCH.hideSecs && C.lunge <= 0;
    this.stepClock(dt); C.spot = 0; for (const W of this.witches) { this.stepWitch(W, dt); if (W.state === 'chase') C.spot = 1; else C.spot = Math.max(C.spot, W.spot); }
    this.comboT -= dt; if (this.comboT <= 0) this.combo = 1;
    /* hunger: the belly drains all the time and the run ends when it is empty */
    this.belly = Math.max(0, this.belly - CFG.BELLY.drain * (1 + CFG.BELLY.drainDay * (this.day - 1)) * dt);
    if (this.alive && this.belly <= 0) { this.alive = false; this.cause = 'hunger'; SFX.play('die'); }
    Test.assert(this.belly >= 0 && this.belly <= CFG.BELLY.max, 'belly stays inside its tank');
    for (const W of this.witches) Test.assert(W.x > 0 && W.y > 0 && W.x < this.N && W.y < this.N && !this.solidAt(W.x, W.y), 'witches stay on open ground');
    Test.assert(this.paws >= 0 && this.paws <= CFG.PAWS.max, 'paws stay between 0 and the max');
    Test.assert(this.combo >= 1 && this.combo <= CFG.COMBO.max, 'combo stays between 1 and its max');
    for (const c of this.critters) Test.assert(c.state === 'gone' || (c.x > 0 && c.y > 0 && c.x < this.N && c.y < this.N), 'critters stay on the map');
    Test.assert(!this.blocked(C.x, C.y), 'the cat never stands inside a wall');
  },
  /* the cushion the bot hides on: far from the witches, near the cat, and a different one when this one's cover is nearly spent */
  hideSpot() {
    const C = this.cat; let best = null, bs = -1e9;
    for (const q of this.cushions) {
      const here = Math.hypot(q.x - C.x, q.y - C.y) < 0.5; if (here && C.hideT > CFG.WITCH.hideSecs - 2) continue;
      let wd = 99; for (const W of this.witches) wd = Math.min(wd, Math.hypot(q.x - W.x, q.y - W.y));
      const sc = wd - 0.4 * Math.hypot(q.x - C.x, q.y - C.y) + (here ? 2 : 0); if (sc > bs) { bs = sc; best = q; }
    }
    return best;
  },
  /* the bot's eyes: if a car in a lane the cat stands in (or is about to be thrown into) will reach it within a second, the way to
     step sideways out of that lane (a tile-space direction), else null */
  dodge() {
    const C = this.cat, CA = CFG.CARS;
    for (const c of this.cars) {
      const along = c.axis === 'x' ? C.x - c.x : C.y - c.y, across = c.axis === 'x' ? C.y - c.y : C.x - c.x;
      if (Math.abs(across) > CA.width / 2 + 1.0 || along * c.dir < -c.len / 2 - 0.5 || along * c.dir > c.v * 1.1 + 1) continue;
      const side = across >= 0 ? 1 : -1; return c.axis === 'x' ? { x: 0, y: side } : { x: side, y: 0 };
    }
    return null;
  },
  /* the autopilot for soak tests: on every other run it goes on a hunger strike and just strolls (so game over gets played);
     otherwise it walks to the nearest friend who can be patted (preferring one with food when hungry) and holds Space */
  bot(dt) {
    const C = this.cat, B = Input.bot, FR = CFG.FRIENDS; let meow = false, want = null;
    let prey = null;
    if (this.detour <= 0) { let pd = 1e9; for (const c of this.critters) { if (c.state === 'gone') continue; const d = Math.hypot(c.x - C.x, c.y - C.y); if (d < pd) { pd = d; prey = c; } } }
    if (this.runs % 2 === 0 && this.detour <= 0) { let bd = 1e9; for (const F of this.friends) { if (F.cd > 0) continue; const d = Math.hypot(F.x - C.x, F.y - C.y) - (F.food && this.belly < 60 ? 6 : 0); if (d < bd) { bd = d; want = F; } } }
    this.detour -= dt;
    const night = this.phase === 'night' && this.runs % 2 === 0 && this.belly > 30; /* careful runs hide on a porch cushion all night (moving on when its time is nearly up) */
    if (night) { want = null; prey = null; }
    if (want) { this.goal = { x: want.x, y: want.y }; meow = Math.hypot(want.x - C.x, want.y - C.y) < FR.range * 0.8; }
    else if (prey) { this.goal = { x: prey.x, y: prey.y }; if (Math.hypot(prey.x - C.x, prey.y - C.y) < CFG.CAT.pounceRange * 0.8 && C.wind <= 0 && C.lunge <= 0) Input.tapKey('Space'); }
    else if (night) this.goal = this.hideSpot();
    else if (!this.goal || Math.hypot(this.goal.x - C.x, this.goal.y - C.y) < CFG.BOT.arrive || C.stuck > CFG.BOT.stuck) {
      let x, y; do { x = Rng.randi(1, this.N - 2); y = Rng.randi(1, this.N - 2); } while (TILES[this.tile(x, y)].solid);
      this.goal = { x: x + 0.5, y: y + 0.5 }; C.stuck = 0;
    }
    if (C.stuck > CFG.BOT.stuck) { this.detour = 1.5; this.goal = null; C.stuck = 0; }
    Input.keys.Space = meow;
    const dodge = this.runs % 2 === 0 ? this.dodge() : null; /* odd runs are careless: cars and hunger get their turn */
    if (dodge) { Input.keys.Space = false; const A = CFG.ISO.tw / 2, B3 = CFG.ISO.th / 2, sx = (dodge.x - dodge.y) * A, sy = (dodge.x + dodge.y) * B3, l = Math.hypot(sx, sy) || 1; B.axis.x = sx / l; B.axis.y = sy / l; C.lastX = undefined; return; }
    if (night && this.goal && Math.hypot(this.goal.x - C.x, this.goal.y - C.y) < 0.15) { B.axis.x = B.axis.y = 0; C.lastX = undefined; return; }
    if (!this.goal || meow) { B.axis.x = B.axis.y = 0; C.lastX = undefined; return; }
    const dx = this.goal.x - C.x, dy = this.goal.y - C.y, A = CFG.ISO.tw / 2, B2 = CFG.ISO.th / 2, sx = (dx - dy) * A, sy = (dx + dy) * B2, l = Math.hypot(sx, sy) || 1;
    B.axis.x = sx / l; B.axis.y = sy / l;
    if (C.moving && C.lastX !== undefined && Math.hypot(C.x - C.lastX, C.y - C.lastY) < CFG.CAT.speed * dt * 0.3) C.stuck += dt; else C.stuck = 0;
    C.lastX = C.x; C.lastY = C.y;
  },
};
