# The VoidLux kit: how to build a game on it

The kit is the engine every VoidLux game runs on. It is seven classic scripts in `kit/js/` with no build step, no modules
and no dependencies: all art is drawn on one canvas in code, all sound is synthesized. It was pulled out of **Centipede**
(`../centipede/`, a finished, optimized game: read it when you want to see how a real game on these ideas is put together).
A game loads the kit's scripts first, then its own. The kit is shared: a change to it changes every game, so edit it with
every game in mind and never for one game's convenience.

## The scripts, in load order

| file | global(s) | what it gives you |
| --- | --- | --- |
| kit-util.js | `TAU`, `U`, `Rng`, `Pool` | random, clamp, lerp, distance, angle helpers, `U.damp(rate, dt)` for frame-rate independent easing, `U.hit` circle tests, a seeded random for repeatable runs, object pools |
| kit-sfx.js | `SFX` | `SFX.play('key')` plays the sound designed under that key in js/sfx.js (or `SFX.FALLBACK[key]`, or a blip). `SFX.at(x, fn)` pans by screen position. Voices are pooled: never create audio nodes per sound |
| kit-music.js | `Music` | `Music.start('name')` loops a tracker song from js/music-themes.js; `setMood(semitones, fever)`; `playOnce`; `fanfare`; `Music.has(name)` |
| kit-draw.js | `Draw` | `Draw.text` (outlined), `Draw.ink`, `circle`, `ring`, `roundRect`, `glow`, `bar`, `button`; the sprite atlas: `Draw.sprite(key, radius, paint)` draws once, `Draw.stamp(S, x, y, angle)` stamps every frame |
| kit-input.js | `Input`, `TOUCH` | `Input.down(code)`, `Input.once(code)`, `Input.axis()` (WASD / arrows / joystick / gamepad), `Input.mouse`, `Input.taps`, `Input.tapped(rect)`; `TOUCH.buttons` is the phone's button cluster (data: edit it at boot) |
| kit-game.js | `Game`, `Settings`, `Save` | the loop, scenes, canvas fit, camera, messages, shake, profiler; settings and one saved run per game in localStorage |
| kit-notes.js | `Notes` | the producer's note mode: Alt+P pauses, a click picks the nearest thing with an x and y in the game's state and opens a note dialog; notes reach the forge. A game can answer better with `Game.describe = (x, y) => [{ label, fields }]` |
| kit-test.js | `Test` | `?soak=N&fast=K` plays the game with the scenes' bots and reports errors, fps and scenes visited; `Test.assert(cond, what)` |

## The shape of a game

```
index.html          the canvas and the <script> tags (kit, then config.js, tuning.js, sfx.js, music-themes.js, the game, main.js)
js/config.js        const CFG = { ... } every number, each with a comment; data tables (KINDS, WAVES, ...) under it
js/tuning.js        written by the config editor (overrides CFG). Keep it; never hand-edit
js/sfx.js           written by the SFX editor: const SFX_DATA = { key: { jitter, layers } }
js/music-themes.js  written by the tracker: Object.assign(Music.THEMES, { ... }) with songs below the marker line
js/*.js             the game: one object per system (World, Render, Player, Enemies...), spread over files with Object.assign
js/main.js          const Scenes = { title, play, gameover, ... }; Game.boot({ id, title, W, H, scenes, start })
GAME.md             the brief, design, acceptance criteria, tasks and log
```

**Scenes.** A scene is `{ enter(arg), leave(), update(dt), draw(ctx), bot(dt), resize() }`. `Game.go('name', arg)` switches.
`update` gets dt in seconds, capped at 1/30. `draw` gets the context cleared, in screen space (0..Game.W, 0..Game.H).
For a scrolling world set `Game.cam` and draw inside `Game.world(c => ...)`, then the HUD after it.

**Size.** The logical height is fixed (720 by default) and the width follows the screen between WMIN and WMAX, so lay
everything out from `Game.W` at draw time, never from a constant. `Game.uiScale` grows HUD text and buttons on phones.

**Input.** Keyboard codes (`'Space'`, `'KeyW'`); `Input.axis()` folds keys, the touch joystick and a gamepad into one
vector. On a phone the left part of the screen is a joystick; the right has `TOUCH.buttons`, each with a key code the game
already handles, so touch needs no extra code paths. Menus: draw a `Draw.button(...)` and test `Input.tapped(rect)`.

**Sound.** Call `SFX.play('key')` wherever something happens; name keys by the event (`shoot`, `hit`, `kill`, `pickup`,
`hurt`, `die`, `wave`, `start`, `tab`). Every key the code plays shows up in the SFX editor to be designed; until then it is
a blip, never silence. Music: name themes by where they play (`title`, `play`, `boss`, `win`) and `Music.start` them from
the scene's `enter`. Check `Music.has(name)` before relying on a song that may not be written yet.

**Performance (the lessons Centipede paid for).**
- Anything drawn many times a frame is a sprite: `Draw.sprite(key, r, paint)` once, `Draw.stamp` after. Key the sprite by
  what changes its look (kind, colour, flash, animation frame), never by position.
- Never allocate per frame in hot loops: pool bullets and particles (`Pool`), reuse arrays, avoid closures in `update`.
- Never create Web Audio nodes per sound: `SFX.play` uses a pool. Never `setInterval` game logic; everything ticks from `update`.
- Collision over many entities: bucket into a grid; test the few in the same cell.
- Watch `?fps=1`: update and draw ms must stay under 8 each on a phone-class budget.

**Testing.** Every scene has a `bot(dt)`: it sets `Input.bot.axis` and presses keys with `Input.tapKey`, so the soak can
play from the title through game over and back. `../../studio/bin/soak 60 4` runs a headless 60 game-second soak at 4x and prints
the report; `ok: true` means no exceptions, no console errors, no failed `Test.assert`. The report also
lists which scenes were visited: a screen the bot never reached is untested. Run it when the autopilot's run goes through
what you changed (gameplay, scene flow, load order); skip it, and say what you checked instead, when it would not (a screen
the bot never reaches, docs, tuning, copy). It takes the producer a minute each time.

## House rules

- Match the code around you: dense lines, several statements per line, plain-English comments that say what a thing is
  for and why. Every file starts with a header comment saying what it holds.
- **Never splice a `//` comment into the middle of a line**: it silently comments out the rest. Use `/* ... */` inside a line.
- `node --check` every changed file. Soak when the autopilot would play through the change, not otherwise. Then commit,
  saying what changed and why.
- Files the editors write (tuning.js, sfx.js, music-themes.js) are the designer's work: never revert or tidy them.
- The kit is shared. A game-specific need goes in the game. A need two games share goes in the kit, documented here.
