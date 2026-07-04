--TEST--
stdlib array_keys() — null search literal with null key haystack (#11272, ext/standard/array.c)
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
