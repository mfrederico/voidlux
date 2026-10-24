# Config notebook

The data layer. Every tuning number, every bullet, recipe, creature, boss, biome and affix lives in plain global tables in
`js/config.js`. Two small files sit on top and are written by editors, not by hand: `js/tuning.js` overrides CFG paths, and
`js/bestiary.js` holds per-creature elemental resistances and stat overrides. The editors under `editor/` read the same
tables and save through `tools/devserver.py` (see notebook-tools.md). Change a number here and the game changes; nothing
else in the codebase should hard-code a balance number that has a home in CFG.

**Files:**

| file | what it holds |
| --- | --- |
| `js/config.js` | `CFG` (all tuning) and the content tables: `BULLETS`, `RECIPES` / `findRecipe` / `ricochetOf`, `SYN_EXTRA` / `synInfo`, `PIPES`, `ELEMENTS`, `PARASITE_RAMP`, `PARASITES` (+ the `minion_*` bullets), `BIOMES`, `BOSSES`, `BUGS`, `AFFIXES`, `UNIQUES` |
| `js/tuning.js` | `TUNING`: `{ "<CFG path>": value }` and the `applyTuning` IIFE that writes them onto CFG. Rewritten by the config editor |
| `js/bestiary.js` | `BESTIARY = { resist: {...}, stats: {...} }`. Rewritten by the bestiary editor |
| `editor/index.html` | The editors' front door: one card per editor and the file it writes. Served by the dev server, never deployed |
| `editor/config/index.html` | Game config editor: every number/boolean leaf of CFG with its config.js comment; writes `js/tuning.js` |
| `editor/bestiary/index.html` | Bestiary editor: a resist grid (creatures x elements, family / level / nightmare rows) and a stats tab; writes `js/bestiary.js` |
| `editor/range/index.html` | Test range: boots the whole game, stops its loop, runs every weapon through four scripted fights; saves nothing |

## Model — the state it owns, and its lifecycle

- All tables are classic-script globals (`const`), created when `js/config.js` loads. Load order (index.html):
  `util.js`, `music.js`, `music-themes.js`, **`config.js`, `tuning.js`, `bestiary.js`**, then `sfx.js` and every system.
- They are not frozen. Several are mutated at load or at run time:
  - `applyTuning` (tuning.js) writes TUNING values onto CFG right after config.js.
  - The `PARASITES` loop in config.js registers one `minion_<id>` pseudo-bullet per parasite (`kind: 'minion'`, `craft: true`).
  - `applyBestiaryStats` (an IIFE at the end of `js/enemies.js`) writes bestiary stats onto `BUGS`, `PARASITES`, `UNIQUES`,
    `BOSSES` and fills `Enemies.paraSpawn`.
  - `Player.tuneFusions` (player-build.js, called from main.js boot "before anything reads bullet damage") raises the
    `dmg` of fused bullets that fail `CFG.BALANCE.fuseFloor` and marks them with `tuned`.
  - `ricochetOf` registers derived bullets (`<root>_ric1..3`) into BULLETS lazily, the first time `findRecipe` sees a
    shot + Bouncer pair (render code calls findRecipe too, so just hovering a pair on the workbench can register one).
  - `CFG.W` is recomputed from the screen aspect by `fit()` in main.js boot on every resize.
- No save fields live here. The run save stores bullet ids; anything that resolves an id goes through `BULLETS[id]`.

### The tables, and who reads them

- **CFG** — world geometry (W/H, WORLD_H, REGION_W, HEDGE, WALL_*), the player (speeds, radii, START_SEGMENTS,
  MAX_SEGMENTS, HEAD_SPIT), DUNGEON_TIERS and DUNGEON, LOOT and DOT_DROP, megas (MEGA_DECK, LIGHTNING, LICH, SACRIFICE,
  HIVE_MIND, DEEP_FREEZE, MEGA_MAX), BOSS_EVERY, the saucer DECK and DECK_MODE, bag and crafting (BAG_MAX, STACK_MAX,
  CRAFT_COST, TRASH_DOTS), piping (PIPE_*, RICOCHET), LOOSE, the ramps (LEVEL_RAMP, LEVEL_RAMP_LOW, HP_CURVE), RESONANCE,
  GEAR, element synergy (SYNERGY, DRY, GIGGLE), TWIN, MAGNET_BASE, dungeon devices (SIPHON, SHOVEL, HAMMER, MOLE),
  summoning (ROCK, GRAVE, SONG, CANNON), DENSITY, SWARM, SNAKE, NIGHTMARE, STORM_PROJ, BALANCE, PARA_SHARE, RARE, MINIONS,
  biome toys (GIANT, TINY, SHROOM, HIVE, GEYSER, FORTUNE, LAUGH, SLOWMO). Read everywhere. Each key carries its meaning in
  the comment above or beside it; the config editor shows those comments.
