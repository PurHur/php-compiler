--TEST--
stdlib long2ip(null) — soft-null on 8.4 forward profile (#21236, php-src basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (): bool { return true; });
echo var_export(long2ip(null), true), "\n";
?>
--EXPECT--
'0.0.0.0'
