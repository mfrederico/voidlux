// The Studio's page: the game in a frame, inspect (freeze it, ask the game what is under the pointer through its forgePick
// hook), the list of things, and the read-only card of the one picked (lib/Inspector.php). views/studio/open.php is the markup.
(() => {
  const $ = id => document.getElementById(id), esc = Forge.esc, frame = $('game'), over = $('over'), ring = $('ring'), tip = $('tip');
  let inspecting = false, things = [], current = '', seq = 0;
  const W = () => frame.contentWindow;
  const hint = t => { $('hint').textContent = t; };

  // ---- inspect: the game held still; the overlay catches the pointer ----
  function setInspect(on) {
    let N = null; try { N = W().Notes; } catch (e) {}
    if (on && !N) { hint(frame.contentDocument && frame.contentDocument.readyState === 'complete' ? 'This game has no inspect support (it does not load the kit\'s notes): choose from the list.' : 'The game has not finished loading yet.'); return; }
    if (on && typeof W().forgePick !== 'function') { hint('This game does not say what is under the pointer yet: choose from the list.'); return; }
    inspecting = on; if (N) N.freeze(on); over.classList.toggle('on', on); $('inspect').classList.toggle('on', on); $('inspect').textContent = on ? 'Resume game' : 'Inspect';
    ring.style.display = tip.style.display = 'none'; hint(on ? 'Paused. Click anything in the game. Esc resumes.' : 'Press Inspect, then click anything in the game.'); if (!on) frame.focus();
  }
  const at = e => { const r = over.getBoundingClientRect(), cx = e.clientX - r.left, cy = e.clientY - r.top; const N = W().Notes, p = N.logical(cx, cy); return { cx, cy, list: W().forgePick(p.x, p.y) || [] }; };
  over.addEventListener('pointermove', e => { if (!inspecting) return; const { cx, cy, list } = at(e), N = W().Notes;
    if (!list.length) { ring.style.display = tip.style.display = 'none'; over.style.cursor = 'crosshair'; return; }
    const t = list[0], c = N.css(t.x, t.y), k = N.canvas.getBoundingClientRect().width / N.canvas.width, s = t.r * k * 2;
    Object.assign(ring.style, { display: 'block', left: c.x - s / 2 + 'px', top: c.y - s / 2 + 'px', width: s + 'px', height: s + 'px' }); over.style.cursor = 'pointer';
    tip.textContent = t.key; Object.assign(tip.style, { display: 'block', left: Math.min(cx + 14, over.clientWidth - 180) + 'px', top: Math.max(cy - 28, 4) + 'px' }); });
  over.addEventListener('click', e => { if (!inspecting) return; const { list } = at(e); if (!list.length) { hint('Nothing there. Menus and title screens have nothing to inspect: press Esc, start playing, then Inspect again.'); return; } if (typeof UX !== 'undefined') UX.act('inspect', list[0].key); show(list[0].key, list[0]); });
  $('inspect').onclick = () => setInspect(!inspecting);
  $('restart').onclick = () => { setInspect(false); frame.src = `/games/${SLUG}/?r=${Date.now()}`; };
  const keys = e => { if (e.altKey && e.key.toLowerCase() === 'i') { e.preventDefault(); setInspect(!inspecting); } else if (e.key === 'Escape' && inspecting) { e.preventDefault(); setInspect(false); } };
  addEventListener('keydown', keys);
  frame.addEventListener('load', () => { inspecting = false; over.classList.remove('on'); $('inspect').classList.remove('on'); $('inspect').textContent = 'Inspect'; try { W().addEventListener('keydown', keys, true); } catch (e) {} });
  // a note made in the game (its own Alt+P) is kept as on the game page, never lost
  addEventListener('message', async e => { const m = e.data || {}; if (m.type !== 'forge:note' || !m.note || e.source !== W()) return; try { await Forge.api(`/game/note/${SLUG}`, { note: m.note }); hint('Note saved: it is on the game page, under Notes.'); } catch (err) { alert('note not saved: ' + err.message); } });

  // ---- the list ----
  function drawList() { const q = $('find').value.trim().toLowerCase(), box = $('things'); box.innerHTML = ''; let group = null, n = 0;
    for (const t of things) { if (q && !(t.key + ' ' + t.about + ' ' + t.group).toLowerCase().includes(q)) continue; n++;
      if (t.group !== group) { group = t.group; box.insertAdjacentHTML('beforeend', `<h4>${esc(group)}</h4>`); }
      const b = document.createElement('button'); b.className = 'th' + (t.key === current ? ' on' : ''); b.title = t.about; b.innerHTML = `<i class="strip"${t.thumb ? ` style="background-image:url('${esc(t.thumb)}')"` : ''}></i><span>${esc(t.key)}</span>`; b.onclick = () => show(t.key); box.appendChild(b); }
    if (!n) box.innerHTML = `<p class="muted small" style="padding:8px">${things.length ? 'Nothing by that name.' : 'This game lists no things yet.'}</p>`; }
  $('find').oninput = drawList;
  $('find').onkeydown = e => { if (e.key === 'Enter') { const b = $('things').querySelector('.th'); if (b) b.click(); } };

  // ---- the card: what is specific to this thing first; what it shares with its group, folded ----
  async function show(key, live) { const mine = ++seq; current = key; drawList(); const row = $('things').querySelector('.th.on'); if (row) row.scrollIntoView({ block: 'nearest' }); $('card').innerHTML = `<h2>${esc(key)}</h2><p class="muted">Loading…</p>`;
    try { const { card } = await Forge.api(`/studio/card/${SLUG}?key=${encodeURIComponent(key)}`); if (mine === seq) $('card').innerHTML = render(card, live); }
    catch (e) { if (mine === seq) $('card').innerHTML = `<h2>${esc(key)}</h2><p class="bad">The card could not be read: ${esc(e.message)}</p>`; } }
  const ruleRow = r => `<div class="rule${r.off ? ' off' : ''}">${esc(RuleWords.say(r))}${r.off ? ' <span class="pill">off</span>' : ''}${r.note ? `<div class="n">${esc(r.note)}</div>` : ''}${r.broken ? `<div class="bad small">no longer fits the game: ${esc(r.broken)}</div>` : ''}</div>`;
  const fold = (title, n, inner) => n ? `<details><summary>${esc(title)} <span class="c">${n}</span></summary>${inner}</details>` : '';
  function render(c, live) { const L = c.look; let h = `<h2>${esc(c.key)}</h2>`; if (c.about) h += `<div class="muted">${esc(c.about)}</div>`; if (c.groups.length) h += `<div class="small muted">${c.groups.map(esc).join(' · ')}</div>`;
    if (live && (live.hp != null || (live.status && live.status.length))) h += `<div class="now"><b>The one you clicked:</b> ${live.hp != null ? `health ${live.hp}${live.maxhp != null ? ' / ' + live.maxhp : ''}` : ''}${live.status && live.status.length ? ' · ' + live.status.map(esc).join(', ') : ''}</div>`;
    h += '<h3>Look</h3>';
    if (!L) h += '<div class="muted small">It has no model or sprite of its own: the game draws it in code.</div>';
    else if (L.kind === 'sprite') h += `<div class="look"><img src="${esc(L.url)}" alt=""><div class="small muted">${esc(L.spriteKind)} · ${esc(L.file)}</div></div>`;
    else h += `<div class="look">${L.strip ? `<i class="strip spin" style="background-image:url('${esc(L.strip)}')"></i>` : ''}<div class="small">${L.kept ? `3D model, ${esc(L.size)} units${L.versions ? ` · ${L.versions} earlier version${L.versions > 1 ? 's' : ''}` : ''}` : 'No model yet: the game draws its stand-in.'}<div class="muted">${esc(L.note)}</div>${L.moves ? `<div class="muted">The game animates: ${esc(L.moves)}</div>` : ''}${L.colours.length ? `<div class="dots">${L.colours.map(x => `<i style="background:${esc(x)}" title="${esc(x)}"></i>`).join('')}</div>` : ''}</div></div>`;
    h += '<h3>Its own rules</h3>' + (c.rules.own.length ? c.rules.own.map(ruleRow).join('') : `<div class="muted small">${c.wired ? 'None yet.' : 'This game has no events wired in yet.'}</div>`);
    h += fold(`Rules from its group${c.groups.length ? 's (' + c.groups.join(', ') + ')' : ''}`, c.rules.group.length, c.rules.group.map(ruleRow).join('')) + fold('Rules for everything', c.rules.all.length, c.rules.all.map(ruleRow).join(''));
    if (c.bestiary) { h += '<h3>Resistances</h3>'; const els = ['fire', 'poison', 'ice', 'electric'], many = c.bestiary.length > 1; h += `<table class="res"><tr>${many ? '<th></th>' : ''}${els.map(e => `<th>${e}</th>`).join('')}</tr>${c.bestiary.map(l => `<tr>${many ? `<th>${esc(l.row)}</th>` : ''}${els.map(e => `<td><b>${esc(l.values[e].v)}×</b><span class="from">${esc(l.values[e].from || 'default')}</span></td>`).join('')}</tr>`).join('')}</table>`; for (const l of c.bestiary) { const s = Object.entries(l.stats); if (l.note || s.length) h += `<div class="small muted">${l.note ? esc(l.note) + ' ' : ''}${s.map(([k, v]) => `${esc(k)} ${esc(v)}`).join(', ')}</div>`; } }
    const systems = Object.entries(c.built); h += '<h3>Built into the game</h3>';
    if (!c.listed) h += '<div class="muted small">Not listed yet (the Events tab can ask the builder to list it).</div>'; else if (!systems.length) h += '<div class="muted small">Nothing listed for it specifically.</div>';
    for (const [name, items] of systems) h += fold(name, items.length, items.map(i => { const now = i.now && i.now.length && (i.rule === 'yes' || i.via); return `<div class="bi">${esc(i.does || '')}<div class="muted small">when ${esc(i.when || '')} <span class="pill${now ? ' rule-now' : ''}">${now ? 'a rule now' : i.rule === 'no' ? 'stays in code' : i.rule === 'partly' ? 'partly a rule' : 'can be a rule'}</span></div></div>`; }).join(''));
    if (c.builtForAll) h += `<div class="small muted" style="margin-top:6px">Plus ${c.builtForAll} that apply to everything: see the Events tab.</div>`;
    const O = c.open; h += `<div class="links"><a href="${esc(O.rules)}" target="_blank">Edit rules</a>${O.model ? `<a href="${esc(O.model)}" target="_blank">Model and revisions</a>` : ''}${O.bestiary ? `<a href="${esc(O.bestiary)}" target="_blank">Bestiary</a>` : ''}</div>`;
    return h; }

  // ---- this page also runs the playtests and screenshots agents ask for, as the game page does ----
  let last = 0; ForgeRunner.init(SLUG, { note: t => { if (t) hint(t); }, done: rep => hint(rep.ok ? 'Playtest passed in this browser.' : 'Playtest in this browser: ' + (rep.status || 'failed') + '. See the game page, under Test.') });
  async function poll() { const att = $('attached');
    try { const r = await Forge.api(`/game/events/${SLUG}?since=${last}`); last = r.last; ForgeRunner.take(r); att.textContent = '● runs playtests'; }
    catch (e) { att.textContent = '○ not connected: ' + e.message; }
    setTimeout(poll, document.hidden ? 8000 : 5000); }
  if (window.top === window) poll(); else $('attached').textContent = ''; // inside another page's frame (a browser-drive session looking at this page) it is not the runner
  addEventListener('beforeunload', e => { if (ForgeRunner.busy()) { e.preventDefault(); e.returnValue = ''; } });

  Forge.api(`/studio/things/${SLUG}`).then(r => { things = r.things || []; drawList(); }).catch(e => { $('things').innerHTML = `<p class="bad small" style="padding:8px">The list could not be read: ${esc(e.message)}</p>`; });
})();
