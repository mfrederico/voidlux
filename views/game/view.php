<?php /* One game: play, build (the agent stream), test (soaks), the three editors, the design doc, history, ship.
         The page polls /game/events and /game/status; every action is a JSON POST with the CSRF token. */
$slug = $game['slug']; 
?>
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<div class="vl-gamebar"><span class="vl-pill" id="statusPill"><?= h($game['status']) ?></span><span id="agentStatus" class="vl-pill">agent idle</span>
  <?php if ($game['publishedUrl']): ?><a class="btn btn-sm btn-outline-primary ms-auto" href="<?= h($game['publishedUrl']) ?>" target="_blank">Live</a><?php endif; ?></div>
<div class="vl-tabs" id="tabs">
  <a href="#play" data-tab="play" class="on">Play</a><a href="#build" data-tab="build">Build</a><a href="#notes" data-tab="notes">Notes <span id="notesTab"></span></a><a href="#test" data-tab="test">Test</a><a href="#media" data-tab="media">Media</a><a href="#ship" data-tab="ship">Ship</a>
  <span class="vl-tab-menu"><button data-tab="advanced" type="button">Advanced ▾</button><span class="vl-tab-menu-list"><a href="#design" data-tab="design">Concept</a><a href="#status" data-tab="status">Status</a><a href="#config" data-tab="config">Config</a><a href="#history" data-tab="history">History</a></span></span>
</div>

<section class="vl-pane" id="pane-status">
  <div class="vl-card"><h3 class="vl-h2 mt-0">Fork</h3><div id="forkBox" class="small vl-muted">…</div><p class="vl-muted small">A fork is a private copy of this game with all its history, for a big experiment (a new engine, a new look) that must not touch the original. It has its own builder, notes and playtests; changes can flow either way later.</p><div class="vl-toolbar"><button class="btn btn-sm btn-outline-primary" onclick="forkGame()">Fork this game…</button></div></div>
  <div class="vl-card"><h3 class="vl-h2 mt-0">Owner</h3><p class="vl-muted small">The owner decides the game's team, links it to GitHub, and can hand it on or scrap it. Handing it on keeps everything, history and GitHub link included; you keep access only through the game's team, if it has one.</p><div class="vl-toolbar"><button class="btn btn-sm btn-outline-primary" onclick="transferGame()">Transfer ownership…</button></div></div>
  <div class="vl-card"><h3 class="vl-h2 mt-0">Make this a template</h3><p class="vl-muted small">New games can then be summoned from this one: its code, look and art copied (not its history), its name left for the new game, its design kept as the starting point for the planner. Admins only.</p><div class="vl-toolbar"><button class="btn btn-sm btn-outline-primary" onclick="asTemplate()">Make a template from <?= h($slug) ?>…</button></div></div>
  <div class="vl-card" style="border-color:#4a1a1a"><h3 class="vl-h2 mt-0">Scrap this game</h3><p class="vl-muted small">Its folder and any published copy move into an archive (nothing is erased), its notes go, and it leaves the forge and the shelf. The owner or an admin only.</p><div class="vl-toolbar"><button class="btn btn-sm btn-outline-danger" onclick="scrapGame()">Scrap <?= h($slug) ?>…</button></div></div>
  <div class="vl-toolbar"><span>What the builder is doing: the pipeline stage, the task list from the Concept, and the agent session's running cost.</span></div>
  <div class="vl-cols">
    <div class="vl-card"><h3 class="vl-h2 mt-0">Pipeline</h3><div id="stage" class="vl-kv">idle</div><div class="vl-kv mt-2" id="metaLine"></div></div>
    <div class="vl-card"><h3 class="vl-h2 mt-0">Tasks</h3><ul class="vl-tasks" id="tasks"></ul></div>
    <div class="vl-card"><h3 class="vl-h2 mt-0">Session</h3><div class="vl-kv" id="session"></div><div class="vl-toolbar mt-2"><button class="btn btn-sm btn-outline-secondary" onclick="resetSession()" title="The next run starts with a clean memory and reads the game fresh. Use it when the builder seems stuck on an old idea.">Clear the builder's memory</button></div><div class="vl-kv mt-2 small">Agents sign in on <a href="/agents">AI agents</a>; today: <?= h($agentNote) ?>.</div></div>
  </div>
</section>

<section class="vl-pane" id="pane-media">
  <div class="vl-media-grid">
    <a class="vl-media-card" href="#sfx" onclick="showTab('sfx'); return false;"><div class="vl-media-icon">♪</div><div class="vl-media-title">SFX</div><div class="vl-media-sub">Sound effects for the elements of the game. Every sound the code plays is a slot; design it as layers of swept tones and noise, hear it, save it into the game.</div></a>
    <a class="vl-media-card" href="#tracker" onclick="showTab('tracker'); return false;"><div class="vl-media-icon">▦</div><div class="vl-media-title">Tracker</div><div class="vl-media-sub">Make chiptune music on an 8-track tracker with the game's own synth and drums. Songs save by name (title, play, boss) straight into the game.</div></a>
    <a class="vl-media-card" href="#assets" onclick="showTab('assets'); return false;"><div class="vl-media-icon">◈</div><div class="vl-media-title">Media</div><div class="vl-media-sub">Upload and download SVG and image files for the builder to use. Tell it which, in a note or a message.</div></a>
    <a class="vl-media-card" href="#sprites" onclick="showTab('sprites'); return false;"><div class="vl-media-icon">✦</div><div class="vl-media-title">Sprites</div><div class="vl-media-sub">Draw pixel art and SVG sprites, frame by frame, or have the builder make them with its AI plugins. Saved sprites land in the game's assets and the builder draws them.</div></a>
    <a class="vl-media-card" href="#events" onclick="showTab('events'); return false;"><div class="vl-media-icon">⚡</div><div class="vl-media-title">Events</div><div class="vl-media-sub">How things react: on an event, if conditions hold, do actions. Describe a concept in your words and it becomes rules; see what the game does built in, and make any of it a rule you can change.</div></a>
  </div>
</section>

<section class="vl-pane" id="pane-assets">
  <div class="vl-toolbar"><a href="#media" onclick="showTab('media'); return false;">← Media</a><span>The game's picture files. Tell the builder which to use, in a note or a message.</span></div>
  <form class="vl-toolbar" method="post" action="/game/assetupload/<?= h($slug) ?>" enctype="multipart/form-data"><?= csrf_field() ?><input type="file" name="file" accept=".svg,.png,.jpg,.jpeg,.gif,.webp" required><button class="btn btn-sm btn-primary" type="submit">Upload</button><span>SVG, PNG, JPG, GIF or WebP, up to 5 MB.</span></form>
  <div class="vl-assets" id="assets"></div>
