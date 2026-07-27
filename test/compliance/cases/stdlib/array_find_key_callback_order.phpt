--TEST--
stdlib array_find_key() callback receives (value, key) (#17599, #24000, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$a = [1 => 'a', 2 => 'b'];
var_export(array_find_key($a, fn ($v, $k) => $k === 2));
echo "\n";
var_export(array_find_key([1 => 'a'], fn ($v, $k) => $k > 5));
echo "\n";
var_export(array_find_key($a, fn ($v, $k) => $v === 'b'));
echo "\n";
?>
--EXPECT--
2
NULL
2
