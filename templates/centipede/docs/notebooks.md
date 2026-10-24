# Notebooks

One notebook per system. Each one says what the system is for, the state it owns, how it works, who it talks to, the
CFG keys and save fields it touches, and the traps. Read the notebook before you change a system, and update it in the
same commit when its behaviour changes. AGENTS.md (repo root) has the workflow rules. docs/known-issues.md lists bugs
and dead code found while writing these, not yet fixed.

## The systems

| System | Notebook | Global(s) | Files |
| --- | --- | --- | --- |
| Game shell | [notebook-core.md](notebook-core.md) | `G`, `U`, `Input`, `Settings`, `Save`, `Title` | main.js, util.js, input.js, settings.js, save.js, title.js, index.html |
| Data and tuning | [notebook-config.md](notebook-config.md) | `CFG`, `BULLETS`, `RECIPES`, `BOSSES`, `BUGS`, `BIOMES`, ... `TUNING`, `BESTIARY` | config.js, tuning.js, bestiary.js; editor/config, editor/bestiary, editor/range |
| Overworld | [notebook-world.md](notebook-world.md) | `World` | world.js, world-terrain.js, world-summons.js, world-discover.js, world-swarm.js; render-world.js, render-circus.js, render-farm.js, render-jungle.js, render-graveyard.js |
| Loot and pickups | [notebook-world-loot.md](notebook-world-loot.md) | `World` | world-loot.js; pickups in world.js, player.js, render-world.js |
| The centipede | [notebook-player.md](notebook-player.md) | `Player`, `Minions` | player.js, player-build.js, player-loadout.js, player-hurt.js, player-fire.js, player-gear.js, minions.js, render-player.js |
| Workbench | [notebook-workbench.md](notebook-workbench.md) | `Build`, `BenchFx`, `Gallery`, `Icons` | workbench.js, benchfx.js, gallery.js, icons.js; render-bench.js, render-bench-body.js, render-bench-perks.js, render-bench-forge.js, render-bench-lab.js |
| Combat | [notebook-combat.md](notebook-combat.md) | `Combat` | combat.js, render-fx.js |
| Monsters | [notebook-enemies.md](notebook-enemies.md) | `Enemies` | enemies.js, enemies-snakes.js, enemies-bugs.js, enemies-uniques.js, enemies-parasites.js, enemies-spells.js, render-enemies.js |
| Bosses | [notebook-bosses.md](notebook-bosses.md) | `Enemies` | enemies-bosses.js, enemies-crab.js, enemies-skull.js, enemies-serpent.js, enemies-overlord.js, enemies-clowncar.js, render-bosses.js |
| Dungeons | [notebook-dungeon.md](notebook-dungeon.md) | `Dungeon`, `Deck` | dungeon.js, deck.js, render-dungeon.js |
| Perks and megas | [notebook-perks.md](notebook-perks.md) | `Perks`, `PERKS`, `Mega`, `MEGAS` | perks.js, render-perkshop.js |
| Drawing | [notebook-render.md](notebook-render.md) | `Render` | render.js, render-hud.js, render-cards.js, render-screens.js (the other render-*.js files belong to their systems above) |
| Sound and music | [notebook-audio.md](notebook-audio.md) | `SFX`, `SFX_DATA`, `Music` | util.js (SFX), sfx.js, music.js, music-themes.js; editor/sfx, editor/tracker |
| Tools | [notebook-tools.md](notebook-tools.md) | `Bot` | tools/devserver.py, tools/deploy.sh, tools/check-comments.py, tools/readme-recipes.js, bot.js, desktop/ |

## How a frame runs

`G.update(dt)` (main.js) is the one entry point per frame. It runs the autopilot first (`Bot.update`, which may press
keys), then the screen that owns the moment, by `G.state`:

- **title**: `Title`.
- **settings**: `Settings`.
- **perk**: `Perks.update`, the shop.
- **discover**: `Settings.updateDiscover`, the cards and tips.
- **paused**: `Build`, `BenchFx` and `Gallery`, the workbench.
- **playing**: `Mega`, then `Player`, then by `G.mode`:
  - world: `World.update`, then `Enemies.update`.
  - deck: `Deck.update`.
  - dungeon: `Dungeon.update`.

  `Combat.update` runs after that in every mode.

