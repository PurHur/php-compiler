--TEST--
stdlib var_export chained dim-fetch first arg with literal return flag (#15762, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);

$b = [1 => [0 => 'a', 1 => 0]];
echo var_export($b[1][0], true), "\n";

preg_match('/(a)(b)/', 'ab', $m, PREG_OFFSET_CAPTURE);
echo var_export($m[1][0], true), "\n";
--EXPECT--
'a'
'a'
