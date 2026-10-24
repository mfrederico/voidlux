// Save and continue. The run is written to localStorage by itself at every region, dungeon exit, perk shop and workbench
// (and on demand from the settings page), and the title screen offers to continue it. A continue starts at the beginning
// of the saved region (the world is generated fresh) with the body, bag, locked build, perks, dots, ghosts, score, power
// and mega exactly as they were. Dying clears the save: it is a roguelike. The best score is kept separately.
const Save = {
  KEY: 'centipede.run', data: null,
  load() { try { this.data = JSON.parse(localStorage.getItem(this.KEY) || 'null'); } catch (e) { this.data = null; } if (this.data && this.data.v !== 1) this.data = null; return this.data; },
  has() { return !!this.data; },
  // a sentence for the title screen and the settings page
  summary() { const d = this.data; return d ? `level ${d.level} · region ${d.region + 1} · score ${d.score} · ${d.segments.length} segment${d.segments.length === 1 ? '' : 's'}` : 'no saved run'; },
  when() { const d = this.data; if (!d) return ''; const m = Math.round((Date.now() - d.t) / 60000); return m < 1 ? 'just now' : m < 60 ? `${m} min ago` : m < 1440 ? `${Math.round(m / 60)} h ago` : `${Math.round(m / 1440)} d ago`; },
  write(manual) {
    if (G.state === 'title' || G.state === 'gameover' || !Player.alive || G.god) { if (manual) G.msg(G.god ? 'God mode runs are not saved.' : 'Nothing to save yet.', '#aaa'); return false; }
    const P = Player;
    const d = { v: 1, t: Date.now(), nightmare: G.nightmare || 0, level: G.level, region: this.region(), score: G.score, kills: G.kills, powerMult: G.powerMult, dungeonsCleared: G.dungeonsCleared, maxLen: G.maxLen, pity: G.pity || 0,
      mods: G.mods, perks: G.perks, bought: G.bought || [], ghosts: G.ghosts, dots: G.dots, mega: G.mega,
      segments: P.segments.filter(s => !P.isMinion(s)).map(s => s.id), bag: P.bag, blueprint: P.blueprint, locked: !!P.autoRebuild, maxSegs: P.maxSegs, head: P.head.id, gear: P.gear, gears: P.gears || [], spitting: !!P.spitting,
      shop: G.state === 'perk' ? { choices: G.perkChoices, free: G.perkFree } : null }; // an open perk shop comes back open
    try { localStorage.setItem(this.KEY, JSON.stringify(d)); this.data = d; if (manual) { G.msg('Run saved. Continue it from the title screen.', '#9bf06a'); SFX.play('pickup'); } return true; }
    catch (e) { if (manual) G.msg('Could not save: the browser blocks storage here.', '#ff9b8b'); return false; }
  },
  // a saved bullet id the game knows; a Ricochet (<root>_ricN) only exists once something has made it, so make it again here
  known(id) { const m = /^(.+)_ric([1-3])$/.exec(id); if (!BULLETS[id] && m && BULLETS[m[1]]) { let k = m[1]; for (let i = 0; i < +m[2]; i++) k = ricochetOf(k); } return !!BULLETS[id]; },
  // where a resume starts: the region you are in (inside a maze or on the deck, the one whose entrance you took: the
  // player's x there is screen space); in a beaten boss's arena, the region after it (a resume rebuilds the world, and
  // the arena would come back with its boss alive)
  region() {
    const ri = G.mode === 'dungeon' ? Dungeon.region : G.mode === 'deck' ? Deck.region : World.regionAt(Player.x), R = World.regions[ri];
    return R && R.boss && R.bossDone ? ri + 1 : ri;
  },
  clear() { this.data = null; try { localStorage.removeItem(this.KEY); } catch (e) {} },
  // a fresh world, then the saved run dropped into the start of its region
  resume() {
    const d = this.data; if (!d) return false;
    G.start();
    const ri = Math.max(0, d.region | 0); World.ensure(ri + 1); const R = World.regions[ri];
    G.nightmare = d.nightmare || 0; G.level = d.level; G.score = d.score; G.kills = d.kills; G.powerMult = d.powerMult; G.dungeonsCleared = d.dungeonsCleared; G.maxLen = d.maxLen; G.pity = d.pity || 0;
    G.mods = Object.assign(Perks.defaults(), d.mods || {}); G.perks = (d.perks || []).slice(); G.bought = (d.bought || []).slice();
    G.ghosts = d.ghosts; G.dots = d.dots; G.mega = Object.assign({ id: 'nuke', owned: ['nuke'], charge: 0, used: 0 }, d.mega || {}); if (!d.mega || !d.mega.owned) G.mega.owned = [...new Set(['nuke'].concat(G.mega.id ? [G.mega.id] : []))]; if (!G.mega.id) G.mega.id = 'nuke'; // (older saves: one shop mega, or none)
    const P = Player;
    P.gear = d.gear && BULLETS[d.gear] ? d.gear : null; P.gears = (d.gears || []).filter(id => BULLETS[id]); if (P.gear && !P.gears.includes(P.gear)) P.gears.push(P.gear); /* (older saves: the worn one starts the rack) */ P.maxSegs = d.maxSegs; P.segments = d.segments.filter(id => this.known(id)).map(id => P.mkSeg(id)); P.bag = d.bag.filter(id => this.known(id)); P.blueprint = (d.blueprint || []).filter(id => this.known(id)); P.autoRebuild = !!d.locked;
    if (d.head && BULLETS[d.head]) P.head.id = d.head; P.spitting = !!d.spitting;
    P.maxLen = Math.max(P.segments.length, d.maxLen || 0);
    G.skipBossesBefore(ri); G.regionMax = Math.max(0, ri - 1); // the region intro (and a boss climb) fires again on the first step
    P.x = R.x0 + 120; P.y = CFG.WORLD_H / 2; P.angle = 0; P.initTrail(P.x, P.y, 0); P.resolveBuild(); P.computeSegPos(); P.invuln = 3;
    P.ready = false; P.reorient = true; P.reorientText = 'Welcome back'; // the world holds still until you press a direction
    for (const id of [...d.segments, ...d.bag]) Settings.seen.add(id);
    Minions.recall(); Combat.reset(); G.snapCam();
    { const th = Music.levelTheme(R.level); if (th) Music.start(th); }
    G.msg(`Welcome back: ${World.regionName(R)}, level ${d.level}, ${d.score} points. Saved ${this.when()}.`, '#ffd166');
    if (d.shop && d.shop.choices) { // quit in the perk shop: it opens again as you left it (the free pick, the shelf, what you bought)
      G.perkChoices = d.shop.choices.filter(id => PERKS[id]); G.perkFree = d.shop.free | 0; G.perkSel = 0; G.perkLeaveSel = false; G.state = 'perk'; G.perkReel = Perks.mkReel(G.perkChoices);
      if (Music.THEMES.perk_shop) Music.start('perk_shop');
    }
    return true;
  },
};
