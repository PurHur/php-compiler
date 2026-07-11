--TEST--
Language: (array) cast on stream resource JIT (#15012, Zend/zend_operators.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
$a = (array) $h;
echo count($a);
echo array_key_exists(0, $a) ? '1' : '0';
fclose($h);
$b = (array) $h;
echo count($b);
echo array_key_exists(0, $b) ? '1' : '0';
echo "\n";
--EXPECT--
1111
