--TEST--
stdlib touch() — null $filename TypeError (#18245, ext/standard/file.c)
--FILE--
<?php
try {
    touch(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
touch(): Argument #1 ($filename) must be of type string, null given
