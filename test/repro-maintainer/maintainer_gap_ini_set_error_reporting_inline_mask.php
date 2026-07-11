<?php
// Issue #15460 — ini_set('error_reporting', inline E_ALL & ~MASK) must persist computed mask.

$fail = 0;
$old = error_reporting();

ini_set('error_reporting', (string) (E_ALL & ~E_NOTICE));
$got = error_reporting();
if ($got !== (E_ALL & ~E_NOTICE)) {
    echo "ini_set inline mask: got {$got}, want " . (E_ALL & ~E_NOTICE) . "\n";
    $fail = 1;
}
error_reporting($old);

$m = E_ALL & ~E_NOTICE;
ini_set('error_reporting', (string) $m);
$got2 = error_reporting();
if ($got2 !== $m) {
    echo "ini_set variable mask: got {$got2}, want {$m}\n";
    $fail = 1;
}
error_reporting($old);

exit($fail);
