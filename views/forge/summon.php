<?php /* The brief form. The slug fills itself from the title (forge.js). */
$fields = [
    ['name' => 'title', 'label' => 'Name', 'required' => true, 'placeholder' => 'Void Drift', 'width' => 6],
    ['name' => 'slug', 'label' => 'Web address', 'placeholder' => 'void-drift', 'help' => 'Made from the name. The game plays at https://<this>.voidlux.com/', 'width' => 6],
    ['name' => 'template', 'label' => 'Start from', 'type' => 'select', 'options' => $templates, 'required' => true, 'help' => 'What the game starts from; blank if unsure.', 'width' => 6],
    ['name' => 'team_id', 'label' => 'Team', 'type' => 'select', 'options' => [0 => 'Just me'] + array_combine(array_map(fn($t) => (int) $t['id'], $teams), array_map(fn($t) => $t['name'], $teams)), 'help' => 'Teammates on it can open, build and ship the game.', 'width' => 6],
    ['name' => 'brief', 'label' => 'What is the game?', 'type' => 'textarea', 'help' => 'Up to 4000 characters.', 'placeholder' => 'What is the game? Who is the player, what do they do every ten seconds, what makes a run end, what do they collect or build, what is the hook that sells it? Mention the platform (phone, desktop, both) and a price point.'],
    ['name' => 'pipeline', 'label' => 'Start building it now. ' . $estimate['cost'] . ' You can stop it at any time.', 'type' => 'checkbox'],
];
?>
<?= \app\Ui::page(['eyebrow' => 'The forge', 'title' => 'Summon a game', 'lead' => 'Describe a game. Nothing is spent until you start building it.', 'back' => ['label' => 'Games', 'url' => '/forge']]) ?>
<?= \app\Ui::form(['action' => '/forge/summon', 'fields' => $fields, 'values' => $form, 'problems' => $problems, 'submit' => 'Create the game', 'cancel' => '/forge']) ?>
<script src="/forge.js"></script>
<script>Forge.slugFromTitle();</script>
