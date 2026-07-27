<?php
// #24010: foreach by reference — compile + run under AOT (MemoryRuntime insert restore +
// unset of borrowed foreach lvalues).
$a = [1, 2, 3];
foreach ($a as &$v) { $v *= 2; }
unset($v);
echo implode(',', $a), "\n";
