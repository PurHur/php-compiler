<?php
// #24010: foreach by reference fails to compile under AOT with an internal invariant failure,
// "Current basic block has no parent function". FAILS AOT today by design.
$a = [1, 2, 3];
foreach ($a as &$v) { $v *= 2; }
unset($v);
echo implode(',', $a), "\n";
