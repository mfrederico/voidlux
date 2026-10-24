// Effects: projectiles, poison clouds, lightning arcs, particles, floating text.
// Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  // ---- effects ----
  drawProjectiles() {
    const c = this.ctx;
    for (const p of Combat.projectiles) {
      if (p.owner === 'enemy') {
        if (p.pie) { c.save(); c.translate(p.x, p.y); c.rotate(p.spin); c.fillStyle = '#d9b06d'; c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.ellipse(0, 2, p.size * 1.3, p.size * 0.7, 0, 0, TAU); c.fill(); c.stroke(); c.fillStyle = '#fff5e0'; c.beginPath(); c.ellipse(0, -1, p.size * 1.1, p.size * 0.6, 0, 0, TAU); c.fill(); c.stroke(); c.fillStyle = '#ff5c5c'; c.beginPath(); c.arc(0, -4, 3, 0, TAU); c.fill(); c.restore(); continue; } // a cream pie, spinning
        if (p.bounce > 0 || (p.bounce === 0 && p.spin > 0)) { c.save(); c.translate(p.x, p.y); c.rotate(p.spin); c.fillStyle = p.color; c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.ellipse(0, 3, 4.5, 8, 0, 0, TAU); c.fill(); c.stroke(); c.fillRect(-1.5, -11, 3, 8); c.strokeRect(-1.5, -11, 3, 8); c.restore(); continue; } // a juggler's pin
        if (p.push) { c.fillStyle = 'rgba(159,227,255,0.8)'; c.beginPath(); c.arc(p.x, p.y, p.size, 0, TAU); c.fill(); c.fillStyle = 'rgba(255,255,255,0.6)'; c.beginPath(); c.arc(p.x - 1, p.y - 1, p.size * 0.4, 0, TAU); c.fill(); continue; } // a water drop
        this.circle(p.x, p.y, p.size, p.color, this.OUT, 2); this.shine(p.x, p.y, p.size); continue;
      }
      if (p.type && p.type.pellet) { this.circle(p.x, p.y, p.size + 1.5, p.color, '#ffffff', 2); this.shine(p.x, p.y, p.size); continue; } // the deck's pellet: a dot in its segment's colour, white-rimmed like the dots that rain down
      if (G.rubber > 0 && (p.kind === 'shot' || p.kind === 'bounce')) { c.save(); c.translate(p.x, p.y); c.rotate(Math.atan2(p.vy, p.vx)); c.fillStyle = '#ffd166'; c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.beginPath(); c.ellipse(-4, 0, 8, 4.5, 0, 0, TAU); c.fill(); c.stroke(); c.beginPath(); c.arc(6, -2, 3.5, 0, TAU); c.fill(); c.stroke(); c.fillStyle = '#ff5c5c'; c.beginPath(); c.arc(8, -5, 1.5, 0, TAU); c.fill(); c.restore(); continue; } // the fortune teller's rubber chickens
      if (p.type && p.type.pin) { c.save(); c.translate(p.x, p.y); c.rotate(p.spin); if (p.elems.length) { c.fillStyle = p.color + '66'; c.beginPath(); c.arc(0, 0, 11, 0, TAU); c.fill(); } c.fillStyle = p.elems.length ? p.color : '#ff5c8a'; c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.ellipse(0, 3, 5, 9, 0, 0, TAU); c.fill(); c.stroke(); c.fillStyle = '#fff5e0'; c.fillRect(-1.8, -12, 3.6, 9); c.strokeRect(-1.8, -12, 3.6, 9); c.fillStyle = '#fff'; c.fillRect(-5, 1, 10, 2.5); c.restore(); continue; } // a juggling pin: a bowling-pin shape with a white band
      switch (p.kind) {
        case 'chain': { // the Ricochet: a gold boomerang with a glow and a dotted line to its next victim
          if (p.target && !p.target.dead && !p.returning) { c.save(); c.setLineDash([3, 7]); c.strokeStyle = 'rgba(255,216,128,0.45)'; c.lineWidth = 1.5; c.beginPath(); c.moveTo(p.x, p.y); c.lineTo(p.target.x, p.target.y); c.stroke(); c.restore(); }
          c.fillStyle = p.color + '55'; c.beginPath(); c.arc(p.x, p.y, 13, 0, TAU); c.fill();
          c.save(); c.translate(p.x, p.y); c.rotate(p.spin); c.fillStyle = p.color; c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.lineJoin = 'round';
          c.beginPath(); c.moveTo(-10, -3); c.lineTo(2, -3); c.lineTo(10, -11); c.lineTo(13, -6); c.lineTo(5, 3); c.lineTo(5, 10); c.lineTo(-1, 10); c.lineTo(-1, 3); c.lineTo(-10, 3); c.closePath(); c.fill(); c.stroke(); c.restore();
          if (p.hops !== undefined) this.text(String(p.hops), p.x, p.y - 16, 11, '#ffd880', 'center');
          break;
        }
        case 'boomerang': {
          c.save(); c.translate(p.x, p.y); c.rotate(p.spin);
          c.fillStyle = p.color; c.strokeStyle = this.OUT; c.lineWidth = 2.5; c.lineJoin = 'round';
          c.beginPath(); c.moveTo(-10, -3); c.lineTo(2, -3); c.lineTo(10, -11); c.lineTo(13, -6); c.lineTo(5, 3); c.lineTo(5, 10); c.lineTo(-1, 10); c.lineTo(-1, 3); c.lineTo(-10, 3); c.closePath(); c.fill(); c.stroke();
          c.restore(); break;
        }
        case 'orbit': {
          c.fillStyle = p.color + '55'; c.beginPath(); c.arc(p.x, p.y, p.size + 6 + Math.sin(G.time * 12) * 2, 0, TAU); c.fill();
          this.circle(p.x, p.y, p.size, p.color, this.OUT, 2); this.shine(p.x, p.y, p.size);
          c.strokeStyle = p.color + '66'; c.lineWidth = 1.5; const h = Combat.segPos(p); c.beginPath(); c.arc(h.x, h.y, p.type.radius, 0, TAU); c.stroke();
          if (p.seg && p.owner === 'player' && p.type.shield) { // its shield: a bright ring and a pip per charge, or a grey ring filling back up while it recharges
            const s = p.seg, n = Combat.shieldOf(s), R = p.size + 7;
            if (n > 0) { c.strokeStyle = '#ffffff'; c.lineWidth = 2.5; c.beginPath(); c.arc(p.x, p.y, R, 0, TAU); c.stroke(); for (let k = 0; k < n; k++) { const a = G.time * 3 + k * TAU / p.type.shield; this.circle(p.x + Math.cos(a) * R, p.y + Math.sin(a) * R, 2.5, '#fff', this.OUT, 1); } }
            else { const f = 1 - Math.max(0, s.shieldT || 0) / (p.type.recharge || 6); c.strokeStyle = 'rgba(160,160,170,0.7)'; c.lineWidth = 2.5; c.beginPath(); c.arc(p.x, p.y, R, -Math.PI / 2, -Math.PI / 2 + TAU * f); c.stroke(); }
          }
          break;
        }
        case 'homing': {
          const flap = Math.abs(Math.sin(G.time * 30 + p.spin)) * 0.7 + 0.3, px = -Math.sin(p.angle), py = Math.cos(p.angle);
          c.fillStyle = p.color; c.strokeStyle = this.OUT; c.lineWidth = 2;
          for (const sgn of [-1, 1]) { c.beginPath(); c.ellipse(p.x + px * sgn * 6 * flap, p.y + py * sgn * 6 * flap, 7, 4, p.angle + sgn * 0.6, 0, TAU); c.fill(); c.stroke(); }
          this.circle(p.x, p.y, 3.5, '#fff', this.OUT, 1.5); break;
        }
        case 'bounce': {
          this.circle(p.x, p.y, p.size, p.color, this.OUT, 2); this.shine(p.x, p.y, p.size);
          c.strokeStyle = this.OUT; c.lineWidth = 1.5; c.beginPath(); c.arc(p.x, p.y, p.size * 0.5, p.spin, p.spin + 2); c.stroke(); break;
        }
        case 'mine': {
          const armed = p.arm <= 0, blink = armed && Math.floor(G.time * 6) % 2 === 0;
          this.circle(p.x, p.y, p.size, blink ? '#fff' : p.color, this.OUT, 2.5);
          c.strokeStyle = this.OUT; c.lineWidth = 2; for (let k = 0; k < 4; k++) { const a = k * Math.PI / 2 + Math.PI / 4; c.beginPath(); c.moveTo(p.x + Math.cos(a) * p.size, p.y + Math.sin(a) * p.size); c.lineTo(p.x + Math.cos(a) * (p.size + 4), p.y + Math.sin(a) * (p.size + 4)); c.stroke(); }
          if (armed) { c.strokeStyle = 'rgba(255,90,90,0.25)'; c.lineWidth = 1.5; c.beginPath(); c.arc(p.x, p.y, p.type.blast * 0.5, 0, TAU); c.stroke(); }
          break;
        }
        case 'pulse': {
          const fa = Math.max(0, p.life / (p.life0 || p.type.life)); c.lineWidth = 6 + 4 * Math.max(0, G.mods.range - 1); c.strokeStyle = p.resonance ? this.tint(p.color, fa) : `rgba(255,255,255,${fa})`; c.beginPath(); c.arc(p.x, p.y, p.r, 0, TAU); c.stroke();
          c.strokeStyle = p.resonance ? `rgba(255,233,168,${fa * 0.8})` : `rgba(125,249,255,${fa * 0.7})`; c.lineWidth = 2; c.stroke(); break; // (a resonance ring: the mine's orange)
        }
        default: {
          if (p.type.spear) { // a thrown bone: shaft with knobbed ends and a pointed head, glowing in its element's colour when tipped
            const a = Math.atan2(p.vy, p.vx), L = p.type.pierce >= 99 ? 30 : 22;
            c.save(); c.translate(p.x, p.y); c.rotate(a);
            if (p.elems.length) { c.fillStyle = p.color + '66'; c.beginPath(); c.arc(L * 0.5, 0, 9, 0, TAU); c.fill(); }
            c.lineCap = 'round'; c.strokeStyle = this.OUT; c.lineWidth = 6; c.beginPath(); c.moveTo(-L, 0); c.lineTo(L * 0.5, 0); c.stroke(); c.strokeStyle = '#eae4d4'; c.lineWidth = 3; c.stroke();
            for (const sy of [-2.5, 2.5]) this.circle(-L, sy, 2.6, '#eae4d4', this.OUT, 1.2);
            c.fillStyle = p.elems.length ? p.color : '#fff5e0'; c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); c.moveTo(L * 0.4, -5); c.lineTo(L, 0); c.lineTo(L * 0.4, 5); c.closePath(); c.fill(); c.stroke();
            c.restore(); break;
          }
          if (p.type.bounces && p.kind === 'shot') { c.strokeStyle = `rgba(255,209,102,${0.25 + 0.15 * Math.sin(G.time * 20)})`; c.lineWidth = 2; c.beginPath(); c.arc(p.x, p.y, p.size + 6, 0, TAU); c.stroke(); } // a ricochet shot wears a gold ring
          if (p.laser) {
            const l = p.beam ? 40 : 16; c.lineCap = 'round';
            c.globalAlpha = 0.5; c.strokeStyle = p.color; c.lineWidth = p.beam ? 16 : 8; c.beginPath(); c.moveTo(p.x, p.y); c.lineTo(p.x - p.vx / p.type.speed * l, p.y - p.vy / p.type.speed * l); c.stroke();
            c.globalAlpha = 1; c.lineWidth = 3; c.strokeStyle = '#fff'; c.stroke();
          } else {
            if (p.elems.length || p.combo) { c.fillStyle = p.color + '66'; c.beginPath(); c.arc(p.x, p.y, p.size + (p.combo ? 7 : 4), 0, TAU); c.fill(); }
            if (p.type.shards && !p.shard) { c.fillStyle = p.color; c.strokeStyle = this.OUT; c.lineWidth = 2; c.beginPath(); for (let k = 0; k < 10; k++) { const a = k * Math.PI / 5 + G.time * 6, rr = k % 2 ? p.size * 0.5 : p.size + 2; c.lineTo(p.x + Math.cos(a) * rr, p.y + Math.sin(a) * rr); } c.closePath(); c.fill(); c.stroke(); }
            else { this.circle(p.x, p.y, p.size, p.color, this.OUT, p.size > 5 ? 2.5 : 1.5); if (p.size > 5) this.shine(p.x, p.y, p.size); }
          }
        }
      }
    }
  },

  drawClouds() {
    const c = this.ctx;
    for (const cl of Combat.clouds) {
      const kind = cl.kind || 'poison', steam = kind === 'steam', charged = kind === 'charged' || kind === 'electric', fire = kind === 'fire';
      const a = Math.min(1, cl.t / 1.2) * 0.45 * (fire ? 0.8 + 0.2 * Math.sin(G.time * 30 + cl.seed) : 1);
      const pal = { poison: ['140,230,90', '180,255,120'], charged: ['190,255,140', '220,255,170'], steam: ['235,245,255', '255,255,255'], fire: ['255,120,40', '255,215,90'], dust: ['200,175,120', '235,215,165'], giggle: ['255,150,210', '255,215,240'], ice: ['190,230,255', '240,250,255'], electric: ['110,200,255', '200,240,255'], venom: ['90,150,40', '150,210,70'], laugh: ['255,170,220', '255,220,245'] }[kind] || ['140,230,90', '180,255,120'];
      if (cl.enemy) { c.strokeStyle = kind === 'laugh' ? `rgba(255,150,210,${a})` : `rgba(90,150,40,${a})`; c.lineWidth = 2; c.setLineDash([4, 6]); c.beginPath(); c.arc(cl.x, cl.y, cl.r, 0, TAU); c.stroke(); c.setLineDash([]); } // the boss's venom (or laughing gas): an edge you can read
      if (kind === 'laugh' && Math.floor(G.time * 3 + cl.seed) % 2 === 0) this.text('ha', cl.x + Math.sin(cl.seed * 5) * cl.r * 0.4, cl.y - cl.r * 0.3 - ((G.time * 20 + cl.seed * 10) % 30), 12, `rgba(255,255,255,${a})`, 'center');
      const c1 = pal[0], c2 = pal[1];
      for (let i = 0; i < 4; i++) {
        const ang = cl.seed + i * 1.7 + G.time * (steam ? 1.4 : 0.6), rr = cl.r * 0.55;
        c.fillStyle = `rgba(${c1},${a * 0.6})`; c.beginPath(); c.arc(cl.x + Math.cos(ang) * cl.r * 0.4, cl.y + Math.sin(ang) * cl.r * 0.4, rr, 0, TAU); c.fill();
      }
      c.fillStyle = `rgba(${c2},${a * 0.5})`; c.beginPath(); c.arc(cl.x, cl.y, cl.r * 0.6, 0, TAU); c.fill();
      if (charged && U.chance(0.2)) { c.strokeStyle = '#7df9ff'; c.lineWidth = 1.5; c.beginPath(); c.moveTo(cl.x + U.rand(-cl.r, cl.r) * 0.6, cl.y + U.rand(-cl.r, cl.r) * 0.6); c.lineTo(cl.x + U.rand(-cl.r, cl.r) * 0.6, cl.y + U.rand(-cl.r, cl.r) * 0.6); c.stroke(); }
    }
  },

  drawArcs() {
    const c = this.ctx;
    for (const a of Combat.arcs) {
      c.strokeStyle = `rgba(125,249,255,${Math.min(1, a.t / 0.12)})`; c.lineWidth = 2.5; c.beginPath(); c.moveTo(a.x1, a.y1);
      const n = 5;
      for (let i = 1; i < n; i++) { const t = i / n; const x = U.lerp(a.x1, a.x2, t), y = U.lerp(a.y1, a.y2, t); const j = Math.sin(a.seed * 40 + i * 9 + G.time * 50) * 9; c.lineTo(x + j, y - j); }
      c.lineTo(a.x2, a.y2); c.stroke();
    }
  },

  drawParticles() {
    const c = this.ctx;
    for (const q of Combat.particles) { c.globalAlpha = Math.max(0, q.t / q.life); c.fillStyle = q.color; c.beginPath(); c.arc(q.x, q.y, q.size, 0, TAU); c.fill(); }
    c.globalAlpha = 1;
  },

  drawFloaters() {
    for (const f of Combat.floaters) { this.ctx.globalAlpha = Math.min(1, f.t / 0.3); this.textStamp(String(f.text), f.x, f.y, 13, f.color, 'center'); } // from the text atlas: a fight shows the same few numbers over and over
    this.ctx.globalAlpha = 1;
  },

});
