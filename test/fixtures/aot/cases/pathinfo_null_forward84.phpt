--TEST--
AOT: basename null — TypeError on 8.4 forward profile (#20099)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    basename(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
basename(): Argument #1 ($path) must be of type string, null given
