// Settings: a small page reachable from the workbench cog (or O), saved in the browser. Size (the touch zoom and HUD scale),
// fullscreen, the guide (the ? button), sound and volume, screen shake, and whether a new bullet type pauses the game for its discovery card.
// Also the discovery cards themselves: the first time you pick up a bullet type you have never held, a card swoops in.
const Settings = {
  data: { size: 1.3, guide: false, sound: true, volume: 1, stereo: 0.8, discover: true, shake: 1, craftTip: false, perkTip: false, dungeonCard: true, msgLevel: 3 }, // dungeonCard: the get-ready card on the way into a dungeon; craftTip: the guide's one-time crafting explainer has been shown
  sel: 0, from: 'playing', hit: [], seen: new Set(), benchSeen: new Set(),
  // the list as shown: Size only on touch screens (it does nothing on a keyboard and mouse)
  items() { return this.ITEMS.filter(it => it.id !== 'size' || Input.touch); },
  ITEMS: [
    { id: 'fullscreen', label: 'Fullscreen', action: true, desc: 'Android and desktop. iPhone: Share, then Add to Home Screen, and open it from there' },
    { id: 'save', label: 'Save run', action: true, desc: 'it saves itself at every region, dungeon, shop and workbench; this saves now' },
    { id: 'size', label: 'Size on touch screens', values: [1, 1.15, 1.3], names: ['100%', '115%', '130%'], desc: 'how far the view zooms in and how big the HUD and buttons are (phones and tablets only)' },
    { id: 'guide', label: 'Guide (the ? button)', values: [false, true], names: ['off', 'on'], desc: 'points at what to do next and names things while you play' },
    { id: 'sound', label: 'Sound', values: [false, true], names: ['off', 'on'], desc: 'M also toggles it' },
    { id: 'stereo', label: 'Stereo', values: [0, 0.5, 0.8], names: ['off', 'soft', 'wide'], desc: 'sounds come from where they happen: a kill on the left pops in your left ear' },
    { id: 'volume', label: 'Volume', values: [0.5, 0.75, 1, 1.5], names: ['50%', '75%', '100%', '150%'], desc: 'music and effects together' },
    { id: 'msgLevel', label: 'Messages', values: [0, 3, 2, 1], names: ['off', 'alerts', 'important', 'everything'], desc: 'the commentary on the left: alerts (act now), important (+ news), everything (+ play-by-play)' },
    { id: 'shake', label: 'Screen shake', values: [0, 0.4, 1], names: ['off', 'low', 'full'], desc: 'how hard the screen jolts on hits, blasts and bosses' },
    { id: 'discover', label: 'New segment cards', values: [false, true], names: ['off', 'on'], desc: 'the first time you pick up a bullet type you have never held, the game pauses and shows what it does' },
  ],
  load() {
    try { Object.assign(this.data, JSON.parse(localStorage.getItem('centipede.settings') || '{}')); } catch (e) {}
    try { this.seen = new Set(JSON.parse(localStorage.getItem('centipede.seen') || '[]')); } catch (e) {}
    // the kinds you have looked at on the workbench: anything else there wears a gold NEW badge (seeded from `seen` the first time)
    try { const raw = localStorage.getItem('centipede.benchSeen'); this.benchSeen = new Set(raw ? JSON.parse(raw) : [...this.seen]); } catch (e) { this.benchSeen = new Set(this.seen); }
    this.apply();
  },
  save() { try { localStorage.setItem('centipede.settings', JSON.stringify(this.data)); localStorage.setItem('centipede.seen', JSON.stringify([...this.seen])); localStorage.setItem('centipede.benchSeen', JSON.stringify([...this.benchSeen])); } catch (e) {} },
  apply() {
    SFX.muted = !this.data.sound;
    if (SFX.master) SFX.master.gain.value = 0.22 * this.data.volume;
    if (typeof Bot !== 'undefined' && Bot.guide !== this.data.guide) { Bot.guide = this.data.guide; if (Bot.guide) { Bot.on = false; Bot.offline = true; Bot.retryT = 1e9; } }
    if (typeof TOUCH !== 'undefined') TOUCH.place();
  },
  size() { return this.data.size; },
  value(it) { if (it.id === 'fullscreen') return Input.inFullscreen() ? 'on' : 'off'; if (it.id === 'save') return Save.has() ? `saved ${Save.when()}` : 'nothing saved'; const i = it.values.indexOf(this.data[it.id]); return it.names[i < 0 ? 0 : i]; },
  change(it, dir) {
    if (it.action) { if (it.id === 'fullscreen') Input.toggleFullscreen(); if (it.id === 'save') Save.write(true); return; }
    let i = it.values.indexOf(this.data[it.id]); if (i < 0) i = 0; i = (i + dir + it.values.length) % it.values.length; this.data[it.id] = it.values[i];
    if (it.id === 'guide') { Bot.guide = this.data.guide; if (Bot.guide) Bot.on = false; }
    if (it.id === 'sound') SFX.muted = !this.data.sound;
    if (it.id === 'volume') { SFX.init(); if (SFX.master) SFX.master.gain.value = 0.22 * this.data.volume; SFX.play('dot'); }
    if (it.id === 'size') { this.apply(); Dungeon.center(); }
    this.save(); SFX.play('dot');
  },
  open(from) { this.from = from || G.state; G.state = 'settings'; this.sel = 0; this.scroll = 0; },
  close() { G.state = this.from === 'settings' ? 'playing' : this.from; this.save(); },
  update() {
    const n = this.items().length;
    if (Input.once('Escape') || Input.once('KeyO') || Input.once('KeyP')) { this.close(); return; }
    if (Input.once('KeyW') || Input.once('ArrowUp')) this.sel = (this.sel + n - 1) % n;
    if (Input.once('KeyS') || Input.once('ArrowDown')) this.sel = (this.sel + 1) % n;
    if (Input.once('KeyA') || Input.once('ArrowLeft')) this.change(this.items()[this.sel], -1);
    if (Input.once('KeyD') || Input.once('ArrowRight') || Input.once('Enter') || Input.once('Space')) this.change(this.items()[this.sel], 1);
  },
  tap(p) { const V = this.view; for (const h of this.hit) if (!h.close && !h.panel && V && (p.y < V.top - 4 || p.y > V.bottom)) continue; else if (p.x >= h.x && p.x <= h.x + h.w && p.y >= h.y && p.y <= h.y + h.h) { if (h.close) { this.close(); return; } if (h.panel) { this.change(this.items()[this.sel], h.dir); return; } if (h.dir === 0) { if (this.sel !== h.i) SFX.play('dot'); this.sel = h.i; return; } this.sel = h.i; this.change(this.items()[h.i], h.dir); return; } }, // a row selects; its value (or the panel's arrows) changes it
  // ---- discovery cards ----
  // a bullet type you have never held: remember it, and (if the cards are on) pause the game and show it
  discover(id) {
    if (this.seen.has(id)) return false;
    this.seen.add(id); this.save();
    if (!this.data.discover || G.state !== 'playing') return false;
    G.discover = { id, t: 0 }; G.state = 'discover'; SFX.play('discover');
    return true;
  },
  // the guide's crafting explainer: the first time a fusion is affordable (ever, in this browser), the game pauses on a
  // card that says what crafting is and why it matters, with a button straight into the workbench. f = the fusion
  explainCraft(f) {
    if (this.data.craftTip || G.state !== 'playing') return false;
    this.data.craftTip = true; this.save();
    G.discover = { tip: 'craft', f, t: 0 }; G.state = 'discover'; SFX.play('discover');
    return true;
  },
  // the first perks you ever buy: a card saying the PERKS tab of the workbench keeps every card in full. ids = just bought
  perkTip(ids) {
    if (this.data.perkTip || G.state !== 'playing') return false;
    this.data.perkTip = true; this.save();
    G.discover = { tip: 'perks', ids, t: 0 }; G.state = 'discover'; SFX.play('discover');
    return true;
  },
  // the swarm's card: the first time you run into its sealed walls, the game pauses on why you cannot cross
  swarmCard() {
    if (G.state !== 'playing' || !G.swarm) return false;
    G.discover = { tip: 'swarm', t: 0 }; G.state = 'discover'; SFX.play('roar');
    return true;
  },
  // the get-ready card: on the way into a dungeon (or the saucer deck) the game pauses on what it is and what to do. B = the
  // place's briefing {name, color, lines}. Returns false when the card is off (the caller posts the lines as messages)
  dungeonCard(B) {
    if (!this.data.dungeonCard || G.state !== 'playing') return false;
    G.cam.x = 0; G.cam.y = 0; // the maze or the deck behind the card (the camera only moves in there on the first played frame)
    G.discover = { tip: 'dungeon', B, t: 0 }; G.state = 'discover';
    return true;
  },
  updateDiscover(dt) {
    const D = G.discover; if (!D) { G.state = 'playing'; return; }
    D.t += dt;
    if (D.tip === 'leave') { // the dungeon's exit: EXIT (left) or STAY (right). A / D or the arrows choose, ENTER confirms, ESC stays; a tap picks a button
      if (Input.once('KeyA') || Input.once('ArrowLeft')) { if (D.sel !== 0) SFX.play('tab'); D.sel = 0; }
      if (Input.once('KeyD') || Input.once('ArrowRight')) { if (D.sel !== 1) SFX.play('tab'); D.sel = 1; }
      if (Input.once('KeyW') || Input.once('KeyS') || Input.once('ArrowUp') || Input.once('ArrowDown')) { D.sel = 1 - D.sel; SFX.play('tab'); }
      let pick = null; if (Input.once('Escape')) pick = 1;
      if (D.t > 0.25) { if (Input.once('KeyC')) pick = 0; else if (Input.once('KeyP')) pick = 1; else if (Input.once('Enter') || Input.once('Space')) pick = D.sel; }
      if (pick !== null) { G.discover = null; G.state = 'playing'; if (pick === 0) Dungeon.exit(false); else Dungeon.stay(); }
      return;
    }
    if (Input.once('KeyC') && (!D.tip || D.tip === 'dungeon')) { const k = D.tip ? 'dungeonCard' : 'discover'; this.data[k] = !this.data[k]; this.save(); SFX.play('dot'); }
    if (D.t > 0.4 && ((Input.once('KeyP') && D.tip !== 'dungeon') || (D.tip === 'craft' && Input.once('KeyC')))) { const tip = D.tip; G.discover = null; G.state = 'paused'; Build.open(tip === 'craft' ? 'lab' : null); if (tip === 'perks') { Build.setPanel('perks'); Build.row = 'perks'; } Player.invuln = Math.max(Player.invuln, 1); return; } // straight to the workbench to fit the new piece
    if (D.t > 0.4 && (Input.once('Enter') || Input.once('Space') || Input.once('Escape'))) { G.discover = null; G.state = 'playing'; Player.invuln = Math.max(Player.invuln, 1); }
  },
  // how a bullet fires, in a sentence, from its kind and bits
  howItFires(B) {
    const k = B.kind, el = (B.elems || []).map(e => ELEMENTS[e].name.toLowerCase());
    if (k === 'head') return 'Worn on the head, always on in the overworld: ' + B.desc.replace(/^head gear: /, '') + '. Head-first crashes bounce you off, and it feeds segment 1 its element.';
    let s = k === 'trail' ? 'It does not shoot: the segment lays a patch of ' + B.trail + ' where it is, every ' + B.cd + 's.'
      : k === 'orbit' ? 'It circles the segment as a shield for ' + B.life + 's and hits whatever it touches.'
      : k === 'homing' ? 'It flutters after the nearest enemy for ' + B.life + 's' + (B.blast ? ' and blows up on it: everything within ' + B.blast + ' px is caught in the blast.' : '.')
      : k === 'bounce' ? 'It ricochets off walls, trees and boulders ' + B.bounces + ' times.'
      : k === 'mine' ? 'It is dropped behind you and blows up when something comes near. A Pulse ring that sweeps over it sets off a RESONANCE: a bigger blast and a shockwave ring of its own.'
      : k === 'pulse' ? 'A shockwave ring around the segment, only when an enemy is within ' + Math.round(150 * G.mods.range * G.mods.range) + ' px. It sets off your mines it sweeps over, with a RESONANCE: a bigger blast and a ring of their own.'
      : k === 'boomerang' ? 'It flies out and comes back, hitting on both legs.'
      : k === 'chain' ? 'It hops from enemy to enemy, ' + B.hops + ' hits, then comes home.'
      : B.laser ? 'A laser that hits up to ' + ((B.pierce || 0) + 1) + ' in a line' + (B.maxInflight ? ', ' + B.maxInflight + ' in the air at once' : '') + '.'
      : B.spear ? 'A thrown spear that skewers ' + ((B.pierce || 0) + 1) + ' in a line.'
      : B.burst ? 'A burst of ' + B.burst + ' shots; all must return before it reloads.'
      : B.pierce >= 99 ? 'It pierces everything on its line.' : B.pierce ? 'It pierces ' + (B.pierce + 1) + ' enemies.' : 'A straight shot at the nearest enemy.';
    if (B.bounces && k === 'shot') s += ' It ricochets off walls, trees and boulders ' + B.bounces + ' times, each bounce hitting softer (1/2, 1/3, 1/4...).';
    if (el.length) s += ' Element: ' + el.join(' and ') + '.';
    if (B.wallMult) s += ' It wrecks walls (×' + B.wallMult + ').';
    if (B.combo === 'sonar' || B.combo === 'sonicboom') s += ' Whatever it touches goes berserk and attacks its own kind.';
    if (B.combo === 'sonicboom') s += ' And it blows up where it stands: everything within ' + B.blast + ' px is caught.';
    return s;
  },
};
