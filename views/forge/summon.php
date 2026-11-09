<?php /* The brief form. The slug fills itself from the title (forge.js). */
$fields = [
    ['name' => 'title', 'label' => 'Name', 'required' => true, 'placeholder' => 'Void Drift', 'width' => 6],
    ['name' => 'slug', 'label' => 'Web address', 'placeholder' => 'void-drift', 'help' => 'Made from the name. The game plays at https://<this>.voidlux.com/', 'width' => 6],
    ['name' => 'team_id', 'label' => 'Team', 'type' => 'select', 'options' => [0 => 'Just me'] + array_combine(array_map(fn($t) => (int) $t['id'], $teams), array_map(fn($t) => $t['name'], $teams)), 'help' => 'Teammates on it can open, build and ship the game.', 'width' => 6],
    ['name' => 'brief', 'label' => 'What is the game?', 'type' => 'textarea', 'help' => 'Up to 4000 characters.', 'placeholder' => 'What is the game? Who is the player, what do they do every ten seconds, what makes a run end, what do they collect or build, what is the hook that sells it? Mention the platform (phone, desktop, both) and a price point.'],
    ['name' => 'pipeline', 'label' => 'Start building it now. ' . $estimate['cost'] . ' You can stop it at any time.', 'type' => 'checkbox'],
];
/* the kind chosen on the first screen (forge/choose): 2D and 3D start empty; "an existing game" picks one of the other starting points */
if ($kind === 'existing') array_splice($fields, 2, 0, [['name' => 'template', 'label' => 'Start from', 'type' => 'select', 'options' => array_diff_key($templates, ['empty-2d' => 1, 'empty-3d' => 1]), 'required' => true, 'help' => 'A game to start from, which the builder reshapes to what you say.', 'width' => 6]]);
if (!$confirmed) $fields = array_values(array_filter($fields, fn($f) => $f['name'] !== 'pipeline')); /* building waits for a confirmed email: said above the form instead */
$said = ['2d' => 'A 2D game, starting empty.', '3d' => 'A 3D game, starting empty: a floor and a sky.', 'existing' => 'A game started from one that exists.'][$kind] ?? '';
?>
<?= \app\Ui::page(['eyebrow' => 'The forge', 'title' => 'Summon a game', 'lead' => $said . ' Describe it: nothing is spent until you start building it.', 'back' => ['label' => 'Change the kind', 'url' => '/forge/summon']]) ?>
<?php if (!$confirmed): ?><?= \app\Ui::notice(['tone' => 'info', 'title' => 'Building waits for your email.', 'text' => 'Create the game now; once you confirm your email, the builder can make it.', 'action' => ['label' => 'Confirm my email', 'url' => '/verify']]) ?><?php endif; ?>
<?= \app\Ui::form(['action' => '/forge/summon', 'fields' => $fields, 'values' => $form, 'problems' => $problems, 'submit' => 'Create the game', 'cancel' => '/forge', 'hidden' => $kind === 'existing' ? [] : ['template' => $form['template']]]) ?>
<script src="/forge.js"></script>
<script>Forge.slugFromTitle();</script>
<script>/* an idea typed on the home page before signing up waits in this browser: it fills an empty brief once, then is forgotten */
(() => { let idea = ''; try { idea = localStorage.getItem('vl.idea') || ''; } catch (e) {} const b = document.querySelector('[name=brief]'); if (!b) return;
  if (idea && !b.value.trim()) b.value = idea; if (idea) try { localStorage.removeItem('vl.idea'); } catch (e) {} })();
</script>
