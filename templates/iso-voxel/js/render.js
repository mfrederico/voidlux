// Drawing: the sky, the isometric town (ground, then houses, trees, hedges and the cat sorted back to front), and the HUD.
// Every block is a sprite drawn once into the atlas and stamped; nothing here changes the game. Screen position of a tile
// point is sx = (x - y) * tw/2, sy = (x + y) * th/2; blocks stand on that point and rise by h * unit pixels.
const Render = {
  sx: 0, sy: 0, ents: [],
  /* project a tile-space point to screen (world-pixel) space into Render.sx / Render.sy, no allocation */
  iso(x, y) { this.sx = (x - y) * CFG.ISO.tw / 2; this.sy = (x + y) * CFG.ISO.th / 2; },
  /* multiply a #rrggbb colour's brightness by k, to light a cube's top and two sides */
  shade(hex, k) { const n = parseInt(hex.slice(1), 16); return `rgb(${Math.min(255, (n >> 16) * k) | 0},${Math.min(255, ((n >> 8) & 255) * k) | 0},${Math.min(255, (n & 255) * k) | 0})`; },
  /* a cube whose base diamond is centred on x, y: hw and hh the diamond's half width and height, h the rise in px */
  cube(g, x, y, hw, hh, h, base) {
    g.fillStyle = this.shade(base, 0.86); g.beginPath(); g.moveTo(x - hw, y); g.lineTo(x, y + hh); g.lineTo(x, y + hh - h); g.lineTo(x - hw, y - h); g.fill();
    g.fillStyle = this.shade(base, 0.68); g.beginPath(); g.moveTo(x, y + hh); g.lineTo(x + hw, y); g.lineTo(x + hw, y - h); g.lineTo(x, y + hh - h); g.fill();
    g.fillStyle = this.shade(base, 1.08); g.beginPath(); g.moveTo(x, y - hh - h); g.lineTo(x + hw, y - h); g.lineTo(x, y + hh - h); g.lineTo(x - hw, y - h); g.fill();
  },
  /* a rectangle on a cube's side: face 'l' faces +y (lower left), 'r' faces +x (lower right); u runs along the face 0..1, v up 0..1 of height h */
  face(g, f, x, y, hw, hh, h, u0, v0, u1, v1, col) {
    const P = (u, v) => f === 'l' ? [x - hw + u * hw, y + u * hh - v * h] : [x + u * hw, y + hh - u * hh - v * h];
    g.fillStyle = col; g.beginPath(); let p = P(u0, v0); g.moveTo(p[0], p[1]); p = P(u1, v0); g.lineTo(p[0], p[1]); p = P(u1, v1); g.lineTo(p[0], p[1]); p = P(u0, v1); g.lineTo(p[0], p[1]); g.fill();
  },
  /* flat ground: a thin slab so the town reads as blocks, with road markings drawn on the top */
  ground(ch, tx, ty) {
    const T = TILES[ch], hw = CFG.ISO.tw / 2, hh = CFG.ISO.th / 2, alt = (tx + ty) & 1, key = 'gr:' + ch + (T.alt ? alt : '') + (T.road === 'x' ? '' : '');
    return Draw.sprite(key, 32, g => {
      const col = T.alt && alt ? T.alt : T.color; this.cube(g, 0, 7, hw + 0.5, hh + 0.25, 7, col);
      if (T.road === 'ew' || T.road === 'ns') { /* a dashed lane line along the road's length */
        g.strokeStyle = '#f4e9a8'; g.lineWidth = 2; g.setLineDash([6, 8]); g.beginPath();
        if (T.road === 'ew') { g.moveTo(-hw * 0.5, -hh * 0.5); g.lineTo(hw * 0.5, hh * 0.5); } else { g.moveTo(hw * 0.5, -hh * 0.5); g.lineTo(-hw * 0.5, hh * 0.5); }
        g.stroke(); g.setLineDash([]);
      }
      if (T.cushion) { this.cube(g, 0, 2, 13, 6.5, 6, T.cushion); g.fillStyle = this.shade(T.cushion, 1.25); g.beginPath(); g.arc(0, -8, 2, 0, TAU); g.fill(); }
    });
  },
  /* a house tile: a pastel wall with windows (and a door when T.door) under an overhanging roof slab */
  house(ch) {
    const T = TILES[ch], hw = CFG.ISO.tw / 2, hh = CFG.ISO.th / 2, h = T.h * CFG.ISO.unit;
    return Draw.sprite('bl:' + ch, 96, g => {
      this.cube(g, 0, 0, hw, hh, h, T.color);
      this.face(g, 'l', 0, 0, hw, hh, h, 0.22, 0.45, 0.78, 0.8, '#bfe6ff'); this.face(g, 'r', 0, 0, hw, hh, h, 0.22, 0.45, 0.78, 0.8, '#9cd0ee');
      if (T.door) this.face(g, 'l', 0, 0, hw, hh, h, 0.3, 0, 0.7, 0.62, '#8a5a3c');
      this.cube(g, 0, -h, hw + 2, hh + 1, 9, T.roof);
    });
  },
  /* a tree: a stubby trunk under two stacked leaf cubes */
  tree(ch) {
    const T = TILES[ch];
    return Draw.sprite('bl:' + ch, 96, g => {
      this.cube(g, 0, 0, 7, 3.5, 20, T.trunk); this.cube(g, 0, -16, 27, 13.5, 28, T.color); this.cube(g, 0, -44, 17, 8.5, 16, '#62cf80');
    });
  },
  /* a hedge: a low green cube with a few pink blossoms on top */
  hedge(ch) {
    const T = TILES[ch], hw = CFG.ISO.tw / 2 - 3, hh = CFG.ISO.th / 2 - 1.5, h = T.h * CFG.ISO.unit;
    return Draw.sprite('bl:' + ch, 48, g => {
      this.cube(g, 0, 0, hw, hh, h, T.color); g.fillStyle = '#ffb3d9';
      for (const p of [[-10, -h], [6, -h - 3], [14, -h + 2], [-2, -h + 5]]) { g.beginPath(); g.arc(p[0], p[1], 2, 0, TAU); g.fill(); }
    });
  },
  block(ch) { const T = TILES[ch]; return T.trunk ? this.tree(ch) : T.roof ? this.house(ch) : this.hedge(ch); },
  /* the cat, as voxels: a tail, a body cube and a big head cube with ears; (fx, fy) is which way it faces on screen, eyes show when it looks toward us */
  catSprite(fx, fy) {
    return Draw.sprite('cat:' + fx + fy, 48, g => {
      const fur = '#f6a85f', pale = '#fff1d6', tail = () => this.cube(g, -fx * 14, 0 - fy * 6 - 6, 4, 2, 11, fur);
      if (fy > 0) tail();
      this.cube(g, 0, -1, 11, 5.5, 10, fur);
      const hx = fx * 4, hy = -10 + fy * 2, hw = 14, hh = 7, hgt = 13;
      this.cube(g, hx, hy, hw, hh, hgt, fur);
      g.fillStyle = fur; for (const s of [-1, 1]) { g.beginPath(); g.moveTo(hx + s * 11 - 5, hy - hgt - 3); g.lineTo(hx + s * 11 + 5, hy - hgt - 3); g.lineTo(hx + s * 11, hy - hgt - 13); g.fill(); g.fillStyle = '#ff9fb3'; g.beginPath(); g.moveTo(hx + s * 11 - 2.5, hy - hgt - 3); g.lineTo(hx + s * 11 + 2.5, hy - hgt - 3); g.lineTo(hx + s * 11, hy - hgt - 9); g.fill(); g.fillStyle = fur; }
      if (fy > 0) { /* the face: two eyes, a nose, a pale muzzle */
        const f = fx > 0 ? 'r' : 'l';
        this.face(g, f, hx, hy, hw, hh, hgt, 0.2, 0.3, 0.8, 0.55, pale);
        for (const u of [0.28, 0.62]) this.face(g, f, hx, hy, hw, hh, hgt, u, 0.55, u + 0.1, 0.8, '#2b2233');
        this.face(g, f, hx, hy, hw, hh, hgt, 0.43, 0.43, 0.53, 0.5, '#ff7f9a');
      } else { g.fillStyle = this.shade(fur, 0.8); g.beginPath(); g.moveTo(hx - 6, hy - hgt - 1); g.lineTo(hx, hy - hgt - 4); g.lineTo(hx + 6, hy - hgt - 1); g.lineTo(hx, hy - hgt + 2); g.fill(); tail(); }
    });
  },
  cat(c) {
    const C = World.cat, hop = C.lunge > 0 ? Math.sin(Math.min(1, (1 - C.lunge / CFG.CAT.lungeTime)) * Math.PI) * 16 : C.moving ? Math.abs(Math.sin(C.hop)) * CFG.CAT.hop * (C.wind > 0 ? 0.4 : 1) : 0;
    this.iso(C.x, C.y); if (C.inv > 0 && Math.floor(C.inv * 12) % 2) return; /* blink while safe */
    c.fillStyle = 'rgba(0,0,0,0.22)'; c.beginPath(); c.ellipse(this.sx, this.sy, 16, 8, 0, 0, TAU); c.fill();
    Draw.stamp(this.catSprite(C.fx, C.fy), this.sx, this.sy - hop, 0);
    if (C.spot > 0) { const x = this.sx, y = this.sy - 64 - hop; c.strokeStyle = 'rgba(0,0,0,0.35)'; c.lineWidth = 6; c.beginPath(); c.arc(x, y, 12, 0, TAU); c.stroke(); c.strokeStyle = C.spot >= 1 ? '#ff3030' : '#b46bff'; c.beginPath(); c.arc(x, y, 12, -Math.PI / 2, -Math.PI / 2 + TAU * C.spot); c.stroke(); Draw.text('!', x, y + 6, 16, '#fff', 'center'); }
    if (C.hidden) { Draw.text('zzz', this.sx, this.sy - 50, 14, '#fff', 'center'); Draw.bar(this.sx - 20, this.sy - 40, 40, 6, 1 - C.hideT / CFG.WITCH.hideSecs, '#7a5cd6'); }
    if (C.wind > 0) Draw.text('huff...', this.sx, this.sy - 50, 14, '#5a3b2e', 'center');
    if (C.meowing) Draw.text('meow!', this.sx, this.sy - 50 - hop, 14, '#c9485b', 'center');
  },
  /* a person, as voxels: legs, a body in their colour, a big skin-coloured head with hair (a bun for granny, a tall hat for the baker); tall scales the height; the face looks toward the viewer, left or right by fx */
  friendSprite(kind, fx) {
    const K = FRIEND_KINDS[kind];
    return Draw.sprite('fr:' + kind + fx, 48, g => {
      const t = K.tall, lh = 9 * t, bh = 15 * t, hh = 11, hw = 9, f = fx > 0 ? 'r' : 'l';
      this.cube(g, 0, 0, 6, 3, lh, '#4a3b5c'); this.cube(g, 0, -lh, 9, 4.5, bh, K.color);
      const hy = -lh - bh; this.cube(g, 0, hy, hw + 1, hw / 2 + 0.5, hh, '#ffd9b3');
      this.cube(g, 0, hy - hh + 2, hw + 1.5, hw / 2 + 1, 4, K.hair);
      if (K.bun) this.cube(g, 0, hy - hh - 2, 5, 2.5, 5, K.hair);
      if (K.hat) this.cube(g, 0, hy - hh + 1, 8, 4, 12, '#ffffff');
      for (const u of [0.28, 0.64]) this.face(g, f, 0, hy, hw + 1, hw / 2 + 0.5, hh, u, 0.3, u + 0.1, 0.55, '#2b2233');
      this.face(g, f, 0, hy, hw + 1, hw / 2 + 0.5, hh, 0.38, 0.12, 0.62, 0.2, '#e8788e');
    });
  },
  /* a box in tile space for things that are not square (cars): centre x, y, size lx by ly tiles, from height z0 up h px; top and the two visible sides lit as a cube is */
  box(c, x, y, lx, ly, z0, h, base) {
    const P = (px, py, z) => { this.iso(px, py); return [this.sx, this.sy - z]; }, hx = lx / 2, hy = ly / 2;
    const a = P(x - hx, y - hy, z0), b = P(x + hx, y - hy, z0), d = P(x - hx, y + hy, z0), e = P(x + hx, y + hy, z0), a2 = P(x - hx, y - hy, z0 + h), b2 = P(x + hx, y - hy, z0 + h), d2 = P(x - hx, y + hy, z0 + h), e2 = P(x + hx, y + hy, z0 + h);
    const poly = (col, ...q) => { c.fillStyle = col; c.beginPath(); c.moveTo(q[0][0], q[0][1]); for (let i = 1; i < q.length; i++) c.lineTo(q[i][0], q[i][1]); c.fill(); };
    poly(this.shade(base, 0.86), d, e, e2, d2); poly(this.shade(base, 0.68), b, e, e2, b2); poly(this.shade(base, 1.08), a2, b2, e2, d2);
  },
  /* a car or van: a low coloured body with a pale cabin (taller for the van), lit headlights, and a ! over it as it nears the crossing */
  car(c, K) {
    const ax = K.axis === 'x', lx = ax ? K.len : CFG.CARS.width, ly = ax ? CFG.CARS.width : K.len, van = K.kind === 'van', flash = K.warn > 0 && Math.floor(Game.time * 12) % 2;
    this.iso(K.x, K.y); c.fillStyle = 'rgba(0,0,0,0.25)'; c.beginPath(); c.ellipse(this.sx, this.sy, 30, 15, 0, 0, TAU); c.fill();
    this.box(c, K.x, K.y, lx, ly, 3, van ? 24 : 12, van ? '#f4f4f4' : K.color);
    if (van) this.box(c, K.x, K.y, lx * 0.98, ly * 0.98, 3, 6, K.color);
    else this.box(c, K.x - (ax ? K.dir * 0.08 : 0), K.y - (ax ? 0 : K.dir * 0.08), ax ? K.len * 0.5 : lx * 0.9, ax ? ly * 0.9 : K.len * 0.5, 15, 9, '#cfeaff');
    const hx = K.x + (ax ? K.dir * K.len / 2 : 0), hy = K.y + (ax ? 0 : K.dir * K.len / 2);
    this.box(c, hx, hy, ax ? 0.12 : lx * 0.8, ax ? ly * 0.8 : 0.12, 6, 4, flash ? '#ffffff' : '#ffe38a');
    if (K.warn > 0) Draw.text('!', this.sx, this.sy - (van ? 52 : 42), 22, flash ? '#ff3030' : '#ffd23f', 'center');
  },
  /* the crossing lights up red where an approaching car is about to enter it */
  warnTiles(c) {
    const CA = CFG.CARS; for (const K of World.cars) {
      if (K.warn <= 0) continue; if (Math.floor(Game.time * 12) % 2) c.fillStyle = 'rgba(255,50,50,0.45)'; else c.fillStyle = 'rgba(255,210,60,0.4)';
      const L = CAR_LANES[K.lane];
      for (let i = CA.crossFrom; i < CA.crossTo; i++) { const tx = L.axis === 'x' ? i : L.line, ty = L.axis === 'x' ? L.line : i; this.iso(tx + 0.5, ty + 0.5); const x = this.sx, y = this.sy, hw = CFG.ISO.tw / 2, hh = CFG.ISO.th / 2; c.beginPath(); c.moveTo(x, y - hh); c.lineTo(x + hw, y); c.lineTo(x, y + hh); c.lineTo(x - hw, y); c.fill(); }
    }
  },
  /* a critter, as voxels: a bird (blue body, wings that flap by frame, orange beak) or a squirrel (brown body, big upright tail); fx is its facing, fr the wing frame */
  critterSprite(kind, fx, fr) {
    const f = fx > 0 ? 'r' : 'l';
    return Draw.sprite('cr:' + kind + fx + fr, 32, g => {
      if (kind === 'bird') {
        this.cube(g, 0, 0, 8, 4, 9, '#6ec1ff'); this.cube(g, fx * 3, -8, 6, 3, 6, '#8fd2ff');
        this.face(g, f, fx * 3, -8, 6, 3, 6, 0.35, 0.35, 0.5, 0.65, '#2b2233'); this.face(g, f, fx * 3, -8, 6, 3, 6, 0.65, 0.2, 0.95, 0.4, '#ffa43a');
        this.cube(g, -fx * 3, fr ? -9 : -3, 5, 2.5, fr ? 3 : 7, '#4a9fe0');
      } else {
        this.cube(g, -fx * 7, 0, 5, 2.5, 20, '#c9733a'); this.cube(g, 0, 0, 7, 3.5, 9, '#b8662f'); this.cube(g, fx * 3, -8, 6, 3, 7, '#c9733a');
        this.cube(g, fx * 3 - 3, -15, 2, 1, 4, '#c9733a'); this.cube(g, fx * 3 + 3, -15, 2, 1, 4, '#c9733a');
        this.face(g, f, fx * 3, -8, 6, 3, 7, 0.35, 0.4, 0.5, 0.7, '#2b2233');
      }
    });
  },
  critter(c2, C) {
    if (C.state === 'gone') return;
    const K = CRITTER_KINDS[C.kind]; this.iso(C.x, C.y); const air = C.alt * 26, flap = C.alt > 0.2 ? Math.floor(Game.time * 14) & 1 : 0, hop = C.state === 'idle' && Math.hypot(C.tx - C.x, C.ty - C.y) > 0.05 ? Math.abs(Math.sin(Game.time * 12)) * 3 : 0;
    c2.fillStyle = 'rgba(0,0,0,' + (0.22 - C.alt * 0.1) + ')'; c2.beginPath(); c2.ellipse(this.sx, this.sy, 9 - C.alt * 3, 4.5 - C.alt * 1.5, 0, 0, TAU); c2.fill();
    Draw.stamp(this.critterSprite(C.kind, C.fx, flap), this.sx, this.sy - air - hop, 0);
    if (C.alert > 0) Draw.text('!', this.sx, this.sy - 36 - air, 18, '#e03030', 'center');
  },
  /* a witch, as voxels: a purple robe, a pale green face with a long nose-less grin, and a tall pointed hat with a band */
  witchSprite(fx) {
    return Draw.sprite('wi:' + fx, 56, g => {
      const f = fx > 0 ? 'r' : 'l', hy = -26;
      this.cube(g, 0, 0, 11, 5.5, 26, '#6b3fa0'); this.cube(g, 0, hy, 9, 4.5, 11, '#b8e6a8');
      for (const u of [0.28, 0.64]) this.face(g, f, 0, hy, 9, 4.5, 11, u, 0.35, u + 0.1, 0.6, '#2b2233');
      this.face(g, f, 0, hy, 9, 4.5, 11, 0.3, 0.12, 0.7, 0.22, '#4a2a6a');
      this.cube(g, 0, hy - 10, 15, 7.5, 3, '#3a2160'); this.cube(g, 0, hy - 13, 10, 5, 9, '#4b2a7a'); this.cube(g, 0, hy - 22, 6, 3, 8, '#4b2a7a'); this.cube(g, fx * 2, hy - 30, 3, 1.5, 6, '#4b2a7a');
      this.cube(g, 0, hy - 14, 10.5, 5.25, 3, '#ffcc3d');
    });
  },
  witch(c, W) {
    this.iso(W.x, W.y); c.fillStyle = 'rgba(0,0,0,0.3)'; c.beginPath(); c.ellipse(this.sx, this.sy, 14, 7, 0, 0, TAU); c.fill();
    const bob = Math.sin(Game.time * 4 + W.wx) * 2;
    Draw.stamp(this.witchSprite(W.fx), this.sx, this.sy - 6 - bob, 0);
    const ang = W.state === 'chase' ? '!' : W.spot > 0 ? '?' : ''; if (ang) Draw.text(ang, this.sx, this.sy - 88, 24, W.state === 'chase' ? '#ff3030' : '#ffd23f', 'center');
  },
  /* the lantern cone as a lit patch on the ground: its fan of ray hits projected to the screen; a witch chasing glows red */
  cones(c) {
    for (const W of World.witches) {
      c.fillStyle = W.state === 'chase' ? 'rgba(255,120,120,0.45)' : 'rgba(255,244,160,0.4)'; c.beginPath();
      for (let i = 0; i < W.poly.length; i++) { this.iso(W.poly[i].x, W.poly[i].y); if (i) c.lineTo(this.sx, this.sy); else c.moveTo(this.sx, this.sy); }
      c.closePath(); c.fill();
    }
  },
  /* the night's own light, drawn over the darkness: a purple aura and warm lantern glow on each witch */
  lights(c) {
    for (const W of World.witches) { this.iso(W.x, W.y); Draw.glow(this.sx, this.sy - 20, 70, '#a45cff', 0.5); Draw.glow(this.sx + W.fx * 12, this.sy - 8, 34, '#ffe08a', 0.7); }
  },
  /* the moon, stars and a night-blue sky that fade in with the dark */
  moon(c) {
    const d = World.dark; if (d <= 0) return; c.globalAlpha = d;
    for (let i = 0; i < 40; i++) { const x = (i * 197 % 1000) / 1000 * Game.W, y = (i * 61 % 100) / 100 * CFG.H * 0.55; c.fillStyle = '#fff'; c.fillRect(x, y, 2, 2); }
    const mx = Game.W - 150, my = 120; Draw.glow(mx, my, 110, '#fff6c8', 0.5); c.fillStyle = '#fff6d6'; c.beginPath(); c.arc(mx, my, 44, 0, TAU); c.fill();
    c.fillStyle = '#e9dfb8'; for (const q of [[-14, -8, 9], [12, 6, 7], [-4, 18, 5]]) { c.beginPath(); c.arc(mx + q[0], my + q[1], q[2], 0, TAU); c.fill(); }
    c.globalAlpha = 1;
  },
  mix(a, b, t) { const x = parseInt(a.slice(1), 16), y = parseInt(b.slice(1), 16), m = (s) => Math.round(((x >> s) & 255) * (1 - t) + ((y >> s) & 255) * t); return `rgb(${m(16)},${m(8)},${m(0)})`; },
  /* the mood bubble over a friend: a heart (a pat is on offer) or a fish (they carry food), with a ring that fills as the cat meows; none while they rest */
  bubble(c, F, x, y) {
    if (F.cd > 0) return;
    const pulse = 1 + 0.08 * Math.sin(Game.time * 6 + F.hx), r = 13 * pulse;
    c.fillStyle = 'rgba(255,255,255,0.95)'; c.beginPath(); c.arc(x, y, r, 0, TAU); c.fill(); c.beginPath(); c.moveTo(x - 4, y + r - 2); c.lineTo(x, y + r + 6); c.lineTo(x + 4, y + r - 2); c.fill();
    if (F.food) { c.fillStyle = '#4aa8e8'; c.beginPath(); c.ellipse(x - 1, y, 7, 4.5, 0, 0, TAU); c.fill(); c.beginPath(); c.moveTo(x + 5, y); c.lineTo(x + 10, y - 4); c.lineTo(x + 10, y + 4); c.fill(); c.fillStyle = '#fff'; c.fillRect(x - 5, y - 2, 2, 2); }
    else { c.fillStyle = '#ff5c8a'; c.beginPath(); c.arc(x - 3.5, y - 2, 4.2, 0, TAU); c.arc(x + 3.5, y - 2, 4.2, 0, TAU); c.fill(); c.beginPath(); c.moveTo(x - 7.6, y); c.lineTo(x, y + 8); c.lineTo(x + 7.6, y); c.fill(); }
    if (F.meter > 0) { c.strokeStyle = '#ff5c8a'; c.lineWidth = 4; c.beginPath(); c.arc(x, y, r + 4, -Math.PI / 2, -Math.PI / 2 + TAU * F.meter); c.stroke(); }
  },
  friend(c, F) {
    this.iso(F.x, F.y); const K = FRIEND_KINDS[F.kind], walking = K.wander && Math.hypot(F.tx - F.x, F.ty - F.y) > 0.1, hop = walking ? Math.abs(Math.sin(Game.time * 8 + F.hx)) * 3 : 0;
    c.fillStyle = 'rgba(0,0,0,0.22)'; c.beginPath(); c.ellipse(this.sx, this.sy, 11, 5.5, 0, 0, TAU); c.fill();
    Draw.stamp(this.friendSprite(F.kind, F.fx), this.sx, this.sy - hop, 0);
    this.bubble(c, F, this.sx, this.sy - 62 * K.tall - 14 - hop);
  },
  /* the camera eases toward the cat (snap=true jumps there, for a new run) */
  follow(snap, dt) {
    const C = World.cat; this.iso(C.x, C.y); const tx = this.sx - Game.W / 2, ty = this.sy - CFG.H / 2 - CFG.CAM.lift;
    if (snap) { Game.cam.x = tx; Game.cam.y = ty; } else { const k = U.damp(CFG.CAM.follow, dt); Game.cam.x += (tx - Game.cam.x) * k; Game.cam.y += (ty - Game.cam.y) * k; }
  },
  sky(c) { const d = World.dark, g = c.createLinearGradient(0, 0, 0, CFG.H); g.addColorStop(0, this.mix('#bfe9ff', '#0c1033', d)); g.addColorStop(1, this.mix('#e9f7d6', '#2b2f66', d)); c.fillStyle = g; c.fillRect(0, 0, Game.W, CFG.H); this.moon(c); },
  /* the town under the camera: all ground first, then every block and the cat, one diagonal at a time from back to front */
  town(c) {
    const N = World.N, rows = World.rows, cx = Game.cam.x, cy = Game.cam.y, m = 120, ents = this.ents;
    ents.length = 0; ents.push(World.cat); for (const W of World.witches) ents.push(W); for (const F of World.friends) ents.push(F); for (const K of World.critters) ents.push(K); for (const K of World.cars) ents.push(K);
    ents.sort((a, b) => a.x + a.y - b.x - b.y); let ei = 0;
    for (let d = 0; d <= 2 * N - 2; d++) for (let tx = Math.max(0, d - N + 1); tx <= Math.min(d, N - 1); tx++) {
      const ty = d - tx; this.iso(tx + 0.5, ty + 0.5); if (this.sx < cx - m || this.sx > cx + Game.W + m || this.sy < cy - m || this.sy > cy + CFG.H + m) continue;
      Draw.stamp(this.ground(rows[ty][tx], tx, ty), this.sx, this.sy, 0);
    }
    this.warnTiles(c); this.cones(c);
    for (let d = 0; d <= 2 * N - 2; d++) {
      for (let tx = Math.max(0, d - N + 1); tx <= Math.min(d, N - 1); tx++) {
        const ty = d - tx, ch = rows[ty][tx]; if (!TILES[ch].solid) continue;
        this.iso(tx + 0.5, ty + 0.5); if (this.sx < cx - m || this.sx > cx + Game.W + m || this.sy < cy - m || this.sy > cy + CFG.H + m + 100) continue;
        Draw.stamp(this.block(ch), this.sx, this.sy, 0);
      }
      while (ei < ents.length && Math.floor(ents[ei].x) + Math.floor(ents[ei].y) <= d) { const E = ents[ei++]; if (E === World.cat) this.cat(c); else if (E.lane !== undefined) this.car(c, E); else if (E.poly) this.witch(c, E); else if (E.meter === undefined) this.critter(c, E); else this.friend(c, E); }
    }
  },
  /* the whole scene: sky, then the town under the camera */
  world(c) {
    this.sky(c); Game.world(() => this.town(c));
    if (World.dark > 0) { c.fillStyle = 'rgba(20,12,60,' + 0.5 * World.dark + ')'; c.fillRect(0, 0, Game.W, CFG.H); Game.world(() => this.lights(c)); }
  },
  hud(c) {
    const k = Game.uiScale;
    for (let i = 0; i < CFG.PAWS.max; i++) { const x = Game.W - (CFG.PAWS.max - i) * 44 * k, y = 34 * k, on = i < World.paws; c.fillStyle = on ? '#f6a85f' : 'rgba(0,0,0,0.2)'; c.beginPath(); c.ellipse(x, y + 4 * k, 11 * k, 9 * k, 0, 0, TAU); c.fill(); for (const t of [[-10, -8], [-4, -13], [4, -13], [10, -8]]) { c.beginPath(); c.ellipse(x + t[0] * k, y + t[1] * k + 4 * k, 4 * k, 5 * k, 0, 0, TAU); c.fill(); } }
    const night = World.phase === 'night', left = World.clock / (night ? CFG.DAY.nightSecs : CFG.DAY.daySecs);
    Draw.text((night ? 'NIGHT ' : 'DAY ') + World.day, Game.W / 2, 30 * k, 22 * k, night ? '#e9e2ff' : '#5a3b2e', 'center');
    Draw.bar(Game.W / 2 - 80 * k, 42 * k, 160 * k, 8 * k, left, night ? '#7a5cd6' : '#ffc94a');
    Draw.text('SCORE ' + Game.score, 20 * k, 28 * k, 22 * k, '#5a3b2e');
    const b = World.belly / CFG.BELLY.max;
    if (World.comboT > 0 && World.combo > 1) { Draw.text('COMBO x' + World.combo, 20 * k, 90 * k, 20 * k, '#c9485b'); Draw.bar(20 * k, 100 * k, 120 * k, 8 * k, World.comboT / CFG.COMBO.window, '#ff8fb1'); }
    Draw.text('BELLY', 20 * k, 58 * k, 14 * k, '#5a3b2e'); Draw.bar(80 * k, 50 * k, 160 * k, 14 * k, b, b < 0.25 && Math.floor(Game.time * 4) % 2 ? '#ff5c5c' : '#4aa8e8');
    Game.drawMsgs(20 * k, CFG.H - 120 * k, 16 * k);
  },
};
