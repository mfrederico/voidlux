// Pac-man style side-quest: grid maze, dots, ghosts. Clear all dots to open the perk shop; eaten ghosts are the currency.
const Dungeon = {
  cols: 0, rows: 0, cell: 0, ox: 0, oy: 0, grid: [], dots: [], pellets: [], dotsLeft: 0, ghosts: [],
  entrance: null, region: 0, fright: 0, dist: null, distT: 0, winT: 0, pen: null, returnPos: null, startCell: null,
  siphon: null, // the dungeon device (see CFG.SIPHON): { r, c, x, y, state: idle|on|spent, t, max, taken, order, motes }

  tier() { const r = this.region || 0; return CFG.DUNGEON_TIERS.find(t => r <= t.maxRegion) || CFG.DUNGEON_TIERS[CFG.DUNGEON_TIERS.length - 1]; },
  theme() { const L = World.levelOf(this.region || 0); return L >= 5 ? 'bigtop' : L === 4 ? 'ufo' : L === 3 ? 'temple' : L === 2 ? 'cathedral' : 'burrow'; }, // level 2 dungeons are cathedrals, level 3 Aztec temples, level 4 saucer decks, level 5 the big top
  name() { const t = this.theme(); return (t === 'cathedral' ? 'Cathedral ' : t === 'temple' ? 'Temple ' : t === 'ufo' ? 'Saucer ' : t === 'bigtop' ? 'Big Top ' : '') + this.tier().name; },
  playerSpeed() { return CFG.DUNGEON.PLAYER_SPEED + Math.max(0, this.cell - 40) * 0.6; }, // roomier cells, a touch faster
  center() { const Z = Input.touch && this.cols ? Math.max(1, Math.min(Settings.size(), (CFG.W - 30) / (this.cols * this.cell), (CFG.H - 90) / (this.rows * this.cell))) : 1; this.ox = (CFG.W / Z - this.cols * this.cell) / 2; this.oy = (CFG.H / Z - this.rows * this.cell) / 2 + (Z > 1 ? 10 : 0); }, // re-run when the screen width changes (the maze sits in the zoomed view on touch screens)
  isFloor(r, c) { return r >= 0 && c >= 0 && r < this.rows && c < this.cols && this.grid[r][c] !== 1; },
  cx(c) { return this.ox + (c + 0.5) * this.cell; },
  cy(r) { return this.oy + (r + 0.5) * this.cell; },
  cellOf(x, y) { return { c: Math.floor((x - this.ox) / this.cell), r: Math.floor((y - this.oy) / this.cell) }; },
  isWallAt(x, y) {
    const { r, c } = this.cellOf(x, y);
    if (r < 0 || c < 0 || r >= this.rows || c >= this.cols) return true;
    return this.grid[r][c] === 1;
  },

  gen() {
    const T = this.tier();
    this.cols = T.cols; this.rows = T.rows; this.cell = T.cell;
    this.center();
    const g = [];
    for (let r = 0; r < this.rows; r++) { g.push(new Array(this.cols).fill(1)); }
    // recursive backtracker on odd cells
    const stack = [[1, 1]]; g[1][1] = 0;
    while (stack.length) {
      const [r, c] = stack[stack.length - 1];
      const dirs = U.shuffle([[0, 2], [0, -2], [2, 0], [-2, 0]]);
      let found = false;
      for (const [dr, dc] of dirs) {
        const nr = r + dr, nc = c + dc;
        if (nr >= 1 && nc >= 1 && nr < this.rows - 1 && nc < this.cols - 1 && g[nr][nc] === 1) {
          g[r + dr / 2][c + dc / 2] = 0; g[nr][nc] = 0; stack.push([nr, nc]); found = true; break;
        }
      }
      if (!found) stack.pop();
    }
    // open extra walls for loops
    for (let r = 1; r < this.rows - 1; r++) for (let c = 1; c < this.cols - 1; c++) {
      if (g[r][c] !== 1) continue;
      const h = g[r][c - 1] === 0 && g[r][c + 1] === 0, v = g[r - 1][c] === 0 && g[r + 1][c] === 0;
      if ((h || v) && U.chance(this.tier().loops)) g[r][c] = 0;
    }
    // remove dead ends
    for (let r = 1; r < this.rows - 1; r++) for (let c = 1; c < this.cols - 1; c++) {
      if (g[r][c] !== 0) continue;
      const nb = [[0, 1], [0, -1], [1, 0], [-1, 0]].filter(([dr, dc]) => g[r + dr][c + dc] === 0);
      if (nb.length === 1) {
        const cand = U.shuffle([[0, 1], [0, -1], [1, 0], [-1, 0]]).filter(([dr, dc]) => {
          const wr = r + dr, wc = c + dc, fr = r + 2 * dr, fc = c + 2 * dc;
          return g[wr][wc] === 1 && fr >= 1 && fc >= 1 && fr < this.rows - 1 && fc < this.cols - 1 && g[fr][fc] === 0;
        });
        if (cand.length) g[r + cand[0][0]][c + cand[0][1]] = 0;
      }
    }
    // ghost pen in the middle
    const mr = Math.floor(this.rows / 2), mc = Math.floor(this.cols / 2);
    const penCells = new Set();
    for (let r = mr - 1; r <= mr + 1; r++) for (let c = mc - 2; c <= mc + 2; c++) { g[r][c] = 0; penCells.add(r + ',' + c); }
    this.pen = { r: mr, c: mc };
    // entrance at the bottom
    g[this.rows - 2][mc] = 0; g[this.rows - 1][mc] = 2;
    this.startCell = { r: this.rows - 2, c: mc };
    this.grid = g;
    // dots & pellets
    this.dots = []; this.pellets = [];
    const pelletSet = new Set([`1,1`, `1,${this.cols - 2}`, `${this.rows - 2},1`, `${this.rows - 2},${this.cols - 2}`]);
    for (let r = 0; r < this.rows; r++) { this.dots.push(new Array(this.cols).fill(0)); }
    this.dotsLeft = 0;
    for (let r = 1; r < this.rows - 1; r++) for (let c = 1; c < this.cols - 1; c++) {
      if (g[r][c] !== 0 || penCells.has(r + ',' + c) || (r === this.startCell.r && c === this.startCell.c)) continue;
      this.dots[r][c] = pelletSet.has(r + ',' + c) ? 2 : 1; this.dotsLeft++;
    }
    // the Siphon sits on a plain-dot cell deep in the maze: at least minDist of the longest corridor distance from the entrance
    this.siphon = null;
    const dmap = this.distFrom(this.startCell.r, this.startCell.c);
    let far = 0; for (let r = 0; r < this.rows; r++) for (let c = 0; c < this.cols; c++) far = Math.max(far, dmap[r][c]);
    const cands = [];
    for (let r = 1; r < this.rows - 1; r++) for (let c = 1; c < this.cols - 1; c++) if (this.dots[r][c] === 1 && dmap[r][c] >= far * CFG.SIPHON.minDist) cands.push({ r, c });
    if (cands.length) {
      const s = U.pick(cands); this.dots[s.r][s.c] = 0; this.dotsLeft--;
      this.siphon = { r: s.r, c: s.c, x: this.cx(s.c), y: this.cy(s.r), state: 'idle', t: 0, max: 0, taken: 0, acc: 0, interval: 1, order: [], motes: [], spin: 0, eaten: 0, need: this.tier().ghosts, runs: 0 };
    }
    // the Shovel sits on another plain-dot cell, a little way in
    this.shovel = null; this.dug = []; Player.digs = 0;
    const sc = [];
    for (let r = 1; r < this.rows - 1; r++) for (let c = 1; c < this.cols - 1; c++) if (this.dots[r][c] === 1 && dmap[r][c] >= far * CFG.SHOVEL.minDist && !(this.siphon && this.siphon.r === r && this.siphon.c === c)) sc.push({ r, c });
    if (sc.length) { const s = U.pick(sc); this.dots[s.r][s.c] = 0; this.dotsLeft--; this.shovel = { r: s.r, c: s.c, x: this.cx(s.c), y: this.cy(s.r), taken: false }; }
    // the big top: a Hammer on a dot cell, a high striker on another, and moles that pop up to steal dots
    this.hammer = null; this.striker = null; this.moles = []; this.moleT = CFG.MOLE.every * 0.6; Player.swings = 0; Player.hammerT = 0;
    if (this.theme() === 'bigtop') {
      const free = (r, c) => this.dots[r][c] === 1 && !(this.siphon && this.siphon.r === r && this.siphon.c === c) && !(this.shovel && this.shovel.r === r && this.shovel.c === c);
      const hc = []; for (let r = 1; r < this.rows - 1; r++) for (let c = 1; c < this.cols - 1; c++) if (free(r, c) && dmap[r][c] >= far * CFG.HAMMER.minDist) hc.push({ r, c });
      if (hc.length) { const s = U.pick(hc); this.dots[s.r][s.c] = 0; this.dotsLeft--; this.hammer = { r: s.r, c: s.c, x: this.cx(s.c), y: this.cy(s.r), taken: false }; }
      const kc = []; for (let r = 1; r < this.rows - 1; r++) for (let c = 1; c < this.cols - 1; c++) if (free(r, c) && !(this.hammer && this.hammer.r === r && this.hammer.c === c)) kc.push({ r, c });
      if (kc.length) { const s = U.pick(kc); this.dots[s.r][s.c] = 0; this.dotsLeft--; this.striker = { r: s.r, c: s.c, x: this.cx(s.c), y: this.cy(s.r), rung: false, t: 0 }; }
    }
  },
  // ---- the Hammer (big top): E swings it. It slams the cell above AND below you (and your own cell): any ghost there is eaten, scared or not; moles are flattened; the high striker rings ----
  endHammerMusic() { if (Music.name === 'hammer') { Music.stop(); if (this.hammerPrev) Music.start(this.hammerPrev); } this.hammerPrev = null; }, // the maze's own tune comes back
  swing() {
    const P = Player; if (G.mode !== 'dungeon' || !(P.hammerT > 0)) return false;
    this.swingT = 0.3;
    const cells = [[P.grow - 1, P.gcol], [P.grow, P.gcol], [P.grow + 1, P.gcol], [P.grow + P.gdir.y, P.gcol + P.gdir.x]]; // your cell, above, below, and the one ahead
    const inCells = (r, c) => cells.some(([cr, cc]) => cr === r && cc === c);
    let ate = 0, moles = 0;
    for (const gh of this.ghosts) { if (gh.dead || gh.hidden || gh.hold > 0) continue; const near = gh.prog > 0.5 ? { r: gh.row + gh.dir.y, c: gh.col + gh.dir.x } : { r: gh.row, c: gh.col }; if (inCells(gh.row, gh.col) || inCells(near.r, near.c)) { G.score += 60; G.ghosts++; ate++; Combat.burst(gh.x, gh.y, 18, gh.color, 3); for (let k = 0; k < 8; k++) Combat.particles.push(Combat.mk(gh.x, gh.y - 10, U.rand(-80, 80), U.rand(-140, -40), 0.7, '#ffd166', 3)); SFX.play('eat'); this.respawnGhost(gh, 6); this.feedSiphon(); } }
    for (let i = this.moles.length - 1; i >= 0; i--) { const m = this.moles[i]; if (inCells(m.r, m.c)) { this.moles.splice(i, 1); moles++; G.dots += CFG.MOLE.reward; G.score += 20; Combat.burst(m.x, m.y, 12, '#8a5a3a', 2); Combat.floaters.push({ x: m.x, y: m.y - 14, t: 0.8, text: `+${CFG.MOLE.reward} dots`, color: '#ffe9a8' }); SFX.play('mole'); } }
    if (this.striker && !this.striker.rung && inCells(this.striker.r, this.striker.c)) { const S = this.striker; S.rung = true; S.t = 2.5; SFX.play('bell'); G.ghosts += 1; G.dots += 6; this.fright = Math.max(this.fright, this.tier().fright); for (const gh of this.ghosts) gh.fright = true; G.banner = { text: 'DING!', sub: 'the bell rings: +1 ghost coin, +6 dots, every ghost is scared', t: 2 }; }
    if (ate || moles) { SFX.play('squeak'); G.shake = Math.max(G.shake, 0.3); G.msg(ate ? `WHAM! The hammer ate ${ate} ghost${ate > 1 ? 's' : ''}${moles ? ` and flattened ${moles} mole${moles > 1 ? 's' : ''}` : ''}. Ghosts to spend: ${G.ghosts}.` : `WHACK! ${moles} mole${moles > 1 ? 's' : ''} flattened.`, '#ffd166'); } // (it swings by itself: only a hit says so)
    return true;
  },
  // moles pop out of a dot cell, wait, then take the dot with them unless the hammer gets them first
  updateMoles(dt) {
    if (this.theme() !== 'bigtop') return;
    const K = CFG.MOLE;
    this.moleT -= dt;
    if (this.moleT <= 0 && this.moles.length < 3) {
      this.moleT = K.every;
      const cands = []; for (let r = 1; r < this.rows - 1; r++) for (let c = 1; c < this.cols - 1; c++) if (this.dots[r][c] === 1 && !this.moles.some(m => m.r === r && m.c === c) && U.dist(this.cx(c), this.cy(r), Player.x, Player.y) > this.cell * 2) cands.push({ r, c });
      if (cands.length) { const s = U.pick(cands); this.moles.push({ r: s.r, c: s.c, x: this.cx(s.c), y: this.cy(s.r), t: K.up, seed: Math.random() * 10 }); SFX.play('mole'); }
    }
    for (let i = this.moles.length - 1; i >= 0; i--) {
      const m = this.moles[i]; m.t -= dt;
      if (m.t <= 0) { this.moles.splice(i, 1); if (this.dots[m.r][m.c] === 1) { this.dots[m.r][m.c] = 0; this.dotsLeft--; Combat.burst(m.x, m.y, 6, '#8a5a3a', 2); G.msg('A mole made off with a dot.', '#aaa'); this.checkClear(); } }
    }
    if (this.swingT > 0) this.swingT -= dt;
    if (this.striker && this.striker.t > 0) this.striker.t -= dt;
  },
  // dig through an inner wall cell (the border and the exit row stay): costs one charge
  dig(r, c) {
    if (r < 1 || c < 1 || r >= this.rows - 1 || c >= this.cols - 1 || this.grid[r][c] !== 1 || Player.digs <= 0) return false;
    this.grid[r][c] = 0; this.dug.push({ r, c }); Player.digs--; this.distT = 0;
    const x = this.cx(c), y = this.cy(r);
    Combat.burst(x, y, 22, this.theme() === 'temple' ? '#9a8a58' : this.theme() === 'cathedral' ? '#6b6775' : '#5a46a8', 3); Combat.burst(x, y, 8, '#fff', 2); SFX.play('wall'); G.shake = Math.max(G.shake, 0.25);
    G.msg(Player.digs ? `Dug through! ${Player.digs} dig${Player.digs > 1 ? 's' : ''} left.` : 'Dug through! The shovel is worn out.', '#ffd166');
    return true;
  },
  // ---- the Siphon ----
  startSiphon() {
    const S = this.siphon; if (!S || S.state !== 'idle') return;
    let plain = 0; for (let r = 0; r < this.rows; r++) for (let c = 0; c < this.cols; c++) if (this.dots[r][c] === 1) plain++;
    if (!plain) { S.state = 'spent'; G.msg('The Siphon coughs: nothing left to pull.', '#aaa'); return; }
    const dmap = this.distFrom(S.r, S.c);
    S.order = [];
    for (let r = 0; r < this.rows; r++) for (let c = 0; c < this.cols; c++) if (this.dots[r][c] === 1 && dmap[r][c] >= 0) S.order.push({ r, c, d: dmap[r][c] + Math.random() * 0.5 });
    S.order.sort((a, b) => a.d - b.d);
    S.max = Math.max(1, Math.round(plain * CFG.SIPHON.share)); S.taken = 0; S.acc = 0; S.t = CFG.SIPHON.time; S.interval = CFG.SIPHON.time / S.max; S.state = 'on';
    this.distT = 0; // ghosts re-path to the noise right away
    G.msg(`THE SIPHON ROARS: it pulls in ${S.max} dot${S.max === 1 ? "" : "s"} over ${CFG.SIPHON.time}s, and every ghost hears it. Be somewhere else.`, '#ffb347');
    SFX.play('power'); G.shake = Math.max(G.shake, 0.3);
  },
  // the recharged Siphon becomes a mining cart: it rolls along the corridors to the floor cell with the most plain dots
  // within CFG.SIPHON.reach steps (big mazes have dots far from where it started), then settles and waits to be touched
  relocateSiphon() {
    const S = this.siphon, K = CFG.SIPHON;
    const dotCells = [];
    for (let r = 0; r < this.rows; r++) for (let c = 0; c < this.cols; c++) if (this.dots[r][c] === 1) dotCells.push({ r, c });
    if (!dotCells.length) { S.state = 'idle'; G.msg('The Siphon is RECHARGED. Touch it to run it again.', '#ffb347'); return; }
    // score every reachable floor cell by the dots within reach (a BFS from each dot cell, spreading its vote)
    const score = []; for (let r = 0; r < this.rows; r++) score.push(new Array(this.cols).fill(0));
    for (const d of dotCells) { const dm = this.distFrom(d.r, d.c); for (let r = 0; r < this.rows; r++) for (let c = 0; c < this.cols; c++) if (dm[r][c] >= 0 && dm[r][c] <= K.reach) score[r][c] += 1 - dm[r][c] / (K.reach + 1) * 0.5; }
    const from = this.distFrom(S.r, S.c);
    let best = null, bs = -1;
    for (let r = 1; r < this.rows - 1; r++) for (let c = 1; c < this.cols - 1; c++) {
      if (from[r][c] < 0 || this.grid[r][c] !== 0 || (r === this.startCell.r && c === this.startCell.c) || Math.abs(r - this.pen.r) <= 1 && Math.abs(c - this.pen.c) <= 2) continue;
      const v = score[r][c] - from[r][c] * 0.02; // a touch of preference for nearer spots when scores tie
      if (v > bs) { bs = v; best = { r, c }; }
    }
    if (!best || (best.r === S.r && best.c === S.c) || score[best.r][best.c] <= score[S.r][S.c] + 0.5) { S.state = 'idle'; G.msg('The Siphon is RECHARGED. Touch it to run it again.', '#ffb347'); return; }
    // path from here to there (BFS with parents)
    const prev = new Map(), q = [{ r: S.r, c: S.c }]; prev.set(S.r + ',' + S.c, null); let qi = 0;
    while (qi < q.length) {
      const n = q[qi++]; if (n.r === best.r && n.c === best.c) break;
      for (const [dr, dc] of [[0, 1], [0, -1], [1, 0], [-1, 0]]) { const r = n.r + dr, c = n.c + dc, k = r + ',' + c; if (this.isFloor(r, c) && this.grid[r][c] !== 2 && !prev.has(k)) { prev.set(k, n); q.push({ r, c }); } }
    }
    const path = []; for (let n = best; n; n = prev.get(n.r + ',' + n.c)) path.push(n); path.reverse();
    if (this.dots[best.r][best.c]) { this.dots[best.r][best.c] = 0; this.dotsLeft--; G.dots++; this.checkClear(); } // it parks on that dot (it may be the last one)
    S.state = 'moving'; S.path = path; S.pi = 0; S.prog = 0; S.target = best;
    G.msg(`The Siphon is RECHARGED and trundles off toward the dots (${path.length - 1} tiles). Touch it when it stops.`, '#ffb347');
  },
  updateSiphon(dt) {
    const S = this.siphon; if (!S) return;
    S.spin += dt * (S.state === 'on' ? 14 : S.state === 'idle' ? 0.8 : S.state === 'moving' ? 6 : 0);
    for (let i = S.motes.length - 1; i >= 0; i--) { S.motes[i].t -= dt; if (S.motes[i].t <= 0) S.motes.splice(i, 1); }
    if (S.state === 'moving') {
      S.prog += CFG.SIPHON.cartSpeed * dt;
      while (S.prog >= 1 && S.pi < S.path.length - 1) { S.prog -= 1; S.pi++; }
      const a = S.path[S.pi], b = S.path[Math.min(S.pi + 1, S.path.length - 1)], k = S.pi >= S.path.length - 1 ? 0 : S.prog;
      S.x = this.cx(a.c) + (this.cx(b.c) - this.cx(a.c)) * k; S.y = this.cy(a.r) + (this.cy(b.r) - this.cy(a.r)) * k;
      S.dir = { x: Math.sign(b.c - a.c), y: Math.sign(b.r - a.r) };
      if (U.chance(dt * 8)) Combat.burst(S.x, S.y + this.cell * 0.3, 1, '#c9c2a8', 1.5);
      if (S.pi >= S.path.length - 1) { S.r = S.target.r; S.c = S.target.c; S.x = this.cx(S.c); S.y = this.cy(S.r); S.state = 'idle'; S.path = null; G.msg('The Siphon settles by the dots. Touch it to run it again.', '#ffb347'); SFX.play('pellet'); }
      return;
    }
    if (S.state !== 'on') return;
    S.t -= dt; S.acc += dt;
    while (S.acc >= S.interval && S.taken < S.max) {
      S.acc -= S.interval;
      let cell = null; while (S.order.length && !cell) { const n = S.order.shift(); if (this.dots[n.r][n.c] === 1) cell = n; }
      if (!cell) break;
      this.dots[cell.r][cell.c] = 0; this.dotsLeft--; G.dots++; G.score++; S.taken++;
      S.motes.push({ x: this.cx(cell.c), y: this.cy(cell.r), t: 0.45, d: 0.45 });
      if (U.chance(0.4)) SFX.play('dot');
      this.checkClear();
    }
    const left = S.order.some(n => this.dots[n.r][n.c] === 1);
    if (S.t <= 0 || S.taken >= S.max || !left) { S.state = 'spent'; S.runs++; S.eaten = 0; this.distT = 0; G.msg(`The Siphon winds down: ${S.taken} dot${S.taken === 1 ? '' : 's'} pulled in. Eat ${S.need} ghost${S.need > 1 ? 's' : ''} to recharge it.`, '#ffb347'); }
  },
  // eating a ghost feeds the spent Siphon: as many ghosts as the maze holds and it is ready to run again
  feedSiphon() {
    const S = this.siphon; if (!S || S.state !== 'spent') return;
    S.eaten++;
    if (S.eaten >= S.need) { S.eaten = 0; SFX.play('power'); this.relocateSiphon(); }
    else G.msg(`The Siphon stirs: ${S.need - S.eaten} more ghost${S.need - S.eaten > 1 ? 's' : ''} to recharge it.`, '#ffb347');
  },

  enter(entrance) {
    SFX.play('enter');
    this.entrance = entrance; this.region = entrance.region;
    this.returnPos = { x: entrance.x, y: entrance.y };
    this.gen();
    this.fright = 0; this.winT = 0; this.distT = 0; this.exitT = 3; // the exit is shut for a moment, so a push down on arrival does not throw you straight back out
    Combat.reset();
    G.mode = 'dungeon';
    G.enemies = this.ghosts = [];
    const P = Player;
    P.gcol = this.startCell.c; P.grow = this.startCell.r; P.gdir = { x: 0, y: -1 }; P.gprog = 0; P.want = null; P.wantT = 0; P.stopped = false; this.stopHint = false;
    P.x = this.cx(P.gcol); P.y = this.cy(P.grow); P.angle = -Math.PI / 2; P.invuln = 1.0;
    P.firing = false; // a maze starts with auto-fire OFF (FIRE turns it on; exit() turns it back on outside)
    P.initTrail(P.x, P.y, -Math.PI / 2, true);
    P.computeSegPos();
    const n = this.tier().ghosts;
    const th = this.theme(), colors = th === 'cathedral' ? ['#dfe6ff', '#c9b8ff', '#bfe9ff', '#ffe0f0', '#e0ffe8', '#fff3c4'] : th === 'temple' ? ['#5fd9a8', '#ffd166', '#ff5c5c', '#4ad0e0', '#f3a0ff', '#ffb347'] : th === 'ufo' ? ['#b7d9a8', '#ff5cf0', '#7df9ff', '#c9ced6', '#9bf06a', '#ffd166'] : th === 'bigtop' ? ['#ff5c8a', '#ffd166', '#7df9ff', '#9bf06a', '#f3c6ff', '#ff8a3d'] : ['#ff6b9d', '#ffb347', '#7de0ff', '#c58aff', '#8af0a0'];
    for (let i = 0; i < n; i++) this.spawnGhost(i, colors[i % colors.length], 2 + i * 2.5);
    Minions.enterDungeon();
    { this.prevTheme = Music.on ? Music.name : null; const L = World.levelOf(this.region || 0), mt = ['maze_level' + L, 'dungeon_level' + L, 'maze_' + th, 'maze', 'dungeon'].find(n => Music.THEMES[n]); this.tune = mt || null; if (mt) Music.start(mt); } // the maze's own tune: maze_level1..5 (or dungeon_level1..5) for that level's mazes, else maze_burrow / maze_cathedral / maze_temple / maze_bigtop, else maze (or dungeon) for all of them
    if (G.god) { Player.digs = 9; if (th === 'bigtop') Player.hammerT = 999; } // god mode: a shovel (and a hammer) from the start (testing)
    const B = this.briefing(); if (!Settings.dungeonCard(B)) B.lines.forEach(([t, col], i) => G.msg(i ? t : `${B.name.toUpperCase()}: ${t}`, col)); // the get-ready card, or (cards off) the same words as messages
  },
  // what to know on the way in: the get-ready card's lines (or the messages when it is off)
  briefing() {
    const n = this.tier().ghosts, lines = [['Eat every dot to open the perk shop.', '#ffd166'], [`A wall stops you here: pick a direction. ${n} ghost${n > 1 ? 's' : ''} bite.`, '#fff']];
    if (this.siphon) lines.push(['A SIPHON hums deep in the maze: touch it and it vacuums dots for you. Every ghost will hear it.', '#ffb347']);
    if (this.shovel) lines.push([`A SHOVEL lies in the maze: grab it and push into walls to dig through (${CFG.SHOVEL.digs} digs).`, '#ffd166']);
    if (this.hammer) lines.push([`A HAMMER lies in the ring: grab it and for ${CFG.HAMMER.time} seconds it swings by itself, eating any ghost in reach, scared or not. Moles steal dots: smash them.`, '#ff5c8a']);
    return { name: this.name(), color: '#9bd0ff', lines };
  },
  spawnGhost(i, color, hold) {
    const hp = 18 + 6 * Enemies.hpScale(this.region);
    const gh = {
      kind: 'ghost', color, dark: '#333', x: 0, y: 0, col: this.pen.c + (i % 3) - 1, row: this.pen.r, dir: { x: 0, y: -1 }, prog: 0,
      speed: this.playerSpeed() * this.tier().ghostSpeed, hp, maxhp: hp, st: {}, biteCd: 0, hold, dead: false, flash: 0, r: 13, fright: false, seed: Math.random() * 10, hidden: true,
      hitCircles() { return this.hidden ? [] : [{ x: this.x, y: this.y, r: this.r }]; },
      onDeath() { G.kills++; G.score += 30 * (Dungeon.region + 1); Dungeon.respawnGhost(this, 5); },
    };
    gh.x = this.cx(gh.col); gh.y = this.cy(gh.row);
    this.ghosts.push(gh);
  },
  respawnGhost(gh, hold) {
    // ghosts are reused: reset to the pen after a hold
    gh.dead = false; gh.hidden = true; gh.hold = hold; gh.col = this.pen.c; gh.row = this.pen.r; gh.prog = 0; gh.st = {}; gh.hp = gh.maxhp; gh.fright = false;
    gh.x = this.cx(gh.col); gh.y = this.cy(gh.row);
    if (!this.ghosts.includes(gh)) this.ghosts.push(gh);
  },
  exit(success) {
    if (Player.hammerT > 0) { Player.hammerT = 0; this.endHammerMusic(); } // the hammer stays in the big top
    if (this.tune && Music.name === this.tune) { Music.stop(); const th = this.prevTheme || Music.levelTheme(G.level); if (th) Music.start(th); } // the level's tune comes back (after a victory song: when the perk shop closes)
    const P = Player;
    G.mode = 'world';
    G.enemies = Enemies.list;
    Combat.reset();
    P.x = this.returnPos.x; P.y = this.returnPos.y + 40; P.angle = Math.PI / 2; P.invuln = 1.6;
    P.ready = false; P.reorient = true; // the world holds still until you press a direction: look around, then go
    P.initTrail(P.x, P.y, P.angle); P.computeSegPos(); P.reorientText = 'Back outside';
    G.snapCam(); // the camera is already on the cave: no scroll across the map
    this.entrance.cd = 2.5; // no instant re-entry
    if (!P.firing) { P.firing = true; G.msg('Weapons back ON', '#9bf06a'); } // holding fire is a dungeon trick: nobody should walk out unarmed
    if (success) {
      this.entrance.used = true;
      G.dungeonsCleared++; G.score += 500 * (this.region + 1);
      P.cleanse(); P.rebuild();
      Perks.offer(); // pause and let the player pick a perk
    } else { this.entrance.used = true; G.msg('You left early: the dungeon has closed behind you. No perk, no way back in.', '#ff9b8b'); } // leaving early closes it for good
    Save.write(); // checkpoint (before the perk shop; the shop saves again when it closes)
  },

  // STAY on the exit's question: turn round, back up into the maze (the exit shuts for a moment so a held push down does not ask again)
  stay() {
    const P = Player; P.gdir = { x: 0, y: -1 }; P.gprog = 0; P.want = null; P.wantT = 0; P.stopped = false; P.angle = -Math.PI / 2;
    this.exitT = 1.5; P.invuln = Math.max(P.invuln, 1);
  },

  // ---- player grid movement ----
  movePlayer(dt) {
    const P = Player, C = this.cell;
    const raw = Input.axis(); let ax = { x: Math.abs(raw.x) > 0.5 ? Math.sign(raw.x) : 0, y: Math.abs(raw.y) > 0.5 ? Math.sign(raw.y) : 0 }; // the grid only knows whole steps (the autopilot's overworld vector is a float)
    const touchy = Input.touch && !Bot.on; // a phone: the stick reads four directions biased toward turning, and a swipe queues a turn
    if (touchy && Input.joy) ax = Input.mazeDir(P.gdir) || { x: 0, y: 0 };
    if (touchy && Input.swipeDir) { ax = Input.swipeDir; Input.swipeDir = null; }
    if (!ax.x && !ax.y) { const k = Input.pressed; if (k.KeyW || k.ArrowUp) ax.y = -1; else if (k.KeyS || k.ArrowDown) ax.y = 1; else if (k.KeyA || k.ArrowLeft) ax.x = -1; else if (k.KeyD || k.ArrowRight) ax.x = 1; } // a tap shorter than a frame still counts as a turn request
    if (P.gcol !== Math.round(P.gcol) || P.grow !== Math.round(P.grow)) { P.gcol = Math.round(P.gcol); P.grow = Math.round(P.grow); P.gprog = 0; }
    if (!this.isFloor(P.grow, P.gcol)) { // a safety net: never stand inside a wall (nothing in play should put you there, but if anything does, hop to the nearest open cell)
      let best = null, bd = 1e9; for (let r = 0; r < this.rows; r++) for (let c = 0; c < this.cols; c++) if (this.isFloor(r, c)) { const d = (r - P.grow) ** 2 + (c - P.gcol) ** 2; if (d < bd) { bd = d; best = { r, c }; } }
      if (best) { P.grow = best.r; P.gcol = best.c; P.gprog = 0; P.stopped = true; P.x = this.cx(best.c); P.y = this.cy(best.r); P.initTrail(P.x, P.y, P.angle, true); P.computeSegPos(); }
    }
    if (ax.x || ax.y) {
      if (ax.x && ax.y) P.want = P.gdir.x !== 0 ? { x: 0, y: ax.y } : { x: ax.x, y: 0 };
      else P.want = { x: ax.x, y: ax.y };
      P.wantT = touchy ? CFG.DUNGEON.TOUCH_GRACE : CFG.DUNGEON.WANT_GRACE; // (a phone: a turn asked early waits for its junction)
    } else if (P.want) { P.wantT = (P.wantT || 0) - dt; if (P.wantT <= 0) P.want = null; } // a released request is forgotten soon: no surprise turns at a junction seconds later
    // reverse any time
    if (P.want && P.want.x === -P.gdir.x && P.want.y === -P.gdir.y) {
      const nc = P.gcol + P.gdir.x, nr = P.grow + P.gdir.y;
      if (P.gprog > 0) { P.gcol = nc; P.grow = nr; P.gprog = 1 - P.gprog; }
      P.gdir = P.want; P.want = null;
    }
    let rem = this.playerSpeed() * P.speedMult * G.mods.speed * dt / C;
    let guard = 0;
    while (rem > 0 && guard++ < 4) {
      const openAt = (r0, c0, d) => { const r = r0 + d.y, c = c0 + d.x; return this.isFloor(r, c) && !(this.exitT > 0 && this.grid[r][c] === 2); }; // (the exit counts as a wall while it is shut)
      const open = (d) => openAt(P.grow, P.gcol, d);
      if (P.want && P.gprog > 0 && (P.want.x !== P.gdir.x || P.want.y !== P.gdir.y)) { // cornering: a sideways press near a corner snaps to it and turns now, instead of waiting for the exact centre
        const K = CFG.DUNGEON.CORNER;
        if (P.gprog <= K && open(P.want)) P.gprog = 0; // just past a corner: step back onto it
        else if (P.gprog >= 1 - K) { // nearly at the next one: step onto it
          const nr = P.grow + P.gdir.y, nc = P.gcol + P.gdir.x;
          if (openAt(nr, nc, P.want)) { P.gcol = nc; P.grow = nr; P.gprog = 0; this.arrive(nr, nc); if (G.mode !== 'dungeon') return; }
        }
      }
      if (P.gprog === 0) {
        // at a cell center: choose direction (with a shovel, pushing into an inner wall digs it open)
        if (P.want && !open(P.want) && P.digs > 0 && !Bot.on && this.dig(P.grow + P.want.y, P.gcol + P.want.x)) { /* now open (digging is a human decision: the autopilot's wall pushes are just BFS jitter) */ }
        if (P.want && open(P.want)) { P.gdir = P.want; P.want = null; P.stopped = false; }
        if (!open(P.gdir)) { // a wall: STOP here and wait for a direction that is open (no auto-turning: you get a moment to look and choose)
          if (!P.stopped) { P.stopped = true; if (!this.stopHint) { this.stopHint = true; G.msg('A wall. Pick a direction: the maze waits while you look.', '#ccc'); } }
          break;
        }
      }
      const step = Math.min(rem, 1 - P.gprog);
      P.gprog += step; rem -= step;
      if (P.gprog >= 1 - 1e-6) {
        P.gcol += P.gdir.x; P.grow += P.gdir.y; P.gprog = 0;
        this.arrive(P.grow, P.gcol);
        if (G.mode !== 'dungeon') return;
      }
    }
    P.x = this.cx(P.gcol) + P.gdir.x * P.gprog * C;
    P.y = this.cy(P.grow) + P.gdir.y * P.gprog * C;
    P.angle = Math.atan2(P.gdir.y, P.gdir.x);
  },
  arrive(r, c) {
    if (this.grid[r][c] === 2) { if (Bot.on) this.exit(false); else { G.discover = { tip: 'leave', sel: 1, t: 0 }; G.state = 'discover'; SFX.play('tab'); } return; } // the exit asks first: there is no going back (STAY is picked, so a stray tap stays)
    if (this.dots[r][c]) this.eatDot(r, c, false);
    if (this.siphon && this.siphon.state === 'idle' && r === this.siphon.r && c === this.siphon.c) this.startSiphon();
    if (this.shovel && !this.shovel.taken && r === this.shovel.r && c === this.shovel.c) { this.shovel.taken = true; Player.digs += CFG.SHOVEL.digs; SFX.play('pickup'); Combat.burst(this.shovel.x, this.shovel.y, 12, '#ffd166', 2); G.msg(`SHOVEL: ${Player.digs} digs. Push into an inner wall to dig straight through it.`, '#ffd166'); }
    if (this.hammer && !this.hammer.taken && r === this.hammer.r && c === this.hammer.c) { this.hammer.taken = true; Player.hammerT = CFG.HAMMER.time; this.autoT = 0; SFX.play('pickup'); SFX.play('squeak'); Combat.burst(this.hammer.x, this.hammer.y, 12, '#ff5c8a', 2); G.banner = { text: 'HAMMER TIME!', sub: `${CFG.HAMMER.time} seconds: it swings by itself, smash everything`, t: 1.6 }; this.hammerPrev = Music.on ? Music.name : null; if (Music.THEMES.hammer) Music.start('hammer'); G.msg('HAMMER! It swings by itself: ghosts in reach are eaten, blue or not, moles are flattened, and the high striker rings as you pass.', '#ff5c8a'); }
    for (let i = this.moles.length - 1; i >= 0; i--) if (this.moles[i].r === r && this.moles[i].c === c) { this.moles.splice(i, 1); Combat.burst(this.cx(c), this.cy(r), 5, '#8a5a3a', 2); } // walking onto a mole just sends it back down
  },
  // eat the dot at (r,c): by you, or by a foraging minion (minions only ever take plain dots)
  eatDot(r, c, byMinion) {
    const d = this.dots[r][c]; if (!d) return;
    this.dots[r][c] = 0; this.dotsLeft--;
    G.dots += d === 2 ? 5 : 1; // dots are crafting currency
    if (d === 2) { this.fright = this.tier().fright; G.score += 10; SFX.play('pellet'); for (const gh of this.ghosts) gh.fright = true; G.msg('Ghosts are scared! Eat them!', '#7de0ff'); }
    else { G.score += 1; if (!byMinion || U.chance(0.3)) SFX.play('dot'); }
    this.checkClear();
  },
  // recount from the board rather than trust the running tally: any path that eats a dot without calling this (the Siphon parking on
  // the last one did) would otherwise leave an empty maze that never ends. update() also calls it once a second as a backstop.
  checkClear() { let n = 0; for (const row of this.dots) for (const d of row) if (d) n++; this.dotsLeft = n; if (this.dotsLeft <= 0 && !this.winT) { this.winT = 1.6; G.banner = { text: 'ALL DOTS!', sub: 'Super power-up incoming', t: 1.6 }; if (Music.victory(World.levelOf(this.region || 0))) Music.stop(); else SFX.play('cleared'); } }, // the victory song (the maze tune stops for it), or the chime

  // ---- ghosts ----
  // corridor distance from (r0,c0) to every floor cell (-1 = unreachable; the exit cell is never entered)
  distFrom(r0, c0) {
    const dist = [];
    for (let r = 0; r < this.rows; r++) dist.push(new Array(this.cols).fill(-1));
    const q = [[r0, c0]]; dist[r0][c0] = 0;
    let qi = 0;
    while (qi < q.length) {
      const [r, c] = q[qi++];
      for (const [dr, dc] of [[0, 1], [0, -1], [1, 0], [-1, 0]]) {
        const nr = r + dr, nc = c + dc;
        if (this.isFloor(nr, nc) && this.grid[nr][nc] !== 2 && dist[nr][nc] < 0) { dist[nr][nc] = dist[r][c] + 1; q.push([nr, nc]); }
      }
    }
    return dist;
  },
  // what the ghosts home in on: you, or the Siphon while it runs
  bfs() {
    const S = this.siphon, src = S && S.state === 'on' ? { r: S.r, c: S.c } : { r: Player.grow, c: Player.gcol };
    this.dist = this.distFrom(src.r, src.c);
  },
  update(dt) {
    const P = Player;
    this.updateSiphon(dt); this.updateMoles(dt); if (this.exitT > 0) this.exitT -= dt;
    if (P.hammerT > 0) { // hammer time: it swings by itself, Donkey Kong style
      P.hammerT -= dt; this.autoT = (this.autoT || 0) - dt;
      if (this.autoT <= 0) { this.autoT = CFG.HAMMER.every; this.swing(); }
      if (P.hammerT <= 0) { P.hammerT = 0; this.endHammerMusic(); G.msg('The hammer is spent.', '#aaa'); }
    }
    this.clearT = (this.clearT || 0) - dt; if (this.clearT <= 0) { this.clearT = 1; this.checkClear(); }
    if (this.winT) { this.winT -= dt; if (this.winT <= 0) { this.winT = 0; this.exit(true); } return; }
    this.fright -= dt;
    if (this.fright <= 0) for (const gh of this.ghosts) gh.fright = false;
    this.distT -= dt;
    if (this.distT <= 0 || !this.dist) { this.distT = 0.25; this.bfs(); }
    const C = this.cell;
    for (const gh of this.ghosts) {
      gh.flash -= dt; gh.biteCd -= dt;
      if (gh.hold > 0) { gh.hold -= dt; gh.hidden = gh.hold > 0.6; continue; }
      gh.hidden = false;
      Combat.tickStatus(gh, dt);
      if (gh.dead) continue;
      if (!gh.st.freeze) {
        let rem = gh.speed * (gh.fright ? 0.55 : 1) * dt / C, guard = 0;
        while (rem > 0 && guard++ < 4) {
          if (gh.prog === 0) {
            const opts = [{ x: 0, y: 1 }, { x: 0, y: -1 }, { x: 1, y: 0 }, { x: -1, y: 0 }].filter(d => this.isFloor(gh.row + d.y, gh.col + d.x) && this.grid[gh.row + d.y][gh.col + d.x] !== 2);
            const fwd = opts.filter(d => !(d.x === -gh.dir.x && d.y === -gh.dir.y));
            const pool = fwd.length ? fwd : opts;
            if (!pool.length) break;
            const score = d => { const v = this.dist[gh.row + d.y][gh.col + d.x]; return v < 0 ? 999 : v; };
            pool.sort((a, b) => score(a) - score(b));
            if (gh.fright) gh.dir = U.chance(0.75) ? pool[pool.length - 1] : U.pick(pool);
            else gh.dir = U.chance(0.85) ? pool[0] : U.pick(pool);
          }
          const step = Math.min(rem, 1 - gh.prog);
          gh.prog += step; rem -= step;
          if (gh.prog >= 1 - 1e-6) { gh.col += gh.dir.x; gh.row += gh.dir.y; gh.prog = 0; }
        }
      }
      gh.x = this.cx(gh.col) + gh.dir.x * gh.prog * C;
      gh.y = this.cy(gh.row) + gh.dir.y * gh.prog * C;
      // contact
      if (!P.alive) continue;
      if (U.dist2(gh.x, gh.y, P.x, P.y) < (gh.r + CFG.HEAD_R) ** 2) {
        if (gh.fright) { G.score += 50; G.ghosts++; G.msg(`Ate a ghost! Ghosts to spend: ${G.ghosts}`, '#7de0ff'); SFX.play('eat'); Combat.burst(gh.x, gh.y, 16, gh.color, 3); this.respawnGhost(gh, 6); this.feedSiphon(); }
        else if (P.invuln <= 0) { P.hit(2, 'a ghost'); this.respawnGhost(gh, 2.5); }
        continue;
      }
      if (!gh.fright && gh.biteCd <= 0 && P.invuln <= 0) {
        for (let j = 0; j < P.segPos.length; j++) {
          const s = P.segPos[j];
          if (U.dist2(gh.x, gh.y, s.x, s.y) < (gh.r + CFG.SEG_R) ** 2) { P.removeAt(j, 'ghost'); gh.biteCd = 2.5; G.msg('A ghost bit off a segment!', '#ff9b8b'); break; }
        }
      }
    }
  }
};
