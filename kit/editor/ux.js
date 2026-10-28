// UX — what a person meets in the forge's editors (sprites, SFX, tracker, config), reported for the hourly retrospective.
// Loaded first by each editor page. It sends, batched and quiet, to /gameapi/ux/<game>:
//   error     a script error or an unhandled rejection on the page
//   fail      a call to the forge's API that answered with an error (status and path; never the body)
//   act       a named action, from the page's own code: UX.act('save'), UX.act('keep', 'svg') …
//   undo      each undo, so heavy undoing (a hard-to-control tool) shows up
//   tab       which tab or mode is being used, and for how long
//   session   one per visit: how long the page was open, how many actions, errors and fails
// Nothing typed by the person, no file contents, no request bodies. Off when the page has no ?game.
const UX = (() => {
  const game = new URLSearchParams(location.search).get('game') || '', page = (location.pathname.match(/\/editor\/([a-z]+)/) || [])[1] || 'page';
  const q = [], t0 = Date.now(), counts = { act: 0, error: 0, fail: 0, undo: 0 }; let tab = '', tabAt = Date.now(), sent = false;
  const push = (ev, d) => { if (!game) return; counts[ev] = (counts[ev] || 0) + 1; q.push(Object.assign({ ev, page, t: Math.round((Date.now() - t0) / 1000) }, d || {})); if (q.length >= 20) flush(); };
  const flush = (beacon) => { if (!game || !q.length) return; const body = JSON.stringify({ page, items: q.splice(0, q.length) });
    try { if (beacon && navigator.sendBeacon) navigator.sendBeacon(`/gameapi/ux/${encodeURIComponent(game)}`, new Blob([body], { type: 'application/json' })); else fetch(`/gameapi/ux/${encodeURIComponent(game)}`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body, keepalive: true }).catch(() => {}); } catch (e) {} };
  window.addEventListener('error', e => push('error', { msg: String(e.message || '').slice(0, 200), where: `${String(e.filename || '').replace(/^.*\//, '')}:${e.lineno || 0}` }));
  window.addEventListener('unhandledrejection', e => push('error', { msg: String((e.reason && e.reason.message) || e.reason || '').slice(0, 200), where: 'promise' }));
  // API failures: the forge's own calls that came back with an error status (the path without its query; never the body)
  const realFetch = window.fetch.bind(window);
  window.fetch = async (input, init) => { const url = typeof input === 'string' ? input : (input && input.url) || ''; const r = await realFetch(input, init);
    try { if (!r.ok && /\/(gameapi|game|forge)\//.test(url) && !/\/gameapi\/ux\//.test(url)) push('fail', { status: r.status, path: url.replace(/^https?:\/\/[^/]+/, '').replace(/\?.*$/, '').replace(/\/[a-z0-9-]+$/, '/<game>').slice(0, 80), method: (init && init.method) || 'GET' }); } catch (e) {} return r; };
  // which buttons get pressed (their label only): the editors' actions, without each page having to report them
  document.addEventListener('click', e => { const b = e.target.closest('button, a.btn'); if (!b) return; const label = (b.textContent || b.title || '').replace(/\s+/g, ' ').trim().slice(0, 40); if (!label) return;
    if (/undo|↶/i.test(label + ' ' + (b.id || '') + ' ' + (b.title || ''))) push('undo', { via: 'button' }); else push('act', { name: label, id: (b.id || '').slice(0, 30) }); }, true);
  // undo from the keyboard, on any editor
  document.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && !e.shiftKey && !e.target.matches('input, textarea')) push('undo', { via: 'key' }); }, true);
  const leave = () => { if (sent) return; sent = true; if (tab) push('tab', { tab, secs: Math.round((Date.now() - tabAt) / 1000) }); push('session', { secs: Math.round((Date.now() - t0) / 1000), acts: counts.act, errors: counts.error, fails: counts.fail, undos: counts.undo }); flush(true); };
  addEventListener('pagehide', leave); document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flush(true); });
  setInterval(() => flush(), 60000);
  return {
    act(name, detail) { push('act', { name: String(name).slice(0, 40), detail: detail === undefined ? undefined : String(detail).slice(0, 60) }); },
    undo(via) { push('undo', { via: via || 'button' }); },
    tab(name) { if (name === tab) return; if (tab) push('tab', { tab, secs: Math.round((Date.now() - tabAt) / 1000) }); tab = name; tabAt = Date.now(); },
  };
})();
