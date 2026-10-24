<?php /* One game: play, build (the agent stream), test (soaks), the three editors, the design doc, history, ship.
         The page polls /game/events and /game/status; every action is a JSON POST with the CSRF token. */
$slug = $game['slug']; $e = fn($s) => htmlspecialchars((string) $s);
?>
<meta name="csrf-token" content="<?= $e(csrf_token()) ?>">
<div class="vl-gamebar"><b><?= $e($game['title']) ?></b><span id="metaLine"><?= $e($game['template']) ?> · <?= (int) $game['checkpoints'] ?> checkpoints · <?= $e($game['lastCommit']) ?></span><span class="vl-pill" id="statusPill"><?= $e($game['status']) ?></span><span id="agentStatus" class="vl-pill">agent idle</span>
  <a class="btn btn-sm btn-outline-secondary ms-auto" href="/games/<?= $e($slug) ?>/" target="_blank">Play in a tab</a><?php if ($game['publishedUrl']): ?><a class="btn btn-sm btn-outline-primary" href="<?= $e($game['publishedUrl']) ?>" target="_blank">Live</a><?php endif; ?></div>
<div class="vl-tabs" id="tabs">
  <button data-tab="play" class="on">Play</button><button data-tab="build">Build</button><button data-tab="test">Test</button><button data-tab="sfx">SFX</button><button data-tab="tracker">Tracker</button><button data-tab="config">Config</button><button data-tab="design">Design</button><button data-tab="history">History</button><button data-tab="ship">Ship</button>
</div>

<section class="vl-pane on" id="pane-play">
  <div class="vl-toolbar"><button class="btn btn-sm btn-outline-secondary" onclick="reloadPlay()">Reload</button><button class="btn btn-sm btn-outline-secondary" onclick="playWith('fps=1')">Profiler</button><button class="btn btn-sm btn-outline-secondary" onclick="playWith('bot=1')">Autopilot</button><button class="btn btn-sm btn-primary" onclick="noteMode()" id="noteBtn">Note mode (Alt+P)</button><span>The game as a player sees it, straight from its folder. Note mode pauses it: click anything, say what should change.</span></div>
  <iframe class="vl-frame" id="playFrame" allow="autoplay; fullscreen"></iframe>
  <div class="vl-notes-strip" id="notesStrip"></div>
</section>

<section class="vl-pane" id="pane-build">
  <div class="vl-build">
    <div>
      <div class="vl-toolbar">
        <button class="btn btn-sm btn-primary" onclick="startAgent('pipeline')" id="btnPipeline">Run the pipeline</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="startAgent('review')">Review only</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="startAgent('fix')">Soak &amp; fix</button>
        <button class="btn btn-sm btn-danger" onclick="stopAgent()">Stop</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="resetSession()" title="the next run starts a fresh Claude session">Fresh session</button>
        <span id="streamNote"><?= $e($agentNote) ?></span>
      </div>
      <div class="vl-stream" id="stream"></div>
      <form class="vl-compose" id="chatForm">
        <textarea id="chatText" data-ph="Tell the builder what to do: 'make the enemies spawn in rings', 'add a boss every fifth wave', 'the ship feels sluggish, tighten it'. Each message is one agent turn in the game's folder, checkpointed after." placeholder="Tell the builder what to do: 'make the enemies spawn in rings', 'add a boss every fifth wave', 'the ship feels sluggish, tighten it'. Each message is one agent turn in the game's folder, checkpointed after."></textarea>
        <div class="btns"><button type="submit" class="btn btn-primary" id="chatBtn">Send</button><button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('chatText').value=''">Clear</button></div>
      </form>
    </div>
    <aside class="vl-side">
      <div class="vl-card"><h3>Pipeline</h3><div id="stage" class="vl-kv">idle</div></div>
      <div class="vl-card"><h3>Producer's notes <span id="notesCount"></span></h3><div id="notesBox" class="vl-notes-list"></div><div class="vl-toolbar mt-2"><input id="notesIntro" placeholder="a word for the builder (optional)" style="flex:1;min-width:120px"><button class="btn btn-sm btn-primary" onclick="sendNotes()" id="sendNotesBtn">Send checked to build</button></div></div>
      <div class="vl-card"><h3>Tasks</h3><ul class="vl-tasks" id="tasks"></ul></div>
      <div class="vl-card"><h3>Session</h3><div class="vl-kv" id="session"></div><div class="vl-kv mt-2 small">Agents sign in on <a href="/agents">AI agents</a> when [forge] agent_login is app; today: <?= $e($agentNote) ?>.</div></div>
    </aside>
  </div>
