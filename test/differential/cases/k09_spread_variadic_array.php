<?php
// FAILS ON AOT — #24167. Expected 6, AOT prints "Object": the variadic pack is not a PHP array.
//
// Bounding evidence: e08_spread does `implode(",", $v)` on the same pack from the same kind of call
// and PASSES — implode evidently tolerates whatever $v actually is. Swap in array_sum() and it
// breaks. This case exists because e08's pass is shallow, not because e08 is wrong.
function sv(...$v) { echo array_sum($v), "\n"; }
$p = [1, 2, 3];
sv(...$p);
