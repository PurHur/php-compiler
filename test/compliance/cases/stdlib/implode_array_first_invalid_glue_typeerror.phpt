--TEST--
stdlib implode() — array-first invalid glue cites argument #2 (#16401, ext/standard/string.c)
--FILE--
<?php
try {
    implode([], 1);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    join([], 1);
    echo "uncaught join\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    implode(',', ['a', 'b']);
    echo "modern ok\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
implode(): Argument #2 ($array) must be of type ?array, int given
join(): Argument #2 ($array) must be of type ?array, int given
modern ok
