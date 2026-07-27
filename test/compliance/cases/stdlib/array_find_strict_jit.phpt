--TEST--
stdlib array_find()/array_all()/array_any() reject 3rd arg JIT — Zend exactly 2 (#23875, ext/standard/array.c)
--JIT--
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$haystack = [1, 2, 3];
foreach (['array_find', 'array_find_key', 'array_any', 'array_all'] as $fn) {
    try {
        $fn($haystack, fn ($v) => $v === 2, true);
        echo $fn, ":unexpected-ok\n";
    } catch (Throwable $e) {
        echo $fn, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
array_find:ArgumentCountError:array_find() expects exactly 2 arguments, 3 given
array_find_key:ArgumentCountError:array_find_key() expects exactly 2 arguments, 3 given
array_any:ArgumentCountError:array_any() expects exactly 2 arguments, 3 given
array_all:ArgumentCountError:array_all() expects exactly 2 arguments, 3 given
