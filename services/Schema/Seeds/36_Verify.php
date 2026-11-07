<?php
/** Confirming an email (controls/Verify.php): the page and its send for members; the mailed link's page and button for anyone holding it. */
echo "  authcontrol: verify::* => " . \app\PermissionCache::seedRule('verify', '*', 100, 'Confirm your email: where you stand, and a link by mail') . "\n";
echo "  authcontrol: verify::confirm => " . \app\PermissionCache::seedRule('verify', 'confirm', 101, 'Confirm your email: the page the mailed link opens') . "\n";
echo "  authcontrol: verify::doconfirm => " . \app\PermissionCache::seedRule('verify', 'doconfirm', 101, 'Confirm your email: its button') . "\n";