</section>

<section class="vl-pane" id="pane-test">
  <div class="vl-toolbar">
    <label>game-seconds <input type="number" id="soakSecs" value="60" min="5" max="1800" style="width:80px"></label>
    <label>speed <input type="number" id="soakFast" value="4" min="1" max="30" style="width:60px"></label>
    <button class="btn btn-sm btn-primary" onclick="runSoak()" id="soakBtn">Run a headless soak</button>
    <span>Playwright opens the game with <code>?soak=N&amp;fast=K</code>; the scenes' bots play; every exception, console error and failed check is reported.</span>
  </div>
  <table class="vl-table"><thead><tr><th>when</th><th>result</th><th>played</th><th>fps</th><th>errors</th><th>scenes</th><th>score</th><th></th></tr></thead><tbody id="soaks"></tbody></table>
  <div id="soakDetail"></div>
</section>

<section class="vl-pane" id="pane-sfx"><div class="vl-toolbar">Every <code>SFX.play('key')</code> in the game's code is a slot here. Design it as layers; Save writes js/sfx.js. <a href="/editor/sfx/?game=<?= $e($slug) ?>" target="_blank">open in a tab</a></div><iframe class="vl-frame tall" id="sfxFrame"></iframe></section>
<section class="vl-pane" id="pane-tracker"><div class="vl-toolbar">The 8-track chip tracker. Press / to load or save a song into js/music-themes.js; name songs after where they play (title, play, boss). <a href="/editor/tracker/?game=<?= $e($slug) ?>" target="_blank">open in a tab</a></div><iframe class="vl-frame tall" id="trackerFrame"></iframe></section>
<section class="vl-pane" id="pane-config"><div class="vl-toolbar">Every number in js/config.js with its comment. Changes save to js/tuning.js and apply on reload. <a href="/editor/config/?game=<?= $e($slug) ?>" target="_blank">open in a tab</a></div><iframe class="vl-frame tall" id="configFrame"></iframe></section>

<section class="vl-pane" id="pane-design">
  <div class="vl-toolbar"><button class="btn btn-sm btn-primary" onclick="saveDoc()">Save GAME.md</button><button class="btn btn-sm btn-outline-secondary" onclick="loadDoc()">Reload</button><span id="docMsg">The contract: brief, design, acceptance criteria, tasks, log. The planner writes it; you can edit it any time.</span></div>
  <textarea class="vl-doc" id="doc" spellcheck="false"><?= $e($gameMd) ?></textarea>
</section>

<section class="vl-pane" id="pane-history">
  <div class="vl-toolbar"><input id="cpLabel" placeholder="what this checkpoint is" style="width:320px"><button class="btn btn-sm btn-primary" onclick="makeCheckpoint()">Checkpoint now</button><span>Every agent run is bracketed by a tag. Rolling back makes a checkpoint of the current state first.</span></div>
  <table class="vl-table"><thead><tr><th>tag</th><th>when</th><th>label</th><th></th></tr></thead><tbody id="cps"></tbody></table>
</section>

