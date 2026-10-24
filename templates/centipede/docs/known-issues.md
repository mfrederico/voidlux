# Known issues

These came out of a full read of the code on 2026-10-22, while writing the notebooks. When you fix one, delete its line
and say so in the commit.

Already fixed:
- 2af20dc: seven lines where a mid-line `//` comment hid live code. tools/check-comments.py now guards against this.
- The next commit: the likely bugs, the dead code and the stale comments from the first list.

What's left below is a judgement call (it may be deliberate), a copy that can drift, or a small thing.

## Gameplay quirks that may be deliberate

Decide whether each is how the game should play.

- These kills don't go through `Combat.kill`, so they give no loot, score or mega charge:
  - a charmed monster dying (`kill` clears its `onDeath`)
  - a latching parasite
  - Hive Mind running out
  - `charmBoom`
  - the fortune teller's chicken swap
- Burn, poison and spark ticks take hp directly. They skip the damage numbers, the hit flash (burn and poison) and the
  Bullet Storm boss resist.
- Uniques don't inherit their base bug's resistances (`rowOf` gives only `unique-<id>`). For example, an Ironback is
  not ice-immune like a beetle. The bestiary editor can now set each unique's resistances.
- `stompLand` always names "the skeletal foot" as the cause, even for the Overlord's saucer.
- Pickups more than 1600 px away freeze, fuse timers included.
- The test range sets every target's resist to 1, so it ignores the bestiary.
- Above level 5 there is no level song until you make one: name it `level5` or `circus`. Levels 6 and up use it too.

## Copies that can drift

When you change one of these, change its twin too.

- `Render.drawPerks`, `Perks.cardRect` and `Input.tapShop` each repeat the perk-card layout numbers.
- `Render.pipeFrom` repeats the piping rule from `Player.resolveBuild`.
- tools/devserver.py has its own copy of the `applyTuning` code and the element list.
- `Music.inst` and the tracker's `Synth.inst` must stay identical. They match today, apart from one comment.
- editor/range/index.html clears the World arrays by hand, so a new World array has to be added there too.
- The bestiary editor keeps hand-written hp, speed and traits for its non-unique rows.

## Small things

- `hiveT` counts down inside `Render.drawWorld`, by 1/60 per frame: game state changing in the renderer, tied to the
  frame rate.
- `Player.snapshot(keep)`: no caller passes `keep`. `Player.build` has an unused `have`.
- The `BIOMES` flags `haunted`, `jungle`, `farm` and `circus` are unread; `genRegion` works the flags out from the level.
  `CFG.MOBILE_SCALE` is unread too.
- `G.mega.used` is counted but never read. `THEMES.demo` is never looked up (it is a tracker song, kept).
- The crop circle's `d.stand` is never set, so the beam part of its glow never draws.
- The god keys `KeyQ` and `Quote` both warp to the Clown Car. The god-mode help message leaves out several keys.
- `G.lootScale` stays stale in dungeons.
- The arena centre `x0 + 1900` is written out in both `genRegion` and `drawWorld`.
- The bestiary editor's `tempo` scales every boss key ending in `Cd`.
- tools/readme-recipes.js shows config damage before `tuneFusions`.
- The desktop build has no `version.js`, so its title says "dev build".
