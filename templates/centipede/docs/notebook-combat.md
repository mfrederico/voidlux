# Combat notebook

Combat is everything that flies, hurts and fades. In player terms: your segments' bullets (shots, moths, boomerangs, orbiters, mines, pulses, ricochets), the elements they carry (fire, poison, ice, electric), the statuses those leave (burn, freeze, spark...), poison and other clouds, lightning arcs, hit sparks and the floating damage numbers. In code terms it is the global `Combat` (js/combat.js), which owns five arrays and one per-frame `Combat.update`, plus the drawers in js/render-fx.js that paint those arrays. Combat does not decide *when* to shoot (the player, minions and monsters do) and does not decide *what drops* (World does).

**Files:**

| file | what it holds |
|---|---|
| js/combat.js | `Combat`: projectile creation (`fire`, `fireEnemy`, `spawnShard`), damage and kill, resistances, statuses, elements, pipes, combos, ricochet, resonance, the per-frame `update` and `updateFx` |
| js/render-fx.js | `Render.drawProjectiles`, `drawClouds`, `drawArcs`, `drawParticles`, `drawFloaters` (extends `Render`) |

## Model — the state it owns, and its lifecycle

`Combat` holds five plain arrays, all wiped by `Combat.reset`:

- `projectiles` — every bullet, player and enemy alike. A player bullet is a fat object: `owner: 'player'`, `kind` (from the BULLETS entry: `shot`, `homing`, `boomerang`, `orbit`, `chain`, `bounce`, `mine`, `pulse`), `type` (the BULLETS entry itself), `dmg` and `base`, `elems`, `combo`, `pipe` / `pipeBoost`, `pierce`, `bounces`, `life`, `hit` (a Set of enemies already struck), `seg` (the segment that owns it, or null). Enemy bullets come from `fireEnemy`: `owner: 'enemy'`, `power` instead of dmg, plus flags `hostile`, `from`, `venom`, `bounce`, `pie`, `push`.
- `clouds` — area patches `{x, y, r, t, mag, kind, seed}`. Kinds: `poison` (default), `charged`, `steam`, `giggle`, `venom` (enemy), `laugh`, and trail kinds laid by walker segments (`fire`, `ice`, `electric`, `dust`). `enemy: true` marks a cloud that hurts the player.
- `particles` — `{x, y, vx, vy, t, life, color, size}` from `Combat.mk` / `Combat.burst`. Many systems push here directly.
- `arcs` — lightning segments `{x1, y1, x2, y2, t, seed}`.
- `floaters` — damage numbers and words (`RESIST`, `IMMUNE`, `STRONG!`, `TOO DRY`, `RESONANCE`, `HA HA!`...) `{x, y, t, text, color}`.

Also: `Combat.near` (the enemies near the player this frame, set at the top of `update`), `MAX_PROJ` 400, `MAX_PARTICLES` 1500.

Per-enemy state Combat writes onto monsters: `e.st` (status map, `{t, mag, tick, t0}` per name), `e.res` (cached resistance table), `e.flash`, `e.stun`, `e.dead`, `e.freezeCd` (bosses), and throttle stamps `tagT`, `dryT`, `tinderT`, `hahaT`.

Per-segment state: `seg.inflight` (bullets it owns still alive), `seg.cd` (reload), `seg.shield` / `seg.shieldT` (orbiter shield charges).

Lifecycle: `Combat.reset` runs on new run, region warp, entering and leaving a dungeon or the saucer deck, and on loading a save. It also zeroes every segment's `inflight`, since every bullet is gone.

## How it works — the main flows, named by function

**The damage pipeline, end to end**

