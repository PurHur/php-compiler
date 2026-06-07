--TEST--
stdlib fsync() — non-resource TypeError (#6062, php-src-strict)
--FILE--
<?php
try {
    fsync(42);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
fsync(): Argument #1 ($stream) must be of type resource, int given
