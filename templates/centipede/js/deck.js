// The saucer deck: the farm's dungeon as a fixed-screen shooter (a prototype). The centipede folds into a cannon at the
// bottom of the deck, the head on top and the segments racked below it like bowling pins (rows of 2, 3, 4...), and slides
// left and right; every segment is a gun and fires as it always does. A ray that hits a segment knocks that one off. Waves
// of invaders (brainlings, scout saucers, abducted cows) step down the deck in formation and fire rays. Invaders drop
// dots that drift down to be caught; a bonus UFO crosses the top now and then and drops a ghost (a perk coin) when hit;
// a cow that reaches your band lifts a segment, and gives it back as a pickup if it dies before it leaves the top. The
// last wave down clears the deck: the perk shop opens, as it does for any dungeon.
// Same seams as Dungeon: enter() / exit(), movePlayer(), update(), draw(), isSolid(); G.mode is 'deck' while inside.
const Deck = {
  W: 1280, PW: 1280, H: 720, BAND: 0.78, WAVES: 5, BEAM_W: 44, BEAM_LIFT: 170, // the UFO's tractor beam: half-width at the floor, and how fast it lifts
  invaders: [], pickups: [], rays: 0, wave: 0, winT: 0, t: 0, ufo: null, ufoT: 0, form: null, entrance: null, region: 0, returnPos: null,
  // the screen's width (re-run when it changes, e.g. going full screen): the play stops short of the MEGA bar (and on a phone the FIRE column)
  resize() { this.W = CFG.W; this.H = CFG.H; this.PW = Input.touch ? Math.min(...TOUCH.buttons.filter(b => b.id === 'mega' || b.id === 'fire').map(b => b.x - (b.hw || b.r))) - 16 : (b => b.x - b.hw - 16)(Render.deskMega()); }, // (a keyboard screen: short of its MEGA bar)
  enter(entrance) {
    SFX.play('enter');
    this.entrance = entrance; this.region = entrance.region; this.returnPos = { x: entrance.x, y: entrance.y };
    this.WAVES = G.nightmare ? CFG.NIGHTMARE.deckWaves : 5; // nightmare: more waves
    this.resize(); this.t = 0; this.winT = 0; this.wave = 0; this.ufo = null; this.ufoT = 6; this.pickups = [];
    Combat.reset();
    G.mode = 'deck';
    G.enemies = this.invaders = [];
    G.cam.x = 0; G.cam.y = 0;
    const P = Player;
    P.x = this.PW / 2; P.y = this.H - 70; P.angle = -Math.PI / 2; P.invuln = 1.2; P.ready = true; P.reorient = false;
    P.initTrail(P.x, P.y, -Math.PI / 2); P.computeSegPos();
    Minions.enterDungeon();
    if (G.deckStats) G.deckStats.entered++; this.enterT = G.time;
    // the dot purse: what a maze in this region holds, shared over every invader of every wave by weight
    const T = CFG.DUNGEON_TIERS.find(t => this.region <= t.maxRegion) || CFG.DUNGEON_TIERS[CFG.DUNGEON_TIERS.length - 1]; let wsum = 0;
    for (let w = 1; w <= this.WAVES; w++) { const f = this.formation(w); for (let r = 0; r < f.rows; r++) for (let c = 0; c < f.cols; c++) wsum += this.weight(this.typeAt(w, r, c, f.rows)); }
    this.dotPer = CFG.DECK.DOTS * T.cols * T.rows / Math.max(1, wsum); this.dotAcc = 0; this.waveLost = 0;
    this.nextWave();
    this.prevTheme = Music.on ? Music.name : null; if (Music.THEMES.deck) Music.start('deck'); // the deck's own tune; the level's comes back on the way out
    const B = { name: 'The Saucer Deck', color: '#9bf06a', lines: [[`You are the cannon now. ${Input.touch ? 'Hold left or right of the middle to slide it: the further out you hold, the faster it goes' : 'Slide with A / D'}.`, '#9bf06a'], ['A hit knocks off the segment it lands on. Some invaders break off and dive at you.', '#fff'], [`Clear ${this.WAVES} waves. Shoot the cows: they drop ghosts. Catch what falls before it drops off the deck.`, '#ffd166']] };
    if (!Settings.dungeonCard(B)) G.msg(`THE SAUCER DECK: ${B.lines.map(l => l[0]).join(' ')}`, '#9bf06a'); // the get-ready card, or (cards off) one message
  },
  exit(success) {
    const P = Player;
    if (Music.name === 'deck') { Music.stop(); const th = this.prevTheme || Music.levelTheme(G.level); if (th) Music.start(th); }
    G.mode = 'world';
    G.enemies = Enemies.list;
    Combat.reset();
    P.x = this.returnPos.x; P.y = this.returnPos.y + 40; P.angle = Math.PI / 2; P.invuln = 1.6;
    P.ready = false; P.reorient = true; P.reorientText = 'Back outside';
    P.initTrail(P.x, P.y, P.angle); P.computeSegPos();
    G.snapCam();
    this.entrance.cd = 2.5;
    if (!P.firing) { P.firing = true; G.msg('Weapons back ON', '#9bf06a'); }
    if (G.deckStats) { G.deckStats.secs += Math.round(G.time - this.enterT); if (success) G.deckStats.cleared++; }
    if (success) { this.entrance.used = true; G.dungeonsCleared++; G.score += 500 * (this.region + 1); P.cleanse(); P.rebuild(); Perks.offer(); }
    else G.msg('Left the deck early. No reward.', '#ccc');
    Save.write();
  },
  // a lost segment: its pieces pop up and out, loose and yours, to be caught before they fall off the deck
  drop(id, p) {
    if (G.deckStats) G.deckStats.lost++; this.waveLost++;
    // on the deck a loss bites: the dots it cost to craft are gone, and its pieces burst up and out (no catching them for a
    // moment, so they can't drop straight back into the rack) and fall through the floor unless you slide under them
    const parts = Player.pieces(id).parts.slice();
    parts.forEach((pid, k) => { const pk = Object.assign(World.mkPickup(p.x, p.y, 'weapon', pid, this.region), { loose: true, fall: true, grace: 0.5 }); const a = -Math.PI / 2 + (k - (parts.length - 1) / 2) * 0.7 + U.rand(-0.35, 0.35), sp = U.rand(300, 360); pk.vx = Math.cos(a) * sp; pk.vy = Math.sin(a) * sp; pk.pop = true; this.pickups.push(pk); });
  },
  isSolid(p) { return p.x < 10 || p.x > this.W - 10 || p.y < 10 || p.y > this.H - 10; }, // bullets die at the deck's edges
  // ---- the player: a cannon in the bowling-pin formation, sliding along the bottom ----
  DX: 23, DY: 18, // the rack's spacing: just over a segment apart, so a ray picks off one segment, not two
  rows(n) { let r = 0, used = 0; while (used < n) { r++; used += r + 1; } return r; }, // rows of 2, 3, 4... to hold n segments
  formPos() { // head at the apex (P.x, P.y); row r (1, 2, ...) holds r + 1 segments, the last row centred; all face up the deck
    const P = Player, n = P.segments.length, res = [];
    for (let r = 1; res.length < n; r++) { const k = Math.min(r + 1, n - res.length); for (let j = 0; j < k; j++) res.push({ x: P.x + (j - (k - 1) / 2) * this.DX, y: P.y + r * this.DY, a: -Math.PI / 2 }); }
    return res;
  },
  movePlayer(dt) {
    const P = Player, ax = Input.axis(), sp = CFG.PLAYER_SPEED * 1.15 * P.speedMult * G.mods.speed;
    if (Input.joy) { const z = this.zone(Input.joy.x); P.x += z.dir * this.SPEEDS[z.k] * sp * dt; } // a phone: hold left of the middle to slide left, right to slide right; the further out, the faster (four steps)
    else P.x += (ax.x || 0) * sp * dt;
    P.angle = -Math.PI / 2;
    const half = (this.rows(P.segments.length) + 1) / 2 * this.DX + 10; // the widest row's half-width keeps the whole rack on the deck
    P.x = U.clamp(P.x, 30 + half, this.PW - 30 - half);
    P.y = this.H - 34 - this.rows(P.segments.length) * this.DY; // the bottom row sits on the deck's floor: a bigger body stands taller
    P.computeSegPos(); const HR = CFG.HEAD_R;
    for (const pk of this.pickups) { if (!pk.alive || pk.grace > 0) continue; if (U.dist2(pk.x, pk.y, P.x, P.y) < (HR + pk.r + 4) ** 2) { if (pk.kind === 'weapon' && !P.wouldTake(pk.id, pk)) continue; P.collect(pk); continue; } for (const s of P.segPos) if (U.dist2(pk.x, pk.y, s.x, s.y) < (CFG.SEG_R + pk.r) ** 2) { if (pk.kind === 'weapon' && !P.wouldTake(pk.id, pk)) break; P.collect(pk); break; } } // the body catches what falls on it
  },
  // ---- waves ----
  formation(w) { return { cols: Math.min(11, 6 + w), rows: Math.min(5, 2 + Math.ceil(w / 2)) }; },
  typeAt(w, r, c, rows) { return r === 0 && c % 2 === 1 ? 'mooncow' : r === rows - 1 ? 'saucer' : 'brainling'; },
  weight(type) { return type === 'mooncow' ? 4 : type === 'saucer' ? 3 : 2; },
  nextWave() {
    if (this.wave >= 1 && this.wave <= this.WAVES && !this.waveLost && CFG.DECK.PERFECT) { // a clean wave: not one segment lost
      for (let k = 0; k < CFG.DECK.PERFECT; k++) { const g = World.mkPickup(this.PW / 2 + (k - (CFG.DECK.PERFECT - 1) / 2) * 40, 150, 'ghost', 'ghost', this.region); g.r = 12; g.vy = 95; this.pickups.push(g); }
      G.msg(`PERFECT WAVE: not a segment lost. A ghost drifts down: catch it!`, '#7de0ff'); SFX.play('megaReady');
    }
    this.waveLost = 0;
    this.wave++;
    if (this.wave > this.WAVES) { if (this.ufo) { this.ufoBurst(this.ufo, false); this.ufo = null; } this.winT = 2; if (Music.victory(G.level, true)) Music.stop(); else SFX.play('pellet'); G.banner = { text: 'DECK CLEARED', sub: 'the invaders are gone', t: 2 }; SFX.play('cleared'); return; }
    const w = this.wave, { cols, rows } = this.formation(w), gapX = 76, gapY = 50, x0 = this.PW / 2 - (cols - 1) * gapX / 2, y0 = 200; // the formation starts within reach of a short-range body
    this.diveT = 2.2; this.form = { x: 0, y: 0, dir: 1, speed: 36 + 16 * w, drop: 28, cols, rows, x0, y0, gapX, gapY, stepT: 0 };
    const types = ['brainling', 'saucer', 'mooncow'].filter(k => BUGS[k]);
    for (let r = 0; r < rows; r++) for (let c = 0; c < cols; c++) {
      const type = this.typeAt(w, r, c, rows), e = Enemies.spawnBug(type, x0 + c * gapX, y0 + r * gapY, this.region, false);
      Enemies.list.splice(Enemies.list.indexOf(e), 1); // it belongs to the deck, not the overworld
      e.hidden = false; e.emerge = 0; e.formC = c; e.formR = r; e.col0 = e.x; e.row0 = e.y; e.fireT = U.rand(2, 6 - w); e.awake = true; e.r = Math.round(e.r * 1.3); // a little bigger on the deck: easier to read, easier to hit
      const self = e; e.onDeath = () => Deck.onKill(self);
      this.invaders.push(e);
    }
    G.banner = { text: `WAVE ${w}`, sub: `${cols * rows} invaders`, t: 1.6 };
  },
  onKill(e) {
    G.kills++; G.score += 40 * (this.region + 1);
    this.dotAcc += this.weight(e.type) * this.dotPer; const n = Math.floor(this.dotAcc); this.dotAcc -= n; // its share of the deck's dot purse drifts down to be caught
    if (!e.carry && (e.type === 'mooncow' ? U.chance(CFG.DECK.COW) : e.dive && e.dive.phase === 'dive' && U.chance(CFG.DECK.DIVE_GHOST))) { const g = World.mkPickup(e.x, e.y, 'ghost', 'ghost', this.region); g.r = 12; g.vy = 70; this.pickups.push(g); Combat.floaters.push({ x: e.x, y: e.y - 18, t: 1, text: 'GHOST!', color: '#7de0ff' }); } // a cow (the deck's ghost carrier), or a diver shot mid-dive: a ghost to catch
    for (let k = 0; k < n; k++) { const pk = World.mkPickup(e.x + U.rand(-14, 14), e.y, 'dot', 'dot', this.region); pk.value = 1; pk.r = 9; pk.vy = U.rand(70, 110); pk.vx = U.rand(-20, 20); this.pickups.push(pk); }
    if (e.carry) { const pk = World.mkPickup(e.x, e.y, 'weapon', e.carry, this.region); pk.loose = true; pk.vy = 90; this.pickups.push(pk); e.carry = null; for (let k = 0; k < CFG.DECK.COW_GHOST; k++) { const g = World.mkPickup(e.x + (k - (CFG.DECK.COW_GHOST - 1) / 2) * 30, e.y, 'ghost', 'ghost', this.region); g.r = 12; g.vy = 80; this.pickups.push(g); } Combat.floaters.push({ x: e.x, y: e.y - 18, t: 1.2, text: 'GHOST!', color: '#7de0ff' }); G.msg('Popped the cow: your segment and a ghost fall back to the deck. Catch them!', '#9bf06a'); } // the riskiest shot on the deck pays
    else if (U.chance(0.08 * G.mods.luck)) { const pk = World.mkPickup(e.x, e.y, 'weapon', World.pickBullet(2), this.region); pk.vy = 80; this.pickups.push(pk); }
    Combat.burst(e.x, e.y, 10, e.color, 2.5);
  },
  // a dive: a half loop out of the formation, a weaving swoop down at you (firing a shot or two), out through the bottom,
  // and back in from the top to its slot
  updateDive(e, dt, F) {
    const D = e.dive, P = Player, sp = 250 + 40 * this.wave; D.t += dt;
    if (D.t < 0 || e.st.freeze) return; // the wingman waits a beat; a frozen diver hangs where it is
    if (D.phase === 'loop') {
      const k = Math.min(1, D.t / 0.6), a = Math.PI * k;
      e.x = D.x0 + D.side * (1 - Math.cos(a)) * 40; e.y = D.y0 - Math.sin(a) * 50; e.vx = 0; e.vy = 0;
      if (k >= 1) { D.phase = 'dive'; D.t = 0; }
    } else if (D.phase === 'dive') {
      const tx = P.x + Math.sin(D.t * 3) * 90 * D.side;
      D.vx = U.clamp(D.vx + U.clamp((tx - e.x) * 4, -700, 700) * dt, -280, 280);
      e.x += D.vx * dt; e.y += sp * dt; e.vx = D.vx; e.vy = sp;
      if (D.shots > 0 && D.t > D.nextShot && e.y < this.H * this.BAND - 60) { // it fires as it comes, aimed at you
        Combat.fireEnemy(e.x, e.y + 10, Math.atan2(P.y - e.y, P.x - e.x), { speed: 300, power: 1, size: 5, color: e.type === 'saucer' ? '#ff5cf0' : '#9ef04c', life: 3 }); SFX.play('ray');
        D.shots--; D.nextShot = D.t + 0.35;
      }
      if (e.y > this.H + 40) { e.y = -40; e.x = e.col0 + F.x; D.phase = 'return'; }
    } else {
      const tx = e.col0 + F.x, ty = e.row0 + F.y, d = Math.hypot(tx - e.x, ty - e.y);
      if (d < 8) { e.dive = null; return; }
      const s = Math.min(d, sp * 0.8 * dt); e.x += (tx - e.x) / d * s; e.y += (ty - e.y) / d * s; e.vx = 0; e.vy = 0;
    }
  },
  // the UFO blows apart: everything it beamed up bursts out all round in a shower of confetti (and, shot down, its ghost)
  ufoBurst(u, ghost) {
    const L = u.loot.slice(), n = L.length;
    if (ghost) { const g = World.mkPickup(u.x, u.y, 'ghost', 'ghost', this.region); g.r = 12; g.vy = 95; this.pickups.push(g); }
    L.forEach((q, k) => { const a = k * TAU / Math.max(1, n) + U.rand(-0.2, 0.2), sp = U.rand(200, 360); Object.assign(q, { alive: true, beamed: false, x: u.x, y: u.y, pop: true, rest: 0, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp - 120 }); this.pickups.push(q); });
    const cols = ['#ff5c8a', '#7df9ff', '#ffd166', '#9bf06a', '#c58aff', '#ffffff'].concat(L.map(q => q.kind === 'weapon' ? BULLETS[q.id].color : q.kind === 'dot' ? '#ffe9a8' : '#7de0ff'));
    for (let k = 0; k < 14; k++) Combat.burst(u.x, u.y, 4, U.pick(cols), U.rand(2, 4)); // the confetti
    Combat.burst(u.x, u.y, 24, '#7de0ff', 3); G.shake = Math.max(G.shake, 0.3);
    if (n) G.msg(`The UFO burst: ${n} stolen pickup${n > 1 ? 's' : ''} fly out! Catch them before they fall off the deck.`, '#7de0ff');
    u.loot = [];
  },
  // the deck is clear: everything still on it streaks to you
  vacuum(dt) {
    const P = Player;
    for (const pk of this.pickups) {
      if (!pk.alive) continue;
      if (!pk.loose && pk.y > this.H - 28) { pk.alive = false; continue; } // already past the floor: it was lost, the vacuum does not save it
      const d = Math.hypot(P.x - pk.x, P.y - pk.y); pk.vac = (pk.vac || 250) + 2200 * dt; pk.beamed = false; pk.pop = false; pk.vx = 0; pk.vy = 0;
      if (d < 22) { if (pk.kind !== 'weapon' || P.wouldTake(pk.id, pk)) P.collect(pk); pk.alive = false; continue; }
      const s = Math.min(d, pk.vac * dt); pk.x += (P.x - pk.x) / d * s; pk.y += (P.y - pk.y) / d * s;
    }
  },
  update(dt) {
    const P = Player, F = this.form; this.t += dt; P.firing = true; // the deck always fires (Space and the FIRE button do nothing here)
    if (this.landT > 0) this.landT -= dt;
    if (this.winT) { this.vacuum(dt); this.winT -= dt; if (this.winT <= 0) { this.winT = 0; this.exit(true); } return; }
    // the formation steps sideways, drops at the edge and turns; faster as it thins (the classic)
    const live = this.invaders.filter(e => !e.dead && !e.carry);
    if (F) {
      const thin = live.length ? 1 + 1.4 * (1 - live.length / (F.cols * F.rows)) : 1;
      F.x += F.dir * F.speed * thin * dt;
      let minX = 1e9, maxX = -1e9; for (const e of live) { minX = Math.min(minX, e.col0 + F.x); maxX = Math.max(maxX, e.col0 + F.x); }
      if (live.length && (maxX > this.PW - 50 && F.dir > 0 || minX < 50 && F.dir < 0)) { F.dir = -F.dir; F.y += F.drop; SFX.play('dot'); }
    }
    // Galaga dives: now and then one invader (two, from wave 2) peels out of the formation and swoops at you
    this.diveT -= dt;
    if (this.diveT <= 0 && P.alive) {
      const free = live.filter(e => !e.dive && !e.st.freeze), n = this.wave >= 2 && U.chance(0.4) ? 2 : 1;
      for (let k = 0; k < n && free.length; k++) { const e = free.splice(U.randi(0, free.length - 1), 1)[0]; e.dive = { phase: 'loop', t: -k * 0.25, x0: e.x, y0: e.y, side: e.x < this.PW / 2 ? -1 : 1, vx: 0, shots: 1 + (this.wave >= 2 ? 1 : 0), nextShot: 0.25 }; }
      this.diveT = Math.max(0.9, U.rand(1.8, 3.6) - 0.3 * this.wave) * (G.nightmare ? CFG.NIGHTMARE.dive : 1); SFX.play('beam');
    }
    const band = this.H * this.BAND;
    for (const e of this.invaders) {
      if (e.dead) continue;
      e.flash -= dt; e.legT += dt * 10; Combat.tickStatus(e, dt); if (e.dead) continue;
      if (e.carry) { e.y -= 120 * dt; e.x += Math.sin(this.t * 3 + e.seed) * 30 * dt; if (e.y < -40) { e.dead = true; G.msg(`The cow took your ${BULLETS[e.carry].name} to the mothership.`, '#ff9b8b'); } continue; } // a cow with a segment: straight up and away
      if (e.dive) this.updateDive(e, dt, F);
      else if (!e.st.freeze) { e.x = e.col0 + F.x + Math.sin(this.t * 2 + e.formC) * 4; e.y = e.row0 + F.y + Math.sin(this.t * 3 + e.formR) * 3; e.vx = F.dir * F.speed * (live.length ? 1 + 1.4 * (1 - live.length / (F.cols * F.rows)) : 1); e.vy = 0; } else { e.vx = 0; e.vy = 0; } // its drift, so your segments lead their shots
      e.angle = Math.atan2(P.y - e.y, P.x - e.x);
      // rays: the front invader of each column fires at you, leading your drift a little
      e.fireT -= dt;
      if (e.fireT <= 0 && P.alive && !e.st.freeze && !e.st.blind) {
        const front = !this.invaders.some(o => !o.dead && !o.carry && o.formC === e.formC && o.formR > e.formR);
        const raysOut = Combat.projectiles.reduce((n, p) => n + (p.owner === 'enemy' ? 1 : 0), 0); // like the classic: only a few rays in the air at once
        if (front && !e.dive && raysOut < 1 + this.wave) { const a = Math.PI / 2 + U.clamp(Math.atan2(P.x - e.x, P.y - e.y) * -0.25, -0.12, 0.12), B = BUGS[e.type]; /* rays fall nearly straight down, leaning a touch your way: slide out from under them */ Combat.fireEnemy(e.x, e.y + 10, a + U.rand(-0.06, 0.06), { speed: e.type === 'saucer' ? 260 : 200, power: 1, size: e.type === 'mooncow' ? 7 : 5, color: e.type === 'saucer' ? '#ff5cf0' : '#9ef04c', life: 3 }); SFX.play('ray'); }
        e.fireT = U.rand(2.0, 4.6) - 0.25 * this.wave;
      }
      // a cow reaching the band: the tractor beam lifts your tail segment
      if (e.type === 'mooncow' && e.y > band - 40 && P.alive && P.segments.length) { const j = P.segments.length - 1; if (!P.isMinion(P.segments[j])) { e.dive = null; e.carry = P.segments[j].id; P.segments.splice(j, 1); this.waveLost++; P.resolveBuild(); P.computeSegPos(); SFX.play('abduct'); SFX.play('moo'); G.shake = 0.3; G.msg(`A cow's beam lifted your ${BULLETS[e.carry].name}! Shoot it down before it leaves.`, '#9bf06a'); } }
      // an invader reaching your band otherwise: it crashes into you, and the body pays
      else if (e.y > band + 10 && P.alive) { // a diver (or a formation that got this low) crashes into the rack: the segment it hits comes off, and it dies too
        const j = P.segPos.findIndex(s => U.dist(e.x, e.y, s.x, s.y) < e.r + CFG.SEG_R);
        if (j >= 0 && P.invuln <= 0) { P.removeAt(j, 'an invader'); P.invuln = 0.4; e.hp = 0; Combat.damage(e, 1, '#fff'); }
        else if (U.dist(e.x, e.y, P.x, P.y) < e.r + CFG.HEAD_R) { P.hit(1, 'an invader', e.x, e.y); e.hp = 0; Combat.damage(e, 1, '#fff'); }
        // it reached the floor (not a diver passing through): it LANDS. Your tail segment comes off wherever you are, and it is gone
        else if (!e.dive && e.y > this.H - 40) {
          if (!(this.landT > 0)) { // at most one segment a second: a whole row touching down together costs one, the next row another
            this.landT = 1;
            if (P.segments.length) { P.removeAt(P.segments.length - 1, 'an invader landed'); G.msg('Invaders LANDED: they tore off your tail segment. Catch the pieces!', '#ff9b8b'); }
            else P.die('invaders landing');
          }
          e.hp = 0; Combat.damage(e, 1, '#fff'); G.shake = Math.max(G.shake, 0.4);
        }
      }
    }
    // the bonus UFO: across the top, now and then; hit it and it drops a ghost (a perk coin) to catch
    this.ufoT -= dt;
    if (!this.ufo && this.ufoT <= 0 && live.length) { const fromRight = Math.random() < 0.5; this.ufo = { x: fromRight ? this.W + 40 : -40, y: 130, vx: (fromRight ? -1 : 1) * 140, hp: (60 + 25 * this.wave) * (1 + 0.1 * Math.max(0, this.region - 10)), r: 18, t: 0, loot: [] }; SFX.play('beam'); G.msg('A bonus UFO crosses the deck, beaming up your dots and powerups! Shoot it down: it drops a ghost and everything it took.', '#7de0ff'); }
    if (this.ufo) {
      const u = this.ufo; u.x += u.vx * dt; u.t += dt;
      for (const p of Combat.projectiles) { if (p.owner !== 'player' || p.hit.has(u)) continue; if (U.dist2(p.x, p.y, u.x, u.y) < (u.r + p.size) ** 2) { p.hit.add(u); u.hp -= p.dmg; p.life = Math.min(p.life, 0.02); Combat.burst(u.x, u.y, 4, '#7de0ff', 2); } }
      // its tractor beam: anything on the deck under it (dots, powerups, your own lost pieces) rises into it
      for (const pk of this.pickups) {
        if (!pk.alive || pk.kind === 'ghost' || pk.y < u.y || Math.abs(pk.x - u.x) > this.BEAM_W) { pk.beamed = false; continue; }
        if (!pk.beamed) SFX.play('moo'); // up it goes
        pk.beamed = true; pk.pop = false; pk.rest = 0; pk.vx = (u.x - pk.x) * 3 + u.vx; pk.vy = -this.BEAM_LIFT;
        if (pk.y < u.y + 14) { pk.alive = false; u.loot.push(pk); if (U.chance(0.5)) SFX.play('dot'); } // taken aboard
      }
      if (u.hp <= 0) { this.ufoBurst(u, true); SFX.play('kill'); G.score += 200; this.ufo = null; this.ufoT = U.rand(7, 11); }
      else if (u.x < -60 || u.x > this.W + 60) { if (u.loot.length) G.msg(`The UFO got away with ${u.loot.length} of your pickups.`, '#ff9b8b'); this.ufo = null; this.ufoT = U.rand(7, 11); }
    }
    // falling pickups: drift down, settle on the floor, fade after a while
    for (let i = this.pickups.length - 1; i >= 0; i--) {
      const pk = this.pickups[i]; if (!pk.alive) { this.pickups.splice(i, 1); continue; }
      if (pk.beamed && !this.ufo) { pk.beamed = false; pk.pop = true; pk.vx *= 0.3; pk.vy = Math.max(0, pk.vy) + 30; } // the beam dies with its saucer: what it was lifting drops
      if (pk.y < 24 && pk.vy < 0) { pk.y = 24; pk.vy = 0; } // the deck has a ceiling: nothing sails off the top
      pk.bob += dt * 3; if (pk.pop && !pk.beamed) pk.vy = (pk.vy || 0) + 420 * dt; pk.x += (pk.vx || 0) * dt; pk.y += (pk.vy || 0) * dt; if (pk.grace > 0) pk.grace -= dt; if (pk.loose && !pk.fall && pk.y >= this.H - 28) { pk.y = this.H - 28; pk.vy = 0; pk.vx = 0; pk.pop = false; pk.rest = (pk.rest || 0) + dt; if (pk.rest > CFG.LOOSE.time) { pk.alive = false; Combat.burst(pk.x, pk.y, 6, '#8a8a99', 2); } } // your own pieces land and wait (they blink for the last 3 s)
      else if (pk.y > this.H + 24) pk.alive = false; // anything else falls off the deck: catch it or lose it
      pk.x = U.clamp(pk.x, 30, this.PW - 30);
    }
    if (!this.invaders.some(e => !e.dead) && !this.winT) this.nextWave();
  },
  // the phone's slide: the deck's width splits at the middle, and each half into four steps of speed (x the keyboard's)
  SPEEDS: [0.35, 0.65, 0.95, 1.3],
  zone(x) { const mid = this.PW / 2, d = x - mid; return { dir: d < 0 ? -1 : 1, k: Math.min(3, Math.floor(Math.abs(d) / (mid / 4))) }; },
  drawLabels() { // after the vignette: never darkened. The wave, large in the middle of the control band; on a phone the speed steps either side
    const R = Render, mid = this.PW / 2, y = this.H - 22;
    R.text(`WAVE ${Math.min(this.wave, this.WAVES)} / ${this.WAVES}`, mid, y - 4, 30, 'rgba(255,255,255,0.5)', 'center');
    if (Input.touch) { const c = R.ctx, band = this.H * this.BAND; c.strokeStyle = 'rgba(155,240,106,0.14)'; c.lineWidth = 2; c.setLineDash([6, 8]);
      for (let k = 1; k <= 3; k++) for (const dir of [-1, 1]) { const x = mid + dir * k * mid / 4; c.beginPath(); c.moveTo(x, band + 6); c.lineTo(x, this.H - 6); c.stroke(); } c.setLineDash([]); // the steps' edges, down the control band
      c.strokeStyle = 'rgba(255,255,255,0.3)'; c.lineWidth = 4; c.lineCap = 'round'; c.lineJoin = 'round';
      for (let k = 1; k < 4; k++) for (const dir of [-1, 1]) { const n = k + 1, gap = 14, cx = mid + dir * (k + 0.5) * mid / 4; for (let i = 0; i < n; i++) { const x = cx + (i - (n - 1) / 2) * gap; c.beginPath(); c.moveTo(x - dir * 5, y - 11); c.lineTo(x + dir * 5, y); c.lineTo(x - dir * 5, y + 11); c.stroke(); } } } // ‹‹ ‹‹‹ ‹‹‹‹ outward, drawn (the slowest step sits under the WAVE label)
  },
  draw() {
    const R = Render, c = R.ctx, W = this.W, H = this.H, band = H * this.BAND;
    // the deck: dark metal, a grid, a glowing band at the bottom where you crawl
    c.fillStyle = '#0d1420'; c.fillRect(0, 0, W, H);
    c.strokeStyle = 'rgba(120,180,200,0.08)'; c.lineWidth = 1; for (let x = 0; x < W; x += 64) { c.beginPath(); c.moveTo(x, 0); c.lineTo(x, H); c.stroke(); } for (let y = 0; y < H; y += 64) { c.beginPath(); c.moveTo(0, y); c.lineTo(W, y); c.stroke(); }
    for (let i = 0; i < 40; i++) { const sx = (i * 173 + 50) % W, sy = (i * 97 + 20) % (band - 40); c.fillStyle = `rgba(255,255,255,${0.25 + 0.25 * Math.sin(G.time * 2 + i)})`; c.fillRect(sx, sy, 2, 2); } // portholes onto space
    c.fillStyle = 'rgba(155,240,106,0.06)'; c.fillRect(0, band, W, H - band); c.strokeStyle = 'rgba(155,240,106,0.35)'; c.setLineDash([10, 8]); c.lineWidth = 2; c.beginPath(); c.moveTo(0, band); c.lineTo(W, band); c.stroke(); c.setLineDash([]);
    // pickups
    for (const pk of this.pickups) {
      if (!pk.alive) continue; if (pk.loose && pk.rest > CFG.LOOSE.time - 3 && Math.floor(G.time * 8) % 2) continue; const y = pk.y + Math.sin(pk.bob) * 2;
      if (pk.kind === 'dot') { R.circle(pk.x, y, 7, '#ffe9a8', R.OUT, 1.5); }
      else if (pk.kind === 'ghost') { R.ghostIcon(pk.x, y, 11, '#7de0ff'); }
      else { const w = BULLETS[pk.id]; c.fillStyle = w.color; c.strokeStyle = pk.loose ? '#fff' : R.OUT; c.lineWidth = 3; c.beginPath(); for (let i = 0; i < 6; i++) { const a = i * TAU / 6 + pk.bob * 0.3; c.lineTo(pk.x + Math.cos(a) * 14, y + Math.sin(a) * 14); } c.closePath(); c.fill(); c.stroke(); Icons.tile(w, pk.x, y, 22, c); if (pk.loose) R.text('yours', pk.x, y - 22, 11, '#9bf06a', 'center'); }
    }
    // the UFO
    if (this.ufo) { // the tractor beam, from the saucer to the floor (it flickers)
      const u = this.ufo, a = 0.12 + 0.05 * Math.sin(G.time * 18), gr = c.createLinearGradient(0, u.y, 0, H);
      gr.addColorStop(0, `rgba(125,224,255,${a + 0.1})`); gr.addColorStop(1, `rgba(125,224,255,${a * 0.5})`);
      c.fillStyle = gr; c.beginPath(); c.moveTo(u.x - 12, u.y + 8); c.lineTo(u.x + 12, u.y + 8); c.lineTo(u.x + this.BEAM_W, H); c.lineTo(u.x - this.BEAM_W, H); c.closePath(); c.fill();
      for (let k = 0; k < 4; k++) { const y = H - ((G.time * 140 + k * (H - u.y) / 4) % (H - u.y - 20)), f = (y - u.y) / (H - u.y), hw = 12 + (this.BEAM_W - 12) * f; c.strokeStyle = `rgba(200,245,255,${0.4 * f})`; c.lineWidth = 2; c.beginPath(); c.moveTo(u.x - hw, y); c.lineTo(u.x + hw, y); c.stroke(); } // rings rising up the beam, narrowing as they go
    }
    if (this.ufo) { const u = this.ufo; c.fillStyle = 'rgba(125,224,255,0.25)'; c.beginPath(); c.ellipse(u.x, u.y + 6, u.r * 1.6, u.r * 0.5, 0, 0, TAU); c.fill(); R.circle(u.x, u.y - 6, u.r * 0.6, '#cfeeff', R.OUT, 2); c.fillStyle = '#7de0ff'; c.strokeStyle = R.OUT; c.lineWidth = 2.5; c.beginPath(); c.ellipse(u.x, u.y + 2, u.r * 1.4, u.r * 0.45, 0, 0, TAU); c.fill(); c.stroke(); for (let k = 0; k < 4; k++) { c.fillStyle = Math.floor(G.time * 6 + k) % 2 ? '#ff5cf0' : '#ffd166'; c.beginPath(); c.arc(u.x - u.r + k * (u.r * 2 / 3), u.y + 4, 2.5, 0, TAU); c.fill(); } }
    // a cow carrying a segment: the beam and the stolen tile
    for (const e of this.invaders) if (!e.dead && e.carry) { c.fillStyle = 'rgba(155,240,106,0.25)'; c.beginPath(); c.moveTo(e.x - 6, e.y - 400); c.lineTo(e.x + 6, e.y - 400); c.lineTo(e.x + 20, e.y + 24); c.lineTo(e.x - 20, e.y + 24); c.closePath(); c.fill(); const w = BULLETS[e.carry]; c.fillStyle = w.color; R.roundRect(e.x - 9, e.y + 14, 18, 18, 4); c.fill(); c.strokeStyle = '#fff'; c.lineWidth = 1.5; c.stroke(); }
  },
};
