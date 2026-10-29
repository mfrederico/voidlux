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
| kit-draw.js | `Draw` | `Draw.text` (outlined), `Draw.ink`, `circle`, `ring`, `roundRect`, `glow`, `bar`, `button`; the sprite atlas: `Draw.sprite(key, radius, paint)` draws once, `Draw.stamp(S, x, y, angle)` stamps every frame; pictures from files: `Draw.load(key, 'assets/x.png')` once, `Draw.pic(key, x, y, w, h, angle)` every frame |
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

## Sprites and pictures

`assets/sprites.json` is the game's sprite manifest: `{ "sprites": { "<key>": { "file": "cow.png", "w": 32, "h": 32, "frames": 1,
"fps": 8, "source": "editor" } } }`. The sprite editor (Media → Sprites) and the builder both write it; `Game.boot` loads it
(`Draw.loadManifest`), so a key is drawable from the first frame: `Draw.pic('cow', x, y, 48)` (centred, crisp, height from the
picture's shape), `Draw.pic(key, x, y, w, h, angle, frame)` for one frame of a strip, `Draw.anim(key, Game.time, x, y, w)` to
run through the strip at its fps. A strip is one PNG of `frames` equal frames side by side. Until a file has arrived `pic`
draws nothing and returns false: keep the coded fallback (`Draw.sprite`) for anything that must show at once. A file that
will not load is a console error, which the soak reports.

- A sprite the producer drew is theirs: never redraw or "improve" `assets/` files or manifest entries you did not make;
  draw them where the game needs them. A sprite you make (by code, or through a plugin) you register in the manifest
  yourself (key, file, w, h, frames, fps, `"source": "spritecook"` / `"cellcog"` / `"builder"`) and commit with the file.
- An SVG in the manifest draws like any picture (scaled smoothly, set `"smooth": true`); pixel art stays crisp by default.
- A **code sprite** is `assets/<key>.js` with `"kind": "code"` in the manifest: one `Draw.define('<key>', half, (g, f) => { ... })`
  that draws at (0, 0), facing right, within radius `half` (plain canvas calls on `g`, `Draw.circle` and friends, `f` the
  frame; `{ frames, fps }` as a fourth argument for a little life, or a whole cycle: a run, an idle, a jump, rigged as
  parts posed per frame; `Draw.anim(key, Game.time, x, y, w, 0, 0, flip)` plays it, flip facing it left). The manifest loads the script; `Draw.pic` paints it
  once per frame into the atlas and stamps it scaled, so it costs what a picture costs. The sprite page can make these
  from a sketch (maker "drawing code"), and you may write one yourself the same way instead of a stroke-by-stroke
  drawer in render.js.
- A **rig** is `assets/<key>.rig.js` with `"kind": "rig"`: one `Rig.define('<key>', { half, bones, chains, parts, motions })`
  (the format is at the top of kit-rig.js): bones with rest angles, two-bone IK chains for arms and legs, parts drawn in
  bone space, and motions as keyframes tweened. `Draw.rig(key, 'run', Game.time, x, y, w, flip)` plays one centred at
  (x, y); with a final `true` (stand) the rig's ground line, where its feet stand, is put at (x, y) instead, which is what
  a platformer wants. `Draw.anim`
  on a rig key plays its first motion. Motions are data: a motion made for one biped fits any other. The same safety
  rules as a code sprite apply (only drawing, node --check).
- A **3D model** is `assets/<key>.glb` (binary glTF, made with mojulo on the sprite page's 3D tab), listed with
  `"kind": "model"` in sprites.json and, with what it is for, in **`assets/models.json`, the 3D cast**: one item per
  thing the game draws in 3D (`what`, `replaces`: the code that draws its stand-in, `size` in world units, and `file`
  once a model is kept). A model may change or arrive at any time without you: the producer revises them on the page.
  **Wire models in once, through `../../kit/js/kit-models.js`, never with a loader of your own:**
  1. Vendor three's `examples/jsm/loaders/GLTFLoader.js`, `examples/jsm/utils/BufferGeometryUtils.js` and
     `examples/jsm/utils/SkeletonUtils.js` of the game's own three.js version (its REVISION) into
     `js/vendor/addons/loaders/` and `js/vendor/addons/utils/`, from `https://cdn.jsdelivr.net/npm/three@0.<REVISION>.0/`.
  2. Give index.html an import map, before any module script, mapping `"three"` to the game's own
     `./js/vendor/three.module.min.js` (one three instance, not two), and `<script src="../../kit/js/kit-models.js">`.
  3. Where the module script makes `window.THREE`, import the loader and SkeletonUtils and call
     `Models.init(THREE, GLTFLoader, SkeletonUtils)`.
  4. For EVERY item in models.json, kept or not: where the game builds that thing's stand-in, use `Models.make('<key>')`
     when it returns an object, the stand-in otherwise, and in `Models.on(key => …)` swap live stand-ins for the model.
     The model comes scaled to the item's size, standing on y = 0, centred, facing +x: place it as the stand-in is placed.
     Parts the stand-in animated in code (legs, eyes, colour flashes) need a decision per thing: keep those parts in code
     beside the model, tint the model's materials, or play its clips (`Models.clips(key)`) with an AnimationMixer.
  Then a model kept later appears without another change. 2D games ignore all of this.
  To see a model without a browser: `node ../../studio/bin/glb-strip.mjs assets/<key>.glb /tmp/look.png 4 300`, then
  Read the PNG (four views turning, drawn in software).
