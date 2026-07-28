--TEST--
stdlib extract() EXTR_SKIP imports compile-allocated uninitialized CVs (#24309, ext/standard/array.c php_extract_skip)
--FILE--
<?php
$a = 1;
$arr = ['a' => 2, 'b' => 3, 'c' => 4];
$n = extract($arr, EXTR_SKIP);
echo $n, "\n";
echo $a, "\n";
echo $b, "\n";
echo $c, "\n";
--EXPECT--
2
1
3
4
