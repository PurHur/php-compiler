--TEST--
stdlib implode() JIT — array-first invalid glue cites argument #2 (#16401)
--FILE--
<?php
try {
    implode([], 1);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
implode(): Argument #2 ($array) must be of type ?array, int given
