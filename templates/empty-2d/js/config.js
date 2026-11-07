// Every tuning number and switch in the game, with a comment saying what it does. The config editor (studio) reads this
// file and writes overrides to js/tuning.js, so keep the shape: `const CFG = {` ... `};`, top-level keys in CAPS, one
// comment above or beside each. This game starts empty: the builder adds what the brief asks for.
const CFG = {
  W: 1280, H: 720, // the logical canvas (H is fixed; W follows the screen between W_MIN and W_MAX)
  W_MIN: 960, W_MAX: 1600,
};
