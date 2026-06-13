--TEST--
stdlib current()/next()/end() on enum case arrays preserve enum objects (#5627, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

$a = [E::A, E::B];
var_export(current($a));
echo PHP_EOL;
next($a);
var_export(current($a));
echo PHP_EOL;
var_export(end($a));
echo PHP_EOL;
--EXPECT--
\E::A
\E::B
\E::B
