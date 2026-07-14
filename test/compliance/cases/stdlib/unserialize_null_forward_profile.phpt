--TEST--
stdlib unserialize(null) — TypeError on 8.4 forward profile (#18840, ext/standard/var.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    unserialize(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
unserialize(): Argument #1 ($data) must be of type string, null given
