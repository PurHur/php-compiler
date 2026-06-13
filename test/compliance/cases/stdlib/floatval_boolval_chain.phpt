--TEST--
stdlib floatval(boolval(intval())) — boxed bool operand (#1492 bootstrap-aot)
--FILE--
<?php
declare(strict_types=1);
$n = intval('42');
$b = boolval($n);
$f = floatval($b);
echo (string) ($n + intval($f));
--EXPECT--
43
