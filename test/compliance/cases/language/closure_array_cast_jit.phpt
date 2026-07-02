--TEST--
Language: (array) cast on Closure JIT (#15015, Zend/zend_closures.c)
--FILE--
<?php
$c = static fn (): int => 1;
$a = (array) $c;
echo count($a);
echo array_key_exists(0, $a) ? '1' : '0';
echo $a[0] instanceof Closure ? '1' : '0';
echo "\n";
--EXPECT--
111
