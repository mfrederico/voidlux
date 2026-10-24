// Overrides for js/config.js, edited in the config editor (http://localhost:8765/editor/config/ via tools/devserver.py).
// Each entry is a CFG path and its value; only what differs from js/config.js is here. The editor rewrites this file.
const TUNING = {};
// applied once, right after js/config.js loads: each path is written onto CFG (a path that no longer exists, or a value
// of the wrong kind, is skipped)
(function applyTuning() { for (const [path, v] of Object.entries(TUNING)) { const keys = path.split('.'), k = keys.pop(); let o = CFG; for (const q of keys) o = o && o[q]; if (o && typeof o === 'object' && typeof o[k] === typeof v) o[k] = v; } })();
