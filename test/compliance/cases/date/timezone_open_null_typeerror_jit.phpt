--TEST--
stdlib timezone_open(null) — TypeError JIT on 8.4 profile (#18763, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    timezone_open(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
timezone_open(): Argument #1 ($timezone) must be of type string, null given
