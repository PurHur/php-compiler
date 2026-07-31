--TEST--
ext/odbc odbc_do + odbc_result_all registered (#21308)
--ENV--
PHP_COMPILER_ENABLE_ODBC=1
--FILE--
<?php
foreach (['odbc_do', 'odbc_result_all'] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}
?>
--EXPECT--
odbc_do=true
odbc_result_all=true
