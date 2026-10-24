# Player notebook

The player is the centipede: a head that the player steers, followed by a chain of segments that trace its trail. Each segment is a gun. The segments are the build. Losing one is losing health and firepower at the same time. In code it is the single global `Player` (js/player.js), extended with `Object.assign(Player, {...})` by five more files that load right after it. `Minions` (js/minions.js) is a small separate object for tamed parasites that ride as extra segments. The drawing lives on `Render` in js/render-player.js.

**Files:**

| file | what it holds |
|---|---|
| js/player.js | the `Player` object and its fields; `reset`, the trail (`initTrail`, `pushTrail`, `computeSegPos`, `ballPos`), `collect` (pickups), `update`, `predict`, `oddities`, `updateWorld` (steering, walls, obstacles, pickups, dungeon doors), `bounce` |
| js/player-build.js | `resolveBuild` (jam, piping, synergy), `synRuns`, `synText`, `mkSeg`, `isMinion`, `weaponCount`, `moveSeg`, `toBag`, `rawDps`, `tuneFusions`, `estimateDps`, crafting (`idAt`/`takeAt`/`putAt`, `canCraft`, `craftCost`, `fusions`, `craft`) |
| js/player-loadout.js | the blueprint / padlock (`lock`, `unlock`, `snapshot`, `arrange`, `rebuild`, `plan`, `build`, `rebuildPlan`, `blueprintLines`), the bag (`bagGroups`, `bagFull`, `stackFull`, `fromBag`, `destroy`), `addSegment`, `removeAt`, `shrinkTo` |
| js/player-hurt.js | `pieces`, `scatter` (lost segments burst into their parts), `loseTail`, `CRASH`/`isCrash`, `shed`, `cleanse`, `infest`, `shakeOff`, `hit`, `die` |
| js/player-fire.js | `fireUpdate`, `fireFrom` (aim, lead, the wall as a target), `pellet`/`firePellet` (the saucer deck), `wallTarget` |
| js/player-gear.js | head gear: `isGear`, `ownsGear`, `gainGear`, `equipGear`, `gearUpdate`, `gearUnleash`, `gearAhead`, the Front Hammer (`hammerEl`, `hammerHit`), `gearMult` |
| js/minions.js | `Minions`: tamed parasites as slot-free segments, their spit, and the tail minion that launches itself at big targets |
| js/render-player.js | `Render.drawPlayer`, `drawRider` (parasites on segments), `drawHelmet` (Fast and Hard), `drawGear` (hats), `drawBall` (Lightning Storm), `drawLichSkulls`, `drawGiantGauge` |

## Model — the state it owns, and its lifecycle

**A segment** is a plain object: `{ kind: 'weapon', id, cd, inflight, jam, para }`, made by `Player.mkSeg(id)`.
- `id` is a key into `BULLETS` (js/config.js). The id is the whole identity of the segment: its weapon, colour, tier, elements. Nothing else about the gun is stored on the segment.
- `kind` is always `'weapon'` today. The weapon's own delivery type is `BULLETS[id].kind` (`shot`, `bounce`, `homing`, `chain`, `pulse`, `mine`, `orbit`, `boomerang`, `trail`, `head`, `minion`...). Do not confuse the two.
- `cd` is the reload clock. `inflight` counts this segment's bullets in the air (Combat decrements it). `maxInflight` comes from the bullet.
- `jam`, `pipe`, `pipeBoost`, `charged`, `maxInflight` are derived. `resolveBuild` rewrites them. Never set them by hand.
- `para` is a parasite riding the segment: `{ id, t, hold, seed }`. It counts down in `Player.update` and is shaken off at 0.

**Kinds of segment by `BULLETS[id]`:**
- weapons: everything with no `minion` flag. They take a body slot.
- minions: ids `minion_<parasite>`, made at load in js/config.js from `PARASITES` (`kind: 'minion'`, `minion: true`). They take no slot. They sit at the tail.
- head gear (`kind: 'head'`) is never a segment. It lives in `Player.gear` and `Player.gears`.

**The slot cap.** `Player.maxSegs` counts weapon segments only (`weaponCount()`). It starts at `CFG.MAX_SEGMENTS` (3) and only the Bigger Body perk raises it. Minion segments are extra, capped by `Minions.cap()` = `CFG.MINIONS.max` + `G.mods.minions`.

