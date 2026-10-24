const CFG = {
  W: 1280, H: 720,      // logical canvas; W is recomputed from the screen's aspect ratio at load / resize / fullscreen
  W_MIN: 960, W_MAX: 1700,
  MOBILE_SCALE: 1.3,    // the old touch-screen zoom; Render.zoom / Render.ui / Dungeon.center now follow Settings.size() (the Size setting)
  WORLD_H: 1400,
  REGION_W: 2600,
  HEDGE: 26,            // thickness of the thorn hedge around the world
  WALL_T: 26,           // thickness of region walls
  WALL_SECTION: 100,    // breakable section height
  SEG_SPACING: 15,
  HEAD_R: 13, SEG_R: 10,
  PLAYER_SPEED: 205, TURN_RATE: 6.5,
  INVULN: 1.2,
  START_SEGMENTS: ['bullet', 'pebble', 'pebble'],
  MAX_SEGMENTS: 3,      // body capacity at the start; only the Bigger Body dungeon perk raises it
  WIN_RESET: 0,         // after a boss, shrink the body (and cap) back to this many segments; 0 = keep everything (the graveyard is hard enough)
  HEAD_SPIT: 'pebble',  // the head spits this whenever no segment can fire, so you are never unarmed
  // Dungeons scale with the region: big, open and sleepy early; tight, twisty and perilous later.
  // Tiers are picked by region index (first tier whose maxRegion >= region). cols/rows must be odd.
  DUNGEON_TIERS: [
    { maxRegion: 1,  cols: 15, rows: 9,  cell: 64, loops: 0.35, ghosts: 2, ghostSpeed: 0.62, fright: 12, name: 'Burrow' },
    { maxRegion: 3,  cols: 17, rows: 11, cell: 56, loops: 0.25, ghosts: 3, ghostSpeed: 0.70, fright: 10, name: 'Warren' },
    { maxRegion: 6,  cols: 21, rows: 13, cell: 48, loops: 0.15, ghosts: 4, ghostSpeed: 0.78, fright: 8, name: 'Catacomb' },
    { maxRegion: 9,  cols: 23, rows: 15, cell: 40, loops: 0.08, ghosts: 5, ghostSpeed: 0.86, fright: 6, name: 'Labyrinth' },
    { maxRegion: 99, cols: 27, rows: 17, cell: 36, loops: 0.04, ghosts: 6, ghostSpeed: 0.95, fright: 5, name: 'Nightmare Maze' },
  ],
  DUNGEON: { PLAYER_SPEED: 165, CORNER: 0.35, WANT_GRACE: 0.3, TOUCH_GRACE: 1.0, TURN_BIAS: 0.64, LEASH: 1.8, SWIPE: 46, STICK_DEAD: 0.45, STICK_MIN: 44 }, // the maze stick is one maze cell across (never under STICK_MIN px): past STICK_DEAD of a cell into the next square is a turn // touch in the maze: a turn waits TOUCH_GRACE s for its junction; a thumb leaning past TURN_BIAS (sin 40°) sideways is a turn (so a held diagonal settles on one way, never zigzags); the stick's centre stays where the thumb landed (a joystick) and only follows a thumb that strays LEASH x its radius; a SWIPE (canvas px, quick) anywhere on the right queues one
  // px/s; bigger cells get a small bonus (see Dungeon.playerSpeed). CORNER: a turn asked for within this fraction of a cell of a corner snaps to it and turns at once. WANT_GRACE: a released turn request is forgotten after this many seconds
  // Loot only drops from kills. tier = how potent; a drop can roll one tier up or down.
  LOOT: { TIER_UP: 0.25, TIER_DOWN: 0.2, PITY: 7, RATE: 0.5 }, // RATE scales every monster's drop chance (not rares, uniques or sure drops: a build came together by level 2)
  DREAD_REGIONS: 8,     // by this region the world is as dark as it gets
  // Sacrifice: the tail weapon goes off in its own style. Centre hit = (base + perRegion * the region's HP scale) * (1 + tierMult * (tier - 1)),
  // half that at the rim; the radius is r, and Long Reach widens it the way it widens a Pulse (x range squared: x1.69, x2.86...). A boss takes at most bossCap of
  // its max HP. Its element adds a DoT of dot * the hit per second (fire burns, poison sickens, electric arcs; ice freezes)
  // the MEGA DECK: you start with Sacrifice and collect the rest (a mega card in the perk shop, or one a unique monster drops,
  // `uniqueDrop` of the time). One is active; switching (LOADOUT) empties the bar. A mega card costs `base` ghosts for your
  // second mega, then `step` more for each one after
  MEGA_DECK: { base: 2, step: 2, uniqueDrop: 0.35 },
  // Lightning Storm: curled into a ball that rises up (`scale`), untouchable, every segment arcs a bolt every `every`s at the
  // nearest enemy within `range` (chaining `hops` more times within `hopR`, each `hopMult` of the last), for `time`s
  LIGHTNING: { time: 5, scale: 1.5, every: 0.35, range: 420, base: 5, perRegion: 2, hops: 2, hopR: 170, hopMult: 0.6, stun: 0.6 },
  // Will of the Lich: every segment flies off as an untouchable flaming skull for `time`s (the last `back`s flying home), hunting
  // within `reach` of the head: each hit burns and dries out what it touches (once per `hitCd`s per monster). The head is untouchable
  LICH: { time: 3, back: 0.45, speed: 430, turn: 7, reach: 650, base: 8, perRegion: 3, hitCd: 0.35, burn: 3, dry: 3 },
  SACRIFICE: { base: 40, perRegion: 14, tierMult: 0.4, bossCap: 0.08, dot: 0.08, r: 440 },
  HIVE_MIND: { time: 8, respawn: 0.6 }, // Hive Mind: how long the parasites fight for you; a fallen one is replaced every `respawn`s while it lasts
  DEEP_FREEZE: { time: 5, mult: 2 }, // Deep Freeze: for `time`s your bullets are ice too, and ice ignores cold resistance: every hit's element multiplier is at least `mult`
  DOOR: { rx: 48, ry: 42, dy: 0 }, // a dungeon entrance's doorway: an ellipse (half-width rx, half-height ry, centred dy below the entrance point) covering the whole drawn entrance
  MEGA_MAX: 120,        // power-bar charge needed for one mega (kills add 8 + 4*tier)
  BOSS_EVERY: 7,        // every 7th region (after 6 wall-breaks) is a boss arena
  // the saucer deck's purse: DOTS x (the cells of a maze in the same region) is all the dots its waves drop, shared out by
  // invader (cow 4 : saucer 3 : brainling 2), so a deck pays what a maze would. Ghosts are the deck's real payoff, and cows carry
  // them: a cow shot down drops one at COW odds (every other column of the top row is a cow), a diver shot mid-dive at
  // DIVE_GHOST odds, a cow shot down while it carries your segment off drops COW_GHOST, and a wave cleared without
  // losing a segment pays PERFECT (the UFO still drops its own)
  DECK: { DOTS: 0.45, COW: 0.4, DIVE_GHOST: 0.1, PERFECT: 1, COW_GHOST: 1 },
  DECK_MODE: true, DECK_DMG: 10,  // the farm's dungeons are the saucer deck, a fixed-screen shooter (js/deck.js, a prototype); false = mazes everywhere. DECK_DMG: on the deck your shots hit ten times harder: the invaders keep their overworld health (it grows with the region), there are just many more of them
  BAG_MAX: 20,          // kinds of spare bullet the bag holds (a stack of one kind counts once); craft with them on the pause screen
  STACK_MAX: 20,        // and at most this many of one kind: a full stack leaves the next one on the ground (an endless run hoarded 4000)
  CRAFT_COST: { 3: 20, 4: 40 }, // dots (eaten in dungeons) a fusion costs, by the result's tier
  TRASH_DOTS: 2,         // trashing a bullet on the workbench transmutes it into this many dots
  RICOCHET: { falloff: 1, floor: 0.1 }, // a ricochet's hits after its nth bounce do 1 / (1 + falloff x n) of its damage (1/2, 1/3, 1/4...), never under floor
  PIPE_BURST: { mult: 0.6, spread: 0.12 }, // +burst piping: the extra shot's share of the damage, and how far (radians) it angles off
  PIPE_HOMING: { turn: 2.2, reach: 320 }, // +homing piping: how hard a shot behind a moth bends (radians/s; a Moth turns at 5) toward the nearest enemy within reach px
  PIPE_BOUNCES: 2, // +ricochet piping: how many times a shot or moth behind a bouncing segment reflects
  LOOSE: { time: 10, toss: 260, dpsRef: 150, dpsK: 0.45, levelK: 0.25, maxMult: 5 },  // de-segmenting: a lost segment bursts into the base bullets and dots it was made of, thrown `toss` px away. time > 0 would put the pieces on a fuse (seconds before they fizzle); 0 = they lie there like any loot. Throw distance = toss x (1 + dpsK x log2(1 + dps / dpsRef)) x (1 + levelK x (level - 1)), capped at maxMult
  LEVEL_RAMP: 0.15,             // tier-3+ bullets deal this much more per level past the first (enemy hp keeps climbing with the region; so should a fused build)
  LEVEL_RAMP_LOW: 0.07,         // tier-1 and tier-2 bullets ramp too, half as fast (so a plain body is not useless on level 3)
  HP_CURVE: 0.6,                // past level 1, each further region adds only this fraction of a region to monster hp (Enemies.hpScale)
  // element synergy: `need` segments carrying an element (side by side when inRow) turn on its bonus, plus `pen` resistance penetration for that element
  // (a resisted hit moves that share of the way back to normal; immunity holds unless immuneFloor > 0, which immune monsters then take);
  // and every gun behind such a run takes its charged pipe (+++jolt, +++chill, +++taint, +++dry...) at pipeBoost x the strength: the runs stack down the body
  // RESONANCE: a Pulse ring sweeping over one of your armed mines sets it off: the blast is `blastMult` wider and `dmgMult`
  // harder, and the mine sends out a ring of its own (`ringDmg` x the mine's damage, its elements, the pulse's reach) that can set off more mines
  RESONANCE: { blastMult: 1.4, dmgMult: 1.5, ringDmg: 0.5, color: '#ff8a5c' },
  GEAR: { hardHead: 0.2, drop: 0.006, rareDrop: 0.05, uniqueDrop: 0.25, mines: { range: 170, arc: 0.6, mult: 1.25 }, charge: { max: 4, min: 0.4 } },  // head gear: hardHead = +damage per Fast and Hard copy while gear is on; drop chances (a kill, a rare, a unique), and how far ahead it sets off your mines (and how much harder they blow). Charge: holding fire builds the gear's mega-hit for up to max s; firing again unleashes it (if at least min s)
  GIGGLE: { poison: 4, cloud: 55, cloudTime: 3 }, // Laughing Gas: its poison lasts `poison`s and leaves a pink cloud (`cloud` px, cloudTime s); poisoned by it = giddy (no attacks, wandering away) as long as the poison
  DRY: { time: 3, fire: 1.5, pipeTime: 1.5, synergy: 1.25 },  // Desiccated: a bone spear hit dries a monster out for `time`s (the Ice Spear does not): fire does x`fire` to it (hits and burns) and it cannot be frozen; +dry piping dries for pipeTime (bone synergy, 3+ bone weapons in a row: dried-out monsters take x`synergy` from everything, and the run's +dry pipe is charged)
  SYNERGY: { need: 3, inRow: true, pipeBoost: 2.5, pen: 0.25, immuneFloor: 0, arcs: 1, burningTaken: 1.5, frozenTaken: 1.5, cloudTime: 2, cloudSize: 1.5 },
  TWIN: { mult: [0.4, 0.3, 0.25, 0.2] }, // Twin Shot: each copy's share of a shot's damage with 1, 2, 3, 4+ copies (statuses stay full)
  MAGNET_BASE: 0.10,            // every pickup within this fraction of the screen width drifts to you (Loot Magnet stacks add to it)
  // the Siphon: one vacuum pump per dungeon, deep in the maze. Touch it and it sucks in up to `share` of the plain dots
  // over `time` seconds, nearest first; every ghost hears it running and converges on it (so be elsewhere).
  SIPHON: { share: 0.35, time: 7, minDist: 0.5, reach: 7, cartSpeed: 4 }, // recharged, it trundles to the cell with the most dots within `reach` corridor steps, at `cartSpeed` cells/s
  // the Shovel: the other dungeon device. Pick it up and you can dig through `digs` inner walls by pushing into them (the border stays)
  SHOVEL: { digs: 3, minDist: 0.3 },
  // SUMMONING: circle a thing once (within its radius) and you summon its power: a boulder wakes, a grave gives up its ghost, a
  // tree sings, a cannon fires you, a barn lets its tractors out. The Summoner perk halves the lap and strengthens what comes.
  // waking boulders: circle a rock `laps` times (within `radius` px of it) and it comes alive, rolls at the nearest enemy
  // within `range` and detonates (blast radius / damage, times your power); with nothing to hit it crumbles after `life`s
  ROCK: { laps: 1, radius: 150, speed: 280, blast: 150, blastDmg: 45, range: 900, life: 8, wallDmg: 80 },
  // graveyard tombstones (level 2+): circle one `laps` times within `radius` px and a ghost rises; every monster within
  // `range` (bosses excepted) fights for you for `charm` seconds, then bursts in a `blast` px blast of `blastDmg` x power
  GRAVE: { laps: 1, radius: 140, range: 720, charm: 10, blast: 130, blastDmg: 40 },
  // jungle trees (level 3+): circle one `laps` times and it sings. Every monster within `range` (bosses excepted) is drawn
  // to the tree and stands there hypnotized for `time` seconds: no shooting, no biting, and it takes `dmgMult` damage
  SONG: { laps: 1, radius: 150, range: 700, time: 8, dmgMult: 1.5, sync: true }, // sync: a tracker treesong plays at the music's tempo and pitch, on its beat
  SLOWMO: { scale: 0.18, time: 1.3 }, // the abduction: the world runs at `scale` speed for `time` real seconds
  // Dots also trickle from overworld kills: chance per kill (scaled by Lucky Shell), 1 dot each. Rare monsters drop a few, hoarders a pile.
  DOT_DROP: { chance: 0.12, rare: [2, 4] },
  // Monster density: ongoing spawns keep this many enemies in your region (+ region index + DENSITY_LEVEL per level cleared)
  DENSITY: 5, DENSITY_LEVEL: 2,
  // the SWARM: linger `after` s in one region and a swarm gathers. `warn` s of warning, then `count` (+ perLevel per level past
  // the first) weak bugs pour in from off screen in `waves` waves `gap` s apart: hp x `hp`, shooting x `fire` as often, loot
  // chance x `loot` (no pity drops); `elite` of them are rare monsters, at full strength. They all hunt you, `chase` faster per
  // second alive (up to `chaseMax` x their speed). `cooldown` s before the next can gather
  SWARM: { after: 75, warn: 4, count: 30, perLevel: 8, waves: 4, gap: 2.5, hp: 0.35, fire: 0.6, loot: 0.25, elite: 0.05, chase: 0.06, chaseMax: 2.5, cooldown: 90, minRegion: 1 },
  // snakes: px/s at region 0 + speedR per region; spitters spit every spitCd s within spitRange px, at spitSpeed (the bestiary can override each variant)
  SNAKE: { speed: 135, speedR: 7, spitCd: 2.6, spitRange: 480, spitSpeed: 260 },
  // Balance knobs (so a giant body is not the only build that wins; measured with ?trials):
  //  freeCap: the dungeon's free perk pays at most this many ghosts; a pricier perk needs the rest in ghosts
  //  bodySq: Bigger Body costs 1 + n + n²/bodySq ghosts after n copies (other perks: 1 + n)
  //  fuseFloor / fuseBoostMax: at load, a fused bullet whose base DPS is under fuseFloor × its two ingredients' gets its
  //    damage raised to reach it (at most ×fuseBoostMax; trails are skipped, their area damage is not in the estimate)
  //  longBody / longInvuln / longInvulnMin: past longBody weapon segments, each one shortens the after-hit invulnerability by longInvuln (floor longInvulnMin)
  //  fireSegs / firePer / fireCap: a long body draws fire. For every fireSegs weapon segments past the starting body, monsters
  //    shoot firePer more often (their cooldowns shrink to match), up to fireCap more; bosses keep their own rhythm
  //  crowdRef / crowdPerDoubling / crowdMax: your damage output draws a crowd. Past crowdRef DPS, every doubling keeps crowdPerDoubling
  //    more monsters in the region (at most crowdMax), they spawn that much faster, and each kill's loot and dot chances shrink
  //    in step, so a strong build mows more down without drowning in loot
  // NIGHTMARE: beat the Clown Car and the run loops back to level 1 with your build, harder; beat the nightmare Clown Car to finish.
  //   hp: monster health multiplier · fire: monster cooldown multiplier (lower = shoots more) · density: extra monsters per region ·
  //   deckWaves: saucer deck waves · dive: deck dive interval multiplier · pitch: music shift in semitones · tint: the dusk over the world
  NIGHTMARE: { hp: 10, uniques: 0.1, uniqueHp: 4, fire: 0.75, density: 4, deckWaves: 7, dive: 0.5, pitch: -3, tint: 'rgba(40,0,50,0.22)' },
  STORM_PROJ: 240,      // Bullet Storm stops adding bullets past this many in the air (it hit the 400 safety cap: ~5x the update cost)
  BALANCE: { freeCap: 4, bodySq: 6, fuseFloor: 1.3, fuseBoostMax: 2.5, longBody: 20, longInvuln: 0.025, longInvulnMin: 0.5, crowdRef: 50, crowdPerDoubling: 2, crowdMax: 12, fireSegs: 3, firePer: 0.04, fireCap: 0.6 },
  // Parasites' share of the ongoing spawns: base + perRegion x region, capped (uncapped it reached 80% in the jungle: that was the 'parasites are kicking my ass')
  PARA_SHARE: { base: 0.1, perRegion: 0.02, max: 0.3, seedMax: 6 },
  // Rare monsters: chance a spawned snake or bug rolls an affix, rising per region
  RARE: { chance: 0.05, chanceR: 0.012, max: 0.2 },
  // Minions: tamed parasites that follow you, spit at enemies, kamikaze into big ones, and forage dots in dungeons
  // Minions ride your body as slot-free segments that spit (spitMult x power every spitCd), and the tail one launches
  // itself at a boss, unique or beefy rare within launchRange (one dive per launchCd seconds; the diver has hp and detonates for blast/blastDmg)
  MINIONS: { max: 2, hp: 30, speed: 215, blast: 110, blastDmg: 26, spitMult: 0.7, spitCd: 0.55, launchRange: 320, launchCd: 6 },
  // the circus cannon (level 5): circle one `laps` times and it fires YOU: `time` seconds of flight at `speed` px/s,
  // invulnerable, dealing `dmg` x power to everything you pass through, and `wallDmg` to a wall you hit
  CANNON: { laps: 1, radius: 150, speed: 950, time: 1.1, dmg: 60, hitR: 70, wallDmg: 120 },
  // the Hammer: the big-top dungeon device. It swings for `time` s, every `every` s; a swing eats any ghost in the cell above or below you, scared or not
  HAMMER: { time: 10, every: 0.3, minDist: 0.3 }, // the big top's hammer: grab it and it swings by itself for `time` s, every `every` s (old Donkey Kong)
  MOLE: { every: 7, up: 3.5, reward: 3 }, // big-top moles: one pops out of a dot cell every `every`s, steals the dot after `up`s unless hammered (`reward` dots)
  LAUGH: { time: 1.0 },           // laughing gas: enemies inside giggle and cannot attack; you inside get your controls mirrored for `time`s
  GIANT: { time: 8, speed: 0.85, dmg: 45, r: 2.1, bossHit: 0.04, warn: 2.5, grace: 1.5 }, // Eat Me cake / the tractor: `time`s of being huge; whatever touches the head is crushed
  // outright (a boss loses bossHit of its max HP per crush, at least dmg), the last `warn`s count down (the tractor sputters), then `grace`s of invulnerability
  TINY:  { time: 8, speed: 1.5, dmg: 0.6 },           // Drink Me potion: small and quick; enemy shots sail over you, your bullets hit for less
  SHROOM: { time: 8, dmg: 2 },                        // the mushroom: double damage, inverted colours, a rainbow echo behind you
  HIVE: { bees: 4, charm: 8, chance: 0.5 },           // meadow beehive trees: a bullet hit has `chance` to shake out `bees` angry gnats that fight for you for `charm`s
  GEYSER: { every: 6, on: 1.0, r: 95, dmg: 22, stun: 1.5 }, // jungle geysers: erupt every `every`s for `on`s; monsters inside are thrown (dmg x power, stunned); you are tossed, unhurt
  FORTUNE: { range: 600 },                            // the fortune teller's tent: touch it once and a card flips (see World.tellFortune)
};

