--TEST--
var_export() — string values with embedded NUL (#13972, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);
var_export(preg_grep('/\0/', ["a\0b", 'c']));
echo "\n";
--EXPECT--
array (
  0 => 'a' . "\0" . 'b',
)
