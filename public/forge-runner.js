// The forge's playtest and screenshot runner: this open page plays what an agent asked for, in a small window (studio/PRODUCER-BROWSER.md).
// Used by the game page and the Studio, which each poll /game/events and hand over what comes back:
//   ForgeRunner.init(slug, { note(text), done(report) });   ForgeRunner.take(eventsAnswer);   ForgeRunner.busy()
const ForgeRunner = (() => {
  let slug = '', note = () => {}, done = () => {}, soakRunning = false, shotRunning = false;
// a screenshot the builder asked for: the game in a small frame with the asked query, given its seconds, its canvas captured
async function runBrowserShot(req) {
  shotRunning = true; let win = null;
  try {
    const c = await Forge.api(`/game/shotclaim/${slug}`, { id: req.id }); if (!c.claimed) return;
    win = document.createElement('div'); win.className = 'vl-soak-win'; win.innerHTML = `<div class="vl-soak-title"><span>📷 screenshot for the builder · ${req.secs} s${req.query ? ' · ?' + Forge.esc(req.query) : ''}</span><button type="button">–</button></div>`; win.querySelector('button').onclick = () => win.classList.toggle('min');
    const f = document.createElement('iframe'); f.className = 'vl-soak-frame'; const q = (req.query || '').replace(/^\?/, ''); f.src = `/game/shotframe/${slug}?${q}${q ? '&' : ''}seed=7&r=${Date.now()}`; /* the game with kit-shot.js first: it draws even while this tab is hidden */ win.appendChild(f); document.body.appendChild(win);
    const errs = []; f.addEventListener('load', () => { try { f.contentWindow.addEventListener('error', e => errs.push(String(e.message))); } catch (e) {} });
    await new Promise(r => setTimeout(r, Math.max(1, Math.min(120, +req.secs || 3)) * 1000));
    let png = '', w = 0, h = 0; try { const cv = f.contentWindow.document.querySelector('canvas'); w = cv.width; h = cv.height; png = cv.toDataURL('image/png'); } catch (e) { errs.push('could not read the canvas: ' + e.message); }
    if (!png) { const cv = document.createElement('canvas'); cv.width = 320; cv.height = 180; const g = cv.getContext('2d'); g.fillStyle = '#000'; g.fillRect(0, 0, 320, 180); g.fillStyle = '#f66'; g.font = '14px monospace'; g.fillText('no canvas to capture', 20, 90); png = cv.toDataURL('image/png'); }
    await Forge.api(`/game/shotdone/${slug}`, { id: req.id, png, w, h, errors: errs });
    win.querySelector('.vl-soak-title span').textContent = '📷 sent to the builder'; win.classList.add('ok');
  } catch (e) { note('shot: ' + e.message); } finally { shotRunning = false; if (win) setTimeout(() => win.remove(), 2500); }
}
async function runBrowserSoak(req) {
  soakRunning = true;
  try {
    const c = await Forge.api(`/game/soakclaim/${slug}`, { id: req.id }); if (!c.claimed) return;
    note(`playtest ${req.id} running in this browser (${req.secs} s at ${req.fast}x)…`);
    const win = document.createElement('div'); win.className = 'vl-soak-win'; win.innerHTML = `<div class="vl-soak-title"><span>▶ playtest · ${req.secs} game-seconds at ${req.fast}x · the bots are playing</span><button type="button" title="hide the window (the playtest keeps running)">–</button></div>`;
    win.querySelector('button').onclick = () => win.classList.toggle('min');
    const f = document.createElement('iframe'); f.className = 'vl-soak-frame'; f.src = `/game/shotframe/${slug}?soak=${req.secs}&fast=${req.fast}&seed=7&r=${Date.now()}`; /* with kit-shot.js first: the game draws even while this tab is hidden, whatever the game's own pumps do */ win.appendChild(f); document.body.appendChild(win);
    const budget = Date.now() + (req.secs / Math.max(1, req.fast)) * 1000 * 2.5 + 20000; let rep = null, errs = [], hiddenTicks = 0, ticks = 0;
    // the frame's own window exists only once it has loaded: listen for its errors then (a listener set before load is lost)
    f.addEventListener('load', () => { try { const w = f.contentWindow; w.addEventListener('error', e => errs.push(String(e.message || e.error) + (e.filename ? ' @' + e.filename.replace(/^.*\//, '') + ':' + e.lineno : ''))); w.addEventListener('unhandledrejection', e => errs.push('unhandled: ' + String(e.reason && e.reason.message || e.reason))); } catch (e) {} });
    while (Date.now() < budget) { await new Promise(r => setTimeout(r, 500)); ticks++; if (document.hidden) hiddenTicks++; try { if (f.contentWindow && f.contentWindow.__SOAK) { rep = f.contentWindow.__SOAK; break; } } catch (e) { break; } }
    if (!rep) { // say why, from what the frame shows: errors, how far the game got, whether this tab was in the background
      let prog = null, game = 'unknown'; try { const w = f.contentWindow; prog = w.__SOAK_PROGRESS || (w.Test ? { t: +(w.Test.t || 0).toFixed(1), scenes: w.Test.scenes || {} } : null); game = (w.G || w.Game) ? 'loaded' : 'did not load (no game object)'; } catch (e) {}
      const bg = ticks ? Math.round(hiddenTicks / ticks * 100) : 0, why = errs.length ? 'the game raised errors: ' + errs.slice(0, 3).join(' | ') : game.startsWith('did not') ? 'the game ' + game : (!prog || !prog.t) ? (bg > 50 ? `the game never advanced: this forge tab was in the background ${bg}% of the time, and a background tab gets no animation frames` : 'the game loaded but never advanced (no game time passed)') : `the game reached ${prog.t} of ${req.secs} game-seconds before the time ran out` + (bg > 50 ? ` (this tab was in the background ${bg}% of the time)` : '');
      rep = { status: errs.length ? 'error' : (bg > 50 && (!prog || !prog.t) ? 'hidden-tab' : 'timeout'), errors: [{ text: 'the playtest did not finish in this browser: ' + why }].concat(errs.slice(0, 8).map(t => ({ text: t }))), scenes: (prog && prog.scenes) || {}, seconds: prog ? prog.t : 0, background: bg }; }
    win.querySelector('.vl-soak-title span').textContent = rep.ok ? '✓ playtest ok · ' + (rep.fps || '?') + ' fps · score ' + (rep.score ?? '') : '✗ playtest ' + (rep.status || 'failed') + ' · ' + ((rep.errors || []).length) + ' error(s)';
    win.classList.add(rep.ok ? 'ok' : 'bad'); setTimeout(() => win.remove(), 4000);
    await Forge.api(`/game/soakdone/${slug}`, { id: req.id, report: rep });
    done(rep);
    note(''); 
  } catch (e) { note('playtest here failed: ' + e.message); }
  finally { soakRunning = false; }
}
  return {
    init(s, hooks = {}) { slug = s; note = hooks.note || note; done = hooks.done || done; },
    take(r) { if (r.soakRequest && !soakRunning) runBrowserSoak(r.soakRequest); if (r.shotRequest && !shotRunning) runBrowserShot(r.shotRequest); },
    busy: () => soakRunning,
  };
})();
