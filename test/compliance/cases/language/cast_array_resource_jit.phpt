--TEST--
Language: (array) cast on stream resource JIT (#15012, Zend/zend_operators.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
$a = (array) $h;
echo count($a);
echo is_resource($a[0]) ? '1' : '0';
fclose($h);
$b = (array) $h;
echo count($b);
echo gettype($b[0]) === 'resource (closed)' ? '1' : '0';
echo "\n";
--EXPECT--
1111
