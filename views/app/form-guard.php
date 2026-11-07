<?php
/* FormGuard's two fields, added to the sign-up and password-reset forms as the page loads (lib/FormGuard.php says why). The forms are
   the runtime's; this part reaches them through the layout's scripts slot, on those two pages only. */
$path = (string) parse_url((string) Flight::request()->url, PHP_URL_PATH);
$post = \app\FormGuard::FORMS[$path] ?? null; if ($post === null) return;
?>
<script>
(() => { const f = document.querySelector('form[action="<?= htmlspecialchars($post, ENT_QUOTES) ?>"]'); if (!f) return;
  const trap = document.createElement('input'); Object.assign(trap, { type: 'text', name: <?= json_encode(\app\FormGuard::TRAP) ?>, tabIndex: -1, autocomplete: 'off' });
  trap.setAttribute('aria-hidden', 'true'); trap.style.cssText = 'position:absolute;left:-10000px;top:auto;width:1px;height:1px;opacity:0'; // no person sees or fills it
  const stamp = document.createElement('input'); Object.assign(stamp, { type: 'hidden', name: <?= json_encode(\app\FormGuard::STAMP) ?>, value: <?= json_encode(\app\FormGuard::stamp()) ?> });
  f.append(trap, stamp); })();
</script>