// Every segment is one bullet type and may have exactly ONE bullet in flight at a time.
// cd = minimum time between shots once the bullet is back; tier = potency (1 common .. 3 rare), weight = drop odds within its tier.
const BULLETS = {
  pebble:    { name: 'Pebble',    key: 'P', color: '#e9e2cf', dark: '#9c9484', kind: 'shot',      dmg: 1,  speed: 460,  size: 3,   range: 260, cd: 0.10, spread: 0.15, tier: 1, weight: 3,   desc: 'tiny and quick, back in a blink' },
  bullet:    { name: 'Bullet',    key: 'B', color: '#ffd166', dark: '#b58a2b', kind: 'shot',      dmg: 3,  speed: 560,  size: 4,   range: 420, cd: 0.22, spread: 0.04, tier: 1, weight: 3,   desc: 'honest work' },
  rock:      { name: 'Rock',      key: 'R', color: '#b8a48e', dark: '#6f5f4e', kind: 'shot',      dmg: 12, speed: 300,  size: 9,   range: 360, cd: 0.7,  spread: 0.03, tier: 2, weight: 2,   wallMult: 3, desc: 'slow, heavy, wrecks walls' },
  burst:     { name: 'Burst',     key: '3', color: '#ffe08a', dark: '#a67c1c', kind: 'shot',      dmg: 2,  speed: 520,  size: 3.5, range: 380, cd: 0.35, spread: 0.06, tier: 2, weight: 2,   burst: 3, desc: 'three-round burst, all three must return' },
  laser:     { name: 'Laser',     key: 'L', color: '#ff7eb6', dark: '#b5386f', kind: 'shot',      dmg: 2,  speed: 1000, size: 2.5, range: 560, cd: 0.14, spread: 0,    tier: 2, weight: 2,   pierce: 3, laser: true, desc: 'hits up to four enemies in a line' },
  boomerang: { name: 'Boomerang', key: 'C', color: '#d49a5a', dark: '#6b4423', kind: 'boomerang', dmg: 5,  speed: 440,  size: 7,   range: 230, cd: 0.15, spread: 0.05, tier: 2, weight: 2,   pierce: 99, desc: 'flies out and back, hits both ways' },
  orbiter:   { name: 'Orbiter',   key: 'O', color: '#9bd3ff', dark: '#2c6fa8', kind: 'orbit',     dmg: 6,  speed: 5.5,  size: 6,   life: 3.0,  cd: 0.3,  radius: 64, pierce: 99,   tier: 3, weight: 1.5, shield: 3, recharge: 6, desc: 'circles its segment for 3s, cutting what it sweeps and blocking enemy shots: 3 blocks, then 6s to recharge' },
  moth:      { name: 'Moth',      key: 'M', color: '#f3c6ff', dark: '#8a4aa8', kind: 'homing',    dmg: 4,  speed: 210,  size: 5,   life: 3.5,  cd: 0.3,  turn: 5,      tier: 3, weight: 1.5, desc: 'flutters after the nearest enemy' },
  bouncer:   { name: 'Bouncer',   key: '~', color: '#7df58f', dark: '#2d8a3e', kind: 'bounce',    dmg: 4,  speed: 380,  size: 5,   life: 3.0,  cd: 0.25, bounces: 4,   tier: 3, weight: 1.5, spread: 0.1, desc: 'ricochets off walls' },
  spark:     { name: 'Spark',     key: 'E', color: '#7df9ff', dark: '#2aa6b5', kind: 'shot',      dmg: 2,  speed: 520,  size: 4,   range: 380, cd: 0.3,  spread: 0.05, tier: 2, weight: 1.5, elems: ['electric'], desc: 'chains lightning between enemies' },
  ember:     { name: 'Ember',     key: 'F', color: '#ff8a3d', dark: '#b4451a', kind: 'shot',      dmg: 2,  speed: 360,  size: 5,   range: 300, cd: 0.25, spread: 0.08, tier: 2, weight: 1.5, elems: ['fire'], desc: 'sets enemies burning' },
  frost:     { name: 'Frost',     key: 'I', color: '#cfeeff', dark: '#5aa5d6', kind: 'shot',      dmg: 2,  speed: 480,  size: 4,   range: 360, cd: 0.3,  spread: 0.05, tier: 2, weight: 1.5, elems: ['ice'], desc: 'freezes enemies in place' },
  venom:     { name: 'Venom',     key: 'V', color: '#9bf06a', dark: '#4e8a2e', kind: 'shot',      dmg: 1,  speed: 260,  size: 6,   range: 280, cd: 0.4,  spread: 0.05, tier: 2, weight: 1.5, elems: ['poison'], cloudOnExpire: true, desc: 'bursts into a poison cloud' },
  laughgas:  { name: 'Laughing Gas', key: 'LG', color: '#ffaad8', dark: '#a04a7a', kind: 'shot',   dmg: 2,  speed: 260,  size: 7,   range: 300, cd: 0.5,  spread: 0.06, tier: 3, weight: 1,   minLevel: 5, elems: ['poison'], combo: 'giggle', desc: 'a puff of laughing gas: whatever it poisons goes giddy, harmless, and wanders off giggling until the poison wears off' },
  // head gear (kind 'head': worn on the head, see player-gear.js; dropped rarely, or crafted from the recipes below)
  flamethrower: { name: 'Flamethrower', key: 'Ft', color: '#ff8a3d', dark: '#9a3a10', kind: 'head', icon: 'fire', tier: 3, minLevel: 2, weight: 0, craft: true, dmg: 0, cd: 1, elems: ['fire'], gear: { type: 'cone', el: 'fire', range: 150, arc: 0.5, dps: 14, burn: 3, pipe: 'fire' }, desc: 'head gear: a flower that breathes a cone of fire ahead of you' },
  pumpkin:      { name: 'Pumpkin Head', key: 'PH', color: '#ff9a2a', dark: '#8a4a10', kind: 'head', icon: 'fire', tier: 2, weight: 0, craft: true, dmg: 0, cd: 1, elems: ['fire'], gear: { type: 'cone', el: 'fire', range: 105, arc: 0.45, dps: 7, burn: 1.5, pipe: 'fire' }, desc: 'head gear: a jack-o\'-lantern that breathes a little fire ahead of you (the Flamethrower is the big one)' },
  santahat:     { name: 'Santa Hat',    key: 'SH', color: '#ff4a4a', dark: '#8a1a1a', kind: 'head', icon: 'ice', tier: 3, weight: 0, craft: true, dmg: 0, cd: 1, elems: ['ice'], gear: { type: 'cone', el: 'ice', range: 140, arc: 0.5, dps: 6, freezeEvery: 0.8, pipe: 'ice' }, desc: 'head gear: frosty breath that chills what is ahead, slowing it and freezing it now and then' },
  warhammer:    { name: 'Front Hammer', key: 'FH', color: '#a9b0bc', dark: '#4a505c', kind: 'head', icon: 'hammer', tier: 4, minLevel: 2, weight: 0, craft: true, dmg: 0, cd: 1, gear: { type: 'hammer', dmg: 22, stun: 0.8, push: 50, wallDmg: 30, shatter: 3 }, desc: 'head gear: a battering ram that smashes, stuns and shoves whatever it hits, shatters the frozen (x3) and chips walls; it takes on the element of the segment behind it' },
  bonehelm:     { name: 'Bone Helmet',  key: 'BH', color: '#eae4d4', dark: '#8a7a5a', kind: 'head', icon: 'spear', tier: 3, weight: 0, craft: true, dmg: 0, cd: 1, gear: { type: 'ram', dmg: 14, pipe: 'dry' }, desc: 'head gear: ram monsters to batter them and dry them out (fire then hurts them more)' },
  thundercap:   { name: 'Thundercap',   key: 'Tc', color: '#7df9ff', dark: '#2a7ab5', kind: 'head', icon: 'electric', tier: 3, minLevel: 2, weight: 0, craft: true, dmg: 0, cd: 1, elems: ['electric'], gear: { type: 'arc', range: 230, arc: 0.8, dmg: 9, cd: 0.45, jump: 140, pipe: 'electric' }, desc: 'head gear: a bolt leaps to the nearest monster ahead, then one more' },
  mushcap:      { name: 'Mushroom Cap', key: 'MC', color: '#c58aff', dark: '#5a2a8a', kind: 'head', icon: 'poison', tier: 3, weight: 0, craft: true, dmg: 0, cd: 1, elems: ['poison'], gear: { type: 'spores', cd: 0.3, ahead: 70, r: 34, t: 3, mag: 4, pipe: 'poison' }, desc: 'head gear: puffs poison spores into your path' },
  mine:      { name: 'Mine',      key: 'X', color: '#ff5c5c', dark: '#7a1f1f', kind: 'mine',      dmg: 16, speed: 0,    size: 7,   life: 9,    cd: 0.6,  blast: 95,    tier: 3, weight: 1,   desc: 'dropped behind you, blows up when bugs come near' },
  splitter:  { name: 'Splitter',  key: 'S', color: '#ffb3ff', dark: '#a040a0', kind: 'shot',      dmg: 3,  speed: 400,  size: 6,   range: 300, cd: 0.3,  spread: 0.05, tier: 3, weight: 1,   shards: 4, desc: 'splits into four shards on impact' },
  pulse:     { name: 'Pulse',     key: 'W', color: '#ffffff', dark: '#8888aa', kind: 'pulse',     dmg: 3,  speed: 260,  size: 4,   life: 0.6,  cd: 0.5,  tier: 3, weight: 1,   desc: 'a shockwave ring around you' },
  // level 2 loot (minLevel): the graveyard's weapon. Tip it with an element on the workbench.
  spear:     { name: 'Bone Spear', key: 'Sp', color: '#eae4d4', dark: '#8f8778', kind: 'shot',     dmg: 7,  speed: 640,  size: 5,   range: 440, cd: 0.45, spread: 0.02, tier: 2, weight: 2.5, pierce: 1, spear: true, minLevel: 2, desc: 'a thrown bone that skewers two enemies in a line' },
  // level 5 loot: the circus's weapon. A juggling pin that ricochets off trees, boulders and walls. Tip it on the workbench.
  pin:       { name: 'Juggling Pin', key: 'Jp', color: '#ff5c8a', dark: '#8a1f3a', kind: 'bounce',  dmg: 6,  speed: 420,  size: 6,   life: 2.6,  cd: 0.3, bounces: 3, spread: 0.08, tier: 2, weight: 2.5, pin: true, minLevel: 5, desc: 'a juggling pin that ricochets off three things before it drops' },
};

