// Small helpers + a tiny synth for sound effects.
const TAU = Math.PI * 2;
const U = {
  rand: (a, b) => a + Math.random() * (b - a),
  randi: (a, b) => Math.floor(a + Math.random() * (b - a + 1)),
  pick: arr => arr[Math.floor(Math.random() * arr.length)],
  chance: p => Math.random() < p,
  clamp: (v, a, b) => (v < a ? a : v > b ? b : v),
  lerp: (a, b, t) => a + (b - a) * t,
  dist: (ax, ay, bx, by) => Math.hypot(bx - ax, by - ay),
  dist2: (ax, ay, bx, by) => (bx - ax) * (bx - ax) + (by - ay) * (by - ay),
  angDiff(a, b) { let d = a - b; while (d > Math.PI) d -= TAU; while (d < -Math.PI) d += TAU; return d; }, // signed a - b, wrapped to -PI..PI
  // rotate angle a toward b by at most step radians
  angLerp(a, b, step) {
    let d = b - a;
    while (d > Math.PI) d -= TAU;
    while (d < -Math.PI) d += TAU;
    if (Math.abs(d) <= step) return b;
    return a + Math.sign(d) * step;
  },
  shuffle(arr) { for (let i = arr.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [arr[i], arr[j]] = [arr[j], arr[i]]; } return arr; },
  // exponential smoothing factor for frame-rate independent lerps
  damp: (rate, dt) => 1 - Math.exp(-rate * dt),
};

