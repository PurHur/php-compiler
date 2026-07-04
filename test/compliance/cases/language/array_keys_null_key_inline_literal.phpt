--TEST--
language array_keys() — inline [null => 1] haystack arg wiring (#15930, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);
$a = [null => 1];
echo var_export(array_keys($a, null), true), "\n";
echo var_export(array_keys([null => 1], null), true), "\n";
--EXPECT--
array (
)
array (
)
