# World loot notebook

Loot is everything that lies on the overworld ground for the centipede to crawl over: bullets (each one becomes a body segment or goes into the bag), head gear, crafting dots, Cleanse, mega cards for the mega deck, Broodmother eggs, and the circus oddities (cake, potion, mushroom). In code, the drop rules live on `World` in js/world-loot.js, every item is a plain object in the one array `World.pickups`, `World.update` moves them (bob, skid, magnet, fuse), `Player.collect` applies them, and `Render.drawPickups` draws them. Loot only comes from kills and from a few discoverables; nothing is scattered at generation except the odd Cleanse and the circus oddity.

**Files:**

| file | what it holds |
|---|---|
| js/world-loot.js | `World.pickBullet(tier)`, `World.mkPickup`, `World.dropDots`, `World.dropAt`, `World.crowdBonus`, `World.loot(e, L)` |
| js/world.js | the pickup half of `World.update` (lift, bob, skid, fuse, the magnet, sweeping dead ones); generation-time Cleanse and circus oddity |
| js/world-discover.js | non-kill sources: coffins, crop circles, the fortune teller's rain card |
| js/player.js | `Player.collect(pk)` (what each kind does) and the pickup-contact loop in `Player.updateWorld` |
| js/player-loadout.js | `Player.wouldTake`, `wantedNow`, `addSegment` (where a picked-up bullet goes) |
| js/player-hurt.js | `Player.scatter`: a lost segment bursts into loose pickups |
| js/render-world.js | `Render.drawPickups` |

## Model — the state it owns, and its lifecycle

