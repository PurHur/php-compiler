--TEST--
stdlib implode() JIT — array separator TypeError (#4160)
--FILE--
<?php
try {
    implode(['x'], ['a', 'b']);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
implode(): Argument #1 ($separator) must be of type string, array given
