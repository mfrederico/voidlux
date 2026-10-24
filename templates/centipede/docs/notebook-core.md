# Core notebook

The game shell. It boots the page, owns the global game state `G`, runs the one `requestAnimationFrame` loop, and moves the game between its screens: title, play, workbench, perk shop, cards, win, game over. It also holds the small shared helpers (`U`), raw input (`Input`, `TOUCH`), the settings page and the one-time tip cards (`Settings`), the run save (`Save`) and the title's attract mode (`Title`). In code terms: every other system is a global object that `G.update` ticks and `Render.frame` draws. This shell decides which of them run each frame.

**Files:**

| file | what it holds |
|---|---|
| index.html | the canvas (`#game`, 1280x720 logical) and every `<script>` in load order; page CSS (edge-to-edge on `body.mobile` / `body.desktop`) |
| js/main.js | `G`: state, the state machine (`G.update`), messages (`G.msg`), run start / level win / ascend / nightmare / game over, camera, god mode and dev warps, and the `boot()` IIFE with `fit()` and the main loop |
| js/util.js | `TAU`, `U` (random, clamp, lerp, distance, angle helpers, `damp`), then `SFX` (see notebook-audio.md) |
| js/input.js | `TOUCH` (the touch button layout) and `Input` (keyboard state, touch and mouse routing per state, joystick, maze swipes, title taps) |
| js/settings.js | `Settings`: the settings page (`ITEMS`), its persistence, `Settings.seen`, and the pause-the-game cards (discovery, craft tip, perk tip, swarm, dungeon get-ready, dungeon leave) |
| js/save.js | `Save`: write / resume / clear the run in localStorage |
| js/title.js | `Title`: the attract-mode centipede on the title screen (pure show, touches nothing live) |

## Model

**`G` (js/main.js)** is one plain object, created at load with defaults. `G.start` resets nearly every run field. Key groups:
- Flow: `state` (see below), `mode` (`'world'` | `'dungeon'` | `'deck'`), `cinematic` (the ascend climb, `{t, total, region}`), `won` (the win-screen tally), `time` (seconds since boot, never reset; drives every animation).
- Run numbers: `score`, `kills`, `powerMult`, `dungeonsCleared`, `maxLen`, `regionMax`, `pity`, `level`, `nightmare`, `ghosts`, `dots`, `best` (from localStorage).
- Build: `mods` (from `Perks.defaults()`), `perks`, `perkChoices`, `perkSel`, `perkFree`, `bought`, `mega` `{id, owned, charge, used}`.
- Timed effects that `G.fx` ticks down: `msgs`, `banner`, `flash`, `tint`, `ring`, `splats`. Also `shake`, `freeze` + `pendingBlast` (Sacrifice), `storm`, `deepFreeze`, `slowmo`, `bolt`, `lich`, `hive`, `swarm*`.
- Camera: `cam {x, y}` in world pixels.
- `enemies` is an alias of `Enemies.list`. It is re-pointed after `Enemies.reset()` in `G.start` and `G.enterNightmare`.
- Hit rects written by the renderers each frame and read back by `Input`: `titleBtns`, `titleWord`, `newRunBox`, `loadRunBox`, `discoverBox`, `discoverBench`, `craftBtn`, `perkLeave`, `hudBottom`.
- Dev: `god`, `godMortal`, `godArmed`, `deckArmed`, `godTaps`, `prof` (the profiler numbers), `lastError`.

**`G.state` values and who sets them:**
- `'title'`: at boot; after the nightmare finale (`G.update`, won + demoEnd); `Bot` trials between runs.
- `'playing'`: `G.start`, `G.enterNightmare`, leaving `won`, closing settings / discover / perk / workbench.
- `'paused'`: the workbench. C or P in `G.update` (calls `Build.open`), a discover card's workbench button, the desktop window losing focus.
- `'settings'`: `Settings.open(from)`, only from the workbench (O). `Settings.close` returns to `from`.
- `'discover'`: the pause cards. `Settings.discover`, `explainCraft`, `perkTip`, `swarmCard`, `dungeonCard`, and `Dungeon` (the leave card, `G.discover.tip === 'leave'`).
- `'perk'`: `Perks.offer` (after a dungeon, or J in god mode); `Perks` sets it back to `'playing'`.
- `'won'`: `G.winLevel`, called by the boss death in enemies-bosses.js. `G.won.demoEnd` is true after level 5 (the Clown Car).
- `'gameover'`: `G.gameOver`, called from player-hurt.js. R restarts with `G.start`. There is no way back to the title from here but a reload.
- `G.cinematic` is not a state but acts like one: while set, `G.update` only counts it down, then drops the player into the boss region.

**`G.mode`** is set by `Dungeon.enter/exit` and `Deck.enter/exit` (see notebook-dungeon.md). `G.enterDungeon` picks the deck for farm regions when `CFG.DECK_MODE`.

