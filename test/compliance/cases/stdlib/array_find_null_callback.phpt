--TEST--
stdlib array_find family null callback — TypeError (#17133, ext/standard/array.c)
--FILE--
<?php
foreach (['array_find', 'array_find_key', 'array_all', 'array_any', 'array_all_key', 'array_any_key'] as $fn) {
    try {
        if ('array_find_key' === $fn) {
            $fn(['a' => 1], null);
        } else {
            $fn([1], null);
        }
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), "\n";
    }
}
?>
--EXPECT--
array_find: TypeError
array_find_key: TypeError
array_all: TypeError
array_any: TypeError
array_all_key: TypeError
array_any_key: TypeError