**Other state on `Player`:**
- position: `x, y, angle, angVel`, `trail` (points `{x, y, d}` with cumulative distance `d`), `segPos` (per segment `{x, y, a}`), `wallX` (last x, for wall tunnelling checks).
- `head`: a hidden segment (id `CFG.HEAD_SPIT`, a pebble) that fires only when no weapon segment can. `spitting` says it is on.
- `bag`: a flat array of bullet ids. It is shown as stacks by `bagGroups()` (alphabetical).
- `blueprint` (array of ids) and `autoRebuild` (the padlock). `lockHint` remembers the one-time tip.
- `gear` (worn hat id or null), `gears` (the hat rack), `gearT`, `gearCharge`, `gearFreezeT`.
- oddity timers: `giant` (Eat Me cake, and the tractor with `tractor: true`), `tiny` (Drink Me), `shroom` (double damage), `mirror` (laughing gas reverses steering). `flight` is the cannon ride.
- `firing` (Space toggles), `invuln`, `alive`, `ready` (the overworld waits for the first direction), `reorient`/`reorientText`, `headSlow`, `speedMult`, `inLake`.
- derived synergy: `syn` (elements switched on), `synN` (best run length per element), `synAt` (per segment, which elements glow).
- `maxLen` (longest body this run; also mirrored to `G.maxLen`). `lastCraft` tells the workbench where a fusion landed. `lastHit` aims the scatter.
- `digs`, `swings`, `hammerT`, the dungeon grid fields (`gcol`, `grow`, `gdir`, `gprog`, `want`) belong to other systems that borrow `Player`.

**Lifecycle.** `G.start` calls `Player.reset(x, y)`: three `CFG.START_SEGMENTS`, empty bag, no blueprint, no hats, cap back to `CFG.MAX_SEGMENTS`. `Save.resume` overwrites fields after that. Each frame in play, main.js calls `Player.update(dt)`. Death is `Player.die`, which calls `G.gameOver`.

## How it works — the main flows, named by function

**Movement and the trail.** `Player.update` runs `oddities`, then moves by mode: `updateWorld` in the overworld, `Deck.movePlayer` on the saucer deck, `Dungeon.movePlayer` in a dungeon. Then `pushTrail` and `computeSegPos`. Segments sit every `CFG.SEG_SPACING` px of trail distance behind the head. On the deck, `computeSegPos` takes `Deck.formPos()` instead. During Lightning Storm (`G.bolt`) `ballPos` curls the body round the head. `initTrail(x, y, angle, coil)` resets the trail after a warp; `coil` stacks the body on the head (mazes).

**Steering (`updateWorld`).** Until `ready`, nothing moves; the first direction starts the crawl. The angle turns toward the input at `CFG.TURN_RATE`. Speed is `CFG.PLAYER_SPEED` times `speedMult`, `G.mods.speed`, lake (0.6), giant or tiny. Then in order: mime walls push you out; the thorn hedge and region walls call `bounce`; a swarm seals its region; trees, boulders, graves, cannons and solid deco call `hit`; pickups call `collect`; dungeon entrances call `G.enterDungeon`.

**`predict(t)`** says where the head will be in t seconds, following the current turn arc. Bosses aim at it.

**Pickups (`collect`).** Weapons go through `addSegment` (body if there is room, else the bag). Hats go to `gainGear`. Also megas (`Mega.add`), cleanse, ghosts, dots, eggs (`Minions.hatch`), cake, potion, mushroom. Every pickup ends with `rebuild()`. `wouldTake` decides before touching: a full stack or a full bag leaves the item on the ground with a one-time message.

**`addSegment(seg, force)`.** New weapons go in front of the riding minions (`firstMinion()`), so minions stay the tail. If the body is full, or a lock is set and this piece is not wanted by the lock (`lockedShort`, `wantedNow`), it goes to the bag. `force` (a loose piece of your own body) may overflow the bag.

**`resolveBuild`.** The one place that derives segment state. Call it after any change to `segments`, `gear` or a `para`.
- jam: the first segment with a parasite, and every segment behind it, cannot fire. A slug also slows you.
- piping: segment i takes a "pipe" from segment i-1. Ricochet from a bouncer ahead, split from shards, homing from a moth into a plain shot, burst, dry from a plain spear, dazzle from a laser, else the first element. An element pipes only into a bullet with no element of its own; behaviours pipe into anything that lacks them. The worn hat's `gear.pipe` feeds segment 0 the same way.
- synergy: `synRuns` finds runs of side-by-side segments sharing an element (minions break runs; `SYN_EXTRA.bone` counts spears and dust). A run of `CFG.SYNERGY.need` lights that element (`inRow: true`). Every gun behind a lit run gets that element's charged pipe in `seg.charged` (bone gives `dry`); runs stack down the body, so a gun behind a jolt run and a taint run carries both. A segment whose own `pipe` is in `charged` also gets `pipeBoost`. A newly lit element prints a message and plays `synergy`.

