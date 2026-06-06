--TEST--
stdlib fdatasync() — non-resource TypeError (#6813, php-src-strict)
--FILE--
<?php
try {
    fdatasync(42);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
fdatasync(): Argument #1 ($stream) must be of type resource, int given
