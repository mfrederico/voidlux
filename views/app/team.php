<?php /* What a team does in the forge: the games shared with it. $team is in scope (the runtime's team page). */
$__tg = array_values(\app\Bean::find('game', 'team_id = ? ORDER BY title ASC', [(int) $team->id]));
$__pending = $team->box()->canManage((int) member_id(\Flight::getMember())) ? array_filter(\app\Bean::find('teaminvitation', 'team_id = ? ORDER BY id DESC', [(int) $team->id]), fn($i) => empty($i->acceptedAt)) : []; // (accepted_at only exists once someone has accepted: not a column to query on)
$__base = rtrim((string) \Flight::get('app.baseurl'), '/');
?>
<?php if ($__pending): ?>
<div class="ui-panel mb-3">
    <div class="ui-panel-header"><h3><i class="bi bi-envelope-paper text-primary me-2"></i>Invites waiting</h3></div>
    <div class="ui-panel-body">
        <p class="text-secondary small">Each invite is a link. If this install sends no email, pass the link along yourself (a text, a chat message): opening it, signed in or not, puts them on the team.</p>
        <?php foreach ($__pending as $__i): $__link = $__base . '/teams/join?token=' . urlencode((string) $__i->token); ?>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2"><b><?= htmlspecialchars((string) $__i->email) ?></b><span class="text-secondary small">· <?= htmlspecialchars((string) $__i->role) ?> · until <?= htmlspecialchars(substr((string) $__i->expiresAt, 0, 10)) ?></span>
            <input class="form-control form-control-sm" style="max-width:520px" readonly value="<?= htmlspecialchars($__link) ?>" onclick="this.select()"><button class="btn btn-sm btn-outline-secondary" type="button" onclick="var i = this.previousElementSibling, b = this; i.select(); (navigator.clipboard ? navigator.clipboard.writeText(i.value) : Promise.reject()).then(function () { b.textContent = 'copied'; }, function () { document.execCommand('copy'); b.textContent = 'copied'; })">copy link</button>
            <button class="btn btn-sm btn-outline-primary" type="button" onclick="vlInvite(this, 'resend', <?= (int) $__i->id ?>, <?= (int) $team->id ?>, <?= json_encode((string) $__i->email) ?>, <?= json_encode((string) $__i->role) ?>)" title="send the same link again by email">Resend email</button>
            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="vlInvite(this, 'renew', <?= (int) $__i->id ?>, <?= (int) $team->id ?>, <?= json_encode((string) $__i->email) ?>, <?= json_encode((string) $__i->role) ?>)" title="withdraw this link and send a fresh one by email">Revoke &amp; re-send</button>
            <button class="btn btn-sm btn-outline-danger" type="button" onclick="vlInvite(this, 'revoke', <?= (int) $__i->id ?>, <?= (int) $team->id ?>, <?= json_encode((string) $__i->email) ?>, <?= json_encode((string) $__i->role) ?>)" title="withdraw this link; nothing is sent">Revoke</button></div>
        <?php endforeach; ?>
        <script>
        // the three invite actions: resend (the runtime's route, same link), revoke (the forge's route), renew (revoke, then invite afresh through the runtime)
        async function vlInvite(btn, what, id, teamId, email, role) {
            const csrf = <?= json_encode(csrf_token()) ?>; btn.disabled = true;
            const form = o => { const f = new FormData(); f.append('_csrf_token', csrf); for (const k in o) f.append(k, o[k]); return f; };
            try {
                if (what === 'resend') { const r = await (await fetch('/teams/resendinvite', { method: 'POST', body: form({ team_id: teamId, invitation_id: id }) })).json(); if (!r.success) throw new Error(r.message || 'could not resend'); btn.textContent = 'sent'; return; }
                if (what === 'revoke' && !confirm('Withdraw the invitation for ' + email + '?')) { btn.disabled = false; return; }
                const rv = await (await fetch('/forge/revokeinvite', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ id }) })).json();
                if (!rv.success) throw new Error(rv.message || rv.error || 'could not revoke');
                if (what === 'renew') { await fetch('/teams/invite', { method: 'POST', body: form({ id: teamId, email, role }) }); }
                location.reload();
            } catch (e) { alert(e.message); btn.disabled = false; }
        }
        </script>
    </div>
</div>
<?php endif; ?>
<div class="ui-panel mb-3">
    <div class="ui-panel-header"><h3><i class="bi bi-controller text-primary me-2"></i>Games this team builds</h3></div>
    <div class="ui-panel-body">
        <?php if (!$__tg): ?><p class="text-secondary mb-0">No game is shared with this team yet. On a game's Ship tab, set its team.</p>
        <?php else: ?><div class="vl-grid">
            <?php foreach ($__tg as $__g): $__m = \app\GameStore::meta($__g); ?>
            <a class="vl-card" href="/game/view/<?= htmlspecialchars($__m['slug']) ?>"><div class="vl-card-title"><?= htmlspecialchars($__m['title']) ?></div><div class="vl-meta"><?= htmlspecialchars($__m['status']) ?> · <?= htmlspecialchars($__m['lastCommit']) ?></div></a>
            <?php endforeach; ?>
        </div><?php endif; ?>
    </div>
</div>
