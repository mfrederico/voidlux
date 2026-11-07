// The screens (title, play, paused, game over) and the boot. Each scene is a plain object the kit's Game runs: enter, update(dt),
// draw(ctx), and bot(dt) for soak tests. Game.boot at the bottom wires it all up. The game starts empty: play shows that it is.
const Scenes = {
  title: {
    t: 0, enter() { this.t = 0; },
    update(dt) {
      this.t += dt;
      if (Input.once('Space') || Input.once('Enter') || Input.tapped(this.playRect())) Game.go('play');
      if (Input.once('KeyM') || Input.tapped(this.soundRect())) Settings.toggleSound();
    },
    playRect() { return { x: Game.W / 2 - 120, y: CFG.H / 2 + 60, w: 240, h: 60 }; },
    soundRect() { return { x: Game.W / 2 - 90, y: CFG.H / 2 + 140, w: 180, h: 40 }; },
    draw(c) {
      Render.stage(c); c.fillStyle = 'rgba(0,0,0,0.35)'; c.fillRect(0, 0, Game.W, CFG.H);
      Draw.text(Game.title.toUpperCase(), Game.W / 2, CFG.H / 2 - 60, 72, '#ffcc00', 'center');
      const r = this.playRect(); Draw.button(r.x + r.w / 2, r.y + r.h / 2, r.w, r.h, 'PLAY', '#9b87ff', '#111');
      const s = this.soundRect(); Draw.button(s.x + s.w / 2, s.y + s.h / 2, s.w, s.h, Settings.data.sound ? 'SOUND ON' : 'SOUND OFF', 'rgba(255,255,255,0.12)', '#fff', 15);
    },
    bot(dt) { if (this.t > 0.5) Input.tapKey('Space'); },
  },
  play: {
    enter() { Game.score = 0; Game.msgs = []; World.reset(); },
    update(dt) { if (Input.once('KeyP') || Input.once('Escape')) { Game.go('paused'); return; } World.update(dt); },
    draw(c) { Render.world(c); Render.hud(c); },
    bot(dt) { World.bot(dt); },
  },
  paused: {
    update(dt) { if (Input.once('KeyP') || Input.once('Escape') || Input.once('Space') || Input.taps.length) Game.go('play_resume'); },
    draw(c) { Render.world(c); c.fillStyle = 'rgba(0,0,0,0.55)'; c.fillRect(0, 0, Game.W, CFG.H); Draw.text('PAUSED', Game.W / 2, CFG.H / 2, 56, '#fff', 'center'); },
    bot(dt) { Input.tapKey('KeyP'); },
  },
  // resuming must not call play.enter (which resets the run): a scene that is play without the reset
  play_resume: { enter() { Game.scene = Scenes.play; Game.state = 'play'; }, update() {}, draw() {} },
  gameover: {
    t: 0, enter() { this.t = 0; },
    update(dt) { this.t += dt; if (this.t > 0.8 && (Input.once('Space') || Input.once('Enter') || Input.taps.length)) Game.go('play'); },
    draw(c) { Render.world(c); c.fillStyle = 'rgba(0,0,0,0.6)'; c.fillRect(0, 0, Game.W, CFG.H); Draw.text('GAME OVER', Game.W / 2, CFG.H / 2, 64, '#ff5c5c', 'center'); },
    bot(dt) { if (this.t > 1) Input.tapKey('Space'); },
  },
};
/* the boot waits for three.js (index.html's module script) and builds the stage first */
Stage.ready(() => { Stage.init(); Game.boot({ id: '__SLUG__', title: '__TITLE__', W: CFG.W, H: CFG.H, WMIN: CFG.W_MIN, WMAX: CFG.W_MAX, scenes: Scenes, start: 'title' }); });
