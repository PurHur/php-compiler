--TEST--
Default parameter: global PHP_INT_MAX constant
--FILE--
<?php
function f(int $a = PHP_INT_MAX): int { return $a; }
echo f() === PHP_INT_MAX ? "ok\n" : "bad\n";
--EXPECT--
ok
