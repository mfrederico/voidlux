// The centipede: head + a chain of segments that follow its trail. Segments ARE the build.
const Player = {
  x: 0, y: 0, angle: 0, segments: [], trail: [], segPos: [], invuln: 0, alive: true,
  speedMult: 1, shedCd: 0, inLake: false, wiggle: 0, maxLen: 0, headSlow: 0, freshBag: new Set(), // freshBag: kinds just crafted into the bag (the workbench frames them in lightning)
  head: null, spitting: false, // built-in fallback bullet
  bag: [], // spare bullet ids, swapped in on the pause screen
  firing: true, // SPACE toggles this: off = nothing on the body shoots (eat frightened ghosts instead of killing them)
  maxSegs: 3,
  digs: 0, // shovel charges inside the current dungeon
  swings: 0, hammerT: 0, // the big top's hammer: seconds of auto-swinging left (swings is unused, kept for old saves)
  flight: null, // in a cannon's flight: { t, a, hits } (World.updateFlight)
  giant: 0, tiny: 0, shroom: 0, mirror: 0, tractor: false, // oddity timers: Eat Me / the tractor, Drink Me, the mushroom, laughing gas
  scale() { const B = G.bolt; return this.giant > 0 ? CFG.GIANT.r : B ? 1 + (CFG.LIGHTNING.scale - 1) * this.ballK() : this.tiny > 0 ? 0.6 : 1; },
  ballK() { const B = G.bolt; if (!B) return 0; const k = Math.min(1, (B.t0 - B.t) / 0.4, B.t / 0.4); return k * k * (3 - 2 * k); }, // Lightning Storm: 0..1 as the ball curls up and rises (and back at the end) // how big the body is drawn (and the head's crush reach)
  // dungeon grid state
  gcol: 0, grow: 0, gdir: { x: 0, y: -1 }, gprog: 0, want: null,

  reset(x, y) {
    this.x = x; this.y = y; this.angle = 0; this.invuln = 1.5; this.alive = true; this.shedCd = 0; this.wiggle = 0;
    this.segments = CFG.START_SEGMENTS.map(id => ({ kind: 'weapon', id, cd: U.rand(0, 0.3), inflight: 0, jam: false, para: null }));
    this.headSlow = 0; this.maxSegs = CFG.MAX_SEGMENTS; this.firing = true; this.bag = []; this.freshBag = new Set();
    this.gear = null; this.gears = []; this.flight = null; this.giant = 0; this.tiny = 0; this.shroom = 0; this.mirror = 0; this.tractor = false; SFX.engineOff(); this.swings = 0; this.geyserT = 0;
    this.blueprint = []; this.autoRebuild = false; this.lockHint = false; this.reorientText = ''; // the build: locked on the workbench (the padlock), the body in order. Nothing is rebuilt until you lock one
    this.head = { kind: 'weapon', id: CFG.HEAD_SPIT, cd: 0, inflight: 0, jam: false, para: null }; this.spitting = false;
    this.maxLen = this.segments.length; G.maxLen = this.segments.length;
    this.ready = false;
    this.initTrail(x, y, 0);
    this.resolveBuild();
    this.computeSegPos();
  },
  initTrail(x, y, angle, coil) { this.wallX = x; // (a deliberate move: a warp, a climb, a dungeon exit; the wall memory starts over here) // coil: the whole body starts curled up on the head (a maze: it unfurls along the corridor you take, never through a wall)
    this.trail = [];
    const n = Math.ceil(((this.segments.length + 2) * CFG.SEG_SPACING + 60) / 4);
    for (let i = n; i >= 0; i--) {
      const dd = coil ? 0 : i * 4;
      this.trail.push({ x: x - Math.cos(angle) * dd, y: y - Math.sin(angle) * dd, d: (n - i) * 4 });
    }
  },
  pushTrail() {
    const t = this.trail, last = t[t.length - 1];
    const dd = U.dist(last.x, last.y, this.x, this.y);
    if (dd < 0.4) return;
    t.push({ x: this.x, y: this.y, d: last.d + dd });
    const need = (this.segments.length + 2) * CFG.SEG_SPACING + 60;
    while (t.length > 2 && t[t.length - 1].d - t[1].d > need) t.shift();
  },
  computeSegPos() {
    if (G.mode === 'deck') { this.segPos = Deck.formPos(); return; } // on the saucer deck the body holds the cannon formation, not a trail
    const res = [], t = this.trail, headD = t[t.length - 1].d;
    let j = t.length - 1;
    for (let i = 0; i < this.segments.length; i++) {
      const target = headD - (i + 1) * CFG.SEG_SPACING;
      while (j > 0 && t[j - 1].d > target) j--;
      if (j === 0) { res.push({ x: t[0].x, y: t[0].y, a: this.angle }); continue; }
      const a = t[j - 1], b = t[j], span = b.d - a.d, f = span > 0 ? (target - a.d) / span : 0;
      res.push({ x: U.lerp(a.x, b.x, f), y: U.lerp(a.y, b.y, f), a: Math.atan2(b.y - a.y, b.x - a.x) });
    }
    if (G.bolt && res.length) this.ballPos(res); // Lightning Storm: curled up round the head
    this.segPos = res;
  },
  // the Lightning Storm ball: the segments ring the head, spinning, blended in from (and back out to) the trail
  ballPos(res) {
    const n = res.length, k = this.ballK(), sc = this.scale(), R = Math.max((CFG.HEAD_R + CFG.SEG_R + 4) * sc, n * CFG.SEG_R * sc * 2.1 / TAU), spin = G.time * 4;
    for (let i = 0; i < n; i++) { const a = spin + i / n * TAU; res[i] = { x: U.lerp(res[i].x, this.x + Math.cos(a) * R, k), y: U.lerp(res[i].y, this.y + Math.sin(a) * R, k), a: k > 0.5 ? a : res[i].a }; }
  },

  collect(pk) {
    pk.alive = false;
    if (pk.kind === 'weapon' && this.isGear(pk.id)) { if (this.gainGear(pk.id) && !pk.loose) Settings.discover(pk.id); return; } // head gear: onto the hat rack (and the head, if it is bare)
    if (pk.kind === 'weapon') {
      const B = BULLETS[pk.id];
      const wanted = this.wantedNow(pk.id), where = this.addSegment(this.mkSeg(pk.id), pk.loose);
      if (!where) { pk.alive = true; return; }
      if (wanted && where === 'body') { SFX.play('pickup'); G.msg(`+ ${B.name}: back into your loadout.`, B.color); this.rebuild(); return; }
      G.msg(where === 'bag' ? `${B.name} into the bag (${this.bagTypes()}/${this.bagMax()} kinds)${this.lockedShort() && this.weaponCount() < this.maxSegs ? ': your loadout is saved, so the body stays as set' : ''}` : `+ ${B.name}: ${B.desc}`, B.color); SFX.play('pickup');
      if (!pk.loose) Settings.discover(pk.id); // never held before: the card (not for a piece of your own body)
    }
    else if (pk.kind === 'mega') { if (Mega.add(pk.id, 'found: it joins your deck')) { SFX.play('fuse4'); G.flash = 0.4; G.banner = { text: 'MEGA FOUND', sub: `${MEGAS[pk.id].name} joins your mega deck (${Mega.owned().length} found)`, t: 2.4 }; } }
    else if (pk.kind === 'cleanse') { this.cleanse(); SFX.play('pickup'); G.msg('Cleanse!', '#fff'); }
    else if (pk.kind === 'ghost') { G.ghosts++; SFX.play('eat'); Combat.floaters.push({ x: pk.x, y: pk.y - 14, t: 0.9, text: '+1 ghost', color: '#7de0ff' }); } // the deck's UFO coin: a perk's worth
    else if (pk.kind === 'dot') { const v = pk.value || 1; G.dots += v; SFX.play(v > 1 ? 'pellet' : 'dot'); Combat.floaters.push({ x: pk.x, y: pk.y - 14, t: 0.8, text: `+${v} dot${v > 1 ? 's' : ''}`, color: '#ffe9a8' }); }
    else if (pk.kind === 'egg') { if (!Minions.hatch(null, pk.x, pk.y)) { pk.alive = true; return; } } // hatches straight onto your tail
    else if (pk.kind === 'cake') { this.giant = CFG.GIANT.time; this.tractor = false; this.tiny = 0; SFX.play('power'); G.shake = 0.4; G.banner = { text: 'EAT ME', sub: `you are enormous for ${CFG.GIANT.time}s: crush them`, t: 1.8 }; G.msg('EAT ME: the cake makes you ENORMOUS. Nothing can hurt you and whatever touches your head is crushed.', '#ffd166'); }
    else if (pk.kind === 'potion') { this.tiny = CFG.TINY.time; this.giant = 0; SFX.play('pellet'); G.banner = { text: 'DRINK ME', sub: `tiny and quick for ${CFG.TINY.time}s: their shots sail over you`, t: 1.8 }; G.msg('DRINK ME: you shrink. Enemy shots and tongues miss you, you crawl half again as fast, your bullets hit for less. Bodies still bite.', '#7df9ff'); }
    else if (pk.kind === 'shroom') { this.shroom = CFG.SHROOM.time; SFX.play('laugh'); G.tint = { color: '243,198,255', t: 1.5 }; G.banner = { text: 'THE MUSHROOM', sub: `double damage for ${CFG.SHROOM.time}s. The colours are wrong`, t: 1.8 }; G.msg('You ate the mushroom. Everything is inside out and your bullets hit twice as hard.', '#f3c6ff'); }
    Combat.burst(pk.x, pk.y, 12, '#fff', 2);
    this.rebuild(); // new parts or new dots: maybe the build can be put back together
  },

  update(dt) {
    if (!this.alive) return;
    this.invuln -= dt; this.shedCd -= dt; this.wiggle += dt;
    if (Input.once('Space') && G.mode !== 'deck') { this.firing = !this.firing; G.msg(this.firing ? 'Weapons ON' : 'Weapons OFF: holding fire', this.firing ? '#9bf06a' : '#ff9b8b'); SFX.play(this.firing ? 'pickup' : 'shed'); }
    this.oddities(dt);
    if (G.mode === 'world') this.updateWorld(dt); else if (G.mode === 'deck') Deck.movePlayer(dt); else Dungeon.movePlayer(dt);
    Minions.update(dt); // the tail minion may leap at a boss
    this.pushTrail();
    this.computeSegPos();
    if (G.mode === 'world' && !this.ready) return;
    // parasites riding segments run out of time and die
    let changed = false;
    for (let i = 0; i < this.segments.length; i++) {
      const s = this.segments[i];
      if (!s.para) continue;
      s.para.t -= dt;
      if (s.para.t <= 0) { this.shakeOff(i); changed = true; }
    }
    if (this.headSlow > 0) { this.headSlow -= dt; if (this.headSlow <= 0) changed = true; }
    if (changed) this.resolveBuild();
    this.fireUpdate(dt);
  },
  // where the head will be in t seconds if you keep doing what you are doing: straight on, or round the arc you are
  // turning on. Bosses aim stomps, spits, stones and beams at this, so orbiting one at a steady radius walks you into them.
  predict(t) {
    const sp = CFG.PLAYER_SPEED * this.speedMult * G.mods.speed * (this.inLake ? 0.6 : 1) * (this.giant > 0 ? CFG.GIANT.speed : this.tiny > 0 ? CFG.TINY.speed : 1), w = this.angVel || 0;
    if (!this.ready || this.flight) return { x: this.x, y: this.y };
    if (Math.abs(w) < 0.35) return { x: this.x + Math.cos(this.angle) * sp * t, y: this.y + Math.sin(this.angle) * sp * t };
    const R = sp / w, a1 = this.angle + w * t;
    return { x: this.x + (Math.sin(a1) - Math.sin(this.angle)) * R, y: this.y - (Math.cos(a1) - Math.cos(this.angle)) * R };
  },
  // the oddity timers (overworld only): giant, tiny, mushroom, laughing gas
  oddities(dt) {
    if (G.mode !== 'world') return;
    this.gearUpdate(dt); // head gear (player-gear.js)
    if (this.mirror > 0) this.mirror -= dt;
    if (this.shroom > 0) { this.shroom -= dt; if (U.chance(dt * 25)) { const s = this.segPos[U.randi(0, Math.max(0, this.segPos.length - 1))] || this; Combat.particles.push(Combat.mk(s.x, s.y, U.rand(-20, 20), U.rand(-20, 20), 0.8, `hsl(${(G.time * 400) % 360},90%,65%)`, 6)); } }
    if (this.tiny > 0) { this.tiny -= dt; if (this.tiny <= 0) G.msg('You are your own size again.', '#7df9ff'); }
    if (this.giant > 0) {
      this.giant -= dt; this.invuln = Math.max(this.invuln, 0.05);
      const reach = CFG.HEAD_R * CFG.GIANT.r;
      for (const e of G.enemies) { if (e.dead || e.hidden || e.ally > 0 || e.kind === 'stone' || e.crushT > 0) continue; if (U.dist2(e.x, e.y, this.x, this.y) < (reach + e.r) ** 2) { e.crushT = 0.5; Combat.damage(e, e.kind === 'boss' ? Math.max(CFG.GIANT.dmg * G.powerMult, e.maxhp * CFG.GIANT.bossHit) : e.hp + 1, '#ffd166'); Combat.burst(e.x, e.y, 10, '#fff', 3); G.shake = Math.max(G.shake, 0.15); SFX.play(this.tractor ? 'stomp' : 'wall'); } }
      for (const e of G.enemies) if (e.crushT > 0) e.crushT -= dt;
      for (const t of World.trees) if (t.alive && Math.abs(t.x - this.x) < 100 && U.dist2(t.x, t.y, this.x, this.y) < (reach + t.r * 0.5) ** 2) { t.alive = false; Combat.burst(t.x, t.y, 14, t.charred ? '#55555c' : '#5fae4c', 3); }
      const W = CFG.GIANT.warn, low = this.giant < W ? 1 - this.giant / W : 0; // the last seconds: running out of fuel (or cake)
      if (low > 0 && Math.ceil(this.giant) < Math.ceil(this.giant + dt)) SFX.play('fuel'); // a warning beep on each second left
      if (this.tractor) {
        SFX.engine(1, low); // the engine chugs (and sputters as the tank runs dry)
        if (U.chance(dt * (20 + 30 * low))) Combat.particles.push(Combat.mk(this.x - Math.cos(this.angle) * 20, this.y - Math.sin(this.angle) * 20, U.rand(-20, 20), U.rand(-40, -10), 0.6 + 0.5 * low, low > 0 ? '#2a2630' : '#8a5a3a', 4 + 4 * low)); // dust, then black smoke
      }
      if (this.giant <= 0) { this.invuln = Math.max(this.invuln, CFG.GIANT.grace); G.msg(this.tractor ? 'Out of fuel: you hop off the tractor.' : 'Back to normal size.', '#ffd166'); if (this.tractor) SFX.engineOff(); this.tractor = false; } // a moment of grace to get clear of the crowd
    }
  },
  updateWorld(dt) {
    if (this.flight) { World.updateFlight(dt); return; } // fired from a cannon: no steering until you land
    const ax = Input.axis();
    if (!this.ready) { // a held key, the joystick, or even a tap shorter than a frame starts the crawl
      if (!ax.x && !ax.y) { const k = Input.pressed; if (k.KeyW || k.ArrowUp) ax.y = -1; else if (k.KeyS || k.ArrowDown) ax.y = 1; else if (k.KeyA || k.ArrowLeft) ax.x = -1; else if (k.KeyD || k.ArrowRight) ax.x = 1; }
      if (ax.x || ax.y) { this.ready = true; this.reorient = false; this.angle = Math.atan2(ax.y, ax.x); this.invuln = Math.max(this.invuln, 1); } else return;
    }
    if (this.mirror > 0) { ax.x = -ax.x; ax.y = -ax.y; } // laughing gas
    const a0 = this.angle;
    if (ax.x || ax.y) this.angle = U.angLerp(this.angle, Math.atan2(ax.y, ax.x), CFG.TURN_RATE * dt);
    { let da = this.angle - a0; while (da > Math.PI) da -= TAU; while (da < -Math.PI) da += TAU; this.angVel = (this.angVel || 0) * 0.85 + (dt > 0 ? da / dt : 0) * 0.15; } // how hard you are turning (bosses aim along your arc, so circling them is not free)
    this.inLake = World.inLake(this.x, this.y);
    const sp = CFG.PLAYER_SPEED * this.speedMult * G.mods.speed * (this.inLake ? 0.6 : 1) * (this.giant > 0 ? CFG.GIANT.speed : this.tiny > 0 ? CFG.TINY.speed : 1);
    this.x += Math.cos(this.angle) * sp * dt;
    this.y += Math.sin(this.angle) * sp * dt;
    const HR = CFG.HEAD_R;
    // a mime's invisible wall: you cannot cross it either (it just stops you, no harm)
    for (const e of G.enemies) {
      if (e.dead || e.hidden || !e.mimeWall || Math.abs(e.x - this.x) > 220) continue;
      const W = e.mimeWall, rx = this.x - W.x, ry = this.y - W.y, nx = -Math.sin(W.a), ny = Math.cos(W.a), along = rx * Math.cos(W.a) + ry * Math.sin(W.a), perp = rx * nx + ry * ny;
      if (Math.abs(along) < W.len / 2 && Math.abs(perp) < HR + 6) { const side = perp >= 0 ? 1 : -1; this.x = W.x + rx - perp * nx + nx * side * (HR + 6); this.y = W.y + ry - perp * ny + ny * side * (HR + 6); if (!e.mimeShown) { e.mimeShown = true; G.msg('The mime is holding an invisible wall. Shoot the mime.', '#fff'); } }
    }
    // world bounds (thorn hedge)
    const minX = CFG.HEDGE + HR, minY = CFG.HEDGE + HR, maxY = CFG.WORLD_H - CFG.HEDGE - HR;
    if (this.x < minX) { this.x = minX; this.bounce(Math.PI - this.angle, 'the thorn hedge', 'x'); }
    if (this.y < minY) { this.y = minY; this.bounce(-this.angle, 'the thorn hedge', 'y'); }
    if (this.y > maxY) { this.y = maxY; this.bounce(-this.angle, 'the thorn hedge', 'y'); }
    // region walls: the side you came from (wallX, where the head was last frame) wins, and a wall jumped clean across in one
    // step (a geyser's launch, a shove, a frame hitch) blocks too: nothing can tunnel through a wall, a sealed arena's least of all
    const w = World.wallAt(this.x, this.y, HR) || World.wallBetween(this.wallX, this.x, this.y);
    if (w) {
      const side = (this.wallX != null && Math.sign(this.wallX - w.x)) || Math.sign(this.x - w.x) || -1;
      this.x = w.x + side * (CFG.WALL_T / 2 + HR + 1);
      this.bounce(Math.PI - this.angle, 'a thorn wall', 'x');
    }
    const SW = G.swarm, SR = SW && G.mode === 'world' && World.regions[SW.region]; // a swarm seals its region, breaches and all, until it is wiped out: turned back, unhurt
    if (SR) { const lo = SR.x0 + CFG.WALL_T / 2 + HR + 1, hi = SR.x1 - CFG.WALL_T / 2 - HR - 1; if (this.x < lo || this.x > hi) { this.x = U.clamp(this.x, lo, hi); this.angle = Math.PI - this.angle; } if ((SR.idx > 0 && this.x <= lo + 1) || this.x >= hi - 1) { if (!SW.told) { SW.told = true; Settings.swarmCard(); } else if ((this.sealMsgT || 0) < G.time) { this.sealMsgT = G.time + 3; G.msg('Do your job: pest control that swarm! The walls open when it is gone.', '#ffd166'); } } } // (the first bump of each swarm pauses on the card)
    this.wallX = this.x;
    if (this.invuln <= 0) {
      for (const t of World.trees) {
        if (!t.alive || Math.abs(t.x - this.x) > 60) continue;
        if (U.dist2(t.x, t.y, this.x, this.y) < (HR + t.r * 0.55) ** 2) {
          t.alive = false;
          Combat.burst(t.x, t.y, 18, t.charred ? '#55555c' : '#5fae4c', 3); Combat.burst(t.x, t.y, 8, t.charred ? '#2a2328' : '#8a5a3a', 3);
          this.hit(1, t.charred ? 'a charred tree' : 'a tree', t.x, t.y);
          break;
        }
      }
    }
    if (this.invuln <= 0) {
      for (const b of World.boulders) {
        if (b.gone || Math.abs(b.x - this.x) > 60) continue; // fried or woken boulders are not there any more
        if (U.dist2(b.x, b.y, this.x, this.y) < (HR + b.r * 0.8) ** 2) { this.hit(2, 'a boulder', b.x, b.y); Combat.burst(b.x, b.y, 8, '#aaa', 2); break; }
      }
      for (const g of World.graves) { // tombstones are stone too: circle them, do not ram them
        if (Math.abs(g.x - this.x) > 60) continue;
        if (U.dist2(g.x, g.y - 6, this.x, this.y) < (HR + g.r * 0.6) ** 2) { this.hit(1, 'a tombstone', g.x, g.y); Combat.burst(g.x, g.y - 10, 8, '#a9a6ad', 2); break; }
      }
      for (const cn of World.cannons) { // iron: circle it, do not ram it
        if (Math.abs(cn.x - this.x) > 60) continue;
        if (U.dist2(cn.x, cn.y, this.x, this.y) < (HR + cn.r * 0.75) ** 2) { this.hit(1, 'a cannon', cn.x, cn.y); Combat.burst(cn.x, cn.y, 8, '#aaa', 2); break; }
      }
      for (const d of World.regions[World.regionAt(this.x)].deco) { // booths and closed coffins are solid too
        if (!d.solid || Math.abs(d.x - this.x) > 80) continue;
        if (U.dist2(d.x, d.y, this.x, this.y) < (HR + d.solid * 0.7) ** 2) { this.hit(1, d.kind === 'coffin' ? 'a coffin' : d.kind === 'barn' ? 'a barn' : 'a ticket booth', d.x, d.y); Combat.burst(d.x, d.y, 8, '#aaa', 2); break; }
      }
    }
    // pickups
    for (const pk of World.pickups) {
      if (pk.lift) continue; // already on its way up a tractor beam
      if (!pk.alive || Math.abs(pk.x - this.x) > 40) continue;
      if (U.dist2(pk.x, pk.y, this.x, this.y) < (HR + pk.r) ** 2) {
        if (pk.kind === 'weapon' && !this.wouldTake(pk.id, pk)) { if (!pk.warned) { pk.warned = true; G.msg(this.stackFull(pk.id) ? `Your ${BULLETS[pk.id].name} stack is full (${CFG.STACK_MAX}). Left it.` : 'Body and bag are full. Left it.', '#aaa'); } continue; }
        if (pk.kind === 'egg' && Minions.count() >= Minions.cap()) { if (!pk.warned) { pk.warned = true; G.msg(`No room for another minion (${Minions.count()}/${Minions.cap()}). The egg waits here.`, '#c58aff'); } continue; }
        this.collect(pk);
      }
    }
    // dungeon entrances
    if (this.invuln <= 0 || G.god) { // god mode is always invulnerable, so it may enter regardless
      for (const en of World.entrances) { // the whole entrance is the doorway: touch any of it (an ellipse over the drawn mound, crypt, temple, saucer or tent) and you are in
        if (en.used || en.cd > 0 || Math.abs(en.x - this.x) > 80) continue;
        const ex = (this.x - en.x) / (CFG.DOOR.rx + HR * 0.6), ey = (this.y - en.y - CFG.DOOR.dy) / (CFG.DOOR.ry + HR * 0.6);
        if (ex * ex + ey * ey < 1) {
          if (G.swarm) { en.cd = 1.5; if (!G.swarm.told) { G.swarm.told = true; Settings.swarmCard(); } else G.msg('Do your job: pest control that swarm! No hiding in a dungeon.', '#ffd166'); break; } // a swarm seals the dungeons too
          G.enterDungeon(en); break;
        }
      }
    }
  },
  bounce(newAngle, what, axis) {
    if (/wall/.test(what)) this.wallBumpT = G.time; // (your guns turn on the wall for a moment: wallTarget)
    if (this.invuln > 0) {
      // already hit recently: slide along the barrier instead of jittering back into it
      if (axis === 'y') this.angle = Math.cos(this.angle) >= 0 ? 0 : Math.PI;
      else if (axis === 'x') this.angle = Math.sin(this.angle) >= 0 ? Math.PI / 2 : -Math.PI / 2;
      else this.angle = newAngle;
      return;
    }
    this.angle = newAngle;
    this.hit(1, what);
  },

};
