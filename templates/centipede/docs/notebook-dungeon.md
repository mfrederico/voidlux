# Dungeon notebook

Dungeons are the side quests that make a run stronger. Every overworld region except the first (60% chance there) and the boss arenas has one entrance. Its look depends on the biome: a mound, a crypt, a temple door, a saucer hatch or a circus tent. Touching it takes you off the map into a Pac-Man maze. In the maze you move on a grid, eat dots (crafting currency) and power pellets, eat frightened ghosts (perk currency), and can use a Siphon, a Shovel and, in the big top, a Hammer. Eating every dot plays a victory song, takes you back outside and opens the perk shop with a free pick. On the farm (level 4), while `CFG.DECK_MODE` is true, the entrance leads to the **saucer deck** instead: a fixed-screen shooter with Space Invaders waves. In code, `G.mode` switches from `'world'` to `'dungeon'` or `'deck'`, and `G.enemies` is pointed at the mode's own list.

**Files:**

| file | what it holds |
|---|---|
| js/dungeon.js | `Dungeon`: maze generation (`gen`), tiers and themes, grid movement (`movePlayer`, `arrive`), dots (`eatDot`, `checkClear`), ghosts (`spawnGhost`, `respawnGhost`, `bfs`/`distFrom`, `update`), the Siphon, the Shovel (`dig`), the Hammer and moles (big top), `enter`/`exit`/`stay`, `briefing` |
| js/deck.js | `Deck`: the saucer deck's `enter`/`exit`, the cannon formation (`formPos`, `movePlayer`), waves (`formation`, `nextWave`, `onKill`), dives, the bonus UFO, falling pickups, `vacuum`, its own `draw`/`drawLabels` |
| js/render-dungeon.js | `Render.drawDungeon` (walls, floor, dots, pellets, themed decor, exit label, fright tint), `drawDungeonLabels` (shovel and hammer strip), `drawShovel`, `drawSiphon` |
| js/render-enemies.js | `Render.drawGhost` (maze ghosts, fright blink, big-top clown nose) |
| js/render-cards.js | `Render.drawLeaveCard` (the EXIT / STAY prompt) and the get-ready card |
| js/settings.js | `Settings.dungeonCard(B)` (the get-ready card), and the `tip === 'leave'` key handling in `updateDiscover` |
| js/main.js | `G.enterDungeon(en)` (picks deck or maze), the mode dispatch in `G.update` and `G.solidAt`, `G.playDeck` (`?deck=1`) |
| js/player.js | the entrance touch test in `Player.updateWorld`, and the mode dispatch to `Dungeon.movePlayer` / `Deck.movePlayer` |
| js/world.js | entrance placement in `World.genRegion`, entrance cooldowns, and the farm saucers' tractor beam in `World.update` |
| render-world.js + render-graveyard/jungle/farm/circus.js | entrance drawers: the default mound, `drawCrypt`, `drawTempleDoor`, `drawSaucerHatch`, `drawTentDoor` |

## Model — the state it owns, and its lifecycle

- **Entrance** (`World.entrances[]`): `{ x, y, region, used, cd }`. Farm entrances also have `beamT` and `beam`. `used` makes it dead for the rest of the run. Cleared or abandoned, it closes, and the drawer shows it dark with no label. `cd` (2.5 s after an exit) stops instant re-entry.
- **Dungeon state** (all on the `Dungeon` singleton; rebuilt by `gen()` on every `enter`):
  - The grid: `grid[r][c]` is 0 floor, 1 wall, 2 exit. `cols`, `rows`, `cell` come from the tier. `ox`, `oy` are the screen offsets from `center()`.
  - `dots[r][c]`: 0 none, 1 dot, 2 pellet. `dotsLeft`.
  - `ghosts[]` (which is also `G.enemies` while inside), `pen`, `startCell`, `fright`, `dist` (the BFS map) and `distT`.
  - `exitT` (the exit is shut for a few seconds), `winT` (countdown after ALL DOTS), `entrance`, `region`, `returnPos`.
  - Devices: `siphon`, `shovel`, `dug[]`; big top only: `hammer`, `striker`, `moles[]`.
  - Music bookkeeping: `tune`, `prevTheme`, `hammerPrev`.
