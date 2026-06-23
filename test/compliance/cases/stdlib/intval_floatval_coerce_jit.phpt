--TEST--
stdlib intval()/floatval() array/object coercion — JIT (#10810, ext/standard/type.c)
--FILE--
<?php
declare(strict_types=1);

echo 'intval([]): ', intval([]), "\n";
echo 'floatval([]): ', floatval([]), "\n";
echo 'intval(obj): ', @intval(new stdClass()), "\n";
?>
--EXPECT--
intval([]): 0
floatval([]): 0
intval(obj): 1