// Crafting: fuse two bullets on the workbench (pause screen) into ONE new bullet that takes one slot.
// Fused bullets can be fused again. Recipes are unordered pairs: [a, b, result].
const RECIPES = [
  ['spark', 'ember',   'plasma'],
  ['ember', 'venom',   'gas'],
  ['frost', 'spark',   'berserk'],
  ['ember', 'frost',   'steam'],
  ['spark', 'venom',   'charged'],
  ['frost', 'venom',   'acid'],
  ['bullet', 'bullet', 'burst'],
  ['laser', 'bullet',  'rapid'],
  ['rock',  'rock',    'boulderShot'],
  ['plasma', 'laser',  'beam'],
  ['gas',   'rock',    'bomb'],
  ['plasma', 'pebble', 'sparks'],
  ['berserk', 'moth',  'madmoth'],
  ['mine',  'moth',    'bomber'],
  ['pulse', 'berserk', 'sonar'],      // the Pulse's upgrade path: Sonar drives what its ring touches berserk (like the Mad Moth)...
  ['sonar', 'mine',    'sonicboom'],  // ...and Sonic Boom blows each one up as well (like the Bomber Moth)
  ['gas',   'ember',   'napalm'],
  ['plasma', 'frost',  'shatter'],
  ['charged', 'spark', 'arc'],
  ['rapid', 'bullet',  'rapid2'],
  ['ember', 'ember',   'firewalker'],
  ['frost', 'frost',   'icewalker'],
  ['venom', 'venom',   'plaguewalker'],
  ['spark', 'spark',   'stormwalker'],
  ['spear', 'pebble',  'dustwalker'],
  ['ember', 'burst',   'flamethrower'], // head gear (worn on the head, see player-gear.js)
  ['ember', 'pebble',  'pumpkin'],
  ['frost', 'burst',   'santahat'],
  ['spear', 'rock',    'bonehelm'],
  ['rock', 'boulderShot', 'warhammer'],
  ['spark', 'rock',    'thundercap'],
  ['venom', 'pebble',  'mushcap'],   // bone ground with grit into dust: whatever walks through it dries out (fire hurts it more)
  ['spear', 'ember',   'fireSpear'],
  ['spear', 'frost',   'iceSpear'],
  ['spear', 'venom',   'venomSpear'],
  ['spear', 'spark',   'stormSpear'],
  ['spear', 'spear',   'lance'],
  ['boomerang', 'bouncer', 'ricochet'],
  ['ricochet', 'bouncer', 'ricochet2'],
  ['ricochet2', 'bouncer', 'ricochet3'],
  ['pin',   'ember',   'firePin'],
  ['pin',   'frost',   'icePin'],
  ['pin',   'venom',   'venomPin'],
  ['pin',   'spark',   'stormPin'],
  ['pin',   'pin',     'cascade'],
];
const RECIPE_MAP = (() => { const m = new Map(); for (const [x, y, r] of RECIPES) { m.set(x + '+' + y, r); m.set(y + '+' + x, r); } return m; })(); // both orders, built once
function findRecipe(a, b) {
  const hit = RECIPE_MAP.get(a + '+' + b); if (hit) return hit;
  // the Bouncer is also a MODIFIER: any shot-type bullet + Bouncer = that bullet with a ricochet (it reflects off walls, trees and
  // boulders `bounces` times, hitting again after each), and the ricochet levels up with another Bouncer: 3, 6, then 9 bounces
  const base = a === 'bouncer' ? b : b === 'bouncer' ? a : null;
  if (base && base !== 'bouncer' && BULLETS[base] && BULLETS[base].kind === 'shot' && !BULLETS[base].minion && (BULLETS[base].ricLevel || 0) < 3) return ricochetOf(base);
  return null;
}
// derive (and register) the ricochet version of a shot bullet: same bullet, `bounces` wall reflections, one level up
const RIC_NAMES = ['', ' Ricochet', ' Ricochet II', ' Ricochet III'];
function ricochetOf(id) {
  const B = BULLETS[id], level = (B.ricLevel || 0) + 1, root = B.ricRoot || id, nid = root + '_ric' + level;
  if (!BULLETS[nid]) BULLETS[nid] = Object.assign({}, B, { name: (B.ricRoot ? BULLETS[B.ricRoot].name : B.name) + RIC_NAMES[level], key: (B.ricRoot ? BULLETS[B.ricRoot].key : B.key) + 'r', ricLevel: level, ricRoot: root, bounces: 3 * level, tier: Math.min(4, (B.ricRoot ? BULLETS[B.ricRoot].tier : B.tier) + 1), weight: 0, craft: true, elems: (B.elems || []).slice(), desc: `${B.ricRoot ? BULLETS[B.ricRoot].desc : B.desc}; ricochets off walls, trees and boulders ${3 * level} times, hitting again after each bounce` });
  return nid;
}
// Fused bullet types. `combo` is the special effect handled in Combat; craft: true means they never drop as loot.
Object.assign(BULLETS, {
  plasma:   { name: 'Plasma',        key: 'Pl', color: '#9bf7ff', dark: '#2a8ba0', kind: 'shot',   dmg: 5,  speed: 480,  size: 6,   range: 360, cd: 0.28, spread: 0.04, tier: 3, weight: 0, craft: true, elems: ['fire'], pierce: 2, combo: 'plasma', desc: 'white-hot: pierces, fries boulders and armor' },
  gas:      { name: 'Explosive Gas', key: 'Gs', color: '#b6ff5c', dark: '#5a8a1e', kind: 'shot',   dmg: 3,  speed: 280,  size: 7,   range: 300, cd: 0.45, spread: 0.05, tier: 3, weight: 0, craft: true, elems: ['poison'], combo: 'gas', desc: 'detonates on impact in a big blast and melts trees' },
  berserk:  { name: 'Berserk',       key: 'Bk', color: '#ff7bff', dark: '#8a2a8a', kind: 'shot',   dmg: 3,  speed: 500,  size: 5,   range: 380, cd: 0.32, spread: 0.05, tier: 3, weight: 0, craft: true, elems: ['electric'], combo: 'berserk', desc: 'drives enemies mad: they attack each other for 4s' },
  steam:    { name: 'Steam',         key: 'St', color: '#e8f4ff', dark: '#6a8aa0', kind: 'shot',   dmg: 3,  speed: 440,  size: 5,   range: 340, cd: 0.32, spread: 0.05, tier: 3, weight: 0, craft: true, elems: ['ice'], combo: 'steam', desc: 'freezes and leaves a steam cloud that stops enemies shooting' },
  // walkers: the segment lays a patch of its element where it is every `cd` seconds instead of shooting. Patches last `life`s; `dmg` is per second inside one.
  firewalker:   { name: 'Firewalker',   key: 'Fw', color: '#ff9a3d', dark: '#9a3a10', kind: 'trail', trail: 'fire',     dmg: 7, cd: 0.22, life: 3.5, r: 36, tier: 3, weight: 0, craft: true, elems: ['fire'],     desc: 'your tail leaves a trail of fire for 3.5s' },
  icewalker:    { name: 'Icewalker',    key: 'Iw', color: '#cfeeff', dark: '#4a8ab8', kind: 'trail', trail: 'ice',      dmg: 2, cd: 0.22, life: 3.5, r: 36, tier: 3, weight: 0, craft: true, elems: ['ice'],      desc: 'your tail leaves a trail of frost: whatever steps in it freezes' },
  dustwalker:   { name: 'Dustwalker',   key: 'Dw', color: '#e0c890', dark: '#8a7040', kind: 'trail', trail: 'dust',     dmg: 1, cd: 0.22, life: 4,   r: 40, tier: 3, weight: 0, craft: true, desc: 'your tail leaves a trail of bone dust: whatever walks through it dries out, and fire hurts it more' },
  plaguewalker: { name: 'Plaguewalker', key: 'Pw', color: '#9bf06a', dark: '#3f7a2a', kind: 'trail', trail: 'poison',   dmg: 5, cd: 0.22, life: 4.5, r: 40, tier: 3, weight: 0, craft: true, elems: ['poison'],   desc: 'your tail leaves a trail of poison cloud for 4.5s' },
  stormwalker:  { name: 'Stormwalker',  key: 'Sw', color: '#7df9ff', dark: '#2a7ab5', kind: 'trail', trail: 'electric', dmg: 6, cd: 0.22, life: 3,   r: 36, tier: 3, weight: 0, craft: true, elems: ['electric'], desc: 'your tail leaves a crackling trail that zaps and stuns' },
  charged:  { name: 'Charged Cloud', key: 'Cc', color: '#c6ff9b', dark: '#4e8a2e', kind: 'shot',   dmg: 2,  speed: 280,  size: 6,   range: 300, cd: 0.4,  spread: 0.05, tier: 3, weight: 0, craft: true, elems: ['poison'], cloudOnExpire: true, combo: 'charged', desc: 'poison clouds that crackle with lightning' },
  acid:     { name: 'Acid Frost',    key: 'Af', color: '#b8f0d8', dark: '#3a8a6a', kind: 'shot',   dmg: 3,  speed: 460,  size: 5,   range: 360, cd: 0.32, spread: 0.05, tier: 3, weight: 0, craft: true, elems: ['ice', 'poison'], desc: 'freezes and poisons in one shot' },
  rapid:    { name: 'Rapid Laser',   key: 'RL', color: '#ff7eb6', dark: '#b5386f', kind: 'shot',   dmg: 2,  speed: 1000, size: 2.5, range: 560, cd: 0.06, spread: 0.02, tier: 3, weight: 0, craft: true, pierce: 3, laser: true, maxInflight: 3, combo: 'rapid', desc: 'keeps three lasers in the air' },
  rapid2:   { name: 'Rapid Laser II',key: 'R2', color: '#ff9ec8', dark: '#b5386f', kind: 'shot',   dmg: 2,  speed: 1000, size: 2.5, range: 560, cd: 0.04, spread: 0.03, tier: 4, weight: 0, craft: true, pierce: 3, laser: true, maxInflight: 5, combo: 'rapid', desc: 'keeps five lasers in the air' },
  boulderShot: { name: 'Boulder',    key: 'Bo', color: '#8f8578', dark: '#4a4038', kind: 'shot',   dmg: 30, speed: 240,  size: 14,  range: 340, cd: 1.1,  spread: 0.02, tier: 3, weight: 0, craft: true, wallMult: 5, pierce: 1, desc: 'a boulder. Slow, crushing, wrecks walls' },
  beam:     { name: 'Beam',          key: 'Bm', color: '#ffffff', dark: '#88aacc', kind: 'shot',   dmg: 6,  speed: 1100, size: 3,   range: 640, cd: 0.1,  spread: 0,    tier: 4, weight: 0, craft: true, pierce: 99, laser: true, beam: true, elems: ['fire'], combo: 'beam', desc: 'pierces everything on its line' },
  bomb:     { name: 'Gas Bomb',      key: 'GB', color: '#d6ff7a', dark: '#5a8a1e', kind: 'shot',   dmg: 14, speed: 280,  size: 10,  range: 340, cd: 0.8,  spread: 0.03, tier: 4, weight: 0, craft: true, elems: ['poison'], wallMult: 4, combo: 'bomb', desc: 'a rock that bursts into a poison blast and wrecks walls' },
  sparks:   { name: 'Plasma Sparks', key: 'PS', color: '#bffcff', dark: '#2a8ba0', kind: 'shot',   dmg: 4,  speed: 520,  size: 3.5, range: 300, cd: 0.1,  spread: 0.15, tier: 4, weight: 0, craft: true, elems: ['fire'], combo: 'plasma', desc: 'quick white-hot pebbles that fry armor' },
  madmoth:  { name: 'Mad Moth',      key: 'MM', color: '#ff9bff', dark: '#8a2a8a', kind: 'homing', dmg: 4,  speed: 220,  size: 5,   life: 3.5,  cd: 0.3,  turn: 5,      tier: 4, weight: 0, craft: true, elems: ['electric'], combo: 'madmoth', desc: 'homing, and whatever it catches goes berserk' },
  sonar:    { name: 'Sonar',         key: 'So', color: '#ffb0ff', dark: '#8a3a9a', kind: 'pulse',  dmg: 4,  speed: 280,  size: 4,   life: 0.65, cd: 0.5,  tier: 4, weight: 0, craft: true, combo: 'sonar', desc: 'a shockwave ring, and whatever it touches goes berserk' },
  sonicboom:{ name: 'Sonic Boom',    key: 'SB', color: '#ffc28a', dark: '#9a4a1a', kind: 'pulse',  dmg: 5,  speed: 300,  size: 4,   life: 0.7,  cd: 0.6,  tier: 4, weight: 0, craft: true, combo: 'sonicboom', blast: 75, blastMult: 2.5, desc: 'a shockwave ring: whatever it touches goes berserk and blows up' },
  bomber:   { name: 'Bomber Moth',   key: 'BM', color: '#ffb0a0', dark: '#8a2a2a', kind: 'homing', dmg: 16, speed: 190,  size: 6,   life: 4,    cd: 0.7,  turn: 4.5,    blast: 90, tier: 4, weight: 0, craft: true, combo: 'bomber', desc: 'a moth with a mine strapped on: flutters after the nearest enemy and blows up on it' },
  napalm:   { name: 'Napalm',        key: 'Np', color: '#ff6a2a', dark: '#8a2a10', kind: 'shot',   dmg: 4,  speed: 340,  size: 6,   range: 300, cd: 0.3,  spread: 0.06, tier: 4, weight: 0, craft: true, elems: ['fire'], combo: 'napalm', desc: 'burns three times hotter, the burn spreads, melts trees' },
  shatter:  { name: 'Shatter',       key: 'Sh', color: '#ffd9c2', dark: '#a0604a', kind: 'shot',   dmg: 5,  speed: 480,  size: 6,   range: 360, cd: 0.3,  spread: 0.04, tier: 4, weight: 0, craft: true, elems: ['ice'], pierce: 1, combo: 'shatter', desc: 'freezes, and shatters frozen enemies for triple damage' },
  arc:      { name: 'Arc Venom',     key: 'Av', color: '#aaff66', dark: '#4e8a2e', kind: 'shot',   dmg: 3,  speed: 520,  size: 5,   range: 400, cd: 0.3,  spread: 0.05, tier: 4, weight: 0, craft: true, elems: ['electric'], combo: 'arc', desc: 'lightning chains twice as far and poisons everything it touches' },
  // the Ricochet: a boomerang that hops from enemy to enemy (`hops` hits, each within `chainR` px of the last), then flies home. Fuse a Bouncer in again for more hops (three is the cap).
  ricochet:  { name: 'Chain Boomerang', key: 'CB', color: '#e0c070', dark: '#6b4423', kind: 'chain', dmg: 5, speed: 520, size: 7, range: 340, chainR: 340, hops: 4,  cd: 0.3, spread: 0.03, tier: 3, weight: 0, craft: true, desc: 'a boomerang that bounces between enemies: four hits, then it comes home' },
  ricochet2: { name: 'Chain Boomerang II', key: 'C2', color: '#ffd880', dark: '#6b4423', kind: 'chain', dmg: 6, speed: 560, size: 7, range: 360, chainR: 380, hops: 7,  cd: 0.3, spread: 0.03, tier: 4, weight: 0, craft: true, desc: 'bounces between enemies seven times before it comes home' },
  ricochet3: { name: 'Chain Boomerang III', key: 'C3', color: '#fff0b0', dark: '#6b4423', kind: 'chain', dmg: 7, speed: 600, size: 7, range: 380, chainR: 420, hops: 11, cd: 0.3, spread: 0.03, tier: 4, weight: 0, craft: true, desc: 'eleven hops between enemies, then home: a whole crowd from one throw' },
  // tipped bone spears: the graveyard's weapon with an element on the point (skewers three)
  fireSpear:  { name: 'Fire Spear',  key: 'FS', color: '#ffb36b', dark: '#9a3a10', kind: 'shot', dmg: 8, speed: 660, size: 5, range: 460, cd: 0.42, spread: 0.02, tier: 3, weight: 0, craft: true, spear: true, pierce: 2, elems: ['fire'],     desc: 'a bone spear tipped with fire: skewers three and sets them burning' },
  iceSpear:   { name: 'Ice Spear',   key: 'IS', color: '#dff4ff', dark: '#4a8ab8', kind: 'shot', dmg: 8, speed: 660, size: 5, range: 460, cd: 0.42, spread: 0.02, tier: 3, weight: 0, craft: true, spear: true, pierce: 2, elems: ['ice'],      desc: 'a bone spear tipped with frost: skewers three and freezes them' },
  venomSpear: { name: 'Venom Spear', key: 'VS', color: '#c8f0a8', dark: '#4e8a2e', kind: 'shot', dmg: 8, speed: 660, size: 5, range: 460, cd: 0.42, spread: 0.02, tier: 3, weight: 0, craft: true, spear: true, pierce: 2, elems: ['poison'], cloudOnExpire: true, desc: 'a bone spear tipped with venom: skewers three and leaves poison where it lands' },
  stormSpear: { name: 'Storm Spear', key: 'SS', color: '#bff6ff', dark: '#2a7ab5', kind: 'shot', dmg: 8, speed: 660, size: 5, range: 460, cd: 0.42, spread: 0.02, tier: 3, weight: 0, craft: true, spear: true, pierce: 2, elems: ['electric'], desc: 'a bone spear tipped with lightning: skewers three and chains between them' },
  lance:      { name: 'Bone Lance',  key: 'BL', color: '#fff5e0', dark: '#8f8778', kind: 'shot', dmg: 14, speed: 760, size: 6, range: 520, cd: 0.6, spread: 0.01, tier: 4, weight: 0, craft: true, spear: true, pierce: 99, wallMult: 2, desc: 'two spears bound into one: runs through everything in a line and cracks walls' },
  // tipped juggling pins: the circus's weapon with an element, bouncing five times; two pins make a juggling cascade
  firePin:  { name: 'Flaming Hoop',   key: 'FH', color: '#ff8a5c', dark: '#8a1f3a', kind: 'bounce', dmg: 7, speed: 440, size: 6, life: 3.0, cd: 0.28, bounces: 5, spread: 0.08, tier: 3, weight: 0, craft: true, pin: true, elems: ['fire'],     desc: 'a pin on fire: ricochets five times and sets everything it clips burning' },
  icePin:   { name: 'Frozen Custard', key: 'FC', color: '#dff4ff', dark: '#4a8ab8', kind: 'bounce', dmg: 7, speed: 440, size: 6, life: 3.0, cd: 0.28, bounces: 5, spread: 0.08, tier: 3, weight: 0, craft: true, pin: true, elems: ['ice'],      desc: 'a pin dipped in frozen custard: ricochets five times and freezes what it hits' },
  venomPin: { name: 'Pie Pin',        key: 'PP', color: '#c8f0a8', dark: '#4e8a2e', kind: 'bounce', dmg: 7, speed: 440, size: 6, life: 3.0, cd: 0.28, bounces: 5, spread: 0.08, tier: 3, weight: 0, craft: true, pin: true, elems: ['poison'], cloudOnExpire: true, desc: 'a pin with a rancid cream pie on top: ricochets five times and leaves poison where it drops' },
  stormPin: { name: 'Static Pin',     key: 'SP', color: '#bff6ff', dark: '#2a7ab5', kind: 'bounce', dmg: 7, speed: 440, size: 6, life: 3.0, cd: 0.28, bounces: 5, spread: 0.08, tier: 3, weight: 0, craft: true, pin: true, elems: ['electric'], desc: 'a pin rubbed on a balloon: ricochets five times and chains lightning between the things it clips' },
  cascade:  { name: 'Cascade',        key: 'Cs', color: '#ff9ec8', dark: '#8a1f3a', kind: 'bounce', dmg: 5, speed: 460, size: 5, life: 2.8, cd: 0.4, bounces: 4, spread: 0.12, tier: 4, weight: 0, craft: true, pin: true, burst: 5, desc: 'five pins in the air at once, every one ricocheting: the screen fills with pins' },
});
// Piping (incidental): the segment ahead leaks a minor status into this segment's hits. No slot cost, no crafting.
// synergy families beyond the four elements: bone (the spears and the lance), with what the workbench shows for it
const SYN_EXTRA = { bone: { name: 'Bone', color: '#eae4d4', icon: 'spear', has: B => !!B.spear || B.trail === 'dust' } };
const synInfo = el => ELEMENTS[el] ? { name: ELEMENTS[el].name, color: ELEMENTS[el].color, icon: el } : SYN_EXTRA[el];
const PIPES = {
  ice:      { tag: 'chill',  color: '#cfeeff', desc: 'hits slow enemies for 1.2s' },
  electric: { tag: 'jolt',   color: '#7df9ff', desc: 'hits stun for a moment' },
  fire:     { tag: 'singe',  color: '#ff8a3d', desc: 'hits leave a small burn' },
  poison:   { tag: 'taint',  color: '#9bf06a', desc: 'hits leave a small poison' },
  dazzle:   { tag: 'dazzle', color: '#ffffff', desc: 'hits blind enemies so they cannot shoot' },
  ricochet: { tag: 'ricochet', color: '#ffd166', desc: 'shots bounce off walls, trees and boulders' },
  burst:    { tag: 'burst',  color: '#ffe08a', desc: 'each shot brings a second one beside it' }, // behind a Burst: every shot fires with a twin at PIPE_BURST.mult of its damage
  dry:      { tag: 'dry',    color: '#e0c890', desc: 'hits dry enemies out: fire hurts them more, ice will not take' }, // behind a plain Spear or a Lance
  homing:   { tag: 'homing', color: '#f3c6ff', desc: 'shots curve toward the nearest enemy' }, // behind a moth (a Moth, Mad Moth or Bomber Moth): plain shots bend gently after what is near (PIPE_HOMING)
  split:    { tag: 'split',  color: '#ffb3ff', desc: 'hits burst into two shards' },  // behind a Splitter, a Cascade or a Shatter: every hit (every hop of a chain, every moth landing) throws two shards at a third of the damage, six per bullet at most
};
const ELEMENTS = {
  electric: { name: 'Electric', color: '#7df9ff', dark: '#2aa6b5', key: 'E', desc: 'Sparks nearby enemies, arcs damage between them' },
  poison:   { name: 'Poison',   color: '#9bf06a', dark: '#4e8a2e', key: 'V', desc: 'Leaves a poison cloud where it hits' },
  fire:     { name: 'Fire',     color: '#ff8a3d', dark: '#b4451a', key: 'F', desc: 'Burns over time, magnitude stacks' },
  ice:      { name: 'Ice',      color: '#cfeeff', dark: '#5aa5d6', key: 'I', desc: 'Freezes in place, frozen enemies take +25%' },
};

