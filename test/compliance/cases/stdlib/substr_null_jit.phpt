--TEST--
stdlib substr(null) — soft-null on 8.4 forward profile JIT (#24817 / #21189)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (): bool { return true; });
echo var_export(substr(null, 0), true), "\n";
echo var_export(substr('hello', 1, 3), true), "\n";
?>
--EXPECT--
''
'ell'
