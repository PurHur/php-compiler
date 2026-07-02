--TEST--
Language: (array) cast on stream resource — embeds resource zval (#15012, Zend/zend_operators.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
$a = (array) $h;
echo count($a);
echo is_resource($a[0]) ? '1' : '0';
echo get_resource_type($a[0]);
echo "\n";
fclose($h);
$b = (array) $h;
echo count($b);
echo gettype($b[0]);
echo get_resource_type($b[0]);
echo "\n";
--EXPECT--
11stream
1resource (closed)Unknown
