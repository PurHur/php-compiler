<?php
// Issue #27227 — AOT krsort()/arsort() must match Zend (thin standalone).
$a = ['b' => 2, 'a' => 1, 'c' => 3];
krsort($a);
echo implode(',', array_keys($a)), '|', implode(',', array_values($a)), "\n";
$b = ['a' => 2, 'b' => 3, 'c' => 1];
arsort($b);
echo implode(',', array_keys($b)), '|', implode(',', array_values($b)), "\n";
