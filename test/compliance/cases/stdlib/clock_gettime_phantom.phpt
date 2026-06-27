--TEST--
stdlib clock_gettime() — not advertised on PHP 8.2 reference profile (#12470)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('clock_gettime') ? "fn-fail\n" : "fn-ok\n";
echo enum_exists('ClockInterface') ? "enum-fail\n" : "enum-ok\n";
--EXPECT--
fn-ok
enum-ok
