--TEST--
Language: (array) cast on stream resource — one-element array (#15012, Zend/zend_operators.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
$a = (array) $h;
echo count($a);
echo array_key_exists(0, $a) ? '1' : '0';
var_export($a);
echo "\n";
fclose($h);
$b = (array) $h;
echo count($b);
echo array_key_exists(0, $b) ? '1' : '0';
var_export($b);
echo "\n";
--EXPECT--
11array (
  0 => NULL,
)
11array (
  0 => NULL,
)
