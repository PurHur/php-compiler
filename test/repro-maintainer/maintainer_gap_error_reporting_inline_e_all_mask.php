<?php
// Issue #15391 — inline E_ALL & ~MASK to error_reporting() must persist computed mask.

$fail = 0;

$old = error_reporting();
error_reporting(E_ALL & ~E_NOTICE);
$got = error_reporting();
if ($got !== (E_ALL & ~E_NOTICE)) {
    echo "inline E_ALL & ~E_NOTICE: got {$got}, want " . (E_ALL & ~E_NOTICE) . "\n";
    $fail = 1;
}
error_reporting($old);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
$got2 = error_reporting();
$want2 = E_ALL & ~E_DEPRECATED & ~E_STRICT;
if ($got2 !== $want2) {
    echo "inline E_ALL & ~E_DEPRECATED & ~E_STRICT: got {$got2}, want {$want2}\n";
    $fail = 1;
}
error_reporting($old);

$m = E_ALL & ~E_NOTICE;
error_reporting($m);
$got3 = error_reporting();
if ($got3 !== $m) {
    echo "variable mask: got {$got3}, want {$m}\n";
    $fail = 1;
}
error_reporting($old);

ini_set('error_reporting', (string) (E_ALL & ~E_NOTICE));
$got4 = error_reporting();
if ($got4 !== (E_ALL & ~E_NOTICE)) {
    echo "ini_set inline mask: got {$got4}, want " . (E_ALL & ~E_NOTICE) . "\n";
    $fail = 1;
}
error_reporting($old);

exit($fail);