**Other state:** `Input.keys` (held), `Input.pressed` (pressed this frame), `Input.joy`, `Input.swipes`, `Input.pts` / `Input.pinch` (workbench). `Settings.data` (persisted options), `Settings.seen` (bullet ids ever held). `Save.data` (the parsed save, or null). `Title.segs/enemies/shots/parts`.

## How it works

**Boot** (`boot()` at the bottom of main.js, runs as soon as main.js loads): `Render.init`, `Input.init`, `Bot.init` (reads URL params), `Player.tuneFusions`, then `Settings.load`, `Save.load`, best score. `fit()` sets the logical width: `CFG.H` is fixed at 720 and `CFG.W` follows the window's aspect ratio, clamped to `CFG.W_MIN..W_MAX` and kept even. A width change re-inits the canvas and calls `Dungeon.center`. Every call re-places the touch buttons (`TOUCH.place`) and resizes the deck. It re-runs on resize, orientation and fullscreen events (and again 300 ms later).

**The loop** (`loop` in boot): `dt` is capped at 1/30 s and scaled by `CFG.SLOWMO.scale` while `G.slowmo > 0`. It calls `G.update(dt)` then `Render.frame()`, then `Input.endFrame()`. In `?trials` mode it runs `Bot.trials.fast` fixed 1/60 steps per drawn frame, and a MessageChannel pump keeps stepping when the browser throttles frames. Any exception is caught, logged, stored in `G.lastError` and printed on the canvas: the loop never dies.

**`G.update(dt)` order** (each branch returns early, so order is priority):
1. `Bot.update` first, so the autopilot's key presses are seen this frame.
2. Title: start the title theme once audio runs, `Title.update`, `G.titleNav` (WASD walks a 3-column button grid), Enter presses the selected button, Space plays or continues, N starts a new run over a save. On the desktop build ESC quits.
3. M mutes (saves `Settings.data.sound`); G toggles god mode (not on the workbench); god-mode dev keys (below).
4. `gameover`, `won` (demo end: Enter goes into nightmare, or after nightmare to the title), `cinematic`, `settings`, `discover`, `perk`: each ticks only its own screen plus `Combat.updateFx` and/or `G.fx`.
5. C / P toggles the workbench (`Build.open` / `Build.close`). While paused, `Build.update`, `BenchFx.update` and `Gallery.update` run.
6. E fires the mega (`Mega.use`). `G.freeze` (Sacrifice) holds the world, then `Mega.blast(G.pendingBlast)`.
7. `Mega.update`, `Player.update`. In the world, nothing else moves until `Player.ready` (the "press a direction" hold after a resume, nightmare or dungeon exit).
8. By mode: `World.update` + `Enemies.update`, or `Deck.update`, or `Dungeon.update`. Then `Combat.update`, `G.fx`.
9. Camera: in the world it glides toward `G.camTarget` (player plus a lead along the heading, sized by `Render.viewW/viewH`) with `U.damp(5, dt)`, then `G.clampCam`. In a dungeon or the deck it is pinned to 0,0. Shake adds jitter scaled by `Settings.data.shake`.

**Run lifecycle:**
- `G.start`: reset every run field, `Combat.reset`, `Enemies.reset`, `Minions.reset`, `World.reset`, `Player.reset(300, WORLD_H/2)`, start the level-1 theme. Marks `CFG.START_SEGMENTS` and `CFG.HEAD_SPIT` as seen. Applies `godArmed` / `deckArmed` on the next tick.
- `G.ascend(R)`: called by `World` after six walls; sets `G.cinematic` for 3.2 s. When it ends, `G.update` places the player at the boss region's left edge.
- `G.winLevel(level)`: tally into `G.won`, optionally `Player.shrinkTo(CFG.WIN_RESET)`, `level + 1`, fill the mega bar, play `Music.fanfare`. Enter goes back to `'playing'` and saves.
- `G.enterNightmare`: after the level-5 win; keeps the build, resets the world to level 1 with `G.nightmare = 1`, saves.
- `G.gameOver`: state `'gameover'`, `Save.clear()`, update `centipede_best`.
- `G.msg(text, color, level)`: the left-hand commentary. The level comes from `MSG_RULES` regexes, else from the colour (red = 3 alert, grey = 1 chatter, else 2). It is dropped below `Settings.data.msgLevel`. A repeat bumps a ×N count. At most 6 lines.
- `G.hitSolid(p)`: does a projectile hit terrain? It dispatches by mode to `Dungeon.isWallAt`, `Deck.isSolid` or the hedge / `World.wallAt` (damaging walls for player shots) / `World.solidAt`.