1. *A segment fires.* `Player.fireUpdate` (js/player-fire.js) walks the body. A segment is loaded when its `cd <= 0`, `inflight < maxInflight` (1 by default) and it is not jammed. `Player.fireFrom` picks a target, leads the shot, and calls `Combat.fire(x, y, angle, B, mult, seg)`. Minions and allied parasites also call `Combat.fire` with `seg = null`.
2. *Projectile.* `Combat.fire` expands `B.burst` into several calls, adds spread, and builds the projectile. `dmg = B.dmg * mult * ramp`; `base` is the same without `G.powerMult` (Brawn), and `base` is what drives status magnitudes. `ramp` is `Combat.ramp(B)` (grows with `G.level`, faster for tier 3+) times `CFG.DECK_DMG` on the deck. Deep Freeze adds `ice` to `elems`. Range perks scale life; `M.bspeed` scales speed except for orbit and pulse. Twin Shot pushes extra copies (`extra: true`, no `seg`, damage times `Perks.twinMult`, statuses at full). The owning segment's `inflight` goes up.
3. *Flight.* `Combat.update` moves it according to `kind` (see below) and tests it against `Combat.near` using each enemy's `e.hitCircles()`.
4. *Hit.* Mines and Bomber Moths call `Combat.explode` (area). Everything else calls `Combat.applyHit(p, e)`:
   - multipliers: frozen x1.25 (ice synergy: `CFG.SYNERGY.frozenTaken`), burning with fire synergy, hypnotised (`CFG.SONG.dmgMult`), clown car refuelling, dungeon `fright` x2, stones vs `wallMult`, armor (only bullets with no element and no combo), dried-out (bone synergy, and fire x`CFG.DRY.fire`), then the average element resistance, plasma vs armor, shatter vs frozen x3;
   - `Combat.damage(e, dmg, color)`;
   - `Combat.applyElements` (statuses and clouds), the bone spear's `Combat.dry`, `Combat.applyCombo`, `Combat.applyPipe`.
5. *Damage.* `Combat.damage` routes minions to `Minions.hurt`, applies the boss Bullet Storm resist (`BOSSES[type].megaResist`), subtracts hp, flashes, pushes a damage-number floater (only when amount >= 1), and calls `kill` at hp <= 0.
6. *Kill.* `Combat.kill` sets `e.dead`, bursts particles, plays the death sound (`SFX.death(e)`, or `death:boss-<type>` / `kill` for bosses, stones, rocks), stops a boss's music, then calls `e.onDeath()` unless the monster is charmed (`ally > 0`), then `Mega.charge(e)`.
7. *Loot.* Each monster's `onDeath` (set at spawn, see notebook-enemies.md) bumps `G.kills` / `G.score` and calls `World.loot(e, L)` and sometimes `World.dropDots`. The drop rules live in js/world-loot.js: hand off to notebook-world-loot.md. The dead monster is spliced out of `Enemies.list` on the next `Enemies.update`.

**Projectile kinds in `Combat.update`** (player bullets):
- `shot` (default branch): straight. With `pipe === 'homing'` it bends toward the nearest enemy within `CFG.PIPE_HOMING.reach`. With `bounces > 0` it moves by `reflectMove`.
- `homing` (moths): turns toward the nearest aimable enemy at `type.turn`, with flutter. On the deck only toward what is nearly ahead. `wallAim` disables steering.
- `boomerang`: out to `range`, then flies back to its segment (`segPos`). Hitting a solid on the way out turns it early.
- `chain` (the Ricochet): flies at a target, hops to the next unhit enemy within `type.chainR` on each hit (`hops`), then returns home.
- `orbit`: circles its segment at `type.radius`; clears its hit list every 0.35 s so it hits again; never dies on a hit. Shielded orbiters block enemy shots (`orbBlock`).
- `bounce`: reflects off solids, dies when `bounces < 0`.
- `mine`: drifts backward and slows, arms after 0.5 s, explodes when an enemy is within half its blast.
- `pulse`: a growing ring centred on its segment; damages and stuns each enemy once as the ring edge passes.

