--TEST--
stdlib var_export() on closed stream — NULL like Zend (#5148, php-src-strict)
--FILE--
<?php
declare(strict_types=1);
$fp = fopen('php://memory', 'r+');
fclose($fp);
var_export($fp);
echo "\n";
var_export([$fp]);
echo "\n";
--EXPECT--
NULL
array (
  0 => NULL,
)
