<?php /* Where a member stands with confirming their email (controls/Verify.php). */ ?>
<?= \app\Ui::page(['title' => 'Confirm your email', 'lead' => 'The builder and the artist work for you once your email is confirmed: one click on a link we send you.', 'back' => ['label' => 'The forge', 'url' => '/forge']]) ?>
<?php if ($done): ?>
<?= \app\Ui::notice(['tone' => 'success', 'title' => 'Your email is confirmed.', 'text' => htmlspecialchars($email) . ' is confirmed: the builder and the artist can work for you.', 'action' => ['label' => 'Back to the forge', 'url' => '/forge']]) ?>
<?php else: ?>
<?= \app\Ui::detail(['title' => 'Your email', 'items' => ['Email' => $email, 'Last link sent' => $sent !== '' ? date('M j, H:i', strtotime($sent)) : 'none yet']]) ?>
<?= \app\Ui::form(['action' => '/verify/send', 'submit' => 'Send me the link', 'lead' => 'We send a link to ' . $email . '. Open it and press Confirm my email. It works for 2 days; look in your spam folder if it does not arrive.', 'fields' => []]) ?>
<?php endif; ?>