**Elements and statuses.** `applyElements` counts each element on the bullet: fire -> `burn` (stacking), poison -> a poison cloud (bigger with poison synergy; none for the `gas` combo), ice -> `freeze`, electric -> `spark` on every enemy within a radius plus arcs (and leaps further with electric synergy). `addStatus` is the single entry point: it blocks freeze on dried-out monsters, applies resistance through `ELEM_OF` (burn->fire, poison->poison, freeze/slow->ice, spark->electric, giddy->poison), caps a boss's freeze at 1.4 s then sets `freezeCd = 7`, and merges with an existing status (longer time; magnitude either max or stacking). `tickStatus` ticks burn and poison as direct hp loss, sparks every 0.45 s to everything within 95 px, puffs dust for `dry`, shatters ice on thaw. Other status names that monsters read but Combat only stores: `slow`, `blind`, `berserk`, `giddy`, `hypno`. `e.stun` is a separate number, not a status.

**Resistances (the bestiary).** `Combat.resist(e, el)` looks up `BESTIARY.resist` (js/bestiary.js): the monster's own row from `rowOf` (`boss-<type>`, `unique-<id>`, `snake-<variant>`, `parasite-<id>`, `bug-<type>`, `summon-<type>`), else `family-<kind>`, else 1. It caches this in `e.res`. `resistMod` multiplies the level row (`level-1..5`) and in nightmare `mode-nightmare`. Synergy adds penetration (`CFG.SYNERGY.pen`); Deep Freeze floors ice at `CFG.DEEP_FREEZE.mult`. `resistTag` shows STRONG!/RESIST/IMMUNE at most about once a second, and only for clear tilts (<0.8 or >=1.25).

**Pipes and combos.** A segment's `pipe` (set by js/player-build.js from the segment ahead: see notebook-workbench.md / notebook-player.md) is copied onto its bullets, with `charged` (the charged pipes of every synergy run ahead of the segment). `applyPipe` calls `pipeHit` for the pipe (stronger when `pipeBoost`) and then, charged, for each entry of `charged`: a minor status per hit (chill, jolt, singe, taint, dazzle, dry), at `CFG.SYNERGY.pipeBoost` and with the charged extras (+++chill freezes, +++jolt sparks). `ricochet`, `homing`, `split` and `burst` pipes act in `fire`, `update` and player-fire instead. Combos (`B.combo`) act in `applyCombo` (gas, bomb, berserk/madmoth, steam, giggle, napalm), in `applyHit` (plasma, shatter), in `applyElements` (napalm, arc, charged), and in the pulse branch (sonar, sonicboom).

**Ricochet.** `reflectMove` probes x and y separately with `G.hitSolid`, flips the velocity, spends a bounce, refreshes life, clears the hit list, and scales damage by `max(RICOCHET.floor, 1 / (1 + falloff * n))`.

**Resonance.** In the pulse branch, a player pulse ring crossing an armed player mine calls `Combat.resonate`: the mine explodes wider and harder (`CFG.RESONANCE`), and a new fixed pulse ring (`resonance: true`) spawns at the mine carrying the mine's elements, which can set off further mines.

**`Combat.update` (one ~200-line function), in phases:**
1. Build `near` (enemies within 1500 x 1100 of the player) and store it as `Combat.near`, then the hit grid from it
   (`buildGrid`, below).
2. Recharge orbiter shields; collect shielded orbiter balls.
3. Projectile loop, backwards: age; deck edge removal; move by kind (player) or straight / juggler-pin bounce (enemy); expiry (a Bomber Moth still explodes); solid collisions for shots, moths and enemy bullets (with side effects on `World.lastSolid`: boulders, trees, hives, mime walls); boomerang turn-back; mine stop; then hits: player bullets vs enemies (split pipe, mines, applyHit, shards, chain hops, orbit, pierce), hostile enemy bullets vs other enemies, orbiter blocks, then enemy bullets vs minions, head and segments (pie splat, water push, `Player.hit`, `Player.removeAt`). `Combat.remove` swap-pops and handles side effects.
4. Cloud loop: `laugh` (mirrors your controls, blinds monsters), enemy venom (costs a segment, hurts minions), then per-enemy effects by kind (steam blinds, giggle, dust dries, fire, ice, electric zaps every 0.4 s, poison / charged).
5. `updateFx`: age particles, arcs, floaters.