// Parasites are fast purple spiders that pick a random segment, latch on, and jam that segment AND everything
// behind it for `hold` seconds while they feed, then die.
// They start slow and ramp to full speed over RAMP seconds of hunting, chittering louder as they close in.
// Shoot them before they reach you: hp + hpR*region, speed + speedR*region.
const PARASITE_RAMP = 3.0; // seconds from a creep (30% speed) to a full sprint
const PARASITES = {
  leech:  { name: 'Leech',  color: '#8a3fc4', dark: '#4a1f70', key: 'L', hp: 18, hpR: 6, speed: 225, speedR: 15, hold: 6, desc: 'feeds for 6s: the bitten segment and everything behind it stop firing' },
  slug:   { name: 'Slug',   color: '#6e4a9e', dark: '#3b2358', key: 'S', hp: 16, hpR: 5, speed: 195, speedR: 14, hold: 7, desc: 'feeds for 7s and slows you 15%: the bitten segment and everything behind it stop firing' },
  jammer: { name: 'Jammer', color: '#5a3b8f', dark: '#2c1a4a', key: 'J', hp: 14, hpR: 5, speed: 255, speedR: 18, hold: 5, desc: 'feeds for 5s: the bitten segment and everything behind it stop firing' },
};

// Minion segments: one pseudo-bullet per parasite type, so the body code treats a riding minion like any segment.
// They never drop (craft: true), take no slot (minion: true is what Player.weaponCount skips), and fire through Minions.spit.
for (const id of Object.keys(PARASITES)) { const Pz = PARASITES[id]; BULLETS['minion_' + id] = { name: `${Pz.name} minion`, key: Pz.key, color: Pz.color, dark: Pz.dark, kind: 'minion', minion: id, dmg: 0, speed: 0, size: 0, range: 0, cd: 0.55, tier: 0, weight: 0, craft: true, desc: 'rides your tail, takes no slot, spits at enemies, dives into bosses' }; }