`Render.frame()` (render.js) then draws:

1. Early returns: the title, the workbench, and a cinematic each draw on their own and stop there.
2. Otherwise the world-space pass: the world or the maze, pickups, enemies and bosses (dispatched by `e.kind`), the
   player and the effects.
3. The vignette.
4. `Render.drawHUD`, which also draws the overlay for the current state: cards, the perk shop, the won screen, game
   over.

## How the systems talk

These are the edges an agent is most likely to trip over. The notebooks list the rest.

**Shooting and killing**
- `Player.fireFrom` calls `Combat.fire`, which creates the projectile.
- `Combat.applyHit` applies the multipliers, elements and statuses, and reads `BESTIARY.resist` through
  `Combat.resist`.
- Then `Combat.damage`, then `Combat.kill`, then the monster's `onDeath`, which rolls `World.loot`. Pickups land in
  `World.pickups`, and `Player.collect` takes them.
- `Combat.kill` also charges the mega (`Mega.charge`) and plays `SFX.death`. A boss's death calls `G.winLevel`.

**The build**
- The body is `Player.segments`; each segment is a bullet id from `BULLETS`.
- `Player.resolveBuild` works out the piping, the synergy runs and the jams. Combat reads the results (`seg.pipe`,
  `Player.syn`).
- `Build` (the workbench) edits the body, the bag and the blueprint, and fuses through `RECIPES` (`Player.craft`).
- The renderer records hit rects in `Build.hit` every frame, and `Build` reads them for taps and keyboard navigation.

**The world and its monsters**
- `World.genRegion` spawns monsters through `Enemies.spawn*` and places a boss arena every `CFG.BOSS_EVERY` regions.
- Monsters read `World.levelOf` and `World.regions`.
- An entrance calls `G.enterDungeon`. That leads to `Dungeon.enter`, or to `Deck.enter` on the farm when
  `CFG.DECK_MODE` is on. A successful exit opens the perk shop (`Perks.offer`) and saves.

**Perks**
- `G.mods` holds the perks' effects and is read across Player, World and Combat (`Perks.summonLaps`,
  `magnetRadius`, `twinMult`, ...).

**Saving**
- `Save.write` runs at each new region, dungeon exit, perk shop close, workbench close and win.
- `Save.resume` rebuilds the world from the level and region, then restores the run onto `G`, `Player` and the mega
  deck. The world itself is not saved.

**Music**
- World, Dungeon, Deck, the bosses, Perks and `G` start and stop themes by name (`Music.start`, `levelTheme`,
  `bossTheme`, `fanfare`, `victory`). Combat stops a boss's theme when the boss dies.
- notebook-audio.md lists every theme name the game looks for.

**Hit rects written back into `G`**
- Renderers write rects for buttons into `G`: `titleBtns`, `craftBtn`, `discoverBox`, `perkLeave`, `hudBottom`, and
  more.
- `Input` reads them back on a tap, so moving a button means changing both the drawer and its reader.

**The autopilot**
- `Bot` reads nearly everything and changes things only by pressing keys. A change in controls or layout can break
  it, so run a soak.

## Load order

index.html loads the scripts in this order: util, the music engine and themes, then the data (config, tuning,
bestiary, sfx), then input, combat, world, player, workbench, enemies, minions, dungeon, deck, perks, gallery,
settings, save, bot, icons, render (core first, then the render-* files), benchfx, title, and main last.

Most code runs only when called. Only the top level of each file runs at load, so a file may call into a later one
from inside a function, never at load. editor/range/index.html loads the same list in the same order: keep the two in
step.

## Writing a notebook

Use the template every notebook follows:

- a one-paragraph purpose and a **Files** table
- **Model**
- **How it works**
- **Interop** (other systems, CFG keys, save fields)
- **Invariants and gotchas**
- **Tuning**
- **Testing**
- **Change log hooks**

Other rules:

- Name functions as `Object.fn`, never by line number.
- Write down what the code does not make obvious: intent, contracts between systems, traps. Don't retell the code.
- Check claims against the code. Mark anything you could not check "(unverified)".
- A new system gets a notebook and a row in the table above.
