# Render notebook

The drawing layer. Everything the player sees is drawn by hand on one 2D canvas each frame, in a "sticker-cute" style: chunky round shapes, thick dark outlines (`Render.OUT`), big glossy eyes. There are no images and no font icons: glyphs come from `Icons`. In code terms, `Render` is one global object built across many files. `Render.frame()` is called once per loop tick by main.js. It picks what to draw from `G.state` and `G.mode`, draws the play field in world space under the camera, then the overlays and HUD in screen space.

**Files:**

| file | what it holds |
|---|---|
| js/render.js | the core: `Render.init` (2D context, grass pattern), primitives (`circle`, `shine`, `eyes`, `blush`, `mouth`, `legs`, `tint`, `roundRect`, `text`, `ink`, `square`), scale (`ui`, `zoom`, `viewW`, `viewH`, `aimable`, `worldTransform`), the CRT vignette, and `frame()` |
| js/render-world.js | overworld ground, regions, lakes, trees, hedges, walls; `drawDeco` (every biome's decorations); `drawPickups` (see notebook-world.md) |
| js/render-circus.js, render-farm.js, render-jungle.js, render-graveyard.js | each biome's own drawers, called from `drawWorld` / `drawDeco` (farm's `drawTractor` is also used by render-player.js and render-enemies.js; graveyard's `drawWraiths` / `drawFog` and circus's `drawMirrors` are called by `frame()`) |
| js/render-dungeon.js | the maze (`drawDungeon`, `drawDungeonLabels`), the Shovel and the Siphon (see notebook-dungeon.md) |
| js/render-player.js | the centipede: `drawPlayer`, head gear, riders, the giant gauge, Lich skulls (see notebook-player.md) |
| js/render-enemies.js | snakes, bugs, parasites and sheep, ghosts, stones, rocks, mounds; shared overlays `statusOverlay`, `hpBar`, `drawHalo`, `drawCharm`; `ghostIcon` (see notebook-enemies.md) |
| js/render-bosses.js | crab, Skull King (and stomp foot / shadow), serpent, Overlord (and saucer), Clown Car (see notebook-bosses.md) |
| js/render-fx.js | projectiles, poison clouds, lightning arcs, particles, floating text (see notebook-combat.md) |
| js/render-hud.js | `drawHUD` (stats panel, mega bar, segment chain, messages, key hints, CRAFT pill, god panel and profiler, weapons-off sign, banner, boss and swarm bars) and the overlay dispatch; `drawTouch`, `megaBar`, `deskMega`, `keyCap`, `keyHints`, `shownBoss`, `drawRebuildPreview` |
| js/render-cards.js | the pause cards: `drawDiscover` dispatches to the new-segment card, `drawDungeonCard`, `drawSwarmTip`, `drawLeaveCard`, `drawPerkTip`, `drawCraftTip` |
| js/render-perkshop.js | the perk shop, `drawPerks` and `drawPerkFx` (see notebook-perks.md) |
| js/render-screens.js | whole screens: `drawTitle`, `drawSettings` (+ `setLabel`), `drawGameOver`, `drawWon`, `drawAscend` |
| js/render-bench.js, render-bench-body.js, render-bench-perks.js, render-bench-forge.js | the workbench; entry point `drawPaused` (see notebook-workbench.md) |
| js/icons.js | `ELEM_COLORS` and `Icons`: `draw(name, ...)`, `kindOf(B)`, `strip` / `stripWidth`, `bulletItems(B)`, `tile(B, ...)` |

## Model

