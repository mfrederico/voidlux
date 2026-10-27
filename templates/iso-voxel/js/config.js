// Every tuning number and switch in the game, with a comment saying what it does. The config editor (studio) reads this
// file and writes overrides to js/tuning.js, so keep the shape: `const CFG = {` ... `};`, top-level keys in CAPS, one
// comment above or beside each. Data tables (the map, tile kinds, friends, cars) live below CFG.
const CFG = {
  W: 1280, H: 720, // the logical canvas (H is fixed; W follows the screen between W_MIN and W_MAX)
  W_MIN: 960, W_MAX: 1600,
  // the isometric grid: tw x th is one tile's diamond on screen (2:1), unit is the screen height of one block level in px
  ISO: { tw: 64, th: 32, unit: 28 },
  // the cat: lunge* is the pounce (tiles per second, seconds), windSecs how long a missed pounce leaves it winded and windSpeed its walking speed factor then, pounceRange how far a critter may be for the pounce to aim at it (tiles), catchR how close the lunge must come to catch it; speed in tiles per second, r the body radius in tiles for hitting walls, hop and hopRate the walking bounce in px and Hz
  CAT: { speed: 3.4, r: 0.28, hop: 4, hopRate: 9, startX: 5.5, startY: 5.5, lungeSpeed: 10, lungeTime: 0.22, windSecs: 1.0, windSpeed: 0.45, pounceRange: 2.4, catchR: 0.65 },
  // the camera: follow is how fast it eases to the cat (higher is snappier), lift moves the cat above centre in px so more road shows ahead
  CAM: { follow: 5, lift: 30 },
  // the bot: how long it may be stuck against a wall before choosing a new destination, and how close counts as arrived (tiles)
  BOT: { stuck: 0.8, arrive: 0.4 },
  // friends: meowSecs is how long Space must be held next to one to fill the heart meter (it drains back at decay x that rate
  // when you stop), range how close the cat must stand (tiles), pat the base score per pat (times the friend's pat factor),
  // foodChance how often a friend is carrying food, cool seconds before the same friend can be patted again, wanderR how far
  // from home a wanderer strolls (tiles), pause the min and max seconds it stands still between strolls
  FRIENDS: { meowSecs: 1.0, pat: 25, foodChance: 0.5, range: 1.3, cool: 14, decay: 1.5, wanderR: 3, pauseMin: 1, pauseMax: 3.5 },
  // combo: every pat or catch scores its base points times the current combo, then raises it (up to max); it falls back to 1
  // when window seconds pass without another
  COMBO: { max: 5, window: 4 },
  // critters: how many of each roam the grass, seconds before a caught one reappears, how close the cat must be before they notice
  // (they take react seconds to bolt, so a quiet pounce from further off can still win), hopMin/hopMax seconds between idle hops
  CRITTERS: { birds: 3, squirrels: 2, respawn: 6, react: 0.2, hopMin: 0.8, hopMax: 2.5, hopSpeed: 1.4, liftRate: 4 },
  // the day: daySecs of sun then nightSecs of full moon; the sky darkens over the last duskSecs of the day and lightens over
  // dawnSecs after the night; surviving a night pays bonus x the day number
  DAY: { daySecs: 70, nightSecs: 35, duskSecs: 6, dawnSecs: 3, bonus: 200 },
  // witches (night only): lantern cone range (tiles) and half angle (radians); patrol speed, chase speed (+chaseDay per day up to chaseMax,
  // always under the cat's speed), patrolDay the patrol speed growth per day; spotSecs in the beam to be spotted, grabR how close
  // catches the cat, loseSecs out of sight before she gives up, loseR how far gives up too, hideSecs how long a porch cushion hides the cat,
  // coneRays how many rays shape the lit cone (hedges and houses cast shadows)
  WITCH: { range: 4.5, half: 0.6, patrol: 1.5, patrolDay: 0.05, chase: 2.7, chaseDay: 0.1, chaseMax: 3.2, spotSecs: 1.5, grabR: 0.6, loseSecs: 3, loseR: 8, hideSecs: 8, coneRays: 12, turn: 4 },
  // belly: the hunger tank. start and max in points, drain per second (growing by drainDay of itself each day). Reaching 0 ends the run
  BELLY: { max: 100, start: 60, drain: 1.6, drainDay: 0.06 },
  // cars: warn is the seconds of flash and honk before a car enters the crossing (tiles crossFrom to crossTo on its lane), car and van
  // the speeds (tiles per second), lenCar/lenVan their length and width their breadth (tiles), vanDay the first day vans appear
  // and vanChance how often one does, gapMin/gapMax seconds between cars in a lane (shrinking gapDay per day down to gapFloor of
  // itself), knock the speed of the shove after a hit and knockT how long it lasts, shake the screen shake of a hit
  CARS: { warn: 0.6, car: 5, van: 8, lenCar: 1.1, lenVan: 1.6, width: 0.6, vanDay: 3, vanChance: 0.35, gapMin: 2.5, gapMax: 6, gapDay: 0.08, gapFloor: 0.5, crossFrom: 9, crossTo: 11, knock: 7, knockT: 0.25, shake: 6 },
  // paws are the cat's lives; invuln the seconds of blinking safety after a hit
  PAWS: { max: 3, invuln: 1.5 },
};
// Tile kinds, keyed by the character used in MAP. solid blocks the cat; h is the block height in levels (0 is flat ground).
// color is the main colour; houses (H pink, G blue, Y yellow, D and E the same with a front door) share one look.
const TILES = {
  '.': { name: 'grass', solid: false, h: 0, color: '#8fd96b', alt: '#84d062' },
  'p': { name: 'pavement', solid: false, h: 0, color: '#f0e4c8', alt: '#e8dcbe' },
  'c': { name: 'porch cushion', solid: false, h: 0, color: '#f0e4c8', alt: '#e8dcbe', cushion: '#ff8fb1' },
  '-': { name: 'road east-west', solid: false, h: 0, color: '#6a6f86', road: 'ew' },
  '|': { name: 'road north-south', solid: false, h: 0, color: '#6a6f86', road: 'ns' },
  '+': { name: 'crossroads', solid: false, h: 0, color: '#6a6f86', road: 'x' },
  'H': { name: 'pink house', solid: true, h: 2.2, color: '#ffb3c7', roof: '#c9485b' },
  'D': { name: 'pink house door', solid: true, h: 2.2, color: '#ffb3c7', roof: '#c9485b', door: true },
  'G': { name: 'blue house', solid: true, h: 2.2, color: '#9fd3ff', roof: '#4a6fc9' },
  'E': { name: 'blue house door', solid: true, h: 2.2, color: '#9fd3ff', roof: '#4a6fc9', door: true },
  'Y': { name: 'yellow house', solid: true, h: 2.2, color: '#ffe38a', roof: '#d9773a' },
  'T': { name: 'tree', solid: true, h: 2.4, color: '#4fb86a', trunk: '#8a5a3c' },
  'h': { name: 'hedge', solid: true, h: 0.55, color: '#3fa860' },
};
// The neighbourhood, 20 x 20 tiles, one string per row (north-west corner first). Roads cross in the middle (columns 9-10,
// rows 9-10); porch cushions 'c' sit in front of each door; trees and hedges fence the edge and dot the parks.
const MAP = [
  'TTTTTTTTT||TTTTTTTTT',
  'T.hhhhhh.||.hhhhhh.T',
  'T.HHH...p||p....GGGT',
  'T.HHH.T.p||p..T.GGGT',
  'T.HDH...p||p....GEGT',
  'T.pcppppp||ppppppcpT',
  'T.....h..||...h....T',
  'T..T.....||.....T..T',
  'T........||........T',
  '---------++---------',
  '---------++---------',
  'T........||........T',
  'Tpppppppp||ppppppppT',
  'T.HHH....||..YYY...T',
  'T.HHH..T.||..YYY.T.T',
  'T.HDH....||..YEY...T',
  'Tppcppppp||pppcppppT',
  'T..h..h..||..h..h..T',
  'T........||........T',
  'TTTTTTTTT||TTTTTTTTT',
];
// Critters: score is the base points for a catch, flee the running speed (tiles per second), fleeSecs how long they run,
// alertR how close the cat gets before they notice, flies whether they take to the air (a bird in the air cannot be pounced)
const CRITTER_KINDS = {
  bird: { score: 50, flee: 3.8, fleeSecs: 1.4, alertR: 1.6, flies: true },
  squirrel: { score: 100, flee: 3.1, fleeSecs: 1.8, alertR: 1.9, flies: false },
};
// People who give pats and food: speed in tiles per second, belly the food they hand over, pat the score factor, tall the body height factor, wander or stand still
const FRIEND_KINDS = {
  kid: { color: '#ff8a5c', hair: '#6b3f2a', tall: 0.75, speed: 1.6, belly: 15, pat: 1, wander: true },
  adult: { color: '#6a8fd9', hair: '#2e2a3a', tall: 1, speed: 0.9, belly: 25, pat: 1.5, wander: true },
  granny: { color: '#c79ad9', hair: '#f4f4f4', bun: true, tall: 0.9, speed: 0, belly: 45, pat: 2, wander: false },
  baker: { color: '#f2f2f2', hair: '#f2f2f2', hat: true, tall: 1, speed: 0, belly: 35, pat: 1.5, wander: false },
};
// Where each friend starts (tile coordinates) and its kind; day is the first day it appears
const FRIEND_SPAWNS = [
  { kind: 'kid', x: 6.5, y: 5.5, day: 1 }, { kind: 'adult', x: 14.5, y: 7.5, day: 1 },
  { kind: 'granny', x: 3.5, y: 5.5, day: 1 }, { kind: 'kid', x: 5.5, y: 16.5, day: 1 },
  { kind: 'baker', x: 14.5, y: 16.5, day: 2 },
];
// Where the witches rise on a full-moon night (tile coordinates): the four corners, far from the road
const WITCH_SPAWNS = [{ x: 1.5, y: 1.5 }, { x: 18.5, y: 1.5 }, { x: 1.5, y: 18.5 }, { x: 18.5, y: 18.5 }];
// Traffic: each lane is a road row ('x': cars drive along it east or west) or column ('y') by tile index, the direction (1 is towards higher tile numbers), and the day it opens
const CAR_COLORS = ['#ff6b6b', '#ffd23f', '#6bcB77', '#4d96ff', '#c77dff', '#ff9f43'];
const CAR_LANES = [
  { axis: 'x', line: 9, dir: 1, day: 1 }, { axis: 'x', line: 10, dir: -1, day: 1 },
  { axis: 'y', line: 9, dir: 1, day: 3 }, { axis: 'y', line: 10, dir: -1, day: 3 },
];
// Why a run ended, shown on the game over screen
const CAUSES = { hunger: 'Too hungry to go on...', car: 'Run over by a car!', witch: 'Taken by the witches!' };