- **BULLETS** — every segment type, keyed by id. Common fields: `name, key, color, dark, kind, dmg, speed, size, range,
  cd, spread, tier, weight, desc`; optional `elems, pierce, burst, combo, craft, wallMult, bounces, maxInflight, laser,
  life, radius, trail, r, minion, spear`. `kind` is one of shot, boomerang, orbit, trail, bounce, chain, homing, pulse,
  mine, head (head gear), minion. `tier` 1..4 is potency; `weight` is drop odds within a tier (0 = never drops);
  `craft: true` = only made on the workbench. Read by ~35 files: player-*, combat, workbench and render-bench-*, world-loot,
  minions, deck, gallery, icons, save, bot, and tools/readme-recipes.js.
- **RECIPES** — `[a, b, result]` unordered pairs. `RECIPE_MAP` holds both orders. `findRecipe(a, b)` checks the map, then
  the Bouncer modifier rule (any `kind: 'shot'`, non-minion bullet + `bouncer` = `ricochetOf(base)`, up to level 3).
  Readers: player-build (`canCraft`, `fusions`, `tuneFusions`), player-loadout (`partsOf`), workbench, render-bench*,
  readme-recipes. See notebook-workbench.md.
- **ELEMENTS** — fire, poison, ice, electric: name, colours, key, desc. The key set is the element list for Combat.resist,
  synergy (player-build), perks, gear, settings. The dev server hard-codes the same four names.
- **SYN_EXTRA / synInfo** — synergies that are not elements (today only `bone`: spears and the dust trail). `synInfo(el)`
  gives name/colour/icon for either kind. Read by player-build, player-gear, render-player, render-bench-body.
- **PIPES** — the "piping" a segment hands to the one behind it (chill, jolt, singe, taint, dazzle, ricochet, burst, dry,
  homing, split): tag, colour, desc. Read by combat and render-bench*.
- **PARASITES / PARASITE_RAMP** — leech, slug, jammer: hp + hpR per region, speed + speedR, `hold` (seconds a bite jams
  segments). Read by enemies*, minions, player-hurt, perks, render-player, bot. See notebook-enemies.md.
- **BIOMES** — per biome: ground colours, tree/lake/boulder counts and flags (`haunted`, `jungle`, `farm`, `circus`,
  `graves`, `cannons`). The `arena`, `ossuary`, `ziggurat`, `landing`, `bigtop` entries are the boss arenas per level.
  Read by world.js and render-world.js (notebook-world.md).
- **BOSSES** — one per level (crab, skull, serpent, overlord, clowncar). `hpMin, hpL, fightSecs, hpCap` set hp from
  `Player.estimateDps` when the boss wakes; every `*Cd` key is an ability cooldown; `music` names a theme. Read by
  enemies-bosses and the per-boss files, combat (gasMult), render-bosses, render-hud, minions, perks, bot (notebook-bosses.md).
- **BUGS** — every bug-family monster, including the summon-only ones (kamighost, kamiclown, chicken, weight 0). `ai`
  picks the behaviour; `shot` is the volley; `loot` is `{chance, tier, n}`; `armor` is the share plain bullets do;
  `weight(r)` is a function of region (many call `World.levelOf`, so the table needs World at call time, not at load).
  Read by enemies*, world-swarm, deck, title, render-enemies, perks, bot.
- **AFFIXES** — rare-monster stat multipliers (giant, swift, armored, venomous), applied in `Enemies.makeRare`.
- **UNIQUES** — named monsters: `base` (snake / bug / parasite) plus `type`/`id`, `minRegion`, optional `levels`,
  `chance`, stats, `loot`, `dots`. Read by enemies, enemies-uniques, enemies-parasites, world.js.

## How it works — the main flows, named by function

- **Tuning overrides.** `applyTuning` walks each dotted path from CFG (`"SWARM.after"`, `"DUNGEON_TIERS.2.ghosts"`; array
  indices work as keys) and assigns only if the parent exists and `typeof old === typeof new`. A stale path or a
  wrong-typed value is skipped silently.
- **Resistances.** `Combat.resist(e, el)`: the creature's own row (`Combat.rowOf(e)` gives `boss-<type>`,
  `unique-<id>`, `snake-<snake|bone|python|balloon>`, `parasite-<id>`, `bug-<type>` or `summon-<type>`), else
  `family-<e.kind>`, else 1. The result is cached on the monster as `e.res` on first lookup. It is then multiplied by
  `Combat.resistMod`: the `level-<1..5>` row (level capped at 5) and, in nightmare, `mode-nightmare`. After that come
  element synergy penetration (`CFG.SYNERGY.pen`, `immuneFloor`) and Deep Freeze (`CFG.DEEP_FREEZE.mult` floor for ice).
  Resist scales hit damage, DoT damage, and status time/magnitude in `Combat.addStatus` (via `Combat.ELEM_OF`, so
  giddy counts as poison); 0 blocks the status and shows IMMUNE. Gear, the Sacrifice mega and trails call it too.
