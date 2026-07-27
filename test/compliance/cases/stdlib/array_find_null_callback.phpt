--TEST--
stdlib array_find family null callback — TypeError (#17133, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['array_find', 'array_find_key', 'array_all', 'array_any'] as $fn) {
    try {
        if ('array_find_key' === $fn) {
            $fn(['a' => 1], null);
        } else {
            $fn([1], null);
        }
        echo $fn, ": uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', get_class($e), "\n";
    }
}
var_export(array_find([1, 2, 3], static fn ($v) => 2 === $v));
echo "\n";
?>
--EXPECT--
array_find: TypeError
array_find_key: TypeError
array_all: TypeError
array_any: TypeError
2
