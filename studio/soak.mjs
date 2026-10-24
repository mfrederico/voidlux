// Headless soak: open a game with ?soak=N&fast=K in Playwright's Chromium, let the scenes' bots play, collect every page
// error and console error, wait for the report (window.__SOAK), screenshot, print the report as JSON.
// usage: node soak.mjs <url> <secs> <fast> [screenshot.png]      (the studio calls this; `studio/bin/soak` from a game dir)
import { createRequire } from 'node:module';
const require = createRequire(process.env.PLAYWRIGHT_ROOT ? process.env.PLAYWRIGHT_ROOT + '/' : '/home/ubuntu/');
const { chromium } = require('playwright');
const [url, secs = '60', fast = '4', shot] = process.argv.slice(2);
if (!url) { console.error('usage: node soak.mjs <game url> [secs] [fast] [screenshot]'); process.exit(2); }
const budgetMs = Math.min(600000, (+secs / Math.max(1, +fast)) * 1000 * 2.5 + 20000); // wall time: the game-seconds at speed, with slack
const errors = [], logs = [];
// the system Chrome when config names one (CHROME env): no Playwright browser download needed
// A producer's own Chrome, when one is reachable over the DevTools protocol (CDP_URL; a laptop's
//   google-chrome --remote-debugging-port=9222 --user-data-dir=/tmp/chrome-debug   tunnelled with   ssh -N -R 9222:localhost:9222 <server>
// ), runs the playtest instead of a Chrome on this server. Otherwise headless here; inside the jail (JAILED=1) Chrome cannot make
// its own sandbox (no user namespaces there), bubblewrap is the sandbox.
let browser = null, remote = '';
const cdp = process.env.CDP_URL || '';
if (cdp) { try { const ctrl = new AbortController(); const t = setTimeout(() => ctrl.abort(), 1500); const v = await (await fetch(cdp.replace(/\/$/, '') + '/json/version', { signal: ctrl.signal })).json(); clearTimeout(t); browser = await chromium.connectOverCDP(cdp); remote = v.Browser || 'remote chrome'; } catch (e) { browser = null; } }
if (!browser) browser = await chromium.launch({ executablePath: process.env.CHROME || undefined, args: ['--autoplay-policy=no-user-gesture-required', '--use-gl=swiftshader', '--mute-audio'].concat(process.env.JAILED ? ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'] : []) });
const context = remote ? (browser.contexts()[0] || await browser.newContext()) : await browser.newContext({ viewport: { width: 1280, height: 720 } });
const page = await context.newPage(); if (remote) { try { await page.setViewportSize({ width: 1280, height: 720 }); } catch (e) {} }
page.on('pageerror', e => errors.push({ text: String(e && e.stack || e), kind: 'pageerror' }));
page.on('console', m => { const loc = (m.location() || {}).url || ''; if (m.type() === 'error') { if (/favicon|webmanifest/.test(loc + m.text())) return; errors.push({ text: m.text() + (loc ? ' @ ' + loc : ''), kind: 'console' }); } else if (m.type() === 'warning' || /SOAK/.test(m.text())) logs.push(m.text().slice(0, 500)); });
page.on('requestfailed', r => errors.push({ text: 'request failed: ' + r.url() + ' ' + (r.failure() || {}).errorText, kind: 'network' }));
const u = new URL(url); u.searchParams.set('soak', secs); u.searchParams.set('fast', fast); if (!u.searchParams.has('seed')) u.searchParams.set('seed', '7');
const t0 = Date.now(); let report = null, status = 'ok';
try {
  const resp = await page.goto(u.toString(), { waitUntil: 'load', timeout: 30000 });
  if (!resp || resp.status() >= 400) throw new Error('page load status ' + (resp && resp.status()));
  await page.mouse.click(640, 360); // a gesture, so audio and the first scene behave as for a player
  await page.waitForFunction(() => window.__SOAK, null, { timeout: budgetMs, polling: 500 });
  report = await page.evaluate(() => window.__SOAK);
} catch (e) { status = /Timeout/i.test(String(e)) ? 'timeout' : 'crashed'; errors.push({ text: String(e && e.message || e), kind: status }); try { report = await page.evaluate(() => typeof Test !== 'undefined' ? Test.report() : null); } catch (e2) {} }
if (shot) { try { await page.screenshot({ path: shot }); } catch (e) {} }
if (remote) { try { await page.close(); } catch (e) {} try { await browser.close(); } catch (e) {} } else await browser.close(); // a remote Chrome only loses its tab
const out = Object.assign({ status, runner: remote ? 'remote: ' + remote : 'server', url: u.toString(), wallMs: Date.now() - t0, browserErrors: errors, notes: logs.slice(0, 20) }, report || {});
out.ok = status === 'ok' && !!report && report.ok && !errors.some(e => e.kind !== 'network' || !/favicon|webmanifest/.test(e.text));
console.log(JSON.stringify(out));
process.exit(out.ok ? 0 : 1);