const BIOMES = {
  meadow:  { name: 'Meadow',   grass: '#7cc26a', grass2: '#8fd27a', trees: 14, lakes: 2, boulders: 3 },
  forest:  { name: 'Forest',   grass: '#5ea85a', grass2: '#6fb867', trees: 38, lakes: 1, boulders: 4 },
  wetland: { name: 'Wetlands', grass: '#6fbf8f', grass2: '#82cf9e', trees: 10, lakes: 7, boulders: 2 },
  rocky:   { name: 'Badlands', grass: '#b7b07a', grass2: '#c7bf88', trees: 6,  lakes: 1, boulders: 14 },
  arena:   { name: 'Boss Arena', grass: '#c9b48a', grass2: '#dcc9a0', trees: 0,  lakes: 0, boulders: 5 },
  // level 2: every region past the first boss is haunted. Grey ground, charred trees, tombstones, bone snakes, flaming skulls.
  graveyard: { name: 'Graveyard', grass: '#5e5f68', grass2: '#7a7b84', trees: 16, lakes: 2, boulders: 2, graves: 9, haunted: true },
  ossuary:   { name: 'Bone Arena', grass: '#8a867c', grass2: '#a7a297', trees: 0,  lakes: 0, boulders: 0, graves: 0, haunted: true },
  // level 3: a jungle over an Aztec city. Deep green, palms and broadleaf trees, carved stone heads for boulders, temple doors.
  jungle:    { name: 'Jungle',   grass: '#2e6b35', grass2: '#3f8a45', trees: 34, lakes: 3, boulders: 5, jungle: true },
  ziggurat:  { name: 'Temple Arena', grass: '#8a7a4a', grass2: '#a8966a', trees: 0, lakes: 0, boulders: 0, jungle: true },
  // level 4: farmland under a sickly green sky. Crop rows, crop circles, barns, scarecrows, hay bales for boulders, saucer hatches for dungeons.
  farm:      { name: 'Farmland', grass: '#9aa84a', grass2: '#b4bf5e', trees: 18, lakes: 2, boulders: 6, farm: true },
  landing:   { name: 'Landing Site', grass: '#8a9a3e', grass2: '#a3b050', trees: 0, lakes: 0, boulders: 0, farm: true },
  // level 5: the midway of an evil circus, and pure psychedelia. The ground colours cycle (Render.drawWorld), mushrooms for
  // trees, beach balls for boulders, circus cannons to fire yourself from, big-top tents for dungeons.
  midway:    { name: 'Midway',    grass: '#8a4ab8', grass2: '#c45ab0', trees: 14, lakes: 1, boulders: 5, cannons: 2, circus: true },
  bigtop:    { name: 'Centre Ring', grass: '#9a3a7a', grass2: '#c8508a', trees: 0, lakes: 0, boulders: 0, cannons: 1, circus: true },
};
// Bosses, one per level. hp is set when the boss wakes from an estimate of your build's damage output (Player.estimateDps:
// cooldowns, flight, pierce, elements, fusions, the level ramp and Twin Shot): max(hpMin, estimatedDps * fightSecs) + hpL per
// level. fightSecs is the fight a build that hits 60% of the time should get; megas and minion dives shorten it. A weak build is not walled.
// hpCap stops the scaling: past it, more damage means a shorter fight, so a monster build melts a boss instead of always getting the same minute.
// keep = distance it tries to hold from you (short-range bullets still reach).
const BOSSES = {
  crab: { aimLevel: 3, name: 'King Crab', // aimLevel: from this level on, a crab's claw shot keeps tracking you through the charge and re-aims as it fires (the hard crab); below it the shot goes where you were when the claw lit up
          r: 54, hpMin: 600, hpL: 300, fightSecs: 42, hpCap: 24000, keep: 300, speed: 95, power: 2, armor: 1, score: 2000, color: '#ff7a5c', dark: '#8a2a1a',
          throwCd: 8.0, throwN: 2, throwHp: 12, throwHpL: 6, stoneCd: 7.0, shieldN: 3, shieldRefill: 6.0, stoneHp: 20, stonePower: 1, evadeCd: 2.2, evadeChance: 0.5, megaResist: 0.5, rise: 'rises from the sand', desc: 'throws parasites, sidesteps your shots, and rolls stones that soak bullets' },
  // level 2: a giant floating skull. Its signature move is the STOMP: a shadow grows where you are about to be, then a
  // skeletal foot slams down over stompR px (stompDmg segments, bone shards fly out). It also breathes fireballs and calls flaming skulls.
  skull: { name: 'Skull King', r: 58, hpMin: 1000, hpL: 400, fightSecs: 48, hpCap: 45000, keep: 360, speed: 85, power: 2, armor: 1, score: 4000, color: '#eae4d4', dark: '#6a6456',
           stompCd: 6.5, stompWarn: 1.3, stompR: 175, stompDmg: 2, stompShards: 8, fireCd: 4.5, fireN: 3, ringCd: 9, ringN: 16, summonCd: 11, summonN: 2, megaResist: 0.5, foot: true, music: 'skull',
           rise: 'rises from the bone pile', desc: 'floats out of reach, breathes fireballs and rings of fire, calls flaming skulls, and brings a skeletal foot down on you: get out of the shadow' },
  // level 3: a great feathered snake. It slithers around you, spits venom globs that burst into small lingering clouds
  // (a cloud costs a segment if you sit in it), its body bites on contact, and it sends hatchling snakes after you.
  serpent: { name: 'Jungle Serpent', r: 38, hpMin: 1400, hpL: 450, fightSecs: 52, hpCap: 70000, keep: 330, speed: 150, power: 2, armor: 1, score: 6000, color: '#5ec85a', dark: '#2a5a24',
             segN: 14, segR: 20, spitCd: 3.4, spitN: 3, cloudR: 55, cloudT: 2.6, broodCd: 9, broodN: 2, megaResist: 0.5, serpent: true, music: 'serpent',
             rise: 'uncoils from the pyramid', desc: 'a great feathered snake: it spits venom that lingers in small clouds, its coils bite, and it sends hatchlings after you' },
  // level 4: a big-brained alien overlord on a hovering disc. A sweeping laser beam, a tractor beam that ABDUCTS your
  // segments (time slows while it happens) and turns them into its own guns, and it lands its saucer on you like a foot.
  overlord: { name: 'Brain Overlord', r: 46, hpMin: 5400, hpL: 1560, fightSecs: 168, hpCap: 300000, // (tripled: at 56s he fell before a second abduction)
              keep: 380, speed: 110, power: 2, armor: 1, score: 8000, color: '#b7d9a8', dark: '#4a6a3a',
              beamCd: 7, beamWarn: 0.9, beamT: 1.4, beamSweep: 0.9, beamLen: 900, abductCd: 5.5, abductWarn: 0.9, abductR: 80, abductN: 2, captiveCd: 0.9,
              stompCd: 9, stompWarn: 1.5, stompR: 160, stompDmg: 2, stompShards: 0, summonCd: 10, summonN: 2, megaResist: 0.5, alien: true, music: 'alien',
              rise: 'beams down from the mothership', desc: 'sweeps a laser, abducts your segments and fires them back at you, and lands its saucer on you' },
  // level 5: a tiny car with far too many clowns in it. It BURST-RUNS at you (a warning rev, then a charge), then sputters
  // OUT OF GAS and sits refuelling, wide open; its doors fly open and kamikaze clowns run out with mallets; the horn shoves you
  // and your bullets back; it drops laughing gas behind it when it runs. At half hp the doors fall off and the clowns never stop.
  clowncar: { name: 'Clown Car', r: 46, hpMin: 2400, hpL: 600, fightSecs: 62, hpCap: 140000, keep: 320, speed: 125, power: 2, armor: 1, score: 10000, color: '#ff5c8a', dark: '#7a1f3a',
              runCd: 7, runWarn: 0.8, runT: 1.9, runSpeed: 540, runDmg: 2, gasT: 3.2, gasMult: 1.6, clownCd: 6, clownN: 3, hornCd: 9, hornR: 230, hornPush: 170, flowerCd: 8, flowerN: 6,
              megaResist: 0.5, car: true, music: 'clown', rise: 'screeches into the ring', desc: 'burst-runs you down until it runs out of gas (hit it while it refuels), fires kamikaze clowns with mallets, honks you back, and sprays you with its flower' },
};

