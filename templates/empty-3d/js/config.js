// Every tuning number and switch in the game, with a comment saying what it does. The config editor (studio) reads this
// file and writes overrides to js/tuning.js, so keep the shape: `const CFG = {` ... `};`, top-level keys in CAPS, one
// comment above or beside each. This game starts empty: the builder adds what the brief asks for.
const CFG = {
  W: 1280, H: 720, // the logical canvas (H is fixed; W follows the screen between W_MIN and W_MAX)
  W_MIN: 960, W_MAX: 1600,
  // the 3D stage (js/stage.js): sky and ground colours, fog, how big the floor is, and the camera (distance, height, how fast it circles)
  STAGE: { sky: '#1a1730', ground: '#2b2745', fogNear: 1400, fogFar: 3200, floor: 2000, fov: 45, cam: { dist: 900, height: 520, spin: 0.08 } },
};
