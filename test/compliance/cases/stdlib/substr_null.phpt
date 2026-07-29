--TEST--
stdlib substr(null) — soft-null on 8.4 forward profile (#24817 / #21189, reverts #24694/#18980 TypeError)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
