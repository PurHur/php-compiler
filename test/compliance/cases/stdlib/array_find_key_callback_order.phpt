--TEST--
stdlib array_find_key()/array_any_key()/array_all_key() — closure callback receives (key, value) (#17394, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$a = [1 => 'a', 2 => 'b'];
var_export(array_find_key($a, fn ($k, $v) => $k === 2));
echo "\n";
var_export(array_find_key([1 => 'a'], fn ($k, $v) => $k > 5));
echo "\n";
var_export(array_any_key($a, fn ($k, $v) => $k === 2));
echo "\n";
var_export(array_all_key($a, fn ($k, $v) => is_int($k)));
echo "\n";
?>
--EXPECT--
2
NULL
true
true
