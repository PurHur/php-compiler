--TEST--
ext/odbc specialcolumns/procedures/procedurecolumns registered (#21294)
--ENV--
PHP_COMPILER_ENABLE_ODBC=1
--FILE--
<?php
foreach ([
    'odbc_specialcolumns',
    'odbc_procedures',
    'odbc_procedurecolumns',
] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}
?>
--EXPECT--
odbc_specialcolumns=true
odbc_procedures=true
odbc_procedurecolumns=true
