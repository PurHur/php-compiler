<?php
// #24010: nested foreach fails to compile under AOT — "Unknown array write op:
// PHPCfg\Op\Iterator\Value". A SINGLE foreach compiles and runs fine (i01, i02), so nesting is the
// variable. May share a root cause with #24011. FAILS AOT today by design.
$g = [[1, 2], [3, 4]];
$t = 0;
foreach ($g as $row) { foreach ($row as $c) { $t += $c; } }
echo $t, "\n";
