--TEST--
stdlib array_find()/array_find_key()/array_all()/array_any() reject 3rd arg — Zend exactly 2 (#23875, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['array_find', 'array_find_key', 'array_any', 'array_all'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ' params=[', implode(',', $names), '] n=', $rf->getNumberOfParameters(), "\n";
}
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
array_find params=[array,callback] n=2
array_find_key params=[array,callback] n=2
array_any params=[array,callback] n=2
array_all params=[array,callback] n=2
array_find:ArgumentCountError:array_find() expects exactly 2 arguments, 3 given
array_find_key:ArgumentCountError:array_find_key() expects exactly 2 arguments, 3 given
array_any:ArgumentCountError:array_any() expects exactly 2 arguments, 3 given
array_all:ArgumentCountError:array_all() expects exactly 2 arguments, 3 given
