<?php
// #27073 — AOT array_fill() must match Zend/VM/JIT (not object slots / segfault)
echo json_encode(array_fill(0, 3, 'x')), PHP_EOL;
$a = array_fill(0, 3, 'x');
echo gettype($a[0]), ':', $a[0], $a[1], $a[2], PHP_EOL;
$b = array_fill(2, 2, 7);
echo $b[2], ',', $b[3], PHP_EOL;
