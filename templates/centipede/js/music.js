// Music: everything that is a tune rather than a sound effect, built from oscillators on the fly (no audio files).
//   Music.THEMES     every tune by name, in music-themes.js (loaded after this file): the hand-written boss themes and the
//                    tracker songs.
//   Music.start(t)   starts a theme loop; setMood(semitones, fever) shifts it up and speeds it as the boss weakens; stop() cuts it.
//   Music.song(secs) the singing tree: a pentatonic pan-flute melody over a drum, scheduled once for `secs` seconds.
// Sound effects stay in SFX (js/util.js); the music shares its AudioContext and master gain.
//   TRACKER themes: songs made in the 8-track chip tracker (editor/tracker/) export as
//   { tracker: true, bpm, steps, tracks: [{ name, inst, vol, len, detune?, slide?, cut?, atk?, syn?, notes: [...] }] } where
//   notes is one MIDI number per 16th-note step (_ = rest, -1 = note off) and inst is a wave (square, pulse, sawtooth,
//   triangle, sine, noise, arp, wail), 'synth' (a 3-oscillator patch carried in the track's syn: { osc, a, d, s, r }) or an 8-bit drum (kick, snare, hat, ohat, tom, clap, cowbell, zap). Paste the export
//   into THEMES; a boss plays it with music: '<name>', and a theme named level2..level5 plays across that level's regions,
//   maze_level1..maze_level5 in that level's mazes, complete_level1..complete_level5 once when you beat that level's boss,
//   dungeon_victory once when a dungeon is cleared, and perk_shop in the perk shop after it (else the level's tune).
//   Music.inst is the synth; the tracker carries an identical copy so what you hear there is what the game plays.
const Music = {
  on: false, step: 0, nextT: 0, semitones: 0, fever: false, timer: null, bus: null, filter: null, theme: null,
  STEPS: 16,
  THEMES: {}, // filled by music-themes.js
  name: null, held: null,
  start(theme) {
    if (this.on && this.name === theme) return;
    if (this.on) this.stop(); // switching themes (level music into boss music, or a new level)
    SFX.init(); if (!SFX.ctx) return;
    const c = SFX.ctx;
    this.theme = this.THEMES[theme] || this.THEMES.crab; this.name = theme;
    if (!this.bus) {
      this.bus = c.createGain(); this.filter = c.createBiquadFilter(); this.filter.type = 'lowpass'; this.filter.Q.value = 4;
      this.bus.connect(this.filter); this.filter.connect(SFX.master);
    }
    this.filter.frequency.value = this.theme.filter || 12000;
    this.bus.gain.cancelScheduledValues(c.currentTime); this.bus.gain.setValueAtTime(0.55, c.currentTime); this.on = true; this.step = 0; this.semitones = (typeof G !== 'undefined' && G.nightmare && G.state !== 'title') ? CFG.NIGHTMARE.pitch : 0; this.fever = false; /* (nightmare: the music sinks a few semitones) */ this.nextT = c.currentTime + 0.05;
    this.timer = setInterval(() => this.tick(), 40);
  },
  stop() {
    if (!this.on) return;
    this.on = false; this.name = null; clearInterval(this.timer); this.timer = null;
    if (this.bus && SFX.ctx) { const t = SFX.ctx.currentTime; this.bus.gain.cancelScheduledValues(t); this.bus.gain.setValueAtTime(this.bus.gain.value, t); this.bus.gain.linearRampToValueAtTime(0, t + 0.08); }
  },
  setMood(semitones, fever) { this.semitones = semitones + (G.nightmare ? CFG.NIGHTMARE.pitch : 0); this.fever = fever; if (this.filter && this.theme) this.filter.frequency.value = this.theme.tracker ? 12000 : fever ? this.theme.filter * 2.6 : this.theme.filter + 300 * semitones; },
  note(f, t, dur, type, vol, slide, attack) {
    const c = SFX.ctx, o = c.createOscillator(), g = c.createGain();
    o.type = type; o.frequency.setValueAtTime(f, t); if (slide) o.frequency.exponentialRampToValueAtTime(Math.max(1, slide), t + dur);
    g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(vol, t + (attack || 0.01)); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g); g.connect(this.bus); o.start(t); o.stop(t + dur + 0.02);
  },
  // a church bell: a sine fundamental with two inharmonic partials, long decay
  bell(f, t, vol) { this.note(f, t, 2.4, 'sine', vol); this.note(f * 2.76, t, 1.4, 'sine', vol * 0.35); this.note(f * 5.4, t, 0.7, 'sine', vol * 0.18); this.note(f * 0.5, t, 2.8, 'triangle', vol * 0.3); },
  // a wailing voice: a sine with slow vibrato that slides up into the note
  wail(f, t, dur, vol, theremin) {
    const c = SFX.ctx, o = c.createOscillator(), g = c.createGain(), lfo = c.createOscillator(), lg = c.createGain();
    o.type = 'sine'; o.frequency.setValueAtTime(f * (theremin ? 0.75 : 0.94), t); o.frequency.exponentialRampToValueAtTime(f, t + dur * (theremin ? 0.45 : 0.3)); // a theremin slides a long way into the note
    lfo.type = 'sine'; lfo.frequency.value = theremin ? 6.5 : 5.5; lg.gain.value = f * (theremin ? 0.04 : 0.025); lfo.connect(lg); lg.connect(o.frequency);
    g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(vol, t + dur * 0.25); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g); g.connect(this.bus); o.start(t); lfo.start(t); o.stop(t + dur + 0.02); lfo.stop(t + dur + 0.02);
  },
  // the singing tree: a pan-flute melody over a soft drum, scheduled for `secs` seconds (pentatonic, so any order sounds sweet)
  song(secs) {
    if (SFX.muted || !SFX.ctx) return;
    const now = performance.now() / 1000; if (now < (this.songUntil || 0)) return; this.songUntil = now + secs; // one song at a time: a second tree summoned mid-song joins it in silence
    if (this.THEMES.treesong && this.THEMES.treesong.tracker) { this.playOnce('treesong', secs - 0.3, CFG.SONG.sync); return; } // a tracker song named treesong replaces the generated flute
    const c = SFX.ctx, t0 = c.currentTime + 0.05, scale = [0, 2, 4, 7, 9, 12, 14], root = 392, beat = 0.28;
    const flute = (f, t, dur, vol) => { const o = c.createOscillator(), g = c.createGain(), lfo = c.createOscillator(), lg = c.createGain(); o.type = 'triangle'; o.frequency.setValueAtTime(f, t); lfo.type = 'sine'; lfo.frequency.value = 6; lg.gain.value = f * 0.012; lfo.connect(lg); lg.connect(o.frequency); g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(vol, t + 0.04); g.gain.exponentialRampToValueAtTime(0.0001, t + dur); o.connect(g); g.connect(SFX.master); o.start(t); lfo.start(t); o.stop(t + dur + 0.02); lfo.stop(t + dur + 0.02); };
    const drum = (t, vol) => { const o = c.createOscillator(), g = c.createGain(); o.type = 'sine'; o.frequency.setValueAtTime(130, t); o.frequency.exponentialRampToValueAtTime(45, t + 0.12); g.gain.setValueAtTime(vol, t); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.18); o.connect(g); g.connect(SFX.master); o.start(t); o.stop(t + 0.2); };
    let step = 0, last = 3;
    for (let t = 0; t < secs - 0.3; t += beat, step++) {
      if (step % 2 === 0) drum(t0 + t, step % 8 === 0 ? 0.5 : 0.25);
      if (step % 4 === 3) continue; // a breath
      last = U.clamp(last + U.randi(-2, 2), 0, scale.length - 1);
      flute(root * Math.pow(2, scale[last] / 12), t0 + t, step % 8 === 6 ? beat * 2.2 : beat * 1.1, 0.28);
    }
  },
  // a tracker song played once, for `secs` seconds (the pattern loops to fill them), as a layer over whatever theme is playing:
  // it is scheduled all at once on its own bus, so the level or boss music carries on underneath
  // sync: lock onto the theme that is playing: its tempo (fever included), its pitch shift, and its next step, in phase with its bar
  playOnce(name, secs, sync, bus) {
    const T = this.THEMES[name]; if (!T || !T.tracker || SFX.muted) return;
    SFX.init(); const c = SFX.ctx; if (!c) return;
    if (!this.oneBus) { this.oneBus = c.createGain(); this.oneBus.gain.value = 0.55; this.oneBus.connect(SFX.master); }
    let stepLen = 60 / T.bpm / 4, t0 = c.currentTime + 0.05, k = 1, phase = 0;
    if (sync && this.on && this.theme && this.nextT > c.currentTime - 0.05) {
      const B = this.theme, bpm = B.bpm * (this.fever ? 1.45 : 1); stepLen = 60 / bpm / 4; k = Math.pow(2, this.semitones / 12); t0 = this.nextT;
      phase = B.tracker ? this.step % 16 : (this.step % 8) * 2; // where the theme is in its bar, in 16ths (a classic theme steps in eighths)
    }
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
  // the level-won celebration: a tracker song named complete_level1..complete_level5 (or complete, for every level) played
  // once through, on its own bus so continuing early can cut it (stopFanfare). False when there is none (the game's own chime plays)
  fanfare(level) { return this.playFanfare(['complete_level' + level, 'complete']); },
  // a dungeon cleared (every dot eaten, or the saucer deck's last wave): dungeon_victory (or _level1..5, or maze_victory), the
  // deck trying deck_victory first. Played once over the perk shop; the level's tune comes back when the shop closes
  victory(level, deck) { return this.playFanfare((deck ? ['deck_victory_level' + level, 'deck_victory'] : []).concat(['dungeon_victory_level' + level, 'maze_victory_level' + level, 'dungeon_victory', 'maze_victory'])); },
  playFanfare(names) {
    const name = names.find(n => this.THEMES[n] && this.THEMES[n].tracker); if (!name || SFX.muted) return false;
    SFX.init(); const c = SFX.ctx; if (!c) return false;
    this.stopFanfare(); this.fanBus = c.createGain(); this.fanBus.gain.value = 0.55; this.fanBus.connect(SFX.master);
    const T = this.THEMES[name]; this.playOnce(name, T.steps * 60 / T.bpm / 4, false, this.fanBus);
    return true;
  },
  stopFanfare() { const b = this.fanBus; if (!b || !SFX.ctx) return; const t = SFX.ctx.currentTime; b.gain.cancelScheduledValues(t); b.gain.setValueAtTime(b.gain.value, t); b.gain.linearRampToValueAtTime(0, t + 0.3); setTimeout(() => b.disconnect(), 400); this.fanBus = null; },
  // the theme for a level: 'levelN', or the level's own name (meadow, graveyard, jungle, farm, circus), whichever the tracker saved
  LEVEL_NAMES: [[], ['level1', 'meadow'], ['level2', 'graveyard'], ['level3', 'jungle'], ['level4', 'farm'], ['level5', 'circus']],
  levelTheme(L) { for (const n of this.LEVEL_NAMES[Math.min(L, this.LEVEL_NAMES.length - 1)] || []) if (this.THEMES[n]) return n; return null; }, // past level 5 the world stays circus, and so does its tune
  isLevelTheme(name) { return this.LEVEL_NAMES.some(ns => ns.includes(name)); },
  // the theme for a boss: a song saved under the boss's own id (crab, skull, serpent, overlord, clowncar) wins over its classic theme (crab, skull, serpent, alien, clown)
  bossTheme(type, B) { const m = B.music || 'crab'; return this.THEMES[type] && this.THEMES[type].tracker ? type : this.THEMES[m] ? m : m + '_classic'; }, // (crab and clown: the tracker songs; the hand-written ones are crab_classic and clown_classic)
  // ---- the tracker synth (identical to Synth.inst in ../tracker/index.html: keep the two in step) ----
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
  // a tracker theme: one 16th per step, every track its own instrument; melodic tracks shift with the mood, drums stay put
  tickTracker() {
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
  tick() {
    if (!this.on || !SFX.ctx) return;
    if (this.theme.tracker) { this.tickTracker(); return; }
    const T = this.theme, c = SFX.ctx, stepLen = 60 / (T.bpm * (this.fever ? 1.45 : 1)) / 2; // eighth notes
    while (this.nextT < c.currentTime + 0.12) {
      const t = this.nextT, i = this.step % this.STEPS, k = Math.pow(2, this.semitones / 12) * (T.warp ? 1 + 0.018 * Math.sin(t * 1.9) : 1), muted = SFX.muted, root = T.root; // warp: a slow tape wobble on everything
      if (!muted) {
        const b = T.bass[i]; if (b != null) this.note(root * Math.pow(2, b / 12) * k, t, stepLen * (T.bassLen || 0.9), T.bassWave, T.bassVol, 0, T.bassLen ? 0.08 : 0.01);
        if (T.kick && i % 4 === 0) this.note(150, t, 0.16, 'sine', 0.9, 40);                                   // kick
        if (T.toms && T.toms.includes(i)) this.note(i % 8 < 4 ? 220 : 170, t, 0.2, 'sine', 0.5, i % 8 < 4 ? 120 : 90); // toms
        if (T.hat && (i % 4 === 2 || (this.fever && i % 2 === 1))) this.note(6000, t, 0.03, 'square', 0.06);   // hat
        if (T.organ) { const ch = T.organ[i]; if (ch) for (const n of ch) { if (T.warp) { this.note(root * 2 * Math.pow(2, n / 12) * k, t, stepLen * 0.9, 'square', 0.07, 0, 0.01); this.note(root * 2 * Math.pow(2, n / 12) * k * 1.006, t, stepLen * 0.9, 'triangle', 0.09, 0, 0.01); } else { this.note(root * 2 * Math.pow(2, n / 12) * k, t, stepLen * 7.5, 'triangle', 0.16, 0, 0.25); this.note(root * 2 * Math.pow(2, n / 12) * k * 1.004, t, stepLen * 7.5, 'sine', 0.1, 0, 0.3); } } } // two detuned voices per note: a church organ (short and piping on the calliope)
        if (T.bell && T.bell.includes(i) && (!this.fever || i === 0)) this.bell(root * 4 * k, t, 0.5);
        const l = T.lead[i]; if (l != null) { const f = root * 4 * Math.pow(2, l / 12) * k; if (T.wail) this.wail(f, t, stepLen * (T.leadLen || 1.6), T.leadVol, T.theremin); else this.note(f, t, stepLen * (T.leadLen || 1.6), T.leadWave, T.leadVol); }
        if (this.fever && i % 8 === 4) this.note(root * 2 * k, t, stepLen * 3, 'sawtooth', 0.25, root * 2.2 * k); // a rising snarl
        if (this.fever && T.wail && i % 4 === 0) this.wail(root * 8 * k, t, stepLen * 2, 0.15);                  // the voices shriek
      }
      this.nextT += stepLen; this.step++;
    }
  },
};
