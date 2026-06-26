--TEST--
JIT: prev() after next() past last element returns false (#11957, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = [1, 2, 3];
end($a);
next($a);
echo 'prev=', var_export(prev($a), true), "\n";
--EXPECT--
prev=false
--CREDITS--
PurHur/php-compiler issue #11957
