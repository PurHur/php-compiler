--TEST--
language WeakMap — unset($o) must not drop entry while foreach $k holds key (#24784)
--FILE--
<?php
$wm = new WeakMap();
$o = new stdClass;
$wm[$o] = 1;
foreach ($wm as $k => $v) {
}
echo 'k_same=', ($k === $o) ? 'yes' : 'no', "\n";
unset($o);
echo 'count_with_k=', count($wm), "\n";
unset($k);
gc_collect_cycles();
echo 'count_after_k=', count($wm), "\n";
--EXPECT--
k_same=yes
count_with_k=1
count_after_k=0
