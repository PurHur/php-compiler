<?php
// Issue #27226 — AOT array_push / array_unshift must match Zend/VM/JIT
$a = [1];
echo array_push($a, 2, 3), ' ', implode(',', $a), "\n";
$b = [2, 3];
echo array_unshift($b, 0, 1), ' ', implode(',', $b), "\n";
