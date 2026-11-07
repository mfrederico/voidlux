// The game's things and rules: nothing yet. This game started empty; the builder puts here what the brief describes (the
// player, what they do, what they meet). Pure simulation: no drawing here (render.js) and no screens (main.js).
// World.reset() starts a run; World.update(dt) steps it; World.bot(dt) plays it for soak tests.
const World = {
  t: 0,
  reset() { this.t = 0; },
  update(dt) { this.t += dt; },
  bot(dt) {},
};
