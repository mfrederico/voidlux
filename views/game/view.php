<?php /* One game: play, build (the agent stream), test (soaks), the three editors, the design doc, history, ship.
         The page polls /game/events and /game/status; every action is a JSON POST with the CSRF token. */
$slug = $game['slug']; $e = fn($s) => htmlspecialchars((string) $s);
?>
<meta name="csrf-token" content="<?= $e(csrf_token()) ?>">
<div class="vl-gamebar"><b><?= $e($game['title']) ?></b><span id="metaLine"><?= $e($game['template']) ?> · <?= (int) $game['checkpoints'] ?> checkpoints · <?= $e($game['lastCommit']) ?></span><span class="vl-pill" id="statusPill"><?= $e($game['status']) ?></span><span id="agentStatus" class="vl-pill">agent idle</span>
  <a class="btn btn-sm btn-outline-secondary ms-auto" href="/games/<?= $e($slug) ?>/" target="_blank">Play in a tab</a><?php if ($game['publishedUrl']): ?><a class="btn btn-sm btn-outline-primary" href="<?= $e($game['publishedUrl']) ?>" target="_blank">Live</a><?php endif; ?></div>
<div class="vl-tabs" id="tabs">
  <button data-tab="play" class="on">Play</button><button data-tab="build">Build</button><button data-tab="test">Test</button><button data-tab="notes">Notes <span id="notesTab"></span></button><button data-tab="media">Media</button><button data-tab="ship">Ship</button>
  <span class="vl-tab-menu"><button data-tab="advanced" type="button">Advanced ▾</button><span class="vl-tab-menu-list"><button data-tab="design">Concept</button><button data-tab="status">Status</button><button data-tab="config">Config</button><button data-tab="history">History</button></span></span>
</div>

<section class="vl-pane" id="pane-status">
  <div class="vl-toolbar"><span>What the builder is doing: the pipeline stage, the task list from the Concept, and the agent session's running cost.</span></div>
  <div class="vl-cols">
    <div class="vl-card"><h3 class="vl-h2 mt-0">Pipeline</h3><div id="stage" class="vl-kv">idle</div></div>
    <div class="vl-card"><h3 class="vl-h2 mt-0">Tasks</h3><ul class="vl-tasks" id="tasks"></ul></div>
    <div class="vl-card"><h3 class="vl-h2 mt-0">Session</h3><div class="vl-kv" id="session"></div><div class="vl-kv mt-2 small">Agents sign in on <a href="/agents">AI agents</a>; today: <?= $e($agentNote) ?>.</div></div>
  </div>
</section>

<section class="vl-pane" id="pane-media">
  <div class="vl-toolbar"><span>The game's sound, music and pictures. Each opens its own workshop; the editors write straight into the game's files.</span></div>
  <div class="vl-media-grid">
    <a class="vl-media-card" href="#sfx" onclick="showTab('sfx'); return false;"><div class="vl-media-icon">♪</div><div class="vl-media-title">SFX</div><div class="vl-media-sub">Sound effects for the elements of the game: every <code>SFX.play</code> key, designed as layers.</div></a>
    <a class="vl-media-card" href="#tracker" onclick="showTab('tracker'); return false;"><div class="vl-media-icon">▦</div><div class="vl-media-title">Tracker</div><div class="vl-media-sub">Make chiptune music: the 8-track tracker with the kit's own synth and drums.</div></a>
    <a class="vl-media-card" href="#assets" onclick="showTab('assets'); return false;"><div class="vl-media-icon">◈</div><div class="vl-media-title">Media</div><div class="vl-media-sub">Download and upload SVG and image files into the game's <code>assets/</code> folder for the builder to use.</div></a>
    <div class="vl-media-card soon"><div class="vl-media-icon">✦</div><div class="vl-media-title">Sprites</div><div class="vl-media-sub">A sprite editor on the kit's atlas. Not built yet.</div></div>
  </div>
</section>

<section class="vl-pane" id="pane-assets">
  <div class="vl-toolbar"><a href="#media" onclick="showTab('media'); return false;">← Media</a><span>Files in the game's <code>assets/</code> folder. The builder can load them with <code>new Image()</code> or inline an SVG; tell it which in a note or a chat message.</span></div>
  <form class="vl-toolbar" method="post" action="/game/assetupload/<?= $e($slug) ?>" enctype="multipart/form-data"><?= csrf_field() ?><input type="file" name="file" accept=".svg,.png,.jpg,.jpeg,.gif,.webp" required><button class="btn btn-sm btn-primary" type="submit">Upload</button><span>SVG, PNG, JPG, GIF or WebP, up to 5 MB.</span></form>
  <div class="vl-assets" id="assets"></div>
</section>

<section class="vl-pane on" id="pane-play">
  <div class="vl-toolbar"><button class="btn btn-sm btn-outline-secondary" onclick="reloadPlay()">Reload</button><button class="btn btn-sm btn-outline-secondary" onclick="playWith('fps=1')">Profiler</button><button class="btn btn-sm btn-outline-secondary" onclick="playWith('bot=1')">Autopilot</button><button class="btn btn-sm btn-primary" onclick="noteMode()" id="noteBtn">Note mode (Alt+P)</button><span>The game as a player sees it, straight from its folder. Note mode pauses it: click anything, say what should change.</span></div>
  <iframe class="vl-frame" id="playFrame" allow="autoplay; fullscreen"></iframe>
  <div class="vl-notes-strip" id="notesStrip"></div>