- **Stat overrides.** `applyBestiaryStats` maps `bug-*` and `summon-*` to BUGS, `parasite-*` to PARASITES, `unique-*` to
  UNIQUES, `boss-*` to BOSSES. It copies speed, speedR, cd, range, hold; `shotSpeed` goes to `shot.speed`; `tempo`
  multiplies every numeric key ending in `Cd`; `spawn` wraps a bug's `weight` function, sets `Enemies.paraSpawn[id]`, or
  scales a unique's `chance` (capped at 1). A unique's `cd` also sets `spawnCd` (the Broodmother). Snakes are not in a
  table: `Enemies.snakeStats(variant)` merges `snake-<variant>` stats over `CFG.SNAKE` at spawn time (`cd`, `range`,
  `shotSpeed` map to `spitCd`, `spitRange`, `spitSpeed`), and `snakeSpawnAt` reads its `spawn`.

## Interop — reads from / is called by / calls into other systems

- Called by everything: every notebook reads CFG. The content tables feed notebook-player.md (BULLETS, synergy),
  notebook-workbench.md (RECIPES), notebook-combat.md (ELEMENTS, PIPES, resist), notebook-enemies.md and
  notebook-bosses.md (BUGS, PARASITES, UNIQUES, AFFIXES, BOSSES), notebook-world.md (BIOMES), notebook-dungeon.md
  (DUNGEON_TIERS, devices), notebook-perks.md (MEGA_*, BALANCE).
- Calls into: nothing at load except its own tables. `BUGS[*].weight` calls `World.levelOf` when invoked.
- `MEGAS` and `PERKS` are not here; they live in js/perks.js (notebook-perks.md).
- The editors and `tools/readme-recipes.js` load `js/config.js` on their own, outside the game (notebook-tools.md).
- Save fields written: none. Bullet ids in the save must still exist in BULLETS: `Save.resume` filters out unknown ids.

## Invariants and gotchas

- **Editor-written files have a fixed format.** `tools/devserver.py` regenerates `js/tuning.js` and `js/bestiary.js`
  whole on every save: a fixed header comment, one JSON row per line, sorted keys, and (tuning.js) the `applyTuning`
  tail copied from `TUNING_TAIL` in the server. Hand edits survive only if they keep that shape: the server reads the
  file back by finding `const TUNING = ` and the first `};`, or `const BESTIARY = ` and parsing the rest as JSON. Comments
  inside the object, trailing commas or JS expressions break the read. Prefer editing through the editor. To change the
  applyTuning code, change `TUNING_TAIL` in devserver.py too, or the next save reverts it.
- Tuning only reaches CFG. It cannot change BULLETS, BOSSES, BUGS and the rest; those change in config.js (or via the
  bestiary for creature stats). The dev server only accepts numbers and booleans.
- Overriding `CFG.W` is pointless: `fit()` recomputes it from the window.
- BULLETS `dmg` in config.js is not the final value for fusions: `Player.tuneFusions` may raise it at boot (console logs
  `[balance] fusion damage raised`). `tools/readme-recipes.js` evals config.js alone, so the README shows the raw value.
- Recipe results and ingredients must exist in BULLETS; `tuneFusions` and readers skip or crash on a missing id. Trails
  (`kind: 'trail'`) are skipped by `tuneFusions`.
- Ricochet bullets (`<id>_ricN`) exist only after `ricochetOf` has run in this page. A saved segment with such an id may
  be dropped by `Save.resume` (it keeps only ids already in BULLETS) unless something registers it first (unverified).
- Resist row ids must match `[a-z]+-[a-z0-9]+` (the server rejects others), so camelCase creature ids cannot get a row.
- `e.res` is cached per monster: a resist change does not reach monsters already alive (reload the game).
- Bestiary `tempo` rescales every `*Cd` key, including ones that are not timings of attacks (any key ending in `Cd`).
- The bestiary editor's creature list (`C` in editor/bestiary/index.html) is hand-written, with its own copies of hp,
  speed and traits. It lists 3 of the 13 UNIQUES; a new creature needs a line there to be editable.
- Load order matters: tuning.js must follow config.js; bestiary.js must precede enemies.js and combat use. The range
  editor repeats the game's script list (see notebook-tools.md).

## Tuning — where its numbers live

- Balance numbers: `CFG` in js/config.js, overridden per path in js/tuning.js (currently empty: `{}`).
- Content stats: the tables in js/config.js. Creature speed, cooldown, range, shot speed, hold, boss tempo and spawn
  rate: `BESTIARY.stats` in js/bestiary.js (currently empty). Elemental resistances: `BESTIARY.resist`.
- Fusion damage floor: `CFG.BALANCE.fuseFloor` / `fuseBoostMax`.

## Testing — how to see it working

- Run `python3 tools/devserver.py`, open http://localhost:8765/editor/. Change a value in Game config: it turns gold, the
  status line says "Saved to js/tuning.js"; reload the game. `git diff js/tuning.js` shows the row.
- In the bestiary, set a cell; reload the game and hit that creature with the element: floaters say STRONG!, RESIST or
  IMMUNE (`Combat.resistTag`, only for values under 0.8 or 1.25 and over).
- The test range (http://localhost:8765/editor/range/, `?auto=1` to run at once) compares every weapon at a level and
  Brawn; it uses the real combat code but sets every target's resist to 1. "Download" saves the results as JSON.
- After editing RECIPES or a fused bullet: `node tools/readme-recipes.js` refreshes the README recipe table.

## Change log hooks

Update this notebook in the same commit as any change to the system's behaviour.
