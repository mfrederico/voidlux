<?php /* The page a mailed confirmation link opens: the BUTTON confirms, since mail previews open links by themselves (controls/Verify.php). */ ?>
<?= \app\Ui::page(['title' => 'Confirm your email']) ?>
<?php if ($token === ''): ?>
<?= \app\Ui::notice(['tone' => 'warning', 'title' => 'That link does not work any more.', 'text' => 'It was used already, is more than 2 days old, or is not one of ours. Sign in and ask for a new one.', 'action' => ['label' => 'Ask for a new link', 'url' => '/verify']]) ?>
<?php else: ?>
<?= \app\Ui::form(['action' => '/verify/doconfirm', 'submit' => 'Confirm my email', 'lead' => 'This confirms ' . $email . ' for VoidLux.', 'hidden' => ['t' => $token], 'fields' => []]) ?>
<?php endif; ?>
