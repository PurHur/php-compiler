--TEST--
stdlib idate(null) — TypeError on 8.4 forward profile (#18839, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    idate(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
idate(): Argument #1 ($format) must be of type string, null given
