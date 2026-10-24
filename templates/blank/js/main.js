// The screens (title, play, game over) and the boot. Each scene is a plain object the kit's Game runs: enter, update(dt),
// draw(ctx), and bot(dt) for soak tests. Game.boot at the bottom wires it all up.
const Scenes = {
  title: {
    t: 0, enter() { this.t = 0; Music.start('title'); },
    update(dt) {
      this.t += dt;
      const r = this.playRect();
      if (Input.once('Space') || Input.once('Enter') || Input.tapped(r)) { SFX.play('start'); Game.go('play'); }
      if (Input.once('KeyM') || Input.tapped(this.soundRect())) { Settings.toggleSound(); if (Settings.data.sound) Music.start('title'); }
    },
    playRect() { return { x: Game.W / 2 - 120, y: CFG.H / 2 + 60, w: 240, h: 60 }; },
    soundRect() { return { x: Game.W / 2 - 90, y: CFG.H / 2 + 140, w: 180, h: 40 }; },
    draw(c) {
      Render.bg(c);
      Draw.glow(Game.W / 2, CFG.H / 2 - 80, 260, '#ff6600', 0.25);
      Draw.text(Game.title.toUpperCase(), Game.W / 2, CFG.H / 2 - 80, 72, '#ffcc00', 'center');
      Draw.text('move: WASD / stick · aim: mouse · fire: Space / click', Game.W / 2, CFG.H / 2 - 10, 18, '#c9b8ff', 'center');
      const r = this.playRect(); Draw.button(r.x + r.w / 2, r.y + r.h / 2, r.w, r.h, this.t % 1 < 0.7 ? 'PLAY' : 'PLAY ', '#ff6600', '#111');
      const s = this.soundRect(); Draw.button(s.x + s.w / 2, s.y + s.h / 2, s.w, s.h, Settings.data.sound ? 'SOUND ON' : 'SOUND OFF', 'rgba(255,255,255,0.12)', '#fff', 15);
      if (Game.best) Draw.text('BEST ' + Game.best, Game.W / 2, CFG.H - 50, 18, '#9bf06a', 'center');
      if (typeof BUILD !== 'undefined') Draw.ink(BUILD.id, Game.W - 12, CFG.H - 14, 12, 'rgba(255,255,255,0.4)', 'right');
    },
    bot(dt) { if (this.t > 0.5) Input.tapKey('Space'); },
  },
  play: {
    enter() { Game.score = 0; Game.msgs = []; World.reset(); Music.start('play'); Game.msg('Survive. Shoot. Grab the green.', '#fff'); },
    update(dt) {
      if (Input.once('KeyP') || Input.once('Escape')) { Game.go('paused'); return; }
      World.update(dt);
      Music.setMood(0, World.player.hp === 1);
      if (!World.alive) { Game.setBest(); Game.go('gameover'); }
    },
    draw(c) { Render.world(c); Render.hud(c); },
    bot(dt) { World.bot(dt); },
  },
  paused: {
    enter() { Music.setMood(-5, false); },
    update(dt) { if (Input.once('KeyP') || Input.once('Escape') || Input.once('Space') || Input.taps.length) { Music.setMood(0, false); Game.go('play_resume'); } },
    draw(c) { Render.world(c); Render.hud(c); c.fillStyle = 'rgba(0,0,0,0.55)'; c.fillRect(0, 0, Game.W, CFG.H); Draw.text('PAUSED', Game.W / 2, CFG.H / 2, 56, '#fff', 'center'); Draw.text('P to continue', Game.W / 2, CFG.H / 2 + 50, 18, '#c9b8ff', 'center'); },
    bot(dt) { Input.tapKey('KeyP'); },
  },
  // resuming must not call play.enter (which resets the run): a scene that is play without the reset
  play_resume: { enter() { Game.scene = Scenes.play; Game.state = 'play'; }, update() {}, draw() {} },
  gameover: {
    t: 0, enter() { this.t = 0; Music.stop(); SFX.play('die'); },
    update(dt) { this.t += dt; if (this.t > 0.8 && (Input.once('Space') || Input.once('Enter') || Input.once('KeyR') || Input.taps.length)) Game.go('play'); },
    draw(c) { Render.world(c); c.fillStyle = 'rgba(0,0,0,0.6)'; c.fillRect(0, 0, Game.W, CFG.H); Draw.text('GAME OVER', Game.W / 2, CFG.H / 2 - 40, 64, '#ff5c5c', 'center'); Draw.text('score ' + Game.score + '   ·   wave ' + World.wave + '   ·   best ' + Game.best, Game.W / 2, CFG.H / 2 + 20, 22, '#fff', 'center'); if (this.t > 0.8) Draw.text('Space / tap to play again', Game.W / 2, CFG.H / 2 + 70, 18, '#c9b8ff', 'center'); },
    bot(dt) { if (this.t > 1) Input.tapKey('Space'); },
  },
};
Game.boot({ id: '__SLUG__', title: '__TITLE__', W: CFG.W, H: CFG.H, WMIN: CFG.W_MIN, WMAX: CFG.W_MAX, scenes: Scenes, start: 'title' });
