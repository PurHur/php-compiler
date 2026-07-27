--TEST--
stdlib array_find()/array_find_key() array:/callback: named parameters (#10077, #23875, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(array_find(array: [1, 2, 3], callback: fn ($v) => $v > 1));
echo "\n";
var_export(array_find_key(array: ['a' => 1, 'b' => 2], callback: fn ($v) => $v > 1));
echo "\n";
try {
    var_export(array_find(array: [1, 2, 3], callback: fn ($v) => $v > 1, strict: true));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
2
'b'
Error: Unknown named parameter $strict
