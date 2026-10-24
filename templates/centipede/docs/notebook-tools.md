# Tools notebook

Everything around the game that is not the game: the local dev server that serves it and lets the editors save into
the repo, the deploy script that publishes the web build, the README recipe generator, the in-game autopilot / guide /
balance-trial harness (`js/bot.js`, which does ship with the game), and the Electron wrapper for a Steam desktop build.

**Files:**

| file | what it holds |
| --- | --- |
| `tools/devserver.py` | Local HTTP server (port 8765 by default), caching off, plus the editors' save API |
| `tools/deploy.sh` | Publishes the web build to clicksimple (beta or stable channel) |
| `tools/readme-recipes.js` | Node script: regenerates the README recipe table from RECIPES / BULLETS |
| `js/bot.js` | `GOALS` and `Bot`: the autopilot (reflex + LLM strategist), the guide, what-if DPS, balance trials |
| `editor/` | The editors (bestiary, config, sfx, range, tracker); see notebook-config.md and notebook-audio.md |
| `desktop/main.js`, `desktop/preload.js` | Electron main process and the `window.desktop` bridge |
| `desktop/package.json` | Electron / electron-builder setup; which game files ride along |
| `desktop/linux/centipede.sh`, `desktop/steam/*` | Linux launcher (`--no-sandbox`), SteamPipe templates and `upload.sh` |
| `desktop/STEAM-HOWTO.md` | The full Steam walkthrough |

## Model — the state it owns, and its lifecycle

- **devserver.py** is stateless. It serves the repo root (`SimpleHTTPRequestHandler`, `Cache-Control: no-store`) and
  rewrites game files on POST. It binds all interfaces, so a phone on the LAN can load the game and editors.
- **deploy.sh** builds in a temp dir and mirrors it to the server. The only thing it adds is `js/version.js`
  (`const BUILD = { id, date, channel }`), which exists only in deployed builds.
- **Bot** is a global object in the game page. State: `on` (autopilot), `guide` (advice only), `allowAuto`,
  `scriptedOnly`, `offline`/`retryT`, the current `goal`/`goalT`/`why`/`think`/`danger`, the reflex `axis`, steering
  memory (`orbit`, `weave`, `detour`, `stuckT`, `sideFor`), `runs` and `trials`. `Bot.init` reads URL params at boot.
