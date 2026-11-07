<?php
/** Confirming an email (controls/Verify.php): the page and its send for members; the mailed link's page and button for anyone holding it. */
echo "  authcontrol: verify::* => " . \app\PermissionCache::seedRule('verify', '*', 100, 'Confirm your email: where you stand, and a link by mail') . "\n";
echo "  authcontrol: verify::confirm => " . \app\PermissionCache::seedRule('verify', 'confirm', 101, 'Confirm your email: the page the mailed link opens') . "\n";
echo "  authcontrol: verify::doconfirm => " . \app\PermissionCache::seedRule('verify', 'doconfirm', 101, 'Confirm your email: its button') . "\n";
/* the member's confirmation link (lib/EmailConfirm.php): its hash, when it runs out, when the last one went, as TEXT like the member's other dates */
$__cols = array_keys(\app\Bean::inspect('member'));
foreach (['confirm_hash', 'confirm_expires', 'confirm_sent_at'] as $__c) {
    if (in_array($__c, $__cols, true)) { echo "  member.$__c: kept\n"; continue; }
    \RedBeanPHP\R::exec("ALTER TABLE member ADD COLUMN $__c TEXT"); echo "  member.$__c: added\n";
}
