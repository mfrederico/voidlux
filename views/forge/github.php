<?php /* The forge's GitHub: one OAuth App, one sign-in, for the whole forge. Games link to repositories from their Ship tab. */ ?>
<?= \app\Ui::page(['eyebrow' => 'The forge', 'title' => 'GitHub', 'lead' => 'One sign-in for the whole forge. It owns the games\' repositories; each linked game then pushes with its own key, so teammates need no GitHub account.', 'back' => ['label' => 'Games', 'url' => '/forge']]) ?>
<div class="vl-wrap">
<?php if ($who !== ''): ?>
  <div class="vl-card"><h3 class="vl-h2 mt-0">Connected</h3><p>The forge is signed in to GitHub as <b><?= h($who) ?></b>. Link a game from its <b>Ship</b> tab.</p><p class="vl-muted small">To use another account, sign in again as it: <a class="btn btn-sm btn-outline-primary" href="/forge/githubconnect">Sign in with GitHub</a></p></div>
<?php elseif ($app): ?>
  <div class="vl-card"><h3 class="vl-h2 mt-0">Step 2 · Sign in</h3><p>The OAuth App is saved. Sign in as the GitHub account that should own the games' repositories, and approve.</p><a class="btn btn-primary" href="/forge/githubconnect">Sign in with GitHub</a><?php if ($err !== '' && !str_starts_with($err, 'No GitHub connection')): ?><p class="vl-muted small mt-2"><?= h($err) ?></p><?php endif; ?></div>
<?php endif; ?>
  <div class="vl-card"><h3 class="vl-h2 mt-0"><?= $app ? 'The OAuth App' : 'Step 1 · Register the forge with GitHub' ?></h3>
    <ol class="small"><li>On GitHub: <a href="https://github.com/settings/applications/new" target="_blank" rel="noopener">Settings → Developer settings → OAuth Apps → New OAuth App</a>.</li>
      <li>Application name: <code>VoidLux forge</code> · Homepage URL: <code><?= h($home) ?></code> · Authorization callback URL: <code><?= h($callback) ?></code></li>
      <li>Register it, then <b>Generate a new client secret</b>, and paste both here.</li></ol>
    <form method="post" action="/forge/github" class="vl-form"><?= csrf_field() ?><label>Client ID <input name="client_id" value="<?= h($app['client_id'] ?? '') ?>" required></label><label>Client secret <input name="client_secret" type="password" placeholder="<?= $app ? 'saved; paste a new one to replace it' : '' ?>" required></label><button class="btn btn-sm btn-primary" type="submit"><?= $app ? 'Replace' : 'Save' ?></button></form>
    <p class="vl-muted small">Both are kept encrypted on this server. The sign-in asks for <code>repo</code> (make repositories and add keys to them) and <code>read:user</code>.</p></div>
</div>
