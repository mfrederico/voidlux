# Enemies notebook

The ordinary monsters of the overworld. In player terms: the snakes (bone snakes, pythons, balloon animals), the bug family (gnats, beetles, hornets and every biome's own creatures, from flaming skulls to mimes), the parasite spiders that latch on and jam segments, the rare monsters with gold halos and affixes, and the named uniques. Monsters can also be charmed by a grave ghost, hypnotised by a singing tree, sent giddy by laughing gas or turned berserk. In code terms it is the global `Enemies`, built in js/enemies.js and extended with `Object.assign` by the files below. Its list `Enemies.list` is `G.enemies` in world mode. Bosses live in the same list and the same object but have their own notebook: see notebook-bosses.md.

**Files:**

| file | what it holds |
|---|---|
| js/enemies.js | `Enemies` core: `list`, `reset`, hp scaling (`hpScale`, `nmHp`), `contain`, rares (`rollRare`, `makeRare`), snake stats and spawn weights, `pickParasite`, `rageTarget`, `pressure`, the per-frame `update`; and the load-time `applyBestiaryStats` IIFE |
| js/enemies-snakes.js | `spawnSnake`, `snakeTrail` (the body that follows the head) |
| js/enemies-bugs.js | `pickBug`, `spawnBug`, `shoot`, `updateBug` (all bug AIs), the iguana's `lash` / `updateTongue`, `skullFlame` |
| js/enemies-uniques.js | `uniqueAlive`, `uniquePool`, `spawnUnique`, `maybeUnique` (nightmare) |
| js/enemies-parasites.js | `spawnParasite`, `updateParasite` (hunt, latch, Broodmother, crab toss, taming), `updateAlly` (Hive Mind) |
| js/enemies-spells.js | `giddy`, `charm` / `updateCharmed` / `charmBoom` (grave ghost), `updateHypno` (singing tree) |
| js/render-enemies.js | drawers on `Render`: `drawSnake` / `drawSnakeSprites` / `snakeFace`, `drawBug` / `bugBody`, `drawSkullBug`, `drawMound`, `drawParasite` / `parasiteLegs` / `parasiteBody` / `parasiteFace` / `drawSheep`, `drawHalo`, `drawCharm`, `drawMimeWall`, `statusOverlay`, `hpBar`, `drawStone`, `drawRock`, `drawGhost`, `ghostIcon` |

## Model — the state it owns, and its lifecycle

- `Enemies.list` — every live overworld creature, of `kind` `snake`, `bug`, `parasite`, `boss`, `stone` (crab stones) or `rock` (a summoned boulder). `G.enemies` points at it in world mode; the deck and the dungeon swap `G.enemies` to their own arrays and point it back on exit.
- `Enemies.threat` (0..1, nearest hunting parasite, drives `SFX.skitter`), `skitterT`, `paraSpawn` (parasite spawn shares from the bestiary), `acting` (the monster whose update is running, so `Combat.fireEnemy` can play its attack sound).
- A monster is a plain object built by its spawner. Common fields: `kind`, `type` (bugs) or `id` (parasites), `x, y, angle, speed, r`, `hp, maxhp, power, armor`, `st` (statuses, owned by Combat), `region`, `dead`, `flash`, `stun`, `color, dark, seed`, `baseName` / `label`, `hitCircles()`, `onDeath()`. Bugs add `hidden` / `emerge` (burrowing in), `shotCd`, `windup`, `mode` / `modeT`, `strafe`, `legT`. Snakes add `trail`, `segPos`, `segN`, `spit*`, `biteCd`, `bone / python / balloon`. Parasites add `ti` (target segment, -1 head, -2 none), `huntT`, `ally`, `spitT`.
- Overlay flags: `rare` (the AFFIXES entry), `unique` (UNIQUES id), `ally` / `charmed` (charm seconds; `ally = 1e9` means a permanent minion or rock), `hypnoTree`, `tongue`, `mimeWall`, `swarm`, `lootMult`, `flee`.
- Lifecycle: spawn pushes onto `list`; death sets `dead` (usually via `Combat.kill`, which runs `onDeath`); the next `Enemies.update` splices dead ones out. `Enemies.reset` on new run and on loading. Monsters are not saved: regions repopulate on load.

**Hp.** `hp = base + hpR * hpScale(region)`, where `hpScale` is linear up to region `BOSS_EVERY - 1`, then only `CFG.HP_CURVE` per further region. `nmHp` multiplies by `CFG.NIGHTMARE.hp` in nightmare. Snakes use `12 + 7 * hpScale`. Uniques use their own `hp + hpR * hpScale` times `CFG.NIGHTMARE.uniqueHp` in nightmare.

## How it works — the main flows, named by function

**Spawning.** `World` decides when and where (js/world.js: the initial population when a region is built, and the trickle spawner in `World.update`; js/world-swarm.js for the swarm). It calls:
- `Enemies.spawnSnake(x, y, region, rare)` — variant by region biome (`haunted` bone, `jungle` python, `circus` balloon); stats from `snakeStats` (CFG.SNAKE plus bestiary overrides); spitters from region 2 at 35%.
- `Enemies.pickBug(region)` then `spawnBug(type, ...)` — weighted by each BUGS entry's `weight(region)`; only one `boss: true` bug (the Hornet Queen) per region, else a stinkbug. Bugs start hidden and emerge (mound, or a beam for the farm invaders).
- `Enemies.pickParasite()` then `spawnParasite(id, ...)` — weighted by `paraSpawn`.
- `maybeUnique` (nightmare only: `CFG.NIGHTMARE.uniques` of snake and bug spawns become uniques) and `spawnUnique(id, ...)` from a per-region roll over `uniquePool(region)` (minRegion, `levels`, one of each alive outside nightmare). A unique is built on a base snake, bug or parasite, then restyled and given its own `onDeath` (loot, dots, the Broodmother's egg).
- Rares: `rollRare` (`CFG.RARE`, rising per region) then `makeRare(e, affix)` multiplies hp, size and speed, adds power, armor or extra shots, and wraps `onDeath` to drop dots and announce the kill. Bosses and summoned bugs pass `rare = false`.
- Summons reuse the spawners: bosses (clown car's kamiclowns, overlord's brainlings, skull king's skulls and grave ghosts, serpent's brood, the boss's parasites), js/world-discover.js (hive bees, jack-in-the-box clowns, coffin skulls, the fortune teller's chickens), js/minions.js and js/perks.js (parasites), and the deck (which removes its bugs from `Enemies.list` again).

**The per-frame update, `Enemies.update(dt)`.** For each monster, back to front: splice dead ones; skip ("sleep") anything more than 1.3 region widths from the player; set `acting`; then dispatch in this order:
1. giddy (laughing gas, not bosses, allies or minions) -> `Combat.tickStatus` + `Enemies.giddy`;
2. `parasite` -> `updateParasite`; `boss` -> `updateBoss`; `stone` -> `updateStone` (both in js/enemies-bosses.js); `rock` -> skipped (World moves it);
3. drop a mime's wall unless it is still minding it;
4. charmed (`0 < ally < 1e8`) -> `updateCharmed`;
5. `Combat.tickStatus`; hypnotised -> `updateHypno`;
6. snakes inline: berserk snakes chase `rageTarget`, others steer at the player, spit, `contain`, `snakeTrail`, then contact (head hit, bite off a segment, body touch);
7. otherwise `updateBug`.
Each branch records `e.vx / e.vy` from the position change; the player's aim lead uses it. After the loop: `Minions.worldTick`, the swarm's homing pull on `e.swarm` bugs (`CFG.SWARM.chase`), and the parasite skitter sound.

**`updateBug`.** Emerge first (hidden, untouchable). Then statuses, then berserk (go for `rageTarget`, shoot it), else the AI from `BUGS[type].ai`: `swarm`, `wander`, `keep` (hold 200-300 px, strafe), `dive`, `boss`, `lunge` (earwig), `tongue` (iguana), `mime` (rooted, holds `e.mimeWall`), `ball` (elephant bounces off solids), `kami` (dies after 12 s), plus `e.flee` (Dot Hoarder, chicken). Movement honours freeze, stun and slow. Shooting is a wind-up telegraph (`windup`) then `shoot` (or `lash` for the iguana); blind monsters do not shoot; cooldowns scale by `pressure()`. Contact: chickens die, fragile bugs die, clowns honk, mallet bugs (`bop`) take a segment and burst.

**Parasites.** `updateParasite`: minions go to `Minions.updateWorld`; allies to `updateAlly` (Hive Mind: hunt, spit player bullets via `Combat.fire`, explode or be adopted when time runs out); the Broodmother keeps distance and births hatchlings every `spawnCd`; crab-tossed ones fly first. A frozen parasite you head-butt is tamed (`Minions.tame`) if the roster has room. Otherwise it picks a random clean segment (`ti`), ramps from creep to sprint over `PARASITE_RAMP`, and on reaching it latches: a giant player squashes it, the Brood perk tames it, otherwise `e.dead = true` and `Player.infest(ti, id)`.

**Spells.** `charm(e, secs)` (grave ghost, via js/world-summons.js) sets `ally`; `updateCharmed` fights the nearest enemy, then `charmBoom` bursts it for `CFG.GRAVE.blastDmg`. Hypnosis is a `hypno` status plus `e.hypnoTree`, set by `World` (js/world-summons.js); `updateHypno` walks to the tree and sways. Giddy wanders away from you in a zigzag. Berserk comes from combos (notebook-combat.md).

**Drawing.** `Render.frame` (js/render.js) culls off-screen monsters, draws `drawMound` for hidden bugs, `drawHalo` for rares and uniques, `drawCharm`, `drawMimeWall`, then dispatches by kind: `drawSnake`, `drawSkullBug` (type `skull`), `drawBug` (all other bugs), `drawParasite`, boss drawers (js/render-bosses.js), and draws `e.label`. `drawBug` is one long function holding the whole bug family: the grave ghost returns early; shared shadow, wind-up ring, legs or wings and tongue; then a rotated body with one `if / else if` branch per type; then shared eyes, blush, `statusOverlay` and `hpBar`. Upright types (clowns, mime, elephant, brainling) draw their own faces. `drawParasite` turns into `drawSheep` on farm regions.

## Interop

- **Called by:** `G.update` (js/main.js) calls `Enemies.update` in world mode and `Enemies.reset` on start/load. js/world.js and js/world-swarm.js spawn (notebook-world.md). Bosses spawn minions through the same spawners (notebook-bosses.md). js/world-summons.js charms and hypnotises; js/world-discover.js spawns oddities; js/perks.js (Hive Mind) and js/minions.js spawn parasites (notebook-perks.md). js/deck.js borrows `spawnBug`.
- **Calls into:** `Combat.tickStatus`, `damage`, `kill`, `fireEnemy`, `fire`, `burst`, `particles` (notebook-combat.md); `Player.hit`, `removeAt`, `infest`, `segName`, `weaponCount` (notebook-player.md); `Minions.*` (tame, adopt, hurt, worldTick, count, cap); `World.regions`, `regionAt`, `levelOf`, `solidAt`, `loot`, `dropDots`, `mkPickup`, `pickups` (notebook-world.md, notebook-world-loot.md); `SFX.play`, `SFX.skitter` (notebook-audio.md); `G.msg`.
- **Kill hand-off:** `Combat.kill` -> `e.onDeath` -> `G.kills`, `G.score`, `World.loot(e, B.loot)` (rares: one tier up and always; see notebook-world-loot.md).
- **CFG keys:** `BOSS_EVERY`, `HP_CURVE`, `NIGHTMARE` (hp, uniqueHp, uniques, fire), `RARE`, `DOT_DROP.rare`, `SNAKE`, `BALANCE` (firePer, fireSegs, fireCap), `MAX_SEGMENTS`, `REGION_W`, `WORLD_H`, `WALL_SECTION`, `HEAD_R`, `SEG_R`, `SWARM`, `GRAVE`. Tables: `BUGS`, `PARASITES`, `PARASITE_RAMP`, `UNIQUES`, `AFFIXES`, `BULLETS.bullet` (ally spit), `BESTIARY.stats`.
- **Save fields:** none of its own. `G.kills` and `G.score` (written by `onDeath`) are saved by js/save.js. `G.uniqueSeen` (the uniques already introduced) is reset by `G.start` and not saved.

## Invariants and gotchas

- Every entity in the list needs `hitCircles()`, `st`, `r` and `onDeath` (or none): Combat calls them blindly. Hidden bugs return no circles, so they cannot be shot while emerging.
- Most deaths go through `Combat.kill`. Exceptions set `e.dead = true` directly and so skip loot, score and Mega charge: a latching parasite, a Hive Mind ally's expiry, `charmBoom`, and the fortune teller turning monsters into chickens.
- A charmed monster's death drops nothing (Combat nulls `onDeath`).
- `ally` doubles as a timer and a permanent flag: `1e9` for minions and rocks, so "charmed" is `0 < ally < 1e8`.
- `applyBestiaryStats` runs once at load and *mutates* `BUGS`, `PARASITES`, `UNIQUES` and `BOSSES` (a bug's `weight` function is wrapped). Snakes read their bestiary stats at spawn instead.
- `contain` lets a monster spill into the next region through a breached wall and changes its `region`; spawners count by region.
- Monsters sleep (no movement, no status ticks) when far from the player.
- `drawBug` branches on `e.type` strings; the type `skull` is routed to `drawSkullBug` by js/render.js, not inside `drawBug`.

## Tuning — where its numbers live

- Creature stats: `BUGS`, `PARASITES`, `UNIQUES`, `AFFIXES`, `CFG.SNAKE` in js/config.js (notebook-config.md); CFG paths can be overridden in js/tuning.js via the config editor.
- Speed, cooldown, range, shot speed, hold and spawn share per creature: `BESTIARY.stats` in js/bestiary.js, edited at http://localhost:8765/editor/bestiary/#stats. Resistances: `BESTIARY.resist` (notebook-combat.md).
- Spawn density and shares: `CFG.DENSITY`, `DENSITY_LEVEL`, `PARA_SHARE` in js/world.js's spawner (notebook-world.md).
- Hard-coded: snake hp 12 + 7, 35% spitters, bite cooldown 1.6 s, berserk contact damage (12 or 9 per second), wind-up times 0.45 / 0.55 / 0.7 s, the 1.3-region sleep distance, kamikaze 12 s life.

## Adding a monster

1. **Config.** Add an entry to `BUGS` (with `ai`, `shot`, `loot`, `score`, `weight(region)`; `weight: r => 0` for summon-only), `PARASITES`, or `UNIQUES` (with `base`, `minRegion`, optional `levels`, `loot`, `dots`) in js/config.js. A new AI needs a `case` in `Enemies.updateBug`.
2. **Spawn tables.** Bugs are picked by `weight` automatically. For a biome-only creature use `World.levelOf(r)` in `weight`. Summons call `Enemies.spawnBug(type, x, y, region, false)` from their source. New snake variants need `spawnSnake`, `snakeVariantAt` and `rowOf`.
3. **Bestiary rows.** Add a resist row (`bug-<type>`, `summon-<type>`, `parasite-<id>` or `unique-<id>`) in js/bestiary.js if it should differ from its family. Check `Combat.rowOf` names it the way you expect.
4. **Bestiary editor.** Add it to the hand-written `C` list in editor/bestiary/index.html (id, name, fam, lv, hp, speed, traits; `spawn: false` for summons), or it will not show in the editor at all.
5. **Drawer.** Add an `else if (e.type === '<type>')` branch in `Render.bugBody` (js/render-enemies.js; it draws at the x, y, angle and animation it is given, so it also paints sprites; a type in `Render.SPRITE_BUGS` must animate only by leg, flap and wig), and add it to the `upright` list if it draws its own face. Something radically different gets its own drawer and a dispatch line in `Render.frame` (js/render.js). Check `drawMound` if it should beam in rather than burrow.
6. **Sound.** Add a `case '<type>':` to `SFX.death` in js/util.js; the SFX editor builds its "Creature deaths" slots by reading those case lines. Design the `death:<type>`, `hit:<type>` and `attack:<type>` layers in the SFX editor (saved to js/sfx.js). `SFX.typeOf` gives the key (a bug's `type`; snakes are `snake`, `python`, `boneSnake`).
7. **README.** Add a row to the monster table in README.md (size, behaviour, attack, level).

## Testing

- `python3 tools/devserver.py`, then http://localhost:8765/?god=1. In god mode: L / O / X / Period warp to the graveyard, jungle, farm and circus; N warps a region; Semicolon enters nightmare (uniques everywhere); V makes you mortal; I makes the nearest jungle tree sing (`World.singTree`), to test hypnosis.
- `?bot=1&brain=scripted` lets the scripted bot fight while you watch.
- Console: `Enemies.spawnBug('iguana', Player.x + 300, Player.y, World.regionAt(Player.x), false)`, `Enemies.makeRare(Enemies.spawnSnake(Player.x + 300, Player.y, World.regionAt(Player.x), false), 'giant')`, `Enemies.spawnUnique('oldgrey', Player.x + 400, Player.y, World.regionAt(Player.x))`, `Enemies.list.length`, `Enemies.threat`. New bugs start hidden for 1 s.
- Bestiary editor: http://localhost:8765/editor/bestiary/ (resistances) and `#stats`.

## Change log hooks

Update this notebook in the same commit as any change to the system's behaviour.