const SFX = {
  ctx: null, master: null, muted: false, lastShot: 0,
  init() {
    if (!this.ctx) {
      try {
        const touch = 'ontouchstart' in window || navigator.maxTouchPoints > 0; // a phone: a bigger audio buffer. Android's default (about 4 ms) runs dry while the game draws, and the music crackles
        this.ctx = new (window.AudioContext || window.webkitAudioContext)(touch ? { latencyHint: 'playback' } : undefined);
        this.master = this.ctx.createGain();
        this.master.gain.value = 0.22 * (typeof Settings !== 'undefined' ? Settings.data.volume : 1);
        this.master.connect(this.ctx.destination);
      } catch (e) { this.ctx = null; }
    }
    if (this.ctx && this.ctx.state === 'suspended') this.ctx.resume();
  },
  tone(f0, f1, dur, type, vol) { this.voice({ w: type || 'square', f0, f1, d: dur, v: vol || 0.5 }); },
  // the tractor's engine: a low sawtooth and a sub square chugging through a resonant low-pass, its putt-putt a square LFO on
  // the gain. Called every frame while it runs (rpm 0..1; cough 0..1: sputtering on the last of the fuel). When the calls
  // stop (a pause, a menu, a dungeon) it fades out on its own; engineOff() shuts it down for good
  eng: null,
  engine(rpm, cough) {
    if (!this.ctx) return;
    const c = this.ctx, t = c.currentTime;
    if (!this.eng) {
      const o = c.createOscillator(), o2 = c.createOscillator(), f = c.createBiquadFilter(), am = c.createGain(), g = c.createGain(), lfo = c.createOscillator(), lg = c.createGain();
      o.type = 'sawtooth'; o2.type = 'square'; f.type = 'lowpass'; f.frequency.value = 420; f.Q.value = 5;
      lfo.type = 'square'; lg.gain.value = 0.45; am.gain.value = 0.55; lfo.connect(lg); lg.connect(am.gain); // the gain swings 0.1..1: putt-putt
      g.gain.value = 0; o.connect(f); o2.connect(f); f.connect(am); am.connect(g); g.connect(this.master);
      o.start(); o2.start(); lfo.start();
      this.eng = { o, o2, g, lfo, next: 0, sp: false };
    }
    const E = this.eng; if (t > E.next) { E.sp = cough > 0 && Math.random() < 0.15 + 0.5 * cough; E.next = t + U.rand(0.08, 0.22); } // a cough lasts a moment
    const hz = (40 + 18 * rpm) * (E.sp ? 0.55 : 1), vol = this.muted ? 0 : E.sp ? 0.03 : 0.26;
    E.o.frequency.setTargetAtTime(hz, t, 0.05); E.o2.frequency.setTargetAtTime(hz * 0.5, t, 0.05); E.lfo.frequency.setTargetAtTime(hz / 4.5, t, 0.05);
    E.g.gain.cancelScheduledValues(t); E.g.gain.setTargetAtTime(vol, t, 0.04); E.g.gain.setTargetAtTime(0, t + 0.25, 0.08); // no call for a quarter second: it dies away
  },
  engineOff() { const E = this.eng; if (!E) return; const t = this.ctx.currentTime; E.g.gain.cancelScheduledValues(t); E.g.gain.setTargetAtTime(0, t, 0.08); for (const o of [E.o, E.o2, E.lfo]) o.stop(t + 0.6); this.eng = null; },
  // a cow's MOO, synthesized: a sawtooth voice with a lazy vibrato, pitched up into the 'mm' and sagging through the 'oo',
  // through a resonant low-pass that opens from a closed hum to an open vowel, plus a quieter octave-down growl for body.
  // At most one every 0.7 s, so a beam full of loot does not turn into a stampede.
  lastMoo: 0,
  moo(pitch) {
    if (this.muted || !this.ctx) return;
    const c = this.ctx, t = c.currentTime; if (t - this.lastMoo < 0.7) return; this.lastMoo = t;
    const k = pitch || U.rand(0.88, 1.15), d = 1.05, vol = 0.55;
    const f = c.createBiquadFilter(); f.type = 'lowpass'; f.Q.value = 7;
    f.frequency.setValueAtTime(240, t); f.frequency.linearRampToValueAtTime(760, t + 0.32); f.frequency.linearRampToValueAtTime(430, t + d);
    const g = c.createGain(); g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(vol, t + 0.14); g.gain.setValueAtTime(vol, t + 0.72); g.gain.exponentialRampToValueAtTime(0.001, t + d);
    f.connect(g); g.connect(this.bus());
    const lfo = c.createOscillator(), lg = c.createGain(); lfo.frequency.value = 5.2; lg.gain.value = 2.6 * k; lfo.connect(lg);
    [[1, 'sawtooth', 1], [0.5, 'triangle', 0.6]].forEach(([m, type, amp]) => {
      const o = c.createOscillator(), og = c.createGain(); o.type = type; og.gain.value = amp;
      o.frequency.setValueAtTime(100 * k * m, t); o.frequency.linearRampToValueAtTime(148 * k * m, t + 0.24); o.frequency.linearRampToValueAtTime(126 * k * m, t + 0.62); o.frequency.exponentialRampToValueAtTime(88 * k * m, t + d);
      lg.connect(o.frequency); o.connect(og); og.connect(f); o.start(t); o.stop(t + d + 0.05);
    });
    lfo.start(t); lfo.stop(t + d + 0.05);
  },
  // ---- designed sounds (js/sfx.js, from the SFX editor) ----
  // ---- stereo: a sound that belongs to something on screen comes from its side (Settings: Stereo; 0 = mono) ----
  // SFX.at(x, fn) plays whatever fn plays panned by where x is from the centipede's head (a quarter of the view away: +-Settings.data.stereo);
  // every voice goes through bus(): a StereoPanner shared by every sound on that side (the pan rounded to 0.1, so at most
  // 21 of them), or the master when centred. later() keeps a delayed note on the same side.
  pan: 0, panNode: null, pans: {},
  panOf(x) { const k = typeof Settings !== 'undefined' ? Settings.data.stereo : 0; if (!k || x == null || typeof G === 'undefined' || !G.cam || typeof Render === 'undefined') return 0; const w = Render.viewW() / 4, hx = typeof Player !== 'undefined' && Player.alive ? Player.x : G.cam.x + Render.viewW() / 2; return U.clamp((x - hx) / w, -1, 1) * k; }, // from the head: left of it sounds left (a quarter of the view away is all the way)
  at(x, fn) { return this.panned(this.panOf(x), fn); },
  panned(pan, fn) { const was = this.pan, node = this.panNode; this.pan = pan; this.panNode = null; try { return fn(); } finally { this.pan = was; this.panNode = node; } },
  later(fn, ms) { const pan = this.pan; return setTimeout(() => this.panned(pan, fn), ms); },
  bus() {
    if (!this.pan || !this.ctx.createStereoPanner) return this.master;
    const q = Math.round(this.pan * 10) / 10; if (!q) return this.master;
    let p = this.pans[q]; if (!p) { p = this.pans[q] = this.ctx.createStereoPanner(); p.pan.value = q; p.connect(this.master); } // made once and kept: a new panner per sound piled up hundreds in a fight and pinned the CPU
    return p;
  },
  // made once and shared: two seconds of white noise (a noise layer loops it from a random point) and the tanh distortion
  // curve. Filling a fresh noise buffer with Math.random for every hit cost milliseconds a frame in a fight
  noiseBuf() { if (!this._noise) { const c = this.ctx, n = c.sampleRate * 2, b = this._noise = c.createBuffer(1, n, c.sampleRate), ch = b.getChannelData(0); for (let i = 0; i < n; i++) ch[i] = Math.random() * 2 - 1; } return this._noise; },
  crushCurve() { if (!this._crush) { const m = 256, curve = this._crush = new Float32Array(m); for (let i = 0; i < m; i++) curve[i] = Math.tanh((i * 2 / m - 1) * 6); } return this._crush; },
  custom(key) { return typeof SFX_DATA !== 'undefined' && SFX_DATA[key] && SFX_DATA[key].layers && SFX_DATA[key].layers.length ? SFX_DATA[key] : null; },
  // play a designed sound: its layers, each after its delay, all pitched by one random jitter
  layers(D) {
    if (this.muted || !this.ctx) return;
    const k = 1 + (D.jitter ? U.rand(-D.jitter, D.jitter) : 0);
    for (const L of D.layers) { if (L.m) continue; if (L.t > 0) this.later(() => this.voice(L, k), L.t); else this.voice(L, k); } // (a muted layer stays in the sound, silent)
  },
  // one layer: an oscillator (or filtered noise) sweeping f0 -> f1 over d seconds, fading from v; with lfo/depth a wobble on
  // the pitch, with crush a distortion. SFX.tone is one too.
  // Layers play on a pool of POOL voices made once and left running silent, each re-aimed by automation: the voice that
  // finishes first is taken (a busy pool cuts the oldest sound short). Chrome holds on to the memory of every node a page
  // creates, so a fresh oscillator and gain per sound (40 a second in a fight) grew the tab by ~10 MB a minute and its
  // clean-up stuttered the frame rate. Never create nodes per sound on this path
  POOL: 16, pool: null,
  takeVoice() {
    if (!this.pool) {
      const c = this.ctx, mk = () => {
        const o = c.createOscillator(), n = c.createBufferSource(), bp = c.createBiquadFilter(), og = c.createGain(), ng = c.createGain(), lfo = c.createOscillator(), lg = c.createGain(), ws = c.createWaveShaper(), g = c.createGain(), p = c.createStereoPanner ? c.createStereoPanner() : null;
        n.buffer = this.noiseBuf(); n.loop = true; bp.type = 'bandpass'; bp.Q.value = 1.2; lfo.type = 'square'; og.gain.value = ng.gain.value = lg.gain.value = g.gain.value = 0;
        o.connect(og); n.connect(bp); bp.connect(ng); og.connect(ws); ng.connect(ws); ws.connect(g); lfo.connect(lg); lg.connect(o.frequency); lg.connect(bp.frequency); // the wobble moves whichever source is heard
        if (p) { g.connect(p); p.connect(this.master); } else g.connect(this.master);
        o.start(); n.start(0, Math.random() * 2); lfo.start();
        return { o, bp, og, ng, lfo, lg, ws, g, p, end: 0, crush: false };
      };
      this.pool = []; for (let i = 0; i < this.POOL; i++) this.pool.push(mk());
    }
    let v = this.pool[0]; for (const u of this.pool) if (u.end < v.end) v = u;
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
  // which creature a monster is, for its death and hit sounds: a bug's type, a snake's kind, a boss as boss-<type>
  typeOf(e) { return e.kind === 'bug' ? e.type : e.kind === 'snake' ? (e.bone ? 'boneSnake' : e.python ? 'python' : 'snake') : e.kind === 'boss' ? 'boss-' + e.type : e.kind; },
  // a bullet landing on e: its own designed hit sound if it has one, the shared one otherwise
  hitOn(e) { this.at(e && e.x, () => this.hitAt(e)); },
  hitAt(e) { const t = e ? this.typeOf(e) : '', D = t && this.custom('hit:' + t); if (D) { if (!this.muted && this.ctx && this.gate('hit:' + t, 0.07, 2)) this.layers(D); } else this.play('hit'); },
  // a monster attacks (fires, bites, latches, snips): its own attack sound, silent until designed; allies stay quiet
  attack(e) { if (e && !(e.ally > 0) && !e.minion) this.at(e.x, () => this.extra('attack:' + this.typeOf(e))); },
  // a sound that only exists once it is designed (silent until then)
  extra(key) { const D = this.custom(key); if (D && !this.muted && this.ctx && this.gate(key, 0.12, 2)) this.layers(D); },
  // a distorted, wobbling sawtooth: the crab's digital scream (fast wobble, high and falling) and roar (slow wobble, low)
  growl(f0, f1, dur, lfoHz, depth, vol) {
    if (this.muted || !this.ctx) return;
    const c = this.ctx, t = c.currentTime, o = c.createOscillator(), g = c.createGain(), lfo = c.createOscillator(), lg = c.createGain(), ws = c.createWaveShaper();
    o.type = 'sawtooth'; o.frequency.setValueAtTime(f0, t); o.frequency.exponentialRampToValueAtTime(Math.max(1, f1), t + dur);
    lfo.type = 'square'; lfo.frequency.value = lfoHz; lg.gain.value = depth; lfo.connect(lg); lg.connect(o.frequency);
    ws.curve = this.crushCurve();
    g.gain.setValueAtTime(vol, t); g.gain.exponentialRampToValueAtTime(0.001, t + dur);
    o.connect(ws); ws.connect(g); g.connect(this.bus());
    o.start(t); lfo.start(t); o.stop(t + dur + 0.02); lfo.stop(t + dur + 0.02);
  },
  // chittering that gets faster, higher and louder as a parasite closes in. k = 0 far .. 1 on top of you
  skitter(k) {
    if (this.muted || !this.ctx) return;
    if (!this.gate('skitter', 0.25, 1)) return;
    const clicks = 3 + Math.round(k * 3), f = 900 + k * 900, v = 0.06 + k * 0.3;
    for (let i = 0; i < clicks; i++) this.later(() => this.tone(f * U.rand(0.9, 1.1), f * 0.55, 0.03, 'square', v), i * 38);
    this.tone(55 + k * 60, 40, 0.35, 'sawtooth', 0.08 + k * 0.25); // low throb underneath
  },
  // every monster dies with its own 8-bit noise (see Combat.kill)
  death(e) {
    if (this.muted || !this.ctx) return;
    if (!this.gate('death:' + (e.kind === 'bug' ? e.type : e.kind), 0.25, 2)) return; // two of a kind at once at most: a pile of kills is a crackle, not a wall
    const T = (f0, f1, d, w, v, delay) => delay ? this.later(() => this.tone(f0, f1, d, w, v), delay) : this.tone(f0, f1, d, w, v);
    const t = this.typeOf(e), D = this.custom('death:' + t); if (D) { this.layers(D); return; } // designed in the SFX editor
    switch (t) {
      case 'gnat':     T(1900, 500, 0.08, 'square', 0.25); T(2400, 900, 0.05, 'square', 0.15, 60); break;                                  // a zip
      case 'beetle':   T(320, 70, 0.16, 'square', 0.4); T(900, 200, 0.05, 'sawtooth', 0.2, 40); break;                                      // a crunchy pop
      case 'stinkbug': T(170, 55, 0.32, 'sawtooth', 0.45); T(3200, 1800, 0.25, 'square', 0.05, 80); break;                                 // a burp and a hiss
      case 'hornet':   T(520, 110, 0.28, 'sawtooth', 0.35); T(640, 90, 0.2, 'square', 0.12, 90); break;                                    // a buzz winding down
      case 'queen':    [0, 120, 240].forEach((d, i) => T(660 / (i + 1), 440 / (i + 1), 0.35, 'square', 0.45, d)); T(90, 30, 0.6, 'sawtooth', 0.5, 300); break; // a falling fanfare and a thud
      case 'skull':    this.growl(900, 180, 0.35, 40, 120, 0.4); [0, 70, 130, 210].forEach(d => T(3000 * U.rand(0.8, 1.2), 1500, 0.03, 'square', 0.12, d)); break; // a whoosh and crackle
      case 'earwig':   T(1300, 650, 0.05, 'square', 0.3); T(1300, 650, 0.05, 'square', 0.3, 70); T(200, 60, 0.18, 'sawtooth', 0.35, 120); break; // snap snap thud
      case 'roach':    T(240, 90, 0.12, 'sawtooth', 0.4); T(420, 180, 0.14, 'sine', 0.35, 60); break;                                      // crunch, squish
      case 'iguana':   this.growl(150, 80, 0.4, 14, 30, 0.5); T(600, 300, 0.1, 'sine', 0.3, 300); break;                                   // a croak and a gulp
      case 'snake':    T(2600, 1400, 0.3, 'square', 0.07); [0, 50, 100, 150].forEach(d => T(1800, 1200, 0.025, 'square', 0.15, d)); T(260, 70, 0.2, 'sawtooth', 0.3, 160); break; // hiss, rattle, flop
      case 'python':   T(1900, 900, 0.4, 'square', 0.08); T(140, 50, 0.35, 'sawtooth', 0.45, 120); break;                                   // a deeper hiss and a heavy flop
      case 'boneSnake':[0, 60, 110, 170, 240].forEach((d, i) => T([900, 1300, 700, 1100, 600][i], [700, 1000, 500, 900, 400][i], 0.04, 'square', 0.3, d)); break; // bones clattering
      case 'parasite': T(1400, 2600, 0.14, 'square', 0.3); T(220, 60, 0.16, 'sine', 0.45, 120); break;                                      // a squeal and a splat
      case 'brainling': T(900, 1500, 0.12, 'sine', 0.3); T(700, 120, 0.3, 'square', 0.25, 100); [0, 60, 120].forEach(d => T(2400, 2000, 0.03, 'square', 0.1, 200 + d)); break; // a yelp, a pop, a fizzle
      case 'saucer':   T(1400, 300, 0.4, 'sawtooth', 0.3); T(2200, 2600, 0.15, 'sine', 0.2, 50); T(160, 40, 0.3, 'square', 0.35, 250); break;  // whine down and crash
      case 'mooncow':  this.growl(170, 90, 0.5, 7, 14, 0.45); T(600, 150, 0.2, 'sine', 0.3, 350); break;                                     // a last moo and a thump
      case 'unicycle': T(520, 330, 0.1, 'square', 0.35); T(520, 330, 0.1, 'square', 0.35, 110); T(140, 40, 0.25, 'sawtooth', 0.4, 220); break;  // honk honk, crash
      case 'juggler':  [0, 70, 130, 200, 260].forEach((d, i) => T([1200, 900, 1500, 1000, 700][i], [600, 450, 750, 500, 350][i], 0.06, 'triangle', 0.3, d)); break; // pins clattering
      case 'mime':     break;                                                                                                                 // dies in silence. Of course it does
      case 'pieclown': T(300, 80, 0.12, 'sine', 0.5); T(1200, 300, 0.2, 'sawtooth', 0.1, 40); T(240, 60, 0.3, 'sine', 0.35, 150); break;        // splat, splat
      case 'elephant': this.growl(420, 900, 0.5, 18, 160, 0.5); T(70, 25, 0.6, 'sawtooth', 0.6, 500); break;                                   // a trumpet and a very heavy thud
      case 'blob':     T(200, 700, 0.18, 'sine', 0.4); T(700, 180, 0.22, 'sine', 0.35, 150); break;                                          // bloop
      case 'kamiclown':T(880, 660, 0.08, 'square', 0.3); T(2200, 300, 0.15, 'square', 0.15, 80); T(120, 40, 0.15, 'sawtooth', 0.3, 120); break; // a squeak and a pop
      case 'chicken':  [0, 90, 170].forEach(d => T(1100, 700, 0.07, 'square', 0.3, d)); T(600, 1400, 0.25, 'square', 0.25, 260); break;         // bawk bawk ba-GAWK
      case 'ghost':    { const c = this.ctx, t0 = c.currentTime, o = c.createOscillator(), g = c.createGain(), lfo = c.createOscillator(), lg = c.createGain(); o.type = 'sine'; o.frequency.setValueAtTime(700, t0); o.frequency.exponentialRampToValueAtTime(260, t0 + 0.45); lfo.frequency.value = 9; lg.gain.value = 30; lfo.connect(lg); lg.connect(o.frequency); g.gain.setValueAtTime(0.4, t0); g.gain.exponentialRampToValueAtTime(0.001, t0 + 0.45); o.connect(g); g.connect(this.bus()); o.start(t0); lfo.start(t0); o.stop(t0 + 0.5); lfo.stop(t0 + 0.5); break; } // wooo
      default:         this.play('kill');
    }
  },
  // One of each sound at a time: while a sound is still playing, the same sound is not started again (a chorus of identical
  // tones just gets louder and muddier). Bullets are the exception: two shots may overlap, which is what makes a volley crackle.
  // DUR is roughly how long each sound lasts; anything not listed counts as 0.2s.
  DUR: { shoot: 0.08, hit: 0.07, pickup: 0.25, parasite: 0.45, lose: 0.3, die: 1.3, dot: 0.04, pellet: 0.25, power: 0.85, kill: 0.22, wall: 0.3, freeze: 0.12, zap: 0.08, enter: 0.6, shed: 0.12, eat: 0.2,
         scream: 0.55, roar: 0.85, stomp: 0.16, hiss: 0.45, ray: 0.12, abduct: 1.1, beam: 0.35, moo: 0.6, baa: 0.4, honk: 0.4, horn: 0.7, vroom: 0.9, sputter: 1.3, squirt: 0.3, squeak: 0.2, splat: 0.18,
         laugh: 0.55, trombone: 2.8, trumpet: 0.5, cannon: 0.5, geyser: 0.9, cluck: 0.32, bell: 1.4, mole: 0.2 },
  MAX: { shoot: 2, hit: 2 },
  active: {},
  // true when `name` may start now (and books its slot); false while its limit of copies is still sounding
  gate(name, dur, max) {
    const now = performance.now() / 1000, a = this.active[name] || (this.active[name] = []);
    while (a.length && a[0] <= now) a.shift();
    if (a.length >= (max || this.MAX[name] || 1)) return false;
    a.push(now + (dur || this.DUR[name] || 0.2)); return true;
  },
  // events with their own name (so each can get its own sound in the SFX editor) that play another event's sound until then
  ALIAS: { megaReady: 'power', perkShop: 'power', perk: 'pickup', cleared: 'power', minion: 'power', loadout: 'power', discover: 'power', storm: 'power', synergy: 'power', gearBlast: 'wall' },
  play(name) {
    if (this.muted || !this.ctx) return;
    if (!this.gate(name)) return;
    { const D = this.custom(name); if (D) { this.layers(D); return; } } // designed in the SFX editor
    if (this.ALIAS[name]) { const a = this.ALIAS[name]; if (this.active[a]) this.active[a] = []; this.play(a); return; } // not designed yet: the sound it borrows
    switch (name) {
      case 'shoot': this.tone(700, 240, 0.05, 'square', 0.12); break;
      case 'hit': this.tone(220, 90, 0.07, 'sawtooth', 0.18); break;
      case 'pickup': this.tone(520, 1040, 0.16, 'triangle', 0.5); this.later(() => this.tone(780, 1300, 0.14, 'triangle', 0.4), 90); break;
      case 'parasite': this.tone(320, 110, 0.45, 'sawtooth', 0.45); break;
      case 'lose': this.tone(420, 110, 0.3, 'square', 0.5); break;
      case 'die': this.tone(300, 35, 1.3, 'sawtooth', 0.7); break;
      case 'dot': this.tone(1100, 1500, 0.04, 'square', 0.12); break;
      case 'pellet': this.tone(400, 900, 0.25, 'triangle', 0.5); break;
      case 'power': [0, 0.12, 0.24, 0.36, 0.6].forEach((d, i) => this.later(() => this.tone(420 * (1 + i * 0.22), 420 * (1 + i * 0.22), 0.22, 'triangle', 0.55), d * 1000)); break;
      // the workbench: a fusion is a rising major arpeggio with a bell shimmer on top (a tier-4 result gets the bigger chord), a landing is a soft pop
      case 'fuse': [523, 659, 784, 1047].forEach((f, i) => this.later(() => this.tone(f, f * 1.01, 0.3, 'triangle', 0.4), i * 70)); this.later(() => { this.tone(2093, 2093, 1.1, 'sine', 0.28); this.tone(2093 * 1.5, 2093 * 1.5, 0.6, 'sine', 0.08); }, 260); break;
      case 'fuse4': [392, 523, 659, 784, 1047, 1319].forEach((f, i) => this.later(() => this.tone(f, f * 1.01, 0.34, 'triangle', 0.42), i * 60)); this.later(() => { this.tone(2637, 2637, 1.4, 'sine', 0.3); this.tone(2637 * 1.5, 2637 * 1.5, 0.8, 'sine', 0.1); this.tone(1319, 1319, 1.2, 'triangle', 0.15); }, 330); break;
      case 'land': this.tone(320, 560, 0.08, 'sine', 0.45); this.tone(900, 1500, 0.05, 'triangle', 0.15); break;
      case 'tab': this.tone(800, 1250, 0.05, 'triangle', 0.22); break;
      case 'kill': this.tone(160, 50, 0.22, 'square', 0.35); break;
      case 'wall': this.tone(120, 50, 0.3, 'sawtooth', 0.45); break;
      case 'freeze': this.tone(1600, 2600, 0.12, 'sine', 0.35); break;
      case 'zap': this.tone(2000, 300, 0.08, 'sawtooth', 0.2); break;
      case 'enter': this.tone(200, 60, 0.6, 'sine', 0.6); break;
      case 'shed': this.tone(600, 200, 0.12, 'square', 0.3); break;
      case 'eat': this.tone(300, 700, 0.2, 'square', 0.4); break;
      case 'scream': this.growl(900 * U.rand(0.8, 1.25), 160, 0.55, 28, 170, 0.5); break; // the crab
      case 'roar': this.growl(170, 50, 0.85, 9, 55, 0.65); break;
      case 'fuel': this.tone(880, 860, 0.09, 'square', 0.3); this.later(() => this.tone(660, 640, 0.09, 'square', 0.25), 110); break; // low fuel: a dashboard warning blip
      case 'stomp': this.tone(95, 28, 0.16, 'sine', 0.5); this.tone(60, 20, 0.1, 'square', 0.16); break;
      case 'hiss': this.tone(2400, 1300, 0.45, 'square', 0.1); this.tone(3600, 2000, 0.3, 'square', 0.05); this.growl(120, 70, 0.3, 11, 25, 0.3); break; // the serpent
      case 'ray': this.tone(1800, 600, 0.12, 'square', 0.18); this.tone(2600, 900, 0.1, 'sawtooth', 0.08); break;                                              // a ray gun
      case 'moo': this.moo(); break;
      case 'block': this.tone(1500, 700, 0.06, 'triangle', 0.3); this.tone(3000, 2200, 0.04, 'square', 0.08); break; // a shot pinging off a shield
      case 'shieldup': this.tone(500, 1400, 0.18, 'sine', 0.18); break;
      case 'charge': this.tone(160, 1100, 0.9, 'sawtooth', 0.18); this.tone(320, 2200, 0.9, 'square', 0.06); break; // a rising whine: something is winding up
      case 'snip': this.tone(3200, 1400, 0.05, 'square', 0.35); this.later(() => this.tone(3600, 1500, 0.06, 'square', 0.4), 70); this.later(() => this.tone(220, 90, 0.18, 'sawtooth', 0.3), 90); break; // two scissor clicks and a thunk
      case 'abduct': this.tone(180, 1600, 1.1, 'sine', 0.45); this.later(() => this.tone(900, 1900, 0.5, 'triangle', 0.3), 300); break;                   // the tractor beam
      case 'beam': this.tone(300, 2200, 0.35, 'sawtooth', 0.25); break;                                                                                     // the laser charging
      case 'baa': this.growl(330 * U.rand(0.9, 1.15), 260, 0.4, 16, 40, 0.3); break; // a sheep: a wobbly bleat
      // the circus
      case 'honk': { // a clown's bulb horn: two nasal squeezes, "honk-HONK"
        const blast = (f, d, v) => { this.tone(f, f * 0.82, d, 'sawtooth', v); this.tone(f * 1.01, f * 0.84, d, 'square', v * 0.5); this.tone(f * 2.02, f * 1.7, d * 0.6, 'triangle', v * 0.25); };
        blast(470, 0.14, 0.28); this.later(() => blast(400, 0.2, 0.32), 170); break;
      }
      case 'horn': this.growl(110, 90, 0.7, 30, 40, 0.6); this.tone(220, 180, 0.6, 'square', 0.25); break;                                           // the car's air horn
      case 'vroom': this.tone(60, 260, 0.6, 'sawtooth', 0.5); this.later(() => this.tone(260, 180, 0.3, 'sawtooth', 0.4), 600); break;              // an engine revving
      case 'sputter': [0, 120, 260, 420, 620].forEach((d, i) => this.later(() => this.tone(140 - i * 15, 60, 0.1, 'sawtooth', 0.4), d)); this.later(() => this.tone(90, 30, 0.5, 'sawtooth', 0.35), 800); break; // coughing out of gas
      case 'squirt': this.tone(900, 1800, 0.25, 'sine', 0.25); this.tone(1800, 600, 0.3, 'sine', 0.15); break;                                       // the flower
      case 'squeak': this.tone(1400, 2200, 0.08, 'triangle', 0.35); this.later(() => this.tone(2200, 1200, 0.1, 'triangle', 0.3), 80); break;         // a squeaky toy
      case 'splat': this.tone(260, 60, 0.18, 'sine', 0.55); this.tone(1000, 200, 0.12, 'sawtooth', 0.12); break;                                   // a pie
      case 'laugh': [0, 110, 220, 330, 440].forEach((d, i) => this.later(() => this.tone(700 + (i % 2) * 180, 500, 0.09, 'triangle', 0.3), d)); break; // ha ha ha ha ha
      case 'trombone': [0, 450, 900].forEach((d, i) => this.later(() => this.tone(220 / Math.pow(2, i * 2 / 12), 200 / Math.pow(2, i * 2 / 12), 0.42, 'sawtooth', 0.4), d)); this.later(() => this.tone(165, 110, 1.4, 'sawtooth', 0.4), 1350); break; // wah wah wah wahhhh
      case 'trumpet': this.growl(380, 700, 0.5, 14, 120, 0.4); break;                                                                                // the elephant
      case 'cannon': this.tone(90, 30, 0.5, 'sawtooth', 0.8); this.tone(400, 60, 0.3, 'square', 0.3); this.later(() => this.tone(1200, 300, 0.4, 'sine', 0.25), 60); break;
      case 'geyser': this.tone(150, 900, 0.9, 'sawtooth', 0.12); this.tone(3000, 1500, 0.8, 'square', 0.04); break;                                  // a hiss and a rush
      case 'cluck': [0, 120, 240].forEach(d => this.later(() => this.tone(1100, 700, 0.07, 'square', 0.3), d)); break;
      case 'bell': this.tone(1760, 1760, 1.2, 'sine', 0.5); this.tone(1760 * 2.76, 1760 * 2.76, 0.6, 'sine', 0.15); this.tone(880, 880, 1.4, 'triangle', 0.2); break; // the high striker
      case 'ghost': this.death({ kind: 'ghost' }); break; // Will of the Lich: the dead rise (borrows the ghost's death wail)
      case 'mole': this.tone(500, 250, 0.1, 'square', 0.3); this.later(() => this.tone(250, 500, 0.1, 'square', 0.25), 100); break;
    }
  }
};
