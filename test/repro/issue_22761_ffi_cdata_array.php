<?php
// Repro for #22761 — FFI\CData C-array indexing after #22369.
$a = FFI::new('int[3]');
$a[0] = 1;
$a[1] = 2;
$a[2] = 3;
echo $a[0], ' ', $a[1], ' ', $a[2], PHP_EOL;
$a[1] = 99;
echo 'mid=', $a[1], PHP_EOL;