</section>

<section class="vl-pane on" id="pane-play">
  <div class="vl-toolbar"><button class="btn btn-sm btn-outline-secondary" onclick="reloadPlay()">Reload</button><button class="btn btn-sm btn-outline-secondary" onclick="playWith('fps=1')">Profiler</button><button class="btn btn-sm btn-outline-secondary" onclick="playWith('bot=1')">Autopilot</button><button class="btn btn-sm btn-primary" onclick="noteMode()" id="noteBtn" title="pause the game and point at what should change (Alt+P inside the game)">Note mode</button><a class="btn btn-sm btn-outline-secondary" href="/games/<?= h($slug) ?>/" target="_blank" onclick="playTab = window.open(this.href, 'play-<?= h($slug) ?>'); return false;" title="full screen in its own tab; notes made there still reach this page (on a phone: a three-finger tap opens note mode)">Play in a tab</a></div>
  <iframe class="vl-frame" id="playFrame" allow="autoplay; fullscreen"></iframe>
  <div class="vl-notes-strip" id="notesStrip"></div>
  <form class="vl-compose vl-compose-play" data-chat><textarea placeholder="Tell the builder what to do…"></textarea><div class="btns"><button type="submit" class="btn btn-primary">Send</button></div></form>
</section>

<section class="vl-pane" id="pane-build">
  <div class="vl-build">
    <div>
      <div class="vl-toolbar">
        <button class="btn btn-sm btn-primary" onclick="startAgent('pipeline')" id="btnPipeline" title="The whole loop: plan what the Concept still lacks, build every open task, playtest, review against the acceptance criteria, rework what fails, checkpoint along the way.">Build the game</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="reviewAgain()" title="An independent reviewer (a fresh agent that never saw the build) checks every acceptance criterion in the Concept, with evidence, and reports pass or fail. Builds nothing.">Review</button>
        <button class="btn btn-sm btn-danger" onclick="stopAgent()" title="Stops the agent that is running now. Whatever it had committed stays; a checkpoint marks where it stopped.">Stop</button>
        <span id="streamNote"><?= h($agentNote) ?></span>
      </div>
      <div class="vl-stageline" id="stageline"></div>
      <div class="vl-stream" id="stream"></div>
      <form class="vl-compose" id="chatForm" data-chat>
        <textarea id="chatText" data-ph="Tell the builder what to do: 'make the enemies spawn in rings', 'add a boss every fifth wave', 'the ship feels sluggish, tighten it'. Each message is one agent turn in the game's folder, checkpointed after." placeholder="Tell the builder what to do: 'make the enemies spawn in rings', 'add a boss every fifth wave', 'the ship feels sluggish, tighten it'. Each message is one agent turn in the game's folder, checkpointed after."></textarea>
        <div class="btns"><button type="submit" class="btn btn-primary" id="chatBtn">Send</button></div>
      </form>
    </div>
  </div>
</section>

<section class="vl-pane" id="pane-test">
  <div class="vl-card mb-3">
    <div class="vl-kv"><span class="vl-pill live" id="attachedPill">● this browser is attached</span> While this page is open, playtests for <b><?= h($game['title']) ?></b> run here: a small game window pops up in the corner, the bots play, every error is caught, and the report goes to the builder. Any computer, any browser, nothing to install. With nobody's page open, a playtest waits a minute and then reports that no browser was attached.</div>
    <div class="vl-kv mt-2"><b>Playtests run in this browser:</b> <span id="hereCount">0</span> · last: <span id="hereLast">none yet</span></div>
  </div>
  <div class="vl-toolbar">
    <label>game-seconds <input type="number" id="soakSecs" value="60" min="5" max="1800" style="width:80px"></label>
    <label>speed <input type="number" id="soakFast" value="4" min="1" max="30" style="width:60px" title="game updates per drawn frame: 4 plays four game-seconds in one real second"></label>
    <button class="btn btn-sm btn-primary" onclick="runSoak()" id="soakBtn" title="the bots play for this many game-seconds; the report lands below and on the Build timeline">Run a playtest</button>
    <button class="btn btn-sm btn-outline-primary" onclick="startAgent('fix')" title="a playtest, and if anything breaks the builder is asked to fix it and test again">Playtest &amp; fix</button>
  </div>
  <div class="vl-scroll"><table class="vl-table"><thead><tr><th>when</th><th>result</th><th>played</th><th>fps</th><th>errors</th><th>scenes</th><th>score</th><th></th></tr></thead><tbody id="soaks"></tbody></table></div>
  <div id="soakDetail"></div>
</section>

<section class="vl-pane" id="pane-notes">
  <div class="vl-toolbar"><span>Producer's notes: everything anyone pointed at in note mode (Play → Note mode, or Alt+P in the game). Check the ones to hand to the builder in one batch.</span></div>
  <details class="vl-card mb-3 vl-newnote"><summary class="vl-h2 mt-0" style="cursor:pointer">✎ New note <span class="vl-meta">(no need to run the game)</span></summary>
    <form id="newNoteForm" class="vl-form mt-2" enctype="multipart/form-data">
      <label>The note <textarea name="text" rows="3" placeholder="What should change, be added or be checked? A picture helps: a sketch, a reference, a screenshot." required maxlength="4000"></textarea></label>
      <div class="vl-toolbar"><label style="margin:0">Picture (optional) <input type="file" name="picture" accept=".svg,.png,.jpg,.jpeg,.gif,.webp"></label><label class="vl-check"><input type="checkbox" name="to_assets" value="1"> also add it to Media for the builder to use</label></div>
      <div class="vl-toolbar"><button type="submit" class="btn btn-sm btn-primary">Save note</button><span class="vl-msg" id="newNoteMsg"></span></div>
    </form>
  </details>
  <div class="vl-folders" id="notesFolders"><button data-folder="open" class="on">Awaiting Verdict <span></span></button><button data-folder="sent">Sent to build <span></span></button><button data-folder="done">Scrapyard <span></span></button></div>
  <div class="vl-toolbar"><label class="vl-check"><input type="checkbox" id="notesAll" onchange="notesAll(this.checked)"> check all</label><input id="notesIntroBig" placeholder="a word for the builder (optional)" style="flex:1;min-width:160px"><button class="btn btn-sm btn-primary" onclick="sendNotes('big')" id="sendNotesBig">Send checked to build</button></div>
  <div class="vl-compose-batch" id="composeBatch" style="display:none"><h3 class="vl-h2 mt-0">What the builder will read</h3><p class="vl-kv">Composed from the checked notes, their votes and comments. Change any of it before it goes.</p><textarea id="composeText" spellcheck="false"></textarea><div class="vl-toolbar mt-2"><button class="btn btn-sm btn-primary" onclick="sendComposed()">Send to the builder</button><button class="btn btn-sm btn-outline-secondary" onclick="$('composeBatch').style.display='none'">Cancel</button><span id="composeMsg"></span></div></div>
  <div class="vl-notes-big" id="notesBig"></div>
