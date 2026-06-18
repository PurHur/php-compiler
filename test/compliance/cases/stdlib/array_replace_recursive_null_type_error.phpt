--TEST--
stdlib array_replace_recursive() — TypeError for null argument (#9624, ext/standard/array.c)
--FILE--
<?php
try {
    array_replace_recursive(['a' => 1], null);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    array_replace_recursive(null, ['a' => 1]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: array_replace_recursive(): Argument #2 must be of type array, null given
TypeError: array_replace_recursive(): Argument #1 must be of type array, null given
