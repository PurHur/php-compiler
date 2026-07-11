--TEST--
stdlib: prev() after next() past last element returns false (#11957, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = [1, 2, 3];
end($a);
next($a);
echo 'prev=', var_export(prev($a), true), "\n";

$b = ['a' => 1, 'b' => 2, 'c' => 3];
end($b);
next($b);
echo 'assoc=', var_export(prev($b), true), "\n";

$c = [10, 20, 30];
end($c);
prev($c);
echo 'normal=', key($c), "\n";
--EXPECT--
prev=false
assoc=false
normal=1
--CREDITS--
PurHur/php-compiler issue #11957
