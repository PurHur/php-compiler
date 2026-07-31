--TEST--
ext/odbc prepare/fetch/tables/columns builtins registered (#21258)
--ENV--
PHP_COMPILER_ENABLE_ODBC=1
--FILE--
<?php
foreach ([
    'odbc_prepare',
    'odbc_execute',
    'odbc_fetch_array',
    'odbc_fetch_object',
    'odbc_fetch_into',
    'odbc_tables',
    'odbc_columns',
    'odbc_num_fields',
    'odbc_field_name',
    'odbc_field_type',
    'odbc_field_len',
    'odbc_field_num',
    'odbc_free_result',
] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}
?>
--EXPECT--
odbc_prepare=true
odbc_execute=true
odbc_fetch_array=true
odbc_fetch_object=true
odbc_fetch_into=true
odbc_tables=true
odbc_columns=true
odbc_num_fields=true
odbc_field_name=true
odbc_field_type=true
odbc_field_len=true
odbc_field_num=true
odbc_free_result=true