**Drawing (render-fx.js).** `Render.frame` draws clouds under the enemies, then projectiles, arcs, particles, and floaters last. `drawProjectiles` special-cases enemy pies, juggler pins and water drops, the deck pellet, rubber chickens (`G.rubber`), thrown juggling pins, then switches on `kind`; the default branch draws spears, the ricochet gold ring, lasers and beams, shard stars and plain dots. `drawClouds` picks a two-tone palette by `kind`.

## Interop

- **Called by:** `G.update` (js/main.js) calls `Combat.update` once per playing frame after World/Enemies (or Deck/Dungeon), and `Combat.updateFx` on other screens (game over, won, freeze). `Combat.fire` from js/player-fire.js, js/minions.js, js/enemies-parasites.js (allied parasites) and js/gallery.js. `fireEnemy` from monsters and bosses (notebook-enemies.md, notebook-bosses.md) and the deck. `damage`, `addStatus`, `resist`, `dry`, `explode`, `clouds.push`, `arcs.push` from js/perks.js and js/player-gear.js (notebook-perks.md, notebook-player.md), js/world-summons.js, js/world-discover.js. `tickStatus` is called by each monster's own update (Enemies, bosses, deck, dungeon), not by Combat. `burst` and `particles.push` are used nearly everywhere.
- **Calls into:** `G.hitSolid` and `World.lastSolid`, `World.wallAt` / `damageWall`, `World.meltTree`, `World.fryBoulder`, `World.shakeHive`, `World.levelOf` (notebook-world.md); `Player.hit`, `removeAt`, `segPos`, `syn`, `initTrail` (notebook-player.md); `Minions.hurt`; `Mega.charge` and `Perks.twinMult` (notebook-perks.md); `Deck.isSolid` (the saucer deck, js/deck.js); `Render.aimable`; `SFX.play`, `SFX.death`, `SFX.hitOn`, `SFX.attack`, `SFX.extra`, `SFX.layers` and `Music.stop` (notebook-audio.md); `e.onDeath` -> `World.loot` (notebook-world-loot.md).
- **Reads globals:** `G.mods` (bspeed, range, twin, cd, elem), `G.powerMult`, `G.level`, `G.mode`, `G.storm`, `G.deepFreeze`, `G.nightmare`, `G.rubber`; tables `BULLETS`, `ELEMENTS`, `PIPES`, `BOSSES`, `BESTIARY`.
- **CFG keys:** `DECK_DMG`, `LEVEL_RAMP`, `LEVEL_RAMP_LOW`, `PIPE_BOUNCES`, `PIPE_HOMING`, `RICOCHET`, `RESONANCE`, `SYNERGY`, `DEEP_FREEZE`, `DRY`, `GIGGLE`, `SONG.dmgMult`, `LAUGH.time`, `HEAD_R`, `SEG_R`. (`PIPE_BURST`, `STORM_PROJ` and `TWIN` are read by the callers.)
- **Save fields:** none directly. Kills and score are written by `onDeath` into `G` and saved by js/save.js.

## Invariants and gotchas

- One bullet per segment: `seg.inflight` must go back down exactly once per projectile, and only `Combat.remove` does that. Twin copies, burst-pipe extras and resonance rings have `seg: null` so they never hold a reload. Shards *do* carry `seg` and bump `inflight`, but do not restart `seg.cd` on removal.
- The reload clock starts when the bullet *lands* (`Combat.remove` sets `seg.cd`), not when it leaves.
- `dmg` vs `base`: Brawn (`G.powerMult`) is in `dmg` only. Status magnitudes use `base`. Keep that split when adding effects.
- Never remove a projectile any way other than `Combat.remove(i)`, and the main loop runs backwards because `remove` swap-pops.
- **The hit grid.** `buildGrid(near)` buckets the near enemies into `GRID` (128) px cells: each goes in every cell its hit
  circles cover, grown by `GRID_PAD` (32). A player bullet of size up to `GRID_PAD` tests only `cellAt(p.x, p.y)` (bigger
  ones test all of `near`); clouds test `around(x, y, r)`; player homers pick from on-screen enemies listed once per update.
  Cells keep `near`'s order, so a bullet meets the same enemy first as a full scan would. It is exact as long as an enemy
  does not move more than the pad between `buildGrid` and the hit test (nothing in `update` moves them). In a Bullet Storm
  at 250 monsters a bullet tests about 3 enemies instead of about 200; the phone's update went from ~5.3 to ~2 ms. A new
  hit test inside `update` should use the grid too, and a new pass that moves enemies mid-`update` must rebuild it.
