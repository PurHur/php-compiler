--TEST--
stdlib var_export nested call with dual literal true — return string not NULL (#11399, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);

echo var_export(in_array('a', ['a'], true), true), "\n";
echo var_export(array_search('b', ['a', 'b'], true), true), "\n";
--EXPECT--
true
1
