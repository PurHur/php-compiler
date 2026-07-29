<?php
// Repro for #24784: unset($o) must not drop WeakMap entry while foreach $k holds the key.
$wm = new WeakMap();
$o = new stdClass;
$wm[$o] = 1;
foreach ($wm as $k => $v) {
}
echo 'k_same=', ($k === $o) ? 'yes' : 'no', PHP_EOL;
unset($o);
echo 'count_with_k=', count($wm), PHP_EOL;
unset($k);
gc_collect_cycles();
echo 'count_after_k=', count($wm), PHP_EOL;
