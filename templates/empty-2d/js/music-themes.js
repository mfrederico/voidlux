// Music.THEMES: every tune the game plays, by name, made in the chip tracker (studio) and saved here below the
// marker line that reads 'tracker songs below'. The game plays them by name: Music.start('title') on the title screen,
// Music.start('play') in play (see main.js). See kit/js/kit-music.js for the format and the synth. Never hand-edit a song.
const _ = null; // rests in tracker exports
Object.assign(Music, {
  THEMES: {
    // ---- tracker songs below: the tracker's save writes them here; each starts with '// made in the tracker' ----
    // made in the tracker (2026-10-23): 32 steps of 16ths at 96 bpm
    title: { tracker: true, bpm: 96, steps: 32, tracks: [
      { name: 'Bass', inst: 'triangle', vol: 0.5, len: 2, notes: [38,_,_,_,38,_,45,_,41,_,_,_,41,_,43,_,38,_,_,_,38,_,45,_,36,_,_,_,36,_,43,_] },
      { name: 'Pad', inst: 'synth', vol: 0.18, len: 8, syn: { name: 'void pad', osc: [{ w: 'sawtooth', st: 0, fine: 0, lvl: 0.6 }, { w: 'sawtooth', st: 7, fine: 6, lvl: 0.4 }, { w: 'triangle', st: 12, fine: 0, lvl: 0.3 }], a: 0.4, d: 0.5, s: 0.8, r: 0.8 }, notes: [62,_,_,_,_,_,_,_,65,_,_,_,_,_,_,_,62,_,_,_,_,_,_,_,60,_,_,_,_,_,_,_] },
      { name: 'Hat', inst: 'hat', vol: 0.25, len: 1, notes: [_,_,60,_,_,_,60,_,_,_,60,_,_,_,60,_,_,_,60,_,_,_,60,_,_,_,60,_,_,_,60,60] },
    ] },
    // made in the tracker (2026-10-23): 32 steps of 16ths at 132 bpm
    play: { tracker: true, bpm: 132, steps: 32, tracks: [
      { name: 'Kick', inst: 'kick', vol: 0.8, len: 1, notes: [36,_,_,_,36,_,_,_,36,_,_,_,36,_,_,_,36,_,_,_,36,_,_,_,36,_,_,_,36,_,36,_] },
      { name: 'Snare', inst: 'snare', vol: 0.5, len: 1, notes: [_,_,_,_,38,_,_,_,_,_,_,_,38,_,_,_,_,_,_,_,38,_,_,_,_,_,_,_,38,_,_,38] },
      { name: 'Hat', inst: 'hat', vol: 0.3, len: 1, notes: [_,_,60,_,_,_,60,_,_,_,60,_,_,_,60,_,_,_,60,_,_,_,60,_,_,_,60,_,_,_,60,_] },
      { name: 'Bass', inst: 'square', vol: 0.35, len: 1, cut: 1800, notes: [38,38,_,38,_,38,41,_,38,38,_,38,_,38,45,_,36,36,_,36,_,36,43,_,36,36,_,36,_,41,43,_] },
      { name: 'Lead', inst: 'pulse', vol: 0.22, len: 2, notes: [_,_,_,_,62,_,65,_,69,_,_,_,67,_,65,_,_,_,_,_,60,_,62,_,65,_,_,_,67,_,69,_] },
    ] },
  },
});
