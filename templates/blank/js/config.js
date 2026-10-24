// Every tuning number and switch in the game, with a comment saying what it does. The config editor (studio) reads this
// file and writes overrides to js/tuning.js, so keep the shape: `const CFG = {` ... `};`, top-level keys in CAPS, one
// comment above or beside each. Data tables (enemy kinds, waves) live below CFG.
const CFG = {
  W: 1280, H: 720, // the logical canvas (H is fixed; W follows the screen between W_MIN and W_MAX)
  W_MIN: 960, W_MAX: 1600,
  // the player ship
  PLAYER: { speed: 420, r: 16, fireCd: 0.14, bulletSpeed: 820, bulletR: 5, hp: 3, invuln: 1.2 },
  // enemies: how many to start, how fast the count grows per wave, their speed and the loot they drop
  ENEMIES: { start: 4, perWave: 2, speedMin: 70, speedMax: 170, r: 18, dropChance: 0.25 },
  WAVE_SECS: 14, // seconds per wave; the next wave spawns when the field is clear or the clock runs out
  SCORE: { kill: 10, pickup: 25, wave: 100 },
  SHAKE: { hit: 6, die: 14 },
};
// enemy kinds: a colour, a relative speed, hit points, and how it moves (chase: straight at you; drift: wanders; orbit: circles you)
const KINDS = {
  mote: { color: '#ff6600', speed: 1, hp: 1, move: 'chase', score: 1 },
  shard: { color: '#7df9ff', speed: 1.4, hp: 1, move: 'drift', score: 1.5 },
  husk: { color: '#c9b8ff', speed: 0.6, hp: 3, move: 'orbit', score: 2.5 },
};