</section>

<section class="vl-pane" id="pane-build">
  <div class="vl-build">
    <div>
      <div class="vl-toolbar">
        <button class="btn btn-sm btn-primary" onclick="startAgent('pipeline')" id="btnPipeline" title="The whole loop: plan what the Concept still lacks, build every open task, playtest, review against the acceptance criteria, rework what fails, checkpoint along the way.">Build the game</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="startAgent('review')" title="An independent reviewer (a fresh agent that never saw the build) checks every acceptance criterion in the Concept, with evidence, and reports pass or fail. Builds nothing.">Review</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="startAgent('fix')" title="A headless playtest: the game's own bots play it for 90 game-seconds while every error is caught. If anything breaks, the builder is asked to fix it and test again.">Playtest &amp; fix</button>
        <button class="btn btn-sm btn-danger" onclick="stopAgent()" title="Stops the agent that is running now. Whatever it had committed stays; a checkpoint marks where it stopped.">Stop</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="resetSession()" title="Forget the conversation so far: the next run starts with a clean memory and reads the game fresh. Use it when the builder seems stuck on an old idea.">Forget context</button>
        <span id="streamNote"><?= $e($agentNote) ?></span>
      </div>
      <div class="vl-stageline" id="stageline"></div>
      <div class="vl-stream" id="stream"></div>
      <form class="vl-compose" id="chatForm">
        <textarea id="chatText" data-ph="Tell the builder what to do: 'make the enemies spawn in rings', 'add a boss every fifth wave', 'the ship feels sluggish, tighten it'. Each message is one agent turn in the game's folder, checkpointed after." placeholder="Tell the builder what to do: 'make the enemies spawn in rings', 'add a boss every fifth wave', 'the ship feels sluggish, tighten it'. Each message is one agent turn in the game's folder, checkpointed after."></textarea>
        <div class="btns"><button type="submit" class="btn btn-primary" id="chatBtn">Send</button><button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('chatText').value=''">Clear</button></div>
      </form>
    </div>
  </div>
</section>

<section class="vl-pane" id="pane-test">
  <div class="vl-card mb-3"><h3 class="vl-h2 mt-0">Your browser is the test machine</h3>
    <div class="vl-kv"><span class="vl-pill live" id="attachedPill">● this browser is attached</span> While this page is open, playtests for <b><?= $e($game['title']) ?></b> run here: the game plays itself with its bots in a hidden frame, every error is caught, and the report goes back to the forge. Nothing to install, any computer, any browser. Close the page and the server's own Chrome takes over. If several of you have the page open, whoever's browser answers first runs it.</div>
    <div class="vl-kv mt-2"><b>Playtests run here so far:</b> <span id="hereCount">0</span> · last: <span id="hereLast">none yet</span></div>
    <details class="mt-2"><summary class="vl-kv" style="cursor:pointer">Advanced: let the server drive a visible Chrome on your own computer</summary>
      <div class="vl-kv mt-2">Needs an SSH login on the server, so this is for the operator, not for everyone. Start Chrome with remote debugging, then open a tunnel; while it is up, playtests run in that Chrome and you watch the bots play.<br>
      <b>Mac / Linux:</b> <code>google-chrome --remote-debugging-port=9222 --user-data-dir=/tmp/chrome-debug</code> then <code>ssh -N -R 9222:localhost:9222 ubuntu@voidlux.com</code><br>
      <b>Windows (PowerShell):</b> <code>&amp; "C:\Program Files\Google\Chrome\Application\chrome.exe" --remote-debugging-port=9222 --user-data-dir=$env:TEMP\chrome-debug</code> then <code>ssh -N -R 9222:localhost:9222 ubuntu@voidlux.com</code> (Windows 10 and 11 ship ssh.exe).</div></details>
  </div>
  <div class="vl-toolbar">
    <label>game-seconds <input type="number" id="soakSecs" value="60" min="5" max="1800" style="width:80px"></label>
    <label>speed <input type="number" id="soakFast" value="4" min="1" max="30" style="width:60px"></label>
    <button class="btn btn-sm btn-primary" onclick="runSoak()" id="soakBtn" title="The game's own bots play it headless for this many game-seconds at this speed; every exception, console error and failed check is reported.">Run a playtest</button>
    <span>A playtest (the agents call it a soak): the game plays itself with its bots while every exception and failed check is caught. It runs in <b>this browser</b> while you have this page open, so the server stays light; with nobody watching, the server's own headless Chrome runs it.</span>
  </div>
  <table class="vl-table"><thead><tr><th>when</th><th>result</th><th>played</th><th>fps</th><th>errors</th><th>scenes</th><th>score</th><th></th></tr></thead><tbody id="soaks"></tbody></table>
  <div id="soakDetail"></div>
</section>