</section>
<div class="vl-lightbox" id="lightbox" onclick="this.classList.remove('on')"><img alt=""><div class="vl-lightbox-cap"></div></div>
<section class="vl-pane" id="pane-sfx"><div class="vl-toolbar"><a href="#media" onclick="showTab('media'); return false;">← Media</a><span>Sound effects. Save writes them into the game; reload the game to hear them.</span><a href="/editor/sfx/?game=<?= h($slug) ?>" target="_blank">open in a tab</a></div><iframe class="vl-frame tall" id="sfxFrame"></iframe></section>
<section class="vl-pane" id="pane-sprites"><div class="vl-toolbar"><a href="#media" onclick="showTab('media'); return false;">← Media</a><span>Sprites. Save writes the picture into the game's assets and its manifest; the game draws it with Draw.pic. The AI panel asks the builder.<?php if (is_file(\app\Cast::path($slug))): ?> The 3D tab holds the game's 3D models: describe a change to them there.<?php endif; ?></span><a href="/editor/sprites/?game=<?= h($slug) ?>" target="_blank">open in a tab</a></div><iframe class="vl-frame tall" id="spritesFrame"></iframe></section>
<section class="vl-pane" id="pane-events"><div class="vl-toolbar"><a href="#media" onclick="showTab('media'); return false;">← Media</a><span>Events: the rules for how things react, and what the game does built in. Rules take effect the next time the game starts.</span><a href="/editor/sprites/?game=<?= h($slug) ?>#events" target="_blank">open in a tab</a></div><iframe class="vl-frame tall" id="eventsFrame"></iframe></section>
<section class="vl-pane" id="pane-tracker"><div class="vl-toolbar"><a href="#media" onclick="showTab('media'); return false;">← Media</a><span>The chip tracker. Press / to load or save a song into the game; name songs after where they play (title, play, boss).</span><a href="/editor/tracker/?game=<?= h($slug) ?>" target="_blank">open in a tab</a></div><iframe class="vl-frame tall" id="trackerFrame"></iframe></section>
<section class="vl-pane" id="pane-config"><div class="vl-toolbar"><span>Every tuning number in the game, with its comment. Changes apply when the game reloads.</span><a href="/editor/config/?game=<?= h($slug) ?>" target="_blank">open in a tab</a></div><iframe class="vl-frame tall" id="configFrame"></iframe></section>

<section class="vl-pane" id="pane-design">
  <div class="vl-toolbar"><button class="btn btn-sm btn-outline-secondary" onclick="toggleDocEdit()" id="docEditBtn">Edit</button><button class="btn btn-sm btn-primary" onclick="saveDoc()" id="docSaveBtn" style="display:none">Save GAME.md</button><button class="btn btn-sm btn-outline-secondary" onclick="loadDoc()">Reload</button><span id="docMsg">The game's concept: brief, design, acceptance criteria, tasks and log, as the planner and the builders keep it in GAME.md.</span></div>
  <article class="vl-md" id="docHtml"><?= \app\Game::md($gameMd) ?></article>
  <textarea class="vl-doc" id="doc" spellcheck="false" style="display:none"><?= h($gameMd) ?></textarea>
</section>

<section class="vl-pane" id="pane-history">
  <div class="vl-toolbar"><input id="cpLabel" placeholder="what this checkpoint is" style="width:320px"><button class="btn btn-sm btn-primary" onclick="makeCheckpoint()">Checkpoint now</button><span>Every agent run is bracketed by a tag. Rolling back makes a checkpoint of the current state first.</span></div>
  <table class="vl-table"><thead><tr><th>tag</th><th>when</th><th>label</th><th></th></tr></thead><tbody id="cps"></tbody></table>
</section>

