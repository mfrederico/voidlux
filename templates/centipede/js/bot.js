// Autopilot (T): plays the game with the same two-layer idea as the archived terminal RPG's "tev" brains.
//   reflex     every frame, scripted: steering, dodging, dungeon pathing, weapons on/off, mega timing, bag tidying
//   strategist event-driven and async: ONE typed decision request to Ollama's /v1/systemone (tev1:0.8b by default,
//              or nimble) answers: which GOAL to pursue, how DANGEROUS things are, which FUSION to craft, which PERK to buy.
// The reflex layer carries out the goal. If Ollama is unreachable (not running, or the page is served from a host it will
// not answer), the scripted fallback picks goals and perks instead, and the HUD says so.
// URL options: ?bot=1 (start with the autopilot on) &model=nimble &host=http://localhost:11434
// Your keys always win: the bot only steers while you are not pressing anything.
const GOALS = {
  advance: 'Push east: shoot the thorn wall open and move on to the next region (more danger, better loot, closer to the boss).',
  loot:    'Collect the pickups nearby (bullets, dots, eggs).',
  hunt:    'Fight the enemies nearby for loot and mega charge, kiting at bullet range.',
  dungeon: 'Enter the dungeon maze: clearing it gives a free perk, dots for crafting and ghost currency.',
  flee:    'Break contact: run from everything nearby until the body recovers.',
  boss:    'Fight the boss: circle it at range and unleash the mega.',
  grave:   'Circle a tombstone to summon its ghost: every monster nearby fights for you, then bursts.',
  song:    'Circle a jungle tree to summon its song: every monster nearby (spiders too) stands hypnotized, harmless, taking extra damage.',
};
const Bot = {
  // on = autopilot (steers and presses keys). guide = advice only: same sensing, goals, pointer and highlights, but YOU
  // play; it never steers, never presses a key, never asks Ollama (scripted rules only), so it works on any phone.
  on: false, guide: false, host: 'http://localhost:11434', model: 'tev1:0.8b', every: 7,
  goal: 'advance', goalT: 0, why: '', think: '', pending: false, asks: 0, errors: 0, lastErr: '', latency: 0, offline: false, retryT: 0, danger: 0,
  target: null, axis: { x: 0, y: 0 }, weave: 1, orbit: 1, orbitT: 0, stuckT: 0, bestD: 1e9, detour: null, askT: 0, tidyT: 0, scriptT: 0,
  lastRegion: -1, lastSegs: -1, lastState: '', seenPick: null, perkT: 0, waitT: 0, runs: [], runStart: 0, picks: null, scriptedOnly: false, dstep: null, megaT: 0, fuseKey: '',

  init() {
    const q = new URLSearchParams(location.search);
    if (q.get('model')) this.model = q.get('model');
    if (q.get('host')) this.host = q.get('host').replace(/\/$/, '');
    if (q.get('bot') === '1') { this.on = true; this.allowAuto = true; } // the autopilot only exists for ?bot=1 (and trials): players get the guide
    if (q.get('guide') === '1') this.guide = true;
    if (q.get('brain') === 'scripted') { this.scriptedOnly = true; this.offline = true; this.retryT = 1e12; } // A/B baseline: never ask the model
    if (q.get('deck') === '1') G.deckArmed = true; // start on the saucer deck
    if (q.get('fps') === '1') G.showProf = true; // the profiler without god mode (` toggles it)
    if (q.get('god') === '1') G.godArmed = true; // god mode on the first run (the profiler and the test keys, on a phone too)
    // balance trials: ?trials=100 (&fast=20 &model=tev1:0.8b): the autopilot plays N runs, restarting on death, at `fast` updates per
    // frame with sound off, and prints a summary table (console, the screen, and localStorage 'centipede.trials') when the last run ends
    if (q.get('trials')) { this.on = true; this.allowAuto = true; this.trials = { n: Math.max(1, parseInt(q.get('trials'), 10) || 10), fast: Math.max(1, parseInt(q.get('fast') || '20', 10)), t0: performance.now(), cap: Math.max(60, parseInt(q.get('cap') || '5400', 10)) }; Settings.data.discover = false; Settings.data.guide = false; this.guide = false; Settings.save(); SFX.muted = true; Save.clear(); } // (saved too: a saved guide setting would switch the autopilot off when the settings load after this)
  },
  // the summary of a trial batch: what the numbers say about balance
  trialSummary() {
    const R = this.runs, n = R.length, med = a => { const s = a.slice().sort((x, y) => x - y); return s.length ? s[Math.floor(s.length / 2)] : 0; }, avg = a => a.length ? +(a.reduce((x, y) => x + y, 0) / a.length).toFixed(1) : 0;
    const by = key => R.reduce((m, r) => { m[r[key]] = (m[r[key]] || 0) + 1; return m; }, {});
    const decks = R.filter(r => r.decks > 0);
    const top = key => { const m = {}; for (const r of R) for (const x of (r[key] || '').split(', ').filter(Boolean)) { const k = x.replace(/\*$/, ''); m[k] = (m[k] || 0) + 1; } return Object.entries(m).sort((a, b) => b[1] - a[1]).slice(0, 8).map(([k, v]) => `${k} ${v}`).join(', '); };
    const mp = R.reduce((a, r) => a + r.modelPicks, 0), sp = R.reduce((a, r) => a + r.scriptedPicks, 0);
    return { runs: n, model: R[0] ? R[0].model : '-', secsAvg: avg(R.map(r => r.secs)), secsMed: med(R.map(r => r.secs)), regionAvg: avg(R.map(r => r.region)), regionMax: Math.max(0, ...R.map(r => r.region)), levelReached: by('level'), wins: R.filter(r => /^WON/.test(r.cause)).length, killedBy: by('cause'), dungeonsAvg: avg(R.map(r => r.dungeons)), perksAvg: avg(R.map(r => r.perks)), bodyMaxAvg: avg(R.map(r => r.maxBody)), dpsPeakAvg: avg(R.map(r => r.dpsPeak || 0)), crowdPeakAvg: avg(R.map(r => r.crowdPeak || 0)), scoreAvg: avg(R.map(r => r.score)), modelShare: mp + sp ? Math.round(100 * mp / (mp + sp)) + '%' : '-', topPerks: top('perkList'), topFusions: top('fuseList'), deck: { runsWithDecks: decks.length, cleared: decks.reduce((a, r) => a + r.decksCleared, 0), entered: decks.reduce((a, r) => a + r.decks, 0), secsAvg: avg(decks.map(r => r.deckSecs)), segsLostAvg: avg(decks.map(r => r.deckLost)) } };
  },
  active() { return this.on || this.guide; },
  tap(code) { if (this.on) Input.tapKey(code); }, // the guide only advises
  // T cycles: off → guide → autopilot → off (without ?bot=1 there is no autopilot: T just turns the guide on and off)
  allowAuto: false,
  cycle() {
    if (!this.allowAuto) { if (this.on) this.toggle(); else this.toggleGuide(); return; }
    if (!this.on && !this.guide) this.toggleGuide();
    else if (this.guide) { this.guide = false; this.toggle(); }
    else this.toggle();
  },
  toggleGuide() {
    this.guide = !this.guide; if (this.guide && this.on) this.on = false;
    Settings.data.guide = this.guide; Settings.save();
    this.axis = { x: 0, y: 0 }; this.detour = null; this.goalT = 99; this.lastRegion = -1; this.offline = true; this.retryT = 1e9;
    G.msg(this.guide ? `GUIDE on: it points at what to do next. You drive. ${this.allowAuto ? 'T again for the autopilot, T once more for off.' : 'T again to turn it off.'}` : 'Guide off.', '#ffd166');
  },
  toggle() {
    this.on = !this.on; this.axis = { x: 0, y: 0 }; this.detour = null;
    if (this.on) { this.guide = false; this.goalT = 99; this.askT = 0; this.lastRegion = -1; this.offline = this.scriptedOnly; this.retryT = this.scriptedOnly ? 1e12 : 0; }
    G.msg(this.on ? `AUTOPILOT on: ${this.model} picks goals, perks and fusions; the reflex steers. T to take over.` : 'Autopilot off. You have the wheel.', '#7df9ff');
  },

  // ---- per frame, before the game reads input ----
  update(dt) {
    if (Input.once('KeyT')) this.cycle();
    if (!this.active()) return;
    const st = G.state;
    if (st === 'playing' && this.lastState !== 'playing' && (this.lastState === 'title' || this.lastState === 'gameover' || !this.lastState)) { this.runStart = G.time; this.playT = 0; this.goalT = 99; this.lastRegion = -1; this.picks = { perks: [], fusions: [], model: 0, scripted: 0 }; this.dpsPeak = 0; this.crowdPeak = 0; }
    if (st === 'gameover' && this.lastState !== 'gameover') this.logRun();
    this.lastState = st;
    if (st === 'playing') this.playT = (this.playT || 0) + dt; // a run's seconds: time in play, not at the shop or the workbench
    this.axis = { x: 0, y: 0 };
    if (st === 'title') { this.waitT += dt; if (this.waitT > 1) { this.tap('Enter'); this.waitT = 0; } return; }
    if (st === 'gameover') { this.waitT += dt; if (this.waitT > 4) { this.tap('KeyR'); this.waitT = 0; } return; }
    // trials: beating the Clown Car (the end of the demo) is a win, and a run past the play-time cap (&cap=, seconds) is called; both log and restart
    if (this.trials && !this.trials.done && ((st === 'won' && G.won && G.won.demoEnd && G.nightmare) || (st === 'playing' && (this.playT || 0) > this.trials.cap))) {
      G.deathCause = st === 'won' ? 'WON: cleared the nightmare' : `time cap (${this.trials.cap}s)`; this.logRun();
      if (!this.trials.done) { Save.clear(); G.won = null; G.state = 'title'; this.lastState = 'title'; this.waitT = 0; } // (cleared: ENTER on the title would continue the won save)
      return;
    }
    if (st === 'discover' && this.on) { this.waitT += dt; if (this.waitT > 1.5) { this.tap('Enter'); this.waitT = 0; } return; } // a new-segment card: read it a moment, then carry on
    if (st === 'won') { this.waitT += dt; if (this.waitT > 2.5) { this.tap('Enter'); this.waitT = 0; } return; }
    this.waitT = 0;
    if (st === 'perk') { if (this.guide) this.guidePerk(); else this.perk(dt); return; }
    if (st !== 'playing' || G.cinematic || G.freeze > 0 || !Player.alive) return; // paused = you are looking at the workbench
    this.retryT -= dt; this.askT -= dt; this.tidyT -= dt; this.megaT -= dt; this.goalT += dt;
    if (this.tidyT <= 0) { this.tidyT = 3; this.dpsPeak = Math.max(this.dpsPeak || 0, this.dps()); this.crowdPeak = Math.max(this.crowdPeak || 0, G.crowd || 0); if (this.on) this.tidy(); else this.guideCraft(); }
    if (G.mode === 'world') this.world(dt); else if (G.mode === 'deck') this.deck(dt); else this.dungeon(dt);
  },
  logRun() {
    const D = G.deckStats || {}, r = { run: this.runs.length + 1, region: G.regionMax + 1, level: G.level, secs: Math.round(this.playT || 0), score: G.score, kills: G.kills, dungeons: G.dungeonsCleared, perks: G.perks.length, maxBody: G.maxLen, cause: G.deathCause, asks: this.asks, errors: this.errors, model: this.scriptedOnly ? 'scripted' : this.model, modelPicks: (this.picks || {}).model || 0, scriptedPicks: (this.picks || {}).scripted || 0, perkList: ((this.picks || {}).perks || []).join(', '), fuseList: ((this.picks || {}).fusions || []).join(', '), dpsEnd: Math.round(this.dps()), dpsPeak: Math.round(this.dpsPeak || 0), crowdPeak: this.crowdPeak || 0, decks: D.entered || 0, decksCleared: D.cleared || 0, deckSecs: D.secs || 0, deckLost: D.lost || 0 };
    this.runs.push(r);
    if (this.trials && this.runs.length >= this.trials.n) { // the batch is done: the summary
      const S = this.trialSummary(); S.wallSecs = Math.round((performance.now() - this.trials.t0) / 1000); this.trials.done = S;
      console.log('[trials] summary', S); console.table(this.runs);
      try { const J = JSON.stringify({ summary: S, runs: this.runs }); localStorage.setItem('centipede.trials', J); localStorage.setItem('centipede.trials.' + S.model, J); } catch (e) {} // per brain too, so an A/B pair of batches keeps both
      this.on = false; G.state = 'title'; Music.stop();
    }
    console.log(`[autopilot] run ${r.run}: died in region ${r.region} (level ${r.level}) after ${r.secs}s · score ${r.score} · ${r.kills} kills · ${r.dungeons} dungeons · ${r.perks} perks · body up to ${r.maxBody} · killed by ${r.cause} · ${r.asks} asks (${r.model}) · perks [${r.perkList}] · fusions [${r.fuseList}]`);
  },

  // ---- sensing ----
  sense() {
    const P = Player, ri = World.regionAt(P.x), R = World.regions[ri];
    const enemies = [];
    for (const e of G.enemies) {
      if (e.dead || e.hidden || e.ally > 0 || e.kind === 'stone') continue;
      const d = U.dist(e.x, e.y, P.x, P.y); if (d > 900) continue;
      enemies.push({ e, d });
    }
    enemies.sort((a, b) => a.d - b.d);
    const pickups = [], breached = R.wall.sections.some(s => !s.alive);
    for (const pk of World.pickups) {
      if (!pk.alive) continue;
      if (pk.x < R.x0 - 10 || (pk.x > R.wall.x - 20 && !breached)) continue; // behind a wall: not reachable from here
      if (pk.kind === 'weapon' && !P.wouldTake(pk.id, pk)) continue;
      if (pk.kind === 'egg' && Minions.count() >= Minions.cap()) continue;
      const d = U.dist(pk.x, pk.y, P.x, P.y); if (d > 1100) continue;
      pickups.push({ pk, d });
    }
    pickups.sort((a, b) => a.d - b.d);
    const wall = R.wall, dead = wall.sections.filter(s => !s.alive), mid = s => (s.y0 + s.y1) / 2;
    const section = dead.length ? dead.reduce((b, s) => Math.abs(mid(s) - P.y) < Math.abs(mid(b) - P.y) ? s : b)
                                : wall.sections.reduce((b, s) => s.hp / s.max + Math.abs(mid(s) - P.y) / 4000 < b.hp / b.max + Math.abs(mid(b) - P.y) / 4000 ? s : b);
    const minions = Player.segments.filter(s => P.isMinion(s)), recruits = G.enemies.filter(e => !e.dead && e.ally > 0 && e.ally < 1e8);
    // graveyard: the nearest unused tombstone, worth circling when there is a crowd to charm (and no spider about to bite)
    let grave = null, gd = 1e9;
    if (R.haunted) for (const g of World.graves) { if (g.used || g.region !== ri) continue; const d = U.dist(g.x, g.y, P.x, P.y); if (d < gd) { gd = d; grave = g; } }
    const charmable = grave ? enemies.filter(o => o.e.kind !== 'boss' && U.dist(o.e.x, o.e.y, grave.x, grave.y) < CFG.GRAVE.range * 0.85).length : 0;
    // jungle: the nearest tree that has not sung yet, worth circling when there is an audience
    let tree = null, td = 1e9;
    if (R.jungle) for (const t of World.trees) { if (!t.jungle || !t.alive || t.sung || t.song > 0 || t.region !== ri) continue; const d = U.dist(t.x, t.y, P.x, P.y); if (d < td) { td = d; tree = t; } }
    const audience = tree ? enemies.filter(o => o.e.kind !== 'boss' && !o.e.st.hypno && U.dist(o.e.x, o.e.y, tree.x, tree.y) < CFG.SONG.range * 0.85).length : 0;
    return { ri, R, enemies, minions, recruits, allies: minions.length + recruits.length, boss: enemies.find(o => o.e.kind === 'boss') || null, paras: enemies.filter(o => o.e.kind === 'parasite'),
             pickups, entrance: World.entrances.find(en => !en.used && en.region === ri) || null, wall, section, breached: dead.length, segs: P.segments.length, grave, graveD: gd, charmable, tree, treeD: td, audience };
  },
  label(e) { return e.label || (e.kind === 'boss' ? BOSSES[e.type].name : e.kind === 'bug' ? BUGS[e.type].name : e.kind === 'parasite' ? (PARASITES[e.id] ? PARASITES[e.id].name : 'Parasite') : 'Snake'); },
  pickLabel(pk) { return pk.kind === 'mega' ? `the ${MEGAS[pk.id].name} mega (for your deck)` : pk.kind === 'weapon' ? `${BULLETS[pk.id].name} (tier ${BULLETS[pk.id].tier})` : pk.kind === 'dot' ? `${pk.value || 1} dot${(pk.value || 1) > 1 ? 's' : ''}` : pk.kind === 'cake' ? 'an Eat Me cake (giant for 8s)' : pk.kind === 'potion' ? 'a Drink Me potion (tiny for 8s)' : pk.kind === 'shroom' ? 'a mushroom (double damage 8s)' : pk.kind === 'cleanse' ? 'a Cleanse' : 'a minion egg'; },
  // the goals that make sense right now, each described concretely (these are the strategist's choice criteria)
  available(S) {
    const P = Player, g = {}, px = n => `${Math.round(n)}px`;
    if (S.entrance) { const T = Dungeon.tier.call({ region: S.ri }); g.dungeon = `Enter the dungeon ${px(U.dist(S.entrance.x, S.entrance.y, P.x, P.y))} away (a ${T.name} maze, ${T.ghosts} ghosts, no bullets fly in there). Clearing it is how the centipede GROWS: a free perk (Bigger Body adds a segment, Brawn adds damage), about ${Math.round(T.cols * T.rows * 0.45)} dots for fusions, and parasites are cleansed. ${G.dungeonsCleared === 0 ? 'None cleared yet this run.' : G.dungeonsCleared + ' cleared so far.'}`; }
    if (!S.R.locked) g.advance = `Push east to region ${S.ri + 2}: the wall is ${px(S.wall.x - P.x)} away, ${S.breached ? S.breached + ' section(s) already breached: just go through' : 'weakest section at ' + Math.round(100 * S.section.hp / S.section.max) + '% (bullets break it)'}. Monsters get tougher there${S.entrance ? ', and the unused dungeon in this region, with its free perk and dots, is left behind for good' : ''}.`;
    if (S.pickups.length) g.loot = `Collect pickups: ${S.pickups.slice(0, 3).map(o => this.pickLabel(o.pk) + ' ' + px(o.d)).join(', ')}. Every bullet picked up is a new segment (${S.segs}/${P.maxSegs} slots filled), and segments are the centipede's hit points.`;
    const hunt = S.enemies.filter(o => o.e.kind !== 'parasite' && o.e.kind !== 'boss' && o.d < 700);
    if (hunt.length) g.hunt = `Fight ${hunt.length} enem${hunt.length > 1 ? 'ies' : 'y'} within 700px: nearest ${this.label(hunt[0].e)} (${Math.round(hunt[0].e.hp)} hp) at ${px(hunt[0].d)}. Kills drop loot and charge the mega.${S.allies ? ` Your ${S.allies} all${S.allies > 1 ? 'ies' : 'y'} will fight beside you.` : ''}`;
    if (S.enemies.length) g.flee = `Run from the ${S.enemies.length} enem${S.enemies.length > 1 ? 'ies' : 'y'} nearby${S.paras.length ? ` (${S.paras.length} parasite spider${S.paras.length > 1 ? 's' : ''} hunting you at ${px(S.paras[0].d)})` : ''}.`;
    if (S.boss) g.boss = `Fight the boss ${this.label(S.boss.e)} (${Math.round(S.boss.e.hp)}/${S.boss.e.maxhp} hp) at ${px(S.boss.d)}: the wall stays sealed until it dies.${S.minions.length ? ` Your ${S.minions.length} minion${S.minions.length > 1 ? 's' : ''} will kamikaze into it.` : ''}`;
    if (S.grave && S.charmable >= 2 && S.graveD < 600) g.grave = `Circle the tombstone ${px(S.graveD)} away once to summon its ghost: and the ${S.charmable} monsters near it fight for you for ${CFG.GRAVE.charm}s, then burst. Turns a crowd into an army.`;
    if (S.tree && S.audience >= 2 && S.treeD < 600) g.song = `Circle the tree ${px(S.treeD)} away once to summon its song: it sings and the ${S.audience} monsters near it stand hypnotized for ${CFG.SONG.time}s, harmless and taking extra damage. Free kills.`;
    return g;
  },
  scripted(S) {
    const P = Player;
    if (S.boss) return 'boss';
    if ((S.paras.some(o => o.d < 260) || S.segs === 0) && S.enemies.length) return 'flee';
    if (S.grave && S.charmable >= 2 && S.graveD < 450 && !S.paras.some(o => o.d < 220)) return 'grave';
    if (S.tree && S.audience >= 2 && S.treeD < 450 && !S.paras.some(o => o.d < 220)) return 'song';
    if (S.pickups.length && S.pickups[0].d < 520) return 'loot';
    if (S.entrance && S.segs >= 1) return 'dungeon';
    if ((S.segs >= 2 || (S.segs >= 1 && S.allies)) && S.enemies.some(o => o.e.kind !== 'parasite' && o.d < 420)) return 'hunt';
    if (S.pickups.length) return 'loot';
    if (S.entrance) return 'dungeon';
    if (!S.R.locked) return 'advance';
    return S.enemies.length ? 'hunt' : 'advance';
  },
  // prompt text stays short however big the build gets (a 4000-bullet bag listed one by one overflowed the model's context):
  // fold() keeps the body's order and folds repeats ('Splitter Ricochet III x4'); tally() counts the bag by kind, biggest first
  fold(names) { const out = []; for (const n of names) { const l = out[out.length - 1]; if (l && l.n === n) l.k++; else out.push({ n, k: 1 }); } return out.map(o => o.k > 1 ? `${o.n} x${o.k}` : o.n).join(', '); },
  tally(ids, name = id => BULLETS[id].name) { const m = new Map(); for (const id of ids) m.set(id, (m.get(id) || 0) + 1); return [...m].sort((a, b) => b[1] - a[1]).map(([id, k]) => `${name(id)}${k > 1 ? ' x' + k : ''}`).join(', '); },
  situation(S) {
    const P = Player, body = this.fold(P.segments.map(s => BULLETS[s.id].name + (s.jam ? ' (jammed)' : ''))) || 'none (the head spits pebbles)';
    const bag = this.tally(P.bag) || 'empty';
    const M = MEGAS[Mega.current()], mega = Mega.ready() ? `${M.name} READY` : `${M.name} ${Math.floor(100 * G.mega.charge / CFG.MEGA_MAX)}%`;
    const lines = [
      `You are a centipede in a roguelike snake-shooter. Body: ${S.segs}/${P.maxSegs} segments (${body}); each segment is a bullet that auto-aims. Bag: ${bag}. Dots ${G.dots} (crafting currency), ghosts ${G.ghosts} (perk currency). Mega: ${mega}. Power x${+G.powerMult.toFixed(2)}, ${G.perks.length} perks, ${Minions.count()} minions. Level ${G.level}, region ${S.ri + 1} (${World.regionName(S.R)}), ${Math.round(G.time - this.runStart)}s into the run.`,
      'Rules: you never stop moving; hitting a tree, boulder or wall costs a segment, and so do bites. Segments are your HP: with none left, any hit kills you and the run is over (dying is far worse than slow progress). Loot only drops from kills. The eastern wall is broken by shooting it and every seventh region is a boss arena. Parasite spiders latch on and jam your guns: keep away from them. Gold-ringed rares and named uniques are tougher but drop better loot and dots.',
      'How to get stronger: DUNGEONS are the engine. Every clear gives a free perk (the only way to grow the body or raise damage), dozens of dots, and ghost coins for more perks. DOTS pay for FUSION: two bullets fuse into one stronger tier-3 bullet for 20 dots (40 for tier 4), which frees a body slot for another bullet. A centipede that skips dungeons stays small and dies in the deeper regions.',
      `Progress so far: ${G.dungeonsCleared} dungeons cleared, ${G.perks.length} perks, ${G.dots} dots, ${P.fusions().filter(f => G.dots >= f.cost).length} fusions affordable right now.`,
    ];
    const near = S.enemies.slice(0, 5).map(o => `${this.label(o.e)}${o.e.kind === 'parasite' ? ' (parasite)' : ''} ${Math.round(o.e.hp)}hp at ${Math.round(o.d)}px`).join(', ');
    lines.push(`Nearby: ${S.enemies.length ? S.enemies.length + ' enemies within 900px: ' + near : 'no enemies within 900px'}.`);
    lines.push(`Pickups: ${S.pickups.length ? S.pickups.slice(0, 4).map(o => this.pickLabel(o.pk) + ' at ' + Math.round(o.d) + 'px').join(', ') : 'none'}. Dungeon entrance: ${S.entrance ? Math.round(U.dist(S.entrance.x, S.entrance.y, P.x, P.y)) + 'px away, unused' : 'none left in this region'}. East wall: ${Math.round(S.wall.x - P.x)}px away, ${S.R.locked ? 'sealed until the boss dies' : S.breached ? S.breached + ' sections breached' : 'weakest section ' + Math.round(100 * S.section.hp / S.section.max) + '%'}.`);
    if (S.allies) lines.push(`Allies: ${S.minions.length} minion${S.minions.length === 1 ? '' : 's'} (${S.minions.map(m => BULLETS[m.id].name).join(', ') || 'none'}) ride your tail, spit at whatever you fight, and leap off to blow up on bosses, uniques and beefy rares${S.recruits.length ? `; plus ${S.recruits.length} Hive Mind recruit${S.recruits.length > 1 ? 's' : ''} fighting for you for a few more seconds` : ''}. They are friends, not threats: fighting beside them is much safer than fighting alone, and they are wasted while you run.`);
    lines.push(`Current goal: ${this.goal} for ${Math.round(this.goalT)}s.`);
    return lines.join('\n');
  },

  // ---- the strategist: one typed request, applied when it comes back ----
  maybeAsk(S) {
    let why = '';
    if (this.lastRegion !== S.ri) why = this.lastRegion < 0 ? 'start' : 'new region';
    else if (S.segs < this.lastSegs) why = 'lost a segment';
    else if (S.boss && this.goal !== 'boss') why = 'boss';
    else if (this.goal !== 'loot' && S.pickups.length && S.pickups[0].d < 500 && this.seenPick !== S.pickups[0].pk) { why = 'loot nearby'; this.seenPick = S.pickups[0].pk; }
    else { const key = Player.fusions().filter(f => G.dots >= f.cost).map(f => f.r).join(','); if (key && key !== this.fuseKey) { why = 'fusion affordable'; this.fuseKey = key; } else if (!key) this.fuseKey = ''; }
    if (!why && this.goalT >= this.every) why = `timer (${this.every}s)`;
    this.lastRegion = S.ri; this.lastSegs = S.segs;
    if (!why || this.pending || this.askT > 0) return;
    this.askT = 2.5; this.goalT = 0;
    if (this.offline && this.retryT > 0) { this.goal = this.scripted(S); this.why = `${why} → ${this.goal} (scripted)`; return; }
    this.ask(S, why);
  },
  ask(S, why) {
    const P = Player, questions = {};
    const avail = this.available(S); if (!Object.keys(avail).length) avail.advance = GOALS.advance;
    questions.goal = { type: 'choice', instructions: 'Pick the goal that grows the centipede the most over the next ten seconds without getting it killed.', criteria: avail };
    questions.danger = { type: 'score', instructions: "How dangerous is the centipede's situation right now?", criteria: ['Safe', 'Cautious', 'Dangerous', 'Critical'] };
    const F = P.fusions().filter(f => G.dots >= f.cost).slice(0, 6);
    if (F.length) {
      const now = this.baseDps(), crit = { keep: `Keep the bullets as they are and save the dots: +0 DPS (stays ${Math.round(now)}).` };
      F.forEach((f, i) => { const r = BULLETS[f.r]; crit['fuse' + i] = `Fuse ${BULLETS[f.a].name} + ${BULLETS[f.b].name} into ${r.name} (tier ${r.tier}: ${r.desc}) for ${f.cost} of your ${G.dots} dots: ${this.dpsLabel(now, this.fuseDps(f))}.`; });
      questions.craft = { type: 'choice', instructions: 'Which fusion should be crafted now? Each option shows how the centipede\'s damage per second (DPS) would change. Pick the biggest DPS gain; keep the dots only if no fusion raises DPS.', criteria: crit };
    }
    if (Mega.ready()) { const M = MEGAS[Mega.current()]; questions.mega = { type: 'noul', instructions: `Should the centipede unleash its mega right now (${M.name}: ${M.desc}), or save it for a worse moment?` }; }
    const body = { model: this.model, state: { situation: this.situation(S) }, questions, keep_alive: 600 };
    this.pending = true; this.asks++;
    const t0 = performance.now();
    fetch(this.host + '/v1/systemone', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(data => { this.latency = performance.now() - t0; this.offline = false; this.apply(data, F, why); })
      .catch(err => {
        this.errors++; this.lastErr = String(err && err.message || err); this.offline = true; this.retryT = 15;
        if (G.state === 'playing' && G.mode === 'world') this.goal = this.scripted(this.sense());
        this.why = `${why} → ${this.goal} (scripted: ${this.lastErr})`;
        if (this.errors === 1) G.msg(`Autopilot: ${this.model} at ${this.host} did not answer (${this.lastErr}). Playing on scripted rules; retrying every 15s.`, '#ff9b8b');
      })
      .finally(() => { this.pending = false; });
  },
  apply(data, F, why) {
    const A = data.answers || data;
    const g = A.goal && A.goal.choice;
    if (g && GOALS[g]) { if (g !== this.goal) this.goalT = 0; this.goal = g; }
    if (A.danger && typeof A.danger.score === 'number') this.danger = A.danger.score;
    const conf = A.goal && A.goal.confidence;
    this.why = `${why} → ${this.goal}${conf != null ? ' (' + Math.round(conf * 100) + '%)' : ''}`;
    if (A.craft && A.craft.choice && A.craft.choice.startsWith('fuse')) { const f = F[+A.craft.choice.slice(4)]; if (f) this.craft(f); }
    if (A.mega && A.mega.noul > 0.55 && Mega.ready() && this.megaT <= 0 && G.state === 'playing' && G.mode === 'world' && Player.alive && G.enemies.some(e => !e.dead && !e.hidden && !(e.ally > 0) && U.dist(e.x, e.y, Player.x, Player.y) < 420)) { Mega.use(); this.megaT = 4; this.think = `mega: yes (${A.mega.noul.toFixed(2)})`; }
  },
  // ---- what-if DPS: try a choice on the live build, measure Player.estimateDps, put everything back ----
  dps() { return Player.estimateDps(1, 1); },
  baseDps() { return this.whatIf(() => {}); }, // the baseline gets the same free-slot fill as each option
  whatIf(fn) {
    const P = Player, keep = { segs: P.segments.slice(), bag: P.bag.slice(), max: P.maxSegs, len: P.maxLen, glen: G.maxLen, dots: G.dots, pm: G.powerMult, mods: Object.assign({}, G.mods), lc: P.lastCraft, lh: P.lockHint };
    let after = 0;
    try {
      P.lockHint = true; fn();
      // the free slot a choice opens is filled from the bag, as tidy() would do next
      const tier = id => BULLETS[id].tier;
      while (P.weaponCount() < P.maxSegs && P.bag.length) { let b = 0; for (let i = 1; i < P.bag.length; i++) if (tier(P.bag[i]) > tier(P.bag[b])) b = i; P.segments.splice(P.firstMinion(), 0, P.mkSeg(P.bag.splice(b, 1)[0])); }
      after = this.dps();
    } catch (e) { after = 0; }
    P.segments = keep.segs; P.bag = keep.bag; P.maxSegs = keep.max; P.maxLen = keep.len; G.maxLen = keep.glen; G.dots = keep.dots; G.powerMult = keep.pm; G.mods = keep.mods; P.lastCraft = keep.lc; P.lockHint = keep.lh;
    P.resolveBuild();
    return after;
  },
  fuseDps(f) { return this.whatIf(() => Player.craft(f.A, f.B, true)); },
  perkDps(id) { // Bigger Body: one more slot, filled from the bag if it has anything
    return this.whatIf(() => { if (id === 'body') Player.maxSegs++; else if (['trigger', 'velocity', 'brawn', 'reach', 'elements', 'twin'].includes(id)) PERKS[id].apply(); });
  },
  dpsLabel(now, after) { const d = Math.round(after - now); return `${d >= 0 ? '+' : ''}${d} DPS (${Math.round(now)} to ${Math.round(after)})`; },
  // what the brain chose this run, and who chose it (the model or the scripted rules): the trial log compares them
  note(kind, name, src) {
    if (!this.picks) this.picks = { perks: [], fusions: [], model: 0, scripted: 0 };
    this.picks[kind].push(src === 'model' ? name : name + '*'); this.picks[src]++;
  },
  craft(f, src = 'model') {
    const now = Player.fusions().find(x => x.a === f.a && x.b === f.b) || Player.fusions().find(x => x.r === f.r); if (!now || G.dots < now.cost) return;
    const r = Player.craft(now.A, now.B);
    if (r) { SFX.play('power'); G.msg(`Autopilot fused ${BULLETS[now.a].name} + ${BULLETS[now.b].name} into ${BULLETS[r].name}`, BULLETS[r].color); this.think = `crafted ${BULLETS[r].name}`; this.note('fusions', BULLETS[r].name, src); }
  },
  // bag housekeeping (scripted): fill empty body slots from the bag, swap a stronger bag bullet over a weaker body one,
  // and when offline, craft the best affordable fusion
  tidy() {
    const P = Player; if (!P.alive) return;
    const tier = id => BULLETS[id].tier;
    let guard = 0;
    while (P.weaponCount() < P.maxSegs && P.bag.length && guard++ < 8) { let b = 0; for (let i = 1; i < P.bag.length; i++) if (tier(P.bag[i]) > tier(P.bag[b])) b = i; if (!P.fromBag(b, -1)) break; }
    if (P.bag.length && P.segments.length) {
      let b = 0; for (let i = 1; i < P.bag.length; i++) if (tier(P.bag[i]) > tier(P.bag[b])) b = i;
      let w = -1; for (let i = 0; i < P.segments.length; i++) if (!P.isMinion(P.segments[i]) && (w < 0 || tier(P.segments[i].id) < tier(P.segments[w].id))) w = i;
      if (w >= 0 && tier(P.bag[b]) > tier(P.segments[w].id)) { const name = BULLETS[P.bag[b]].name; if (P.fromBag(b, w)) G.msg(`Autopilot swapped ${name} into the body`, '#7df9ff'); }
    }
    if (this.offline) { const F = P.fusions().filter(f => G.dots >= f.cost); if (F.length) this.craft(F[0], 'scripted'); }
  },

  // ---- overworld reflex ----
  world(dt) {
    const P = Player;
    if (!P.ready) { this.axis = { x: 1, y: 0 }; return; }
    if (!P.firing) this.tap('Space'); // weapons back on after a dungeon (where we hold fire to eat ghosts)
    const S = this.sense();
    this.maybeAsk(S);
    if (this.offline && this.retryT > 0) { this.scriptT -= dt; if (this.scriptT <= 0) { this.scriptT = 1.5; this.goal = this.scripted(S); } }
    // scripted mega: a boss, a crowd, or a last stand
    if (Mega.ready() && this.megaT <= 0 && (S.boss && S.boss.d < 500 || S.enemies.filter(o => o.d < 350).length >= 3 || (S.segs <= 1 && S.enemies.some(o => o.d < 300)))) { this.tap('KeyE'); this.megaT = 4; this.think = 'mega: reflex'; }
    // survival override: with no segments left any hit is death, so grab a bullet if one is close, otherwise run
    let goal = this.goal;
    if (goal === 'flee' && S.boss && S.segs >= 2) { goal = 'boss'; this.think = 'boss: the arena is sealed, nowhere to run'; } // a boss keeps its range anyway; running just stalls the fight
    if (goal === 'advance' && S.entrance && !S.breached && G.dungeonsCleared <= S.ri && (S.segs < P.maxSegs || G.dots < 20)) { goal = 'dungeon'; if (this.think.indexOf('on the way') < 0) this.think = 'dungeon on the way east: taking it first'; }
    // power-ups are segments and segments are HP: grab anything close, and go out of the way for a bullet while slots are empty
    { const near = S.pickups[0], w = S.pickups.find(o => o.pk.kind === 'weapon');
      if (near && near.d < 260 && !S.enemies.some(o => o.e.kind === 'parasite' && U.dist(o.e.x, o.e.y, near.pk.x, near.pk.y) < 160)) { goal = 'loot'; this.think = `grabbing ${this.pickLabel(near.pk)}`; }
      else if (w && S.segs < P.maxSegs && w.d < 720 && !S.enemies.some(o => o.e.kind === 'parasite' && U.dist(o.e.x, o.e.y, w.pk.x, w.pk.y) < 160)) { goal = 'loot'; this.think = `${S.segs}/${P.maxSegs} segments: fetching ${this.pickLabel(w.pk)}`; } }
    if (S.segs === 0 && S.enemies.some(o => o.d < 380)) { const w = S.pickups.find(o => o.pk.kind === 'weapon'); goal = w && w.d < 260 ? 'loot' : 'flee'; if (goal !== this.goal) this.think = `no segments: ${goal} first`; }
    let tgt = this.targetFor(goal, S);
    if (!tgt) for (const g of ['loot', 'dungeon', 'hunt', 'advance', 'boss', 'flee', 'grave', 'song']) { if (g !== goal && (tgt = this.targetFor(g, S))) { this.think = `${goal}: nothing to do, ${g} instead`; break; } }
    if (tgt) tgt = this.viaBreach(S, tgt); // anything past the wall is reached through the breach, not by pressing on the hedge
    this.target = tgt;
    if (!tgt) { this.axis = { x: 1, y: 0 }; return; }
    this.steer(S, tgt, dt);
  },
  // a target beyond this region's wall (loot, a bug to hunt, the next region) is only reachable through a dead section:
  // line up with the nearest breach, then go straight through; the steering's wall push is off for these keys
  breachWaypoint(S) {
    const P = Player, mid = q => (q.y0 + q.y1) / 2, s = S.section; if (!s || s.alive) return null;
    const my = mid(s), ady = Math.abs(my - P.y);
    if (P.x > S.wall.x + 30) return { x: S.wall.x + 420, y: my, key: 'gap', noStuck: true, name: 'through the breach' };
    if (this.aligned && ady > 60) this.aligned = false; else if (!this.aligned && ady < 40) this.aligned = true;
    if (this.aligned) return { x: S.wall.x + 420, y: my, key: 'gap', noStuck: true, name: 'through the breach' };
    return { x: Math.min(P.x, S.wall.x - 230), y: my, key: 'lineup', noStuck: true, name: 'lining up with the breach' };
  },
  viaBreach(S, tgt) {
    if (!S.breached || tgt.key === 'gap' || tgt.key === 'lineup' || tgt.key === 'wall') return tgt;
    if (tgt.x < S.wall.x - 40 || Player.x > S.wall.x + 30) return tgt; // this side of the wall, or already through
    const w = this.breachWaypoint(S); if (!w) return tgt;
    w.name = `${w.name} (to ${tgt.name})`; return w;
  },
  targetFor(goal, S) {
    const P = Player, mid = s => (s.y0 + s.y1) / 2;
    if (goal === 'loot') { const o = S.pickups[0]; return o ? { x: o.pk.x, y: o.pk.y, key: 'pk' + (o.pk.bob | 0) + ':' + Math.round(o.pk.x), name: this.pickLabel(o.pk) } : null; }
    if (goal === 'dungeon') return S.entrance ? { x: S.entrance.x, y: S.entrance.y, key: 'entrance', name: 'dungeon' } : null;
    if (goal === 'hunt') { const o = S.enemies.find(o => o.e.kind !== 'parasite' && o.e.kind !== 'boss' && o.d < 760); return o ? { x: o.e.x, y: o.e.y, key: 'e', keep: this.keepRange() * Math.max(0.6, 1 - 0.15 * S.minions.length), noStuck: true, name: this.label(o.e) + (S.minions.length ? ' (with minions)' : '') } : null; }
    if (goal === 'boss') return S.boss ? { x: S.boss.e.x, y: S.boss.e.y, key: 'boss', keep: Math.max(280, this.keepRange()), noStuck: true, name: this.label(S.boss.e) } : null;
    if (goal === 'grave') return S.grave ? { x: S.grave.x, y: S.grave.y, key: 'grave', keep: 95, lockOrbit: true, noStuck: true, name: 'circle the tombstone' } : null; // keep 95: orbits it inside the lap radius
    if (goal === 'song') return S.tree ? { x: S.tree.x, y: S.tree.y, key: 'tree' + Math.round(S.tree.x), keep: S.tree.r * 0.55 + CFG.HEAD_R + 60, lockOrbit: true, noStuck: true, name: 'circle the tree' } : null;
    if (goal === 'flee') {
      if (!S.enemies.length) return null;
      let ax = 0, ay = 0;
      for (const o of S.enemies) { const k = (o.e.kind === 'parasite' ? 2 : 1) * Math.max(0, 1 - o.d / 700); ax += (P.x - o.e.x) / (o.d || 1) * k; ay += (P.y - o.e.y) / (o.d || 1) * k; }
      const n = Math.hypot(ax, ay) || 1; if (n < 0.01) { ax = 1; ay = 0; }
      return { x: U.clamp(P.x + ax / n * 420, S.R.x0 + 160, S.wall.x - 160), y: U.clamp(P.y + ay / n * 420, 150, CFG.WORLD_H - 150), key: 'flee', noStuck: true, name: 'away' };
    }
    if (goal === 'advance') {
      if (S.R.locked) return null;
      const s = S.section, my = mid(s);
      if (!s.alive) return this.breachWaypoint(S); // slide to the gap's height first (with hysteresis), then straight through
      // weave: run at the weakest section so the bullets land, turn away before touching it, come back
      if (P.x > S.wall.x - 150) this.weave = -1; else if (P.x < S.wall.x - 420) this.weave = 1;
      return this.weave > 0 ? { x: S.wall.x - 20, y: my, key: 'wall', noStuck: true, name: 'shoot the wall' } : { x: S.wall.x - 520, y: my + (this.orbit * 90), key: 'wall', noStuck: true, name: 'swing back' };
    }
    return null;
  },
  keepRange() { const P = Player; let r = 1e9; for (const s of P.segments) if (!s.jam) r = Math.min(r, BULLETS[s.id].range || 420); if (r === 1e9) r = BULLETS[CFG.HEAD_SPIT].range; return U.clamp(r * 0.62, 120, 330); },
  steer(S, tgt, dt) {
    const P = Player, HR = CFG.HEAD_R;
    let vx = tgt.x - P.x, vy = tgt.y - P.y; const d = Math.hypot(vx, vy) || 1; vx /= d; vy /= d;
    let sx = vx, sy = vy;
    this.orbitT -= dt; if (this.orbitT <= 0) { this.orbitT = U.rand(2.5, 4.5); if (!tgt.lockOrbit) this.orbit = -this.orbit; } // (laps around a grave must not change direction)
    if (tgt.keep) { if (d < tgt.keep * 0.7) { sx = -vx; sy = -vy; } else if (d < tgt.keep) { sx = -vy * this.orbit; sy = vx * this.orbit; } } // kite: orbit at range, back off when crowded
    for (const o of S.enemies) { // repulsion: spiders and the boss get a wide berth
      const e = o.e, rr = e.kind === 'parasite' ? 240 : e.kind === 'boss' ? 230 : e.kind === 'bug' && BUGS[e.type].tongue ? BUGS[e.type].range + 40 : 110 + e.r * 3; // an iguana's reach is its tongue
      if (o.d < rr) { const k = (e.kind === 'parasite' ? 3 : 1.5) * (1 - o.d / rr); sx += (P.x - e.x) / (o.d || 1) * k; sy += (P.y - e.y) / (o.d || 1) * k; }
      if (e.kind === 'boss' && e.segPos && e.segPos.length) for (const q of e.segPos) { const dd = U.dist(q.x, q.y, P.x, P.y), rr2 = BOSSES[e.type].segR + 70; if (dd < rr2) { const k = 2.2 * (1 - dd / rr2); sx += (P.x - q.x) / (dd || 1) * k; sy += (P.y - q.y) / (dd || 1) * k; } } // the serpent's coils bite
      if (e.kind === 'boss' && e.beam) { const L = e.beam, rx = P.x - e.x, ry = P.y - e.y, along = rx * Math.cos(L.a) + ry * Math.sin(L.a), perp = -rx * Math.sin(L.a) + ry * Math.cos(L.a); if (along > 0 && Math.abs(perp) < 120) { const k = 3.5 * (1 - Math.abs(perp) / 120) + 0.5, side = perp >= 0 ? 1 : -1; sx += -Math.sin(L.a) * side * k; sy += Math.cos(L.a) * side * k; } } // the laser line: step off it sideways
      if (e.kind === 'boss' && e.abd) { const A = e.abd, dd = U.dist(A.x, A.y, P.x, P.y), rr2 = BOSSES[e.type].abductR + 120; if (dd < rr2) { const k = 4 * (1 - dd / rr2) + 1; sx += (P.x - A.x) / (dd || 1) * k; sy += (P.y - A.y) / (dd || 1) * k; } } // the tractor beam: drag the tail out
      if (e.kind === 'boss' && e.stomp && e.stomp.phase !== 'up') { const St = e.stomp, dd = U.dist(St.x, St.y, P.x, P.y), rr2 = St.r + 90; if (dd < rr2) { const k = 4 * (1 - dd / rr2) + 1; sx += (P.x - St.x) / (dd || 1) * k; sy += (P.y - St.y) / (dd || 1) * k; } } // the foot's shadow: get out from under it
      if (e.kind === 'boss' && e.car && (e.car.state === 'warn' || e.car.state === 'run')) { const a = e.car.state === 'run' ? e.car.dir : e.angle, rx = P.x - e.x, ry = P.y - e.y, along = rx * Math.cos(a) + ry * Math.sin(a), perp = -rx * Math.sin(a) + ry * Math.cos(a); if (along > -60 && Math.abs(perp) < 150) { const k = 4 * (1 - Math.abs(perp) / 150) + 0.8, side = perp >= 0 ? 1 : -1; sx += -Math.sin(a) * side * k; sy += Math.cos(a) * side * k; } } // the clown car's run: step off its line
      if (e.mimeWall) { const W = e.mimeWall, dd = U.dist(W.x, W.y, P.x, P.y); if (dd < 140) { const k = 1.5 * (1 - dd / 140); sx += (P.x - W.x) / (dd || 1) * k; sy += (P.y - W.y) / (dd || 1) * k; } } // the mime's wall: do not press into it
    }
    for (const e of G.enemies) if (e.kind === 'stone' && !e.dead) { const dd = U.dist(e.x, e.y, P.x, P.y), rr = e.r + 85; if (dd < rr) { const k = 2.6 * (1 - dd / rr); sx += (P.x - e.x) / (dd || 1) * k; sy += (P.y - e.y) / (dd || 1) * k; } }
    for (const cl of Combat.clouds) if (cl.enemy) { const dd = U.dist(cl.x, cl.y, P.x, P.y), rr = cl.r + 60; if (dd < rr) { const k = 3 * (1 - dd / rr); sx += (P.x - cl.x) / (dd || 1) * k; sy += (P.y - cl.y) / (dd || 1) * k; } } // venom clouds: out
    for (const p of Combat.projectiles) { // sidestep incoming shots: closest approach within a second, step off the line perpendicular to it
      if (p.owner !== 'enemy' || p.hostile) continue; // hostile = a berserk enemy's shot that hurts other enemies, not you
      const rx = p.x - P.x, ry = p.y - P.y; if (rx * rx + ry * ry > 420 * 420) continue;
      const sp2 = p.vx * p.vx + p.vy * p.vy || 1, t = -(rx * p.vx + ry * p.vy) / sp2; if (t < 0 || t > 1.0) continue;
      const cx = rx + p.vx * t, cy = ry + p.vy * t, cd = Math.hypot(cx, cy);
      if (cd < 64) { const k = 3.2 * (1 - cd / 64) * (1 - t / 1.2), sp = Math.sqrt(sp2), nx = -p.vy / sp, ny = p.vx / sp, side = (cx * nx + cy * ny) >= 0 ? -1 : 1; sx += nx * side * k; sy += ny * side * k; }
    }
    const avoid = (x, y, rr, k0) => { const dd = U.dist(x, y, P.x, P.y); if (dd < rr) { const k = k0 * (1 - dd / rr); sx += (P.x - x) / (dd || 1) * k; sy += (P.y - y) / (dd || 1) * k; } };
    const solids = []; // the things that cost a segment on contact, with the radius we must keep from their centre
    for (const t of World.trees) if (t.alive && Math.abs(t.x - P.x) < 200 && Math.abs(t.y - P.y) < 200) solids.push({ o: t, x: t.x, y: t.y, R: t.r * 0.55 + HR + 14 });
    for (const b of World.boulders) if (!b.gone && Math.abs(b.x - P.x) < 200 && Math.abs(b.y - P.y) < 200) solids.push({ o: b, x: b.x, y: b.y, R: b.r * 0.8 + HR + 14 });
    for (const g of World.graves) if (Math.abs(g.x - P.x) < 200 && Math.abs(g.y - P.y) < 200) solids.push({ o: g, x: g.x, y: g.y - 6, R: g.r * 0.6 + HR + 14 });
    for (const cn of World.cannons) if (Math.abs(cn.x - P.x) < 200 && Math.abs(cn.y - P.y) < 200) solids.push({ o: cn, x: cn.x, y: cn.y, R: cn.r * 0.75 + HR + 14 });
    for (const d of S.R.deco) if (d.solid && Math.abs(d.x - P.x) < 200 && Math.abs(d.y - P.y) < 200) solids.push({ o: d, x: d.x, y: d.y, R: d.solid * 0.7 + HR + 14 });
    for (const e of G.enemies) if (e.kind === 'stone' && !e.dead) solids.push({ o: e, x: e.x, y: e.y, R: e.r + HR + 16 });
    for (const q of solids) avoid(q.x, q.y, q.R + 40, 1.2);
    for (const l of World.lakes) { if (Math.abs(l.x - P.x) > l.rx + 120 || Math.abs(l.y - P.y) > l.ry + 120) continue; const inT = ((tgt.x - l.x) / l.rx) ** 2 + ((tgt.y - l.y) / l.ry) ** 2 < 1; if (!inT && ((P.x - l.x) / (l.rx + 50)) ** 2 + ((P.y - l.y) / (l.ry + 50)) ** 2 < 1) avoid(l.x, l.y, Math.max(l.rx, l.ry) + 60, 0.9); }
    // the thorn hedge and the walls: soft push from 150px, hard push from 80px
    if (P.y < 150) sy += (150 - P.y) / 150 * 2; if (P.y < 80) sy = Math.max(sy, 1.2);
    if (P.y > CFG.WORLD_H - 150) sy -= (P.y - (CFG.WORLD_H - 150)) / 150 * 2; if (P.y > CFG.WORLD_H - 80) sy = Math.min(sy, -1.2);
    const left = S.ri > 0 ? S.R.x0 : CFG.HEDGE;
    if (P.x < left + 150) sx += (left + 150 - P.x) / 150 * 2; if (P.x < left + 80) sx = Math.max(sx, 1.2);
    if (tgt.key !== 'gap' && tgt.key !== 'wall' && tgt.key !== 'lineup') { if (P.x > S.wall.x - 150) sx -= (P.x - (S.wall.x - 150)) / 150 * 2; if (P.x > S.wall.x - 80) sx = Math.min(sx, -1.2); }
    // stuck: no progress toward the target for 2s → a short perpendicular detour
    if (tgt.key !== this.tKey) { this.tKey = tgt.key; this.bestD = 1e9; this.stuckT = 0; }
    if (!tgt.noStuck) { if (d < this.bestD - 3) { this.bestD = d; this.stuckT = 0; } else { this.stuckT += dt; if (this.stuckT > 2) { this.detour = { x: -vy * this.orbit, y: vx * this.orbit, t: 0.8 }; this.stuckT = 0; this.bestD = 1e9; this.think = 'stuck, detouring'; } } }
    if (this.detour) { this.detour.t -= dt; sx = this.detour.x; sy = this.detour.y; if (this.detour.t <= 0) this.detour = null; }
    let n = Math.hypot(sx, sy) || 1; sx /= n; sy /= n;
    // tangent steering: if the chosen direction runs into a solid within 170px, swing to that solid's tangent instead
    // (sticky side per obstacle, so we don't flip-flop and skim it); inside the margin, back straight out
    let block = null, bAlong = 1e9;
    for (const q of solids) {
      const ox = q.x - P.x, oy = q.y - P.y, d = Math.hypot(ox, oy);
      if (d < q.R) { block = { q, inside: true, d }; break; }
      const along = ox * sx + oy * sy; if (along <= 0 || along > 170 + q.R) continue;
      const perp = ox * sy - oy * sx; if (Math.abs(perp) < q.R && along < bAlong) { bAlong = along; block = { q, perp, d }; }
    }
    if (block) {
      const q = block.q, ox = q.x - P.x, oy = q.y - P.y;
      if (block.inside) { sx = -ox / (block.d || 1); sy = -oy / (block.d || 1); }
      else {
        if (this.sideFor !== q.o) { this.sideFor = q.o; this.side = block.perp > 0 ? -1 : 1; }
        const th = Math.atan2(oy, ox), al = Math.asin(Math.min(1, q.R / block.d)) + 0.2, a = th + this.side * al;
        sx = Math.cos(a); sy = Math.sin(a);
      }
    } else this.sideFor = null;
    this.axis = { x: sx, y: sy };
  },

  // ---- dungeon reflex: BFS to the best cell, around hunting ghosts ----
  dungeon(dt) {
    const D = Dungeon, P = Player;
    const scared = D.fright > 0.8;
    if (P.firing === scared) this.tap('Space'); // hold fire while ghosts are edible, shoot otherwise
    const step = this.dungeonStep();
    this.dstep = step;
    this.axis = step ? { x: step.x, y: step.y } : { x: 0, y: 0 };
  },
  // the saucer deck: shoot the cow carrying your segment, dodge rays about to cross the band, catch what falls, else sit under the lowest invader
  deck(dt) {
    const D = Deck, P = Player, band = D.H * D.BAND; if (!P.firing) this.tap('Space');
    const live = D.invaders.filter(e => !e.dead);
    let dodge = 0; for (const p of Combat.projectiles) { if (p.owner !== 'enemy' || p.vy <= 0) continue; const tt = (P.y - p.y) / p.vy; if (tt < 0 || tt > 0.9) continue; const dx = p.x + p.vx * tt - P.x; if (Math.abs(dx) < 48) dodge += dx > 0 ? -1 : 1; }
    const cow = live.find(e => e.carry), falling = D.pickups.filter(pk => pk.alive && pk.y > band - 280 && Math.abs(pk.x - P.x) < 320).sort((a, b) => Math.abs(a.x - P.x) - Math.abs(b.x - P.x))[0], lowest = live.filter(e => !e.carry).sort((a, b) => b.y - a.y)[0];
    const lead = e => e.x + (D.form ? D.form.dir * D.form.speed : 0) * Math.min(1.2, (P.y - e.y) / 450); // lead the formation's drift by the shot's flight time
    const ufoNear = D.ufo && Math.abs(D.ufo.x - P.x) < 300 && !D.invaders.some(e => !e.dead && e.y > band - 120); // the UFO passing overhead, with nothing about to reach the band: take it
    const tx = cow ? lead(cow) : ufoNear ? D.ufo.x + D.ufo.vx * 0.45 : falling ? falling.x : lowest ? lead(lowest) : D.ufo ? D.ufo.x + D.ufo.vx * 0.5 : null;
    const ax = dodge ? Math.sign(dodge) : tx != null && Math.abs(tx - P.x) > 12 ? Math.sign(tx - P.x) : 0, ay = P.y > band + 40 ? -0.6 : P.y < band + 16 ? 0.6 : 0;
    this.axis = { x: ax, y: ay }; this.think = cow ? 'shoot the cow down' : dodge ? 'dodging a ray' : ufoNear ? 'the UFO' : falling ? 'catching' : lowest ? 'under the lowest invader' : D.ufo ? 'the UFO' : 'waiting';
  },
  dungeonStep() {
    const D = Dungeon, P = Player;
    const live = D.ghosts.filter(g => !g.dead && !g.hidden && g.hold <= 0), hunters = live.filter(g => !g.fright), edible = live.filter(g => g.fright);
    const src = P.gprog > 0.5 ? { r: P.grow + P.gdir.y, c: P.gcol + P.gdir.x } : { r: P.grow, c: P.gcol }; // plan from the cell we are about to reach
    if (!D.isFloor(src.r, src.c)) { src.r = P.grow; src.c = P.gcol; }
    const danger = new Set();
    for (const g of hunters) for (let dr = -2; dr <= 2; dr++) for (let dc = -2; dc <= 2; dc++) if (Math.abs(dr) + Math.abs(dc) <= 2) danger.add((g.row + dr) + ',' + (g.col + dc));
    const bfs = (avoid) => {
      const dist = [], first = [];
      for (let r = 0; r < D.rows; r++) { dist.push(new Array(D.cols).fill(-1)); first.push(new Array(D.cols).fill(null)); }
      dist[src.r][src.c] = 0; const q = [src]; let qi = 0;
      while (qi < q.length) {
        const n = q[qi++];
        for (const d of [{ x: 0, y: 1 }, { x: 0, y: -1 }, { x: 1, y: 0 }, { x: -1, y: 0 }]) {
          const r = n.r + d.y, c = n.c + d.x;
          if (!D.isFloor(r, c) || D.grid[r][c] === 2 || dist[r][c] >= 0 || (avoid && danger.has(r + ',' + c))) continue;
          dist[r][c] = dist[n.r][n.c] + 1; first[r][c] = first[n.r][n.c] || d; q.push({ r, c });
        }
      }
      return { dist, first };
    };
    let res = bfs(true);
    const pick = (pred) => { let best = null, bd = 1e9; for (let r = 0; r < D.rows; r++) for (let c = 0; c < D.cols; c++) { const v = res.dist[r][c]; if (v >= 0 && v < bd && pred(r, c)) { bd = v; best = { r, c }; } } return best; };
    const manhattan = g => Math.abs(g.row - src.r) + Math.abs(g.col - src.c);
    let goal = null, what = '';
    if (D.fright > 1 && edible.length) { goal = pick((r, c) => edible.some(g => g.row === r && g.col === c)); what = 'eat a ghost'; }
    if (!goal && hunters.some(g => manhattan(g) <= 5)) { goal = pick((r, c) => D.dots[r][c] === 2); what = 'power pellet'; }
    if (!goal && D.siphon && D.siphon.state === 'idle' && D.dotsLeft > 12) { goal = pick((r, c) => r === D.siphon.r && c === D.siphon.c); what = 'the Siphon'; }
    if (!goal && D.shovel && !D.shovel.taken && res.dist[D.shovel.r][D.shovel.c] >= 0 && res.dist[D.shovel.r][D.shovel.c] <= 8) { goal = { r: D.shovel.r, c: D.shovel.c }; what = 'the Shovel'; } // worth a short detour (digs are used by hand)
    if (!goal) { goal = pick((r, c) => D.dots[r][c] === 1); what = 'dots'; }
    if (!goal) { goal = pick((r, c) => D.dots[r][c] === 2); what = 'power pellet'; }
    if (!goal) { res = bfs(false); goal = pick((r, c) => D.dots[r][c] > 0 || (D.siphon && D.siphon.state === 'idle' && r === D.siphon.r && c === D.siphon.c)); what = 'dots (through danger)'; }
    if (!goal && hunters.length) { // nothing to eat: keep away from the ghosts
      let best = null, bs = -1; for (let r = 0; r < D.rows; r++) for (let c = 0; c < D.cols; c++) { if (res.dist[r][c] < 0) continue; const m = Math.min(...hunters.map(g => Math.abs(g.row - r) + Math.abs(g.col - c))); if (m > bs) { bs = m; best = { r, c }; } }
      goal = best; what = 'keep away';
    }
    this.think = what ? `dungeon: ${what}` : this.think;
    if (!goal) return null;
    return res.first[goal.r][goal.c] || (P.gprog > 0 ? P.gdir : null);
  },

  // ---- the perk shop ----
  perk(dt) {
    this.perkT -= dt;
    if (this.pending || this.perkT > 0) return;
    const ids = G.perkChoices, buyable = ids.map((id, i) => ({ id, i })).filter(o => Perks.canBuy(o.id));
    this.perkT = 1.2;
    if (!buyable.length) { this.tap('Space'); return; }
    if (this.offline && this.retryT > 0) { this.scriptedPerk(buyable); return; }
    const crit = {}, now = this.baseDps();
    for (const o of buyable) { const after = this.perkDps(o.id); crit['buy' + o.i] = `${PERKS[o.id].name} (${Perks.priceLabel(o.id)}): ${PERKS[o.id].desc}. ${Math.round(after) !== Math.round(now) ? this.dpsLabel(now, after) : 'no direct DPS change (utility)'}.`; }
    if (G.perkFree <= 0) crit.save = `Buy nothing more and keep the ${G.ghosts} ghosts for the next shop (every copy of a perk costs more than the last).`;
    const P = Player, body = this.fold(P.segments.map(s => BULLETS[s.id].name)) || 'none';
    const situation = `The centipede just cleared a dungeon and is at the perk shop. Body ${P.segments.length}/${P.maxSegs} (${body}), bag ${P.bag.length} spare bullets, power x${+G.powerMult.toFixed(2)}, perks so far: ${this.tally(G.perks, id => PERKS[id].name) || 'none'}, ${G.ghosts} ghosts to spend${G.perkFree ? ', and one perk is FREE' : ''}, minions ${Minions.count()}/${Minions.cap()}, region ${G.regionMax + 1}. Damage now ${Math.round(now)} DPS. Each option shows its DPS change; segments are also the centipede's hit points, so a bigger body adds both damage and survival. Weigh DPS against survival.`;
    const body2 = { model: this.model, state: { situation }, questions: { perk: { type: 'choice', instructions: 'Which perk should the centipede take now?', criteria: crit } }, keep_alive: 600 };
    this.pending = true; this.asks++;
    const t0 = performance.now();
    fetch(this.host + '/v1/systemone', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body2) })
      .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(data => {
        this.latency = performance.now() - t0; this.offline = false;
        const A = data.answers || data, ch = A.perk && A.perk.choice;
        if (G.state !== 'perk') return;
        if (ch && ch.startsWith('buy')) { const i = +ch.slice(3); this.tap('Digit' + (i + 1)); const pk = PERKS[G.perkChoices[i]]; this.think = `perk: ${pk ? pk.name : ch}`; if (pk && Perks.canBuy(G.perkChoices[i])) this.note('perks', pk.name, 'model'); }
        else { this.tap('Space'); this.think = 'perk: saved the ghosts'; }
      })
      .catch(err => { this.errors++; this.lastErr = String(err && err.message || err); this.offline = true; this.retryT = 15; if (G.state === 'perk') this.scriptedPerk(buyable); })
      .finally(() => { this.pending = false; });
  },
  guidePerk() { // suggests once per offer (a new shop, or after a buy changes the choices): then the cursor is yours
    const offer = G.perkChoices.join() + '|' + G.bought.length; if (this.perkOffer === offer) return; this.perkOffer = offer;
    const buyable = G.perkChoices.map((id, i) => ({ id, i })).filter(o => Perks.canBuy(o.id));
    const order = ['body', 'brawn', 'twin', 'trigger', 'velocity', 'summon', 'brood', 'elements', 'lucky', 'reach', 'swift', 'magnet', 'skin', 'bitter'];
    let best = null; for (const o of buyable) { const k = order.indexOf(o.id); const rank = k < 0 ? 50 : k; if (!best || rank < best.rank) best = { o, rank }; }
    if (best) { G.perkSel = best.o.i; this.think = `suggested: ${PERKS[best.o.id].name}`; } else this.think = 'nothing affordable: leave';
  },
  guideCraft() {
    const P = Player, F = P.fusions().filter(f => G.dots >= f.cost);
    if (F.length && G.mode === 'world' && Settings.explainCraft(F[0])) return; // the first time ever: the explainer card
    if (F.length) this.think = `you can craft ${BULLETS[F[0].r].name} (C)`;
    else if (P.weaponCount() < P.maxSegs && P.bag.length) this.think = `${P.maxSegs - P.weaponCount()} body slot${P.maxSegs - P.weaponCount() > 1 ? 's' : ''} free: add a bag bullet (C)`;
  },
  scriptedPerk(buyable) {
    const order = ['body', 'brawn', 'twin', 'trigger', 'velocity', 'summon', 'brood', 'elements', 'lucky', 'reach', 'swift', 'magnet', 'skin', 'bitter'];
    let best = null;
    for (const o of buyable) { const k = order.indexOf(o.id); const rank = k < 0 ? 50 : k; if (!best || rank < best.rank) best = { o, rank }; }
    if (best && (G.perkFree > 0 || best.rank <= 3)) { this.tap('Digit' + (best.o.i + 1)); this.think = `perk (scripted): ${PERKS[best.o.id].name}`; this.note('perks', PERKS[best.o.id].name, 'scripted'); }
    else { this.tap('Space'); this.think = 'perk (scripted): saving ghosts'; }
  },

  // ---- drawing ----
  draw() { // world space: where the reflex is heading (or, in guide mode, where it suggests you go)
    if (!this.active() || G.state !== 'playing' || !Player.alive) return;
    const c = Render.ctx, P = Player, col = this.guide ? '#ffd166' : '#7df9ff';
    if (G.mode === 'world' && this.target) {
      const t = this.target, cam = G.cam, vw = Render.viewW(), vh = Render.viewH(), onScreen = t.x > cam.x + 30 && t.x < cam.x + vw - 30 && t.y > cam.y + 30 && t.y < cam.y + vh - 30;
      c.save(); c.setLineDash([8, 10]); c.strokeStyle = this.guide ? 'rgba(255,209,102,0.6)' : 'rgba(125,249,255,0.55)'; c.lineWidth = 2; c.beginPath(); c.moveTo(P.x, P.y); c.lineTo(t.x, t.y); c.stroke(); c.setLineDash([]);
      if (onScreen) { c.strokeStyle = col; c.lineWidth = 2.5; c.beginPath(); c.arc(t.x, t.y, 16 + 4 * Math.sin(G.time * 6), 0, TAU); c.stroke(); c.restore(); if (t.name) Render.text(t.name, t.x, t.y - 28, 12, col, 'center'); }
      else { // off screen: an arrow at the edge, pointing the way
        const ex = U.clamp(t.x, cam.x + 50, cam.x + vw - 50), ey = U.clamp(t.y, cam.y + 120 / Render.zoom(), cam.y + vh - 60), a = Math.atan2(t.y - ey, t.x - ex);
        c.translate(ex, ey); c.rotate(a); c.fillStyle = col; c.strokeStyle = Render.OUT; c.lineWidth = 2.5; c.beginPath(); c.moveTo(18, 0); c.lineTo(-10, -12); c.lineTo(-4, 0); c.lineTo(-10, 12); c.closePath(); c.fill(); c.stroke(); c.restore();
        if (t.name) Render.text(t.name, ex, ey + 26, 12, col, 'center');
      }
    } else if (G.mode === 'dungeon' && this.dstep) {
      const C = Dungeon.cell; c.save(); c.strokeStyle = 'rgba(125,249,255,0.8)'; c.lineWidth = 3; c.beginPath(); c.moveTo(P.x, P.y); c.lineTo(P.x + this.dstep.x * C * 0.6, P.y + this.dstep.y * C * 0.6); c.stroke(); c.restore();
    }
  },
  drawHUD() {
    if (!this.active()) return;
    const c = Render.ctx, boss = G.state === 'playing' && G.enemies.some(e => e.kind === 'boss' && !e.dead && e.awake && !e.hidden);
    const y = boss ? 158 : 92, w = Math.min(760, CFG.W - 40), x = CFG.W / 2 - w / 2;
    if (this.guide) { // advice only: what to do next, and why
      c.fillStyle = 'rgba(60,40,10,0.6)'; Render.roundRect(x, y, w, 46, 10); c.fill(); c.strokeStyle = '#ffd166'; c.lineWidth = 1.5; c.stroke();
      const what = this.target && this.target.name ? this.target.name : GOALS[this.goal] ? this.goal : '';
      Render.text(`GUIDE  ·  ${(G.mode === 'dungeon' ? this.think : what).toUpperCase()}  ·  T to switch off`, CFG.W / 2, y + 15, 13, '#ffd166', 'center');
      const line = [G.mode === 'dungeon' ? '' : `goal: ${this.goal}`, G.mode === 'dungeon' ? '' : this.think].filter(Boolean).join('   ·   ');
      if (line) Render.text(line.length > 120 ? line.slice(0, 118) + '…' : line, CFG.W / 2, y + 34, 12, 'rgba(255,255,255,0.85)', 'center', 'normal');
      return;
    }
    c.fillStyle = 'rgba(10,40,60,0.6)'; Render.roundRect(x, y, w, 46, 10); c.fill(); c.strokeStyle = this.pending ? '#fff' : '#7df9ff'; c.lineWidth = 1.5; c.stroke();
    const brain = this.offline ? 'scripted (Ollama offline)' : `${this.model} · ${Math.round(this.latency)} ms`;
    const dng = '●'.repeat(Math.round(U.clamp(this.danger, 0, 3))) + '○'.repeat(3 - Math.round(U.clamp(this.danger, 0, 3)));
    Render.text(`AUTOPILOT  ·  ${brain}  ·  goal ${this.goal.toUpperCase()}  ·  danger ${dng}  ·  asks ${this.asks}  ·  T to take over`, CFG.W / 2, y + 15, 13, this.pending ? '#fff' : '#7df9ff', 'center');
    const line = [this.why, this.think].filter(Boolean).join('   ·   ');
    if (line) Render.text(line.length > 120 ? line.slice(0, 118) + '…' : line, CFG.W / 2, y + 34, 12, 'rgba(255,255,255,0.85)', 'center', 'normal');
  },
};
