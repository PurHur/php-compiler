--TEST--
stdlib array_find family null callback — TypeError JIT/AOT (#17133 / #30624)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    array_find([1], null);
    echo "array_find: uncaught\n";
} catch (TypeError $e) {
    echo 'array_find: ', $e->getMessage(), "\n";
}
try {
    array_find_key(['a' => 1], null);
    echo "array_find_key: uncaught\n";
} catch (TypeError $e) {
    echo 'array_find_key: ', $e->getMessage(), "\n";
}
try {
    array_all([1], null);
    echo "array_all: uncaught\n";
} catch (TypeError $e) {
    echo 'array_all: ', $e->getMessage(), "\n";
}
try {
    array_any([1], null);
    echo "array_any: uncaught\n";
} catch (TypeError $e) {
    echo 'array_any: ', $e->getMessage(), "\n";
}
?>
--JIT--
--EXPECT--
array_find: array_find(): Argument #2 ($callback) must be a valid callback, no array or string given
array_find_key: array_find_key(): Argument #2 ($callback) must be a valid callback, no array or string given
array_all: array_all(): Argument #2 ($callback) must be a valid callback, no array or string given
array_any: array_any(): Argument #2 ($callback) must be a valid callback, no array or string given