- **Tier and theme.** `tier()` is the first entry of `CFG.DUNGEON_TIERS` with `region <= maxRegion`: Burrow, Warren, Catacomb, Labyrinth, Nightmare Maze. Each sets size, loop chance, ghost count and speed, and fright time. `theme()` comes from `World.levelOf(region)`: 1 `burrow`, 2 `cathedral`, 3 `temple`, 4 `ufo`, 5 and up `bigtop`. It changes only the look, the ghost colours, the music lookup, and whether the big-top extras appear. `name()` combines them, for example "Cathedral Catacomb".
- **Player grid state** (on `Player`): `gcol`, `grow`, `gdir`, `gprog` (0..1 between cells), `want`/`wantT` (a queued turn), `stopped`, `digs`, `hammerT`, `swings`.
- **Deck state** (on `Deck`): `invaders[]` (`G.enemies` while inside), `pickups[]` (its own falling pickups, separate from `World.pickups`), `wave`, `WAVES`, `form` (the formation offset and direction), `ufo`, `dotPer`/`dotAcc` (the dot purse), `waveLost`, `winT`, `landT`, `PW` (the play width short of the MEGA bar or touch buttons).
- **Lifecycle.**
  1. `Player.updateWorld` detects touch on the entrance ellipse (`CFG.DOOR`). The player must not be invulnerable (god mode is exempt), and no swarm may be active.
  2. `G.enterDungeon(en)` calls `Deck.enter` if `CFG.DECK_MODE` is on and the region is a farm. Otherwise it calls `Dungeon.enter`.
  3. Play: `G.update` calls `Dungeon.update` or `Deck.update` in place of `World.update` and `Enemies.update`.
  4. `exit(success)` returns to the world beside the entrance.

## How it works — the main flows, named by function

- **Generation** (`Dungeon.gen`):
  1. Fill the grid with walls, then carve a recursive-backtracker maze on odd cells.
  2. Knock out extra walls with probability `tier.loops`, to make loops.
  3. Remove dead ends.
  4. Open a 3×5 ghost pen in the middle. Put the start cell at the bottom centre, with the exit (2) below it.
  5. Put dots on every other floor cell, and pellets in the four corners.
  6. Place the Siphon on a plain dot at least `CFG.SIPHON.minDist` of the farthest corridor distance from the start. Place the Shovel the same way, using `CFG.SHOVEL.minDist`. In the big top, also place the Hammer (`CFG.HAMMER.minDist`) and the high striker. Each device removes the dot under it.
- **Entering** (`Dungeon.enter`):
  - Saves `returnPos`, calls `gen()` and `Combat.reset()`, and sets `G.mode = 'dungeon'` and `G.enemies = this.ghosts = []`.
  - Places the player on the start cell facing up and turns auto-fire OFF (`P.firing = false`), so ghosts can be eaten rather than shot.
  - Spawns `tier.ghosts` ghosts in the pen with staggered holds, and calls `Minions.enterDungeon()` (it recalls the divers).
  - Picks a tune: the first of `maze_levelN`, `dungeon_levelN`, `maze_<theme>`, `maze`, `dungeon` that exists in `Music.THEMES`.
  - Shows `Settings.dungeonCard(briefing())`, or messages if the card is turned off.
  - God mode gives 9 digs, and hammer time in the big top.
- **Grid movement** (`Dungeon.movePlayer`, called from `Player.update`):
  - Input becomes a whole-step `want`. Touch uses `Input.mazeDir` and swipes. A released turn request expires after `CFG.DUNGEON.WANT_GRACE` (or `TOUCH_GRACE` on touch).
  - Reversing is allowed at any time. A sideways press within `CORNER` of a cell snaps onto it and turns.
  - At a wall you STOP (`P.stopped`); there is no auto-turn. With digs left, pushing into an inner wall calls `dig()`. The bot never digs.
  - Each cell entered calls `arrive(r, c)`. It eats the dot, starts the Siphon, picks up the Shovel or Hammer, and sends moles back down. On the exit cell it opens the prompt.
