--TEST--
stdlib var_export($resource, true) — NULL like Zend (#11421, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
var_export(var_export($fp, true));
echo "\n";
var_export([$fp]);
echo "\n";
--EXPECT--
'NULL'
array (
  0 => NULL,
)
