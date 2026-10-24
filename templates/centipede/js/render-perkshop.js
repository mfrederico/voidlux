// The perk shop between levels (the cards, prices and tabs) and the burst a bought perk plays.
// Extends Render (see render.js). Loads after render.js.
Object.assign(Render, {
  drawPerks() {
    const c = this.ctx, ids = G.perkChoices, R = G.perkReel, spinning = Perks.spinning();
    c.fillStyle = 'rgba(20,10,30,0.78)'; c.fillRect(0, 0, CFG.W, CFG.H);
    this.text('DUNGEON CLEARED', CFG.W / 2, 120, 50, '#ffd166', 'center');
    const afford = Perks.canAfford();
    this.text(spinning ? 'Your perks are coming up…' : G.perkFree > 0 ? 'One pick is free (it covers up to ' + CFG.BALANCE.freeCap + ' ghosts of a price). Every copy of a perk costs more than the last.' : afford ? 'Spend your ghosts. Every copy of a perk costs more than the last. Unspent ghosts carry over.' : 'Nothing you can afford. Eat more ghosts next time.', CFG.W / 2, 165, 20, '#fff', 'center');
    // ghost purse
    const gx = CFG.W / 2, gy = 212;
    this.ghostIcon(gx - 60, gy, 13, '#7de0ff');
    this.text(`Ghosts: ${G.ghosts}` + (G.perkFree > 0 ? '   +1 free pick' : ''), gx - 36, gy + 1, 24, '#7de0ff', 'left');
    const T = Input.touch, w = 260, h = T ? 350 : 250, gap = 24, x0 = CFG.W / 2 - (ids.length * w + (ids.length - 1) * gap) / 2, y0 = 250;
    // one perk's badge and name, drawn at a vertical offset inside a card (the reel scrolls these)
    const face = (pid, x, y, dy, alpha, bright) => {
      const Pk = PERKS[pid], PI = PERK_ICONS[pid] || [Pk.mega ? 'mega' : 'dmg', []];
      c.save(); c.globalAlpha *= alpha; /* (times the card's own: an unaffordable card dims its face too) */
      this.circle(x + w / 2, y + 68 + dy, 34, Pk.color, this.OUT, 3.5); this.shine(x + w / 2, y + 68 + dy, 34);
      Icons.draw(PI[0], x + w / 2, y + 68 + dy, 20, this.OUT);
      this.text(Pk.name, x + w / 2, y + 126 + dy, 24, bright ? '#fff' : '#e8e0f0', 'center');
      c.restore();
    };
    ids.forEach((id, i) => {
      const Pk = PERKS[id], x = x0 + i * (w + gap), cd = R && R.cards[i], spin = cd && !cd.done, landed = cd && cd.done ? Math.min(1, (R.t - cd.landT) / 0.35) : 1;
      const sel = i === G.perkSel && !spinning && !G.perkLeaveSel, bob = sel ? Math.sin(G.time * 5) * 4 : 0, y = y0 - bob;
      const can = Perks.canBuy(id);
      c.globalAlpha = spin ? 1 : can ? 1 : 0.45;
      c.fillStyle = '#1a1228'; this.roundRect(x, y, w, h, 18); c.fill(); // solid: the world behind doesn't show through the card
      c.fillStyle = sel ? 'rgba(255,255,255,0.14)' : 'rgba(255,255,255,0.06)'; this.roundRect(x, y, w, h, 18); c.fill();
      c.strokeStyle = sel ? Pk.color : spin ? '#ffd166' : 'rgba(255,255,255,0.35)'; c.lineWidth = sel ? 5 : 2.5; c.stroke();
      if (spin) { // the reel: the faces scroll up through the window, slowing to a stop on the real perk
        const p = Math.min(1, R.t / cd.dur), pos = Perks.reelEase(p) * cd.steps, idx = Math.floor(pos), frac = pos - idx, pitch = 120;
        c.save(); c.beginPath(); c.rect(x + 2, y + 18, w - 4, 136); c.clip();
        for (const j of [idx - 1, idx, idx + 1]) { const pid = cd.seq[Math.max(0, Math.min(cd.steps, j))]; face(pid, x, y, (j - idx) * pitch - frac * pitch, 1 - Math.abs((j - idx) - frac) * 0.6, false); }
        c.restore();
        c.fillStyle = 'rgba(255,255,255,0.08)'; c.fillRect(x + 2, y + 18, w - 4, 4); c.fillRect(x + 2, y + 150, w - 4, 4); // the window's edges
        this.text('?', x + w - 48, y + 24, 16, 'rgba(255,255,255,0.6)', 'center');
      } else {
        // just landed: a squash and a glow
        if (landed < 1) { const k = Math.sin(landed * Math.PI); c.save(); c.globalAlpha = k * 0.5; c.fillStyle = Pk.color; this.roundRect(x, y, w, h, 18); c.fill(); c.restore(); c.save(); c.translate(x + w / 2, y + 68); c.scale(1 + k * 0.25, 1 - k * 0.2); c.translate(-x - w / 2, -y - 68); }
        face(id, x, y, 0, 1, sel);
        if (landed < 1) c.restore();
        const PI = PERK_ICONS[id] || [Pk.mega ? 'mega' : 'dmg', []], strip = typeof PI[1] === 'function' ? PI[1]() : PI[1];
        if (strip.length) Icons.strip(strip.map(([icon, text]) => ({ icon, text, color: icon in ELEM_COLORS ? ELEM_COLORS[icon] : Pk.color })), x + w / 2, y + 156, 9, 'center');
        // wrap the description (small; the strip above says it without words)
        // the description, left-aligned and wrapped to the card's width (the short card text; the strip above says it without words)
        const descText = id === 'magnet' ? `Pulls loot from ${Math.round(Perks.magnetFrac(G.mods.magnet + 1) * 100)}% of the screen (now ${Math.round(Perks.magnetFrac(G.mods.magnet) * 100)}%).` : Pk.card || Pk.desc;
        if (T) this.benchWrap(descText, x + 20, y + 182, 17, '#e4dcf0', w - 40, 22, Pk.mega ? 5 : 7); // a phone: bigger (the card is taller)
        else this.benchWrap(descText, x + 20, y + 186, 14, '#d8d0e8', w - 40, 19, Pk.mega ? 2 : 3);
        if (Pk.mega) { // the mega deck: it joins the megas you own (one active at a time, switched on LOADOUT)
          const ly = y + h - (T ? 26 : 20), n = Mega.owned().length + 1;
          c.fillStyle = 'rgba(125,249,255,0.12)'; this.roundRect(x + 12, ly - 13, w - 24, 26, 8); c.fill();
          Icons.draw('mega', x + 26, ly, 6, Pk.color); this.text(`JOINS YOUR DECK  ·  your ${n}${n === 2 ? 'nd' : n === 3 ? 'rd' : 'th'} mega`, x + 38, ly + 1, T ? 15 : 13, '#9bd3ff', 'left');
        }
        // price tag
        const price = Perks.priceLabel(id), free = G.perkFree > 0 && !Perks.cost(id);
        const pw = T ? 96 : 76, ph = T ? 32 : 26; c.fillStyle = free ? '#9bf06a' : can ? '#7de0ff' : '#aa6b7a'; this.roundRect(x + w - pw - 10, y + 10, pw, ph, ph / 2); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 2; c.stroke();
        this.ink(price, x + w - pw / 2 - 10, y + 10 + ph / 2 + 1, T ? 17 : 14, this.OUT, 'center', pw - 10);
        const lv = Perks.level(id); if (lv > 0) this.text(`Lv ${lv}  ·  next ${Perks.priceAt(id, lv + 1)}`, x + w - 48, y + 50, 12, '#ffe9a8', 'center'); // how many you already have, and what the one after this will cost
      }
      this.text(`${i + 1}`, x + 22, y + 24, 16, 'rgba(255,255,255,0.6)', 'center');
      if (Pk.mega && !spin) { // a MEGA card: a shimmering badge (the colours the mega bar cycles through when it is ready)
        const bw = 120, bh = 30, bx = x + w / 2 - bw / 2, by = y - bh / 2, g = c.createLinearGradient(bx, 0, bx + bw, 0), h0 = (G.time * 120) % 360; // a tab on the card's top edge
        for (let k = 0; k <= 4; k++) g.addColorStop(k / 4, `hsl(${(h0 + k * 70) % 360},90%,65%)`);
        c.fillStyle = g; this.roundRect(bx, by, bw, bh, bh / 2); c.fill(); c.strokeStyle = this.OUT; c.lineWidth = 3; c.stroke();
        Icons.draw('mega', bx + 22, by + bh / 2, 10, '#fff'); Icons.draw('mega', bx + bw - 22, by + bh / 2, 10, '#fff');
        c.fillStyle = this.OUT; c.font = 'bold 18px "Trebuchet MS", "Segoe UI", sans-serif'; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText('MEGA', bx + bw / 2, by + bh / 2 + 1);
      }
      c.globalAlpha = 1;
    });
    const taken = G.bought.length ? `Taken: ${G.bought.map(id => PERKS[id].name).join(', ')}` : '';
    // the CONTINUE button under the cards: leave the shop (unspent ghosts carry over). Input reads G.perkLeave back
    const bw = T ? 340 : 260, bh = T ? 58 : 44, bx = CFG.W / 2 - bw / 2, bcy = T ? 640 : 560, hovL = !T && Input.mouse && Input.mouse.x >= bx && Input.mouse.x <= bx + bw && Input.mouse.y >= bcy - bh / 2 && Input.mouse.y <= bcy + bh / 2;
    G.perkLeave = spinning ? null : { x: bx, y: bcy - bh / 2, w: bw, h: bh };
    if (!spinning) {
      const on = hovL || G.perkLeaveSel; c.fillStyle = on ? 'rgba(155,240,106,0.34)' : 'rgba(155,240,106,0.16)'; this.roundRect(bx, bcy - bh / 2, bw, bh, bh / 2); c.fill(); c.strokeStyle = G.perkLeaveSel ? '#fff' : '#9bf06a'; c.lineWidth = G.perkLeaveSel ? 5 : 3; c.stroke(); // (selected from the keyboard: a white ring)
      this.text(G.bought.length ? 'CONTINUE  ▶' : 'SKIP  ▶', CFG.W / 2, bcy - (G.ghosts || G.perkFree > 0 ? 7 : 0), T ? 26 : 20, '#9bf06a', 'center');
      const sub = G.perkFree > 0 ? 'your free pick does not carry over' : G.ghosts ? `keep ${G.ghosts} ghost${G.ghosts > 1 ? 's' : ''} for next time` : '';
      if (sub) this.text(sub, CFG.W / 2, bcy + (T ? 15 : 12), T ? 15 : 12, G.perkFree > 0 ? '#ffb38a' : 'rgba(255,255,255,0.75)', 'center', 'normal');
    }
    if (taken) this.text(taken, CFG.W / 2, T ? 694 : 600, T ? 17 : 15, '#ffe9a8', 'center', 'normal');
    this.drawPerkFx();
    if (spinning || !T) this.text(spinning ? (Input.touch ? 'tap to land them' : 'any key lands them') : `A / D to choose   ·   ENTER or 1-${ids.length} to buy   ·   S then ENTER, or SPACE, to leave`, CFG.W / 2, T ? 636 : (spinning ? 590 : 630), T ? 20 : 17, '#9bf06a', 'center', 'normal');
  },

  // Perks.burst: the bloom, rings, sparks and the badge popping off the card down to the "Taken" line
  drawPerkFx() {
    const c = this.ctx, F = Perks.fx;
    if (F.glow) { const g = F.glow, k = g.t / g.dur, col = /^#[0-9a-f]{6}$/i.test(g.color) ? g.color : '#ffffff', rad = 80 + (1 - k) * Math.max(CFG.W, CFG.H) * 0.9, gr = c.createRadialGradient(g.x, g.y, 0, g.x, g.y, rad); gr.addColorStop(0, `rgba(255,255,255,${0.95 * k})`); gr.addColorStop(0.25, this.tint(col, 0.55 * k)); gr.addColorStop(1, 'rgba(255,255,255,0)'); c.save(); c.globalCompositeOperation = 'lighter'; c.fillStyle = gr; c.fillRect(0, 0, CFG.W, CFG.H); c.restore(); }
    for (const r of F.rings) { const k = 1 - r.t / r.dur; c.save(); c.globalAlpha = 1 - k; c.strokeStyle = r.color; c.lineWidth = r.lw * (1 - k) + 1; c.beginPath(); c.arc(r.x, r.y, r.r0 + (r.r1 - r.r0) * (1 - Math.pow(1 - k, 2)), 0, TAU); c.stroke(); c.restore(); }
    for (const q of F.pops) {
      const Pk = PERKS[q.id], PI = PERK_ICONS[q.id] || [Pk.mega ? 'mega' : 'dmg', []], k = q.t / q.dur, a = Math.min(1, k / 0.3), e = a < 1 ? 0 : (k - 0.3) / 0.7, ee = e * e * (3 - 2 * e);
      const s = a < 1 ? 1 + 0.5 * Math.sin(a * Math.PI / 2) : 1.5 - 1.1 * ee, x = q.x + (q.tx - q.x) * ee, y = q.y + (q.ty - q.y) * ee - Math.sin(ee * Math.PI) * 80;
      c.save(); c.globalAlpha = 1 - Math.max(0, (k - 0.8) / 0.2);
      if (a < 1) { c.globalAlpha *= 0.9 * (1 - a); c.fillStyle = '#fff'; c.beginPath(); c.arc(q.x, q.y, 40 + a * 50, 0, TAU); c.fill(); c.globalAlpha = 1; }
      this.circle(x, y, 34 * s, Pk.color, this.OUT, 3.5); this.shine(x, y, 34 * s); Icons.draw(PI[0], x, y, 20 * s, this.OUT);
      c.restore();
    }
    for (const p of F.parts) { c.save(); c.globalAlpha = Math.min(1, p.t / 0.3); c.fillStyle = p.color; c.beginPath(); c.arc(p.x, p.y, p.r, 0, TAU); c.fill(); c.restore(); }
  },
});