- Publishing copies `assets/` (models, events-vocab.json and events.json with them) and `js/vendor/` with the game.
- The sprite page's `>>` sends the producer's sketch to you with a message that says exactly what to draw and where
  (`sketches/<id>/<key>-N.svg` or `.png`, then `result.json`). Follow it to the letter: nothing goes into `assets/` or the
  manifest until the producer keeps a take.

## Events (rules the producer writes)

How things react (an enemy hit by fire starts burning, at most three times; a burning tree sets its neighbours alight) is
**rules**, not code: the producer writes them on the sprite page's Events tab, `../../kit/js/kit-events.js` runs them, and
its header is the format. Your part, once per game and whenever the game learns a new trick:
- `assets/events-vocab.json`: the **subjects** (each kind of thing, keyed by its sprite or model key, with groups such as
  player, enemy, boss, prop, pickup), the **events** the game emits (spawn, hit, die, touch, pickup…, each with the fields
  the code knows then: hit has type, amount, source) and the **actions** it can do (each wrapping the game's own function,
  with settings and defaults; `"lasting": true` for one with a duration). The producer can use nothing else, so declare
  what the game really does, in words a producer reads.
- `<script src="../../kit/js/kit-events.js">`; `Events.action(name, (ctx, args, end) => …)` for every action (ctx.target is
  the object, end() for a lasting one when its effect is over); `Events.clock` and `Events.rng` set to the game's own time
  and random if it has them; then `await Events.load()` before play starts.
- `Events.emit(event, key, fields, object)` at the one place each event happens. Rules are data: never read
  `assets/events.json` yourself and never write a reaction in code that a rule could hold. You do not edit the producer's
  rules, except when asked to move a hard-wired reaction into one that reproduces it exactly.
- A broken rule or a missing implementation is an error in the console at load, naming it; fix the vocabulary or the code,
  not the rule. `?events` in the page's address logs every rule that fires; `Events.trace` holds the last 300 steps.

## Plugins the builder has

The forge's agents share the plugins installed on /agents (Skills & plugins). A plugin's skills load when a task matches them
or when a message names one (`/spritecook:spritecook-generate-sprites`); a plugin's MCP tools need the forge's sign-in to
that service (`php scripts/agent-shell.php`, then `/mcp`). A tool that answers "needs authentication" is not signed in: say so
in one line and stop; never work around it with an API key or a web form.

- **spritecook** — pixel-art sprites, character animations, tilesets, UI kits. Read `spritecook-workflow-essentials` before
  the first call. Download each result's `sprite_url` into `assets/` (`curl -s -o assets/<key>.png <url>`), register it in
  `assets/sprites.json` (see Sprites above), commit both, and draw it with `Draw.pic`. Generation costs the
  producer credits: one variation first, more only when asked.
- **cellcog** — art, music, sound, video and documents from a remote agent on the producer's own CellCog account. For a whole
  asset family in one style, or music beyond the tracker. Results are links good for 30 days: download what the game uses
  into `assets/` and commit it.
- **game-trend-skills** — market scans, idea scoring, build briefs, launch plans, metrics, all with live cited sources. For
  GAME.md's Design and the Concept conversation, not for code.
- **mojulo** — a 3D recipe compiler (glTF, STL, a walkable HTML page). Rarely the tool for a canvas game; its data lives in
  the agents' home (`~/.mojulo`) and is kept between runs.

## House rules

- **Never delete, loosen or skip a soak check or test to get a pass.** A check that already fails is reported as failing
  (reply and commit message) and fixed only when that is the task.

- Match the code around you: dense lines, several statements per line, plain-English comments that say what a thing is
  for and why. Every file starts with a header comment saying what it holds.
- **Never splice a `//` comment into the middle of a line**: it silently comments out the rest. Use `/* ... */` inside a line.
- **Never start a browser** (Chrome, Chromium, Playwright, Puppeteer). There is none in the jail and the server must not
  run one; it brought the machine down. Playtests and screenshots run in a producer's open browser, the game page in
  the forge: `../../studio/bin/soak 60 4` for a playtest, `../../studio/bin/shot 5 "bot=1"` for a screenshot after 5
  seconds with that query (it prints a PNG path to Read). If either says `no-browser`, nobody has the game open: the
  check is not done, so say so in your reply and carry on. Do not look for another way.
- If the game has a scheduled first event (a first night, a first boss, a timer), put `"soak_min": <seconds>` in game.json,
  enough to reach and get through it: every soak of this game then runs at least that long.
- `node --check` every changed file. Soak when the autopilot would play through the change, not otherwise. Then commit,
  saying what changed and why.
- Files the editors write (tuning.js, sfx.js, music-themes.js) are the designer's work: never revert or tidy them.
- The kit is shared. A game-specific need goes in the game. A need two games share goes in the kit, documented here.
