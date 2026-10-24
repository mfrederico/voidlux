# Working on this game

This game sprang from **Centipede**, a finished and optimized roguelike snake shooter: plain JavaScript on one canvas, no
build step, no modules, all art and sound in code. Everything Centipede is, this game is at birth; the job is to make it
its own game by the brief and the Design in **GAME.md** (read that first: brief, design, acceptance criteria, task list).

## Read before changing anything

- **docs/notebooks.md** maps every system to its notebook (`docs/notebook-<system>.md`): what it is, which files hold it,
  how the systems talk. Read a system's notebook before you change it, and update it in the same commit when its
  behaviour changes. A notebook that drifts from the code is worse than none.
- **docs/CENTIPEDE-AGENTS.md** is the original's working guide: how the code is put together (one object per system,
  spread over files with `Object.assign`; `G` the game state; `CFG` every tuning number), its code style, and its traps.
  Its sections on the dev server, deploys, phones and Steam are about the original's tooling and do not apply here.
- **README.md** is the player-facing feature list. Keep it true as the game changes.

## The forge's conventions (what is different from the original)

- The editors write these files; never hand-edit or tidy them: js/tuning.js (config editor), js/sfx.js (SFX editor),
  js/music-themes.js (the tracker; songs below the `// ---- tracker songs below` marker). js/bestiary.js has no editor
  here yet: edit it by hand, keeping its format.
- Sounds: `SFX.play('key')` keys the code plays appear in the SFX editor to be designed; a key with no design plays the
  game's coded sound (the original's tone recipes) or, for a new key, nothing until designed.
- **Testing:** `node --check` every changed file. Then, if the autopilot would play through what you changed, a soak from
  this folder: `../../studio/bin/soak 90 4` (90 game-seconds at 4x: the scripted autopilot plays; every exception and
  console error is reported; `ok: true` is the bar). js/forge-soak.js is the shim that makes `?soak=N` work; leave it loaded last.
  `?bot=1&brain=scripted` watches the autopilot by eye; `?god=1` gives god mode, the profiler and the test keys.
- `index.html` lists every script in load order; a new file is a `<script>` tag in the right place. There is no editor/
  range page here, so a script order change needs only index.html.
- **Soak only when it tests the change.** The soak is the autopilot playing the game: it proves gameplay, scene flow,
  load order and anything the bot's run touches. It proves nothing about a screen the bot never reaches (menus, a workbench,
  an editor page), docs, tuning values, copy or comments, and it costs a minute of the producer's time. Before running it,
  ask: would the autopilot's run go through what I changed? Yes: run it and fix what it reports. No: skip it, and say in
  one line what you checked instead (a `node --check`, reading the code path, drawing the screen). Never run it as a ritual.
- Add a line to GAME.md's Log when a task is done, and tick its acceptance line only when a check proves it. Commit
  small, in plain English: what changed and why. The forge makes checkpoints (tags) around your runs.
- Never splice a `//` comment into the middle of a line (it silently comments out the rest). Use `/* ... */` inside a line.
