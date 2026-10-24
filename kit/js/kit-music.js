// VoidLux kit: music. Tunes built from oscillators on the fly, no audio files. Songs come from the chip tracker
// (kit/editor/tracker/), which saves into the game's js/music-themes.js as entries of Music.THEMES:
//   { tracker: true, bpm, steps, tracks: [{ name, inst, vol, len, detune?, slide?, cut?, atk?, syn?, notes: [...] }] }
// notes is one MIDI number per 16th-note step (null = rest, -1 = note off); inst is a wave (square, pulse, sawtooth, triangle,
// sine, noise, arp, wail), 'synth' (a 3-oscillator patch in syn: { osc, a, d, s, r }) or an 8-bit drum (kick, snare, hat,
// ohat, tom, clap, cowbell, zap).
//   Music.start('name')          loop a theme (switching themes stops the old one); Music.stop() fades it out
//   Music.setMood(semitones, fever)  pitch the loop up and speed it (a boss weakening, a timer running out)
//   Music.playOnce('name', secs, sync)  a song over the theme for secs seconds on its own bus (sync: in step with the theme)
//   Music.fanfare('name')        a song once through on its own bus; Music.stopFanfare() cuts it
//   Music.has('name')            whether a theme exists (a game asks before it leans on a name)
// Music.inst is the synth; the tracker carries an identical copy (Synth.inst) so what you hear there is what the game plays.
const Music = {
  on: false, step: 0, nextT: 0, semitones: 0, fever: false, timer: null, bus: null, filter: null, theme: null, name: null,
  THEMES: {}, // filled by the game's js/music-themes.js
  has(name) { return !!(this.THEMES[name] && this.THEMES[name].tracker); },
  start(theme) {
    if (this.on && this.name === theme) return;
    if (this.on) this.stop();
    const T = this.THEMES[theme]; if (!T || !T.tracker) return;
    SFX.init(); if (!SFX.ctx) return;
    const c = SFX.ctx;
    this.theme = T; this.name = theme;
    if (!this.bus) { this.bus = c.createGain(); this.filter = c.createBiquadFilter(); this.filter.type = 'lowpass'; this.filter.Q.value = 1; this.bus.connect(this.filter); this.filter.connect(SFX.master); }
    this.filter.frequency.value = T.filter || 12000;
    this.bus.gain.cancelScheduledValues(c.currentTime); this.bus.gain.setValueAtTime(0.55, c.currentTime); this.on = true; this.step = 0; this.semitones = 0; this.fever = false;
    this.nextT = c.currentTime + 0.05;
    this.timer = setInterval(() => this.tick(), 40);
  },
  stop() {
    if (!this.on) return;
    this.on = false; this.name = null; clearInterval(this.timer); this.timer = null;
    if (this.bus && SFX.ctx) { const t = SFX.ctx.currentTime; this.bus.gain.cancelScheduledValues(t); this.bus.gain.setValueAtTime(this.bus.gain.value, t); this.bus.gain.linearRampToValueAtTime(0, t + 0.08); }
  },
  setMood(semitones, fever) { this.semitones = semitones || 0; this.fever = !!fever; },
  tick() {
    if (!this.on || !SFX.ctx) return;
    const T = this.theme, c = SFX.ctx, stepLen = 60 / (T.bpm * (this.fever ? 1.45 : 1)) / 4, k = Math.pow(2, this.semitones / 12), muted = SFX.muted;
    while (this.nextT < c.currentTime + 0.12) {
      const t = this.nextT, i = this.step % T.steps;
      if (!muted) for (const tr of T.tracks) {
        const n = tr.notes[i]; if (n == null || n === -1) continue;
        let hold = tr.len; for (let j = 1; j < tr.len; j++) { const m = tr.notes[(i + j) % T.steps]; if (m != null) { hold = j; break; } }
        const drum = this.DRUMS.includes(tr.inst), f = 440 * Math.pow(2, (n - 69) / 12) * (drum ? 1 : k);
        this.inst(c, this.bus, tr.inst, f, t, Math.max(0.05, stepLen * hold), tr.vol * (this.fever && drum ? 1.15 : 1), { detune: tr.detune, slide: tr.slide, cut: tr.cut, atk: tr.atk, syn: tr.syn });
      }
      this.nextT += stepLen; this.step++;
    }
  },
  // a tracker song played once for `secs` seconds (the pattern loops to fill them) as a layer over the theme, on its own bus.
  // sync: lock onto the playing theme's tempo, pitch shift and next step, in phase with its bar
  playOnce(name, secs, sync, bus) {
    const T = this.THEMES[name]; if (!T || !T.tracker || SFX.muted) return;
    SFX.init(); const c = SFX.ctx; if (!c) return;
    if (!this.oneBus) { this.oneBus = c.createGain(); this.oneBus.gain.value = 0.55; this.oneBus.connect(SFX.master); }
    let stepLen = 60 / T.bpm / 4, t0 = c.currentTime + 0.05, k = 1, phase = 0;
    if (sync && this.on && this.theme && this.nextT > c.currentTime - 0.05) { const B = this.theme; stepLen = 60 / (B.bpm * (this.fever ? 1.45 : 1)) / 4; k = Math.pow(2, this.semitones / 12); t0 = this.nextT; phase = this.step % 16; }
    const n = Math.max(1, Math.floor(secs / stepLen));
    for (let s = 0; s < n; s++) {
      const i = (s + phase) % T.steps, t = t0 + s * stepLen;
      for (const tr of T.tracks) {
        const nn = tr.notes[i]; if (nn == null || nn === -1) continue;
        let hold = tr.len; for (let j = 1; j < tr.len; j++) { const m = tr.notes[(i + j) % T.steps]; if (m != null) { hold = j; break; } }
        this.inst(c, bus || this.oneBus, tr.inst, 440 * Math.pow(2, (nn - 69) / 12) * k, t, Math.max(0.05, stepLen * hold), tr.vol, { detune: tr.detune, slide: tr.slide, cut: tr.cut, atk: tr.atk, syn: tr.syn });
      }
    }
  },
  fanfare(name) {
    const T = this.THEMES[name]; if (!T || !T.tracker || SFX.muted) return false;
    SFX.init(); const c = SFX.ctx; if (!c) return false;
    this.stopFanfare(); this.fanBus = c.createGain(); this.fanBus.gain.value = 0.55; this.fanBus.connect(SFX.master);
    this.playOnce(name, T.steps * 60 / T.bpm / 4, false, this.fanBus); return true;
  },
  stopFanfare() { const b = this.fanBus; if (!b || !SFX.ctx) return; const t = SFX.ctx.currentTime; b.gain.cancelScheduledValues(t); b.gain.setValueAtTime(b.gain.value, t); b.gain.linearRampToValueAtTime(0, t + 0.3); setTimeout(() => b.disconnect(), 400); this.fanBus = null; },
  // ---- the tracker synth (identical to Synth.inst in kit/editor/tracker/index.html: keep the two in step) ----
  DRUMS: ['kick', 'snare', 'hat', 'ohat', 'tom', 'clap', 'cowbell', 'zap'],
  noiseBuf: null,
  noise(ctx) { if (!this.noiseBuf) { const n = ctx.sampleRate * 2, b = ctx.createBuffer(1, n, ctx.sampleRate), d = b.getChannelData(0); for (let i = 0; i < n; i++) d[i] = Math.random() * 2 - 1; this.noiseBuf = b; } const s = ctx.createBufferSource(); s.buffer = this.noiseBuf; s.loop = true; return s; },
  env(ctx, dest, t, dur, vol, atk) { const g = ctx.createGain(); g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(Math.max(0.0002, vol), t + Math.max(0.003, atk || 0.01)); g.gain.exponentialRampToValueAtTime(0.0001, t + dur); g.connect(dest); return g; },
  inst(ctx, dest, kind, f, t, dur, vol, o) {
    o = o || {}; const det = o.detune || 0, cut = o.cut || 0, slide = o.slide || 0;
    let out = dest; if (cut && cut < 12000) { const lp = ctx.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = cut; lp.connect(dest); out = lp; }
    const osc = (type, f0, f1, d, v, atk) => { const x = ctx.createOscillator(), g = this.env(ctx, out, t, d, v, atk); x.type = type; x.frequency.setValueAtTime(f0, t); if (f1 && f1 !== f0) x.frequency.exponentialRampToValueAtTime(Math.max(1, f1), t + d); x.detune.value = det; x.connect(g); x.start(t); x.stop(t + d + 0.05); return x; };
    const nz = (d, v, hp, lp) => { const s = this.noise(ctx), g = this.env(ctx, out, t, d, v, 0.002); let node = s; if (hp) { const h = ctx.createBiquadFilter(); h.type = 'highpass'; h.frequency.value = hp; node.connect(h); node = h; } if (lp) { const l = ctx.createBiquadFilter(); l.type = 'lowpass'; l.frequency.value = lp; node.connect(l); node = l; } node.connect(g); s.start(t); s.stop(t + d + 0.05); };
    const f1 = slide ? f * Math.pow(2, slide / 12) : f;
    switch (kind) {
      case 'square': case 'sawtooth': case 'triangle': case 'sine': osc(kind, f, f1, dur, vol, o.atk); break;
      case 'pulse': { const x = ctx.createOscillator(), y = ctx.createOscillator(), g = this.env(ctx, out, t, dur, vol * 0.7, o.atk); x.type = 'sawtooth'; y.type = 'sawtooth'; x.frequency.setValueAtTime(f, t); y.frequency.setValueAtTime(f, t); if (f1 !== f) { x.frequency.exponentialRampToValueAtTime(f1, t + dur); y.frequency.exponentialRampToValueAtTime(f1, t + dur); } y.detune.value = det + 3; const inv = ctx.createGain(); inv.gain.value = -1; const dl = ctx.createDelay(); dl.delayTime.value = 0.25 / f; x.connect(g); y.connect(dl); dl.connect(inv); inv.connect(g); x.start(t); y.start(t); x.stop(t + dur + 0.05); y.stop(t + dur + 0.05); break; }
      case 'noise': nz(dur, vol, 0, cut || 4000); break;
      case 'arp': { const n = Math.max(2, Math.round(dur / 0.06)); for (let i = 0; i < n; i++) { const x = ctx.createOscillator(), g = this.env(ctx, out, t + i * 0.06, 0.07, vol, 0.003); x.type = 'square'; x.frequency.value = f * (i % 2 ? 2 : 1); x.detune.value = det; x.connect(g); x.start(t + i * 0.06); x.stop(t + i * 0.06 + 0.1); } break; }
      case 'wail': { const x = ctx.createOscillator(), g = this.env(ctx, out, t, dur, vol, dur * 0.25), lfo = ctx.createOscillator(), lg = ctx.createGain(); x.type = 'sine'; x.frequency.setValueAtTime(f * 0.94, t); x.frequency.exponentialRampToValueAtTime(f1, t + dur * 0.3); lfo.frequency.value = 5.5; lg.gain.value = f * 0.025; lfo.connect(lg); lg.connect(x.frequency); x.connect(g); x.start(t); lfo.start(t); x.stop(t + dur + 0.05); lfo.stop(t + dur + 0.05); break; }
      case 'synth': { // 3-oscillator additive synth. o.syn = { osc: [{ w, st, fine, lvl } x3], a, d, s, r }: wave (sine, triangle, square,
        // sawtooth, noise, off), semitones above the note, cents, level 0..1; then attack, decay, release in seconds and sustain 0..1
        const p = o.syn || { osc: [{ w: 'sine', st: 0, fine: 0, lvl: 1 }], a: 0.01, d: 0.1, s: 0.7, r: 0.1 };
        const a = Math.max(0.003, p.a ?? 0.01), d = Math.max(0.005, p.d ?? 0.1), s = Math.min(1, Math.max(0, p.s ?? 0.7)), r = Math.max(0.01, p.r ?? 0.1);
        const oscs = (p.osc || []).filter(x => x && x.w && x.w !== 'off' && x.lvl > 0), sum = oscs.reduce((m, x) => m + x.lvl, 0); if (!sum) break;
        const g = ctx.createGain(), rel = Math.max(t + dur, t + a), end = rel + r * 1.6 + 0.05; // the gate is the note length; the release rings past it
        g.gain.setValueAtTime(0, t); g.gain.linearRampToValueAtTime(vol, t + a);
        if (rel > t + a + 0.001) g.gain.setTargetAtTime(vol * s, t + a, d / 3);
        g.gain.setTargetAtTime(0, rel, r / 4); g.connect(out);
        for (const x of oscs) {
          const m = ctx.createGain(); m.gain.value = x.lvl / Math.max(1, sum); m.connect(g); // levels mix down so three full oscillators do not clip
          if (x.w === 'noise') { const n = this.noise(ctx); n.connect(m); n.start(t); n.stop(end); continue; }
          const k = Math.pow(2, (x.st || 0) / 12), y = ctx.createOscillator(); y.type = x.w; y.frequency.setValueAtTime(f * k, t); if (f1 !== f) y.frequency.exponentialRampToValueAtTime(f1 * k, t + dur); y.detune.value = det + (x.fine || 0); y.connect(m); y.start(t); y.stop(end);
        }
        break;
      }
      case 'kick': osc('sine', 150, 40, 0.18, vol * 1.2, 0.002); osc('square', 60, 20, 0.08, vol * 0.25, 0.002); break;
      case 'snare': osc('triangle', 190, 120, 0.12, vol * 0.6, 0.002); nz(0.16, vol * 0.7, 1200, 0); break;
      case 'hat': nz(0.04, vol * 0.5, 7000, 0); break;
      case 'ohat': nz(0.18, vol * 0.45, 6000, 0); break;
      case 'tom': osc('sine', f * 2, f * 0.9, 0.22, vol, 0.002); break;
      case 'clap': for (let i = 0; i < 3; i++) { const s = this.noise(ctx), g = this.env(ctx, out, t + i * 0.012, 0.05, vol * 0.5, 0.002), h = ctx.createBiquadFilter(); h.type = 'bandpass'; h.frequency.value = 1800; h.Q.value = 0.8; s.connect(h); h.connect(g); s.start(t + i * 0.012); s.stop(t + i * 0.012 + 0.1); } nz(0.2, vol * 0.3, 1500, 0); break;
      case 'cowbell': osc('square', 560, 560, 0.12, vol * 0.4, 0.002); osc('square', 845, 845, 0.12, vol * 0.3, 0.002); break;
      case 'zap': osc('sawtooth', f * 4, f * 0.25, 0.14, vol * 0.6, 0.002); break;
      default: osc('square', f, f1, dur, vol, o.atk);
    }
  },
};