- `Render` is created in render.js. Every other render file adds methods with `Object.assign(Render, {...})`, so `this` inside any drawer is `Render` and any drawer can call any other.
- State it owns: `ctx`, `grassTile` (a pattern made in `init`), `OUT` (the outline colour), `vig` / `vigKey` (the cached vignette canvas, rebuilt when the size or nightmare flag changes), `hudW` (the stats panel width, so the boss bar keeps clear).
- `Render.init(canvas)` runs at boot and again from main.js `fit()` whenever `CFG.W` changes.
- Coordinates: logical canvas is `CFG.W` x `CFG.H` (H is always 720; W follows the window). The browser scales the canvas element with CSS. All drawing is in logical pixels.
- Renderers write hit rects back to `G` (and `Settings`) every frame so `Input` can test taps and clicks: `G.titleBtns`, `G.titleWord`, `G.newRunBox`, `G.loadRunBox`, `G.discoverBox`, `G.discoverBench`, `G.craftBtn`, `G.perkLeave`, `G.hudBottom`, `Settings.hit`, `Settings.view`. A rect exists only after its screen has been drawn once.
- Drawing is mostly stateless: animation comes from `G.time` and the entities' own timers.

## How it works

**Scale.** `Render.ui()` is the HUD scale: `Settings.size()` on touch screens, else 1. `Render.zoom()` is the world camera zoom: 1 on keyboard screens and on the deck; on touch, `Settings.size()` in the world, and in a dungeon as much as fits the maze (at least 1). `viewW()` / `viewH()` are how much world the screen shows. `worldTransform(c)` applies the zoom and the rounded camera offset. `aimable(e)` says if an enemy is on screen (in the world only; bosses always count). Player and combat targeting use it, so your guns only fire at what you can see.

**`Render.frame()`** in order:
1. Clear. Title: `drawTitle` (and `drawTouch` on touch), done.
2. Workbench (`'paused'`, or settings opened from it): a dark fill, then `drawHUD`, which draws `drawPaused` and the settings page instead of the HUD. The world is not drawn under the bench (it saves frames).
3. `G.cinematic`: `drawAscend`, done.
4. World space (under `worldTransform`): `drawWorld`, `Deck.draw` or `drawDungeon` by mode; `drawClouds`; in the world `drawPickups` and `drawStompShadow`. Then every enemy in `G.enemies` (culled off screen in the world with a 700 / 500 px margin): mound if hidden, halo if rare or unique, charm, mime wall, then the drawer by `e.kind` (bosses by their `BOSSES[type]` flag: `foot`, `serpent`, `alien`, `car`, else the crab), then its label. Then `drawMirrors` (world), `Minions.drawDungeon` (dungeon), `Bot.draw`, the player (`drawPlayer`, `drawLichSkulls`, `drawGiantGauge`), projectiles, arcs, particles, the world's wraiths, fog and stomp foot, floaters.
5. Screen space: nightmare tint (`CFG.NIGHTMARE.tint`), `vignette()`, then the maze or deck labels (in world space again, but over the vignette so they stay bright).
6. World extras: the dread vignette (`World.dread`), circus spotlights, the mushroom invert, the "giggles" line, cream pies (`G.splats`), the parasite threat pulse (`Enemies.threat`).
7. Mega effects: `G.tint`, `G.ring`, the Sacrifice countdown, `G.flash`; the storm / lightning / lich / deep-freeze timers; the slow-mo scanlines.
8. `drawHUD()`.

