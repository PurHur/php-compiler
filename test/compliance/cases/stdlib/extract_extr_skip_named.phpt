--TEST--
stdlib extract() EXTR_SKIP named flags parameter (#9620, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
$a = 1;
$n = extract(['a' => 99, 'b' => 2], flags: EXTR_SKIP);
echo "n={$n}\n";
echo "a={$a}\n";
echo isset($b) ? "b={$b}\n" : "b=unset\n";
--EXPECT--
n=1
a=1
b=2
