# World (overworld) notebook

The overworld is the endless side-scrolling map the centipede crawls through: a row of regions, each `CFG.REGION_W` wide and `CFG.WORLD_H` tall, ringed by a thorn hedge and closed on the right by a breakable thorn wall. Every 7th region is a boss arena; the six regions before it are one "level", and each level has its own biome (meadow-ish, graveyard, jungle, farm, circus). In code it is the global `World` (terrain, generation, spawn pacing, summons, discoverables, the swarm) plus the overworld half of `Render` (ground, hedges, walls, decorations, pickups). Things you circle to summon, and things you bump into to discover, live here too.

**Files:**

| file | what it holds |
|---|---|
| js/world.js | `World` itself: the arrays, `reset`, `levelOf`, `bossFor`, `regionAt`, `dread`, `regionName`, `ensure`, `genRegion`, and the per-frame `update` (spawning, region change, farm tractor beams, pickup physics) |
| js/world-loot.js | drops and pickups: see notebook-world-loot.md |
| js/world-terrain.js | `solidAt` (what stops bullets), `wallAt` / `wallBetween` / `damageWall` (thorn walls), `inLake`, `meltTree`, `fryBoulder` |
| js/world-summons.js | circle-to-summon: boulders (`updateRocks`, `wakeRock`, `roller`, `updateRock`, `boom`), barns (`updateBarns`, `openBarn`), tombstones (`updateGraves`, `raiseGhost`), singing trees (`updateTrees`, `singTree`), cannons (`updateCannons`, `fireCannon`, `updateFlight`) |
| js/world-discover.js | `shakeHive`, `updateDeco` (geysers, jack-in-the-boxes, coffins, the tractor, crop circles, the fortune teller), `tellFortune` |
| js/world-swarm.js | the SWARM: `updateSwarm`, `swarmWalls`, `swarmBug` |
| js/render-world.js | `Render.drawWorld` (ground, deco, lakes, entrances, stumps, graves, cannons, boulders, trees, hedges and walls), the `HEDGE_*` palettes and `hedgePal`, `hedgeBlobs`, `drawDeco` (every deco kind), `drawPickups` |
| js/render-circus.js | `drawMushroom`, `drawBeachBall`, `drawTentDoor`, `drawCannon`, `drawMirrors` |
| js/render-farm.js | `drawCorn`, `drawHay`, `drawSaucerHatch`, `drawTractor` (also used for the player's tractor and monster tractors) |
| js/render-jungle.js | `drawSongRing`, `drawNotes`, `drawPalm`, `drawIdol`, `drawTempleDoor`, `drawZiggurat` |
| js/render-graveyard.js | `drawCharredTree`, `drawGrave`, `drawCrypt`, `drawWraiths`, `drawFog` |

## Model — the state it owns, and its lifecycle

- **World arrays** (global across regions, each item tagged with `region`): `regions`, `trees`, `lakes`, `boulders`, `pickups`, `entrances`, `graves`, `wraiths`, `cannons`. Decorations are per region, in `R.deco`. `World.nearTrees` is a per-frame cache of live trees within 1500 px of the player.
- **A region `R`**: `{ idx, x0, x1, biome, level, haunted, jungle, farm, circus, spawnT, wall, boss, locked, bossDone, deco }`. `R.wall = { x: x1, sections: [{ y0, y1, hp, max, alive, swarmMade? }] }`, one section per `CFG.WALL_SECTION` px of height. Wall hp is `22 + 16 * idx`. A boss region starts `locked`; the boss's death (enemies-bosses.js) sets `locked = false, bossDone = true`.
- **Level and biome** come from the region index only: `World.levelOf(i) = floor(i / CFG.BOSS_EVERY) + 1`. Region `i` is a boss arena when `i % BOSS_EVERY === BOSS_EVERY - 1`. Level 1 picks randomly among meadow/forest/wetland/rocky (region 0 is always meadow; arena `arena`); level 2 graveyard (`ossuary`); 3 jungle (`ziggurat`); 4 farm (`landing`); 5 and beyond midway (`bigtop`). The boolean flags `haunted/jungle/farm/circus` on R are what everything else tests, not the biome name.
- **Lifecycle**: `G.start` (js/main.js) calls `Enemies.reset()` then `World.reset()`, which clears every array and generates regions 0 and 1. After that, regions are generated lazily: `World.update` calls `ensure(ri + 1)`, so the next region exists as soon as you step into a region. Warps (`G.warpBoss`, `G.warpLevel`) and `Save.resume` call `ensure` too. Nothing is seeded: generation uses `Math.random`, and a resumed save gets a fresh world.
- **G fields the world owns or writes**: `G.regionMax` (furthest region reached; drives intros and checkpoints), `G.crowd`, `G.lootScale`, `G.rubber` (fortune card timer), `G.swarm`, `G.swarmT`, `G.swarmCd`, `G.swarmRegion`, the one-shot intro flags `G.hauntedIntro/jungleIntro/farmIntro/circusIntro`, plus fx fields (`G.ring`, `G.tint`, `G.flash`, `G.shake`, `G.banner`).
- **Summonable state** lives on the object itself: `spin` (signed angle swept), `lastAng`, `spinT` (time out of range), `cheered`, plus `used` (graves, barns), `sung`/`song` (trees), `cd` (cannons), `gone` (boulders).

## How it works — the main flows

- **Generation, `World.genRegion(i)`**: build R and its wall; then place things with an internal `place(r, tries)` that rejects spots via `okSpot` (spawn pocket in region 0, the entry lane at the left of every region, other trees, boulders, lakes, entrances, graves, cannons and `solid` deco). Order matters because `okSpot` only checks what is already placed: lakes, then the dungeon entrance (never in an arena; 60% in region 0), trees (jungle bigger, palms; farm corn; circus mushrooms and lollipops; level-1 hives), cannons, boulders, graves, then per-biome deco. An arena then calls `Enemies.spawnBoss(World.bossFor(level), x0 + 1900, WORLD_H/2, i, level)` and returns: no monsters, parasites or cleanse. Other regions seed parasites, a 25% Cleanse pickup, snakes and bugs (counts grow with region index and `G.level`; region 0 keeps its left side empty), and maybe one named unique.
- **Per-frame, `World.update(dt)`** (called from `G.update` only in `G.mode === 'world'`, before `Enemies.update`): build `nearTrees`; `ensure(ri+1)`; run every summon and deco updater; on entering a new furthest region set `G.regionMax`, `Save.write()`, call `G.ascend(R)` for an arena (and return), otherwise show the region name, switch to the level's music (`Music.levelTheme(R.level)`, else stop the music unless a boss is awake) and show the biome's first-time intro. Then `updateSwarm`, then the spawn tick, then the farm tractor beams, then pickup physics (notebook-world-loot.md).
- **Spawn pacing**: each region has `spawnT`. When it runs out (never in an arena) the target population is `base = CFG.DENSITY + ri + CFG.DENSITY_LEVEL*(G.level-1) (+ CFG.NIGHTMARE.density)` plus `World.crowdBonus()` (more monsters for a stronger build). It sets `G.crowd` and `G.lootScale = base/(base+extra)`, resets `spawnT` (shorter deeper in, and scaled by lootScale; floor 0.8 s with a crowd bonus, else 1.6 s), and if fewer non-allied enemies are alive in this region than the target, spawns ONE monster at a random spot at least 380 px from you and not in a lake: a parasite (share from `CFG.PARA_SHARE`), a snake, or a bug, or in nightmare maybe a unique (`Enemies.maybeUnique`).
- **Walls, `World.wallAt` / `wallBetween` / `damageWall`**: a bullet or the head hitting an alive section damages it; at 0 hp the section dies and you can pass. Locked arena walls and both walls of a swarm's region refuse damage. `wallBetween` catches moves that jump clean across a wall in one step (geyser, shove); Player uses both.
- **Solids, `World.solidAt(x, y, r)`**: trees (canopy circle, offset up), boulders, graves, cannons, and mime walls from `Combat.near`. It records `World.lastSolid` ({tree|boulder|grave|cannon|mime}); Combat reads it right after to melt trees (napalm), fry boulders (plasma) and shake hives. Lakes do not stop bullets; they slow the player (`World.inLake`) and spawns/deco avoid them.
- **Circle-to-summon** (world-summons.js): the same pattern everywhere. While you are within `K.radius + r` and within 400 px in x, the signed angle you sweep round the object accumulates in `spin` (reversing direction decays it). Leave for 1.5 s and it resets. At 0.4 laps you get a "keep circling" message once; at `K.laps * Perks.summonLaps()` laps it fires:
  - boulder: `wakeRock` turns it into a `roller` entity (`kind: 'rock'`, ally, in `G.enemies`) that `updateRock` drives at the nearest monster, flattening trees, then `boom` (blast damage x `G.powerMult` x `Perks.summonPower()`). Rollers that reach a wall damage it and explode.
  - barn (farm deco): `openBarn` releases three rollers flagged `farm` with `rank` 0..2 so they pick different targets.
  - tombstone: `raiseGhost` charms every non-boss monster in `CFG.GRAVE.range` via `Enemies.charm`, adds a wraith (drawn by `drawWraiths`). Nobody in range: nothing is used up.
  - jungle tree: `singTree` adds status `hypno` to the crowd (they walk to the tree via `Enemies.updateHypno` and take `CFG.SONG.dmgMult`), and anything wandering in while it plays is caught too; `Music.song` plays. One song per tree (`sung`).
  - cannon: the barrel turns to the crowd as you circle; `fireCannon` teleports you to its mouth and sets `Player.flight`; `Player.update` then calls `World.updateFlight` instead of steering: straight line, hits everything once, a wall stops you and takes damage. Cannons cool down 6 s and can be reused.
- **Discover** (world-discover.js, `updateDeco` scans deco of regions ri-1..ri+1): geysers erupt on a timer and toss monsters (damage, stun) and you (no damage); a jack-in-the-box spawns a `kamiclown` when you pass within 70 px; a coffin at 80 px is 50/50 a tier-3 bullet plus 4 dots, or two flaming skulls; the farm tractor at 44 px gives `Player.giant` + `Player.tractor` for `CFG.GIANT.time`; a crop circle with `gift` is circled like a summon (no CFG laps key: just `Perks.summonLaps()`) and beams down a tier-2 bullet; the fortune tent at 50 px flips one card: chickens (nearby bugs and snakes become chickens), rubber (`G.rubber = 12`, x1.5 player damage in player-fire.js), rain (14 dots + 2 tier-2 bullets), or the star (mega charged). Beehives are shaken by Combat calling `World.shakeHive(tree)` on a bullet hit: charmed gnats.
- **Farm tractor beams** (in `update`): a farm entrance within 460 px periodically pulls the player toward it for 2 s; it can drag you into the dungeon (which for the farm is the saucer Deck when `CFG.DECK_MODE`).
- **The swarm** (world-swarm.js): the clock `G.swarmT` runs per region (reset when you change region). After `CFG.SWARM.after` s in one non-arena region past `minRegion`, and once no part of the body sits in either wall gap, it starts: broken sections of both walls regrow (`swarmMade`, drawn gold), a warning phase, then `waves` waves spawned off screen by `swarmBug` (weakened bugs, `lootMult = SWARM.loot`, the odd elite via `Enemies.makeRare`). When all are dead: score bonus, regrown sections crumble again, cooldown. Walls refuse damage and Player refuses dungeon entry while `G.swarm` is set.
- **Drawing, `Render.drawWorld`** (from `Render.draw` when `G.mode === 'world'`): per visible region the ground gradient from `BIOMES[b].grass/grass2` (circus cycles hue), dread tint, circus stripes, farm crop rows; the shared `grassTile`; jungle shade and the ziggurat; every visible deco via `drawDeco` (so deco sits UNDER lakes and trees); lakes (circus lakes are ball pits, `l.pit`); entrances by biome (crypt, temple door, saucer hatch, tent, else the dirt mound); stumps; graves; cannons; boulders by biome (idol, hay, beach ball, else rock with lap ring); trees by type; then hedges and wall sections with `hedgePal(R)`. After enemies, `Render.draw` calls `drawPickups`, `drawMirrors`, and later `drawWraiths` and `drawFog`.

## Interop

- **Called by**: `G.update` → `World.update`; `G.start`/range editor → `World.reset`; `G.hitSolid` (main.js) → `wallAt`, `damageWall`, `solidAt` for every projectile; Player (player.js) → `wallAt`, `wallBetween`, `inLake`, `updateFlight`, reads trees/boulders/graves/cannons/deco `solid` for collisions and entrances for dungeon entry; player-hurt.js → `wallAt`, `damageWall`; Combat → `lastSolid`, `meltTree`, `fryBoulder`, `shakeHive`, `levelOf`; enemies*.js and config.js `BUGS[].weight` → `levelOf`, `regions`, `regionAt`; Save (save.js) → `ensure`, `regionAt`, `regionName`; render-hud.js → `regionName`, swarm bar; bot.js reads most arrays.
- **Calls into**: `Enemies.spawnBoss/spawnSnake/spawnBug/spawnParasite/spawnUnique/maybeUnique/pickBug/pickParasite/uniquePool/uniqueAlive/paraSpawnAvg/snakeSpawnAt/charm/makeRare` (notebook-enemies.md, notebook-bosses.md); `Combat.burst/damage/addStatus/mk/particles` (notebook-combat.md); `Perks.summonLaps/summonPower/magnetRadius` (notebook-perks.md); `Player.estimateDps` via `crowdBonus` (notebook-player.md); `Music.levelTheme/isLevelTheme/start/stop/song`, `SFX.play` (notebook-audio.md); `G.ascend`, `G.msg`, `Save.write` (notebook-core.md); `Render.viewW/viewH` for swarm spawn radius (notebook-render.md). Loot and pickups: notebook-world-loot.md. Dungeon/Deck entry: notebook-dungeon.md.
- **CFG keys read**: `WORLD_H`, `REGION_W`, `HEDGE`, `WALL_T`, `WALL_SECTION`, `BOSS_EVERY`, `DREAD_REGIONS`, `DENSITY`, `DENSITY_LEVEL`, `NIGHTMARE.density`, `PARA_SHARE`, `BALANCE.crowd*`, `ROCK`, `GRAVE`, `SONG`, `CANNON`, `HIVE`, `GEYSER`, `FORTUNE`, `GIANT`, `SWARM`, `MEGA_MAX`, `HEAD_R`, `SEG_R`; and the separate global `BIOMES` (js/config.js, after `CFG`).
- **Save fields**: the world itself is never saved. `Save.write` stores `region: Save.region()` (the region you are in; in a beaten arena, the one after it); `Save.resume` regenerates up to it, calls `G.skipBossesBefore(ri)` and sets `G.regionMax = ri - 1` so the region intro and level music fire again.

## Invariants and gotchas

- Generation order in `genRegion` is load-bearing: `okSpot` only avoids what was placed earlier. Lakes and entrances first; scattered litter (bones, ferns, pumpkins, midway deco) is placed with plain `U.rand` and only avoids lakes, so it can sit under trees.
- `genRegion` uses `G.level` (the player's level now) for monster density, not `R.level`. Regions are generated one ahead, so the numbers are those of the moment you entered the previous region.
- Arena boss x is hard-coded `x0 + 1900` in both `genRegion` and `drawWorld` (centre ring, ziggurat). Change both.
- Anything new that should stop bullets must be added to `solidAt` AND to Player's collision loop AND to bot.js's solid list; anything that should block placement must be in `okSpot`; anything generated must be cleared in `reset` and in editor/range/index.html (it clears `trees, boulders, graves, cannons, pickups, entrances` and every `R.deco` by hand).
- Deco with a `solid` radius blocks placement, and Player treats it as a hit (costs a segment) only in the current region.
- `World.update` returns early on the frame you reach an arena (`G.ascend`), skipping the rest of that frame.
- `hiveT` is decremented inside `drawWorld` (render mutates state, by a fixed 1/60).
- The BIOMES entries carry `haunted/jungle/farm/circus` flags, but `genRegion` ignores them and derives the flags from the level. Levels above 5 stay circus, and `Music.levelTheme` keeps using level 5's names there.
- Summon lap counts are all `laps: 1` in config; some comments still say two or three laps.

## Adding a biome or a decoration

1. **Generation flags** (js/world.js `genRegion`): the `level → haunted/jungle/farm/circus` line and the `biome` ternary (field and arena names). A new flag also goes on R, and into every `R.haunted ? ... : R.jungle ? ...` chain listed below.
2. **`BIOMES`** in js/config.js: `name`, `grass`, `grass2`, counts `trees`, `lakes`, `boulders`, and optional `graves`, `cannons`. Add both a field biome and an arena biome.
3. **Boss**: `World.bossFor(level)`; the arena spawn in `genRegion`.
4. **Deco kinds**: push `{ x, y, kind, seed, a, ... }` into `R.deco` in `genRegion` (use `place()` and a `solid` radius for big things); draw it in a new `else if (d.kind === '...')` branch of `Render.drawDeco` (js/render-world.js); unknown kinds fall through to the will-o'-wisp. Interactive kinds get a branch in `World.updateDeco` (world-discover.js) or their own updater called from `World.update`. Solid kinds need a name in Player's deco collision message.
5. **The biome's render file** js/render-<biome>.js (`Object.assign(Render, {...})`) for its tree, boulder and entrance drawers, wired into the tree, boulder and entrance branches of `drawWorld`. Add its `<script>` tag after render-world.js in BOTH index.html and editor/range/index.html.
6. **Hedge palette**: a `HEDGE_<NAME>` object and a branch in `Render.hedgePal(R)`.
7. **Ground extras**: the dread tint and per-biome passes in `drawWorld`; the vignette in `Render.draw` (js/render.js, tests `RR.haunted/farm/circus`).
8. **Music**: `Music.LEVEL_NAMES` in js/music.js (`['levelN', '<name>']`), and a theme of that name in js/music-themes.js.
9. **Intro**: a `G.<name>Intro` flag, its message in `World.update`, and its reset in js/main.js.
10. **Monsters**: bug `weight` functions in `BUGS` use `World.levelOf`; snakes pick their skin from R flags (enemies-snakes.js); parasites draw as sheep on the farm (render-enemies.js). See notebook-enemies.md.
11. README.md feature list.

## Tuning — where its numbers live

`CFG` in js/config.js (overridable per path in js/tuning.js via the config editor): world size and walls at the top; `ROCK`, `GRAVE`, `SONG` (summons), `CANNON`, `HIVE`, `GEYSER`, `FORTUNE`, `GIANT`, `SWARM`, `DENSITY`, `DENSITY_LEVEL`, `PARA_SHARE`, `BALANCE.crowdRef/crowdPerDoubling/crowdMax`. `BIOMES` is a separate const, not under CFG, so tuning.js cannot reach it. Many small numbers are inline in `genRegion` (wall hp `22 + 16*i`, deco counts, entrance and hive chances, 25% cleanse), `updateDeco` (trigger radii 50/70/80/44 px, coffin 50/50), `fireCannon` (6 s cooldown) and `update` (beam range 460, pull 150 px/s, spawn timer formula).

## Testing

- Dev server http://localhost:8765/ (tools/devserver.py). `?god=1` arms god mode (or press G). In god mode: N next region, L / O / X / . warp to the first graveyard / jungle / farm / circus region, B / Y / , / 0 / Q (or ') warp to bosses 1-5, K summons the nearest boulder, I sings the nearest jungle tree, H enters the nearest dungeon, V toggles mortal.
- `?bot=1&brain=scripted` runs the autopilot offline; its state snapshot tracks the nearest grave and jungle tree and the region's wall (bot.js), so it exercises summons and wall breaks.
- The range editor (editor/range/) builds a clean world from these same files.
- Console: `World.regions.length`, `World.regions[World.regionAt(Player.x)]`, `G.swarmT = 1e3` (forces a swarm), `G.crowd`, `G.lootScale`, `World.tellFortune(World.regions[World.regionAt(Player.x)].deco.find(d => d.kind === 'fortune'), World.regionAt(Player.x))` in a circus region.

## Change log hooks

Update this notebook in the same commit as any change to the system's behaviour.
