// Forge screenshots and playtests (studio/bin/shot, studio/bin/soak): loaded FIRST in the frame the game page opens for either (/game/shotframe/<slug>),
// before any game script. A browser stops animation frames in a tab that is not the one on screen, and the producer's game
// page is often behind another tab while the builder asks for a picture: the game then never draws, and the shot is blank.
// So while the document is hidden, requestAnimationFrame is served from a message-channel pump (not throttled like timers)
// at about 60 frames a second; while it is visible, the browser's own is used, and a frame waiting on either moves to the
// other when the tab is shown or hidden. Never in a published build.
(() => {
  const native = window.requestAnimationFrame.bind(window), cancelNative = window.cancelAnimationFrame.bind(window), ch = new MessageChannel();
  const queue = new Map(), waiting = new Map(); let next = 1, last = 0, pumping = false; // queue: frames the pump owes (by our id); waiting: our id -> [native id, callback]
  const pump = () => { if (!pumping) { pumping = true; ch.port2.postMessage(0); } };
  const toNative = (id, cb) => waiting.set(id, [native(t => { waiting.delete(id); cb(t); }), cb]);
  ch.port1.onmessage = () => {
    if (!document.hidden) { pumping = false; for (const [id, cb] of [...queue]) { queue.delete(id); toNative(id, cb); } return; }
    const now = performance.now();
    if (now - last >= 16) { last = now; const due = [...queue.values()]; queue.clear(); for (const cb of due) { try { cb(now); } catch (e) { console.error(e); } } }
    if (queue.size) ch.port2.postMessage(0); else pumping = false;
  };
  document.addEventListener('visibilitychange', () => { if (!document.hidden) return; for (const [id, [nid, cb]] of [...waiting]) { cancelNative(nid); waiting.delete(id); queue.set(id, cb); } if (queue.size) pump(); });
  window.requestAnimationFrame = cb => { const id = next++; if (document.hidden) { queue.set(id, cb); pump(); } else toNative(id, cb); return id; };
  window.cancelAnimationFrame = id => { queue.delete(id); const w = waiting.get(id); if (w) { cancelNative(w[0]); waiting.delete(id); } };
  window.__FORGE_SHOT = true;
})();