- **desktop/** keeps no game state. Saves are browser storage in Electron's user-data folder.

## How it works — the main flows, named by function

### tools/devserver.py — endpoints

- `GET /api/bestiary` — js/bestiary.js as JSON (`read_bestiary`: parses everything after `const BESTIARY = `).
- `POST /api/bestiary` — `{ resist, stats }`. `save_bestiary` checks row ids (`[a-z]+-[a-z0-9]+`), resist values
  (0..5 or null, rounded to 2 places), notes (300 chars), stats against `STATS` limits; rewrites the whole file with
  `BESTIARY_HEAD`, one row per line, sorted.
- `GET /api/tuning` / `POST /api/tuning` — `{ "<CFG path>": number|boolean }`. Paths must match
  `[A-Z][A-Z0-9_]*(\.[A-Za-z0-9_]+){0,4}`; numbers rounded to 4 places. `save_tuning` rewrites js/tuning.js from
  `TUNING_HEAD` + sorted rows + `TUNING_TAIL` (which holds the `applyTuning` code).
- `GET /api/sfx` / `POST /api/sfx` — `{ "<sound key>": { jitter, layers: [...] } }`. `save_sfx` validates the key,
  1..64 layers, the wave, clamps each field to `LAYER` ranges, keeps `crush`, `m` (muted) and `c` (editor colour); keeps
  the file's head above `const SFX_DATA = ` and rewrites the data (notebook-audio.md).
- `POST /api/music/save` — `{ name, snippet }`. `save_song` replaces the block that starts with a
  `// made in the tracker` line and `<name>: { tracker: true,` and ends at `    ] },`, or inserts it above the
  `// ---- tracker songs below` marker inside `Music.THEMES` in js/music-themes.js.
- `POST /api/music/delete` — `{ name }`. `delete_song` removes only a tracker block; hand-written themes are refused.
- Writes go through `write_atomic` (`.tmp` then `os.replace`). Only the music endpoints first copy the old file to
  `js/music-themes.js.bak` (`*.bak` is git-ignored). Bestiary, tuning and sfx saves keep no backup: git is the backup.
- **Writes are localhost only.** Any POST from an address outside `LOCAL` (127.0.0.1, ::1, ::ffff:127.0.0.1) gets 403;
  a phone over `adb reverse` arrives as localhost and can save. Others can still read everything.
- Errors: ValueError gives 400 with the message (the editors show it), anything else 500. Request logging is off.

### tools/deploy.sh

- `tools/deploy.sh beta` publishes to `https://clicksimple.com/centipede-beta/`, `stable` to `/centipede/`. Remote:
  `cannonwms.com:/home/ubuntu/default/clicksimple/public/<dir>/`.
- Publishes only `index.html manifest.webmanifest js icons` (not editor/, tools/, desktop/, docs/, README).
- **Refuses** if `git status --porcelain` shows changes in those paths, unless the second argument is `--force`; then the
  build id is `<short sha>-dirty` and the working copy is copied. Otherwise it uses `git archive HEAD`, so only
  committed content ships.
- Build stamping: writes `js/version.js`, inserts its `<script>` before `js/util.js` in index.html, and appends
  `?v=<id>` to every `js/<name>.js` script tag (the pattern `[a-z-]*` covers names like `music-themes`). The title screen
  shows the build (`render-screens.js`, `typeof BUILD` check; "dev build" when absent).
- `rsync -az --delete` mirrors: files on the server that are not in the build are removed.
- **Never rsync the repo to the web folder by hand.** That ships editors and tools, skips stamping and `?v=` cache-busting,
  and the next deploy deletes the extras. (STEAM-HOWTO.md section 7 still shows such an rsync; do not follow it.)

### tools/readme-recipes.js

- `node tools/readme-recipes.js`. Evals js/config.js with `const` turned into `var` and stubs for `World.levelOf` and `G`,
  builds one row per RECIPES entry (fuse, result, tier, dmg, a kind summary, desc), and replaces the README block that
  starts with `  | Fuse | Result | Tier` up to the next blank line. Damage is the raw config value (no `tuneFusions`).

### js/bot.js — autopilot and guide

- **URL params** (`Bot.init`): `?bot=1` autopilot on and allowed; `?guide=1` guide on; `?brain=scripted` never ask the
  model (A/B baseline); `?model=` (default `tev1:0.8b`); `?host=` (default `http://localhost:11434`); `?deck=1` sets
  `G.deckArmed` (start on the saucer deck); `?god=1` sets `G.godArmed`; `?trials=N` with `&fast=` (updates per frame,
  default 20) and `&cap=` (play-seconds cap per run, default 5400).
- **T** (`Bot.cycle`): without `allowAuto` it only toggles the guide (or turns a running autopilot off); with it, off →
  guide → autopilot → off. The guide is also a touch button (input.js) and a setting (`Settings.data.guide`).
- **Per frame**: `G.update` calls `Bot.update(dt)` before anything reads input. It presses keys through `Bot.tap`
  (`Input.tapKey`, autopilot only) and sets `Bot.axis`, which `Input` uses only when the player presses no direction.
  Title, gameover, discover and won screens are clicked through on timers; the perk shop goes to `perk` / `guidePerk`;
  in play it runs `world`, `deck` or `dungeon` by `G.mode`. Every 3 s `tidy` (autopilot) or `guideCraft` (guide).
- **Reflex layer**: `sense` gathers enemies (900 px), reachable pickups, the wall's weakest or breached section,
  graves and singing trees. `world` applies overrides (boss over flee, a dungeon on the way, grab close pickups, no
  segments → loot or flee), picks a target with `targetFor`, routes it through a breach (`viaBreach`, `breachWaypoint`),
  then `steer`: kite at `keepRange`, repulsion from enemies, boss hazards (beam, abduction, stomp, car run, mime wall),
  stones, enemy clouds, projectile sidestep, solids and lakes, hedge and wall pushes, a stuck detour, and tangent steering
  round obstacles. `dungeon` / `dungeonStep` BFS the maze avoiding cells near hunting ghosts (eat frightened ghosts,
  pellets, Siphon, Shovel, dots, keep away) and hold fire while ghosts are edible. `deck` lines up under the cow, falling
  pickups or the lowest invader, and dodges rays. A scripted mega (`KeyE`) fires on a boss, a crowd or a last stand.
- **Strategist**: `maybeAsk` fires on events (start, new region, lost a segment, boss, loot nearby, a new affordable
  fusion, every `every` = 7 s). `ask` POSTs one typed request to `<host>/v1/systemone` (Ollama-side endpoint): `goal`
  (choice among `available(S)`), `danger` (score), `craft` (choice among affordable fusions, each labelled with its DPS
  change), `mega` (yes/no). `apply` sets the goal, crafts, or fires the mega. The perk shop sends its own `perk` question.
  On any error it goes `offline` with `retryT = 15` and uses `scripted(S)` / `scriptedPerk` (a fixed perk order).
- **What-if DPS**: `whatIf(fn)` snapshots segments, bag, maxSegs, maxLen, dots, powerMult, mods, lastCraft, lockHint;
  runs the choice; fills free slots from the bag like `tidy`; measures `Player.estimateDps(1, 1)`; restores and calls
  `resolveBuild`. Used by `fuseDps` (`Player.craft(A, B, true)`) and `perkDps` (Bigger Body, or `PERKS[id].apply()` for
  a few stat perks).
- **Trials**: `?trials=N` forces the autopilot, turns off discover cards and the guide, mutes, clears the save. main.js
  runs `fast` updates per frame, plus a MessageChannel pump that keeps stepping when the tab is hidden. A run ends on
  death, on clearing the nightmare Clown Car, or at the cap; `logRun` records it. After N runs `trialSummary` goes to the
  console, to the title screen, and to localStorage `centipede.trials` and `centipede.trials.<model>`.
- **Drawing**: `Bot.draw` (world space, from render.js) draws the target line or an edge arrow, or the next dungeon
  step; `Bot.drawHUD` (from render-hud.js) the AUTOPILOT or GUIDE banner.

### desktop/ (Electron, Steam)

- `main.js` opens one full-screen, menu-less window on `index.html`: from the repo one folder up in development
  (`npm start`), from `resources/game` when packaged (electron-builder `extraResources` copies index.html, the
  manifest, `js/**`, `icons/**`). F11 toggles fullscreen; Ctrl+Shift+I devtools in development only. Window blur sends
  `desktop:blur`.
- `preload.js` exposes `window.desktop` = `{ quit, fullscreen, onBlur }`. The game checks `window.desktop`: QUIT button
  and ESC on the title, window fullscreen, pause to the workbench on blur (not while `Bot.on`), a `desktop` body class.
- `CENTIPEDE_SMOKE=<png>`: headless self-test (off-screen window, 300 updates, a save, a screenshot, a `SMOKE {json}`
  line). `CENTIPEDE_EVAL=<file.js>` stages a custom scene instead.
- Build: `npm run dist:linux|dist:win|dist:mac` (dir targets). `steam/upload.sh <login> <app> <linux depot> <win depot>`
  builds both and runs SteamCMD; the build still has to be set live in Steamworks. Linux launches through
  `centipede.sh` with `--no-sandbox` (Steam's runtime cannot provide Chromium's setuid sandbox).
- Not done yet (per the HOWTO): Steamworks integration, controller support, the Mac build, and deciding whether god
  mode / the autopilot ship in a release.

## Interop — reads from / is called by / calls into other systems

- devserver.py writes files owned by notebook-config.md (tuning.js, bestiary.js) and notebook-audio.md (sfx.js,
  music-themes.js). It hard-codes the four element names and the tuning.js tail.
- Bot is called by main.js (`Bot.init` at boot, `Bot.update` from `G.update`, `Bot.trials` in the loop), input.js
  (`Bot.axis`, the guide button), render.js (`Bot.draw`), render-hud.js (`Bot.drawHUD`, trials line), render-screens.js
  (trial summary), settings.js (guide setting), dungeon.js (`Bot.on`: no digging, the exit does not ask).
- Bot reads: Player (segments, bag, `fusions`, `craft`, `estimateDps`, `fromBag`, grid position), World (regions,
  walls, pickups, trees, graves, lakes, entrances), Dungeon, Deck, Combat (projectiles, clouds), Mega and MEGAS, PERKS
  and Perks, BULLETS, BUGS, BOSSES, PARASITES, Minions, Settings, Save, Music, SFX.
- CFG keys read by Bot: HEAD_R, HEAD_SPIT, WORLD_H, HEDGE, MEGA_MAX, GRAVE.range/charm, SONG.range/time.
- Saves and storage: Bot writes `Settings.data.guide` (and `discover` in trials), calls `Save.clear()` in trials, and
  writes localStorage `centipede.trials*`. deploy.sh adds the `BUILD` global.

## Invariants and gotchas

- **editor/range/index.html loads the game's scripts in the same order as index.html.** Add, rename or split a js file
  and update both lists in the same commit (the split commit 2c6c552 had to fix the range's missing biome files).
  `deploy.sh` and `desktop/package.json` take all of `js/`, so they need no change, but deploy's `?v=` pattern only
  matches lowercase-and-dash file names.
- Editor-written files (tuning.js, bestiary.js, sfx.js, tracker blocks in music-themes.js) are regenerated whole; keep
  their format or edit through the editor (notebook-config.md).
- devserver.py reads tuning.js up to the first `};` in the file; do not put `};` before the TUNING object ends.
- The bot's Ollama endpoint `/v1/systemone` is a custom endpoint, not stock Ollama (unverified what serves it). With it
  unreachable everything still works on scripted rules; the HUD says "scripted (Ollama offline)".
- `Bot.whatIf` mutates the live build and restores it; anything new that `Player.craft` or a perk's `apply` changes must
  be added to its snapshot or a what-if leaks into the real run.
- The guide never presses keys and never asks the model; `Bot.tap` is a no-op unless `Bot.on`.
- Trials reset the save (`Save.clear`); do not run them in a browser profile whose run you want to keep.
- The desktop build has no `js/version.js`, so it shows "dev build" on the title.

## Tuning — where its numbers live

- Bot: `every` (strategist timer, 7 s), retry 15 s, ask cooldown 2.5 s, sense radii (900 / 1100 / 700 px) and steering
  constants are literals in js/bot.js. The scripted perk order is duplicated in `guidePerk` and `scriptedPerk`.
- devserver limits: `STATS`, `LAYER`, `WAVES`, the regexes. Deploy targets and the remote host: top of deploy.sh.
- Electron window size and builder targets: desktop/main.js and desktop/package.json.

## Testing — how to see it working

- Dev server: `python3 tools/devserver.py [port]`, then http://localhost:8765/ and /editor/. A save prints one line
  (e.g. `tuning: saved 3 overrides to js/tuning.js`); `git diff` shows the change.
- Deploy: `tools/deploy.sh beta` and read the title screen's build line. A dirty tree should refuse.
- Recipes: `node tools/readme-recipes.js` prints `README recipe table: N rows`.
- Bot: `?bot=1&brain=scripted` (no model needed), `?guide=1`, `?trials=20&brain=scripted` for a balance batch
  (results in the console and on the title screen). Test visibly (a phone or a visible browser).
- Desktop: `cd desktop && npm start -- --no-sandbox`; `CENTIPEDE_SMOKE=/tmp/shot.png ./dist/linux-unpacked/centipede.sh`.

## Change log hooks

Update this notebook in the same commit as any change to the system's behaviour.
