# Working on this game

This game runs on the **VoidLux kit** (`../../kit/`). Read `../../kit/AGENTS.md` first: it is the engine's guide (the
loop, scenes, input, sprites, sound, music, the soak test) and the house rules for game code. Then read **GAME.md** here:
the brief, the design, the acceptance criteria and the task list are the contract you build against.

## Files

| file | what it holds |
| --- | --- |
| index.html | the canvas and every `<script>` in load order (kit first, then config, tuning, sfx, music, the game, main.js last) |
| js/config.js | `CFG`: every tuning number with its comment (the config editor reads it); data tables below it |
| js/tuning.js | overrides written by the config editor. Never hand-edit; never delete |
| js/sfx.js | `SFX_DATA`: sounds designed in the SFX editor. Never hand-edit; add a key by calling `SFX.play('newkey')` in code and design it in the editor (until then it plays a blip) |
| js/music-themes.js | `Music.THEMES`: songs from the tracker, below the `// ---- tracker songs below` marker. Never hand-edit the songs |
| js/world.js | the simulation: entities and rules, no drawing |
| js/render.js | drawing, no rules |
| js/main.js | the scenes (title, play, paused, gameover) and `Game.boot` |
| GAME.md | the design doc, acceptance criteria, task list and log |

## Rules

- Classic scripts and globals, no build step, no modules, no dependencies, all art and sound in code. A new file is a
  `<script>` tag in index.html in the right place.
- Every scene gets a `bot(dt)` so the soak can play it. A screen the bot cannot leave fails the soak.
- `CFG` holds every number a designer might tune. No magic numbers in world.js.
- Comments say what a thing is for and why. Never splice a `//` comment into the middle of a line.
- After a change: `for f in js/*.js; do node --check $f || exit 1; done`, then a soak:
  `node ../../studio/soak.mjs __SLUG__ 60 4` (60 game-seconds at 4x). Fix every error it reports before you call a task done.
- Add a line to GAME.md's log when a task is done, and tick its acceptance line only when the soak proves it.
- Commit small, in plain English: what changed and why. The studio makes checkpoints (tags) around your runs.