// Bugs come in sizes. All of them shoot back. weight(region) controls how often each spawns.
// loot = { chance, tier, n }: odds of a drop per kill, how potent, how many rolls. Tougher bug, better loot.
// armor = fraction of damage plain bullets do (elements and combos ignore it; plasma does extra).
const BUGS = {
  gnat:     { name: 'Gnat',          r: 6,  hp: 2,   hpR: 1,  speed: 150, speedR: 5, power: 1, fragile: true,  flying: true,  ai: 'swarm',  range: 260, cd: 2.6,
              shot: { n: 1, spread: 0,    speed: 300, power: 1, size: 3, color: '#ffe66d' }, color: '#c9f06a', dark: '#5f8a2e', score: 4,   loot: { chance: 0.2,  tier: 1, n: 1 }, weight: r => 2 + r * 0.3 },
  beetle:   { name: 'Beetle',        r: 10, hp: 4,   hpR: 2,  speed: 48,  speedR: 4, power: 1, fragile: true,  flying: false, ai: 'wander', range: 340, cd: 3.2,
              shot: { n: 1, spread: 0,    speed: 240, power: 1, size: 5, color: '#9ef04c' }, color: '#6ec6ff', dark: '#2a6fa8', score: 5,   loot: { chance: 0.4,  tier: 1, n: 1 }, weight: r => 3 },
  stinkbug: { name: 'Stinkbug',      r: 16, hp: 13,  hpR: 4,  speed: 55,  speedR: 3, power: 2, fragile: false, flying: false, ai: 'keep',   range: 380, cd: 3.6,
              shot: { n: 3, spread: 0.32, speed: 230, power: 1, size: 6, color: '#a3e06b' }, color: '#7fa85c', dark: '#3f5e2a', score: 15,  loot: { chance: 0.7,  tier: 2, n: 1 }, armor: 0.35, weight: r => r >= 1 ? 1 + r * 0.3 : 0 },
  hornet:   { name: 'Hornet',        r: 12, hp: 9,   hpR: 4,  speed: 210, speedR: 8, power: 1, fragile: false, flying: true,  ai: 'dive',   range: 300, cd: 2.4,
              shot: { n: 2, spread: 0.12, speed: 380, power: 1, size: 4, color: '#ffb347' }, color: '#ffc83d', dark: '#3a2a1a', score: 12,  loot: { chance: 0.6,  tier: 2, n: 1 }, weight: r => r >= 2 ? 1.5 : 0 },
  queen:    { name: 'Hornet Queen',  r: 26, hp: 120, hpR: 40, speed: 38,  speedR: 2, power: 3, fragile: false, flying: true,  ai: 'boss',   range: 520, cd: 3.8,
              shot: { n: 1, spread: 0,    speed: 260, power: 2, size: 9, color: '#ff6b9d', ring: 10, ringSpeed: 170 }, color: '#f08ad0', dark: '#6a2a5a', score: 150, loot: { chance: 1,    tier: 3, n: 3 }, armor: 0.5, weight: r => r >= 4 ? 0.35 : 0, boss: true },
  // the graveyard's own monster (level 2+): a skull wreathed in fire that dive-bombs you and spits fireballs. Fire does nothing to it.
  skull:    { name: 'Flaming Skull', r: 13, hp: 12,  hpR: 5,  speed: 185, speedR: 7, power: 2, fragile: false, flying: true,  ai: 'dive',   range: 330, cd: 2.8,
              shot: { n: 1, spread: 0,    speed: 330, power: 1, size: 6, color: '#ff8a3d' }, color: '#eae4d4', dark: '#2b1d3a', score: 18,  loot: { chance: 0.6,  tier: 2, n: 1 }, weight: r => World.levelOf(r) === 2 ? 5 : 0 },
  // the jungle's monsters (level 3+)
  earwig:   { name: 'Earwig',        r: 12, hp: 10,  hpR: 5,  speed: 125, speedR: 6, power: 1, fragile: false, flying: false, ai: 'lunge',  range: 220, cd: 3.4,
              shot: { n: 1, spread: 0,    speed: 300, power: 1, size: 4, color: '#c9a26b' }, color: '#8a5a2a', dark: '#3a2010', score: 14,  loot: { chance: 0.55, tier: 2, n: 1 }, weight: r => World.levelOf(r) === 3 ? 6 : 0 },
  roach:    { name: 'Flying Cockroach', r: 11, hp: 14, hpR: 5, speed: 235, speedR: 8, power: 1, fragile: false, flying: true, ai: 'swarm', range: 300, cd: 3.0,
              shot: { n: 1, spread: 0,    speed: 320, power: 1, size: 4, color: '#d9b36b' }, color: '#6b3a1a', dark: '#2a1408', score: 16,  loot: { chance: 0.55, tier: 2, n: 1 }, armor: 0.5, weight: r => World.levelOf(r) === 3 ? 5 : 0 },
  // sits still, flicks its tongue out to `range` when you are in reach: a hit on a segment slurps it off the body, a hit on the head costs one
  iguana:   { name: 'Iguana',        r: 18, hp: 40,  hpR: 10, speed: 0,   speedR: 0, power: 2, fragile: false, flying: false, ai: 'tongue', range: 300, cd: 3.2, tongue: 950,
              shot: { n: 1, spread: 0,    speed: 280, power: 1, size: 5, color: '#9ef04c' }, color: '#7fbf4a', dark: '#2f6a2a', score: 30,  loot: { chance: 0.8,  tier: 3, n: 1 }, weight: r => World.levelOf(r) === 3 ? 3 : 0 },
  // the farm's invaders (level 4+): gawky 1950s aliens
  brainling:{ name: 'Brainling',     r: 12, hp: 14,  hpR: 5,  speed: 70,  speedR: 4, power: 1, fragile: false, flying: false, ai: 'keep',   range: 360, cd: 2.6,
              shot: { n: 1, spread: 0,    speed: 420, power: 1, size: 4, color: '#ff5cf0' }, color: '#b7d9a8', dark: '#4a6a3a', score: 16,  loot: { chance: 0.55, tier: 2, n: 1 }, weight: r => World.levelOf(r) === 4 ? 6 : 0 }, // a big brain on little legs with a ray gun
  saucer:   { name: 'Scout Saucer',  r: 14, hp: 18,  hpR: 6,  speed: 200, speedR: 8, power: 1, fragile: false, flying: true,  ai: 'dive',   range: 320, cd: 3.2,
              shot: { n: 3, spread: 0.25, speed: 340, power: 1, size: 4, color: '#7df9ff' }, color: '#c9ced6', dark: '#4a5060', score: 20,  loot: { chance: 0.6,  tier: 2, n: 1 }, armor: 0.6, weight: r => World.levelOf(r) === 4 ? 4 : 0 },
  mooncow:  { name: 'Abducted Cow',  r: 16, hp: 26,  hpR: 7,  speed: 160, speedR: 6, power: 2, fragile: false, flying: true,  ai: 'swarm',  range: 0,   cd: 99,
              shot: { n: 1, spread: 0,    speed: 200, power: 1, size: 4, color: '#fff' }, color: '#f3efe6', dark: '#2b1d3a', score: 24,  loot: { chance: 0.7,  tier: 2, n: 1 }, weight: r => World.levelOf(r) === 4 ? 2.5 : 0 }, // floats about in a beam, mooing, and rams you
  // the circus (level 5+): evil clowns and things that should not be
  unicycle: { name: 'Unicycle Clown', clown: true, r: 12, hp: 14, hpR: 5,  speed: 240, speedR: 9, power: 1, fragile: false, flying: false, ai: 'swarm',  range: 260, cd: 3.0,
              shot: { n: 1, spread: 0,    speed: 320, power: 1, size: 5, color: '#ffd166' }, color: '#ff5c8a', dark: '#7a1f3a', score: 16,  loot: { chance: 0.55, tier: 2, n: 1 }, weight: r => World.levelOf(r) >= 5 ? 7 : 0 }, // zigzags at you on one wheel, honking
  juggler:  { name: 'Juggler', clown: true,       r: 13, hp: 18,  hpR: 6,  speed: 85,  speedR: 4, power: 1, fragile: false, flying: false, ai: 'keep',   range: 420, cd: 2.8,
              shot: { n: 3, spread: 0.3,  speed: 300, power: 1, size: 6, color: '#ff8a3d', bounce: 2, life: 3.2 }, color: '#f3c6ff', dark: '#6a2a8a', score: 20, loot: { chance: 0.6, tier: 2, n: 1 }, weight: r => World.levelOf(r) >= 5 ? 4 : 0 }, // lobs pins that ricochet off boulders
  mime:     { name: 'Mime',          r: 12, hp: 30,  hpR: 8,  speed: 0,   speedR: 0, power: 1, fragile: false, flying: false, ai: 'mime',   range: 0,   cd: 99, wall: 150,
              shot: { n: 1, spread: 0,    speed: 300, power: 1, size: 4, color: '#fff' }, color: '#f5f5f5', dark: '#2b1d3a', score: 24,  loot: { chance: 0.7,  tier: 2, n: 1 }, weight: r => World.levelOf(r) >= 5 ? 2.5 : 0 }, // stands still and pantomimes an invisible wall that stops your bullets and your body
  pieclown: { name: 'Pie Clown', clown: true,     r: 13, hp: 16,  hpR: 6,  speed: 95,  speedR: 5, power: 1, fragile: false, flying: false, ai: 'keep',   range: 380, cd: 3.4,
              shot: { n: 1, spread: 0,    speed: 280, power: 0, size: 9, color: '#fff5e0', pie: true, life: 2.2 }, color: '#7df9ff', dark: '#1f4a6a', score: 18, loot: { chance: 0.55, tier: 2, n: 1 }, weight: r => World.levelOf(r) >= 5 ? 3.5 : 0 }, // cream pies: they splat on your SCREEN, not your body
  elephant: { name: 'Pink Elephant', r: 24, hp: 110, hpR: 30, speed: 170, speedR: 6, power: 2, fragile: false, flying: false, ai: 'ball',   range: 0,   cd: 99,
              shot: { n: 1, spread: 0,    speed: 200, power: 1, size: 4, color: '#fff' }, color: '#ffb3d9', dark: '#8a3a6a', score: 80,  loot: { chance: 1,    tier: 3, n: 2 }, armor: 0.6, weight: r => World.levelOf(r) >= 5 ? 0.6 : 0 }, // balances on a ball and bounces off everything
  blob:     { name: 'Lava Blob',     r: 15, hp: 22,  hpR: 7,  speed: 70,  speedR: 4, power: 1, fragile: false, flying: true,  ai: 'wander', range: 300, cd: 3.6, split: true,
              shot: { n: 1, spread: 0,    speed: 240, power: 1, size: 5, color: '#ff8aff' }, color: '#ff7bff', dark: '#6a1f8a', score: 10,  loot: { chance: 0.4,  tier: 2, n: 1 }, weight: r => World.levelOf(r) >= 5 ? 3 : 0 }, // a lava-lamp blob: splits in two when it dies, until the halves are too small
  kamighost:{ name: 'Grave Ghost', ghost: true, r: 13, hp: 8, hpR: 3,  speed: 235, speedR: 6, power: 1, fragile: true,  flying: true,  ai: 'kami',   range: 0,   cd: 99, bop: true,
              shot: { n: 1, spread: 0,    speed: 200, power: 1, size: 4, color: '#fff' }, color: '#dfe6ff', dark: '#4a5070', score: 6,   loot: { chance: 0.1, tier: 1, n: 1 }, weight: r => 0 }, // nightmare Skull King: rises from an unmarked grave and dives at you, bursting on contact
  kamiclown:{ name: 'Kamikaze Clown', clown: true, r: 11, hp: 10, hpR: 3,  speed: 250, speedR: 8, power: 1, fragile: true,  flying: false, ai: 'kami',   range: 0,   cd: 99, bop: true,
              shot: { n: 1, spread: 0,    speed: 200, power: 1, size: 4, color: '#fff' }, color: '#ffd166', dark: '#7a1f3a', score: 6,   loot: { chance: 0.15, tier: 1, n: 1 }, weight: r => 0 }, // runs straight at you with a mallet, bops once, bursts into confetti
  chicken:  { name: 'Chicken',       r: 10, hp: 4,   hpR: 0,  speed: 120, speedR: 0, power: 0, fragile: true,  flying: false, ai: 'flee',   range: 0,   cd: 99,
              shot: { n: 1, spread: 0,    speed: 200, power: 1, size: 4, color: '#fff' }, color: '#fff5e0', dark: '#8a5a3a', score: 3,   loot: { chance: 0.8,  tier: 2, n: 1 }, weight: r => 0 }, // what the fortune teller turns monsters into: harmless, panicky, drops loot
};

