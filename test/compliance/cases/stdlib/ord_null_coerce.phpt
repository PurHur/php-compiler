--TEST--
stdlib ord(null) — TypeError on 8.4 forward profile (#18838, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    ord(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
ord(): Argument #1 ($character) must be of type string, null given
