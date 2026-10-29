#!/usr/bin/env node
// glb-strip.mjs — a turntable strip of a binary glTF model, drawn in software: no browser, no GPU (the server has neither).
//   node studio/bin/glb-strip.mjs <in.glb> <out.png> [frames=16] [px=250]    (from a game folder: ../../studio/bin/glb-strip.mjs)
// Writes one PNG, frames × px wide and px high, transparent background: the model turning once, seen as the sprite page's
// viewer sees it (35° lens, from a little above, the same orbit). Handles what the forge's models are: triangles with vertex
// colours and/or a material's base colour, through the node tree's transforms. A texture is not drawn (base colour only),
// and that is said on stderr. Exit 1 with the reason on anything it cannot read.
import fs from 'node:fs';
import zlib from 'node:zlib';

const [, , IN, OUT, FR = '16', PXA = '250'] = process.argv;
if (!IN || !OUT) { console.error('usage: glb-strip.mjs <in.glb> <out.png> [frames] [px]'); process.exit(1); }
const N = Math.max(1, Math.min(64, +FR)), PX = Math.max(16, Math.min(1024, +PXA)), SS = 2, W = PX * SS;
const die = m => { console.error('glb-strip: ' + m); process.exit(1); };

// ---- read the glb ----
const buf = fs.readFileSync(IN); if (buf.toString('latin1', 0, 4) !== 'glTF') die(`${IN} is not binary glTF`);
let off = 12, json = null, bin = null;
while (off < buf.length) { const len = buf.readUInt32LE(off), type = buf.readUInt32LE(off + 4); const chunk = buf.subarray(off + 8, off + 8 + len); if (type === 0x4E4F534A) json = JSON.parse(chunk.toString('utf8')); else if (type === 0x004E4942) bin = chunk; off += 8 + len; }
if (!json) die('no JSON chunk');
const CT = { 5120: [Int8Array, 127], 5121: [Uint8Array, 255], 5122: [Int16Array, 32767], 5123: [Uint16Array, 65535], 5125: [Uint32Array, 0], 5126: [Float32Array, 0] }, NC = { SCALAR: 1, VEC2: 2, VEC3: 3, VEC4: 4, MAT4: 16 };
function acc(i) { // an accessor as an array of numbers (normalised integers scaled to 0..1)
  const a = json.accessors[i], [T, max] = CT[a.componentType], n = NC[a.type], bv = json.bufferViews[a.bufferView]; if (!bv || !bin) die('an accessor without data (sparse or external buffers are not read)');
  const stride = bv.byteStride || n * T.BYTES_PER_ELEMENT, base = (bv.byteOffset || 0) + (a.byteOffset || 0), out = new Float32Array(a.count * n), dv = new DataView(bin.buffer, bin.byteOffset);
  const rd = { 5120: (o) => dv.getInt8(o), 5121: (o) => dv.getUint8(o), 5122: (o) => dv.getInt16(o, true), 5123: (o) => dv.getUint16(o, true), 5125: (o) => dv.getUint32(o, true), 5126: (o) => dv.getFloat32(o, true) }[a.componentType];
  for (let k = 0; k < a.count; k++) for (let c = 0; c < n; c++) { let v = rd(base + k * stride + c * T.BYTES_PER_ELEMENT); if (a.normalized && max) v = Math.max(v / max, -1); out[k * n + c] = v; }
  return { v: out, n, count: a.count };
}
// ---- matrices (column-major, as glTF) ----
const mul = (a, b) => { const o = new Float64Array(16); for (let i = 0; i < 4; i++) for (let j = 0; j < 4; j++) { let s = 0; for (let k = 0; k < 4; k++) s += a[k * 4 + j] * b[i * 4 + k]; o[i * 4 + j] = s; } return o; };
const I4 = () => { const m = new Float64Array(16); m[0] = m[5] = m[10] = m[15] = 1; return m; };
function local(nd) {
  if (nd.matrix) return Float64Array.from(nd.matrix);
  const [x, y, z, w] = nd.rotation || [0, 0, 0, 1], [sx, sy, sz] = nd.scale || [1, 1, 1], [tx, ty, tz] = nd.translation || [0, 0, 0];
  return Float64Array.from([(1 - 2 * (y * y + z * z)) * sx, 2 * (x * y + z * w) * sx, 2 * (x * z - y * w) * sx, 0, 2 * (x * y - z * w) * sy, (1 - 2 * (x * x + z * z)) * sy, 2 * (y * z + x * w) * sy, 0, 2 * (x * z + y * w) * sz, 2 * (y * z - x * w) * sz, (1 - 2 * (x * x + y * y)) * sz, 0, tx, ty, tz, 1]);
}
// ---- every triangle, in world space, with its three colours ----
const P = [], C = []; let textured = false;
function walk(i, parent) {
  const nd = json.nodes[i], m = mul(parent, local(nd));
  if (nd.mesh != null) for (const pr of json.meshes[nd.mesh].primitives) {
    if ((pr.mode ?? 4) !== 4) continue;
    const pos = acc(pr.attributes.POSITION), col = pr.attributes.COLOR_0 != null ? acc(pr.attributes.COLOR_0) : null, mat = json.materials?.[pr.material]?.pbrMetallicRoughness || {};
    if (mat.baseColorTexture) textured = true; const bc = mat.baseColorFactor || [1, 1, 1, 1];
    const idx = pr.indices != null ? acc(pr.indices).v : Uint32Array.from({ length: pos.count }, (_, k) => k);
    for (let t = 0; t < idx.length; t++) { const k = idx[t];
      const x = pos.v[k * 3], y = pos.v[k * 3 + 1], z = pos.v[k * 3 + 2];
      P.push(m[0] * x + m[4] * y + m[8] * z + m[12], m[1] * x + m[5] * y + m[9] * z + m[13], m[2] * x + m[6] * y + m[10] * z + m[14]);
      const r = col ? col.v[k * col.n] : 1, g = col ? col.v[k * col.n + 1] : 1, b = col ? col.v[k * col.n + 2] : 1; C.push(r * bc[0], g * bc[1], b * bc[2]); }
    if (idx.length % 3) die('a primitive whose index count is not a multiple of 3');
  }
  for (const ch of nd.children || []) walk(ch, m);
}
const sc = json.scenes?.[json.scene ?? 0]; if (!sc) die('no scene'); for (const r of sc.nodes || []) walk(r, I4());
if (!P.length) die('no triangles to draw');
if (textured) console.error('glb-strip: the model has a texture; it is drawn in its base colour only');
// ---- the camera: as the sprite page's viewer (35° vertical, orbit radius 1.1 × the box's diagonal, raised 0.6 ×) ----
let lo = [Infinity, Infinity, Infinity], hi = [-Infinity, -Infinity, -Infinity];
for (let i = 0; i < P.length; i += 3) for (let c = 0; c < 3; c++) { lo[c] = Math.min(lo[c], P[i + c]); hi[c] = Math.max(hi[c], P[i + c]); }
const ctr = [0, 1, 2].map(c => (lo[c] + hi[c]) / 2), size = Math.hypot(hi[0] - lo[0], hi[1] - lo[1], hi[2] - lo[2]) || 1, F = 1 / Math.tan(35 * Math.PI / 360);
const lin2srgb = v => v <= 0.0031308 ? 12.92 * v : 1.055 * Math.pow(v, 1 / 2.4) - 0.055;
const LD = (() => { const v = [3, 5, 4], l = Math.hypot(...v); return v.map(x => x / l); })();
const strip = Buffer.alloc(PX * N * PX * 4);
const zb = new Float32Array(W * W), cb = new Float32Array(W * W * 4);
for (let f = 0; f < N; f++) {
  zb.fill(Infinity); cb.fill(0);
  const a = 0.7 + f * Math.PI * 2 / N, eye = [ctr[0] + Math.cos(a) * size * 1.1, ctr[1] + size * 0.6, ctr[2] + Math.sin(a) * size * 1.1];
  const fw = ctr.map((c, i) => c - eye[i]), fl = Math.hypot(...fw); for (let i = 0; i < 3; i++) fw[i] /= fl;
  let rt = [fw[1] * 0 - fw[2] * 1, fw[2] * 0 - fw[0] * 0, fw[0] * 1 - fw[1] * 0]; const rl = Math.hypot(...rt) || 1; rt = rt.map(x => x / rl); // forward × up
  const up = [rt[1] * fw[2] - rt[2] * fw[1], rt[2] * fw[0] - rt[0] * fw[2], rt[0] * fw[1] - rt[1] * fw[0]];
  const proj = (x, y, z) => { const dx = x - eye[0], dy = y - eye[1], dz = z - eye[2], vz = dx * fw[0] + dy * fw[1] + dz * fw[2]; return [W / 2 + (dx * rt[0] + dy * rt[1] + dz * rt[2]) / vz * F * W / 2, W / 2 - (dx * up[0] + dy * up[1] + dz * up[2]) / vz * F * W / 2, vz]; };
  for (let t = 0; t < P.length; t += 9) {
    const A = proj(P[t], P[t + 1], P[t + 2]), B = proj(P[t + 3], P[t + 4], P[t + 5]), D = proj(P[t + 6], P[t + 7], P[t + 8]); if (A[2] <= 0 || B[2] <= 0 || D[2] <= 0) continue;
    // flat shading from the face normal, lit from both sides: a hemisphere (sky above, a cool ground below) and one sun
    const e1 = [P[t + 3] - P[t], P[t + 4] - P[t + 1], P[t + 5] - P[t + 2]], e2 = [P[t + 6] - P[t], P[t + 7] - P[t + 1], P[t + 8] - P[t + 2]];
    let n = [e1[1] * e2[2] - e1[2] * e2[1], e1[2] * e2[0] - e1[0] * e2[2], e1[0] * e2[1] - e1[1] * e2[0]]; const nl = Math.hypot(...n); if (!nl) continue; n = n.map(x => x / nl);
    if (n[0] * (eye[0] - P[t]) + n[1] * (eye[1] - P[t + 1]) + n[2] * (eye[2] - P[t + 2]) < 0) n = n.map(x => -x);
    const hemi = 0.5 + 0.5 * n[1], light = 0.62 * (hemi + (1 - hemi) * 0.45) + 0.58 * Math.max(0, n[0] * LD[0] + n[1] * LD[1] + n[2] * LD[2]);
    const cs = [0, 1, 2].map(v => [C[t + v * 3], C[t + v * 3 + 1], C[t + v * 3 + 2]]); // C is laid out as P: three numbers a vertex
    const area = (B[0] - A[0]) * (D[1] - A[1]) - (B[1] - A[1]) * (D[0] - A[0]); if (Math.abs(area) < 1e-9) continue;
    const x0 = Math.max(0, Math.floor(Math.min(A[0], B[0], D[0]))), x1 = Math.min(W - 1, Math.ceil(Math.max(A[0], B[0], D[0]))), y0 = Math.max(0, Math.floor(Math.min(A[1], B[1], D[1]))), y1 = Math.min(W - 1, Math.ceil(Math.max(A[1], B[1], D[1])));
    for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) {
      const px = x + 0.5, py = y + 0.5;
      const w0 = ((B[0] - px) * (D[1] - py) - (B[1] - py) * (D[0] - px)) / area, w1 = ((D[0] - px) * (A[1] - py) - (D[1] - py) * (A[0] - px)) / area, w2 = 1 - w0 - w1;
      if (w0 < 0 || w1 < 0 || w2 < 0) continue;
      const z = w0 * A[2] + w1 * B[2] + w2 * D[2], p = y * W + x; if (z >= zb[p]) continue; zb[p] = z;
      for (let c = 0; c < 3; c++) cb[p * 4 + c] = lin2srgb(Math.min(1, (w0 * cs[0][c] + w1 * cs[1][c] + w2 * cs[2][c]) * light)); cb[p * 4 + 3] = 1;
    }
  }
  for (let y = 0; y < PX; y++) for (let x = 0; x < PX; x++) { // SS×SS down to one pixel, colour weighted by coverage
    let r = 0, g = 0, b = 0, al = 0; for (let j = 0; j < SS; j++) for (let i = 0; i < SS; i++) { const p = ((y * SS + j) * W + x * SS + i) * 4, w = cb[p + 3]; r += cb[p] * w; g += cb[p + 1] * w; b += cb[p + 2] * w; al += w; }
    const o = (y * PX * N + f * PX + x) * 4; if (al) { strip[o] = Math.round(r / al * 255); strip[o + 1] = Math.round(g / al * 255); strip[o + 2] = Math.round(b / al * 255); } strip[o + 3] = Math.round(al / (SS * SS) * 255);
  }
}
// ---- PNG ----
const chunk = (type, data) => { const t = Buffer.from(type, 'latin1'), len = Buffer.alloc(4), crc = Buffer.alloc(4); len.writeUInt32BE(data.length); crc.writeUInt32BE(zlib.crc32(Buffer.concat([t, data])) >>> 0); return Buffer.concat([len, t, data, crc]); };
const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(PX * N, 0); ihdr.writeUInt32BE(PX, 4); ihdr[8] = 8; ihdr[9] = 6;
const rows = Buffer.alloc((PX * N * 4 + 1) * PX); for (let y = 0; y < PX; y++) strip.copy(rows, y * (PX * N * 4 + 1) + 1, y * PX * N * 4, (y + 1) * PX * N * 4);
fs.writeFileSync(OUT, Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(rows, { level: 6 })), chunk('IEND', Buffer.alloc(0))]));