- **A pickup** (from `World.mkPickup(x, y, kind, id, region)`): `{ x, y, kind, id, region, bob, r: 11, alive: true, vx: 0, vy: 0 }`. Optional fields set by callers: `value` (dots), `loose` (a piece of your own body: always fits), `fuse` (seconds before it fizzles), `lift` (being pulled up the Brain Overlord's tractor beam: `{ t, d, tx, ty }`), `warned` (the "no room" message was shown), `dropped`/`away` (see gotchas).
- **Kinds**: `weapon` (id = a `BULLETS` key; head gear is a `weapon` whose BULLETS entry has `kind: 'head'`), `dot` (id `'dot'`, `value` n), `cleanse`, `mega` (id = a `MEGAS` key), `egg`, `cake`, `potion`, `shroom` (the three oddities are created with id `'oddity'`), and `ghost` (only on the saucer Deck's own pickup list, see notebook-dungeon.md). `drawPickups` has a white-cross fallback for any other kind.
- **Lifecycle**: pushed into `World.pickups` by a dropper; moved each frame by `World.update`; collected by `Player.collect` (sets `alive = false`, or puts it back to `true` if it could not be used); swept out of the array by `World.update` the next frame. `World.reset` empties the list. Pickups are never saved: a resumed run starts with an empty ground.
- **G fields it touches**: `G.pity` (dry-streak counter, saved), `G.lootScale` and `G.crowd` (set by the spawn tick in `World.update`), `G.dots` (saved), `G.mega` (saved), `G.mods.luck` and `G.mods.magnet` (perks, saved via `mods`).

## How it works — the main flows

- **A kill's roll, `World.loot(e, L)`**: called from each monster's `onDeath` with its loot table `L = { chance, tier, n }` (`BUGS[type].loot`, inline tables in enemies-snakes.js and enemies-parasites.js, `UNIQUES[id].loot`). In order:
  1. Head gear: chance `CFG.GEAR.drop`, `.rareDrop` or `.uniqueDrop`; a random `kind: 'head'` BULLETS entry allowed at `G.level` (crafted head gear included).
  2. Mega: a unique in the overworld rolls `CFG.MEGA_DECK.uniqueDrop` for a `mega` pickup of a mega you do not own (`Mega.missing()`) and that is not already lying on the ground.
  3. Bullets: `n` rolls of `chance * G.mods.luck`, each a `dropAt` near the body. `chance` is `L.chance x CFG.LOOT.RATE (only if L.chance < 1) x G.lootScale x e.lootMult`. A rare (`e.rare`) always drops (`chance = 1`) one tier up.
  4. Pity: if nothing dropped (and it is not a swarm bug), with probability `G.lootScale` the counter `G.pity` goes up; at `CFG.LOOT.PITY` a drop is forced. Any drop resets it.
  5. A dot: non-uniques roll `CFG.DOT_DROP.chance x luck x lootScale x lootMult` for one dot.
- **`World.dropAt(x, y, region, tier)`**: shifts the tier up (`CFG.LOOT.TIER_UP`) or else down (`TIER_DOWN`), clamps to 1..3, then 3% Cleanse, otherwise a `weapon` of `World.pickBullet(tier)`.
- **`World.pickBullet(tier)`**: weighted pick by `BULLETS[k].weight` among non-crafted bullets of that tier whose `minLevel` (if any) is reached; falls back to all tiers when the tier is empty. Also used by coffins (tier 3), crop circles and the fortune card (tier 2), the Deck, and god-mode presets.
- **`World.dropDots(x, y, region, n)`**: splits n into up to 6 piles scattered 10-34 px, each a `dot` pickup with `value` (drawn bigger and numbered when > 1). Used by kills, rares (`Enemies.makeRare` adds 2-4 via `CFG.DOT_DROP.rare`), uniques (`UNIQUES[id].dots`), coffins, the fortune rain card, and `Player.scatter` (the dots a lost fusion cost).
- **The crowd bonus, `World.crowdBonus()`**: `round(crowdPerDoubling x log2(Player.estimateDps(1,1) / crowdRef))`, clamped to 0..`crowdMax`. A stronger build gets more monsters alive at once; the spawn tick then sets `G.lootScale = base / (base + extra)`, so every kill's loot is rarer by the same ratio. Net loot per minute stays roughly flat as kills speed up.
- **Other sources**: boss deaths (enemies-bosses.js: three tier-3 `dropAt`s, a Cleanse, spears from the Skull King, pins from the Clown Car, abducted segments returned by the Overlord); the Hornet Queen and other `BUGS[].boss` drop a Cleanse (enemies-bugs.js); unique eggs (`UNIQUES[id].egg`, enemies-uniques.js); the crab and player hurt throw `loose` pieces of your body with a `fuse` of `CFG.LOOSE.time`; at generation a 25% Cleanse per non-arena region and a 70% oddity per circus region.
- **Per-frame physics** (in `World.update`): a lifted pickup slides to the beam and vanishes; anything over 1600 px from the player is frozen; others bob; a thrown pickup (vx/vy) skids with `e^-4t` friction and is kept inside its own region (left hedge to 50 px before its wall), off the top and bottom hedges and out of lakes; a `fuse` counts down and the pickup fizzles (one message lists fizzled bullet names); a `weapon` the player cannot take does not move; everything else within `Perks.magnetRadius()` drifts toward the head.
- **Pickup, `Player.updateWorld` → `Player.collect`**: contact is `HR + pk.r`. A weapon that `wouldTake` refuses, or an egg with the minion roster full, stays and warns once. `collect` by kind: head gear → `gainGear` (hat rack); weapon → `addSegment` (body if there is room and the locked build allows it, else the bag) and a first-time `Settings.discover` card; mega → `Mega.add`; cleanse → `Player.cleanse`; dot → `G.dots += value`; egg → `Minions.hatch`; cake → `Player.giant`; potion → `Player.tiny`; shroom → `Player.shroom` (x`CFG.SHROOM.dmg` damage in player-fire.js). It always ends with `Player.rebuild()` so a locked build re-forms from new parts and dots.
- **Drawing, `Render.drawPickups`**: weapons as a hexagon with `Icons.tile` (dimmed when `wouldTake` is false); a dashed "wanted" ring when the locked blueprint needs that id (`Player.rebuildPlan().wanted`); a countdown ring and blinking for fused pieces; dots sized by value; labelled sprites for cake (EAT ME), potion (DRINK ME), shroom (?), mega (MEGA: name), egg (EGG).

## How loot feeds the build, the workbench and perks

- Bullets are the build: each `weapon` pickup is one segment or one bag entry; the workbench fuses two into one (notebook-workbench.md).
- Dots are the crafting currency: fusing costs dots (`Player.craftCost`), trashing on the workbench refunds `CFG.TRASH_DOTS`. Dungeons are the other big dot source.
- Ghosts (the perk shop currency, `G.ghosts`) do not drop in the overworld; they come from dungeons and the Deck (notebook-perks.md, notebook-dungeon.md).
- Perks feed back in: Lucky Shell multiplies `G.mods.luck` (bullet and dot chances, not head gear or megas), Loot Magnet widens `Perks.magnetRadius()`.
- The mega deck grows from `mega` pickups and the perk shop; a new mega only goes live if the bar is empty (`Mega.add`).

## Interop

- **Called by**: every monster `onDeath` (enemies-bugs.js, enemies-snakes.js, enemies-parasites.js, enemies-uniques.js) → `World.loot`; enemies.js `makeRare` → `dropDots`; enemies-bosses.js → `dropAt`, `mkPickup`; player-hurt.js `scatter` → `mkPickup`, `dropDots`; enemies-crab.js → loose pieces; enemies-overlord.js sets `pk.lift`; deck.js uses `mkPickup` and `pickBullet` for its own list; main.js god presets → `pickBullet`; `World.update` → `crowdBonus`.
- **Calls into**: `Player.estimateDps`, `wouldTake`, `collect` (notebook-player.md), `Perks.magnetRadius`, `Mega.missing`, `Mega.add` (notebook-perks.md), `Minions.hatch/count/cap`, `Settings.discover`, `Icons.tile`, `Combat.burst/floaters` (notebook-combat.md), `SFX.play` (notebook-audio.md).
- **CFG keys read**: `LOOT.{TIER_UP,TIER_DOWN,PITY,RATE}`, `DOT_DROP.{chance,rare}`, `GEAR.{drop,rareDrop,uniqueDrop}`, `MEGA_DECK.uniqueDrop`, `BALANCE.{crowdRef,crowdPerDoubling,crowdMax}`, `LOOSE.time`, `MAGNET_BASE` (via Perks), `GIANT`, `TINY`, `SHROOM`, `STACK_MAX` (via `wouldTake`); tables `BULLETS` (`tier`, `weight`, `craft`, `minLevel`, `kind`), `BUGS[].loot`, `UNIQUES[].loot/dots/egg`, `MEGAS`.
- **Save fields**: `pity`, `dots`, `mega`, `mods` (luck, magnet), plus the body and bag the pickups fed (notebook-core.md for save.js).

## Invariants and gotchas

- `G.lootScale` is only recomputed on the overworld spawn tick, and it is global: kills in a dungeon use whatever value was last set.
- `CFG.LOOT.RATE` only thins tables with `chance < 1`; a sure drop (`chance: 1`) stays sure.
- The mega roll requires `G.mode === 'world'` and filters out megas already on the ground, so two copies cannot drop. Uniques skip the generic dot roll because they drop `UNIQUES[id].dots` themselves.
- Pickups farther than 1600 px in x from the player are frozen: their `fuse` does not tick and the magnet does not pull.
- Thrown pickups are clamped to their `region`'s side of its wall, so loot can never land behind a sealed wall.
- A `weapon` the player cannot take is skipped by the magnet as well as by pickup, so it does not trail behind you.
- `collect` must be the only place that consumes a pickup; it reverts `alive` when the target refuses (bag full after all, egg not hatched).
- `drawPickups` culls to the view (`Render.viewW()` / `viewH()`). Weapon pieces (the hexagon, turning as it bobs, and the `Icons.tile` glyph) and dots are sprites (notebook-render.md): a change to how one looks must change its sprite key or painting, not only the live code.

## Tuning — where its numbers live

js/config.js: `LOOT`, `DOT_DROP`, `GEAR`, `MEGA_DECK`, `BALANCE` (crowd keys), `LOOSE`, `MAGNET_BASE`, `STACK_MAX`, `GIANT`, `TINY`, `SHROOM`, `SWARM.loot`, and per-monster `loot` tables in `BUGS`; `UNIQUES` (js/config.js) for unique loot, dots and eggs; `BULLETS[].weight/tier` for which bullet a tier rolls. Overrides go in js/tuning.js through the config editor. Inline numbers: 3% Cleanse in `dropAt`, 6 piles in `dropDots`, magnet speed `(220 + 60 x magnet) x (1 - d/R) + 80` in `World.update`.

## Testing

- http://localhost:8765/?god=1 (or press G): 999 dots, every base bullet in the bag; N skips regions for fresh kills.
- `?bot=1&brain=scripted` plays on its own; bot.js tracks `G.crowd` peaks and pickups.
- Console: `G.pity`, `G.lootScale`, `G.crowd`, `World.crowdBonus()`, `World.pickups.filter(p => p.alive).map(p => p.kind + ':' + p.id)`, `World.dropAt(Player.x + 80, Player.y, World.regionAt(Player.x), 3)`, `World.pickups.push(World.mkPickup(Player.x + 80, Player.y, 'cake', 'oddity', World.regionAt(Player.x)))`, `World.dropDots(Player.x + 80, Player.y, World.regionAt(Player.x), 12)`.

## Change log hooks

Update this notebook in the same commit as any change to the system's behaviour.
