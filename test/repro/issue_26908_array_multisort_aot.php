<?php
// #26908: AOT array_multisort two-array coupled sort must match Zend (no segfault).
$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, $b);
echo implode(',', $a), '/', implode(',', $b), "\n";