<section class="vl-pane" id="pane-ship">
  <div class="vl-cols">
    <div class="vl-card"><h3 class="vl-h2 mt-0">Build &amp; publish</h3><div class="vl-kv" id="shipInfo"></div><div class="vl-toolbar mt-2"><button class="btn btn-sm btn-primary" onclick="publish()" id="pubBtn">Build and publish</button></div><div class="vl-msg" id="pubMsg"></div></div>
    <div class="vl-card"><h3 class="vl-h2 mt-0">Store card</h3>
      <form class="vl-form" id="cardForm">
        <label>Title <input name="title"></label>
        <label>Status <select name="status"><option>new</option><option>designed</option><option>building</option><option>playable</option><option>polished</option><option>published</option></select></label>
        <label>Price (USD) <input name="price" type="number" step="0.01" min="0" placeholder="2.99"></label>
        <label>Tags <input name="tags" placeholder="arcade, shooter, mobile"></label>
        <label>Team <select name="team_id"><option value="0">Just me</option><?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"><?= $e($t['name']) ?></option><?php endforeach; ?></select></label>
        <label>Notes <textarea name="notes" rows="3" placeholder="store blurb, what to screenshot, what is missing before launch"></textarea></label>
        <button type="submit" class="btn btn-sm btn-primary">Save card</button> <span class="vl-msg" id="cardMsg"></span>
      </form>
    </div>
  </div>
</section>

