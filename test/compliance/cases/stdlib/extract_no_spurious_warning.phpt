--TEST--
stdlib extract() — imported variables readable without undefined-variable warnings (#10590, ext/standard/basic_functions.c)
--FILE--
<?php
$arr = ['a' => 1, 'b' => 2];
extract($arr, EXTR_SKIP);
echo $a, ':', $b, "\n";
--EXPECT--
1:2