<section class="vl-pane" id="pane-ship">
  <div class="vl-cols">
    <div class="vl-card"><h3 class="vl-h2 mt-0">Build &amp; publish</h3><div class="vl-kv" id="shipInfo"></div><div class="vl-toolbar mt-2"><button class="btn btn-sm btn-primary" onclick="publish()" id="pubBtn">Build and publish</button></div><div class="vl-msg" id="pubMsg"></div></div>
    <div class="vl-card"><h3 class="vl-h2 mt-0">GitHub</h3><div id="gitBox" class="vl-kv">…</div><div class="vl-msg" id="gitMsg"></div></div>
    <div class="vl-card"><h3 class="vl-h2 mt-0">Store card</h3>
      <form class="vl-form" id="cardForm">
        <label>Title <input name="title"></label>
        <label>Status <select name="status"><option>new</option><option>designed</option><option>building</option><option>playable</option><option>polished</option><option>published</option></select></label>
        <label>Price (USD) <input name="price" type="number" step="0.01" min="0" placeholder="2.99"></label>
        <label class="vl-check"><input name="is_public" type="checkbox"> Public: listed on voidlux.com for anyone to play, once published</label>
        <label>Tags <input name="tags" placeholder="arcade, shooter, mobile"></label>
        <label>Team <select name="team_id"><option value="0">Just me</option><?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?></select></label>
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
const HAS_CAST = <?= json_encode(is_file(\app\Cast::path($slug))) ?>; // a 3D game: its sprites open on the 3D cast
const EDITOR_V = <?= json_encode(array_combine(['sfx', 'tracker', 'sprites', 'config'], array_map(fn($e) => @filemtime(\app\GameStore::root() . "/kit/editor/{$e}/index.html") ?: 0, ['sfx', 'tracker', 'sprites', 'config']))) ?>;
const GROUP = { sfx: 'media', tracker: 'media', sprites: 'media', events: 'media', assets: 'media', design: 'advanced', status: 'advanced', config: 'advanced', history: 'advanced' };
function askBuilder(text) { showTab('build'); const ta = $('chatForm').querySelector('textarea'); ta.value = text; ta.focus(); const i = text.indexOf('…'); if (i >= 0) ta.setSelectionRange(i, i + 1); }
function showTab(name) {
  if (name === 'advanced') { document.querySelector('.vl-tab-menu').classList.toggle('open'); return; }
  document.querySelector('.vl-tab-menu').classList.remove('open');
  for (const b of document.querySelectorAll('.vl-tabs [data-tab]')) b.classList.toggle('on', b.dataset.tab === name || b.dataset.tab === GROUP[name]);
  for (const p of document.querySelectorAll('.vl-pane')) p.classList.toggle('on', p.id === 'pane-' + name);
  location.hash = name;
  if (name === 'play' && !loaded.play) { loaded.play = 1; $('playFrame').src = `/games/${slug}/`; }
  if (name === 'events' && !loaded.events) { loaded.events = 1; $('eventsFrame').src = `/editor/sprites/?game=${slug}&v=${EDITOR_V.sprites || 0}#events`; } // the sprite page, on its Events tab
  if (['sfx', 'tracker', 'sprites', 'config'].includes(name) && !loaded[name]) { loaded[name] = 1; $(name + 'Frame').src = `/editor/${name}/?game=${slug}&v=${EDITOR_V[name] || 0}` + (name === 'sprites' && HAS_CAST ? '#3d' : ''); } // v: the editor file's mtime, so a changed editor is never the browser's stale copy
  if (name === 'design') loadDoc();
  if (name === 'build') { const b = $('stream'); b.scrollTop = b.scrollHeight; }
  if (name === 'notes') loadNotes();
  if (name === 'ship') gitLoad();
  if (name === 'status') forkLoad();
  if (name === 'assets') loadAssets(); // the timeline opens on the newest event
}
$('tabs').onclick = e => { const b = e.target.closest('[data-tab]'); if (!b) return; e.stopPropagation(); if (b.tagName === 'A' && (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1)) return; /* a new tab or window: the browser's business */ e.preventDefault(); showTab(b.dataset.tab); };
document.addEventListener('click', () => document.querySelector('.vl-tab-menu').classList.remove('open')); // a click anywhere else closes the Advanced menu
async function loadAssets() { try { const r = await Forge.api(`/game/assets/${slug}`); $('assets').innerHTML = r.files.length ? r.files.map(f => `<div class="vl-asset"><a href="/games/${slug}/assets/${encodeURIComponent(f.name)}" target="_blank">${/\.(svg|png|jpe?g|gif|webp)$/i.test(f.name) ? `<img src="/games/${slug}/assets/${encodeURIComponent(f.name)}" alt="">` : ''}</a><div class="vl-asset-name">${Forge.esc(f.name)}</div><div class="vl-note-meta">${Math.round(f.size / 1024)} KB</div><div class="vl-note-actions"><a class="btn btn-sm btn-outline-secondary" href="/games/${slug}/assets/${encodeURIComponent(f.name)}" download>Download</a><button class="btn btn-sm btn-outline-secondary" onclick="deleteAsset('${Forge.esc(f.name)}')">Delete</button></div></div>`).join('') : '<div class="vl-kv">No files yet. Upload an SVG or an image above.</div>'; } catch (e) { $('assets').innerHTML = '<div class="vl-kv">' + Forge.esc(e.message) + '</div>'; } }
async function deleteAsset(name) { if (!confirm('Delete ' + name + '?')) return; try { await Forge.api(`/game/assetdelete/${slug}`, { name }); loadAssets(); } catch (e) { alert(e.message); } }
function reloadPlay() { $('playFrame').src = `/games/${slug}/?r=${Date.now()}`; }
function playWith(q) { $('playFrame').src = `/games/${slug}/?${q}&r=${Date.now()}`; }
function render(d) {
  G = d.game; $('statusPill').textContent = G.status; $('metaLine').textContent = `${G.template} template · ${G.checkpoints} checkpoints · last commit ${G.lastCommit || 'none'}`;
  renderTasks(G.taskList || []); renderSession(G.agent); renderCps(d.checkpoints); renderSoaks(d.soaks);
  const f = $('cardForm'); if (document.activeElement && f.contains(document.activeElement)) {} else { f.title.value = G.title || ''; f.status.value = G.status || 'new'; f.price.value = G.price == null ? '' : G.price; f.is_public.checked = !!G.isPublic; f.tags.value = (G.tags || []).join(', '); f.notes.value = G.notes || ''; f.team_id.value = String(G.teamId || 0); }
  $('shipInfo').innerHTML = `<b>last commit</b> ${Forge.esc(G.lastCommit || 'none')}<br><b>published</b> ${G.publishedAt ? Forge.esc(G.publishedAt) + (G.publishedUrl ? ' · <a href="' + Forge.esc(G.publishedUrl) + '" target="_blank">' + Forge.esc(G.publishedUrl) + '</a>' : ' · dist/ only') : 'never'}<br><b>last soak</b> ${G.lastSoak ? (G.lastSoak.ok ? '<span class="ok">ok</span>' : '<span class="bad">failing</span>') : 'none'}`;
}
function renderTasks(list) { $('tasks').innerHTML = list.length ? list.map(t => `<li class="${t.done ? 'done' : ''}">${t.done ? '✓' : '○'} ${Forge.esc(t.id)}: ${Forge.esc(t.text)}</li>`).join('') : '<li>no plan yet: run the pipeline</li>'; }
function renderSession(a) { a = a || {}; G.agent = a; $('session').innerHTML = `<b>${a.busy ? 'running ' + Forge.esc(a.mode) : 'idle'}</b><br>runs ${a.runs || 0} · spent $${(+a.cost || 0).toFixed(2)}<br>session ${a.session ? Forge.esc(String(a.session).slice(0, 8)) : 'none yet'}`; $('agentStatus').textContent = a.busy ? 'agent: ' + a.mode : 'agent idle'; $('agentStatus').className = 'vl-pill' + (a.busy ? ' live' : ''); $('btnPipeline').disabled = !!a.busy; for (const f of document.querySelectorAll('form[data-chat]')) f.querySelector('button[type=submit]').textContent = a.busy ? 'Queue' : 'Send'; $('chatText').placeholder = a.busy ? 'The agent is busy: a message now waits in its inbox and goes with the next run (the next task, fix or rework).' : $('chatText').dataset.ph; }
function renderCps(list) { $('cps').innerHTML = (list || []).map(c => `<tr><td>${Forge.esc(c.tag)}</td><td>${Forge.esc(c.at.slice(0, 16))}</td><td>${Forge.esc(c.label)}</td><td><button class="btn btn-sm btn-outline-secondary" onclick="rollback('${Forge.esc(c.tag)}')">roll back</button></td></tr>`).join('') || '<tr><td colspan="4">no checkpoints</td></tr>'; }
function renderSoaks(list) { $('soaks').innerHTML = (list || []).map(s => `<tr><td>${Forge.esc(s.id.replace(/^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2}).*$/, '$2/$3 $4:$5'))} <span class="vl-note-meta">${s.runner === 'browser' ? 'in a browser' : s.runner === 'none' ? 'no browser' : 'server'}</span></td><td>${s.ok ? '<b class="ok">ok</b>' : '<b class="bad">' + Forge.esc(s.status || 'fail') + '</b>'}</td><td>${s.seconds || ''} s</td><td>${s.fps || ''}</td><td>${s.errors || 0}</td><td>${Forge.esc(Object.entries(s.scenes || {}).map(([k, v]) => k + ' ' + v + 's').join(', '))}</td><td>${s.score == null ? '' : s.score}</td><td><button class="btn btn-sm btn-outline-secondary" onclick="soakDetail('${Forge.esc(s.id)}')">detail</button></td></tr>`).join('') || '<tr><td colspan="8">no soaks yet</td></tr>'; }
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
  if (ev.kind === 'notes') text = (ev.missing && ev.missing.length ? '⚠ ' : '✓ ') + (ev.text || '') + (ev.answers && Object.keys(ev.answers).length ? '\n' + Object.entries(ev.answers).map(([n, a]) => `  ${n}. ${a}`).join('\n') : '');
  if (ev.kind === 'artdone') text = '◆ sprite job ' + (ev.text || 'finished') + ' (the art lane, beside the builder)';
  if (ev.kind === 'prompt') { d.classList.add('you'); d.innerHTML = `<time>${Forge.time(ev.t)}</time><div class="vl-you-head">${Forge.esc(ev.by || 'you')} · ${Forge.esc({ chat: 'to the builder', pipeline: 'the brief for a build', inbox: 'to the builder, for its next run', art: 'to the artist' }[ev.mode] || ev.mode || '')}</div><div class="vl-you-text">${Forge.esc(ev.text || '')}</div>`; const stick0 = box.scrollTop + box.clientHeight >= box.scrollHeight - 40; box.appendChild(d); if (stick0) box.scrollTop = box.scrollHeight; return; }
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
    if (r.shotRequest && !shotRunning) runBrowserShot(r.shotRequest);
    if (refreshDue) { refreshDue = false; render(await Forge.api(`/game/status/${slug}`)); }
    $('streamNote').textContent = ''; $('attachedPill').className = 'vl-pill live'; $('attachedPill').textContent = '● this browser is attached';
  } catch (e) { $('streamNote').textContent = 'polling: ' + e.message; $('attachedPill').className = 'vl-pill'; $('attachedPill').textContent = '○ not attached (reconnecting)'; }
  pollT = setTimeout(poll, document.hidden ? 8000 : (G.agent && G.agent.busy ? 1500 : 5000));
}
// ---- playtests in this browser: the server hands a pending request to whoever polls first; the game runs in a hidden
// frame here with its bots, and the report (window.__SOAK, same origin) goes back. The server's Chrome is the fallback ----
let soakRunning = false, hereCount = 0, shotRunning = false;
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
  } catch (e) { $('streamNote').textContent = 'shot: ' + e.message; } finally { shotRunning = false; if (win) setTimeout(() => win.remove(), 2500); }
}
async function runBrowserSoak(req) {
  soakRunning = true;
  try {
    const c = await Forge.api(`/game/soakclaim/${slug}`, { id: req.id }); if (!c.claimed) return;
    $('streamNote').textContent = `playtest ${req.id} running in this browser (${req.secs} s at ${req.fast}x)…`;
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
    hereCount++; $('hereCount').textContent = hereCount; $('hereLast').textContent = new Date().toLocaleTimeString() + (rep.ok ? ' · ok' : ' · ' + (rep.status || 'failed'));
    $('streamNote').textContent = ''; refreshDue = true;
  } catch (e) { $('streamNote').textContent = 'playtest here failed: ' + e.message; }
  finally { soakRunning = false; }
}
// Review: if this exact version was reviewed already (no commit since, nothing uncommitted), say so first
async function reviewAgain() {
  try { const r = await Forge.api(`/game/status/${slug}`); G = r.game; } catch (e) {}
  const L = G.lastReview; if (!L || L.sha !== G.head || G.dirty) return startAgent('review');
  const when = new Date(L.at).toLocaleString(), how = L.pass ? 'it passed every criterion' : `it failed ${L.failures} criteri${L.failures === 1 ? 'on' : 'a'}`;
  const box = document.createElement('div'); box.className = 'vl-ask';
  box.innerHTML = `<div class="vl-ask-card"><h3 class="vl-h2 mt-0">Already reviewed</h3><p>This version <code>${Forge.esc(G.head)}</code> has been reviewed (${Forge.esc(when)}): ${how}. Nothing has changed since.</p><p class="vl-muted small">A new review costs a run and should come out the same. Continue with the review anyway?</p><div class="vl-toolbar"><button class="btn btn-sm btn-primary" data-go>CONTINUE</button><button class="btn btn-sm btn-outline-secondary" data-no>CANCEL</button></div></div>`;
  const close = () => box.remove(); box.querySelector('[data-no]').onclick = close; box.addEventListener('click', e => { if (e.target === box) close(); }); box.querySelector('[data-go]').onclick = () => { close(); startAgent('review'); };
  document.body.appendChild(box); box.querySelector('[data-no]').focus(); document.addEventListener('keydown', function esc(e) { if (e.key === 'Escape') { close(); document.removeEventListener('keydown', esc); } });
}
// this page is the browser that runs the playtests: while a playtest runs here, or a run that playtests is going
// (pipeline, review, fix), leaving the page asks first, since closing it leaves the playtest with no browser
window.addEventListener('beforeunload', e => { const a = G.agent || {}; if (soakRunning || (a.busy && ['pipeline', 'review', 'fix', 'soak'].includes(a.mode))) { e.preventDefault(); e.returnValue = ''; } });
async function startAgent(mode, prompt) { try { renderSession(await Forge.api(`/game/agent/${slug}`, { mode, prompt: prompt || '' })); showTab('build'); if (['pipeline', 'review', 'fix', 'soak'].includes(mode)) $('streamNote').textContent = 'Keep this page open until the run ends: its playtests run in this tab (it can sit behind other tabs).'; } catch (e) { alert(e.message); } }
async function stopAgent() { try { await Forge.api(`/game/stop/${slug}`, {}); } catch (e) { alert(e.message); } }
async function gitLoad() { const box = $('gitBox'); let r; try { r = await Forge.api(`/game/gitstatus/${slug}`); } catch (e) { box.textContent = e.message; return; }
  if (r.link) { const L = r.link; box.innerHTML = `<b>linked</b> <a href="https://github.com/${Forge.esc(L.repo)}/tree/${Forge.esc(L.branch || 'main')}" target="_blank">${Forge.esc(L.repo)}</a> on <code>${Forge.esc(L.branch || 'main')}</code> ${L.private ? '(private)' : '(public)'}<br><span class="vl-muted small">its own deploy key; every publish pushes, whoever publishes${L.last_push ? '<br>last push: ' + Forge.esc(L.last_push.slice(0, 16).replace('T', ' ')) + ' · ' + Forge.esc(L.last_push_text || '') : ''}</span><div class="vl-toolbar mt-2"><button class="btn btn-sm btn-outline-primary" onclick="gitPush()">Push now</button>${r.canLink ? '<button class="btn btn-sm btn-outline-danger" onclick="gitUnlink()">Unlink…</button>' : ''}</div>`; return; }
  if (!r.canLink) { box.innerHTML = '<span class="vl-muted small">Not linked to GitHub. The game\'s owner or an admin can link it; after that, anyone on the game pushes when they publish.</span>'; return; }
  if (r.connection !== 'ok') { box.innerHTML = `<span class="vl-muted small">The forge is not signed in to GitHub yet. <a href="/forge/github">Sign it in</a> (an admin, once); then link this game here.</span>`; return; }
  box.innerHTML = `<div class="vl-muted small">The forge's GitHub account is <b>${Forge.esc(r.account)}</b>. Linking gives this game its own key for one repository; teammates do not need GitHub accounts.</div><form class="vl-form mt-2" id="gitForm"><label>Repository <input name="repo" value="${Forge.esc(r.account)}/${Forge.esc(slug)}"></label><label class="vl-check"><input type="checkbox" name="create" checked> make it if it does not exist yet (an existing one is linked as it is)</label><label>Branch <input name="branch" placeholder="main (a fork: its own name)"></label><label class="vl-check"><input type="checkbox" name="private" checked> private</label><button class="btn btn-sm btn-primary" type="submit">Link to GitHub</button></form>`;
  $('gitForm').onsubmit = async e => { e.preventDefault(); const f = e.target; $('gitMsg').textContent = 'linking, then pushing the history…'; try { const x = await Forge.api(`/game/gitlink/${slug}`, { repo: f.repo.value.trim(), branch: f.branch.value.trim(), create: f.create.checked ? 1 : 0, private: f.private.checked ? 1 : 0 }); $('gitMsg').textContent = x.push && x.push.text ? x.push.text : 'linked'; gitLoad(); } catch (err) { $('gitMsg').textContent = err.message; } }; }