- Bullets only meet `Combat.near`. Anything more than 1500 px across or 1100 px down from the player is untouchable. `Combat.near` is stale outside `update` (other callers use `Combat.near || G.enemies`).
- Every entity in `G.enemies` must have `hitCircles()`, `st`, `r`, and either `onDeath` or none. Hidden (burrowing) bugs return no circles.
- Burn, poison and spark ticks subtract hp directly: no floater, no Bullet Storm boss resist, and resistance is applied when the status is added (spark per zap).
- Statuses only tick when the monster's own update calls `Combat.tickStatus`. Sleeping monsters (far from the player) do not burn down.
- A charmed monster (`ally > 0`) that dies loses its `onDeath` for good: no loot, no score, no Mega charge.
- Allies are skipped by every player area effect via `ally > 0`; world minions carry `ally = 1e9`.
- `rowOf` gives a unique its own `unique-<id>` row, so a unique does NOT inherit its base bug's row (an Ironback is not ice-immune like `bug-beetle`); it falls back to `family-<kind>`.
- `e.res` is cached per monster on first lookup.
- Particles: `burst` refuses above 700; `updateFx` hard-caps at 1500. Some drawers push particles while drawing, which is why `updateFx` runs on every screen.
- Comments in js/combat.js sit at the end of long lines. Splicing a `//` comment mid-line silently comments out code. It happened on the chain-hop line in `Combat.update` (an out-of-hops Ricochet never flew home; fixed in 2af20dc). Use `/* */` inside a line; `tools/check-comments.py` catches it.

## Tuning — where its numbers live

- Bullet stats (`dmg`, `speed`, `range`, `cd`, `pierce`, `bounces`, `blast`, `hops`, `chainR`, `shield`, `combo`, `elems`): `BULLETS` in js/config.js (notebook-config.md, notebook-workbench.md).
- Global combat knobs: the CFG keys listed above, overridable from js/tuning.js via the config editor.
- Element resistances per creature, level and nightmare: js/bestiary.js, edited at http://localhost:8765/editor/bestiary/.
- Hard-coded in js/combat.js (not in CFG): burn 3 s at 0.55 x base, freeze 1.0 + 0.35 per ice, spark radius 95 + 25 per electric and 0.45 s tick, gas blast radius 130 and x2.5, napalm 90 px, steam cloud 75 px, boss freeze 1.4 s and 7 s cooldown, pipe magnitudes, the 0.35 s orbit re-hit, the 1500 x 1100 near box.

## Testing

- Run `python3 tools/devserver.py`, open http://localhost:8765/. Add `?god=1` for god mode on the first run (G toggles it in game). In god mode: V toggles mortal, U takes 19% off an awake boss, digits load loadout presets, L / O / X / Period warp to levels 2-5, Semicolon enters nightmare.
- `?bot=1&brain=scripted` runs the scripted autopilot so combat runs hands-off.
- Console: `Combat.projectiles.length`, `Combat.particles.length`, `Combat.near.length`, `G.enemies.filter(e => e.st && e.st.freeze).length`, `Combat.resist(G.enemies[0], 'fire')`, `Combat.addStatus(G.enemies[0], 'burn', 5, 10, true)`.
- God mode draws a profiler (update and draw ms) in the HUD: watch it when changing anything in the projectile loop.

## Change log hooks

Update this notebook in the same commit as any change to the system's behaviour.
