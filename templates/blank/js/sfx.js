// Sound effects designed in the SFX editor (studio). Each entry replaces one of the game's sounds with layers played together:
//   w: 'square' | 'sawtooth' | 'triangle' | 'sine' | 'noise'   f0 -> f1: pitch sweep in Hz (noise: the filter sweep)
//   d: seconds   v: volume 0..1   t: start delay in ms   lfo, depth: a wobble (Hz, and Hz of swing)   crush: 1 = distorted
//   m: 1 = muted (kept, silent)   c: its colour in the editor
// jitter: each play varies the pitch by up to this fraction. Keys are whatever the game passes to SFX.play(). Anything not
// listed plays SFX.FALLBACK[key] (set in the game's code) or a plain blip. The editor rewrites this file on every save.
const SFX_DATA = {
  "shoot": {"layers": [{"w": "square", "f0": 700, "f1": 240, "d": 0.05, "v": 0.12}]},
  "hit": {"layers": [{"w": "sawtooth", "f0": 220, "f1": 90, "d": 0.07, "v": 0.18}]},
  "kill": {"layers": [{"w": "noise", "f0": 1200, "f1": 80, "d": 0.3, "v": 0.45}, {"w": "sine", "f0": 90, "f1": 30, "d": 0.3, "v": 0.5}]},
  "pickup": {"layers": [{"w": "triangle", "f0": 520, "f1": 1040, "d": 0.16, "v": 0.5}, {"w": "triangle", "f0": 780, "f1": 1300, "d": 0.14, "v": 0.4, "t": 90}]},
  "hurt": {"layers": [{"w": "square", "f0": 420, "f1": 110, "d": 0.3, "v": 0.5, "crush": 1}]},
  "die": {"layers": [{"w": "sawtooth", "f0": 300, "f1": 35, "d": 1.3, "v": 0.7}]},
  "wave": {"layers": [{"w": "triangle", "f0": 750, "f1": 750, "d": 0.3, "v": 0.2}, {"w": "triangle", "f0": 1000, "f1": 1000, "d": 0.3, "v": 0.2, "t": 120}, {"w": "triangle", "f0": 1500, "f1": 1500, "d": 0.5, "v": 0.2, "t": 240}]},
  "start": {"layers": [{"w": "square", "f0": 440, "f1": 880, "d": 0.2, "v": 0.3}, {"w": "square", "f0": 660, "f1": 1320, "d": 0.3, "v": 0.3, "t": 120}]},
  "tab": {"layers": [{"w": "triangle", "f0": 800, "f1": 1250, "d": 0.05, "v": 0.22}]}
};
