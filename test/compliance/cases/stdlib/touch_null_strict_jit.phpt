--TEST--
stdlib touch() — null $filename TypeError JIT (#18245, ext/standard/file.c)
--JIT--
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
