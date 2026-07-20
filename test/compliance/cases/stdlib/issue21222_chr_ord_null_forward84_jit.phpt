--TEST--
stdlib chr(null)/ord(null) — soft-null on 8.4 forward profile JIT (#21222)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (): bool { return true; });
echo var_export(chr(null), true), "\n";
echo var_export(ord(null), true), "\n";
?>
--EXPECT--
'' . "\0" . ''
0