<section class="vl-pane" id="pane-notes">
  <div class="vl-toolbar"><span>Producer's notes: everything anyone pointed at in note mode (Play → Note mode, or Alt+P in the game). Check the ones to hand to the builder in one batch.</span></div>
  <div class="vl-toolbar"><label class="vl-check"><input type="checkbox" id="notesAll" onchange="notesAll(this.checked)"> check all</label><input id="notesIntroBig" placeholder="a word for the builder (optional)" style="flex:1;min-width:160px"><button class="btn btn-sm btn-primary" onclick="sendNotes('big')" id="sendNotesBig">Send checked to build</button><select id="notesFilter" onchange="renderNotes()" title="which notes to show"><option value="all">all notes</option><option value="open">open only</option><option value="sent">sent to a build</option><option value="done">done</option></select></div>
  <div class="vl-compose-batch" id="composeBatch" style="display:none"><h3 class="vl-h2 mt-0">What the builder will read</h3><p class="vl-kv">Composed from the checked notes, their votes and comments. Change any of it before it goes.</p><textarea id="composeText" spellcheck="false"></textarea><div class="vl-toolbar mt-2"><button class="btn btn-sm btn-primary" onclick="sendComposed()">Send to the builder</button><button class="btn btn-sm btn-outline-secondary" onclick="$('composeBatch').style.display='none'">Cancel</button><span id="composeMsg"></span></div></div>
  <div class="vl-notes-big" id="notesBig"></div>
</section>
<div class="vl-lightbox" id="lightbox" onclick="this.classList.remove('on')"><img alt=""><div class="vl-lightbox-cap"></div></div>
<section class="vl-pane" id="pane-sfx"><div class="vl-toolbar"><a href="#media" onclick="showTab('media'); return false;">← Media</a>Every <code>SFX.play('key')</code> in the game's code is a slot here. Design it as layers; Save writes js/sfx.js. <a href="/editor/sfx/?game=<?= $e($slug) ?>" target="_blank">open in a tab</a></div><iframe class="vl-frame tall" id="sfxFrame"></iframe></section>
<section class="vl-pane" id="pane-tracker"><div class="vl-toolbar"><a href="#media" onclick="showTab('media'); return false;">← Media</a>The 8-track chip tracker. Press / to load or save a song into js/music-themes.js; name songs after where they play (title, play, boss). <a href="/editor/tracker/?game=<?= $e($slug) ?>" target="_blank">open in a tab</a></div><iframe class="vl-frame tall" id="trackerFrame"></iframe></section>
<section class="vl-pane" id="pane-config"><div class="vl-toolbar">Every number in js/config.js with its comment. Changes save to js/tuning.js and apply on reload. <a href="/editor/config/?game=<?= $e($slug) ?>" target="_blank">open in a tab</a></div><iframe class="vl-frame tall" id="configFrame"></iframe></section>