**Firing (`fireUpdate`, `fireFrom`).** One bullet in flight per segment (`inflight < maxInflight`), unless Bullet Storm (`G.storm`). If no weapon segment can fire, the head spits. `fireFrom` chooses by kind: minions call `Minions.spit`; the deck fires `firePellet` (straight up, the segment's raw dps as one pellet); `trail` walkers drop a cloud; everything else picks the nearest aimable enemy in range and leads it. `wallTarget` turns guns on a breakable wall for 2.5 s after you bump it, or when there is nothing else to hit. Chain, pulse, mine and boomerang cannot break walls, so they fire their pellet at the wall instead. Damage multiplier: `G.powerMult`, mushroom, potion, rubber chicken. `+burst` pipe fires a second unowned shot.

**Getting hurt (`hit`).** Ignored while invulnerable, flying or giant. A crash (`isCrash` matches the cause text against `CRASH`) bounces off without loss if you wear any hat, or by `hardHeadChance(G.mods.hardHead)`. The Front Hammer also chips the wall. Otherwise `loseTail(power)` removes tail segments. A body longer than `CFG.BALANCE.longBody` gets shorter invulnerability. An empty body is the LAST STAND: the next hit calls `die`.

**Losing a segment (`removeAt`).** In the overworld, unless the cause is `shed` or `sacrifice` or it was a minion, `scatter` throws the segment's base parts (`pieces`, recursive through `partsOf`) and its fusion dots onto the ground as `loose` pickups with a fuse of `CFG.LOOSE.time`. Crashes throw them away from what you hit; attacks spray them all round. Distance grows with your dps and level. On the deck `Deck.drop` is used instead. If a lock is set, `rebuild` runs at once.

**Parasites.** `infest(i, id)` sets `para` (i = -1 bites the head: `headSlow`). `shakeOff` clears it. `cleanse` clears all.

**The blueprint (the padlock).** `lock` snapshots the weapon segments in order into `blueprint` and sets `autoRebuild`. `rebuild` (after pickups, dots, dungeons, closing the workbench) finds missing blueprint entries that are fusions, checks with `plan` that the parts and dots are in hand without eating other blueprint entries, builds them with `build` (recursive `craft` calls, bag first, `spare` protects body pieces the lock needs), then `arrange` puts the body back in blueprint order. Extras not in the lock go to the bag. Base bullets are never made, only found. `rebuildPlan` and `blueprintLines` are read-only previews for the workbench and the world renderer (wanted pickups glow).

**Crafting.** A location is `{ where: 'body'|'bag', i }`. `canCraft(A, B)` asks `findRecipe`. `craft(A, B)` charges `craftCost` (`CFG.CRAFT_COST[tier]`, default 20) dots. The result keeps the body ingredient's slot; two bag items put it in the bag (place it from there). Either way it is marked fresh (`seg.fresh`, or its kind in `freshBag`) for the workbench's lightning frame. A hat result goes to `gainGear`. `lastCraft` records where it went. `fusions()` lists every recipe you could make now, memoised on body + bag + dots + hats; affordable first, then by tier.

**Head gear (hats).** `BULLETS` entries with `kind: 'head'` and a `gear` block. Types: `cone` (Flamethrower, Pumpkin Head; Santa Hat is an ice cone), `ram` (Bone Helmet), `hammer` (Front Hammer), `arc` (Thundercap), `spores` (Mushroom Cap). `gearUpdate` runs only in the overworld while moving. With Space toggled off, `gearCharge` builds up to `CFG.GEAR.charge.max`; turning fire back on calls `gearUnleash` if at least `charge.min`. Hats also detonate your own armed mines ahead (`CFG.GEAR.mines`).
- `gears` is the hat rack. `gainGear(id)`: a new hat goes on the rack, and on the head only if it is bare. A hat you already own is scrapped for `CFG.TRASH_DOTS` dots. `equipGear` swaps for free. Fast and Hard copies boost hat damage (`gearMult`) instead of the bonk chance.

**Oddities (`oddities`, overworld only).** `giant`: invulnerable, crushes what touches the head (`CFG.GIANT`), flattens trees; with `tractor` the engine sound runs and fuel beeps. `tiny`: faster, enemy shots miss (checked elsewhere), less damage. `shroom`: double damage, rainbow particles. `mirror`: input reversed in `updateWorld`.

**Minions.** `Minions.add` pushes a `minion_<id>` segment at the tail. Sources: `hatch` (eggs, god-mode Z), `tame` (head-butting a frozen parasite, the Brood perk), `adopt` (Hive Mind recruits). A minion fires via `Minions.spit` (a plain bullet at `CFG.MINIONS.spitMult`). `Minions.update` launches the tail minion at a boss, unique or beefy rare within `launchRange`: it leaves the body and becomes an allied parasite enemy (`minion: true`, `kami`) driven by `Minions.updateWorld` until it explodes.

## Interop — reads from / is called by / calls into other systems

- **main.js / G (notebook-core.md):** `G.start` calls `Player.reset`; the loop calls `Player.update`; `G.gameOver` from `die`; `G.enterDungeon`; `G.msg`, `G.banner`, `G.shake`, `G.flash`, `G.tint`. Level end may call `shrinkTo(CFG.WIN_RESET)` (0 = off). God presets call `Player.lock(true)`. main.js calls `Player.tuneFusions()` once at load.
- **Workbench (notebook-workbench.md):** `Build` edits the body through `moveSeg`, `toBag`, `fromBag`, `destroy`, `craft`, `lock`/`unlock`, `equipGear`. `Build.close` calls `rebuild`. `craft` sets `Build.need` when dots are short.
- **Combat (notebook-combat.md):** `Combat.fire`, `burst`, `damage`, `addStatus`, `dry`, `resist`, `explode`, `ramp`, `clouds`, `particles`, `arcs`, `floaters`, `near`. Combat decrements `seg.inflight`, and calls `removeAt` for spit and clouds, `Minions.hurt` for divers.
- **Enemies / bosses (notebook-enemies.md, notebook-bosses.md):** they call `Player.hit`, `removeAt` (bites, slurps), `infest`, read `predict`. `Enemies.spawnParasite` makes divers; `Enemies.updateParasite` hands them to `Minions.updateWorld`; `Enemies.update` calls `Minions.worldTick`.
- **World (notebook-world.md, notebook-world-loot.md):** reads `World.trees`, `boulders`, `graves`, `cannons`, `regions[].deco`, `pickups`, `entrances`, `wallAt`, `wallBetween`, `damageWall`, `inLake`, `updateFlight`, `mkPickup`, `dropDots`. World-discover sets `giant` + `tractor`. The world renderer reads `rebuildPlan().wanted`.
- **Dungeon / deck (notebook-dungeon.md):** `Dungeon.movePlayer`, `Deck.movePlayer`, `Deck.formPos`, `Deck.drop`. Leaving a dungeon calls `cleanse` and `rebuild`.
- **Perks (notebook-perks.md):** reads `G.mods` (`speed`, `cd`, `range`, `bspeed`, `elem`, `twin`, `invuln`, `paraHold`, `hardHead`, `minions`), `Perks.twinMult`, `Perks.magnetRadius`; `Mega.add`; the Sacrifice mega calls `removeAt(i, 'sacrifice')`.
- **Audio (notebook-audio.md):** `SFX.play`, `SFX.attack`, `SFX.engine`/`engineOff`, `SFX.tone`.
- **Settings:** `Settings.discover` (first-time cards), `Settings.swarmCard`.
- **Render (notebook-render.md):** `Render.drawPlayer` from js/render.js; uses `legs`, `eyes`, `mouth`, `blush`, `shine`, `circle`. `Render.aimable` limits auto-aim to on-screen enemies.

**CFG keys read:** `START_SEGMENTS`, `MAX_SEGMENTS`, `HEAD_SPIT`, `SEG_SPACING`, `HEAD_R`, `SEG_R`, `PLAYER_SPEED`, `TURN_RATE`, `HEDGE`, `WORLD_H`, `WALL_T`, `WALL_SECTION`, `REGION_W`, `DOOR`, `INVULN`, `BAG_MAX`, `STACK_MAX`, `CRAFT_COST`, `TRASH_DOTS`, `SYNERGY`, `DRY`, `PIPE_BURST`, `STORM_PROJ`, `LOOSE`, `BALANCE` (`fuseFloor`, `fuseBoostMax`, `longBody`, `longInvuln`, `longInvulnMin`), `GIANT`, `TINY`, `SHROOM`, `LIGHTNING.scale`, `GEAR`, `MINIONS`, `WIN_RESET`. Data tables: `BULLETS`, `RECIPES`, `findRecipe`, `ELEMENTS`, `SYN_EXTRA`, `synInfo`, `PARASITES`, `PIPES`.

**Save fields (written by `Save.write` in js/save.js):** `segments` (weapon ids only; minions are not saved), `bag`, `blueprint`, `locked` (= `autoRebuild`), `maxSegs`, `head` (= `head.id`), `gear`, `gears`, `spitting`, plus `G.maxLen` and `G.dots`.

## Invariants and gotchas

- **Segments are the build and the health bar.** Order matters: piping, synergy runs and jams all depend on position. Any code that reorders or splices `segments` must call `resolveBuild()` (and `computeSegPos()` if positions are read before the next frame).
- **Minions stay at the tail.** Insert weapons at `firstMinion()`, never `push`. `arrange` and `shrinkTo` keep this. A minion can not go in the bag (`toBag` refuses).
- **The cap counts weapons only.** Use `weaponCount() < maxSegs`, not `segments.length`.
- `seg.kind` is always `'weapon'`; type checks use `BULLETS[s.id].kind` or `.minion`. `fireFrom` tests `kind === 'minion'`, `isMinion` tests the `minion` flag; both mark the same entries today.
- A lock "owns its empty slots": with `autoRebuild` set, new pickups go to the bag unless the lock wants them. A short locked body is a deliberate choice.
- `rebuild` only makes fusions. A lost base bullet must be picked up again (its scattered pieces are `loose` and always fit).
- `blueprint` excludes minions. `lockMatches` compares weapon ids in order.
- `fusions()` is memoised on a key string. If you add another input that changes the answer, add it to the key.
- `tuneFusions` mutates `BULLETS[r].dmg` at load (`R.tuned` records the factor). Damage numbers in config.js are not the final ones.
- The bag holds `CFG.BAG_MAX` kinds and `CFG.STACK_MAX` of one kind (both unlimited in god mode via `bagMax`/`stackFull`).
- Hats are armour: with any hat on, every crash bounces. Hats only act in the overworld.
- `gainGear` with `quiet` returns false for a duplicate and does NOT scrap it for dots; `craft` relies on `fusionsRaw` hiding recipes for hats you already own.
- Several systems borrow `Player` fields (`digs`, `hammerT`, `gcol`...). Do not remove them without checking the dungeon and circus code.

## Tuning — where its numbers live

All in `CFG` in js/config.js, overridden by js/tuning.js. Body: `MAX_SEGMENTS`, `START_SEGMENTS`, `SEG_SPACING`, `PLAYER_SPEED`, `TURN_RATE`, `INVULN`. Bag and crafting: `BAG_MAX`, `STACK_MAX`, `CRAFT_COST`, `TRASH_DOTS`, `BALANCE.fuseFloor`/`fuseBoostMax`. Synergy: `SYNERGY`. Loss: `LOOSE`, `BALANCE.long*`. Oddities: `GIANT`, `TINY`, `SHROOM`. Hats: `GEAR` plus each hat's `gear` block in `BULLETS`. Minions: `MINIONS`. Bullet stats and recipes: `BULLETS`, `RECIPES` in js/config.js. A hat's numbers are its `gear` block; its bullet stats are zero.

## Testing — how to see it working

- Dev server: `python3 tools/devserver.py`, then http://localhost:8765/.
- `?god=1` starts in god mode (invulnerable, cap 12, every bullet in the bag). In god mode: digit keys load `GOD_PRESETS` (each locks its body), Z hatches a minion, V toggles mortal so `hit` and `scatter` can be tested. G toggles god mode outside the workbench.
- `?bot=1&brain=scripted` runs the autopilot without the model; good for soak tests of loss and rebuild.
- Console: `Player.segments.map(s => s.id)`, `Player.bag`, `Player.blueprint`, `Player.syn`, `Player.synAt`, `Player.segments.map(s => s.pipe)`, `Player.estimateDps(1, 1)`, `Player.fusions()`, `Player.rebuildPlan()`, `Player.gears`.
- Force things: `Player.giant = 8; Player.tractor = true`, `Player.shroom = 8`, `Player.mirror = 5`, `Player.gainGear('thundercap', true)`, `Minions.add('leech')` (any `PARASITES` key: leech, slug, jammer), `Player.hit(1, 'a tree')`, `Player.infest(0, 'slug')`.
- Open the workbench (P or C) to see piping chips, synergy and the gallery dps.

## Change log hooks

Update this notebook in the same commit as any change to the system's behaviour.
