// Drawing: the arena, the ship, enemies (stamped sprites), bullets, pickups, particles and the HUD. Everything here reads
// World and Game and draws; nothing here changes the game.
const Render = {
  bg(c) { c.fillStyle = '#0d0a16'; c.fillRect(0, 0, Game.W, CFG.H); c.strokeStyle = 'rgba(255,102,0,0.08)'; c.lineWidth = 1; for (let x = 0; x <= Game.W; x += 80) { c.beginPath(); c.moveTo(x, 0); c.lineTo(x, CFG.H); c.stroke(); } for (let y = 0; y <= CFG.H; y += 80) { c.beginPath(); c.moveTo(0, y); c.lineTo(Game.W, y); c.stroke(); } },
  enemy(e) {
    const K = KINDS[e.kind], S = Draw.sprite('e:' + e.kind + (e.flash > 0 ? ':f' : ''), e.r + 6, g => {
      const col = e.flash > 0 ? '#ffffff' : K.color; g.fillStyle = U.tint(col, 0.25); g.beginPath(); g.arc(0, 0, e.r + 5, 0, TAU); g.fill();
      g.fillStyle = col; g.beginPath();
      if (K.move === 'chase') { g.moveTo(e.r, 0); g.lineTo(-e.r * 0.7, e.r * 0.7); g.lineTo(-e.r * 0.3, 0); g.lineTo(-e.r * 0.7, -e.r * 0.7); }
      else if (K.move === 'drift') { for (let i = 0; i < 6; i++) { const a = i / 6 * TAU, r = i % 2 ? e.r * 0.6 : e.r; g.lineTo(Math.cos(a) * r, Math.sin(a) * r); } }
      else { g.arc(0, 0, e.r, 0, TAU); g.fillStyle = col; g.fill(); g.fillStyle = '#0d0a16'; g.beginPath(); g.arc(e.r * 0.3, 0, e.r * 0.35, 0, TAU); }
      g.closePath(); g.fill();
    });
    Draw.stamp(S, e.x, e.y, e.a);
    if (KINDS[e.kind].hp > 1) Draw.bar(e.x - e.r, e.y - e.r - 10, e.r * 2, 5, e.hp / KINDS[e.kind].hp, K.color);
  },
  ship(P) {
    const c = Draw.ctx; if (P.inv > 0 && Math.floor(P.inv * 12) % 2) return; // blink while invulnerable
    Draw.glow(P.x, P.y, P.r * 2.4, '#ff6600', 0.35);
    c.save(); c.translate(P.x, P.y); c.rotate(P.aim);
    c.fillStyle = '#ffcc00'; c.beginPath(); c.moveTo(P.r + 4, 0); c.lineTo(-P.r * 0.8, P.r * 0.8); c.lineTo(-P.r * 0.4, 0); c.lineTo(-P.r * 0.8, -P.r * 0.8); c.closePath(); c.fill();
    c.fillStyle = '#ff6600'; c.beginPath(); c.arc(-P.r * 0.1, 0, P.r * 0.35, 0, TAU); c.fill();
    c.restore();
  },
  world(c) {
    this.bg(c); const W = World;
    for (const k of W.pickups) { const pulse = 1 + 0.15 * Math.sin(Game.time * 8); Draw.glow(k.x, k.y, k.r * 2.5 * pulse, '#9bf06a', 0.4); Draw.circle(k.x, k.y, k.r * pulse, '#9bf06a', '#fff', 2); }
    for (const p of W.parts.live) { c.globalAlpha = Math.min(1, p.life * 3); Draw.circle(p.x, p.y, p.r, p.color); } c.globalAlpha = 1;
    for (const b of W.bullets) { Draw.circle(b.x, b.y, b.r, '#fff'); Draw.circle(b.x - b.vx * 0.012, b.y - b.vy * 0.012, b.r * 0.7, 'rgba(255,204,0,0.6)'); }
    for (const e of W.enemies) this.enemy(e);
    if (W.alive) this.ship(W.player);
  },
  hud(c) {
    const W = World, k = Game.uiScale;
    Draw.text('SCORE ' + Game.score, 20 * k, 28 * k, 22 * k, '#fff'); Draw.text('WAVE ' + W.wave, 20 * k, 56 * k, 16 * k, '#ffcc00');
    for (let i = 0; i < CFG.PLAYER.hp; i++) Draw.circle(Game.W / 2 - (CFG.PLAYER.hp - 1) * 14 * k + i * 28 * k, 28 * k, 9 * k, i < W.player.hp ? '#ff5c5c' : 'rgba(255,255,255,0.15)');
    Draw.bar(Game.W / 2 - 100 * k, 48 * k, 200 * k, 6 * k, W.waveT / CFG.WAVE_SECS, '#ff6600');
    Game.drawMsgs(20 * k, CFG.H - 120 * k, 16 * k);
  },
};
