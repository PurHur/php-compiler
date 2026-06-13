--TEST--
JIT: array_rand() — numeric-string $num coercion (#4320, ext/standard/array.c)
--FILE--
<?php
$keys = ['a', 'b', 'c'];
$k = array_rand($keys, '1');
$ok = is_int($k) && $k >= 0 && $k <= 2;
echo $ok ? "ok\n" : "bad\n";
--EXPECT--
ok