- **Ghosts** (`Dungeon.update`):
  - `bfs()` re-runs every 0.25 s (or at once when `distT = 0`). It is a corridor-distance map from the player, or from the Siphon while it runs.
  - At each cell centre a ghost picks the neighbour with the lowest distance 85% of the time. It never reverses unless it is stuck.
  - Frightened ghosts move at 55% speed and mostly pick the highest distance.
  - Touching a frightened ghost eats it: `G.ghosts++`, it respawns in the pen after 6 s, and it feeds the Siphon.
  - Touching a normal ghost does `P.hit(2)`. A normal ghost touching a segment calls `P.removeAt(j, 'ghost')`, with a 2.5 s bite cooldown.
  - Ghosts have hp and can be shot: their `onDeath` gives kills and score, and they respawn after 5 s. They take ×2 damage while frightened (`e.fright` in `Combat.applyHit`).
- **Dots** (`eatDot`): a dot gives +1 `G.dots`. A pellet gives +5 `G.dots`, sets `fright = tier.fright`, and frightens every ghost. `checkClear()` recounts the board; it is also run once a second as a backstop. At zero it sets `winT = 1.6`, shows the ALL DOTS banner, and plays `Music.victory(level)` (the maze tune stops), or the `cleared` chime. When `winT` runs out it calls `exit(true)`.
- **The Siphon.** Its states are `idle → on → spent → (moving) → idle`.
  - `startSiphon` (you step on it) builds a distance-sorted list of plain dots. It pulls `share` of them over `time` seconds (`updateSiphon`), and ghosts path to it while it runs.
  - Afterwards it is `spent`. Eating `need` ghosts (= the tier's ghost count) via `feedSiphon` recharges it.
  - `relocateSiphon` then rolls it as a cart (`cartSpeed`) to the floor cell with the most dots within `reach` steps. It parks on that cell and eats the dot there.
- **The Shovel**: `CFG.SHOVEL.digs` charges. `dig()` opens inner wall cells only; the border and the exit row stay.
- **Big top**: Hammer, high striker and moles.
  - Picking up the Hammer starts `CFG.HAMMER.time` seconds of auto-swings every `HAMMER.every` (`swing()`). A swing eats any ghost in your cell, the cells above and below, and the cell ahead, frightened or not. It also flattens moles, and rings the striker once (+1 ghost, +6 dots, frightens every ghost).
  - The `hammer` tune plays while it lasts; `endHammerMusic` restores the maze tune.
  - Moles (`updateMoles`, `CFG.MOLE`) pop out of dot cells and take the dot after `up` seconds.
- **The exit and "There is no going back!"** `arrive` on the exit cell sets `G.discover = { tip: 'leave', sel: 1 }` and `G.state = 'discover'`, so STAY is pre-selected. (With the bot on, it just calls `exit(false)`.) `Render.drawLeaveCard` draws the card. `Settings.updateDiscover` reads A/D/arrows, Enter/Space, Esc (= STAY), and C/P (the tap targets for EXIT/STAY). EXIT calls `Dungeon.exit(false)`. STAY calls `Dungeon.stay()`, which turns you to face up, resets the grid motion, and shuts the exit for 1.5 s (`exitT`) so a held "down" does not ask again. The exit also counts as a wall for the first 3 s after you enter.
- **Exit** (`Dungeon.exit(success)`):
  - Ends hammer time. Restores music: if the maze tune is still playing, it restarts `prevTheme` or the level theme. After a victory song nothing restarts here, and the perk shop picks the tune.
  - Sets `G.mode = 'world'` and `G.enemies = Enemies.list`, and calls `Combat.reset()`.
  - Puts the player 40 px below the entrance, facing down, with `P.ready = false` and `P.reorient = true` (the world waits for a direction), and calls `G.snapCam()`.
  - Sets `entrance.cd = 2.5` and forces weapons back on.
  - Success: `entrance.used = true`, `G.dungeonsCleared++`, score `500 * (region + 1)`, `P.cleanse()`, `P.rebuild()`, then `Perks.offer()` (perk state, a free pick, the `perk_shop` tune).
  - Failure: `entrance.used = true` as well. The dungeon closes for good, with a message.
  - Both end with `Save.write()`.
- **Saucer deck** (`Deck`):
  - `enter` sets `G.mode = 'deck'`, puts the camera at 0,0, and sets `WAVES` (5, or `CFG.NIGHTMARE.deckWaves`). It sizes the dot purse from the maze tier: `CFG.DECK.DOTS * cols * rows`, shared over every invader by `weight()`. Then it starts wave 1 and the `deck` tune.
  - The body becomes a cannon. `Player.computeSegPos` returns `Deck.formPos()` (rows of 2, 3, 4...). `movePlayer` slides it along x (touch uses four speed zones, `SPEEDS`), and the body catches falling pickups.
  - `nextWave` spawns `formation(w)` bugs through `Enemies.spawnBug` and splices them out of `Enemies.list`. Each one's `onDeath` becomes `Deck.onKill`, which drops dots, ghosts, cow loot and the occasional bullet.
  - `update` steps the formation (it speeds up as the formation thins), launches Galaga dives (`updateDive`), and lets the front invader of each column fire. A cow reaching the band lifts your tail segment. A landing invader tears off the tail; with no segments left you die.
  - The bonus UFO beams up pickups and drops them plus a ghost when shot. A wave with no segment lost drops `CFG.DECK.PERFECT` ghosts.
  - Clearing the last wave: `Music.victory(G.level, true)`, `vacuum` pulls every pickup to you, then `exit(true)`. That exit is the same as the maze's: the entrance is used, the perk shop opens, and the run is saved. An early exit gives no reward.
  - Your shots do ×`CFG.DECK_DMG` damage on the deck (Combat's fire path). `Deck.isSolid` stops bullets at the screen edges. A segment lost on the deck goes through `Deck.drop` (via `Player.removeAt`): its crafted pieces pop out and fall off the floor unless you catch them.

## Interop — reads from / is called by / calls into other systems

- **Called by:**
  - `G.enterDungeon` (main.js) from `Player.updateWorld` (notebook-player.md), the god H key, and `G.playDeck`.
  - `G.update` (`Dungeon.update`, `Deck.update`) and `G.solidAt` (`isWallAt`, `isSolid`) (notebook-core.md).
  - `Player.update` (movePlayer) and `Player.computeSegPos` (`Deck.formPos`).
  - `Settings.updateDiscover` (`exit`, `stay`).
  - Render: `drawDungeon`, `Deck.draw`, labels, and `Render.zoom` reading `Dungeon.cols`/`cell` (notebook-render.md).
  - `Input.stickR`, which uses `Dungeon.cell`. The resize handler calls `Dungeon.center()` and `Deck.resize()`.
  - Perks reads `Dungeon.region` / `Deck.region` for loot tiers (notebook-perks.md). `Player.removeAt` calls `Deck.drop` on the deck.
  - The bot drives mazes (`Bot.dungeon`: BFS to a cell) and the deck (`Bot.deck`) (notebook-tools.md).
- **Calls into:**
  - World (notebook-world.md, notebook-world-loot.md): `levelOf`, `mkPickup`, `pickBullet`.
  - Enemies: `hpScale` (ghost hp), `spawnBug` (deck invaders) (notebook-enemies.md).
  - Combat (notebook-combat.md): `reset`, `tickStatus`, `damage`, `fireEnemy`, `burst`, `particles`, `floaters`, `projectiles`.
  - Player: `hit`, `removeAt`, `initTrail`, `computeSegPos`, `cleanse`, `rebuild`, `collect`, `wouldTake`, `pieces`, `die`.
  - `Minions.enterDungeon` (recalls the riding minions).
  - `Perks.offer`, `Settings.dungeonCard`, `Save.write`.
  - Music (notebook-audio.md): `start`, `stop`, `victory`, `levelTheme`. SFX sounds: `enter`, `dot`, `pellet`, `eat`, `tab`, `mole`, `bell`, `squeak`, `cleared`.
- **Music names it looks for:** `maze_level1..5`, `dungeon_level1..5`, `maze_burrow|cathedral|temple|ufo|bigtop`, `maze`, `dungeon` (maze); `hammer`; `deck`; and for the victory song `dungeon_victory_levelN`, `maze_victory_levelN`, `dungeon_victory`, `maze_victory` (with `deck_victory_levelN` and `deck_victory` first for the deck).
- **CFG keys:** `DUNGEON_TIERS`, `DUNGEON` (`PLAYER_SPEED`, `CORNER`, `WANT_GRACE`, `TOUCH_GRACE`, plus the touch keys read by input.js), `SIPHON`, `SHOVEL`, `HAMMER`, `MOLE`, `DOOR`, `DECK`, `DECK_MODE`, `DECK_DMG`, `NIGHTMARE.deckWaves`, `NIGHTMARE.dive`, `MOBILE_SCALE`, `HEAD_R`, `SEG_R`, `LOOSE.time`, `PLAYER_SPEED` (deck).
- **Save fields:** it writes no fields of its own. It changes `G.dots`, `G.ghosts`, `G.score`, `G.kills` and `G.dungeonsCleared`, which save.js persists. Entrances are not saved: a resumed run generates a fresh world with fresh entrances. `G.deckStats` (entered, cleared, secs, lost) is only for the trial log.

## Invariants and gotchas

- `G.enemies` is swapped: to `Dungeon.ghosts` or `Deck.invaders` inside, and back to `Enemies.list` on exit. Anything that holds a reference to `G.enemies` across a mode change goes stale. `Combat.reset()` runs on every enter and exit.
- Deck invaders are bug objects removed from `Enemies.list`. Their default bug loot is replaced by `Deck.onKill`.
- Grid cells: 1 = wall, 2 = exit. `distFrom` and the ghosts never enter the exit cell. The exit counts as a wall while `exitT > 0`.
- `checkClear` recounts from the board on purpose. Any new path that removes dots must still call it, or a level can end with an empty board that never clears.
- Leaving early also sets `entrance.used`: "no way back in". The bot never sees the prompt; it leaves at once.
- Auto-fire is OFF inside mazes and is forced back ON at every exit. The deck forces `P.firing = true` every frame.
- `theme() === 'ufo'` (level 4 mazes) only appears when `CFG.DECK_MODE` is false.
- `Dungeon.center()` depends on `CFG.W` and the touch zoom. It must re-run on resize (main.js does this, and Settings does it on a size change).
- On exit, `P.ready = false` holds the world still until the player presses a direction.
- Dungeon ghost hp comes from `Enemies.hpScale(region)`, so it is region-scaled like the overworld.

## Tuning — where its numbers live

- js/config.js: `CFG.DUNGEON_TIERS` (`cols` and `rows` must be odd), `CFG.DUNGEON`, `CFG.SIPHON`, `CFG.SHOVEL`, `CFG.HAMMER`, `CFG.MOLE`, `CFG.DOOR`, `CFG.DECK`, `CFG.DECK_MODE`, `CFG.DECK_DMG`, `CFG.NIGHTMARE`. Any of these can be overridden in js/tuning.js (editor/config/).
- Hard-coded in code:
  - dungeon.js: ghost hp `18 + 6 * hpScale`; fright speed ×0.55; ghost chase 85% (frightened 75% flee); BFS every 0.25 s; respawn holds of 6 s, 5 s and 2.5 s; ghost damage 2; dots 1 and pellets 5; clear score `500 * (region + 1)`; `exitT` of 3 s on entry and 1.5 s after STAY.
  - deck.js: `WAVES`, `BAND`, `formation`, `weight`, the UFO hp, dive speeds, and the spacing `DX`/`DY`.
- Entrance odds: `World.genRegion` (60% in region 0, always afterwards, never in an arena).

## Testing — how to see it working

- Dev server http://localhost:8765/.
- God mode (G, or `?god=1`): H hops into the nearest unused entrance (the deck on the farm), with 9 digs and the hammer. L, O, X and `.` warp to levels 2, 3, 4 and 5 to see each maze theme.
- `?deck=1` warps to the farm with a level-4 loadout and boards the deck at once (`G.playDeck`).
- `?bot=1&brain=scripted` runs the autopilot, which enters dungeons and paths mazes with BFS.
- Console:
  - `Dungeon.tier()`, `Dungeon.theme()`, `Dungeon.dotsLeft`, `Dungeon.siphon.state`.
  - `for (const r of Dungeon.dots) r.fill(0); Dungeon.checkClear()` forces a clear.
  - `CFG.DECK_MODE = false` sends the next farm entrance to a maze (`ufo` theme).
  - `Deck.wave`, `G.deckStats`.
- Settings: turn the dungeon card off to see the briefing as messages instead.

## Change log hooks

Update this notebook in the same commit as any change to the system's behaviour.
