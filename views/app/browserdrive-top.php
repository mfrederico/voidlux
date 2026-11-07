<?php
/* On the browserdrive plugin's page (/browserdrive), through the layout's scripts slot (the plugin's own files stay as installed):
   its long how-it-works paragraph goes, and the top says what the page needs from you, an API key for the agent or script that drives
   it, with the link to make or manage one, the pages a session may open, scripts and how agents connect: every setting behind one ⚙ Settings button beside Start. */
$path = parse_url((string) Flight::request()->url, PHP_URL_PATH);
if ($path !== '/browserdrive' || !Flight::hasLevel(LEVELS['ADMIN'])) return;
$me = Flight::getMember();
$keys = $me ? array_values(array_filter($me->withCondition(" (key_class IS NULL OR key_class != 'broker') ORDER BY created_at DESC ")->ownApikeyList,
    fn($k) => !isset($k->isActive) || $k->isActive === null || (int) $k->isActive === 1)) : [];
$live = array_values(array_filter($keys, fn($k) => empty($k->expiresAt) || strtotime((string) $k->expiresAt) > time()));
$names = implode(', ', array_map(fn($k) => (string) $k->name, array_slice($live, 0, 3))) . (count($live) > 3 ? ' and ' . (count($live) - 3) . ' more' : '');
$notice = $live
    ? \app\Ui::notice(['tone' => 'success', 'title' => count($live) === 1 ? 'You have an API key for driving this page.' : 'You have ' . count($live) . ' API keys for driving this page.', 'text' => "An agent or script uses one of yours ({$names}) to open sessions here; this tab plays them.", 'action' => ['label' => 'Manage API keys', 'url' => '/apikeys']])
    : \app\Ui::notice(['tone' => 'info', 'title' => 'No API key, and none needed here.', 'text' => 'The forge drives this page from its own server. A key is only for an agent or script outside it (a tool on your own computer): the allow list says which pages a session may open, a key says who may open one.', 'action' => ['label' => 'Make an API key', 'url' => '/apikeys/add']]);
?>
<template id="bdTopTpl"><details class="bd-settings"><summary class="btn btn-outline-secondary" title="Show or hide the settings"><i class="bi bi-gear" aria-hidden="true"></i> Settings <span class="bd-chev" aria-hidden="true">▾</span></summary><div class="bd-settings-in"><?= $notice ?></div></details></template>
<style>.bd-bar { position: relative; } .bd-settings { margin-left: auto; } .bd-settings > summary { list-style: none; display: inline-flex; gap: 6px; align-items: center; min-height: 38px; cursor: pointer; } .bd-settings > summary::-webkit-details-marker { display: none; }
.bd-settings[open] > summary { background: var(--bs-secondary-bg, rgba(127,127,127,.15)); } .bd-settings[open] .bd-chev { transform: rotate(180deg); } .bd-chev { display: inline-block; transition: transform .15s; }
.bd-settings-in { position: absolute; right: 0; top: calc(100% + 6px); z-index: 30; width: min(680px, 92vw); display: grid; gap: 10px; padding: 14px; border: 1px solid rgba(127,127,127,.35); border-radius: 12px; background: var(--bs-body-bg, #1b1428); box-shadow: 0 12px 32px rgba(0,0,0,.35); }
/* the driven page in the middle of the stage, scaled from its top centre; the plugin's pointer, outline and caption follow it by --bd-dx */
.bd-stage { position: relative; display: flex; justify-content: center; } #bdFrame { flex: none; transform-origin: top center !important; }
.bd-ptr, .bd-box { translate: var(--bd-dx, 0px) 0; }
/* black behind and inside the driven page until it draws: the plugin's white was blinding in a dark room */
.bd-stage, #bdFrame { background: #000 !important; border-color: #222 !important; }</style>
<script>
(() => { const go = () => { const bd = document.querySelector('.bd'), tpl = document.getElementById('bdTopTpl'); if (!bd || !tpl || bd.querySelector('.bd-settings')) return;
  for (const p of bd.querySelectorAll(':scope > p')) if (p.textContent.trim().startsWith('Leave this page open')) p.remove(); // the how-it-works paragraph
  const top = tpl.content.firstElementChild.cloneNode(true), bar = bd.querySelector('.bd-bar'), h1 = bd.querySelector('h1'), box = top.querySelector('.bd-settings-in'); (bar ? bar.appendChild(top) : h1 ? h1.after(top) : bd.prepend(top)); /* a ⚙ Settings button beside Start; its panel drops down under it */
  /* every setting in one place: the pages a session may open and scripts (the plugin's form), and how agents and scripts connect */
  for (const f of bd.querySelectorAll(':scope > form')) box.appendChild(f);
  for (const p of bd.querySelectorAll(':scope > p')) if (/API key|browserdrive_drive|client/.test(p.textContent)) box.appendChild(p); };
  /* how far the centred page sits from the stage's left edge: the plugin places its pointer and boxes from that edge */
  const centre = () => { const st = document.getElementById('bdStage'), f = document.getElementById('bdFrame'); if (!st || !f) return; st.style.setProperty('--bd-dx', (f.getBoundingClientRect().left - st.getBoundingClientRect().left) + 'px'); };
  const watch = () => { const f = document.getElementById('bdFrame'), st = document.getElementById('bdStage'); if (!f || !st) return; new ResizeObserver(centre).observe(st); new MutationObserver(centre).observe(f, { attributes: true, attributeFilter: ['style'] }); centre(); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { go(); watch(); }); else { go(); watch(); } })();
</script>