<script src="/forge.js"></script>
<script>
const slug = <?= json_encode($slug) ?>, $ = id => document.getElementById(id);
let G = <?= json_encode($game, JSON_UNESCAPED_SLASHES) ?>, lastId = 0, loaded = {}, pollT = null;
function showTab(name) {
  for (const b of document.querySelectorAll('.vl-tabs button')) b.classList.toggle('on', b.dataset.tab === name);
  for (const p of document.querySelectorAll('.vl-pane')) p.classList.toggle('on', p.id === 'pane-' + name);
  location.hash = name;
  if (name === 'play' && !loaded.play) { loaded.play = 1; $('playFrame').src = `/games/${slug}/`; }
  if (['sfx', 'tracker', 'config'].includes(name) && !loaded[name]) { loaded[name] = 1; $(name + 'Frame').src = `/editor/${name}/?game=${slug}`; }
}
$('tabs').onclick = e => { const b = e.target.closest('button'); if (b) showTab(b.dataset.tab); };
function reloadPlay() { $('playFrame').src = `/games/${slug}/?r=${Date.now()}`; }
function playWith(q) { $('playFrame').src = `/games/${slug}/?${q}&r=${Date.now()}`; }
function render(d) {
  G = d.game; $('statusPill').textContent = G.status; $('metaLine').textContent = `${G.template} · ${G.checkpoints} checkpoints · ${G.lastCommit || ''}`;
  renderTasks(G.taskList || []); renderSession(G.agent); renderCps(d.checkpoints); renderSoaks(d.soaks);
  const f = $('cardForm'); if (document.activeElement && f.contains(document.activeElement)) {} else { f.title.value = G.title || ''; f.status.value = G.status || 'new'; f.price.value = G.price == null ? '' : G.price; f.tags.value = (G.tags || []).join(', '); f.notes.value = G.notes || ''; f.team_id.value = String(G.teamId || 0); }
  $('shipInfo').innerHTML = `<b>last commit</b> ${Forge.esc(G.lastCommit || 'none')}<br><b>published</b> ${G.publishedAt ? Forge.esc(G.publishedAt) + (G.publishedUrl ? ' · <a href="' + Forge.esc(G.publishedUrl) + '" target="_blank">' + Forge.esc(G.publishedUrl) + '</a>' : ' · dist/ only') : 'never'}<br><b>last soak</b> ${G.lastSoak ? (G.lastSoak.ok ? '<span class="ok">ok</span>' : '<span class="bad">failing</span>') : 'none'}`;
}
function renderTasks(list) { $('tasks').innerHTML = list.length ? list.map(t => `<li class="${t.done ? 'done' : ''}">${t.done ? '✓' : '○'} ${Forge.esc(t.id)}: ${Forge.esc(t.text)}</li>`).join('') : '<li>no plan yet: run the pipeline</li>'; }
function renderSession(a) { a = a || {}; $('session').innerHTML = `<b>${a.busy ? 'running ' + Forge.esc(a.mode) : 'idle'}</b><br>runs ${a.runs || 0} · spent $${(+a.cost || 0).toFixed(2)}<br>session ${a.session ? Forge.esc(String(a.session).slice(0, 8)) : 'none yet'}`; $('agentStatus').textContent = a.busy ? 'agent: ' + a.mode : 'agent idle'; $('agentStatus').className = 'vl-pill' + (a.busy ? ' live' : ''); $('btnPipeline').disabled = !!a.busy; $('chatBtn').textContent = a.busy ? 'Queue' : 'Send'; $('chatText').placeholder = a.busy ? 'The agent is busy: a message now waits in its inbox and goes with the next run (the next task, fix or rework).' : $('chatText').dataset.ph; }
function renderCps(list) { $('cps').innerHTML = (list || []).map(c => `<tr><td>${Forge.esc(c.tag)}</td><td>${Forge.esc(c.at.slice(0, 16))}</td><td>${Forge.esc(c.label)}</td><td><button class="btn btn-sm btn-outline-secondary" onclick="rollback('${Forge.esc(c.tag)}')">roll back</button></td></tr>`).join('') || '<tr><td colspan="4">no checkpoints</td></tr>'; }
function renderSoaks(list) { $('soaks').innerHTML = (list || []).map(s => `<tr><td>${Forge.esc(s.id)}</td><td>${s.ok ? '<b class="ok">ok</b>' : '<b class="bad">' + Forge.esc(s.status || 'fail') + '</b>'}</td><td>${s.seconds || ''} s</td><td>${s.fps || ''}</td><td>${s.errors || 0}</td><td>${Forge.esc(Object.entries(s.scenes || {}).map(([k, v]) => k + ' ' + v + 's').join(', '))}</td><td>${s.score == null ? '' : s.score}</td><td><button class="btn btn-sm btn-outline-secondary" onclick="soakDetail('${Forge.esc(s.id)}')">detail</button></td></tr>`).join('') || '<tr><td colspan="8">no soaks yet</td></tr>'; }
async function soakDetail(id) { const r = await Forge.api(`/game/soakreport/${slug}?id=${id}`); $('soakDetail').innerHTML = `<pre class="vl-json">${Forge.esc(JSON.stringify(r, null, 1))}</pre><img class="vl-shot" src="/game/soakshot/${slug}?id=${id}" onerror="this.remove()">`; }
function addEvent(ev) {
  const box = $('stream'), d = document.createElement('div'); d.className = 'ev ' + ev.kind + (ev.error ? ' err' : ''); let text = ev.text || '';
  if (ev.kind === 'tool') text = `▸ ${ev.name} ${JSON.stringify(ev.input || {})}`;
  if (ev.kind === 'result') text = (ev.error ? '✗ ' : '· ') + text;
  if (ev.kind === 'done') text = `■ ${ev.label || 'run'} ${ev.ok ? 'finished' : 'failed'} · ${ev.turns} turns · $${(+ev.cost || 0).toFixed(3)}` + (ev.text ? '\n' + ev.text : '');
  if (ev.kind === 'stage') { text = `◆ ${ev.stage}: ${ev.text || ''}` + (ev.failures ? '\n' + ev.failures.map(f => '  - ' + f).join('\n') : ''); $('stage').innerHTML = `<b>${Forge.esc(ev.stage)}</b><br>${Forge.esc(ev.text || '')}`; if (ev.tasks) renderTasks(ev.tasks); }
  if (ev.kind === 'soak') text = `⚗ soak ${ev.ok ? 'ok' : 'FAILED (' + ev.status + ')'} · ${ev.seconds || 0} s · ${ev.fps || '?'} fps · scenes ${Object.keys(ev.scenes || {}).join(', ')}` + ((ev.errors || []).concat(ev.browserErrors || []).map(e => '\n  ! ' + String(e.text || '').split('\n')[0]).join(''));
  if (ev.kind === 'checkpoint') text = `⚑ checkpoint ${ev.tag}: ${ev.label || ''}`;
  if (ev.kind === 'idle') text = '— idle —';
  d.innerHTML = `<time>${Forge.time(ev.t)}</time>` + Forge.esc(text);
  const stick = box.scrollTop + box.clientHeight >= box.scrollHeight - 40; box.appendChild(d); if (box.children.length > 1500) box.removeChild(box.firstChild); if (stick) box.scrollTop = box.scrollHeight;
}
let refreshDue = false;
async function poll() {
  try {
    const r = await Forge.api(`/game/events/${slug}?since=${lastId}`);
    for (const ev of r.events) { addEvent(ev); if (['idle', 'checkpoint', 'soak', 'done'].includes(ev.kind)) refreshDue = true; }
    lastId = r.last; renderSession(r.agent);
    if (refreshDue) { refreshDue = false; render(await Forge.api(`/game/status/${slug}`)); }
    $('streamNote').textContent = '';
  } catch (e) { $('streamNote').textContent = 'polling: ' + e.message; }
  pollT = setTimeout(poll, document.hidden ? 8000 : (G.agent && G.agent.busy ? 1500 : 5000));
}
async function startAgent(mode, prompt) { try { renderSession(await Forge.api(`/game/agent/${slug}`, { mode, prompt: prompt || '' })); showTab('build'); } catch (e) { alert(e.message); } }
async function stopAgent() { try { await Forge.api(`/game/stop/${slug}`, {}); } catch (e) { alert(e.message); } }
async function resetSession() { renderSession(await Forge.api(`/game/reset/${slug}`, {})); }
$('chatForm').onsubmit = async e => { e.preventDefault(); const t = $('chatText').value.trim(); if (!t) return; try { const r = await Forge.api(`/game/agent/${slug}`, { mode: 'chat', prompt: t }); renderSession(r); $('chatText').value = ''; showTab('build'); } catch (err) { alert(err.message); } };
async function runSoak() { $('soakBtn').disabled = true; try { await Forge.api(`/game/agent/${slug}`, { mode: 'soak', prompt: `${+$('soakSecs').value} ${+$('soakFast').value}` }); showTab('build'); } catch (e) { alert(e.message); } $('soakBtn').disabled = false; }
async function makeCheckpoint() { const r = await Forge.api(`/game/checkpoint/${slug}`, { label: $('cpLabel').value }); $('cpLabel').value = ''; renderCps(r.checkpoints); }
async function rollback(tag) { if (!confirm(`Roll ${slug} back to ${tag}? The current state is checkpointed first.`)) return; try { const r = await Forge.api(`/game/rollback/${slug}`, { ref: tag }); renderCps(r.checkpoints); reloadPlay(); refreshDue = true; } catch (e) { alert(e.message); } }
async function loadDoc() { const r = await Forge.api(`/game/doc/${slug}`); $('doc').value = r.text; $('docMsg').textContent = 'loaded'; }
async function saveDoc() { await Forge.api(`/game/doc/${slug}`, { text: $('doc').value }); $('docMsg').textContent = 'saved · ' + new Date().toLocaleTimeString(); refreshDue = true; }
async function publish() { $('pubBtn').disabled = true; $('pubMsg').textContent = 'building…'; try { const r = await Forge.api(`/game/publish/${slug}`, {}); $('pubMsg').innerHTML = `build ${Forge.esc(r.build)}` + (r.url ? ` · live at <a href="${Forge.esc(r.url)}" target="_blank">${Forge.esc(r.url)}</a>` : ' · dist/ only (no [forge] domain)'); refreshDue = true; } catch (e) { $('pubMsg').textContent = e.message; } $('pubBtn').disabled = false; }
$('cardForm').onsubmit = async e => { e.preventDefault(); const f = e.target; try { render({ game: await Forge.api(`/game/card/${slug}`, { title: f.title.value, status: f.status.value, price: f.price.value === '' ? null : +f.price.value, tags: f.tags.value.split(',').map(s => s.trim()).filter(Boolean), notes: f.notes.value, team_id: +f.team_id.value }), checkpoints: <?= json_encode($checkpoints) ?>, soaks: <?= json_encode($soaks) ?> }); $('cardMsg').textContent = 'saved'; } catch (err) { $('cardMsg').textContent = err.message; } };
// ---- the producer's notes: the game (kit-notes.js, in the iframe) posts them here; they batch in the Build sidebar ----
let NOTES = [];
function noteMode() { const f = $('playFrame'); if (!f.contentWindow) return; f.contentWindow.postMessage({ type: 'forge:notes', on: true }, '*'); f.focus(); }
window.addEventListener('message', async e => {
  const m = e.data || {}; if (m.type !== 'forge:note' || !m.note) return;
  try { const r = await Forge.api(`/game/note/${slug}`, { note: m.note }); NOTES = r.notes; renderNotes(); if (m.note.build) refreshDue = true; } catch (err) { alert('note not saved: ' + err.message); }
});
function renderNotes() {
  const open = NOTES.filter(n => n.state === 'open'), rest = NOTES.filter(n => n.state !== 'open').slice(0, 6);
  $('notesCount').textContent = open.length ? `(${open.length} open)` : '';
  const row = n => `<div class="vl-note ${n.state}"><label><input type="checkbox" data-id="${n.id}" ${n.state === 'open' ? 'checked' : 'disabled'}>${n.shot ? `<img src="/game/noteshot/${slug}?id=${n.id}" alt="">` : ''}<div><div class="vl-note-text">${Forge.esc(n.text)}</div><div class="vl-note-meta">${Forge.esc(n.scene)} · (${n.x}, ${n.y})${n.what && n.what.label ? ' · ' + Forge.esc(n.what.label) : ''} · ${n.state}</div></div></label><div class="vl-note-btns">${n.state !== 'done' ? `<button title="done" onclick="noteState(${n.id}, 'done')">✓</button>` : ''}${n.state !== 'open' ? `<button title="reopen" onclick="noteState(${n.id}, 'open')">↺</button>` : ''}<button title="delete" onclick="noteState(${n.id}, 'delete')">✕</button></div></div>`;
  $('notesBox').innerHTML = (open.length ? open.map(row).join('') : '<div class="vl-kv">No open notes. In Play, press Note mode (or Alt+P in the game), click a thing, say what should change.</div>') + (rest.length ? '<div class="vl-note-meta mt-2">recent</div>' + rest.map(row).join('') : '');
  $('sendNotesBtn').disabled = !open.length;
  $('notesStrip').innerHTML = open.length ? open.slice(0, 8).map(n => `<span class="vl-chip" title="${Forge.esc(n.text)}">${n.shot ? `<img src="/game/noteshot/${slug}?id=${n.id}" alt="">` : ''}${Forge.esc(n.text.slice(0, 40))}</span>`).join('') + ` <a href="#build" onclick="showTab('build')">${open.length} open note${open.length === 1 ? '' : 's'} → Build</a>` : '';
}
async function noteState(id, state) { if (state === 'delete' && !confirm('Delete this note?')) return; try { NOTES = (await Forge.api(`/game/notestate/${slug}`, { id, state })).notes; renderNotes(); } catch (e) { alert(e.message); } }
async function sendNotes() { const ids = [...document.querySelectorAll('#notesBox input[type=checkbox]:checked')].map(c => +c.dataset.id); if (!ids.length) return; try { const r = await Forge.api(`/game/sendnotes/${slug}`, { ids, text: $('notesIntro').value.trim() }); NOTES = r.notes; renderNotes(); $('notesIntro').value = ''; refreshDue = true; } catch (e) { alert(e.message); } }
async function loadNotes() { try { NOTES = (await Forge.api(`/game/notes/${slug}`)).notes; renderNotes(); } catch (e) {} }
loadNotes();
render({ game: G, checkpoints: <?= json_encode($checkpoints) ?>, soaks: <?= json_encode($soaks) ?> });
showTab(location.hash.slice(1) || 'play'); poll();
window.addEventListener('hashchange', () => showTab(location.hash.slice(1) || 'play')); // a link to #config or #build switches the tab without a reload
</script>
