#!/usr/bin/env node
// What a screenshot shows, in light: how much of it is near-black, how much is one flat colour, how bright it is overall. The shot tool
// prints this beside the picture, so a builder reading a very dark frame knows it is what the game draws (a lamp off, a fade, a night
// scene), not a capture that failed, and can take one that shows something (a query that lights it, a later moment, another scene).
// usage: node shot-look.mjs <file.png>      prints one JSON line: { dark, bright, flat, mean, verdict } (dark and flat in %, bright in % of pixels)
import fs from 'fs'; import zlib from 'zlib';
const f = process.argv[2]; const b = fs.readFileSync(f);
if (b.readUInt32BE(0) !== 0x89504e47) { console.log(JSON.stringify({ verdict: 'not a PNG file' })); process.exit(1); }
let p = 8, w = 0, h = 0, depth = 0, type = 0, inter = 0; const idat = [];
while (p < b.length) { const n = b.readUInt32BE(p), t = b.toString('ascii', p + 4, p + 8), d = b.subarray(p + 8, p + 8 + n);
  if (t === 'IHDR') { w = d.readUInt32BE(0); h = d.readUInt32BE(4); depth = d[8]; type = d[9]; inter = d[12]; } else if (t === 'IDAT') idat.push(d); else if (t === 'IEND') break; p += 12 + n; }
const ch = { 0: 1, 2: 3, 4: 2, 6: 4 }[type];
if (depth !== 8 || !ch || inter) { console.log(JSON.stringify({ verdict: `a PNG this check does not read (depth ${depth}, type ${type}${inter ? ', interlaced' : ''})` })); process.exit(0); }
const raw = zlib.inflateSync(Buffer.concat(idat)), stride = w * ch, px = Buffer.alloc(stride * h);
for (let y = 0, i = 0; y < h; y++) { const ft = raw[i++], row = y * stride, up = row - stride;
  for (let x = 0; x < stride; x++, i++) { const a = x >= ch ? px[row + x - ch] : 0, u = y ? px[up + x] : 0, c = y && x >= ch ? px[up + x - ch] : 0; let v = raw[i];
    if (ft === 1) v += a; else if (ft === 2) v += u; else if (ft === 3) v += (a + u) >> 1; else if (ft === 4) { const q = a + u - c, pa = Math.abs(q - a), pb = Math.abs(q - u), pc = Math.abs(q - c); v += pa <= pb && pa <= pc ? a : pb <= pc ? u : c; }
    px[row + x] = v & 255; } }
let dark = 0, bright = 0, sum = 0, n = 0; const seen = new Map(); const step = Math.max(1, Math.floor(w * h / 60000));
for (let k = 0; k < w * h; k += step) { const o = k * ch, r = px[o], g = ch >= 3 ? px[o + 1] : r, bl = ch >= 3 ? px[o + 2] : r, l = 0.2126 * r + 0.7152 * g + 0.0722 * bl;
  n++; sum += l; if (l < 18) dark++; if (l > 110) bright++; const key = (r >> 3) << 10 | (g >> 3) << 5 | (bl >> 3); seen.set(key, (seen.get(key) || 0) + 1); }
const top = Math.max(...seen.values()), out = { dark: Math.round(dark / n * 100), bright: Math.round(bright / n * 1000) / 10, flat: Math.round(top / n * 1000) / 10, mean: Math.round(sum / n) };
out.verdict = out.flat >= 99.5 ? 'one flat colour: nothing is drawn, or the page had not drawn yet (take it a few seconds later)'
  : out.dark >= 85 && out.bright < 1.5 ? `very dark: ${out.dark}% of it is near-black. That is what the game draws at that moment (a lamp off, a fade, night), not a failed capture; to see more, take it where the scene is lit (a query or a scene that lights it, or a later moment), and read the HUD that does show`
  : out.dark >= 60 && out.bright < 1.5 ? `dark: ${out.dark}% near-black; look closely before judging what is there` : out.dark >= 60 ? `a dark background with bright things on it (${out.bright}% bright): readable` : 'lit: things are visible';
console.log(JSON.stringify(out));
