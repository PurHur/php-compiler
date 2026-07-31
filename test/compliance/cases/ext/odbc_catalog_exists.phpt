--TEST--
ext/odbc catalog builtins registered (#21279)
--ENV--
PHP_COMPILER_ENABLE_ODBC=1
--FILE--
<?php
foreach ([
    'odbc_primarykeys',
    'odbc_foreignkeys',
    'odbc_statistics',
    'odbc_gettypeinfo',
] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}
?>
--EXPECT--
odbc_primarykeys=true
odbc_foreignkeys=true
odbc_statistics=true
odbc_gettypeinfo=true