**`drawHUD()`** draws the play HUD and then the overlay for the state: `drawPaused` (+ `drawSettings`), `drawDiscover` for `'discover'` (scaled 1.35 on touch, and its tap rects scaled once to match), `drawPerks` for `'perk'`, `drawTouch` on touch, `drawWon`, `drawGameOver` (scaled 1.3 on touch), then the boss bar (`shownBoss`: the awake boss in the player's region) or the swarm bar. Text sizes go through `F(n) = round(n * ui())`. On a phone, messages show as the newest three on pills under the stats; on a keyboard, as a list at bottom left. Key hints and the CRAFT pill and desk MEGA bar are keyboard-only.

**`drawTouch()`** draws, in `'playing'`, `'perk'` and `'paused'` only: the floating joystick (or the maze's plus-shaped stick, or a "steer" ghost), and the `TOUCH.buttons` cluster. MEGA with a `bar` is drawn by `megaBar`. The CRAFT button lights gold with a count of affordable fusions.

**Cards.** `drawDiscover` reads `G.discover.tip` (none = new segment, `'craft'`, `'dungeon'`, `'swarm'`, `'perks'`, `'leave'`). Each card slides in using `G.discover.t` and records `G.discoverBox` / `G.discoverBench`; `Input.discoverKey` turns a tap on them into C or P.

**Screens.** `drawTitle` draws `Title.draw()`, the vignette, the CENTIPEDE word (its rect arms god mode on five taps), the controls, the saved-run line, the nightmare-cleared star, and the big square buttons via `Render.square` (rects into `G.titleBtns`), with the keyboard selection ring from `G.titleSel`. `drawSettings` is a scrolling list on the left and an info card on the right, laid out like the workbench pages. `drawWon`, `drawGameOver` and `drawAscend` read `G.won`, the run numbers and `G.cinematic`.

**Icons.** `Icons.draw(name, x, y, s, color, ctx)` draws one glyph in a square of half-size `s`; an unknown name falls back to a plain disc. `Icons.kindOf(B)` maps a bullet to its delivery glyph. `Icons.tile(B, ...)` is the bullet tile used in the HUD chain and the workbench. `Icons.bulletItems(B)` builds the stat strip (damage includes `G.powerMult` and `Combat.ramp`; range, speed and rate include `G.mods`). The `ghost` glyph calls `Render.ghostIcon`.

## Interop

- Called by: main.js loop (`Render.frame`, `Render.init`, `Render.text` for the error line), `G.camTarget/clampCam` (`viewW/viewH`), `Input` (`ui`, `zoom`, `deskMega`), `TOUCH.place` (`ui`), `Dungeon` (sizes), `player-fire.js` and `combat.js` (`aimable`), `world-swarm.js` (`viewW/viewH` for spawn radius), `Title.draw`, `Gallery` and `BenchFx` (`Render.ctx` and primitives), `Deck.draw` (`ghostIcon`), `Gallery` (`statusOverlay`).
- Calls into: `Title.draw`, `Deck.draw/drawLabels`, `Minions.drawDungeon`, `Bot.draw/drawHUD`, `Mega.current/ready/label`, `Player.fusions/rebuildPlan/holdings/segColor/weaponCount`, `World.regions/regionAt/dread/regionName/bossFor`, `Dungeon.name/dotsLeft`, `Settings.howItFires`, `Save.has/summary/when`.
- Reads tables: `BULLETS`, `BUGS`, `BOSSES`, `MEGAS`, `ELEMENTS`, `PERKS`.
- **CFG keys read here (core and HUD):** `W`, `H`, `MEGA_MAX`, `NIGHTMARE.tint`, `DEEP_FREEZE.mult`, `SWARM.warn`, `WIN_RESET`, `HEAD_R`, `SEG_R`, `SEG_SPACING` (render-screens.js), `MINIONS.spitMult/spitCd` (icons). Settings: `Settings.data.size`, `shake` (camera, in main.js).
- Writes no save fields. Writes hit rects on `G` and `Settings` (see Model).
- Other notebooks: notebook-core.md (state machine, Input), notebook-workbench.md, notebook-perks.md, notebook-world.md, notebook-player.md, notebook-enemies.md, notebook-bosses.md, notebook-dungeon.md, notebook-combat.md.

## Invariants and gotchas

- Every render-*.js file must load after render.js in index.html. A drawer name must be unique across all of them: `Object.assign` silently overwrites a duplicate.
- `frame()` saves the context once and must restore it on every return path. A drawer that changes `globalAlpha`, `setLineDash`, transforms or `letterSpacing` should `save/restore` or reset them, or the next drawer inherits them.
- World-space drawers run under `worldTransform`. HUD and cards are screen space. Labels that must not be darkened by the vignette are drawn after it (`Deck.drawLabels`, `drawDungeonLabels`).
- Tap targets are recorded by the drawer, not computed by `Input`. If you move a button, `Input` follows automatically, except the perk cards, whose layout is repeated in `Input.tapShop`.
- `Render.zoom()` on touch and `Dungeon.center` both follow `Settings.size()` (the Size setting); keep them in step.
- Off-screen culling in `frame()` is world-only, with a margin per kind: how far a monster can reach onto the screen (a bug
  3 r + 40, or its tongue's reach; a parasite 70; a snake 14 per segment; a boss 700). A new monster that draws far from its
  centre needs its own margin there.
- **Sprites.** The swarm's monsters, loot pieces and damage numbers are stamped from a cache, not drawn stroke by stroke.
  `Render.sprite(key, half, paint)` runs `paint` once at (0, 0), facing angle 0 and at the canvas's real pixel scale
  (`pxScale`, taken from the camera each frame), into a shelf of a 2048 px atlas canvas (`alloc`, page `mon`; text on page
  `txt` through `textSprite` / `textStamp`). `stamp(S, x, y, a)` puts it down turned by a, setting the transform from
  `camM` (the camera, kept only during the world pass) instead of save/restore. A full page is wiped and refills.
  - The key must hold everything the painting depends on: colour, flash, size, the animation frame (12 steps of the walk,
    6 of the wingbeat, 8 of the roach's antennae, 5 of a parasite's breathing), dread pupils. Anything not in the key is frozen
    at whatever the first monster had.
  - Only what turns with the body goes in a sprite. Shadows, wind-up rings, status overlays, hp bars, glows and anything
    upright are drawn live around it. Parasites are three stamps (legs, an upright body and shine, the face), because the
    body's shine stays up-left as the spider turns. Snakes are a ball per segment (balls never turn) and a turned face; the
    shadows are one path.
  - Sprite-drawn: the bugs in `SPRITE_BUGS`, non-farm parasites, snakes that are neither bone nor balloon, weapon and dot
    pickups, floaters. Everything else is drawn live. On the phone this was worth about 5 fps at 250 monsters in a Bullet
    Storm: what is left is the count of draws (a snake is 13), and the game update.
- Scale fonts and spacings with `ui()` (via `F`) on HUD and cards, and never scale the world with it: the world uses `zoom()`.
- The text in the game reads through icons where it can (`Icons.strip`), so the HUD works in any language. Prefer an icon to a word.
- `Render.text` draws a dark stroke under the fill; on a bright badge use `Render.ink` instead, or the letters smear.

## Tuning

Colours and sizes are inline in each drawer. The vignette depth and colours are in `Render.vignette`. HUD scale is the Size setting (`Settings.ITEMS` size values 1 / 1.15 / 1.3, default 1.3). The nightmare dusk is `CFG.NIGHTMARE.tint`. Touch button positions and radii are `TOUCH` in input.js.

## Testing

- \` (backquote) toggles the profiler on its own, and `?fps=1` turns it on (for a phone). With `?god=1` the god panel shows it too: the profiler (smoothed update and draw ms, frame interval, fps, slow-frame count, enemies, bullets, particles). Use it to check a new drawer's cost.
- God warps show each biome and boss: L / O / X / . for levels 2-5, B / Y / , / 0 / Q for the bosses, H for a dungeon, `?deck=1` for the deck.
- Phone layout: test on a real phone or with touch emulation, since `Input.touch` switches the HUD, zoom and buttons.
- Console: `Render.zoom()`, `Render.viewW()`, `Render.ui()`, `G.discover = {id: 'bullet', t: 0}; G.state = 'discover'` to show a card, `G.banner = {text: 'TEST', t: 3}`.

## Change log hooks

Update this notebook in the same commit as any change to the system's behaviour.
