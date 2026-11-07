// Drawing: the backdrop, and for now a note that the game is still to be made. Everything here reads World and Game and
// draws; nothing here changes the game.
const Render = {
  bg(c) { c.fillStyle = '#0d0a16'; c.fillRect(0, 0, Game.W, CFG.H); c.strokeStyle = 'rgba(155,135,255,0.06)'; c.lineWidth = 1;
    for (let x = 0; x <= Game.W; x += 64) { c.beginPath(); c.moveTo(x, 0); c.lineTo(x, CFG.H); c.stroke(); } for (let y = 0; y <= CFG.H; y += 64) { c.beginPath(); c.moveTo(0, y); c.lineTo(Game.W, y); c.stroke(); } },
  world(c) { this.bg(c);
    Draw.text('Nothing here yet', Game.W / 2, CFG.H / 2 - 20, 40, 'rgba(255,255,255,0.75)', 'center');
    Draw.text('Say what this game is, and the builder makes it.', Game.W / 2, CFG.H / 2 + 26, 18, '#c9b8ff', 'center'); },
  hud(c) { Game.drawMsgs(20 * Game.uiScale, CFG.H - 120 * Game.uiScale, 16 * Game.uiScale); },
};
