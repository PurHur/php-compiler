<?php
// #24010: sort() segfaults under AOT. FAILS AOT today by design.
$a = [3, 1, 2];
sort($a);
echo implode(',', $a), "\n";
$b = ['b' => 2, 'a' => 1];
ksort($b);
echo implode(',', array_keys($b)), "\n";
