<?php /* The brief form. The slug fills itself from the title (forge.js). */
$fields = [
    ['name' => 'title', 'label' => 'Title', 'required' => true, 'placeholder' => 'Void Drift', 'width' => 'half'],
    ['name' => 'slug', 'label' => 'Slug', 'placeholder' => 'void-drift', 'help' => 'The folder and the subdomain: https://<slug>.voidlux.com/', 'width' => 'half'],
    ['name' => 'template', 'label' => 'Template', 'type' => 'select', 'options' => array_combine($templates, array_map(fn($t) => $t . ($t === 'blank' ? ' (arena shooter)' : ''), $templates)), 'width' => 'half'],
    ['name' => 'team_id', 'label' => 'Team', 'type' => 'select', 'options' => [0 => 'Just me'] + array_combine(array_map(fn($t) => (int) $t['id'], $teams), array_map(fn($t) => $t['name'], $teams)), 'help' => 'Teammates on it can open, build and ship the game.', 'width' => 'half'],
    ['name' => 'brief', 'label' => 'Brief', 'type' => 'textarea', 'placeholder' => 'What is the game? Who is the player, what do they do every ten seconds, what makes a run end, what do they collect or build, what is the hook that sells it? Mention the platform (phone, desktop, both) and a price point.'],
    ['name' => 'pipeline', 'label' => 'Run the pipeline right away (plan, build, soak, review)', 'type' => 'checkbox'],
];
?>
<?= \app\Ui::page(['eyebrow' => 'The forge', 'title' => 'Summon a game', 'lead' => 'A brief in. A designed, built, soaked and reviewed game out.', 'back' => ['label' => 'Games', 'url' => '/forge']]) ?>
<?= \app\Ui::form(['action' => '/forge/summon', 'fields' => $fields, 'values' => $form, 'problems' => $problems, 'submit' => 'Summon', 'cancel' => '/forge']) ?>
<script src="/forge.js"></script>
<script>Forge.slugFromTitle();</script>