<section class="vl-pane" id="pane-design">
  <div class="vl-toolbar"><button class="btn btn-sm btn-outline-secondary" onclick="toggleDocEdit()" id="docEditBtn">Edit</button><button class="btn btn-sm btn-primary" onclick="saveDoc()" id="docSaveBtn" style="display:none">Save GAME.md</button><button class="btn btn-sm btn-outline-secondary" onclick="loadDoc()">Reload</button><span id="docMsg">The game's concept: brief, design, acceptance criteria, tasks and log, as the planner and the builders keep it in GAME.md.</span></div>
  <article class="vl-md" id="docHtml"><?= \app\Game::md($gameMd) ?></article>
  <textarea class="vl-doc" id="doc" spellcheck="false" style="display:none"><?= $e($gameMd) ?></textarea>
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
const GROUP = { sfx: 'media', tracker: 'media', assets: 'media', design: 'advanced', status: 'advanced', config: 'advanced', history: 'advanced' };
function showTab(name) {
  if (name === 'advanced') { document.querySelector('.vl-tab-menu').classList.toggle('open'); return; }
  document.querySelector('.vl-tab-menu').classList.remove('open');
  for (const b of document.querySelectorAll('.vl-tabs button')) b.classList.toggle('on', b.dataset.tab === name || b.dataset.tab === GROUP[name]);
  for (const p of document.querySelectorAll('.vl-pane')) p.classList.toggle('on', p.id === 'pane-' + name);
  location.hash = name;
  if (name === 'play' && !loaded.play) { loaded.play = 1; $('playFrame').src = `/games/${slug}/`; }
  if (['sfx', 'tracker', 'config'].includes(name) && !loaded[name]) { loaded[name] = 1; $(name + 'Frame').src = `/editor/${name}/?game=${slug}`; }
  if (name === 'design') loadDoc();
  if (name === 'build') { const b = $('stream'); b.scrollTop = b.scrollHeight; }
  if (name === 'notes') loadNotes();
  if (name === 'assets') loadAssets(); // the timeline opens on the newest event
}
$('tabs').onclick = e => { const b = e.target.closest('button'); if (b) showTab(b.dataset.tab); };
async function loadAssets() { try { const r = await Forge.api(`/game/assets/${slug}`); $('assets').innerHTML = r.files.length ? r.files.map(f => `<div class="vl-asset"><a href="/games/${slug}/assets/${encodeURIComponent(f.name)}" target="_blank">${/\.(svg|png|jpe?g|gif|webp)$/i.test(f.name) ? `<img src="/games/${slug}/assets/${encodeURIComponent(f.name)}" alt="">` : ''}</a><div class="vl-asset-name">${Forge.esc(f.name)}</div><div class="vl-note-meta">${Math.round(f.size / 1024)} KB</div><div class="vl-note-actions"><a class="btn btn-sm btn-outline-secondary" href="/games/${slug}/assets/${encodeURIComponent(f.name)}" download>Download</a><button class="btn btn-sm btn-outline-secondary" onclick="deleteAsset('${Forge.esc(f.name)}')">Delete</button></div></div>`).join('') : '<div class="vl-kv">No files yet. Upload an SVG or an image above.</div>'; } catch (e) { $('assets').innerHTML = '<div class="vl-kv">' + Forge.esc(e.message) + '</div>'; } }
async function deleteAsset(name) { if (!confirm('Delete ' + name + '?')) return; try { await Forge.api(`/game/assetdelete/${slug}`, { name }); loadAssets(); } catch (e) { alert(e.message); } }
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
function renderSoaks(list) { $('soaks').innerHTML = (list || []).map(s => `<tr><td>${Forge.esc(s.id)} <span class="vl-note-meta">${s.runner === 'browser' ? 'in a browser' : 'server'}</span></td><td>${s.ok ? '<b class="ok">ok</b>' : '<b class="bad">' + Forge.esc(s.status || 'fail') + '</b>'}</td><td>${s.seconds || ''} s</td><td>${s.fps || ''}</td><td>${s.errors || 0}</td><td>${Forge.esc(Object.entries(s.scenes || {}).map(([k, v]) => k + ' ' + v + 's').join(', '))}</td><td>${s.score == null ? '' : s.score}</td><td><button class="btn btn-sm btn-outline-secondary" onclick="soakDetail('${Forge.esc(s.id)}')">detail</button></td></tr>`).join('') || '<tr><td colspan="8">no soaks yet</td></tr>'; }
async function soakDetail(id) { const r = await Forge.api(`/game/soakreport/${slug}?id=${id}`); $('soakDetail').innerHTML = `<pre class="vl-json">${Forge.esc(JSON.stringify(r, null, 1))}</pre><img class="vl-shot" src="/game/soakshot/${slug}?id=${id}" onerror="this.remove()">`; }
function addEvent(ev) {
  const box = $('stream'), d = document.createElement('div'); d.className = 'ev ' + ev.kind + (ev.error ? ' err' : ''); let text = ev.text || '';
  if (ev.kind === 'tool') text = `▸ ${ev.name} ${JSON.stringify(ev.input || {})}`;
  if (ev.kind === 'result') text = (ev.error ? '✗ ' : '· ') + text;
  if (ev.kind === 'done') text = `■ ${ev.label || 'run'} ${ev.ok ? 'finished' : 'failed'} · ${ev.turns} turns · $${(+ev.cost || 0).toFixed(3)}` + (ev.text ? '\n' + ev.text : '');
  if (ev.kind === 'stage') { text = `◆ ${ev.stage}: ${ev.text || ''}` + (ev.failures ? '\n' + ev.failures.map(f => '  - ' + f).join('\n') : ''); $('stage').innerHTML = `<b>${Forge.esc(ev.stage)}</b><br>${Forge.esc(ev.text || '')}`; $('stageline').textContent = `◆ ${ev.stage} · ${ev.text || ''}`; if (ev.tasks) renderTasks(ev.tasks); }
  if (ev.kind === 'soak') text = `⚗ soak ${ev.ok ? 'ok' : 'FAILED (' + ev.status + ')'} · ${ev.seconds || 0} s · ${ev.fps || '?'} fps · scenes ${Object.keys(ev.scenes || {}).join(', ')}` + ((ev.errors || []).concat(ev.browserErrors || []).map(e => '\n  ! ' + String(e.text || '').split('\n')[0]).join(''));
  if (ev.kind === 'checkpoint') text = `⚑ checkpoint ${ev.tag}: ${ev.label || ''}`;
  if (ev.kind === 'idle') text = '— idle —';
  const stamp = `<time>${Forge.time(ev.t)}</time>`;
  if (ev.kind === 'text') d.innerHTML = stamp + '<div class="vl-md-ev">' + Forge.md(text) + '</div>';
  else if (ev.kind === 'done') d.innerHTML = stamp + `<div class="vl-done-head">■ ${Forge.esc(ev.label || 'run')} ${ev.ok ? 'finished' : 'failed'} · ${ev.turns} turns · $${(+ev.cost || 0).toFixed(3)}</div>` + (ev.text ? '<div class="vl-md-ev">' + Forge.md(ev.text) + '</div>' : '');
  else if (ev.kind === 'result') { const first = String(ev.text || '').split('\n')[0]; d.innerHTML = stamp + `<details><summary>${ev.error ? '✗' : '·'} ${Forge.esc(first.slice(0, 110))}${(ev.text || '').length > 110 ? '…' : ''}</summary><pre>${Forge.esc(ev.text || '')}</pre></details>`; }
  else if (ev.kind === 'tool') d.innerHTML = stamp + `<span class="vl-tool">${Forge.esc(ev.name)}</span> <span class="vl-tool-arg">${Forge.esc(JSON.stringify(ev.input || {}).slice(0, 200))}</span>`;
  else d.innerHTML = stamp + Forge.esc(text);
  const stick = box.scrollTop + box.clientHeight >= box.scrollHeight - 40; box.appendChild(d); if (box.children.length > 1500) box.removeChild(box.firstChild); if (stick) box.scrollTop = box.scrollHeight;
}
let refreshDue = false;
async function poll() {
  try {
    const r = await Forge.api(`/game/events/${slug}?since=${lastId}`);
    for (const ev of r.events) { addEvent(ev); if (['idle', 'checkpoint', 'soak', 'done'].includes(ev.kind)) refreshDue = true; }
    const first = lastId === 0; lastId = r.last; renderSession(r.agent); if (first) { const b = $('stream'); b.scrollTop = b.scrollHeight; }
    if (r.soakRequest && !soakRunning) runBrowserSoak(r.soakRequest);
    if (refreshDue) { refreshDue = false; render(await Forge.api(`/game/status/${slug}`)); }
    $('streamNote').textContent = ''; $('attachedPill').className = 'vl-pill live'; $('attachedPill').textContent = '● this browser is attached';
  } catch (e) { $('streamNote').textContent = 'polling: ' + e.message; $('attachedPill').className = 'vl-pill'; $('attachedPill').textContent = '○ not attached (reconnecting)'; }
  pollT = setTimeout(poll, document.hidden ? 8000 : (G.agent && G.agent.busy ? 1500 : 5000));
}
// ---- playtests in this browser: the server hands a pending request to whoever polls first; the game runs in a hidden
// frame here with its bots, and the report (window.__SOAK, same origin) goes back. The server's Chrome is the fallback ----
let soakRunning = false, hereCount = 0;
async function runBrowserSoak(req) {
  soakRunning = true;
  try {
    const c = await Forge.api(`/game/soakclaim/${slug}`, { id: req.id }); if (!c.claimed) return;
    $('streamNote').textContent = `playtest ${req.id} running in this browser (${req.secs} s at ${req.fast}x)…`;
    const win = document.createElement('div'); win.className = 'vl-soak-win'; win.innerHTML = `<div class="vl-soak-title"><span>▶ playtest · ${req.secs} game-seconds at ${req.fast}x · the bots are playing</span><button type="button" title="hide the window (the playtest keeps running)">–</button></div>`;
    win.querySelector('button').onclick = () => win.classList.toggle('min');
    const f = document.createElement('iframe'); f.className = 'vl-soak-frame'; f.src = `/games/${slug}/?soak=${req.secs}&fast=${req.fast}&seed=7&r=${Date.now()}`; win.appendChild(f); document.body.appendChild(win);
    const budget = Date.now() + (req.secs / Math.max(1, req.fast)) * 1000 * 2.5 + 20000; let rep = null, errs = [];
    try { f.contentWindow.addEventListener('error', e => errs.push(String(e.message))); } catch (e) {}
    while (Date.now() < budget) { await new Promise(r => setTimeout(r, 500)); try { if (f.contentWindow && f.contentWindow.__SOAK) { rep = f.contentWindow.__SOAK; break; } } catch (e) { break; } }
    if (!rep) rep = { status: 'timeout', errors: [{ text: 'the game never finished the soak in this browser (' + errs.join('; ') + ')' }], scenes: {} };
    win.querySelector('.vl-soak-title span').textContent = rep.ok ? '✓ playtest ok · ' + (rep.fps || '?') + ' fps · score ' + (rep.score ?? '') : '✗ playtest ' + (rep.status || 'failed') + ' · ' + ((rep.errors || []).length) + ' error(s)';
    win.classList.add(rep.ok ? 'ok' : 'bad'); setTimeout(() => win.remove(), 4000);
    await Forge.api(`/game/soakdone/${slug}`, { id: req.id, report: rep });
    hereCount++; $('hereCount').textContent = hereCount; $('hereLast').textContent = new Date().toLocaleTimeString() + (rep.ok ? ' · ok' : ' · ' + (rep.status || 'failed'));
    $('streamNote').textContent = ''; refreshDue = true;
  } catch (e) { $('streamNote').textContent = 'playtest here failed: ' + e.message; }
  finally { soakRunning = false; }
}
async function startAgent(mode, prompt) { try { renderSession(await Forge.api(`/game/agent/${slug}`, { mode, prompt: prompt || '' })); showTab('build'); } catch (e) { alert(e.message); } }
async function stopAgent() { try { await Forge.api(`/game/stop/${slug}`, {}); } catch (e) { alert(e.message); } }
async function resetSession() { renderSession(await Forge.api(`/game/reset/${slug}`, {})); }
$('chatForm').onsubmit = async e => { e.preventDefault(); const t = $('chatText').value.trim(); if (!t) return; try { const r = await Forge.api(`/game/agent/${slug}`, { mode: 'chat', prompt: t }); renderSession(r); $('chatText').value = ''; showTab('build'); } catch (err) { alert(err.message); } };
async function runSoak() { $('soakBtn').disabled = true; try { await Forge.api(`/game/agent/${slug}`, { mode: 'soak', prompt: `${+$('soakSecs').value} ${+$('soakFast').value}` }); showTab('build'); } catch (e) { alert(e.message); } $('soakBtn').disabled = false; }
async function makeCheckpoint() { const r = await Forge.api(`/game/checkpoint/${slug}`, { label: $('cpLabel').value }); $('cpLabel').value = ''; renderCps(r.checkpoints); }
async function rollback(tag) { if (!confirm(`Roll ${slug} back to ${tag}? The current state is checkpointed first.`)) return; try { const r = await Forge.api(`/game/rollback/${slug}`, { ref: tag }); renderCps(r.checkpoints); reloadPlay(); refreshDue = true; } catch (e) { alert(e.message); } }
let docEditing = false;
function toggleDocEdit() { docEditing = !docEditing; $('doc').style.display = docEditing ? '' : 'none'; $('docHtml').style.display = docEditing ? 'none' : ''; $('docSaveBtn').style.display = docEditing ? '' : 'none'; $('docEditBtn').textContent = docEditing ? 'View' : 'Edit'; }
async function loadDoc() { const r = await Forge.api(`/game/doc/${slug}`); $('doc').value = r.text; $('docHtml').innerHTML = r.html; $('docMsg').textContent = 'loaded'; }
async function saveDoc() { await Forge.api(`/game/doc/${slug}`, { text: $('doc').value }); $('docMsg').textContent = 'saved · ' + new Date().toLocaleTimeString(); refreshDue = true; await loadDoc(); if (docEditing) toggleDocEdit(); }
async function publish() { $('pubBtn').disabled = true; $('pubMsg').textContent = 'building…'; try { const r = await Forge.api(`/game/publish/${slug}`, {}); $('pubMsg').innerHTML = `build ${Forge.esc(r.build)}` + (r.url ? ` · live at <a href="${Forge.esc(r.url)}" target="_blank">${Forge.esc(r.url)}</a>` : ' · dist/ only (no [forge] domain)'); refreshDue = true; } catch (e) { $('pubMsg').textContent = e.message; } $('pubBtn').disabled = false; }
$('cardForm').onsubmit = async e => { e.preventDefault(); const f = e.target; try { render({ game: await Forge.api(`/game/card/${slug}`, { title: f.title.value, status: f.status.value, price: f.price.value === '' ? null : +f.price.value, tags: f.tags.value.split(',').map(s => s.trim()).filter(Boolean), notes: f.notes.value, team_id: +f.team_id.value }), checkpoints: <?= json_encode($checkpoints) ?>, soaks: <?= json_encode($soaks) ?> }); $('cardMsg').textContent = 'saved'; } catch (err) { $('cardMsg').textContent = err.message; } };
// ---- the producer's notes: the game (kit-notes.js, in the iframe) posts them here; they batch in the Build sidebar ----
let NOTES = [];
function noteMode() { const f = $('playFrame'); if (!f.contentWindow) return; f.contentWindow.postMessage({ type: 'forge:notes', on: true }, '*'); f.focus(); }
window.addEventListener('message', async e => {
  const m = e.data || {};
  if (m.type === 'forge:notes-open') { $('playFrame').scrollIntoView({ block: 'center', behavior: 'smooth' }); sendNotesToGame(); return; } // the game is in note mode: keep the whole frame in view, show it the notes so far
  if (m.type !== 'forge:note' || !m.note) return;
  try { const r = await Forge.api(`/game/note/${slug}`, { note: m.note }); NOTES = r.notes; renderNotes(); if (m.note.build) refreshDue = true; } catch (err) { alert('note not saved: ' + err.message); }
});
function sendNotesToGame() { const f = $('playFrame'); if (f.contentWindow) f.contentWindow.postMessage({ type: 'forge:notes-list', notes: NOTES.filter(n => n.state !== 'done').map(n => ({ id: n.id, scene: n.scene, x: n.x, y: n.y, what: n.what, text: n.text, state: n.state, by: n.byName })) }, '*'); }
function renderNotes() {
  sendNotesToGame();
  const filt = $('notesFilter').value, order = { open: 0, sent: 1, done: 2 }, big = NOTES.filter(n => filt === 'all' || n.state === filt).sort((a, b) => (order[a.state] - order[b.state]) || ((b.votes || 0) - (a.votes || 0)) || (b.id - a.id));
  $('notesTab').textContent = NOTES.some(n => n.state === 'open') ? '(' + NOTES.filter(n => n.state === 'open').length + ')' : '';
  $('notesBig').innerHTML = big.length ? big.map(n => `<div class="vl-note-card ${n.state}">
      ${n.shot ? `<img src="/game/noteshot/${slug}?id=${n.id}" alt="" onclick="lightbox(${n.id}, this.src)">` : '<div class="vl-note-noshot">no picture</div>'}
      <div class="vl-note-body"><div class="vl-note-head"><label><input type="checkbox" data-id="${n.id}" ${n.state === 'done' ? 'disabled' : ''} onchange="notesButtons()"> <b>${Forge.esc(n.byName || 'someone')}</b></label><span><button class="vl-vote ${n.voted ? 'on' : ''}" onclick="noteVote(${n.id})" title="${Forge.esc((n.voters || []).join(', ') || 'no votes yet')}">▲ ${n.votes || 0}</button> <span class="vl-state ${n.state}">${n.state === 'sent' ? '✓ sent to build' : n.state === 'done' ? '✓ done' : 'open'}</span></span></div>
        <div class="vl-note-big-text">${Forge.esc(n.text)}</div>
        <div class="vl-note-meta">scene ${Forge.esc(n.scene)} · at (${n.x}, ${n.y})${n.what && n.what.label ? ' · ' + Forge.esc(n.what.label) : ''} · ${Forge.esc((n.createdAt || '').slice(0, 16).replace('T', ' '))}</div>
        ${n.what && n.what.fields ? `<div class="vl-note-fields">${Forge.esc(JSON.stringify(n.what.fields))}</div>` : ''}
        <div class="vl-comments">${(n.comments || []).map(c => `<div class="vl-comment"><b>${Forge.esc(c.by)}</b> ${Forge.esc(c.text)}${c.mine ? ` <a href="#" onclick="noteComment(${n.id}, null, ${c.id}); return false;" title="delete">✕</a>` : ''}</div>`).join('')}
          <form class="vl-comment-form" onsubmit="noteComment(${n.id}, this.text.value); this.text.value = ''; return false;"><input name="text" placeholder="add a comment: refine it, argue with it…" maxlength="4000"><button class="btn btn-sm btn-outline-secondary" type="submit">Comment</button></form></div>
        <div class="vl-note-actions">${n.state !== 'done' ? `<button class="btn btn-sm btn-outline-secondary" onclick="noteState(${n.id}, 'done')">Done</button>` : ''}${n.state !== 'open' ? `<button class="btn btn-sm btn-outline-secondary" onclick="noteState(${n.id}, 'open')">Reopen</button>` : ''}<button class="btn btn-sm btn-outline-secondary" onclick="noteState(${n.id}, 'delete')">Delete</button></div></div></div>`).join('')
    : `<div class="vl-kv">No ${filt === 'all' ? '' : filt + ' '}notes. In Play, press Note mode (or Alt+P in the game), click a thing, say what should change.</div>`;
  notesButtons();
  const open = NOTES.filter(n => n.state === 'open'), rest = NOTES.filter(n => n.state !== 'open').slice(0, 6);
  $('notesTab').textContent = NOTES.some(n => n.state === 'open') ? '(' + NOTES.filter(n => n.state === 'open').length + ')' : '';
  $('notesBig').innerHTML = big.length ? big.map(n => `<div class="vl-note-card ${n.state}">
      ${n.shot ? `<img src="/game/noteshot/${slug}?id=${n.id}" alt="" onclick="lightbox(${n.id}, this.src)">` : '<div class="vl-note-noshot">no picture</div>'}
      <div class="vl-note-body"><div class="vl-note-head"><label><input type="checkbox" data-id="${n.id}" ${n.state === 'done' ? 'disabled' : ''} onchange="notesButtons()"> <b>${Forge.esc(n.byName || 'someone')}</b></label><span><button class="vl-vote ${n.voted ? 'on' : ''}" onclick="noteVote(${n.id})" title="${Forge.esc((n.voters || []).join(', ') || 'no votes yet')}">▲ ${n.votes || 0}</button> <span class="vl-state ${n.state}">${n.state === 'sent' ? '✓ sent to build' : n.state === 'done' ? '✓ done' : 'open'}</span></span></div>
        <div class="vl-note-big-text">${Forge.esc(n.text)}</div>
        <div class="vl-note-meta">scene ${Forge.esc(n.scene)} · at (${n.x}, ${n.y})${n.what && n.what.label ? ' · ' + Forge.esc(n.what.label) : ''} · ${Forge.esc((n.createdAt || '').slice(0, 16).replace('T', ' '))}</div>
        ${n.what && n.what.fields ? `<div class="vl-note-fields">${Forge.esc(JSON.stringify(n.what.fields))}</div>` : ''}
        <div class="vl-comments">${(n.comments || []).map(c => `<div class="vl-comment"><b>${Forge.esc(c.by)}</b> ${Forge.esc(c.text)}${c.mine ? ` <a href="#" onclick="noteComment(${n.id}, null, ${c.id}); return false;" title="delete">✕</a>` : ''}</div>`).join('')}
          <form class="vl-comment-form" onsubmit="noteComment(${n.id}, this.text.value); this.text.value = ''; return false;"><input name="text" placeholder="add a comment: refine it, argue with it…" maxlength="4000"><button class="btn btn-sm btn-outline-secondary" type="submit">Comment</button></form></div>
        <div class="vl-note-actions">${n.state !== 'done' ? `<button class="btn btn-sm btn-outline-secondary" onclick="noteState(${n.id}, 'done')">Done</button>` : ''}${n.state !== 'open' ? `<button class="btn btn-sm btn-outline-secondary" onclick="noteState(${n.id}, 'open')">Reopen</button>` : ''}<button class="btn btn-sm btn-outline-secondary" onclick="noteState(${n.id}, 'delete')">Delete</button></div></div></div>`).join('')
    : `<div class="vl-kv">No ${filt === 'all' ? '' : filt + ' '}notes. In Play, press Note mode (or Alt+P in the game), click a thing, say what should change.</div>`;
  notesButtons();
  const open = NOTES.filter(n => n.state === 'open'), rest = NOTES.filter(n => n.state !== 'open').slice(0, 6);
  const row = n => `<div class="vl-note ${n.state}"><label><input type="checkbox" data-id="${n.id}" ${n.state === 'open' ? 'checked' : n.state === 'done' ? 'disabled' : ''}>${n.shot ? `<img src="/game/noteshot/${slug}?id=${n.id}" alt="">` : ''}<div><div class="vl-note-text">${Forge.esc(n.text)}</div><div class="vl-note-meta">${Forge.esc(n.scene)} · (${n.x}, ${n.y})${n.what && n.what.label ? ' · ' + Forge.esc(n.what.label) : ''} · <span class="vl-state ${n.state}">${n.state === 'sent' ? '✓ sent' : n.state}</span></div></div></label><div class="vl-note-btns">${n.state !== 'done' ? `<button title="done" onclick="noteState(${n.id}, 'done')">✓</button>` : ''}${n.state !== 'open' ? `<button title="reopen" onclick="noteState(${n.id}, 'open')">↺</button>` : ''}<button title="delete" onclick="noteState(${n.id}, 'delete')">✕</button></div></div>`;
  $('notesStrip').innerHTML = open.length ? open.slice(0, 8).map(n => `<span class="vl-chip" title="${Forge.esc(n.text)}">${n.shot ? `<img src="/game/noteshot/${slug}?id=${n.id}" alt="">` : ''}${Forge.esc(n.text.slice(0, 40))}</span>`).join('') + ` <a href="#build" onclick="showTab('build')">${open.length} open note${open.length === 1 ? '' : 's'} → Build</a>` : '';
}
async function noteState(id, state) { if (state === 'delete' && !confirm('Delete this note?')) return; try { NOTES = (await Forge.api(`/game/notestate/${slug}`, { id, state })).notes; renderNotes(); } catch (e) { alert(e.message); } }
let composeIds = [];
async function sendNotes(where) {
  const box = where === 'big' ? '#notesBig' : '#notesBox', intro = where === 'big' ? $('notesIntroBig') : $('notesIntro'); const ids = [...document.querySelectorAll(box + ' input[type=checkbox]:checked')].map(c => +c.dataset.id);
  if (!ids.length) { alert('Check the notes to send first.'); return; }
  if (where !== 'big') { try { const r = await Forge.api(`/game/sendnotes/${slug}`, { ids, text: intro.value.trim() }); NOTES = r.notes; renderNotes(); intro.value = ''; refreshDue = true; showTab('build'); } catch (e) { alert(e.message); } return; }
  try { const r = await Forge.api(`/game/sendpreview/${slug}?ids=${ids.join(',')}&intro=${encodeURIComponent(intro.value.trim())}`); composeIds = ids; $('composeText').value = r.message; $('composeBatch').style.display = ''; $('composeBatch').scrollIntoView({ block: 'start', behavior: 'smooth' }); $('composeText').focus(); } catch (e) { alert(e.message); }
}
async function sendComposed() { try { const r = await Forge.api(`/game/sendnotes/${slug}`, { ids: composeIds, message: $('composeText').value.trim() }); NOTES = r.notes; renderNotes(); $('composeBatch').style.display = 'none'; $('notesIntroBig').value = ''; refreshDue = true; showTab('build'); } catch (e) { $('composeMsg').textContent = e.message; } }
async function noteVote(id) { try { NOTES = (await Forge.api(`/game/notevote/${slug}`, { id })).notes; renderNotes(); } catch (e) { alert(e.message); } }
async function noteComment(id, text, del) { if (!del && !(text || '').trim()) return; try { NOTES = (await Forge.api(`/game/notecomment/${slug}`, del ? { id, delete: del } : { id, text })).notes; renderNotes(); } catch (e) { alert(e.message); } }
function notesAll(on) { for (const c of document.querySelectorAll('#notesBig input[type=checkbox]:not([disabled])')) c.checked = on; notesButtons(); }
function notesButtons() { $('sendNotesBig').disabled = !document.querySelector('#notesBig input[type=checkbox]:checked'); $('sendNotesBig').textContent = 'Send checked to build (' + document.querySelectorAll('#notesBig input[type=checkbox]:checked').length + ')'; }
function lightbox(id, src) { const n = NOTES.find(x => x.id === id), L = $('lightbox'); L.querySelector('img').src = src; L.querySelector('.vl-lightbox-cap').textContent = n ? (n.byName || '') + ': ' + n.text : ''; L.classList.add('on'); }
async function loadNotes() { try { NOTES = (await Forge.api(`/game/notes/${slug}`)).notes; renderNotes(); } catch (e) {} }
loadNotes();
render({ game: G, checkpoints: <?= json_encode($checkpoints) ?>, soaks: <?= json_encode($soaks) ?> });
showTab(location.hash.slice(1) || 'play'); poll();
window.addEventListener('hashchange', () => showTab(location.hash.slice(1) || 'play')); // a link to #config or #build switches the tab without a reload
</script>