async function gitPush() { $('gitMsg').textContent = 'pushing…'; try { const r = await Forge.api(`/game/gitpush/${slug}`, {}); $('gitMsg').textContent = r.text; gitLoad(); } catch (e) { $('gitMsg').textContent = e.message; } }
async function gitUnlink() { if (!confirm('Unlink this game from GitHub? Its key is removed from the repository; the repository itself stays as it is.')) return; try { const r = await Forge.api(`/game/gitunlink/${slug}`, {}); $('gitMsg').textContent = 'unlinked' + (r.note ? ': ' + r.note : ''); gitLoad(); } catch (e) { $('gitMsg').textContent = e.message; } }
async function forkGame() {
  const s = prompt('Slug for the fork (lowercase, dashes):', `${slug}-fork`); if (!s) return; const t = prompt('Its title:', `${G.title} (fork)`); if (t === null) return;
  const goal = prompt('What is the fork for? (this leads its GAME.md and steers the planner)\ne.g. "Convert to three.js as an isometric game; above-ground play becomes 3D; the dungeons stay 2D for now"', ''); if (!goal) return;
  try { const r = await Forge.api(`/game/fork/${slug}`, { slug: s.trim(), title: t.trim(), goal: goal.trim() }); location.href = r.url + '#status'; } catch (e) { alert('Not forked: ' + e.message); } }
