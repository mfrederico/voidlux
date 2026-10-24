# AGENTS.md

Centipede is a roguelike snake shooter that runs in the browser. You are a centipede that never stops moving, and every
segment you carry is part of your build: a bullet type, an element or a parasite. It is plain JavaScript drawn on one
canvas. There is no build step, there are no modules and no dependencies, and all art and sound are made in code.
README.md is the player-facing feature list. This file is how to work on the code. **docs/notebooks.md** maps every
system to its notebook.

## Start here

1. Read **docs/notebooks.md**: what each system is, which files hold it, and how the systems talk to each other.
2. Before you change a system, read its notebook (`docs/notebook-<system>.md`).
3. When your change alters how a system behaves, update its notebook **in the same commit**. A notebook that drifts
   from the code is worse than none.

## Run it

```
python3 tools/devserver.py          # http://localhost:8765/ the game, no caching; http://localhost:8765/editor/ the editors
```

Useful URL parameters (read in `Bot.init`, js/bot.js):

| Param | Effect |
| --- | --- |
| `?bot=1` | the autopilot plays (T cycles it) |
| `?bot=1&brain=scripted` | autopilot with the scripted brain only: never asks the model. The usual soak |
| `?god=1` | god mode on the first run, plus the profiler and test keys |
| `?fps=1` | the profiler alone (\` toggles it): frame time, fps, update and draw ms |
| `?deck=1` | start on the saucer deck |
| `?trials=N` | N autopilot runs in a row, summarised |
| `?guide=1` | the guide (hints) without the autopilot |

## How the code is put together

- **Classic scripts and globals.** Every file in `js/` is a `<script>` in **index.html**, and the order matters: a
  file can only use, at load time, what the files before it defined. **editor/range/index.html loads the same scripts
  in the same order.** When you add, rename or remove a game file, change both pages.
- **One object per system, spread over files.** `World`, `Player`, `Enemies`, `Render` and `Music` are each defined
  once (`const World = {...}`) and extended by sibling files with `Object.assign(World, {...})`. Examples are
  world-*.js, player-*.js, enemies-*.js, render-*.js and music-themes.js. A new file for a system follows the same
  pattern and loads after the file that defines the object.
- **`G`** (js/main.js) is the game state: `G.state` (title, playing, paused, settings, perk, discover, won, gameover),
  `G.mode` (world, dungeon or deck), the camera, score and level. **`CFG`** (js/config.js) holds every tuning number,
  and config.js also defines the data tables (BULLETS, RECIPES, BOSSES, BUGS, BIOMES...; PERKS and MEGAS live in
  perks.js). js/tuning.js overrides CFG paths.
- **Files written by tools.** The dev server rewrites these when an editor saves, so keep their format or use the
  editor:
  - js/tuning.js (config editor)
  - js/bestiary.js (bestiary editor)
  - js/sfx.js (SFX editor)
  - js/music-themes.js (the chip tracker; songs go below the `// ---- tracker songs below` marker, and the save
    pattern depends on the 4-space indent)

  Sounds and songs in these files are the user's work. Never revert or "tidy" them.

## Code style

- Match the code around you. Lines are long and dense, with several statements on one line. Comments are plain
  English that say what a thing is for and why, not what the next line does. Every file starts with a header comment
  saying what it holds.
- **Never splice a `//` comment into the middle of a line.** Because lines pack many statements, a mid-line `//`
  silently comments out the rest of the line. That has broken stable more than once. Use `/* ... */` inside a line.
  After any scripted edit (sed, python), check the changed lines; `python3 tools/check-comments.py` scans for it.
- A split or move should be a pure code move: check it by comparing the sorted code lines before and after.

## Checks, commits and deploys

Before the game is released, every finished change goes straight to stable:

1. `node --check` every js file, and run a soak: an autopilot run (`?bot=1&brain=scripted`), watching for exceptions.
   For a UI change, also draw the screens you touched.
2. Commit to `main`, `git push` (origin is github.com/mfrederico/centipede, private, over SSH), then
   `tools/deploy.sh stable` (https://clicksimple.com/centipede/).
3. **Gate the commit and deploy on the checks in one command**, joined with `&&`, never `;`:
   `ok=1; for f in js/*.js; do node --check $f || ok=0; done; [ $ok = 1 ] && python3 tools/check-comments.py && git commit ... && git push && tools/deploy.sh stable`
   `tools/check-comments.py` finds code hidden inside a `//` comment (see Code style).

Rules for deploys and commits:

- `deploy.sh` publishes only committed game files (index.html, manifest, js/, icons/) and refuses uncommitted
  changes.
- Never rsync the repo to the server. desktop/ alone is hundreds of MB.
- Before a risky rework, `git tag -a <name>` first, so the rework can be reverted on its own.
- Commit messages say what changed and why, in plain English. End each one with the `Co-Authored-By` line the
  session gives you.

## Testing with the user

- **Playtests run where the user can see them:** their phone, or their visible Chrome tab. Never use headless or
  hidden browser windows for demos or playtests. Scripted soaks that open their own Chrome window are only for quick
  pre-deploy checks, so say that they open one.
- **Phone setup.** The test phone is a Galaxy Note 20 on wireless adb at 192.168.1.22:5555:
  `adb connect 192.168.1.22:5555`, then `adb reverse tcp:8765 tcp:8765` (the dev server) and
  `adb forward tcp:9222 localabstract:chrome_devtools_remote` (DevTools, to run JS in the page).
  - **Connection refused on 5555?** The phone has rebooted, which turns adb-over-wifi off. It is not a new random port:
    5555 is set by hand. The user plugs the phone in by USB, then `adb tcpip 5555` turns it back on. The port can take a
    few seconds to open after that. Check with `nmap -Pn -p 5555 192.168.1.22` and retry `adb connect`.
  - **`curl 127.0.0.1:9222/json` returns nothing?** Chrome isn't running on the phone. Open the game with
    `adb shell am start -a android.intent.action.VIEW -d http://localhost:8765/ com.android.chrome`.
  - Run page JS in the tab whose URL is exactly `http://localhost:8765/` (stale tabs with other params exist), over the
    DevTools WebSocket with `node --experimental-websocket`.
- **Phone rules:**
  - Back up the phone's save (localStorage) before poking at its game state, and offer to restore it.
  - Don't reload the phone mid-game.
  - Don't change phone system settings yourself; ask the user.
- **Test balance with a build that fits the level**, not the user's endgame save.
- **The editors hold unsaved work in the user's browser tabs.** Test editor changes in a tab of your own, never in
  theirs.
- **Phone audio:** `SFX.init` creates the AudioContext with `latencyHint: 'playback'` on touch devices. That cured the
  crackle on the phone; keep it.
