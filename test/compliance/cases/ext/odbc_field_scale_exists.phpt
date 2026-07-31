--TEST--
ext/odbc field_scale/field_precision registered (#21306)
--ENV--
PHP_COMPILER_ENABLE_ODBC=1
--FILE--
<?php
foreach ([
    'odbc_field_scale',
    'odbc_field_precision',
] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}
?>
--EXPECT--
odbc_field_scale=true
odbc_field_precision=true
