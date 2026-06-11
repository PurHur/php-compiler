--TEST--
stdlib implode() — array separator TypeError (#4160, ext/standard/string.c)
--FILE--
<?php
try {
    implode(['x'], ['a', 'b']);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    join(['x'], ['a', 'b']);
    echo "uncaught join\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
implode(): Argument #1 ($separator) must be of type string, array given
join(): Argument #1 ($separator) must be of type string, array given
