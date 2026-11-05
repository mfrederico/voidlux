<?php
/* On the browserdrive plugin's page (/browserdrive), through the layout's scripts slot (the plugin's own files stay as installed):
   its long how-it-works paragraph goes, and the top says what the page needs from you, an API key for the agent or script that drives
   it, with the link to make or manage one, and holds its settings (the pages a session may open, scripts) folded. */
$path = parse_url((string) Flight::request()->url, PHP_URL_PATH);
if ($path !== '/browserdrive' || !Flight::hasLevel(LEVELS['ADMIN'])) return;
$me = Flight::getMember();
$keys = $me ? array_values(array_filter($me->withCondition(" (key_class IS NULL OR key_class != 'broker') ORDER BY created_at DESC ")->ownApikeyList,
    fn($k) => !isset($k->isActive) || $k->isActive === null || (int) $k->isActive === 1)) : [];
$live = array_values(array_filter($keys, fn($k) => empty($k->expiresAt) || strtotime((string) $k->expiresAt) > time()));
$names = implode(', ', array_map(fn($k) => (string) $k->name, array_slice($live, 0, 3))) . (count($live) > 3 ? ' and ' . (count($live) - 3) . ' more' : '');
$notice = $live
    ? \app\Ui::notice(['tone' => 'success', 'title' => count($live) === 1 ? 'You have an API key for driving this page.' : 'You have ' . count($live) . ' API keys for driving this page.', 'text' => "An agent or script uses one of yours ({$names}) to open sessions here; this tab plays them.", 'action' => ['label' => 'Manage API keys', 'url' => '/apikeys']])
    : \app\Ui::notice(['tone' => 'warning', 'title' => 'You have no API key yet.', 'text' => 'An agent or script needs one of your API keys to open sessions that this tab plays. Make one, and give it to the agent or script.', 'action' => ['label' => 'Make an API key', 'url' => '/apikeys/add']]);
?>
<template id="bdTopTpl"><div class="bd-top"><?= $notice ?><details class="bd-settings"><summary>Settings: the pages a session may open, and scripts</summary><div class="bd-settings-in"></div></details></div></template>
<style>.bd-top { display: grid; gap: 10px; } .bd-settings > summary { cursor: pointer; min-height: 38px; display: flex; align-items: center; font-weight: 600; } .bd-settings-in { padding-top: 8px; display: grid; gap: 8px; }</style>
<script>
(() => { const bd = document.querySelector('.bd'), tpl = document.getElementById('bdTopTpl'); if (!bd || !tpl) return;
  for (const p of bd.querySelectorAll(':scope > p')) if (p.textContent.trim().startsWith('Leave this page open')) p.remove(); // the how-it-works paragraph
  const top = tpl.content.firstElementChild.cloneNode(true), h1 = bd.querySelector('h1'); (h1 ? h1.after(top) : bd.prepend(top));
  const form = bd.querySelector('form[action="/browserdrive/allow"]'); if (form) top.querySelector('.bd-settings-in').appendChild(form);
})();
</script>
