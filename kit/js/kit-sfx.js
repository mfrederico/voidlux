// VoidLux kit: sound effects. Everything is synthesized in code, no audio files. The same data format the SFX editor
// (kit/editor/sfx/) reads and writes: a game's js/sfx.js holds `const SFX_DATA = { "<key>": { jitter, layers: [...] } }`,
// where a layer is { w: 'square'|'sawtooth'|'triangle'|'sine'|'noise', f0, f1 (Hz sweep), d (s), v (0..1), t (ms delay),
// lfo, depth (a pitch wobble), crush (1 = distorted), m (1 = muted), c (editor colour) }.
//
//   SFX.init()               wake the AudioContext (browsers need a tap first; Input calls it on the first touch or key)
//   SFX.play('key')          the sound designed under that key, or the game's fallback in SFX.FALLBACK, or a plain blip.
//                            A key the game plays but nobody designed is still a sound, so the game is never silent.
//   SFX.at(x, () => SFX.play('hit'))   the same panned by where x is on screen (Game.cam and Game.W decide the centre)
//   SFX.tone(f0, f1, d, wave, v)       a one-off layer for quick code sounds
//   SFX.layers(D) / SFX.voice(L, k)    play a designed sound / a single layer (the editor uses these)
//   SFX.muted, SFX.volume(v)           mute, master volume 0..1 (Settings keeps them)
//
// Voices come from a POOL made once and re-aimed by automation: Chrome keeps the memory of every audio node a page creates,
// so a fresh oscillator per sound (40 a second in a fight) grew a tab by ~10 MB a minute. Never create nodes per sound here.
const SFX = {
  ctx: null, master: null, muted: false, vol: 1, active: {},
  FALLBACK: {}, // the game may fill this: key -> layers, the sound a key plays until it is designed (SFX.FALLBACK.shoot = [...])
  ALIAS: {},    // key -> another key it borrows until designed
  GATE_MS: 30,  // the same key within this many ms plays once (a swarm dying at once is one sound, not two hundred)
  init() {
    if (!this.ctx) {
      try {
        const touch = 'ontouchstart' in window || navigator.maxTouchPoints > 0; // a phone: a bigger audio buffer, or the music crackles while the game draws
        this.ctx = new (window.AudioContext || window.webkitAudioContext)(touch ? { latencyHint: 'playback' } : undefined);
        this.master = this.ctx.createGain(); this.master.gain.value = 0.22 * this.vol; this.master.connect(this.ctx.destination);
      } catch (e) { this.ctx = null; }
    }
    if (this.ctx && this.ctx.state === 'suspended') this.ctx.resume();
  },
  volume(v) { this.vol = U.clamp(v, 0, 1); if (this.master) this.master.gain.value = 0.22 * this.vol; },
  tone(f0, f1, dur, type, vol) { this.voice({ w: type || 'square', f0, f1, d: dur, v: vol || 0.5 }); },
  custom(key) { return typeof SFX_DATA !== 'undefined' && SFX_DATA[key] && SFX_DATA[key].layers && SFX_DATA[key].layers.length ? SFX_DATA[key] : null; },
  gate(name) { const now = performance.now(), last = this.active[name] || 0; if (now - last < this.GATE_MS) return false; this.active[name] = now; return true; },
  play(name) {
    if (this.muted || !this.ctx) return;
    if (!this.gate(name)) return;
    const D = this.custom(name); if (D) { this.layers(D); return; }
    if (this.ALIAS[name]) { this.play(this.ALIAS[name]); return; }
    const F = this.FALLBACK[name]; if (F) { this.layers({ layers: F }); return; }
    this.layers({ layers: SFX.BLIP }); // undesigned: a plain blip, so the game is heard and the editor shows what to design
  },
  BLIP: [{ w: 'square', f0: 660, f1: 440, d: 0.06, v: 0.2 }],
  // ---- stereo: a sound that belongs to something on screen comes from its side ----
  pan: 0, pans: {}, stereo: 0.6,
  panOf(x) { if (!this.stereo || x == null || typeof Game === 'undefined') return 0; const cx = Game.cam.x + Game.W / 2; return U.clamp((x - cx) / (Game.W / 4), -1, 1) * this.stereo; },
  at(x, fn) { return this.panned(this.panOf(x), fn); },
  panned(pan, fn) { const was = this.pan; this.pan = pan; try { return fn(); } finally { this.pan = was; } },
  later(fn, ms) { const pan = this.pan; return setTimeout(() => this.panned(pan, fn), ms); },
  noiseBuf() { if (!this._noise) { const c = this.ctx, n = c.sampleRate * 2, b = this._noise = c.createBuffer(1, n, c.sampleRate), ch = b.getChannelData(0); for (let i = 0; i < n; i++) ch[i] = Math.random() * 2 - 1; } return this._noise; },
  crushCurve() { if (!this._crush) { const m = 256, curve = this._crush = new Float32Array(m); for (let i = 0; i < m; i++) curve[i] = Math.tanh((i * 2 / m - 1) * 6); } return this._crush; },
  // play a designed sound: its layers, each after its delay, all pitched by one random jitter
  layers(D) {
    if (this.muted || !this.ctx) return;
    const k = 1 + (D.jitter ? U.rand(-D.jitter, D.jitter) : 0);
    for (const L of D.layers) { if (L.m) continue; if (L.t > 0) this.later(() => this.voice(L, k), L.t); else this.voice(L, k); }
  },
  POOL: 16, pool: null,
  takeVoice() {
    if (!this.pool) {
      const c = this.ctx, mk = () => {
        const o = c.createOscillator(), n = c.createBufferSource(), bp = c.createBiquadFilter(), og = c.createGain(), ng = c.createGain(), lfo = c.createOscillator(), lg = c.createGain(), ws = c.createWaveShaper(), g = c.createGain(), p = c.createStereoPanner ? c.createStereoPanner() : null;
        n.buffer = this.noiseBuf(); n.loop = true; bp.type = 'bandpass'; bp.Q.value = 1.2; lfo.type = 'square'; og.gain.value = ng.gain.value = lg.gain.value = g.gain.value = 0;
        o.connect(og); n.connect(bp); bp.connect(ng); og.connect(ws); ng.connect(ws); ws.connect(g); lfo.connect(lg); lg.connect(o.frequency); lg.connect(bp.frequency);
        if (p) { g.connect(p); p.connect(this.master); } else g.connect(this.master);
        o.start(); n.start(0, Math.random() * 2); lfo.start();
        return { o, bp, og, ng, lfo, lg, ws, g, p, end: 0, crush: false };
      };
      this.pool = []; for (let i = 0; i < this.POOL; i++) this.pool.push(mk());
    }
    let v = this.pool[0]; for (const u of this.pool) if (u.end < v.end) v = u; // the voice that finishes first (a full pool cuts the oldest sound short)
    return v;
  },
  voice(L, k) {
    if (this.muted || !this.ctx) return;
    const t = this.ctx.currentTime, d = Math.max(0.01, L.d), f0 = Math.max(1, L.f0 * (k || 1)), f1 = Math.max(1, L.f1 * (k || 1)), v = this.takeVoice(), noise = L.w === 'noise';
    const set = (prm, x) => { prm.cancelScheduledValues(t); prm.setValueAtTime(x, t); };
    set(v.og.gain, noise ? 0 : 1); set(v.ng.gain, noise ? 1 : 0);
    if (!noise) v.o.type = L.w || 'square';
    const freq = noise ? v.bp.frequency : v.o.frequency; set(freq, f0); freq.exponentialRampToValueAtTime(f1, t + d);
    const wob = L.lfo > 0 && L.depth > 0; set(v.lg.gain, wob ? L.depth : 0); if (wob) set(v.lfo.frequency, L.lfo);
    if (!!L.crush !== v.crush) { v.crush = !!L.crush; v.ws.curve = v.crush ? this.crushCurve() : null; }
    if (v.p) set(v.p.pan, this.pan || 0);
    set(v.g.gain, Math.max(0.0001, L.v)); v.g.gain.exponentialRampToValueAtTime(0.001, t + d); v.g.gain.setValueAtTime(0, t + d + 0.01);
    v.end = t + d + 0.02;
  },
};