async function forkLoad() { const box = $('forkBox'); try { const r = await Forge.api(`/game/upstream/${slug}`); const u = r.upstream; if (!u) { box.textContent = 'This game is not a fork.'; return; }
  box.innerHTML = `A fork of <a href="/game/view/${Forge.esc(u.slug)}">${Forge.esc(u.slug)}</a>: <b>${u.ahead}</b> commit(s) here that it lacks, <b>${u.behind}</b> there that this lacks.<div class="vl-toolbar mt-2"><button class="btn btn-sm btn-outline-primary" onclick="forkPull()" ${u.behind ? '' : 'disabled'}>Pull from ${Forge.esc(u.slug)}</button><button class="btn btn-sm btn-outline-primary" onclick="forkOffer()" ${u.ahead ? '' : 'disabled'}>Offer back to ${Forge.esc(u.slug)}</button></div>`; } catch (e) { box.textContent = e.message; } }
async function forkPull() { try { const r = await Forge.api(`/game/pullupstream/${slug}`, {}); alert(r.text); forkLoad(); } catch (e) { alert(e.message); } }
async function forkOffer() { if (!confirm('Offer this fork\'s work back as a branch in the original? Nothing in the original changes until someone merges it.')) return; try { const r = await Forge.api(`/game/proposeback/${slug}`, {}); alert(r.text); forkLoad(); } catch (e) { alert(e.message); } }
async function transferGame() { const to = prompt('Hand this game to which member? Their email or username:', ''); if (!to) return; if (!confirm(`Make ${to} the owner of ${G.title}?`)) return; try { const r = await Forge.api(`/game/transfer/${slug}`, { to: to.trim() }); alert(`${r.owner} owns it now.` + (r.team ? ' You keep access through its team.' : ' It has no team, so you no longer have access unless you are an admin.')); location.reload(); } catch (e) { alert('Not transferred: ' + e.message); } }
async function asTemplate() { const name = prompt('Name for the template (lowercase, dashes):', slug.replace(/[^a-z0-9-]/g, '') + '-template'); if (!name) return; const label = prompt('One line for the summon page (what a new game starts from):', `${G.title} — `) || ''; try { const r = await Forge.api(`/game/astemplate/${slug}`, { name, label }); alert(`Template "${r.template}" made. It is in the list when you summon a game.`); } catch (e) { alert('Not made: ' + e.message); } }
async function scrapGame() { const typed = prompt(`Scrap this game? Its folder and any published copy are archived, not erased, but it leaves the forge.\n\nType its slug to confirm:`, ''); if (typed === null) return; if (typed.trim() !== slug) { alert('That is not this game\'s slug; nothing was done.'); return; } try { const r = await Forge.api(`/game/scrap/${slug}`, { confirm: slug }); alert('Scrapped. Archived at: ' + (r.archived || []).join(' and ')); location.href = '/forge'; } catch (e) { alert('Not scrapped: ' + e.message); } }
async function resetSession() { if (!confirm('Clear the builder\'s memory? Nothing in the game changes; the next run just starts without the conversation so far.')) return; renderSession(await Forge.api(`/game/reset/${slug}`, {})); }
for (const f of document.querySelectorAll('form[data-chat]')) f.onsubmit = async e => { e.preventDefault(); const ta = f.querySelector('textarea'), t = ta.value.trim(); if (!t) return; try { const r = await Forge.api(`/game/agent/${slug}`, { mode: 'chat', prompt: t }); renderSession(r); ta.value = ''; showTab('build'); } catch (err) { alert(err.message); } };
async function runSoak() { $('soakBtn').disabled = true; try { await Forge.api(`/game/agent/${slug}`, { mode: 'soak', prompt: `${+$('soakSecs').value} ${+$('soakFast').value}` }); showTab('build'); } catch (e) { alert(e.message); } $('soakBtn').disabled = false; }
async function makeCheckpoint() { const r = await Forge.api(`/game/checkpoint/${slug}`, { label: $('cpLabel').value }); $('cpLabel').value = ''; renderCps(r.checkpoints); }
async function rollback(tag) { if (!confirm(`Roll ${slug} back to ${tag}? The current state is checkpointed first.`)) return; try { const r = await Forge.api(`/game/rollback/${slug}`, { ref: tag }); renderCps(r.checkpoints); reloadPlay(); refreshDue = true; } catch (e) { alert(e.message); } }
let docEditing = false;
function toggleDocEdit() { docEditing = !docEditing; $('doc').style.display = docEditing ? '' : 'none'; $('docHtml').style.display = docEditing ? 'none' : ''; $('docSaveBtn').style.display = docEditing ? '' : 'none'; $('docEditBtn').textContent = docEditing ? 'View' : 'Edit'; }
async function loadDoc() { const r = await Forge.api(`/game/doc/${slug}`); $('doc').value = r.text; $('docHtml').innerHTML = r.html; $('docMsg').textContent = 'loaded'; }
async function saveDoc() { await Forge.api(`/game/doc/${slug}`, { text: $('doc').value }); $('docMsg').textContent = 'saved · ' + new Date().toLocaleTimeString(); refreshDue = true; await loadDoc(); if (docEditing) toggleDocEdit(); }
async function publish() { $('pubBtn').disabled = true; $('pubMsg').textContent = 'building…'; try { const r = await Forge.api(`/game/publish/${slug}`, {}); $('pubMsg').innerHTML = `build ${Forge.esc(r.build)}` + (r.url ? ` · live at <a href="${Forge.esc(r.url)}" target="_blank">${Forge.esc(r.url)}</a>` : ' · dist/ only (no [forge] domain)'); refreshDue = true; } catch (e) { $('pubMsg').textContent = e.message; } $('pubBtn').disabled = false; }
$('cardForm').onsubmit = async e => { e.preventDefault(); const f = e.target; try { render({ game: await Forge.api(`/game/card/${slug}`, { title: f.title.value, status: f.status.value, price: f.price.value === '' ? null : +f.price.value, is_public: f.is_public.checked ? 1 : 0, tags: f.tags.value.split(',').map(s => s.trim()).filter(Boolean), notes: f.notes.value, team_id: +f.team_id.value }), checkpoints: <?= json_encode($checkpoints) ?>, soaks: <?= json_encode($soaks) ?> }); $('cardMsg').textContent = 'saved'; } catch (err) { $('cardMsg').textContent = err.message; } };
// ---- the producer's notes: the game (kit-notes.js, in the iframe) posts them here; they batch in the Build sidebar ----
let NOTES = [], notesFolder = 'open';
const FOLDER = { open: 'awaiting verdict', sent: '✓ sent to build', done: 'scrapyard' };
document.getElementById('notesFolders').onclick = e => { const b = e.target.closest('button'); if (b) { notesFolder = b.dataset.folder; renderNotes(); } };
let playTab = null;
function gameWindows() { const w = []; try { if (playTab && !playTab.closed) w.push(playTab); } catch (e) { playTab = null; } const f = $('playFrame'); if (f.contentWindow) w.push(f.contentWindow); return w; }
function noteMode() { const ws = gameWindows(); if (!ws.length) return; ws[0].postMessage({ type: 'forge:notes', on: true }, '*'); try { ws[0].focus(); } catch (e) {} }
window.addEventListener('message', async e => {
  const m = e.data || {};
  if (m.type === 'forge:notes-open') { $('playFrame').scrollIntoView({ block: 'center', behavior: 'smooth' }); sendNotesToGame(); return; } // the game is in note mode: keep the whole frame in view, show it the notes so far
  if (m.type === 'forge:note-action' && m.id) { noteState(m.id, m.action === 'delete' ? 'delete' : 'done', true); return; } // scrap or delete, asked from note mode
  if (m.type !== 'forge:note' || !m.note) return;
  try { const r = await Forge.api(`/game/note/${slug}`, { note: m.note }); NOTES = r.notes; renderNotes(); if (m.note.build) refreshDue = true; } catch (err) { alert('note not saved: ' + err.message); }
});
function sendNotesToGame() { for (const w of gameWindows()) w.postMessage({ type: 'forge:notes-list', notes: NOTES.filter(n => n.state !== 'done').map(n => ({ id: n.id, scene: n.scene, x: n.x, y: n.y, what: n.what, text: n.text, state: n.state, by: n.byName, canManage: !!n.canManage, votes: n.votes || 0 })) }, '*'); }
function renderNotes() {
  sendNotesToGame();
  const filt = notesFolder, big = NOTES.filter(n => n.state === filt).sort((a, b) => ((b.votes || 0) - (a.votes || 0)) || (b.id - a.id));
  for (const b of document.querySelectorAll('#notesFolders button')) { b.classList.toggle('on', b.dataset.folder === filt); b.querySelector('span').textContent = NOTES.filter(n => n.state === b.dataset.folder).length || ''; }
  $('notesTab').textContent = NOTES.some(n => n.state === 'open') ? '(' + NOTES.filter(n => n.state === 'open').length + ')' : '';
  $('notesBig').innerHTML = big.length ? big.map(n => `<div class="vl-note-card ${n.state}">
      ${n.shot ? `<img src="/game/noteshot/${slug}?id=${n.id}" alt="" onclick="lightbox(${n.id}, this.src)">` : '<div class="vl-note-noshot">no image</div>'}
      <div class="vl-note-body"><div class="vl-note-head"><label><input type="checkbox" data-id="${n.id}" ${n.state === 'done' ? 'disabled' : ''} onchange="notesButtons()"> <b>${Forge.esc(n.byName || 'someone')}</b></label><span><button class="vl-vote ${n.voted ? 'on' : ''}" onclick="noteVote(${n.id})" title="${Forge.esc((n.voters || []).join(', ') || 'no votes yet')}">▲ ${n.votes || 0}</button> <span class="vl-state ${n.state}">${FOLDER[n.state]}</span></span></div>
        <div class="vl-note-big-text">${Forge.esc(n.text)}</div>
        <div class="vl-note-meta">${n.scene === 'note' ? 'general note' : 'scene ' + Forge.esc(n.scene) + ' · at (' + n.x + ', ' + n.y + ')' + (n.what && n.what.label ? ' · ' + Forge.esc(n.what.label) : '')} · ${Forge.esc((n.createdAt || '').slice(0, 16).replace('T', ' '))}</div>
        ${n.what && n.what.fields ? `<div class="vl-note-fields">${Forge.esc(JSON.stringify(n.what.fields))}</div>` : ''}
        <div class="vl-comments">${(n.comments || []).map(c => `<div class="vl-comment"><b>${Forge.esc(c.by)}</b> ${Forge.esc(c.text)}${c.mine ? ` <a href="#" onclick="noteComment(${n.id}, null, ${c.id}); return false;" title="delete">✕</a>` : ''}</div>`).join('')}
          <form class="vl-comment-form" onsubmit="noteComment(${n.id}, this.text.value); this.text.value = ''; return false;"><input name="text" placeholder="add a comment: refine it, argue with it…" maxlength="4000"><button class="btn btn-sm btn-outline-secondary" type="submit">Comment</button></form></div>
        <div class="vl-note-actions">${n.state !== 'done' ? `<button class="btn btn-sm btn-outline-secondary" onclick="noteState(${n.id}, 'done')" title="move it to the scrapyard">Scrap</button>` : ''}${n.state !== 'open' ? `<button class="btn btn-sm btn-outline-secondary" onclick="noteState(${n.id}, 'open')" title="back to awaiting verdict">Back to verdict</button>` : ''}${n.state === 'done' ? `<button class="btn btn-sm btn-outline-secondary" onclick="noteState(${n.id}, 'delete')" title="gone for good">Delete</button>` : ''}</div></div></div>`).join('')
    : `<div class="vl-kv">${filt === 'open' ? 'Nothing awaiting a verdict. In Play, press Note mode (or Alt+P in the game), click a thing, say what should change.' : filt === 'sent' ? 'Nothing has gone to the builder yet.' : 'The scrapyard is empty.'}</div>`;
  notesButtons();
  const open = NOTES.filter(n => n.state === 'open'), rest = NOTES.filter(n => n.state !== 'open').slice(0, 6);
  $('notesStrip').innerHTML = open.length ? open.slice(0, 8).map(n => `<span class="vl-chip" title="${Forge.esc(n.text)}">${n.shot ? `<img src="/game/noteshot/${slug}?id=${n.id}" alt="">` : ''}${Forge.esc(n.text.slice(0, 40))}</span>`).join('') + ` <a href="#notes" onclick="showTab('notes')">${open.length} awaiting verdict → Notes</a>` : '';
}
async function noteState(id, state, sure) { if (state === 'delete' && !sure && !confirm('Delete this note?')) return; try { NOTES = (await Forge.api(`/game/notestate/${slug}`, { id, state })).notes; renderNotes(); } catch (e) { alert(e.message); } }
let composeIds = [];
async function sendNotes(where) {
  const box = where === 'big' ? '#notesBig' : '#notesBox', intro = where === 'big' ? $('notesIntroBig') : $('notesIntro'); const ids = [...document.querySelectorAll(box + ' input[type=checkbox]:checked')].map(c => +c.dataset.id);
  if (!ids.length) { alert('Check the notes to send first.'); return; }
  if (where !== 'big') { try { const r = await Forge.api(`/game/sendnotes/${slug}`, { ids, text: intro.value.trim() }); NOTES = r.notes; renderNotes(); intro.value = ''; refreshDue = true; showTab('build'); } catch (e) { alert(e.message); } return; }
  try { const r = await Forge.api(`/game/sendpreview/${slug}?ids=${ids.join(',')}&intro=${encodeURIComponent(intro.value.trim())}`); composeIds = ids; $('composeText').value = r.message; $('composeBatch').style.display = ''; $('composeBatch').scrollIntoView({ block: 'start', behavior: 'smooth' }); $('composeText').focus(); } catch (e) { alert(e.message); }
}
async function sendComposed() { try { const r = await Forge.api(`/game/sendnotes/${slug}`, { ids: composeIds, message: $('composeText').value.trim() }); NOTES = r.notes; renderNotes(); $('composeBatch').style.display = 'none'; $('notesIntroBig').value = ''; refreshDue = true; showTab('build'); } catch (e) { $('composeMsg').textContent = e.message; } }
$('newNoteForm').onsubmit = async e => { e.preventDefault(); const f = e.target, fd = new FormData(f); fd.append('_csrf_token', Forge.token); $('newNoteMsg').textContent = 'saving…';
  try { const r = await fetch(`/game/noteadd/${slug}`, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } }); const j = await r.json(); if (!r.ok || j.success === false) throw new Error(j.message || j.error || 'could not save'); NOTES = j.data.notes; notesFolder = 'open'; renderNotes(); f.reset(); $('newNoteMsg').textContent = 'saved'; } catch (err) { $('newNoteMsg').textContent = err.message; } };
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
