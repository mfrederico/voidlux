<?php /* Deleted games, for admins (controls/Forge.php trash): each waits in the trash for a while, then the sweep removes it for good. */ ?>
<?= \app\Ui::page(['eyebrow' => 'The forge', 'title' => 'Deleted games', 'lead' => "A deleted game waits here for {$days} days, history and all, then it is gone for good. Restore puts it back as it was; its notes stay deleted.", 'back' => ['label' => 'Games', 'url' => '/forge']]) ?>
<?= \app\Ui::table(['rows' => array_map(fn($t) => (object) $t, $rows), 'count' => 'deleted game',
    'columns' => [['label' => 'Game', 'value' => 'title', 'sub' => fn($t) => $t->slug !== '' ? $t->slug : $t->broken],
                  ['label' => 'Deleted', 'value' => fn($t) => date('M j, H:i', $t->at), 'sub' => fn($t) => $t->by !== '' ? 'by ' . $t->by : ''],
                  ['label' => 'Owner', 'value' => fn($t) => $t->owner ? ($owners[$t->owner] ?? '') : '', 'quiet' => true],
                  ['label' => 'Gone for good in', 'value' => fn($t) => $t->daysLeft === 0 ? 'today' : $t->daysLeft . ' day' . ($t->daysLeft === 1 ? '' : 's')]],
    'actions' => fn($t) => $t->broken !== '' ? [] : [['label' => 'Restore', 'post' => '/forge/restore', 'fields' => ['name' => $t->name], 'icon' => 'arrow-counterclockwise',
                                                   'confirm' => "Put {$t->title} back in the forge? It returns as it was when it was deleted."]],
    'empty' => ['title' => 'Nothing deleted', 'text' => 'A game deleted from the Studio (More, Delete this game) waits here for ' . $days . ' days.']]) ?>