// Rare monsters: a spawned snake or bug may roll one affix (CFG.RARE). Rares are named, glow gold, always drop loot
// one tier up, and drop a few dots. Each affix is a stat tweak applied in Enemies.makeRare.
const AFFIXES = {
  giant:   { name: 'Giant',    color: '#ffd166', desc: 'half again as big, three times the hp, hits harder',   hp: 3,   r: 1.5, speed: 0.8, power: 1 },
  swift:   { name: 'Swift',    color: '#7df58f', desc: 'moves 60% faster',                                       hp: 1.5, r: 1,   speed: 1.6, power: 0 },
  armored: { name: 'Armored',  color: '#a9a2b3', desc: 'plain bullets do 30%; elements and fusions go through',  hp: 2,   r: 1.1, speed: 0.9, power: 0, armor: 0.3 },
  venomous:{ name: 'Venomous', color: '#9bf06a', desc: 'shoots twice as often, with extra shots',                hp: 1.5, r: 1.1, speed: 1,   power: 0, shots: 2, cdMult: 0.6 },
};
// Named uniques: only one of each alive at a time (in nightmare any number: CFG.NIGHTMARE.uniques of spawns are uniques); each
// region past its minRegion (and on one of its `levels`, when it has them) has a `chance` of hosting one.
// shots / cdMult: extra shots per volley and a cooldown multiplier, for monsters that shoot.
// They are built on a base enemy (snake / bug / parasite) with their own stats and a signature drop.
const UNIQUES = {
  oldgrey:  { name: 'Old Grey',       base: 'snake', minRegion: 2, chance: 0.22, color: '#b9b4c4', dark: '#5a5366', segN: 14, hp: 140, hpR: 30, speed: 110, armor: 0.5, power: 2, r: 15,
              loot: { chance: 1, tier: 3, n: 2 }, dots: 10, desc: 'an ancient armored snake. Slow, long, and it shrugs off plain bullets' },
  hoarder:  { name: 'Dot Hoarder',    base: 'bug',   type: 'beetle', minRegion: 1, chance: 0.3, color: '#ffe9a8', dark: '#a67c1c', hp: 40, hpR: 12, speed: 120, r: 15, flee: true,
              loot: { chance: 1, tier: 2, n: 1 }, dots: 14, desc: 'a fat beetle stuffed with dots. It runs from you: corner it' },
  brood:    { name: 'Broodmother',    base: 'parasite', id: 'leech', minRegion: 2, chance: 0.2, color: '#c58aff', dark: '#5a2a8a', hp: 90, hpR: 30, speed: 120, r: 22, spawnCd: 6,
              loot: { chance: 1, tier: 2, n: 1 }, dots: 6, egg: true, desc: 'a huge parasite that keeps birthing hatchlings. Kill it and its egg hatches a minion for you' },
  ironback: { name: 'Ironback',       base: 'bug', type: 'beetle', minRegion: 3, chance: 0.2, color: '#8f9aa8', dark: '#3d4450', hp: 160, hpR: 30, speed: 85, r: 21, armor: 0.25, power: 2,
              loot: { chance: 1, tier: 3, n: 1 }, dots: 10, desc: 'a beetle with a shell like a tank: plain bullets barely scratch it' },
  stormwing:{ name: 'Stormwing',      base: 'bug', type: 'hornet', minRegion: 4, chance: 0.2, color: '#ffe04a', dark: '#8a6a00', hp: 70, hpR: 18, speed: 250, r: 16, shots: 2, cdMult: 0.5,
              loot: { chance: 1, tier: 3, n: 1 }, dots: 8, desc: 'a hornet champion: fast, and it fires volleys' },
  marrowking:{ name: 'Marrowking',    base: 'bug', type: 'skull', levels: [2], minRegion: 7, chance: 0.25, color: '#ff6a3d', dark: '#5a1a0a', hp: 130, hpR: 25, speed: 160, r: 20, shots: 1, cdMult: 0.6,
              loot: { chance: 1, tier: 3, n: 1 }, dots: 10, desc: 'a skull crowned in bone, burning hotter than the rest: it hunts you and spits fire in pairs' },
  gravewyrm:{ name: 'Gravewyrm',      base: 'snake', levels: [2], minRegion: 7, chance: 0.22, color: '#eae4d4', dark: '#6b6250', segN: 18, hp: 200, hpR: 35, speed: 130, r: 16, shots: 2, cdMult: 0.7, power: 2,
              loot: { chance: 1, tier: 3, n: 2 }, dots: 12, desc: 'a serpent of bones: long, fast, and it spits volleys' },
  strangler:{ name: 'The Strangler',  base: 'snake', levels: [3], minRegion: 14, chance: 0.22, color: '#3f8a3a', dark: '#1a3a18', segN: 22, hp: 280, hpR: 40, speed: 150, r: 18, power: 3,
              loot: { chance: 1, tier: 3, n: 2 }, dots: 12, desc: 'a python as long as a river: keep out of its coils' },
  oldtongue:{ name: 'Old Tongue',     base: 'bug', type: 'iguana', levels: [3], minRegion: 14, chance: 0.22, color: '#5fd9a8', dark: '#1f5a40', hp: 200, hpR: 30, speed: 110, r: 24, power: 2, cdMult: 0.6,
              loot: { chance: 1, tier: 3, n: 1 }, dots: 10, desc: 'a giant iguana: its tongue lashes out twice as often, and hard' },
  greycmd:  { name: 'Grey Commander', base: 'bug', type: 'brainling', levels: [4], minRegion: 21, chance: 0.22, color: '#c9ced6', dark: '#4a5060', hp: 150, hpR: 25, speed: 140, r: 19, shots: 2, cdMult: 0.5,
              loot: { chance: 1, tier: 3, n: 1 }, dots: 10, desc: 'the brainlings\' commander: it fires three at a time, twice as often' },
  bessie:   { name: 'Bessie Prime',   base: 'bug', type: 'mooncow', levels: [4], minRegion: 21, chance: 0.22, color: '#f4f0e8', dark: '#5a5050', hp: 260, hpR: 30, speed: 100, r: 26, power: 3,
              loot: { chance: 1, tier: 3, n: 2 }, dots: 12, desc: 'the biggest cow the aliens ever beamed up: it hits like a truck' },
  ringmaster:{ name: 'The Ringmaster', base: 'bug', type: 'juggler', levels: [5], minRegion: 28, chance: 0.22, color: '#ff5c8a', dark: '#6a1030', hp: 180, hpR: 28, speed: 150, r: 20, shots: 1, cdMult: 0.6,
              loot: { chance: 1, tier: 3, n: 1 }, dots: 10, desc: 'the master of the ring: its pins come in pairs, and quickly' },
  jumbo:    { name: 'Jumbo',          base: 'bug', type: 'elephant', levels: [5], minRegion: 28, chance: 0.2, color: '#ff9ec8', dark: '#7a2a50', hp: 500, hpR: 40, speed: 90, r: 34, power: 3, armor: 0.5,
              loot: { chance: 1, tier: 3, n: 2 }, dots: 14, desc: 'the biggest pink elephant in the tent: armored, and very hard to stop' },
};