**Input:** keyboard sets `keys` and `pressed`. `Input.once(code)` returns true once per press and consumes it. `Input.down(code)` is the held state. `Input.axis()` is WASD/arrows, else the joystick quantised to 8 directions, else `Bot.axis`. Touch: `onStart` routes each new touch by `G.state` (title tap, R on game over, Enter on won, shop tap, settings scroll, discover key, workbench pointer and pinch, maze swipe zone, deck steering, or the floating joystick on the left 55%). Buttons in `TOUCH.buttons` press their key codes. Taps are turned into fake key presses with `Input.tapKey`, so the game logic only reads keys. Mouse clicks do the same for title, shop, settings, discover, the CRAFT pill and the desk MEGA bar. F toggles fullscreen anywhere.

**Settings:** `Settings.ITEMS` is the page. `change` cycles a value and saves. The `size` item shows only on touch screens. The cards all set `G.discover = {tip, ...}` and `G.state = 'discover'`. `Settings.updateDiscover` handles their keys (C toggles "show next time", P goes to the workbench, Enter carries on; the leave card picks EXIT or STAY and calls `Dungeon.exit(false)` or `Dungeon.stay`). Each one-time tip sets its flag in `Settings.data` (`craftTip`, `perkTip`) so it shows once per browser. `Settings.howItFires(B)` builds the sentence used on the cards and in the workbench inspector.

