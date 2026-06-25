--TEST--
stdlib extract() EXTR_SKIP return count excludes skipped keys (#11509, ext/standard/basic_functions.c)
--FILE--
<?php
$a = 1;
$n = extract(['a' => 99, 'b' => 2], EXTR_SKIP);
echo "n={$n}\n";
echo "a={$a}\n";
echo "b={$b}\n";
--EXPECT--
n=1
a=1
b=2
