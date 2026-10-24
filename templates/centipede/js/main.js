const G = {
  state: 'title', mode: 'world', time: 0, score: 0, kills: 0, powerMult: 1, dungeonsCleared: 0, maxLen: 0, regionMax: 0, pity: 0, mods: null, perks: [], perkChoices: [], perkSel: 0, perkFree: 0, ghosts: 0, dots: 0, god: false, level: 1, cinematic: null, won: null, mega: { id: 'nuke', owned: ['nuke'], charge: 0, used: 0 }, /* id null = follows your build */ freeze: 0, flash: 0, tint: null, storm: 0, pendingBlast: null, ring: null,
  cam: { x: 0, y: 0 }, msgs: [], banner: null, shake: 0, enemies: [], deathCause: '', best: 0,
  // the commentary, by level: 3 ALERT (you are losing something or must act now), 2 NOTICE (one-off news worth a glance),
  // 1 CHATTER (play-by-play). Settings.data.msgLevel is the lowest level shown (0 = off, 4 hides all). A line the rules
  // below don't name goes by its colour: red is an alert, grey is chatter, the rest notices. A repeat within a few
  // seconds bumps a ×N count on the line already up instead of stacking another
  MSG_RULES: [
    [3, /knocked (off|out)|bit off|bit your|ate a segment|cut off a segment|ran over a segment|took a segment|burst through a segment|slurped your|lifted your|on your tail: get|step off the line|step aside|find a gap|out of the shadow|controls are backwards|Spitting pebbles|Last stand|cut you in half|tore off your|took your|pulling you in|is coming down/i],
    [1, /^(Melted a tree|Fried a boulder|Wall breached|Ate a ghost|Autopilot|Shed |Cleanse|Parasites cleansed|Weapons back ON|Keep moving|A spare from|Landed|The spell breaks|.* fizzled away|The \w+ smashed into|Your \w+ leaps off|Your diving|Your \w+ blew itself|A .* hatched onto|.* stayed loyal|\+ .*: back into your loadout|.* into the bag \(|The parasite|The .* died\. |A mole made off|The hammer is spent|The Siphon coughs|The tree hums|The boulder stirs|Engines turn over|Something stirs|The leaves hum|The cannon swings|A ghost peeks|.* slain!|.* defeated!|Fused |BLUEPRINT)/],
  ],
  msgLevel(text, color) { for (const [lv, re] of this.MSG_RULES) if (re.test(text)) return lv; return /^#(ff9b8b|ff7b6b|ff7a5c|ff5c5c)$/i.test(color || '') ? 3 : /^#(ccc|aaa|ddd)$/i.test(color || '') ? 1 : 2; },
  msg(text, color, level) {
    const lv = level || this.msgLevel(text, color), min = Settings.data.msgLevel;
    if (!(lv >= min) || !min) return;
    const same = this.msgs.find(m => m.text === text && m.t > 0); if (same) { same.n = (same.n || 1) + 1; same.t = 4; return; } // a repeat: count it
    this.msgs.push({ text, color: color || '#fff', t: 4, lv }); if (this.msgs.length > 6) this.msgs.shift();
  },
  // the title's ENABLE SOUND square: wake the audio (a browser needs a tap for that) and the title theme; once on, it mutes and unmutes
  // the title's buttons as a little grid: SOUND and LOAD RUN on the left, PLAY in the middle, FULL SCREEN, NEW RUN and QUIT on the right.
  // A/D (or the arrows) step between the columns, W/S between the rows of one; only the buttons on screen count
  titleNav() {
    const T = G.titleBtns || {}, cols = [['sound', 'load'].filter(k => !!T[k]), ['play'], ['full', 'newrun', 'quit'].filter(k => k === 'newrun' ? !!G.newRunBox : !!T[k])];
    let sel = this.titleSel || 'play'; let ci = cols.findIndex(c => c.includes(sel)); if (ci < 0) { sel = 'play'; ci = 1; }
    let ri = cols[ci].indexOf(sel);
    const dx = (Input.once('KeyD') || Input.once('ArrowRight') ? 1 : 0) - (Input.once('KeyA') || Input.once('ArrowLeft') ? 1 : 0), dy = (Input.once('KeyS') || Input.once('ArrowDown') ? 1 : 0) - (Input.once('KeyW') || Input.once('ArrowUp') ? 1 : 0);
    if (dx) { let nc = ci + dx; while (nc >= 0 && nc < cols.length && !cols[nc].length) nc += dx; if (nc >= 0 && nc < cols.length) { ci = nc; ri = Math.min(ri, cols[ci].length - 1); } }
    if (dy) ri = Math.max(0, Math.min(cols[ci].length - 1, ri + dy));
    const next = cols[ci][ri] || 'play'; if (next !== this.titleSel && (dx || dy)) SFX.play('tab'); this.titleSel = next;
  },
  titleSound() {
    SFX.init();
    if (!SFX.muted && !Music.on && Music.THEMES.title) { Music.start('title'); return; }
    SFX.muted = !SFX.muted; Settings.data.sound = !SFX.muted; Settings.save();
    if (SFX.muted) Music.stop(); else if (Music.THEMES.title) Music.start('title');
  },
  hitSolid(p) {
    if (this.mode === 'dungeon') return Dungeon.isWallAt(p.x, p.y);
    if (this.mode === 'deck') return Deck.isSolid(p);
    if (p.x < CFG.HEDGE || p.y < CFG.HEDGE || p.y > CFG.WORLD_H - CFG.HEDGE) return true;
    const w = World.wallAt(p.x, p.y, p.size);
    if (w) { if (p.owner === 'player') World.damageWall(w, p.dmg * ((p.type && p.type.wallMult) || 1)); return true; }
    return World.solidAt(p.x, p.y, p.size); // trees and boulders stop bullets: go around them

  },
  start() {
    Music.stop();
    this.state = 'playing'; this.mode = 'world'; this.uniqueSeen = new Set(); this.score = 0; this.kills = 0; this.powerMult = 1; this.dungeonsCleared = 0; this.maxLen = 0; this.regionMax = 0; this.pity = 0; this.mods = Perks.defaults(); this.perks = []; this.ghosts = 0; this.dots = 0; this.perkFree = 0; this.god = false; this.level = 1; this.cinematic = null; this.won = null; this.mega = { id: 'nuke', owned: ['nuke'], charge: 0, used: 0 }; this.freeze = 0; this.flash = 0; this.tint = null; this.storm = 0; this.bolt = null; this.lich = null; this.deepFreeze = 0; this.hive = null; this.uniqueSeen = new Set(); this.pendingBlast = null; this.ring = null;
    for (const id of CFG.START_SEGMENTS) Settings.seen.add(id); Settings.seen.add(CFG.HEAD_SPIT); // what you start with needs no card
    if (this.godArmed) { this.godArmed = false; setTimeout(() => { if (!this.god) this.toggleGod(); }, 0); } // ?god=1, or the title taps
    if (this.deckArmed) { this.deckArmed = false; setTimeout(() => this.playDeck(), 0); } // ?deck=1: straight onto the saucer deck
    this.nightmare = 0; this.crowd = 0; this.lootScale = 1; this.deckStats = { entered: 0, cleared: 0, secs: 0, lost: 0 }; // for the trial log
    this.msgs = []; this.banner = null; this.shake = 0; this.deathCause = ''; this.swarm = null; this.swarmT = 0; this.swarmCd = 0; this.swarmRegion = -1; this.hauntedIntro = false; this.jungleIntro = false; this.farmIntro = false; this.circusIntro = false; this.slowmo = 0; this.splats = []; this.rubber = 0;
    Combat.reset(); Enemies.reset(); Minions.reset(); World.reset();
    this.enemies = Enemies.list;
    Player.reset(300, CFG.WORLD_H / 2);
    this.cam.x = 0; this.cam.y = Player.y - CFG.H / 2;
    this.msg('Keep moving. Grab loot. Become something amazing.', '#fff');
    { const th = Music.levelTheme(1); if (th) Music.start(th); } // the first region never 'changes' level, so its music starts here
  },
  // level up: a short climb cinematic, then the boss arena
  ascend(R) {
    this.cinematic = { t: 3.2, total: 3.2, region: R };
    Combat.reset(); SFX.play('power');
    this.msg(`Six walls broken. Climbing to the ${World.regionName(R)}...`, '#ffd166');
  },
  // the boss is dead: tally the winnings, shrink the body back to the starting size (the cap too; the extra bullets go to
  // the bag), and show the celebration. Enter carries on into the next level, whose first wall is already open.
  winLevel(level) {
    Music.stop();
    const B = BOSSES[World.bossFor(level)], shrink = CFG.WIN_RESET > 0 ? Player.shrinkTo(CFG.WIN_RESET) : { kept: Player.segments.length, bagged: 0, lost: 0, none: true };
    this.won = { level, boss: B.name, score: this.score, kills: this.kills, dungeons: this.dungeonsCleared, perks: this.perks.slice(), dots: this.dots, ghosts: this.ghosts, minions: Minions.count(),
                 body: Player.segments.map(s => s.id), bag: Player.bag.length, power: this.powerMult, maxLen: this.maxLen, shrink, t: 0, demoEnd: level === 5 }; // the Clown Car closes the demo
    this.level = level + 1;
    this.state = 'won';
    this.banner = null;
    this.mega.charge = CFG.MEGA_MAX; Player.rebuild();
    if (!Music.fanfare(level)) { SFX.play('power'); setTimeout(() => SFX.play('pickup'), 500); setTimeout(() => SFX.play('power'), 900); } // the level's celebration tune (complete_levelN), or the chime
  },
  // ---- testing aids ----
  prof: { upd: 0, draw: 0, frame: 16.7, last: 0, slow: 0 }, // the profiler's numbers (drawn in god mode): smoothed ms for update and draw, the frame interval, and how many frames went over 16.7 ms
  godTaps: 0, godTapT: 0, // five quick taps on the word CENTIPEDE on the title arm god mode for the next run (phones have no G key)
  toggleGod() {
    this.god = !this.god;
    if (this.god) {
      this.dots = 999; this.ghosts = 99; Player.maxSegs = Math.max(Player.maxSegs, 12);
      for (const id of Object.keys(BULLETS)) if (!BULLETS[id].craft && !Player.bag.includes(id)) Player.bag.push(id); // one of every base bullet to craft with
      this.godMortal = false;
      this.msg('GOD MODE: invulnerable, 999 dots, 99 ghosts, mega charged, cap 12, every bullet in the bag. N next region · B crab · L graveyard · Y Skull King · O jungle · , Serpent · X farm · 0 Overlord · . circus · Q Clown Car · Z minion · 1-9 presets · V mortal. G to turn off.', '#ffd166');
    } else { Player.invuln = 0; this.msg('God mode off', '#ccc'); }
  },
  // earlier arenas count as beaten when you warp past them (their walls open, their bosses gone)
  skipBossesBefore(ri) {
    for (const Q of World.regions) if (Q.boss && Q.idx < ri && !Q.bossDone) { Q.locked = false; Q.bossDone = true; const b = Enemies.list.find(e => e.kind === 'boss' && e.region === Q.idx); if (b) b.dead = true; }
  },
  // jump straight into a boss arena (level 1 = the crab, 2 = the Skull King), next to the sleeping boss
  warpBoss(level) {
    level = level || 1;
    const ri = CFG.BOSS_EVERY * level - 1; this.level = Math.max(this.level, level); World.ensure(ri + 1); const R = World.regions[ri];
    Music.stop(); this.skipBossesBefore(ri);
    this.regionMax = Math.max(this.regionMax, ri); this.cinematic = null;
    const boss = Enemies.list.find(e => e.kind === 'boss' && e.region === ri && !e.dead);
    Player.x = boss ? boss.x - 700 : R.x0 + 200; Player.y = CFG.WORLD_H / 2; Player.angle = 0; Player.initTrail(Player.x, Player.y, 0); Player.computeSegPos(); Player.invuln = 2;
    for (const e of Enemies.list) if (e.kind !== 'boss' && e.region === ri && !e.minion) e.dead = true;
    if (level >= 2) this.levelLoadout(level);
    Minions.recall(); Combat.reset(); this.snapCam(); this.msg(`Warped to the ${World.regionName(R)}. V toggles mortal for a real test.`, '#ffd166');
  },
  // a warp into a later level hands you the body a real run would have by then (otherwise every monster looks overpowered)
  levelLoadout(level) {
    if (this.perks.length || this.powerMult > 1) return; // you already built something: keep it
    this.applyPreset(level >= 3 ? 4 : 3);
    const extra = level >= 5 ? ['brawn', 'brawn', 'brawn', 'brawn', 'velocity', 'body', 'body', 'body', 'trigger', 'twin'] : level >= 4 ? ['brawn', 'brawn', 'brawn', 'velocity', 'body', 'body', 'trigger'] : level >= 3 ? ['brawn', 'brawn', 'velocity', 'body'] : ['brawn'];
    for (const id of extra) { PERKS[id].apply(); this.perks.push(id); }
    while (Player.weaponCount() < Player.maxSegs) Player.segments.push(Player.mkSeg(World.pickBullet(2))); // Bigger Body only opens a slot: fill them as a run would have
    Player.resolveBuild(); Player.computeSegPos(); Player.lock(true); // (the preset locked its own body: lock the filled one, or the rebuild bags the fill)
    this.msg(`Level ${level} loadout: ${GOD_PRESETS[level >= 3 ? 4 : 3].name} plus ${extra.join(', ')} (what a run would have by now). Press 1-9 for another preset.`, '#ffd166');
  },
  // NIGHTMARE: the Clown Car is scrap; the run loops back to the meadow with everything you built, and everything is harder
  enterNightmare() {
    this.nightmare = 1; this.level = 1; this.regionMax = 0; this.won = null; this.state = 'playing'; this.mode = 'world'; this.cinematic = null;
    Combat.reset(); Enemies.reset(); World.reset(); this.enemies = Enemies.list; Minions.recall();
    const P = Player; P.x = 300; P.y = CFG.WORLD_H / 2; P.angle = 0; P.initTrail(P.x, P.y, 0); P.computeSegPos(); P.invuln = 3; P.ready = false; P.reorient = true; P.reorientText = 'Nightmare';
    this.snapCam(); this.hauntedIntro = this.jungleIntro = this.farmIntro = this.circusIntro = false;
    Music.stop(); { const th = Music.levelTheme(1); if (th) Music.start(th); }
    this.banner = { text: 'NIGHTMARE', sub: 'the same five levels, and they remember you', t: 3.5 }; SFX.play('roar'); G.shake = 0.6;
    this.msg('NIGHTMARE: monsters are tougher, shoot faster and come in bigger crowds. The bosses have new tricks. Beat the Clown Car again to finish.', '#c58aff');
    Save.write();
  },
  // ?deck=1: warp to the farm with a level-4 body and board the saucer deck at once (playtesting the Space Invaders level)
  playDeck() {
    this.warpLevel(4);
    const en = World.entrances.find(e => !e.used && World.regions[e.region] && World.regions[e.region].farm);
    if (en) { Player.x = en.x; Player.y = en.y; this.snapCam(); Deck.enter(en); } else this.msg('No farm dungeon entrance found for the saucer deck.', '#ff9b8b');
  },
  // jump to the first region of a level (2 = the graveyard)
  warpLevel(level) {
    const ri = CFG.BOSS_EVERY * (level - 1); this.level = Math.max(this.level, level); World.ensure(ri + 1); const R = World.regions[ri];
    Music.stop(); this.skipBossesBefore(ri); this.regionMax = Math.max(this.regionMax, ri - 1); this.cinematic = null;
    Player.x = R.x0 + 120; Player.y = CFG.WORLD_H / 2; Player.angle = 0; Player.initTrail(Player.x, Player.y, 0); Player.computeSegPos(); Player.invuln = 5;
    this.levelLoadout(level);
    Minions.recall(); Combat.reset(); this.snapCam(); this.msg(`Warped to level ${level}: ${World.regionName(R)}`, '#ffd166');
  },
  // god-mode loadout presets: body, cap, perks, empty bag
  applyPreset(i) {
    const Pz = GOD_PRESETS[i]; if (!Pz) return;
    this.mods = Perks.defaults(); this.perks = []; this.powerMult = 1;
    Player.maxSegs = Pz.cap; Player.segments = []; Player.bag = [];
    for (const id of Pz.perks) { if (id === 'body') { /* cap is set explicitly by the preset */ } else { PERKS[id].apply(); } this.perks.push(id); }
    Player.segments = Pz.segs.map(id => Player.mkSeg(id)); Player.resolveBuild(); Player.computeSegPos(); Player.lock(true);
    this.msg(`Preset ${i + 1}: ${Pz.name}  ·  ${Pz.segs.map(id => BULLETS[id].name).join(', ')}  ·  perks ${Pz.perks.join(', ') || 'none'}`, '#ffd166');
  },
  warpRegion() {
    const ri = World.regionAt(Player.x) + 1; World.ensure(ri + 1);
    const R = World.regions[ri]; Player.x = R.x0 + 120; Player.y = CFG.WORLD_H / 2; Player.angle = 0; Player.initTrail(Player.x, Player.y, 0); Player.computeSegPos(); Player.invuln = 5;
    Minions.recall(); this.snapCam(); this.msg(`Warped to region ${ri + 1}`, '#ffd166');
  },
  gameOver() {
    Music.stop();
    this.state = 'gameover'; Save.clear(); // a roguelike: the run is over
    if (this.score > this.best) { this.best = this.score; try { localStorage.setItem('centipede_best', String(this.best)); } catch (e) {} }
  },
  // where the world camera wants to be: the player, a little ahead of the nose (the view is smaller than the canvas on zoomed touch screens)
  camTarget() { const vw = Render.viewW(), vh = Render.viewH(); return { x: Player.x - vw / 2 + Math.cos(Player.angle) * 90, y: Player.y - vh / 2 + Math.sin(Player.angle) * 40 }; },
  clampCam() { const vw = Render.viewW(), vh = Render.viewH(); this.cam.x = U.clamp(this.cam.x, 0, World.regions.length * CFG.REGION_W - vw); this.cam.y = U.clamp(this.cam.y, 0, CFG.WORLD_H - vh); },
  snapCam() { const t = this.camTarget(); this.cam.x = t.x; this.cam.y = t.y; this.clampCam(); }, // no glide: used after a dungeon exit and warps
  enterDungeon(en) { if (CFG.DECK_MODE && World.regions[en.region] && World.regions[en.region].farm) Deck.enter(en); else Dungeon.enter(en); }, // the farm's dungeons are the saucer deck

  update(dt) {
    this.time += dt;
    Bot.update(dt); // the autopilot presses its keys before anything reads them
    if (this.state === 'title' && window.desktop && Input.once('Escape')) { window.desktop.quit(); return; } // the desktop build: ESC on the title quits
    if (this.state === 'title') { // the title theme (a tracker song named 'title') plays once the browser lets sound run: the first key or tap wakes it, the next Enter starts the game
      const hasTitle = !!Music.THEMES.title, ready = !!(SFX.ctx && SFX.ctx.state === 'running'), wasOn = Music.on;
      if (hasTitle && ready && !Music.on && !SFX.muted) Music.start('title');
      Title.update(dt);
      this.titleNav(); // WASD / arrows walk the buttons; Enter presses the one selected (PLAY by default)
      if (Input.once('Enter')) { const sel = this.titleSel || 'play'; if (sel === 'sound') this.titleSound(); else if (sel === 'newrun') Input.tapKey('KeyN'); else if (sel === 'load') Save.resume(); else if (sel === 'full') { Input.fsAsked = true; Input.toggleFullscreen(); } else if (sel === 'quit' && window.desktop) window.desktop.quit(); else { if (Save.has()) Save.resume(); else this.start(); } } // (the ENABLE SOUND square wakes the music; Enter plays)
      if (Input.once('Space')) { if (Save.has()) Save.resume(); else this.start(); return; } // SPACE always plays (or continues), whatever button is selected: ENTER presses the selected one
      if (Input.once('KeyN') && Save.has()) { Save.clear(); this.start(); SFX.play('pickup'); } // a new run, forgetting the saved one
      return;
    }
    if (Input.once('KeyM')) { SFX.muted = !SFX.muted; Settings.data.sound = !SFX.muted; Settings.save(); this.msg(SFX.muted ? 'Muted' : 'Sound on', '#ccc'); }
    if (Input.once('KeyG') && this.state !== 'paused') this.toggleGod();
    if (Input.once('Backquote')) this.showProf = !this.showProf; // ` shows the profiler on its own (god mode shows it too); ?fps=1 on a phone
    if (this.god) {
      if (!this.godMortal) Player.invuln = Math.max(Player.invuln, 5);
      this.mega.charge = CFG.MEGA_MAX;
      if (this.mode === 'world' && this.state === 'playing') {
        if (Input.once('KeyN')) this.warpRegion();
        if (Input.once('KeyB')) this.warpBoss(1);
        if (Input.once('KeyY')) this.warpBoss(2); // the Skull King
        if (Input.once('Comma')) this.warpBoss(3); // the Jungle Serpent (comma: the digits are presets)
        if (Input.once('KeyZ')) { if (Minions.hatch(null, Player.x, Player.y)) {} else this.msg(`Minion roster full (${Minions.count()}/${Minions.cap()}).`, '#ff9b8b'); } // hatch a minion onto the tail
        if (Input.once('KeyL')) this.warpLevel(2); // the first graveyard region
        if (Input.once('KeyO')) this.warpLevel(3); // the first jungle region
        if (Input.once('KeyX')) this.warpLevel(4); // the first farm region
        if (Input.once('Digit0')) this.warpBoss(4); // the Brain Overlord
        if (Input.once('Period')) this.warpLevel(5); // the first circus region (period: C is CRAFT)
        if (Input.once('Quote')) this.warpBoss(5); // the Clown Car
        if (Input.once('Semicolon')) this.enterNightmare(); // straight into NIGHTMARE with this build (testing)
        if (Input.once('KeyQ')) this.warpBoss(5); // the Clown Car
        if (Input.once('KeyK')) { let best = null, bd = 1e12; for (const b of World.boulders) { if (b.gone) continue; const d2 = U.dist2(b.x, b.y, Player.x, Player.y); if (d2 < bd) { bd = d2; best = b; } } if (best) { if (bd > 400 * 400) { best.x = Player.x + Math.cos(Player.angle) * 170; best.y = Player.y + Math.sin(Player.angle) * 170; } World.wakeRock(best); } else this.msg('No boulder generated yet.', '#ff9b8b'); } // wake the nearest boulder (pulled in front of you if it is far)
        if (Input.once('KeyJ')) Perks.offer(); // open the perk shop (layout testing)
        if (Input.once('KeyI')) { let best = null, bd = 1e12; for (const t of World.trees) { if (!t.jungle || !t.alive || t.sung || t.song > 0) continue; const d2 = U.dist2(t.x, t.y, Player.x, Player.y); if (d2 < bd) { bd = d2; best = t; } } if (best) World.singTree(best); else this.msg('No jungle tree nearby to sing.', '#ff9b8b'); } // make the nearest jungle tree sing
        if (Input.once('KeyU')) { const b = G.enemies.find(e => e.kind === 'boss' && !e.dead && e.awake); if (b) { Combat.damage(b, b.maxhp * 0.19, '#ffd166'); this.msg(`Boss at ${Math.max(0, Math.round(100 * b.hp / b.maxhp))}% (U = -19%)`, '#ffd166'); } } // hurt the boss (music/flee testing)
        if (Input.once('KeyH')) { const ri = World.regionAt(Player.x), en = World.entrances.find(e => !e.used && e.region >= ri) || World.entrances.find(e => !e.used); if (en) { Minions.recall(); this.enterDungeon(en); } else this.msg('No unused dungeon entrance generated yet.', '#ff9b8b'); } // hop into the nearest dungeon
        if (Input.once('KeyV')) { this.godMortal = !this.godMortal; Player.invuln = 0; this.msg(this.godMortal ? 'God mode: now MORTAL (loadout testing)' : 'God mode: invulnerable again', '#ffd166'); }
        for (let k = 1; k <= GOD_PRESETS.length; k++) if (Input.once('Digit' + k)) this.applyPreset(k - 1);
      }
    }
    if (this.state === 'gameover') { if (Input.once('KeyR')) this.start(); else if (Input.once('Escape')) { this.state = 'title'; Music.stop(); } this.fx(dt); Combat.updateFx(dt); return; }
    if (this.state === 'won' && this.won && this.won.demoEnd) { // the end of the demo: into nightmare, or (nightmare done) the finale, then the title
      Combat.updateFx(dt); this.fx(dt);
      if (Input.once('Enter') || Input.once('Space')) {
        Music.stopFanfare();
        if (!this.nightmare) this.enterNightmare();
        else { try { localStorage.setItem('centipede.nightmareCleared', String(Math.max(this.score, +(localStorage.getItem('centipede.nightmareCleared') || 0)))); } catch (e) {} Save.clear(); this.state = 'title'; Music.stop(); }
      }
      return;
    }
    if (this.state === 'won') { Combat.updateFx(dt); if (Input.once('Enter') || Input.once('Space')) { this.state = 'playing'; Music.stopFanfare(); Save.write(); this.msg(`Level ${this.level}. ${CFG.WIN_RESET ? `Body back to ${CFG.WIN_RESET}: the rest is in the bag (P). ` : 'You keep everything. '}The wall ahead is open. Keep crawling.`, '#ffd166'); } this.fx(dt); return; }
    if (this.cinematic) { // ascending to the boss arena
      const c = this.cinematic; c.t -= dt; this.fx(dt);
      if (c.t <= 0) { this.cinematic = null; const R = c.region; Player.x = R.x0 + 160; Player.y = CFG.WORLD_H / 2; Player.angle = 0; Player.initTrail(Player.x, Player.y, 0); Player.computeSegPos(); Player.invuln = 2; Minions.recall(); this.snapCam(); this.msg(`${World.regionName(R)}: the wall ahead is sealed. Find the boss.`, '#ff7a5c'); }
      return;
    }
    if (this.state === 'settings') { Settings.update(); Combat.updateFx(dt); return; }
    if (this.state === 'discover') { Settings.updateDiscover(dt); this.fx(dt); Combat.updateFx(dt); return; }
    if (this.state === 'perk') { Perks.update(dt); Combat.updateFx(dt); return; }
    { const kp = Input.once('KeyP'), kc = !kp && this.state !== 'paused' && Input.once('KeyC'); if (kp || kc) { if (this.state === 'paused') { Build.close(); this.state = 'playing'; } else { this.state = 'paused'; Build.open(Player.fusions().some(f => G.dots >= f.cost) || (kc && Build.labCards().length) ? 'lab' : null); } } } /* a recipe you can afford: straight to the LAB */ // C = CRAFT opens the workbench (P too, from when it was the pause key); on the bench C marks an ingredient, so ESC or P closes it
    if (this.state === 'paused') { Build.update(dt); BenchFx.update(dt); Gallery.update(dt, Gallery.lane || { x0: 120, dx: CFG.W - 110, y: CFG.H - 50 }); return; } // the workbench: the gallery keeps firing while you tinker
    if (Input.once('KeyE')) { if (Mega.ready()) Mega.use(); else this.msg(`${MEGAS[Mega.current()].name} needs ${Math.ceil(CFG.MEGA_MAX - this.mega.charge)} more charge. Kill things.`, '#ccc'); } // with a hammer in hand, E swings it
    // Sacrifice: time stands still, then the blast lands
    if (this.freeze > 0) { this.freeze -= dt; this.fx(dt); if (this.freeze <= 0 && this.pendingBlast) { Mega.blast(this.pendingBlast); this.pendingBlast = null; } return; }
    if (this.storm > 0) this.storm -= dt;
    if (this.deepFreeze > 0) this.deepFreeze -= dt;
    Mega.update(dt); // Hive Mind keeps its numbers up while it lasts
    Player.update(dt);
    if (this.mode === 'world' && !Player.ready) { this.fx(dt); return; }
    if (this.mode === 'world') { World.update(dt); Enemies.update(dt); Enemies.acting = null; } else if (this.mode === 'deck') Deck.update(dt); else Dungeon.update(dt);
    Combat.update(dt);
    this.fx(dt);
    // camera
    if (this.mode === 'world') {
      const t = this.camTarget(), k = U.damp(5, dt);
      this.cam.x += (t.x - this.cam.x) * k; this.cam.y += (t.y - this.cam.y) * k;
      this.clampCam();
    } else { this.cam.x = 0; this.cam.y = 0; }
    if (this.shake > 0) { this.shake -= dt; const s = this.shake * 14 * Settings.data.shake; this.cam.x += U.rand(-s, s); this.cam.y += U.rand(-s, s); } // (scaled by the Screen shake setting: off, low or full)
  },
  fx(dt) {
    for (let i = this.msgs.length - 1; i >= 0; i--) { this.msgs[i].t -= dt; if (this.msgs[i].t <= 0) this.msgs.splice(i, 1); }
    if (this.banner) { this.banner.t -= dt; if (this.banner.t <= 0) this.banner = null; }
    if (this.flash > 0) this.flash -= dt * 1.6;
    if (this.tint) { this.tint.t -= dt; if (this.tint.t <= 0) this.tint = null; }
    if (this.ring) { this.ring.t -= dt; this.ring.r = this.ring.max * (1 - this.ring.t / 0.6); if (this.ring.t <= 0) this.ring = null; }
    if (this.splats) for (let i = this.splats.length - 1; i >= 0; i--) { this.splats[i].t -= dt; if (this.splats[i].t <= 0) this.splats.splice(i, 1); } // cream pies sliding off the screen
  }
};

(function boot() {
  const canvas = document.getElementById('game');
  Render.init(canvas);
  Input.init();
  Bot.init();
  Player.tuneFusions(); // before anything reads bullet damage
  if (window.desktop) document.body.classList.add('desktop');
  if (window.desktop) window.desktop.onBlur(() => { if (G.state === 'playing' && !Bot.on) { G.state = 'paused'; Build.open(); } }); // the desktop build: alt-tab away and the game pauses (on the workbench)
  Settings.load(); Save.load();
  if (Bot.trials) { SFX.muted = true; Music.stop(); } // trials are silent (the saved sound setting loads after Bot.init)
  try { G.best = parseInt(localStorage.getItem('centipede_best') || '0', 10) || 0; } catch (e) {}
  // The logical height is fixed (720); the logical width follows the screen's aspect ratio (within limits) so a
  // phone in fullscreen or a wide monitor shows MORE of the map instead of black bars, and a squarer screen shows less.
  // Everything lays out from CFG.W at draw time, so changing it is enough; the dungeon re-centres and the touch
  // buttons hug the right edge.
  function fit() {
    const vw = window.innerWidth, vh = window.innerHeight;
    const W = Math.round(U.clamp(CFG.H * vw / vh, CFG.W_MIN, CFG.W_MAX) / 2) * 2;
    if (W !== CFG.W) { CFG.W = W; canvas.width = W; Render.init(canvas); Dungeon.center(); }
    TOUCH.place(); if (G.mode === 'deck') Deck.resize(); // (after the buttons: the deck's play stops short of them)
    const s = Math.min(vw / CFG.W, vh / CFG.H) * (Input.mobile ? 1 : 0.98);
    canvas.style.width = Math.floor(CFG.W * s) + 'px'; canvas.style.height = Math.floor(CFG.H * s) + 'px';
  }
  for (const ev of ['resize', 'orientationchange', 'fullscreenchange', 'webkitfullscreenchange']) window.addEventListener(ev, () => { fit(); setTimeout(fit, 300); });
  fit();
  let last = performance.now();
  // An exception inside a frame must never kill the loop (that looks like a freeze). Log it, show it, keep going.
  G.lastError = null;
  function loop(now) {
    G.rafAt = performance.now(); // (the trials pump steps in when frames stop coming)
    let dt = Math.min(1 / 30, (now - last) / 1000); last = now;
    if (G.slowmo > 0) { G.slowmo -= dt; dt *= CFG.SLOWMO.scale; } // the abduction: everything crawls for a moment
    try {
      const t0 = performance.now(); if (Bot.trials && !Bot.trials.done) { for (let k = 0; k < Bot.trials.fast; k++) { G.update(1 / 60); Input.endFrame(); } } else G.update(dt); const t1 = performance.now(); Render.frame(); const t2 = performance.now(); // trials: many fixed steps per drawn frame
      const p = G.prof; p.upd += (t1 - t0 - p.upd) * 0.1; p.draw += (t2 - t1 - p.draw) * 0.1; p.frame += (Math.min(100, now - p.last) - p.frame) * 0.1; p.last = now; // smoothed ms: update, draw, and the real frame interval (what the player feels)
      if (t2 - t0 > 16.7) p.slow++;
    }
    catch (err) {
      console.error(err);
      G.lastError = { text: String(err && err.message || err), t: 6 };
      try { Render.ctx.save(); Render.ctx.setTransform(1, 0, 0, 1, 0, 0); Render.text('Error: ' + G.lastError.text, 20, CFG.H - 60, 14, '#ff7eb6'); Render.ctx.restore(); } catch (e2) {}
    }
    Input.endFrame();
    requestAnimationFrame(loop);
  }
  requestAnimationFrame(loop);
  // trials in a hidden or covered window: the browser throttles animation frames there (to ~10 a second, or none), so a
  // message-channel pump (not throttled like timers) steps the game in ~12 ms slices whenever frames lag; otherwise it idles
  if (Bot.trials) {
    const ch = new MessageChannel(); let due = performance.now(); // held to the visible speed (fast updates per 1/60 s), so the model's answers arrive just as late in game time
    ch.port1.onmessage = () => {
      if (Bot.trials.done) return;
      const now = performance.now();
      if (now - (G.rafAt || 0) < 40) { due = now; setTimeout(() => ch.port2.postMessage(0), 100); return; }
      const step = 1000 / (60 * Bot.trials.fast); let n = 0;
      try { while (due <= now && n < 400 && performance.now() - now < 12) { G.update(1 / 60); Input.endFrame(); due += step; n++; } } catch (err) { console.error(err); }
      if (due < now - 200) due = now - 200; // too slow to keep up: do not bank a backlog
      ch.port2.postMessage(0);
    };
    ch.port2.postMessage(0);
  }
})();
