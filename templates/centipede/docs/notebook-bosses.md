# Bosses notebook

Each level ends in a boss arena. After six wall-breaks the seventh region is an arena whose right-hand wall is sealed. A boss sleeps under the ground there. It wakes when you come near, and its hp is sized to your build at that moment. When it dies, the wall opens, `G.winLevel` runs the celebration screen, and you go on to the next level's biome. There are five bosses: King Crab (level 1), Skull King (2), Jungle Serpent (3), Brain Overlord (4) and Clown Car (5, the end of the demo, then NIGHTMARE). In code, a boss is an entry in `Enemies.list` with `kind: 'boss'` and `type` = a key of `BOSSES`. Its behaviour is spread over `Enemies.*` methods, one file per boss.

**Files:**

| file | what it holds |
|---|---|
| js/enemies-bosses.js | `Enemies.bossHp`, `spawnBoss` (the boss object and its `onDeath`), the crab's stones (`spawnStone`, `updateStone`), the shared `updateBoss` (wake, emerge, mood/music, dispatch, then the crab's own AI), the stomp (`tickStomp`, `stompLand`) |
| js/enemies-crab.js | `clawAt`, `clawCatches`, `crabStrike` (charge, then lunge or claw shot), `snip` (cuts the body in half) |
| js/enemies-skull.js | `updateSkull`: hover, fireball spread, ring of fire, flaming-skull summons, nightmare grave ghosts |
| js/enemies-serpent.js | `updateSerpent` (slither, venom globs, hatchlings, coil bites), `serpentTrail` (the body's segment positions) |
| js/enemies-overlord.js | `updateOverlord`: laser, tractor-beam abduction, captive guns, saucer stomp, brainlings |
| js/enemies-clowncar.js | `updateClownCar`: drive / warn / run / gas state machine, clowns, horn, flower, doors off at half hp |
| js/render-bosses.js | `Render.drawCrab`, `drawSkullBoss`, `drawSerpent`, `drawOverlord`, `drawClownCar`, `clownHead`, `drawStompShadow`, `drawStompFoot`, `drawSaucer`, `drawFoot` |
| js/config.js | `BOSSES` (every boss number), `CFG.BOSS_EVERY`, `CFG.WIN_RESET`, `BIOMES.*` arena biomes |
| js/world.js | `World.levelOf`, `World.bossFor(level)`, arena generation in `World.genRegion` |
| js/main.js | `G.ascend` (climb cinematic), `G.winLevel`, `G.warpBoss`, `G.skipBossesBefore`, `G.enterNightmare` |
| js/music.js / js/music-themes.js | `Music.bossTheme`, `setMood`, `fanfare`; the boss themes in `Music.THEMES` |

## Model — the state it owns, and its lifecycle

- **Arena region.** `World.genRegion(i)` marks region `i` as an arena when `i > 0 && i % CFG.BOSS_EVERY === CFG.BOSS_EVERY - 1`. The region gets `boss: true`, `locked: true`, `bossDone: false` and an arena biome (`arena`, `ossuary`, `ziggurat`, `landing`, `bigtop`). An arena has no dungeon entrance, no seeded monsters and no ongoing spawns. Generation ends with `Enemies.spawnBoss(World.bossFor(level), x0 + 1900, WORLD_H / 2, i, level)`.
- **Boss object** (`spawnBoss`). It has `kind: 'boss'`, `type`, `region`, `level`, `hidden: true`, `awake: false`, `emerge`, `st` (statuses) and `mood`. It also carries timer fields for every boss type (`throwT`, `stoneT`, `stompT`, `fireT`, `summonT`, `spitT`, `broodT`, and the serpent's `trail`/`segPos`/`segN`). Other per-boss state is created lazily on first update: `e.snip`, `e.snipCd` (crab); `e.ringT`, `e.unmarked`, `e.ghostT` (skull); `e.beam`, `e.abd`, `e.captives`, `e.stash` (overlord); `e.car` (clown car); `e.stomp` (skull and overlord).
- **Lifecycle.**
  1. Spawned asleep and hidden when its arena is generated.
  2. **Wake.** `updateBoss` wakes it when the player is within 1100 px on x. It sets `maxhp = hp = Enemies.bossHp(B, level)`, prints `B.rise` and `B.desc`, and starts `Music.bossTheme`.
  3. **Emerge.** About 2.2 s of dust. Then `hidden = false`, and the crab spawns `B.shieldN` orbiting stones.
  4. **Fight.** The shared mood logic runs, then the per-boss update.
  5. **Death.** `Combat.kill` calls `e.onDeath`, which ends in `G.winLevel(level)`.
- **Stones** (crab only) are separate `kind: 'stone'` enemies with `owner` = the crab. `mode: 'orbit'` circles the crab as a shield and dies with it. `mode: 'roll'` is thrown at you and dies at the arena edge or when `life` runs out.
- **G state touched.** `G.level` (set to level + 1 on a win), `G.state = 'won'`, `G.won` (the tally for the celebration screen), `G.cinematic` (the climb), `G.ring`, `G.shake`, `G.flash`, `G.banner`, `G.slowmo`, `G.tint`.

## How it works — the main flows, named by function

- **Getting there.** `World.update` sees the player step into a new region (`ri > G.regionMax`). If the region is an arena, it calls `G.ascend(R)`. That sets `G.cinematic` (3.2 s; drawn in render-screens.js as LEVEL UP, with `BOSSES[...].desc`). When it ends, `G.update` puts the player at `R.x0 + 160`, recalls minions and says the wall ahead is sealed. While `R.locked`, `World.damageWall` (world-terrain.js) refuses wall damage.
- **hp sizing.** `Enemies.bossHp(B, level)` = `round(min(hpCap, max(hpMin, Player.estimateDps(B.armor, 0.6) * fightSecs))) + hpL * (level - 1)`. It runs once, at wake. A weak build is never walled (the `hpMin` floor). A huge build gets a short fight (the `hpCap` ceiling).
- **Shared update** (`Enemies.updateBoss`, called from `Enemies.update` for `kind === 'boss'`). It handles wake, then emerge, then `Combat.tickStatus`, then **mood**. The mood comes from the hp fraction: below 50% = 1, below 30% = 2, below 20% = 3, below 10% = 4. Moods 1-3 call `Music.setMood(min(3, mood), mood === 3)`, which raises the pitch, and mood 3 adds fever. Mood 4 stops the music and shows a per-boss "desperate" line. Then it dispatches on a flag in `BOSSES`: `foot` goes to `updateSkull`, `serpent` to `updateSerpent`, `alien` to `updateOverlord`, `car` to `updateClownCar`. With none of these flags it falls through to the crab code in the same function.
- **Common patterns.** Every boss is "enraged" below 30% hp, which shortens its cooldowns. Every boss is "desperate" at mood 4. Each boss keeps `B.keep` px from you and strafes, is clamped inside its region (x0 + ~120 to x1 - ~120), and bites on contact for `e.power`. Each one aims with `Player.predict(t)` to lead your arc.
- **The crab** (the fallthrough in `updateBoss`):
  - It faces you and sidesteps incoming player bullets (`evadeChance`, `evadeCd`). At mood 4 it flees.
  - It throws `throwN` parasites, sized `throwHp + throwHpL * (level - 1)`, through `Enemies.spawnParasite`.
  - It keeps `shieldN` orbiting stones topped up and rolls a stone at you every `stoneCd`.
  - **Pincer strike** (`crabStrike`): a charge phase (`e.snip.phase = 'charge'`), then a `lunge` if you are within 300 px, else a `shoot` (the claw flies out on its arm). Below `B.aimLevel` (and outside nightmare) it locks onto where you were when the claw lit. From `aimLevel` on, or in nightmare, it re-aims as it fires.
  - A claw that closes on you calls `snip` if you have 2 or more weapon segments, else `P.hit(1)`. `snip` cuts off the back half of the weapon segments. They become loose pickups (`pk.loose`, fuse `CFG.LOOSE.time`), flung away from the crab.
- **The stomp** (`tickStomp`, `stompLand`), shared by the Skull King (a foot) and the Overlord (its saucer, `S.ship`). The phases are `warn` (a shadow at the predicted spot) → `down` → `hold` (it lands: `stompLand`) → `up`. `stompLand` hits the head or any segment inside `stompR` for `stompDmg`. It also deals 40 damage to any non-boss enemy under it, minions included (via `Minions.hurt`), and fires `stompShards` bone shards. `Render.drawStompShadow` draws the shadow under everything. `Render.drawStompFoot` draws the foot or saucer over everything.
- **The Skull King** (`updateSkull`): stomp, a fireball spread (`fireN`), a ring of fire (`ringN`), and flaming skulls (`spawnBug('skull')`, at most 5 alive). In nightmare it adds 6 unmarked graves (`e.unmarked`) that raise `kamighost` bugs (at most 8). Its hp scales with `Enemies.nmHp`.
- **The Serpent** (`updateSerpent`): it slithers in a sine wave and bounces off the arena edges. `serpentTrail` resamples a breadcrumb trail into `segN` coil positions (`e.segPos`, spacing `segR * 1.5`). It spits venom globs (`fireEnemy` with `venom: true, cloudR, cloudT`) that burst where you are heading. It drops hatchling snakes from the tail (`spawnSnake` with `hatchling: true`, at most 6). The coils bite for 1. `hitCircles` includes every coil, so the whole body can be shot.
- **The Overlord** (`updateOverlord`):
  - **Laser.** `e.beam` goes `warn` → `fire` and sweeps toward you at `beamSweep`. On the head it does `P.hit(1)`. On a segment it calls `P.removeAt(j, 'laser')`.
  - **Tractor beam.** `e.abd` puts a warning circle on your predicted tail. Up to `abductN` weapon segments inside it are spliced out of `Player.segments` into `e.captives` (minions are skipped). Each captive orbits the boss and fires its own bullet type at you every `captiveCd`. Pickups inside the circle are lifted (`pk.lift`) into `e.stash`. The abduction also sets `G.slowmo = CFG.SLOWMO.time`.
  - It also does the saucer stomp, and beams down brainlings (at most 5).
  - On death, `onDeath` gives back the captives as weapon pickups and rains the stash back down.
- **The Clown Car** (`updateClownCar`). `e.car.state` cycles `drive` → `warn` (it revs, `runWarn`) → `run` (a straight charge at `runSpeed`, bouncing off the edges, leaving laughing-gas clouds in `Combat.clouds`) → `gas` (it refuels for `gasT`). While refuelling, `e.gasOpen = true`, and `Combat.applyHit` multiplies damage by `gasMult`.
  - The run does `runDmg` to the head and removes any segment it clips.
  - Kamikaze clowns (`kamiclown`, at most 9, or 14 in nightmare) come out of the car. Below 50% hp `C.doorsOff` doubles their rate and adds 2 per wave.
  - The horn (`hornR`, `hornPush`) shoves you and reverses your bullets inside the radius. The flower fires `flowerN` push-only shots.
  - In nightmare it never runs out of gas, rams through segments even while you are invulnerable, and drops clowns mid-run.
- **Death** (`spawnBoss` → `onDeath`):
  - Adds score (`B.score * level`) and sets `R.locked = false` and `R.bossDone = true`. Wall sections drop to 10 hp, so "the wall ahead is open".
  - Drops 3 loot rolls (`World.dropAt`) and a Cleanse.
  - Skull King: 2 spear pickups. Clown Car: 2 pin pickups, confetti and the `trombone` sound. Overlord: gives back its stash and captives.
  - Kills every stone, and every `skull` or `kamiclown` bug in the region.
  - Calls `G.winLevel(level)`.
- **After the win** (`G.winLevel`):
  - Stops the music. If `CFG.WIN_RESET > 0` it shrinks the body (`Player.shrinkTo`); the default 0 keeps everything.
  - Fills `G.won`, sets `G.level = level + 1` and `G.state = 'won'`, and fully charges the mega.
  - Plays `Music.fanfare(level)` (`complete_levelN`, else `complete`), or an SFX chime if there is no fanfare.
  - On the won screen, Enter or Space sets `state = 'playing'`, stops the fanfare and calls `Save.write()`.
  - If `G.won.demoEnd` (level 5), Enter calls `G.enterNightmare()` instead. After a nightmare clear it writes `localStorage['centipede.nightmareCleared']`, clears the save and returns to the title.
  - The next region is the next level's biome, because `World.levelOf(i)` comes from the region index.
- **Music.** `Music.bossTheme(type, B)` returns a tracker song saved under the boss id (`crab`, `skull`, `serpent`, `overlord`, `clowncar`) if one exists. Otherwise it returns `B.music` (`skull`, `serpent`, `alien`, `clown`), falling back to `'crab'`. `Combat.kill` stops a boss theme the moment the boss dies.

## Interop — reads from / is called by / calls into other systems

- **Called by:** `Enemies.update` (notebook-enemies.md) for `kind === 'boss'` and `'stone'`. `World.genRegion` and `World.update` (notebook-world.md) for the spawn and the climb. `Combat.kill` (notebook-combat.md) for `onDeath`.
- **Calls into:**
  - Player (notebook-player.md): `hit`, `predict`, `removeAt`, `segPos`/`segments`, `resolveBuild`, `computeSegPos`, `estimateDps`, `weaponCount`, `isMinion`.
  - Combat (notebook-combat.md): `fireEnemy` (with the options `venom`, `push`), `burst`, `particles`, `clouds`, `projectiles` (crab dodge, horn reversal), `tickStatus`, `damage`.
  - Enemies (notebook-enemies.md): `spawnParasite`, `spawnBug('skull' | 'brainling' | 'kamighost' | 'kamiclown')`, `spawnSnake`, `nmHp`.
  - World (notebook-world-loot.md): `dropAt`, `mkPickup`, `pickups`.
  - `Minions.hurt` (notebook-perks.md or notebook-player.md for minions).
  - SFX and Music (notebook-audio.md).
  - G (notebook-core.md): `winLevel`, `msg`, `banner`.
- **Read by others:**
  - render.js picks the drawer from the `foot`/`serpent`/`alien`/`car` flags. render-hud.js `shownBoss` draws the health bar (name and level). render-screens.js uses `BOSSES[World.bossFor(...)]` in the climb and won screens (notebook-render.md).
  - `Combat.damage` applies `megaResist` during a Bullet Storm. `Combat.applyHit` applies `gasMult`. `Combat.addStatus` gives bosses a freeze cap of 1.4 s and a 7 s immunity (`e.freezeCd`) (notebook-combat.md).
  - Perks megas multiply by `megaResist` (notebook-perks.md).
  - The bot avoids coils and the abduction circle (bot.js; notebook-tools.md).
  - The bestiary's `boss-<type>` resist rows and an optional `tempo` stat (multiplies every `*Cd` key) are applied at load by `applyBestiaryStats` in enemies.js.
  - SFX custom slots are keyed `boss-<type>`: `death:`, `hit:`, `attack:` (`SFX.typeOf`).
- **CFG keys:** `BOSS_EVERY`, `WIN_RESET`, `MEGA_MAX`, `WORLD_H`, `HEAD_R`, `SEG_R`, `LOOSE.time`, `INVULN`, `SLOWMO.time`, `HEDGE`, `NIGHTMARE.pitch` (through `Music.setMood`).
- **Save fields:** none of its own. Bosses are not saved. `G.level` and `nightmare` are saved (save.js). `Save.resume` calls `G.skipBossesBefore(ri)`, which marks every earlier arena as beaten.

## Invariants and gotchas

- `spawnBoss` gives a sleeping boss a stand-in hp (`hpMin` + `hpL` per level); `bossHp` sizes the real hp to your build when it wakes. Do not balance against a sleeping boss's hp.
- Bosses use `Enemies.bossHp`, not `nmHp`. Nightmare makes a boss harder only through its behaviour branches, not through its hp.
- The dispatch flags (`foot`, `serpent`, `alien`, `car`) are checked in that order, both in `updateBoss` and in render.js. A new boss with none of them runs the crab AI and draws as a crab.
- Per-boss state is created lazily (`e.car`, `e.captives`, `e.ringT === undefined`). A boss must survive its first update before render code can rely on those fields; `drawClownCar` guards with `e.car || {...}`.
- `Enemies.update` drops dead entries before `updateBoss` runs, so `updateBoss`'s `if (e.dead) Music.stop()` rarely fires. `Combat.kill` is what stops the theme.
- Stones are `kind: 'stone'`. Homing and chain bullets, grave ghosts, tree songs and laughing gas all skip them. Rock-type bullets do `wallMult` extra damage to them.
- `stompLand` damages your minions and the boss's own summons. `stompLand` uses the message "the skeletal foot" even for the Overlord's saucer.
- The overlord's abducted segments leave `Player.segments`. They come back only as pickups when it dies. If you warp away, they are lost.
- The arena wall is sealed through `R.locked`. `G.skipBossesBefore` and the warps unlock it by hand.
- **(unverified)** Saving on the won screen happens while you stand in the arena. `Save.resume` regenerates the world, sets `regionMax = ri - 1` and skips only arenas before `ri`. A resume there may climb back into the same arena with the boss asleep again.
- The order of the 'won' and cinematic checks in `G.update` matters: the won screen freezes everything except FX.

## Tuning — where its numbers live

- `BOSSES` in js/config.js holds every cooldown, count, radius, speed, damage and hp formula input (`hpMin`, `hpL`, `fightSecs`, `hpCap`), plus `keep`, `megaResist`, `gasMult` and the flavour strings `rise` and `desc`. The comment above `BOSSES` explains the hp formula.
- js/tuning.js can override any `CFG` path, but `BOSSES` is a separate global, so tuning.js does not reach it. The bestiary editor's `tempo` stat (js/bestiary.js `stats["boss-<type>"]`) scales a boss's `*Cd` keys. None are set today.
- Hard-coded values in the update functions: the wake distance of 1100 px, the 2.2 s emerge, the mood thresholds, the summon caps (5 skulls, 6 hatchlings, 5 brainlings, 9/14 clowns), the enrage threshold of 30%, the stone roll speed of `280 + 20 * level`, and the arena clamp margins.
- Boss order: `World.bossFor(level)`. Arena spacing: `CFG.BOSS_EVERY`.

## Testing — how to see it working

- Dev server: `python3 tools/devserver.py`, then http://localhost:8765/. Add `?god=1` to arm god mode on a phone; on a desktop press G.
- God keys in the world:
  - Warp to a boss: B (crab), Y (Skull King), `,` (Serpent), 0 (Overlord), Q or `'` (Clown Car). `G.warpBoss` hands you a level loadout.
  - V toggles mortal. U takes 19% off the awake boss's hp, to test music moods and flee lines.
  - `;` enters nightmare with the current build.
- Bot: `?bot=1&brain=scripted` fights bosses with the scripted brain (goal `boss`).
- The god-mode footer on the workbench prints the crab's `bossHp` against your estimated dps.
- Console: `G.enemies.filter(e => e.kind === 'boss')`, `Enemies.bossHp(BOSSES.skull, 2)`, `World.regions.filter(r => r.boss)`.

## Adding a boss

1. `BOSSES.<id>` in js/config.js: `name`, `r`, `hpMin`, `hpL`, `fightSecs`, `hpCap`, `keep`, `speed`, `power`, `armor`, `score`, `color`, `dark`, `megaResist`, `rise`, `desc`, `music`, and a new dispatch flag (for example `foo: true`). Every `*Cd` key is scaled by the bestiary `tempo`.
2. `World.bossFor(level)` in js/world.js: give it a level. If it brings a new level, add the arena biome to `BIOMES`, its branch in `World.genRegion`, and `levelOf`-based flags.
3. A new js/enemies-<id>.js with `Object.assign(Enemies, { update<Id>(e, dt, mood) {...} })`. Add its `<script>` to index.html after enemies-bosses.js. Add it to the editors that load the game scripts (the range editor loads the enemy files; check `editor/*/index.html`).
4. `Enemies.updateBoss` in js/enemies-bosses.js: add a dispatch line for the flag, and a mood-4 message in the `mood === 4` ternary. Add the dust colour if wanted.
5. `onDeath` in `spawnBoss`: special drops, and which summons die with it.
6. js/render-bosses.js: add `draw<Id>(e)`. In js/render.js, add the flag branch in the enemy draw dispatch. If it stomps, `drawStompShadow` and `drawStompFoot` already handle `e.stomp`; set `ship` for a saucer look.
7. Music: a tracker song saved under the boss id in js/music-themes.js, or a classic theme named by `music:`. Add the level's `complete_levelN` and `levelN` names to `Music.LEVEL_NAMES` if it adds a level.
8. Bestiary: a `boss-<id>` resist row in js/bestiary.js (editor/bestiary/). SFX: `death:boss-<id>`, `hit:boss-<id>` and `attack:boss-<id>` in the SFX editor.
9. God warp key in `G.update` (main.js) and its text in `toggleGod`. Add a `levelLoadout` branch if it is a new level.
10. Bot hazards in bot.js if it has a new area attack. Update `G.winLevel`'s `demoEnd` if the last level changes.
11. README.md feature list (one bullet per boss) and the file table.

## Change log hooks

Update this notebook in the same commit as any change to the system's behaviour.
