--TEST--
ext/odbc autocommit/commit/rollback builtins registered (#21277)
--ENV--
PHP_COMPILER_ENABLE_ODBC=1
--FILE--
<?php
foreach ([
    'odbc_autocommit',
    'odbc_commit',
    'odbc_rollback',
] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}
?>
--EXPECT--
odbc_autocommit=true
odbc_commit=true
odbc_rollback=true
