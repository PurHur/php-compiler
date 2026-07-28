--TEST--
stdlib extract() EXTR_IF_EXISTS skips compile-allocated uninitialized CVs (#24310, ext/standard/array.c php_extract_if_exists)
--FILE--
<?php
$a = 1;
$c = 9;
$arr = ['a' => 2, 'b' => 3, 'c' => 4];
$n = extract($arr, EXTR_IF_EXISTS);
echo "n=$n a=$a";
echo " b=", $b ?? 'unset';
echo " c=$c\n";
--EXPECT--
n=2 a=2 b=unset c=4
