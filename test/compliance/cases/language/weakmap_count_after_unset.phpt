--TEST--
language WeakMap — count() drops to 0 when last strong key ref is unset (#19369)
--FILE--
<?php
$wm = new WeakMap();
$o = new stdClass();
$wm[$o] = 42;
echo 'get=', $wm[$o], "\n";
echo 'count_before=', count($wm), "\n";
$o = null;
echo 'count_after_unset=', count($wm), "\n";
$alive = 0;
foreach ($wm as $k => $v) {
    $alive++;
}
echo 'foreach_after=', $alive, "\n";

// foreach leaves $k holding the key — clearing it must drop count (#19369 / Zend).
$wm2 = new WeakMap();
$o2 = new stdClass();
$wm2[$o2] = 7;
foreach ($wm2 as $k2 => $v2) {
}
$o2 = null;
echo 'count_with_foreach_key=', count($wm2), "\n";
$k2 = null;
echo 'count_after_key_cleared=', count($wm2), "\n";
--EXPECT--
get=42
count_before=1
count_after_unset=0
foreach_after=0
count_with_foreach_key=1
count_after_key_cleared=0
