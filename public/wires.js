// The builder at work: each run on a spine at the left, wired into the parts of the game it read or changed (cards of rows on
// the right), with light running along the wires as it works. Every wire is real: a step of a run that touched that part.
//   reading pulls light out of the part into the run (cyan); writing pushes it into the part (the part's colour); a playtest is green.
// Data: /studio/wiredata/<slug>?since=<t> every 2 s (Studio::wiredata). Replay plays the last run's steps back, quickly.
(() => {
  const $ = id => document.getElementById(id), cv = $('wires'), g = cv.getContext('2d');
  const GROUPS = [ // the parts of a game, in the columns they stand in
    { id: 'design', name: 'Design', col: 0, color: '#ff4f81' }, { id: 'code', name: 'Code', col: 0, color: '#c58aff' },
    { id: 'look', name: 'Look', col: 1, color: '#ffb547' }, { id: 'rules', name: 'Rules', col: 1, color: '#2fd6c3' }, { id: 'sound', name: 'Sound', col: 1, color: '#7de0ff' },
    { id: 'page', name: 'Page', col: 2, color: '#9bf06a' }, { id: 'artist', name: "The artist", col: 2, color: '#ffd166' }, { id: 'tools', name: 'Tools', col: 2, color: '#ff8a5c' },
    { id: 'kit', name: 'The kit (shared)', col: 2, color: '#8a86b0' } ];
  const TWO = { design: 0, code: 0, page: 0, artist: 0, look: 1, rules: 1, sound: 1, tools: 1, kit: 1 }; // where each card stands in a tall video
  const GROUP = Object.fromEntries(GROUPS.map(x => [x.id, x])), READ = '#7de0ff', PLAY = '#9bf06a', MAXROWS = 7;
  const S = { events: [], since: 0, runs: [], nodes: new Map(), wires: new Map(), parts: [], focus: -1, live: false, steps: 0, cost: 0, frame: 0, t0: performance.now(), replaying: null, log: [], zoom: 1, zoomTo: 1, all: false, building: null, born: {} };

  /* ---- which parts a step touched, and which way ---- */
  const groupOf = p => /^GAME\.md$|^AGENTS\.md$/.test(p) ? 'design' : p === 'index.html' ? 'page' : /^js\/(sfx|music)/.test(p) ? 'sound'
    : /^assets\/events|^js\/(events|ui-events|rules)/.test(p) ? 'rules' : /^assets\//.test(p) ? 'look' : /^js\//.test(p) ? 'code' : /^sketches\//.test(p) ? 'artist' : /^(kit|templates|studio)\//.test(p) ? 'kit' : 'design';
  const rowOf = p => /(^|\/)shots\/.*\.(png|jpe?g)$|^\/tmp\/.*\.(png|jpe?g)$/.test(p) ? 'screenshots' : /(^|\/)\.?studio\/notes\//.test(p) ? 'your notes' : /^sketches\//.test(p) ? "the artist's sketches" : /^assets\/.*\.glb$/.test(p) ? '3D models' : /^assets\/.*\.(png|svg|webp)$/.test(p) ? 'pictures' : /^(kit|templates|studio)\//.test(p) ? p.split('/').slice(0, 2).join('/') : p;
  const PATHS = /(?:\.\.\/\.\.\/)?(?:js|assets|sketches|kit|templates|studio)\/[A-Za-z0-9_.\/-]+[A-Za-z0-9_]|GAME\.md|index\.html|AGENTS\.md/g;
  const WRITES = /open\([^)]*['"][wa]['"]|>\s*[A-Za-z.\/]|\bsed -i|\bmv\s|\bcp\s|\btee\s|writeFile|\.write\(|\bgit add\b|writeAtomic|unlink|\brm\s/;
  function targets(e) {
    if (e.kind === 'soak') return [{ g: 'tools', row: 'playtests', dir: 'p' }];
    if (e.kind === 'art' || e.kind === 'rule') return [{ g: 'tools', row: 'saved versions', dir: 'w' }];
    if (e.kind !== 'tool') return [];
    const n = e.name || '', out = [];
    const m = /^mcp__plugin_([a-z0-9-]+)_/.exec(n); if (m) return [{ g: 'artist', row: m[1], dir: 'w' }];
    if (/^(Read|Glob|Grep|NotebookRead)$/.test(n) && e.file) return [{ g: groupOf(clean(e.file)), row: rowOf(clean(e.file)), dir: 'r' }];
    if (/^(Write|Edit|MultiEdit|NotebookEdit)$/.test(n) && e.file) return [{ g: groupOf(clean(e.file)), row: rowOf(clean(e.file)), dir: 'w' }];
    if (/^(WebFetch|WebSearch)$/.test(n)) return [{ g: 'tools', row: 'the web', dir: 'r' }];
    if (/^(ToolSearch|Task|Agent|TodoWrite|Skill)$/.test(n)) return [{ g: 'tools', row: 'its helpers', dir: 'r' }];
    if (/^bash$/i.test(n)) { const c = e.command || '', w = WRITES.test(c), seen = new Set();
      for (const p of (c.match(PATHS) || []).map(clean)) { const k = groupOf(p) + '|' + rowOf(p); if (seen.has(k)) continue; seen.add(k); out.push({ g: groupOf(p), row: rowOf(p), dir: w ? 'w' : 'r' }); }
      if (/\bgit (commit|tag)\b/.test(c)) out.push({ g: 'tools', row: 'saved versions', dir: 'w' });
      if (/studio\/bin\/(soak|shot)|soak=/.test(c)) out.push({ g: 'tools', row: 'playtests', dir: 'p' });
      if (/node --check/.test(c) && !out.length) out.push({ g: 'code', row: 'checked the code', dir: 'r' });
      if (!out.length) out.push({ g: 'tools', row: 'commands it ran', dir: 'r' });
      return out.slice(0, 6); }
    return [{ g: 'tools', row: n.toLowerCase(), dir: 'r' }];
  }
  const clean = p => String(p).replace(/^\.\.\/\.\.\//, '').replace(/^\/var\/www\/html\/default\/voidlux\/games\/[^/]+\//, '').replace(/^\.\//, '');

  /* ---- events → runs (a run opens with a prompt or a stage, and ends at done) ---- */
  function ingest(list, animate) {
    for (const e of list) { S.events.push(e); S.since = Math.max(S.since, e.t);
      let run = S.runs[S.runs.length - 1];
      const opens = e.kind === 'prompt' || e.kind === 'stage' || e.kind === 'tool'; // what follows a run's end (idle, checkpoint, notes) belongs to that run
      if (!run || (run.done && opens) || ((e.kind === 'prompt' || e.kind === 'stage') && run.steps > 0)) { if (!opens) continue; run = { i: S.runs.length, t0: e.t, t1: e.t, label: '', ask: '', said: '', cost: 0, steps: 0, done: false, touched: new Map() }; S.runs.push(run); }
      run.t1 = e.t;
      if (e.kind === 'prompt') { run.ask = e.text; run.label = run.label || (e.mode === 'chat' ? 'you asked' : e.mode || 'run'); }
      if (e.kind === 'stage') { run.label = e.stage || run.label; run.ask = run.ask || e.text; }
      if (e.kind === 'text') run.said = e.text;
      if (e.kind === 'done') { run.done = true; run.cost = e.cost || 0; S.cost += run.cost; run.said = run.said || e.text; }
      if (e.kind === 'tool') { run.steps++; S.steps++; }
      for (const t of targets(e)) { const key = t.g + '|' + t.row; if (!S.nodes.has(key)) { const now = animate ? performance.now() : 0; S.nodes.set(key, { key, g: t.g, row: t.row, n: 0, last: 0, flash: 0, born: now }); if (!(t.g in S.born)) S.born[t.g] = now; }
        const node = S.nodes.get(key); node.n++; node.last = e.t;
        const wk = run.i + '>' + key, w = S.wires.get(wk) || { key: wk, run: run.i, node: key, r: 0, w: 0, p: 0 }; w[t.dir]++; S.wires.set(wk, w); run.touched.set(key, true);
        if (animate) { node.flash = 1; burst(w, t.dir, 10); } }
      if (animate && (e.kind === 'tool' || e.kind === 'soak' || e.kind === 'done' || e.kind === 'stage')) logLine(e);
    }
    if (!animate) { const r = S.live ? S.runs[S.runs.length - 1] : S.runs.filter(x => x.steps).pop(); S.focus = r ? r.i : S.runs.length - 1; } // opens on the run working now, else the last that did something
    else if (S.focus < 0 || S.focus === S.runs.length - 2 || (S.live && !S.building && S.focus !== S.runs.length - 1 && S.runs[S.runs.length - 1].t0 > Date.now() / 1000 - 600)) S.focus = S.runs.length - 1; // a run that starts while you watch takes the stage
    layout(); panels();
  }

  /* ---- where everything stands ---- */
  let W = 0, H = 0, VW = 0, VH = 0, spine = [], cards = [];
  // Everything is laid out in a space that zooms: at 1 it is the window; while it builds, it zooms out until every part fits.
  function layout() {
    const R = S.rec, dpr = R ? R.scale : Math.min(2, devicePixelRatio || 1); W = R ? R.w : innerWidth; H = R ? R.h : innerHeight; // recording: the video's own stage, whatever the window if (cv.width !== Math.round(W * dpr)) { cv.width = Math.round(W * dpr); cv.height = Math.round(H * dpr); }
    const Z = S.zoom; VW = W / Z; VH = H / Z; g.setTransform(dpr * Z, 0, 0, dpr * Z, 0, 0);
    const padTop = R ? R.top : 120, padBottom = R ? R.bottom : 210, top = padTop / Z, bottom = (H - padBottom) / Z, maxRows = S.all ? 14 : MAXROWS, two = VW / VH < 0.8;
    const x0 = two ? Math.max(150, VW * 0.22) : Math.max(240, VW * 0.32), avail = VW - 18 - x0, nc = two ? 2 : 3, cw = Math.min(290, (avail - (nc - 1) * 40) / nc), gap = nc > 1 ? (avail - cw) / (nc - 1) : 0, cols = [x0, x0 + gap, x0 + 2 * gap], // a tall video: two columns
          colOf = G => two ? TWO[G.id] : G.col, // three columns that end inside the window
          rh = 21, ys = [top - 20, top - 20, top - 20]; cards = [];
    for (const G of GROUPS) { let rows = [...S.nodes.values()].filter(n => n.g === G.id); if (!S.all) rows.sort((a, b) => b.n - a.n); if (!rows.length) continue; // the whole game keeps the order first touched, so new parts appear at the foot
      const more = rows.length > maxRows ? rows.splice(maxRows - 1) : []; if (more.length) rows.push({ key: G.id + '|+', g: G.id, row: `+ ${more.length} more`, n: more.reduce((s, x) => s + x.n, 0), last: Math.max(...more.map(x => x.last)), flash: 0, born: Math.max(...more.map(x => x.born)), merged: more.map(x => x.key) });
      const ci = colOf(G), c = { G, x: cols[ci], y: ys[ci], w: cw, total: rows.reduce((s, x) => s + x.n, 0), rows: rows.map((n, k) => ({ node: n, y: ys[ci] + 26 + k * rh })) }; c.h = 30 + rows.length * rh; ys[ci] += c.h + 16; cards.push(c); }
    const tall = Math.max(...ys) - (top - 20); // how tall the cards stand, in the zoomed space
    S.zoomTo = S.all ? Math.max(0.4, Math.min(1, ((H - padBottom) - padTop + 20) / Math.max(1, tall))) : 1;
    const runs = S.all ? S.runs : S.runs.slice(-32), span = Math.max(bottom - top, tall - 20), step = Math.max(S.all ? 3 : 14, Math.min(30, span / Math.max(1, runs.length)));
    spine = runs.map((r, k) => ({ run: r, x: 78, y: top + k * step })); S.spineStep = step;
    S.port = new Map(); for (const c of cards) for (const r of c.rows) { S.port.set(r.node.key, { x: c.x - 6, y: r.y + 8, c, r }); for (const k of r.node.merged || []) S.port.set(k, { x: c.x - 6, y: r.y + 8, c, r }); }
    S.runPort = new Map(spine.map(s => [s.run.i, s]));
    $('nRuns').textContent = S.runs.length; $('nNodes').textContent = S.nodes.size; $('nWires').textContent = S.wires.size; $('nSteps').textContent = S.steps; $('nCost').textContent = '$' + S.cost.toFixed(2);
  }
  addEventListener('resize', layout);

  /* ---- light along the wires ---- */
  const parts = [];
  function curve(w) { const a = S.runPort.get(w.run), b = S.port.get(w.node); if (!a || !b) return null; const dx = (b.x - a.x) * 0.5; return [a.x + 8, a.y, a.x + 8 + dx, a.y, b.x - dx, b.y, b.x, b.y]; }
  const at = (p, s) => { const u = 1 - s; return [u * u * u * p[0] + 3 * u * u * s * p[2] + 3 * u * s * s * p[4] + s * s * s * p[6], u * u * u * p[1] + 3 * u * u * s * p[3] + 3 * u * s * s * p[5] + s * s * s * p[7]]; };
  // light always runs from the run into the part; its colour says what the run did there (looked, changed, played)
  function burst(w, dir, n) { for (let k = 0; k < n; k++) parts.push({ w, s: 0, v: 0.35 + Math.random() * 0.5, color: dir === 'r' ? READ : dir === 'p' ? PLAY : GROUP[(S.nodes.get(w.node) || {}).g || 'tools'].color, delay: k * 0.05, life: 1 }); }

  /* ---- drawing ---- */
  let last = performance.now();
  function frame(now) { const real = Math.min(0.5, (now - last) / 1000), dt = Math.min(0.05, real); last = now; S.frame++;
    if (S.building) build(real); // by the clock: a tab that draws seldom still builds at the same pace
    if (Math.abs(S.zoom - S.zoomTo) > 0.002) { S.zoom += (S.zoomTo - S.zoom) * Math.min(1, dt * 1.5); layout(); }
    g.fillStyle = '#07060c'; g.fillRect(0, 0, VW, VH);
    g.strokeStyle = 'rgba(155,135,255,0.06)'; g.lineWidth = 1; g.beginPath(); for (let x = 0; x < VW; x += 40) { g.moveTo(x, 0); g.lineTo(x, VH); } for (let y = 0; y < VH; y += 40) { g.moveTo(0, y); g.lineTo(VW, y); } g.stroke();
    /* the spine of runs */
    if (spine.length) { g.fillStyle = '#1a1628'; g.fillRect(64, spine[0].y - 16, 28, spine[spine.length - 1].y - spine[0].y + 32); g.fillStyle = '#c58aff'; g.fillRect(64, spine[0].y - 24, 28, 6);
      g.font = '600 10px ui-monospace, Menlo, monospace'; g.fillStyle = '#a79fc0'; g.textAlign = 'left'; g.fillText('BUILDER', 10, spine[0].y - 38); g.fillText('RUNS', 10, spine[0].y - 26); } // each number is one run, counted from the game's first
    g.font = '11px ui-monospace, monospace'; g.textAlign = 'right';
    for (const s of spine) { const on = s.run.i === S.focus, live = S.live && s.run === S.runs[S.runs.length - 1] && !s.run.done;
      const every = Math.ceil(13 / S.spineStep); if (on || (s.run.i + 1) % every === 0 || every === 1) { g.fillStyle = on ? '#fff' : '#8a86b0'; g.fillText(String(s.run.i + 1).padStart(2, '0'), 58, s.y + 4); } // a number every few runs when they stand close
      g.beginPath(); g.arc(78, s.y, on ? 5 : Math.min(3.5, S.spineStep / 2.4), 0, Math.PI * 2); g.fillStyle = live ? '#ff3d9a' : on ? '#ffcc00' : '#c9b8ff'; g.fill();
      if (live) { g.beginPath(); g.arc(78, s.y, 9 + Math.sin(now / 200) * 3, 0, Math.PI * 2); g.strokeStyle = '#ff3d9a88'; g.stroke(); } }
    /* wires: the focused run's bright, the rest a faint web */
    g.globalCompositeOperation = 'lighter';
    for (const w of S.wires.values()) { const p = curve(w); if (!p) continue; const on = w.run === S.focus, node = S.nodes.get(w.node), col = w.w ? GROUP[node.g].color : w.p ? PLAY : READ;
      g.strokeStyle = col; g.globalAlpha = on ? 0.55 : 0.07; g.lineWidth = on ? 1 + Math.min(3, Math.log2(1 + w.r + w.w + w.p)) : 1;
      g.beginPath(); g.moveTo(p[0], p[1]); g.bezierCurveTo(p[2], p[3], p[4], p[5], p[6], p[7]); g.stroke();
      if (on && Math.random() < 0.04 * (1 + Math.log2(1 + w.r + w.w))) parts.push({ w, s: 0, v: 0.25 + Math.random() * 0.3, color: col, delay: 0, life: 1, soft: true }); }
    for (let k = parts.length - 1; k >= 0; k--) { const q = parts[k]; if (q.delay > 0) { q.delay -= dt; continue; } q.s += q.v * dt; if (q.s < 0 || q.s > 1) { parts.splice(k, 1); continue; }
      const p = curve(q.w); if (!p) { parts.splice(k, 1); continue; } const [x, y] = at(p, q.s), r = q.soft ? 2 : 3.2;
      g.globalAlpha = q.soft ? 0.7 : 1; g.fillStyle = q.color; g.beginPath(); g.arc(x, y, r, 0, Math.PI * 2); g.fill(); g.globalAlpha = q.soft ? 0.18 : 0.35; g.beginPath(); g.arc(x, y, r * 3.2, 0, Math.PI * 2); g.fill(); }
    g.globalAlpha = 1; g.globalCompositeOperation = 'source-over';
    /* the cards: a header in the part's colour, a row for each file or tool, with how often it was touched */
    const max = Math.max(1, ...[...S.nodes.values()].map(n => n.n));
    const F = S.runs[S.focus], dimming = !!(F && F.touched.size);
    for (const c of cards) { const col = c.G.color, age = (now - (S.born[c.G.id] || 0)) / 500;
      g.save(); if (age < 1) { const k = 1 + 2.2 * Math.pow(age - 1, 3) + 1.2 * Math.pow(age - 1, 2), sc = Math.max(0.05, k); g.translate(c.x, c.y); g.scale(sc, sc); g.translate(-c.x, -c.y); g.globalAlpha = Math.min(1, age * 2.5); } // pops in, a little past, then settles
      const base = g.globalAlpha;
      g.fillStyle = 'rgba(14,12,22,0.92)'; rr(c.x, c.y, c.w, c.h, 6); g.fill(); g.strokeStyle = col + '88'; g.lineWidth = 1; rr(c.x, c.y, c.w, c.h, 6); g.stroke();
      g.fillStyle = col; rr(c.x, c.y, c.w, 22, 6, true); g.fill(); g.fillStyle = '#0b0a12'; g.font = 'bold 12px ui-monospace, monospace'; g.textAlign = 'left'; g.fillText(c.G.name, c.x + 8, c.y + 15); g.textAlign = 'right'; g.font = '11px ui-monospace, monospace'; g.fillText(`touched ${c.total}×`, c.x + c.w - 8, c.y + 15);
      for (const r of c.rows) { const n = r.node, f = n.flash, used = !!(F && (F.touched.has(n.key) || (n.merged || []).some(k => F.touched.has(k)))); n.flash = Math.max(0, n.flash - dt * 1.6);
        const fresh = Math.min(1, (now - (n.born || 0)) / 400); g.globalAlpha = base * fresh * (dimming && !used ? 0.32 : 1); const ra = g.globalAlpha; // the run shown stands out; what it did not touch steps back
        g.fillStyle = f > 0 ? `rgba(255,255,255,${0.12 + f * 0.25})` : 'rgba(255,255,255,0.03)'; g.fillRect(c.x + 4, r.y, c.w - 8, 17);
        g.fillStyle = col; g.globalAlpha = ra * (0.35 + (used ? 0.5 : 0)); g.fillRect(c.x + 4, r.y, (c.w - 70) * Math.min(1, n.n / max), 17); g.globalAlpha = ra;
        g.fillStyle = used ? '#fff' : '#b9b0cc'; g.font = '11px ui-monospace, monospace'; g.textAlign = 'left'; g.fillText(trim(n.row, 30), c.x + 10, r.y + 12);
        g.textAlign = 'right'; g.fillStyle = '#ffcc00'; g.fillText(String(n.n), c.x + c.w - 8, r.y + 12);
        g.beginPath(); g.arc(c.x - 6, r.y + 8, 3.5, 0, Math.PI * 2); g.fillStyle = used ? col : '#4a4568'; g.fill(); }
      g.restore(); }
    if (S.rec) caption(now);
    requestAnimationFrame(frame); }
  function rr(x, y, w, h, r, topOnly) { g.beginPath(); g.moveTo(x + r, y); g.lineTo(x + w - r, y); g.quadraticCurveTo(x + w, y, x + w, y + r); if (topOnly) { g.lineTo(x + w, y + h); g.lineTo(x, y + h); } else { g.lineTo(x + w, y + h - r); g.quadraticCurveTo(x + w, y + h, x + w - r, y + h); g.lineTo(x + r, y + h); g.quadraticCurveTo(x, y + h, x, y + h - r); } g.lineTo(x, y + r); g.quadraticCurveTo(x, y, x + r, y); g.closePath(); }
  const trim = (s, n) => s.length > n ? '…' + s.slice(-(n - 1)) : s;

  /* ---- the panels ---- */
  function logLine(e) { const ts = new Date(e.t * 1000).toTimeString().slice(0, 8); let cls = 's', txt = '';
    if (e.kind === 'tool') { const ts2 = targets(e), t = ts2[0] || {}, rows = ts2.map(x => x.row).filter(x => x !== 'commands it ran' && x !== 'checked the code').join(', '); cls = t.dir === 'w' ? 'w' : t.dir === 'p' ? 'p' : t.g === 'artist' ? 'a' : 'r';
      txt = t.g === 'artist' ? (t.row === "the artist's sketches" ? 'worked with the artist' : `asked the artist (${t.row})`) : t.dir === 'p' ? 'played the game to check it' : !rows ? (t.row === 'checked the code' ? 'checked the code' : 'ran a command') : `${t.dir === 'w' ? 'changed' : 'looked at'}  ${rows}`; }
    else if (e.kind === 'soak') { cls = 'p'; txt = `played it: ${e.ok ? 'fine' : 'a problem'}${e.fps ? ' · ' + e.fps.toFixed(0) + ' frames a second' : ''}${e.errors ? ' · ' + e.errors + ' errors' : ''}`; }
    else if (e.kind === 'done') txt = `finished${e.ok ? '' : ' (stopped)'} · $${(e.cost || 0).toFixed(2)}`; else if (e.kind === 'stage') txt = `started: ${e.stage}`;
    S.log.push({ ts, cls, txt }); if (S.log.length > 60) S.log.shift();
    $('log').innerHTML = S.log.slice(-11).map(l => `<div class="${l.cls}">${l.ts}  ${esc(l.txt)}</div>`).join(''); $('logRate').textContent = S.steps + ' steps'; }
  function panels() { const r = S.runs[S.focus]; if (!r) return;
    $('runTitle').textContent = `${String(r.i + 1).padStart(2, '0')} · ${r.label || 'a run'}`; $('runSub').textContent = `${new Date(r.t0 * 1000).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })} · ${r.steps} steps · ${r.touched.size} parts touched · ${r.done ? '$' + r.cost.toFixed(2) : S.live ? 'working now' : 'unfinished'}`;
    $('ask').innerHTML = (r.ask ? `<span class="q">asked:</span> ${esc(r.ask)}\n\n` : '') + (r.said ? `<span class="q">said:</span> ${esc(r.said)}` : ''); $('askWhen').textContent = new Date(r.t0 * 1000).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    $('costBig').textContent = '$' + S.cost.toFixed(2); const recent = S.runs.filter(x => x.done).slice(-6), top = Math.max(0.01, ...recent.map(x => x.cost));
    $('costRuns').innerHTML = recent.map(x => `<div class="row"><span style="width:26px">${String(x.i + 1).padStart(2, '0')}</span><i style="width:${Math.round(x.cost / top * 140)}px"></i>$${x.cost.toFixed(2)}</div>`).join(''); }
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  /* ---- choosing a run: click its dot on the spine; hover a row to see what it is ---- */
  cv.addEventListener('click', ev => { const e = { clientX: ev.clientX / S.zoom, clientY: ev.clientY / S.zoom }; const s = spine.find(s => Math.hypot(e.clientX - s.x, e.clientY - s.y) < 12 || (Math.abs(e.clientY - s.y) < 8 && e.clientX < 100)); if (s) { S.focus = s.run.i; panels(); } });
  cv.addEventListener('mousemove', ev => { const t = $('tip'), e = { clientX: ev.clientX / S.zoom, clientY: ev.clientY / S.zoom };
    const sp = spine.find(s => Math.abs(e.clientY - s.y) < 8 && e.clientX < 100); if (sp) { const r = sp.run; cv.style.cursor = 'pointer'; t.style.display = 'block'; t.style.left = ev.clientX + 14 + 'px'; t.style.top = ev.clientY + 10 + 'px';
      t.textContent = `Run ${r.i + 1} of ${S.runs.length} · ${new Date(r.t0 * 1000).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })} · ${r.steps} steps · ${r.touched.size} parts${r.done ? ' · $' + r.cost.toFixed(2) : ''}${r.ask ? '\n' + r.ask.slice(0, 160) + (r.ask.length > 160 ? '…' : '') : ''}\nClick to show it`; return; } cv.style.cursor = ''; for (const c of cards) for (const r of c.rows) if (e.clientX > c.x && e.clientX < c.x + c.w && e.clientY > r.y && e.clientY < r.y + 17) { t.style.display = 'block'; t.style.left = ev.clientX + 14 + 'px'; t.style.top = ev.clientY + 10 + 'px'; t.textContent = `${r.node.row}: touched ${r.node.n} times, last ${new Date(r.node.last * 1000).toLocaleString()}`; return; } t.style.display = 'none'; });

  /* ---- live: new steps as they happen; replay: the last run again, quickly ---- */
  function fillLog() { const r = S.runs.filter(x => x.steps).pop(); if (r) for (const e of S.events.filter(e => e.t >= r.t0 && e.t <= r.t1 && /tool|soak|done|stage/.test(e.kind)).slice(-11)) logLine(e); } // the log opens on the latest run's steps
  async function poll() { try { const r = await fetch(`/studio/wiredata/${encodeURIComponent(SLUG)}?since=${S.since}`, { cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } }); const j = await r.json(); if (!r.ok || j.success === false) throw new Error(j.error || r.status);
      const d = j.data || j, first = !S.events.length; S.live = !!d.agent.busy; $('live').textContent = S.live ? 'live · ' + (d.agent.mode || 'building') : 'idle'; $('live').classList.toggle('on', S.live);
      if (d.events.length && S.building) { S.building.list.push(...d.events.filter(e => BUILT.has(e.kind))); S.building.all.push(...d.events); for (const e of d.events) S.since = Math.max(S.since, e.t); } else if (d.events.length) { ingest(d.events, !first); if (first) { fillLog(); if (new URLSearchParams(location.search).has('build')) startBuild(); } } } // ?build=1 (from /studio/wires, the list of games): start watching it build at once
    catch (e) { $('live').textContent = 'not reachable: ' + e.message; } setTimeout(poll, 2000); }
  $('replay').onclick = () => { const run = S.runs.filter(r => r.steps).pop(); if (!run) return; if (S.replaying) clearInterval(S.replaying);
    const evs = S.events.filter(e => e.t >= run.t0 && e.t <= run.t1 && (e.kind === 'tool' || e.kind === 'soak' || e.kind === 'done' || e.kind === 'stage')); let k = 0; S.focus = run.i; panels(); S.log = [];
    S.replaying = setInterval(() => { const e = evs[k++]; if (!e) { clearInterval(S.replaying); S.replaying = null; return; } for (const t of targets(e)) { const n = S.nodes.get(t.g + '|' + t.row), w = S.wires.get(run.i + '>' + t.g + '|' + t.row); if (n) n.flash = 1; if (w) burst(w, t.dir, 12); } logLine(e); }, Math.max(60, Math.min(260, 9000 / Math.max(1, evs.length)))); };

  /* ---- watch it build: every run from the first, in order, the parts popping in as they are first touched, zooming out to fit ---- */
  const BUILT = new Set(['prompt', 'stage', 'tool', 'soak', 'art', 'rule', 'done', 'text']), PAUSE = 0.2; // a run's start holds a moment, so its title can be read
  const opensRun = e => e.kind === 'prompt' || e.kind === 'stage';
  const runTime = n => 0.25 + Math.min(1.1, n / 35); // a big run takes longer, but none more than about 1.4 s
  function reset() { S.runs = []; S.nodes = new Map(); S.wires = new Map(); S.steps = 0; S.cost = 0; S.focus = -1; S.log = []; S.born = {}; parts.length = 0; $('log').innerHTML = ''; }
  function startBuild() { if (S.replaying) { clearInterval(S.replaying); S.replaying = null; }
    const all = S.events.slice(), list = all.filter(e => BUILT.has(e.kind)); if (!list.length) return;
    reset(); S.events = []; S.all = true; S.building = { list, k: 0, acc: 0, hold: 0.8, speed: 1, rate: 1, all }; pace(S.building);
    $('build').textContent = '■ Stop'; $('speed').hidden = false; $('speed').textContent = 'Speed 1×'; layout(); }
  function pace(B) { let n = 0, j = B.k; while (j < B.list.length && opensRun(B.list[j])) { n++; j++; } for (; j < B.list.length; j++) { if (opensRun(B.list[j])) break; n++; if (B.list[j].kind === 'done') break; } B.rate = n / runTime(n); } // this run's steps spread over its time
  function build(dt) { const B = S.building; if (B.hold > 0) { B.hold -= dt * B.speed; return; }
    B.acc += dt * B.rate * B.speed; const batch = [];
    while (B.acc >= 1 && B.k < B.list.length) { const e = B.list[B.k++]; B.acc--; batch.push(e);
      const nx = B.list[B.k]; if (nx && ((opensRun(nx) && !opensRun(e)) || e.kind === 'done')) { B.hold = PAUSE; B.acc = 0; pace(B); break; } }
    if (batch.length) { ingest(batch, true); S.focus = S.runs.length - 1; panels(); }
    if (B.k >= B.list.length) endBuild(); }
  function endBuild() { const B = S.building; S.building = null; if (S.rec && S.rec.phase === 'build') { S.rec.phase = 'hold'; S.rec.t = performance.now(); } const t = B.all; reset(); S.events = []; ingest(t, false); fillLog(); $('build').textContent = '▶ Watch it build from run 1'; $('speed').hidden = true; }
  $('build').onclick = () => S.rec ? finishRecording(false) : S.building ? endBuild() : startBuild(); // while recording: stop and keep nothing
  $('speed').onclick = () => { const B = S.building; if (!B) return; B.speed = B.speed >= 8 ? 1 : B.speed * 2; $('speed').textContent = `Speed ${B.speed}×`; };

  /* ---- a video of it building, for posting: the browser films its own canvas (MediaRecorder), nothing runs on the server ----
     The stage is the video's size, not the window's; captions are drawn into the frame (the page's HTML panels are not in a recording):
     the game's name and the ask of the run on stage at the top, the counts and voidlux.com at the foot, and an end card. */
  const SHAPES = { tall: { w: 720, h: 1280, scale: 1.5, top: 270, bottom: 150, label: 'tall' }, square: { w: 900, h: 900, scale: 1.2, top: 175, bottom: 95, label: 'square' },
                   wide: { w: 1280, h: 720, scale: 1.5, top: 128, bottom: 82, label: 'wide' } };
  const MIMES = ['video/mp4;codecs=avc1.640028', 'video/mp4;codecs=avc1', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm'];
  function planSeconds(list) { let t = 0.8, n = 0, j = 0; // how long the build takes at 1×, with the same pacing build() uses
    while (j < list.length) { n = 0; while (j < list.length && opensRun(list[j])) { n++; j++; } for (; j < list.length; j++) { if (opensRun(list[j])) break; n++; if (list[j].kind === 'done') { j++; break; } } t += runTime(n) + PAUSE; }
    return t; }
  function startRecording(shape, seconds) {
    if (typeof MediaRecorder === 'undefined' || !cv.captureStream) { alertBox('This browser cannot record a video of the page. Chrome, Edge or Firefox can.'); return; }
    const mime = MIMES.find(m => MediaRecorder.isTypeSupported(m)); if (!mime) { alertBox('This browser records no video format we know.'); return; }
    if (S.building) endBuild(); const list = S.events.filter(e => BUILT.has(e.kind)); if (!list.length) { alertBox('Nothing to film yet: the builder has not worked on this game.'); return; }
    S.rec = Object.assign({}, SHAPES[shape], { phase: 'build', mime, chunks: [], seconds }); document.body.classList.add('recording'); S.zoom = 1; layout();
    const rec = new MediaRecorder(cv.captureStream(30), { mimeType: mime, videoBitsPerSecond: 10e6 }); S.rec.recorder = rec;
    rec.ondataavailable = e => { if (e.data && e.data.size) S.rec && S.rec.chunks.push(e.data); }; rec.start(500);
    startBuild(); S.building.speed = Math.max(1, planSeconds(list) / Math.max(6, seconds - 7.5)); $('speed').hidden = true; $('build').textContent = '■ Stop recording'; }
  function finishRecording(keep) { const R = S.rec; if (!R) return; S.rec = null;
    R.recorder.onstop = () => { document.body.classList.remove('recording'); S.zoom = 1; layout(); $('build').textContent = '▶ Watch it build from run 1';
      if (!keep) return; const blob = new Blob(R.chunks, { type: R.mime.split(';')[0] }), url = URL.createObjectURL(blob), ext = R.mime.includes('mp4') ? 'mp4' : 'webm';
      $('vidPlay').src = url; $('vidSave').href = url; $('vidSave').download = `${SLUG}-voidlux-${R.label}.${ext}`;
      $('vidInfo').textContent = `${R.label} · ${R.w * R.scale}×${R.h * R.scale} · ${(blob.size / 1048576).toFixed(1)} MB · ${ext.toUpperCase()}` + (ext === 'webm' ? ' (this browser records WebM only: most social sites want MP4, so convert it first, or record in Chrome)' : '');
      $('vid').hidden = false; };
    R.recorder.stop(); if (S.building) endBuild(); }
  const alertBox = t => { $('vidInfo').textContent = t; $('vidPlay').removeAttribute('src'); $('vidSave').hidden = true; $('vid').hidden = false; };
  function wrap(text, width, lines) { const words = String(text).replace(/\s+/g, ' ').trim().split(' '), out = []; let cur = '';
    for (const w of words) { const t = cur ? cur + ' ' + w : w; if (g.measureText(t).width > width && cur) { out.push(cur); cur = w; if (out.length === lines) break; } else cur = t; }
    if (out.length < lines && cur) out.push(cur); if (out.length === lines && words.join(' ').length > out.join(' ').length) out[lines - 1] = out[lines - 1].replace(/\s*\S*$/, '') + '…'; return out; }
  function caption(now) { const R = S.rec, w = R.w, h = R.h, tall = h > w, r = S.runs[S.focus];
    g.setTransform(R.scale, 0, 0, R.scale, 0, 0); g.globalAlpha = 1; g.globalCompositeOperation = 'source-over';
    let gr = g.createLinearGradient(0, 0, 0, R.top); gr.addColorStop(0, '#07060c'); gr.addColorStop(0.8, '#07060cee'); gr.addColorStop(1, '#07060c00'); g.fillStyle = gr; g.fillRect(0, 0, w, R.top);
    gr = g.createLinearGradient(0, h - R.bottom, 0, h); gr.addColorStop(0, '#07060c00'); gr.addColorStop(0.35, '#07060cee'); gr.addColorStop(1, '#07060c'); g.fillStyle = gr; g.fillRect(0, h - R.bottom, w, R.bottom);
    const pad = tall ? 34 : 28, big = tall ? 46 : 34; g.textAlign = 'left';
    g.font = `700 ${tall ? 15 : 12}px ui-monospace, Menlo, monospace`; g.fillStyle = '#c58aff'; g.fillText('BUILT WITH VOIDLUX', pad, pad + 6);
    g.font = `700 ${big}px ui-monospace, Menlo, monospace`; g.fillStyle = '#fff'; g.shadowColor = '#c58aff'; g.shadowBlur = 18; g.fillText(GAME.toUpperCase(), pad, pad + 14 + big); g.shadowBlur = 0;
    if (r) { const y0 = pad + 30 + big + (tall ? 14 : 4); g.font = `600 ${tall ? 17 : 13}px ui-monospace, Menlo, monospace`; g.fillStyle = '#ffcc00';
      g.fillText(`RUN ${r.i + 1}  ·  ${new Date(r.t0 * 1000).toLocaleDateString([], { month: 'short', day: 'numeric' }).toUpperCase()}`, pad, y0);
      if (r.ask && R.top > 150) { g.font = `${tall ? 19 : 15}px ui-monospace, Menlo, monospace`; g.fillStyle = '#e9e4f7'; wrap('“' + r.ask + '”', w - pad * 2, tall ? 4 : 2).forEach((l, k) => g.fillText(l, pad, y0 + (tall ? 32 : 24) + k * (tall ? 26 : 20))); } }
    const fy = h - pad + 2, stat = (label, v, x) => { g.font = `700 ${tall ? 30 : 22}px ui-monospace, Menlo, monospace`; g.fillStyle = '#ffcc00'; g.fillText(String(v), x, fy - (tall ? 22 : 16)); g.font = `${tall ? 13 : 11}px ui-monospace, Menlo, monospace`; g.fillStyle = '#a79fc0'; g.fillText(label, x, fy); };
    stat('RUNS', S.runs.length, pad); stat('PARTS', S.nodes.size, pad + (tall ? 130 : 110)); stat('STEPS', S.steps, pad + (tall ? 260 : 220));
    g.textAlign = 'right'; g.font = `700 ${tall ? 22 : 17}px ui-monospace, Menlo, monospace`; g.fillStyle = '#fff'; g.fillText('voidlux.com', w - pad, fy);
    if (R.phase === 'hold' && now - R.t > 1400) { R.phase = 'end'; R.t = now; }
    if (R.phase === 'end') { const a = Math.min(1, (now - R.t) / 600); g.textAlign = 'center'; g.fillStyle = `rgba(7,6,12,${0.88 * a})`; g.fillRect(0, 0, w, h); g.globalAlpha = a;
      g.font = `700 ${tall ? 54 : 44}px ui-monospace, Menlo, monospace`; g.fillStyle = '#fff'; g.shadowColor = '#c58aff'; g.shadowBlur = 24; g.fillText(GAME.toUpperCase(), w / 2, h / 2 - 40); g.shadowBlur = 0;
      g.font = `${tall ? 22 : 18}px ui-monospace, Menlo, monospace`; g.fillStyle = '#ffcc00'; g.fillText(`${S.runs.length} runs · ${S.steps} steps · built by asking`, w / 2, h / 2 + 8);
      g.font = `700 ${tall ? 30 : 26}px ui-monospace, Menlo, monospace`; g.fillStyle = '#c58aff'; g.fillText('Make yours at voidlux.com', w / 2, h / 2 + 70); g.globalAlpha = 1;
      if (now - R.t > 3400) finishRecording(true); }
    g.setTransform(R.scale * S.zoom, 0, 0, R.scale * S.zoom, 0, 0); }
  $('mkGo').onclick = () => { const shape = (document.querySelector('input[name=mkShape]:checked') || {}).value || 'tall', secs = +((document.querySelector('input[name=mkLen]:checked') || {}).value || 30);
    $('mk').open = false; startRecording(shape, secs); };
  document.addEventListener('visibilitychange', () => { // a hidden tab draws nothing: the recording waits for it rather than filming a frozen picture
    const R = S.rec, hid = document.hidden; document.body.dataset.hidden = hid ? 'yes' : 'no'; $('live').textContent = hid ? 'paused: this tab is hidden' : S.live ? 'live · building' : 'idle';
    if (R && R.recorder.state !== 'inactive') { if (hid && R.recorder.state === 'recording') R.recorder.pause(); else if (!hid && R.recorder.state === 'paused') R.recorder.resume(); } });
  document.body.dataset.hidden = document.hidden ? 'yes' : 'no';
  $('vidClose').onclick = () => { $('vid').hidden = true; $('vidSave').hidden = false; };

  layout(); requestAnimationFrame(frame); poll();
})();
