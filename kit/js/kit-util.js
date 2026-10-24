// VoidLux kit: small helpers every game uses. Classic script, no modules: load this first.
//   TAU, U (random, clamp, lerp, distances, angles, damp), Pool (object pools), Rng (seeded random for replays and tests),
//   Timer (cooldowns). Nothing here touches the DOM or the game: pure functions and tiny objects.
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
  angLerp(a, b, step) { let d = b - a; while (d > Math.PI) d -= TAU; while (d < -Math.PI) d += TAU; if (Math.abs(d) <= step) return b; return a + Math.sign(d) * step; },
  shuffle(arr) { for (let i = arr.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [arr[i], arr[j]] = [arr[j], arr[i]]; } return arr; },
  // exponential smoothing factor for frame-rate independent lerps: x += (target - x) * U.damp(rate, dt)
  damp: (rate, dt) => 1 - Math.exp(-rate * dt),
  // circle overlap and point-in-rect, the two tests most games need
  hit: (ax, ay, ar, bx, by, br) => (bx - ax) * (bx - ax) + (by - ay) * (by - ay) < (ar + br) * (ar + br),
  inRect: (px, py, x, y, w, h) => px >= x && py >= y && px < x + w && py < y + h,
  // 'rgba(...)' from '#rrggbb' and an alpha
  tint(hex, a) { const n = parseInt(hex.slice(1), 16); return `rgba(${n >> 16},${(n >> 8) & 255},${n & 255},${a})`; },
  pad: (n, w) => String(n).padStart(w, '0'),
};
// A seeded random (mulberry32): a soak run or a replay can be repeated exactly. Rng.seed(n) then Rng.next() in 0..1.
const Rng = {
  s: 1, seed(n) { this.s = (n >>> 0) || 1; return this; },
  next() { let t = (this.s += 0x6D2B79F5); t = Math.imul(t ^ (t >>> 15), t | 1); t ^= t + Math.imul(t ^ (t >>> 7), t | 61); return ((t ^ (t >>> 14)) >>> 0) / 4294967296; },
  rand(a, b) { return a + this.next() * (b - a); }, randi(a, b) { return Math.floor(a + this.next() * (b - a + 1)); }, pick(arr) { return arr[Math.floor(this.next() * arr.length)]; },
};
// A pool of plain objects: take() gives one (reused when it can), free(o) hands it back. Bullets, particles and damage
// numbers go through pools so a long fight does not churn the garbage collector into frame hitches.
class Pool {
  constructor(make, reset) { this.make = make; this.reset = reset; this.free_ = []; this.live = []; }
  take() { const o = this.free_.pop() || this.make(); if (this.reset) this.reset(o); this.live.push(o); return o; }
  free(o) { const i = this.live.indexOf(o); if (i >= 0) { this.live[i] = this.live[this.live.length - 1]; this.live.pop(); } this.free_.push(o); }
  // drop every live object for which gone(o) is true, in one pass (swap-remove, so order is not kept)
  sweep(gone) { const L = this.live; for (let i = L.length - 1; i >= 0; i--) if (gone(L[i])) { this.free_.push(L[i]); L[i] = L[L.length - 1]; L.pop(); } }
  clear() { while (this.live.length) this.free_.push(this.live.pop()); }
}