**Save:** `Save.write(manual)` builds a `v: 1` object: nightmare, level, region (`Save.region()`: the region you are in; inside a maze or on the deck the entrance's; in a beaten arena the next one), score, kills, powerMult, dungeonsCleared, maxLen, pity, mods, perks, bought, ghosts, dots, mega, segments (minions left out), bag, blueprint, locked (`Player.autoRebuild`), maxSegs, head, gear, gears, spitting, and `shop` when the perk shop is open. It refuses on the title, on game over, when dead, and in god mode. `Save.resume` calls `G.start`, generates a fresh world up to the saved region, restores the fields, drops the player at the region's left edge with `Player.ready = false`, and sets `regionMax = ri - 1` so the region intro fires again. Migration is by hand inside `resume`: missing fields get defaults, unknown bullet ids are filtered out (`Save.known` first remakes a saved Ricochet, `<id>_ricN`), old mega / gear shapes are converted. A save with `v !== 1` is thrown away on load.

**Title:** `Title.update` runs a scripted centipede with a random loadout (re-rolled every 16 s) firing at bugs that drift along the edges. `Title.draw` is called by `Render.drawTitle`. It only reads `BULLETS` and `BUGS`.

## Interop

- `G` is read everywhere. Callers of its methods: `World` calls `G.ascend`; enemies-bosses.js calls `G.winLevel`; player-hurt.js calls `G.gameOver`; player.js calls `G.enterDungeon`; combat.js calls `G.hitSolid`; dungeon.js, deck.js and save.js call `G.snapCam`; nearly every system calls `G.msg`.
- `G.update` calls: `Bot.update`, `Title.update`, `Mega.update/use/blast`, `Player.update`, `World.update`, `Enemies.update`, `Deck.update`, `Dungeon.update`, `Combat.update/updateFx`, `Perks.update`, `Settings.update/updateDiscover`, `Build.open/close/update`, `BenchFx.update`, `Gallery.update`, `Music.*`.
- Dev warps call `World.ensure`, `World.regions`, `Minions.recall/hatch`, `Player.initTrail/computeSegPos/resolveBuild/lock/mkSeg`, `PERKS[id].apply`, `GOD_PRESETS` (perks.js), `World.wakeRock`, `World.singTree`, `Combat.damage`, `Perks.offer`.
- `Input` calls `Build.pointerDown/Move/Up`, `Settings.tap`, `Perks.spinning/finishReel`, `Bot.toggleGuide`, `Save.resume`, `G.titleSound`, `Dungeon.cell`, `Render.ui/zoom/deskMega`. It is read by `Dungeon.movePlayer` (`swipeDir`, `mazeDir`) and `Deck` (`deckTouches`).
- `Settings` is read by `Render` (`size`, `from`, `view`, `hit`), `SFX.init` (volume), `SFX.panOf` (stereo), `Bot` (guide), `G.msg` (msgLevel). `Settings.discover` is called by player.js on pickup; `explainCraft` by bot.js; `perkTip` by perks.js; `swarmCard` by player.js; `dungeonCard` by dungeon.js and deck.js.
- `Save.write` is called at a new region (world.js), a dungeon exit (dungeon.js), a deck exit (deck.js), the perk shop closing (perks.js), the win screen continue, nightmare entry, and the settings Save item.
- Other notebooks: notebook-render.md (frame and hit rects), notebook-audio.md (SFX, Music), notebook-workbench.md (Build), notebook-perks.md (Perks, Mega), notebook-world.md, notebook-player.md, notebook-dungeon.md, notebook-combat.md, notebook-config.md, notebook-tools.md (Bot, editors).
- **CFG keys read here:** `W`, `H`, `W_MIN`, `W_MAX`, `WORLD_H`, `REGION_W`, `HEDGE`, `BOSS_EVERY`, `START_SEGMENTS`, `HEAD_SPIT`, `WIN_RESET`, `MEGA_MAX`, `DECK_MODE`, `SLOWMO.scale`, `DUNGEON.LEASH/SWIPE/TURN_BIAS/STICK_MIN/STICK_DEAD` (input.js).
- **localStorage keys:** `centipede.run` (Save), `centipede.settings` and `centipede.seen` (Settings), `centipede_best` (best score), `centipede.nightmareCleared` (best nightmare score, shown on the title), `centipede.trials` (Bot).

## Invariants and gotchas

- **Load order is the dependency graph.** There are no modules. A file may use another global at load time only if that file is earlier in index.html. `util.js` is first (`U`, `TAU`, `SFX`). `tuning.js` must follow `config.js` because it rewrites `CFG` the moment it loads. Every `render-*.js` must follow `render.js` (they `Object.assign(Render, ...)`). `main.js` must be last: its `boot()` runs immediately and touches everything. A new file goes in index.html (and in the editors that load game files, see notebook-tools.md).
- `Input.pressed` is cleared at the end of every frame. A key nobody reads that frame is lost. `Input.once` consumes: if two places read the same key in one frame, only the first sees it. Dev keys share codes with play keys (O, J, K...), so they only run in god mode, in the world, while playing.
- Touch and mouse never call game logic directly for most screens: they call `Input.tapKey(code)`. To add a tappable thing, have the renderer record its rect on `G` and have `Input` map it to a key.
- `Input.tapShop` repeats the perk-card layout numbers (w 260, h 350/250, gap 24, y0 250) from `Render.drawPerks`. Change both together.
- `G.update` returns early for overlay states, so `G.fx` (message timers) does not tick in `settings`, `perk` or `paused`. Messages wait.
- `G.enemies` must be re-pointed after `Enemies.reset()` (it replaces the array).
- `Save.write` is silent in god mode, so god-mode play never overwrites a real save. A continue does not restore the world: it is regenerated, and bosses before the saved region are marked beaten by `G.skipBossesBefore`.
- `Settings.load` merges saved data over defaults, so a new setting needs only a default in `Settings.data`.
- `Settings.close` from `'settings'` falls back to `'playing'`. The page is only opened from the workbench today.
- `G.time` never resets; renderers use it for every wobble and pulse.
- Never splice a `//` comment into the middle of a long one-line statement: everything after it on that line silently stops running. It happened in seven places at once (`Save.resume` lost the body, `Build.close` stopped saving, and more; fixed in 2af20dc). `python3 tools/check-comments.py` finds it and is part of the deploy gate.

## Tuning

Canvas size limits are `CFG.W_MIN` / `CFG.W_MAX` in js/config.js. Run-start values are `CFG.START_SEGMENTS`, `CFG.HEAD_SPIT`, `CFG.WIN_RESET`, `CFG.MEGA_MAX`. Overrides go in js/tuning.js through the config editor (http://localhost:8765/editor/config/). Touch layout numbers are in `TOUCH` (input.js); phone sizes are set in `Input.init`. Settings choices and defaults are `Settings.ITEMS` and `Settings.data`. Message levels are `G.MSG_RULES`. God presets are `GOD_PRESETS` in perks.js.

## Testing

- Dev server: `python3 tools/devserver.py`, then http://localhost:8765/ (caching off).
- URL params (parsed in `Bot.init`): `?god=1` arms god mode for the first run; `?fps=1` shows the profiler (\` toggles it in game, `G.showProf`); `?deck=1` warps to level 4 and boards the saucer deck; `?bot=1` turns on the autopilot, `&brain=scripted` keeps it offline; `?guide=1`; `?trials=N&fast=20&cap=5400` runs N silent autopilot runs and logs a summary.
- On the title, five quick taps on the word CENTIPEDE arm god mode (for phones).
- God mode (G): invulnerable, profiler panel. N next region, B / Y / , / 0 / Q (or ') warp to bosses 1-5, L / O / X / . warp to levels 2-5, ; nightmare, H nearest dungeon, J perk shop, K wake a boulder, I sing a jungle tree, U boss -19%, Z hatch a minion, 1-9 presets, V mortal.
- Console: `G.state`, `G.mode`, `G.prof`, `G.lastError`, `Save.data`, `Settings.data`, `G.warpLevel(3)`, `G.applyPreset(0)`, `Save.write(true)`, `localStorage.removeItem('centipede.seen')` to see the discovery cards again.

## Change log hooks

Update this notebook in the same commit as any change to the system's behaviour.
